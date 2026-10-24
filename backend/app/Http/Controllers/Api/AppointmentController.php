<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAppointmentRequest;
use App\Http\Requests\StoreWalkInAppointmentRequest;
use App\Http\Requests\UpdateAppointmentRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\MedicalService;
use App\Models\Patient;
use App\Notifications\AppointmentConfirmationNotification;
use App\Notifications\AppointmentReminderNotification;
use App\Services\WorkspacePermissionService;
use App\Support\ActiveWorkspace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Agenda for the active workspace. WorkspaceScope (bound by ResolveActiveWorkspace)
 * handles tenant isolation; appointments are shared within a workspace just like
 * patients. Pass `?doctor_id=` to narrow to a single doctor's personal agenda.
 */
class AppointmentController extends Controller
{
    /**
     * Agenda for a single day (defaults to today). Pass `?date=YYYY-MM-DD` for
     * another day, `?date=all` to list every appointment, or `?from=&to=` for a
     * range. Optional `?doctor_id=` and `?status=` filters.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Appointment::query()
            ->with([
                'doctor',
                'medicalService',
                'patient' => fn ($q) => $q->withMax('medicalRecords', 'created_at'),
            ])
            ->orderBy('appointment_date');

        $date = $request->query('date');
        $from = $request->query('from');
        $to = $request->query('to');

        if ($from && $to) {
            $query->whereBetween('appointment_date', [
                Carbon::parse($from)->startOfDay(),
                Carbon::parse($to)->endOfDay(),
            ]);
        } elseif ($date === 'all') {
            // "All" backs the searchable "Citas y atenciones" list, not a
            // literal unbounded export — without a floor this returns a
            // clinic's entire appointment history, growing every day forever.
            // Three years covers what anyone actually searches for here; an
            // explicit ?from=&to= range still has no floor for real exports.
            $query->where('appointment_date', '>=', Carbon::today()->subYears(3));
        } else {
            $day = $date ? Carbon::parse($date) : Carbon::today();
            $query->whereDate('appointment_date', $day->toDateString());
        }

        // Every member who can see the agenda sees the whole clinic's: doctors
        // need today's queue and a patient's past visits with colleagues (the
        // clinic asked for it, 2026-10-15). WRITING stays per doctor: see
        // scopedAppointment() and resolveDoctorId().
        if ($doctorId = $request->query('doctor_id')) {
            $query->where('doctor_id', $doctorId);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return AppointmentResource::collection($query->get());
    }

    /**
     * The authenticated user's own Doctor id when their clinic agenda must be
     * restricted to their own appointments — i.e. a CLINICAL-ONLY member: holds
     * the 'doctor' role, is not the owner, and holds no coordination role
     * (admin/receptionist). Returns null (see everything) for owners, admins,
     * front desk, and for personal workspaces where the solo doctor already only
     * has their own appointments.
     */
    private function strictAgendaDoctorId(): ?int
    {
        $user = Auth::user();
        $workspace = app(ActiveWorkspace::class)->current();

        if (! $user || ! $workspace) {
            return null;
        }

        // Single source of truth for "clinical-only member" (shared with reports).
        return app(WorkspacePermissionService::class)->selfScopedDoctorId($user, $workspace);
    }

    public function show(int $id): AppointmentResource
    {
        // Reading a colleague's appointment is fine; changing it is not.
        $appointment = Appointment::query()->findOrFail($id)->load([
            'doctor',
            'medicalService',
            'patient' => fn ($q) => $q->withMax('medicalRecords', 'created_at'),
            'medicalRecords',
        ]);

        return AppointmentResource::make($appointment);
    }

    /**
     * Resolve an appointment by id under the SAME strict-agenda rule as index():
     * a clinical-only member can only reach their own appointments — a
     * colleague's appointment 404s instead of being readable, editable,
     * cancellable or completable by id.
     */
    private function scopedAppointment(int $id): Appointment
    {
        return Appointment::query()
            ->when($this->strictAgendaDoctorId(), fn ($q, $ownId) => $q->where('doctor_id', $ownId))
            ->findOrFail($id);
    }

    /**
     * Schedule a new appointment. workspace_id is auto-filled by
     * BelongsToWorkspace; the patient is resolved through the workspace-scoped
     * query so cross-tenant ids resolve to 404. doctor_id defaults to the
     * authenticated doctor.
     */
    public function store(StoreAppointmentRequest $request): AppointmentResource
    {
        $data = $request->validated();

        // 404 if the patient is not in the active workspace.
        Patient::findOrFail($data['patient_id']);

        $data['doctor_id'] = $this->resolveDoctorId($data['doctor_id'] ?? null);
        $data['medical_service_id'] = $this->resolveServiceId($data['medical_service_id'] ?? null);
        $data['status'] ??= 'scheduled';

        $appointment = Appointment::create($data);

        return AppointmentResource::make($appointment->load(['doctor', 'medicalService', 'patient' => fn ($q) => $q->withMax('medicalRecords', 'created_at')]));
    }

    public function update(UpdateAppointmentRequest $request, int $id): AppointmentResource
    {
        $appointment = $this->scopedAppointment($id);

        $data = $request->validated();

        if (isset($data['patient_id'])) {
            Patient::findOrFail($data['patient_id']);
        }

        if (array_key_exists('doctor_id', $data)) {
            // A foreign doctor_id must not be assignable to this workspace's agenda.
            $data['doctor_id'] = $this->resolveDoctorId($data['doctor_id']);
        }

        if (array_key_exists('medical_service_id', $data)) {
            $data['medical_service_id'] = $this->resolveServiceId($data['medical_service_id']);
        }

        // A reminder belongs to the appointment time, not to the row forever.
        // Reopening the 24-hour window after an actual reschedule gives the
        // patient one relevant reminder for the new date without duplicating
        // ordinary edits (notes, service, doctor, etc.).
        if (
            array_key_exists('appointment_date', $data)
            && ! $appointment->appointment_date?->equalTo(Carbon::parse($data['appointment_date']))
        ) {
            $data['reminder_sent_at'] = null;
        }

        $appointment->update($data);

        return AppointmentResource::make($appointment->load(['doctor', 'medicalService', 'patient' => fn ($q) => $q->withMax('medicalRecords', 'created_at')]));
    }

    /**
     * Front-desk check-in: the patient has arrived. Moves the appointment into
     * the clinic day-queue pipeline (status → checked_in) and stamps the arrival
     * time. Idempotent — re-checking-in only refreshes the timestamp if not set.
     */
    public function checkIn(int $id): AppointmentResource
    {
        $appointment = $this->scopedAppointment($id);

        $appointment->update([
            'status' => 'checked_in',
            'checked_in_at' => $appointment->checked_in_at ?? now(),
        ]);

        return AppointmentResource::make($appointment->load(['patient' => fn ($q) => $q->withMax('medicalRecords', 'created_at')]));
    }

    /**
     * Walk-in: register arrival now — creates the appointment already checked in
     * so reception can send the patient to triage without a separate check-in step.
     */
    public function walkIn(StoreWalkInAppointmentRequest $request): AppointmentResource
    {
        $data = $request->validated();

        if (isset($data['patient_id'])) {
            Patient::findOrFail($data['patient_id']);
            $patientId = (int) $data['patient_id'];
        } else {
            // A walk-in is registered with only what's actually known — name and
            // cédula. Demographics/contact are left null (shown as "sin dato")
            // rather than invented, until someone completes the profile.
            $patient = Patient::create([
                'name' => $data['name'],
                'id_number' => $data['id_number'],
            ]);
            $patientId = $patient->id;
        }

        $appointment = Appointment::create([
            'patient_id' => $patientId,
            'doctor_id' => $this->resolveDoctorId($data['doctor_id']),
            'appointment_date' => now(),
            'status' => 'checked_in',
            'checked_in_at' => now(),
            'type' => 'walk_in',
            'channel' => 'in_person',
            'duration_minutes' => 30,
            'notes' => $data['notes'] ?? null,
        ]);

        return AppointmentResource::make($appointment->load([
            'doctor',
            'patient' => fn ($q) => $q->withMax('medicalRecords', 'created_at'),
        ]));
    }

    /**
     * Cancel an appointment (idempotent).
     */
    public function cancel(int $id): AppointmentResource
    {
        $appointment = $this->scopedAppointment($id);
        $appointment->update(['status' => 'cancelled']);

        return AppointmentResource::make($appointment->load(['patient' => fn ($q) => $q->withMax('medicalRecords', 'created_at')]));
    }

    /**
     * Mark an appointment as completed (idempotent).
     */
    public function complete(int $id): AppointmentResource
    {
        $appointment = $this->scopedAppointment($id);
        $appointment->update(['status' => 'completed']);

        return AppointmentResource::make($appointment->load(['patient' => fn ($q) => $q->withMax('medicalRecords', 'created_at')]));
    }

    /**
     * Record that a WhatsApp reminder was sent (click-to-chat is manual, so the
     * staff confirms the send). Tracked apart from the email reminder so both
     * channels coexist. Idempotent.
     */
    public function markReminded(int $id): AppointmentResource
    {
        $appointment = $this->scopedAppointment($id);
        $appointment->updateQuietly(['whatsapp_reminded_at' => now()]);

        return AppointmentResource::make($appointment->load(['patient' => fn ($q) => $q->withMax('medicalRecords', 'created_at')]));
    }

    /**
     * Email the patient the appointment confirmation (sent by the system from
     * the clinic's address, Reply-To the attending doctor). Triggered from the
     * booking confirmation screen.
     */
    public function sendConfirmationEmail(int $id): AppointmentResource
    {
        $appointment = $this->loadForEmail($id);
        $this->requirePatientEmail($appointment);

        $appointment->patient->notifyNow(new AppointmentConfirmationNotification($appointment));

        return AppointmentResource::make($appointment);
    }

    /**
     * Email the patient the appointment reminder now (instead of waiting for the
     * daily 08:00 job). Shares `reminder_sent_at` with that job so it dedupes.
     */
    public function sendReminderEmail(int $id): AppointmentResource
    {
        $appointment = $this->loadForEmail($id);
        $this->requirePatientEmail($appointment);

        $appointment->patient->notifyNow(new AppointmentReminderNotification($appointment));
        $appointment->updateQuietly(['reminder_sent_at' => now()]);

        return AppointmentResource::make($appointment->fresh(['patient', 'doctor']));
    }

    private function loadForEmail(int $id): Appointment
    {
        return $this->scopedAppointment($id)->load(['patient', 'doctor.workspace', 'workspace']);
    }

    private function requirePatientEmail(Appointment $appointment): void
    {
        if (! $appointment->patient?->email) {
            throw ValidationException::withMessages([
                'email' => 'El paciente no tiene un correo registrado.',
            ]);
        }
    }

    /**
     * Resolve the appointment's doctor_id, defaulting to the authenticated
     * doctor. A client-supplied doctor_id must belong to the ACTIVE workspace
     * (owner or member) — Doctor has no WorkspaceScope, so an unvalidated id
     * would let the caller attribute appointments to a doctor outside this
     * tenant. Rejected as a 422 rather than silently accepted.
     */
    private function resolveDoctorId(int|string|null $doctorId): ?int
    {
        // A plain doctor member of a clinic can ONLY schedule for themselves.
        // Force their own id and ignore any doctor_id the client sends, so the
        // attending doctor recorded is always the real one — never reassigned
        // to a colleague to make a panel/agenda line up.
        if ($ownId = $this->strictAgendaDoctorId()) {
            return $ownId;
        }

        if ($doctorId === null || $doctorId === '') {
            return Auth::user()->doctor?->id;
        }

        $doctorId = (int) $doctorId;
        $activeWorkspaceId = app(ActiveWorkspace::class)->id();

        $belongsToWorkspace = Doctor::query()
            ->whereKey($doctorId)
            ->where(function ($q) use ($activeWorkspaceId): void {
                $q->where('workspace_id', $activeWorkspaceId)
                    ->orWhereHas('clinics', fn ($c) => $c->whereKey($activeWorkspaceId));
            })
            ->exists();

        if (! $belongsToWorkspace) {
            throw ValidationException::withMessages([
                'doctor_id' => 'El médico seleccionado no pertenece a este espacio de trabajo.',
            ]);
        }

        return $doctorId;
    }

    /**
     * Resolve the appointment's service id. MedicalService is workspace-scoped,
     * so a foreign/unknown id is rejected rather than silently linking across
     * tenants. Null clears the service.
     */
    private function resolveServiceId(int|string|null $serviceId): ?int
    {
        if ($serviceId === null || $serviceId === '') {
            return null;
        }

        $serviceId = (int) $serviceId;
        if (! MedicalService::whereKey($serviceId)->exists()) {
            throw ValidationException::withMessages([
                'medical_service_id' => 'El servicio seleccionado no pertenece a este espacio de trabajo.',
            ]);
        }

        return $serviceId;
    }
}

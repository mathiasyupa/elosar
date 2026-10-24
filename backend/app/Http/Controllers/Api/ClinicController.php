<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkspaceResource;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Workspace;
use App\Services\ClinicService;
use App\Services\WorkspacePermissionService;
use App\Support\ActiveWorkspace;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Clinic lifecycle for the doctor app: create a clinic, join one by invite code,
 * and (owner only) read/regenerate the invite code.
 *
 * Runs under `auth:sanctum` WITHOUT the `workspace` middleware on purpose — these
 * actions are how a doctor discovers/creates the workspace they will then select,
 * so they must not require an already-active workspace. Workspace is the tenant
 * root (not WorkspaceScope-bound), so isolation here is by explicit ownership /
 * membership checks via ClinicService and owner_doctor_id.
 */
class ClinicController extends Controller
{
    public function __construct(private readonly ClinicService $clinics) {}

    /**
     * Create a new clinic owned by the authenticated doctor.
     */
    public function store(Request $request): JsonResponse
    {
        abort_if($request->user()->workspace_access_lock_id !== null, 403, 'Esta cuenta solo puede usar su clínica asignada.');

        $doctor = $this->doctorOrFail();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        try {
            $clinic = $this->clinics->createClinic($doctor, $data['name']);
        } catch (DomainException) {
            return response()->json([
                'code' => 'clinic_trial_limit_reached',
                'message' => 'Tu cuenta no tiene habilitada la creación de una clínica nueva. Escríbenos y la habilitamos.',
            ], 409);
        }

        return WorkspaceResource::make($clinic)->response()->setStatusCode(201);
    }

    /**
     * Join an existing clinic using its invite code.
     */
    public function join(Request $request): JsonResponse
    {
        abort_if($request->user()->workspace_access_lock_id !== null, 403, 'Esta cuenta solo puede usar su clínica asignada.');

        $doctor = $this->doctorOrFail();

        $data = $request->validate([
            'invite_code' => ['required', 'string', 'max:50'],
        ]);

        $clinic = $this->clinics->joinByInviteCode($doctor, $data['invite_code']);

        return WorkspaceResource::make($clinic)->response();
    }

    /**
     * Show a clinic's current invite code (owner only).
     */
    public function inviteCode(int $id): JsonResponse
    {
        $clinic = $this->ownedClinicOrFail($id);

        return response()->json(['invite_code' => $clinic->invite_code]);
    }

    /**
     * Regenerate a clinic's invite code, invalidating the previous one (owner only).
     */
    public function regenerateCode(int $id): JsonResponse
    {
        $clinic = $this->ownedClinicOrFail($id);

        $code = $this->clinics->regenerateInviteCode($clinic);

        return response()->json(['invite_code' => $code]);
    }

    /**
     * Clinic day-queue: appointments for a date arranged along the operational
     * pipeline (scheduled → checked_in → vitals_done → with_doctor → completed),
     * with the pills the operations dashboard shows. Runs under the `workspace`
     * middleware, so WorkspaceScope isolates the appointments automatically.
     */
    public function queue(Request $request): JsonResponse
    {
        $date = $request->query('date')
            ? Carbon::parse($request->query('date'))
            : Carbon::today();

        $appointments = Appointment::query()
            ->with([
                'doctor',
                'patient' => fn ($q) => $q->withMax('medicalRecords', 'created_at')->withCount('allergies'),
                'medicalRecords' => fn ($q) => $q->whereIn('status', ['draft', 'vitals_done'])->with('vitals'),
            ])
            ->whereDate('appointment_date', $date->toDateString())
            ->orderBy('appointment_date')
            ->get();

        $byStatus = fn (string ...$statuses) => $appointments
            ->whereIn('status', $statuses)
            ->count();

        return response()->json([
            'data' => [
                'date' => $date->toDateString(),
                'stats' => [
                    'waiting' => $byStatus('checked_in', 'vitals_done', 'with_doctor'),
                    'pending_vitals' => $byStatus('checked_in'),
                    'ready' => $byStatus('vitals_done'),
                    'with_doctor' => $byStatus('with_doctor'),
                    'completed' => $byStatus('completed'),
                    'no_show' => $byStatus('no_show'),
                    'total' => $appointments->count(),
                ],
                'appointments' => $appointments->map(fn (Appointment $a) => [
                    'id' => $a->id,
                    // Wall-clock, no offset (see AppointmentResource).
                    'time' => $a->appointment_date?->format('Y-m-d\TH:i:s'),
                    'status' => $a->status,
                    'checked_in_at' => $a->checked_in_at?->toIso8601String(),
                    'draft_record_id' => $a->medicalRecords->first()?->id,
                    // The waiting room shows them before the doctor calls the
                    // patient in: who is feverish or desaturating goes first.
                    'vitals' => ($v = $a->medicalRecords->first()?->vitals->sortByDesc('id')->first()) ? [
                        'blood_pressure_sys' => $v->blood_pressure_sys,
                        'blood_pressure_dia' => $v->blood_pressure_dia,
                        'heart_rate' => $v->heart_rate,
                        'temperature' => $v->temperature,
                        'oxygen_saturation' => $v->oxygen_saturation !== null ? (int) round((float) $v->oxygen_saturation) : null,
                        'respiratory_rate' => $v->respiratory_rate,
                        'weight_kg' => $v->weight_kg,
                    ] : null,
                    'doctor' => $a->doctor ? ['id' => $a->doctor->id, 'name' => $a->doctor->name] : null,
                    'patient' => $a->patient ? [
                        'id' => $a->patient->id,
                        'name' => $a->patient->name,
                        'id_number' => $a->patient->id_number,
                        'is_first_visit' => $a->patient->medical_records_max_created_at === null,
                        'has_allergies' => (int) ($a->patient->allergies_count ?? 0) > 0,
                    ] : null,
                ])->values(),
            ],
        ]);
    }

    /**
     * Clinic team roster: members with their role, status and ownership. Read
     * access (team.view) for any role that can see the team; management is in the
     * team admin endpoints (Phase 4).
     */
    public function team(ActiveWorkspace $activeWorkspace): JsonResponse
    {
        $workspace = $activeWorkspace->current();

        // Commission percentages are compensation data: only whoever manages
        // the team (the owner) sees them in the roster. The cashier still gets
        // the attending doctor's default pct via /billing/doctors when invoicing.
        $seesCommission = app(WorkspacePermissionService::class)
            ->can(Auth::user(), $workspace, 'team.manage');

        $members = $workspace->members()
            ->with(['user', 'doctor', 'staffProfile'])
            ->orderBy('role')
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->doctor?->name ?? $m->staffProfile?->name ?? $m->user?->name ?? '—',
                'email' => $m->user?->email,
                'role' => $m->role,
                'roles' => $m->roleList(),
                'status' => $m->status,
                'commission_pct' => $seesCommission ? $m->commission_pct : null,
                'doctor_id' => $m->doctor_id,
                'account_type' => $m->user?->account_type,
                'phone' => $m->doctor?->phone ?? $m->staffProfile?->phone,
                'is_owner' => $m->user_id !== null && (int) $workspace->owner_user_id === (int) $m->user_id,
            ])
            ->values();

        return response()->json(['data' => $members]);
    }

    /** A minimal, active-doctor list for the authenticated clinic switcher. */
    public function switchableDoctors(ActiveWorkspace $activeWorkspace): JsonResponse
    {
        $workspace = $activeWorkspace->current();

        abort_unless($workspace->type === 'clinic', 404);

        return response()->json(['data' => $workspace->members()
            ->where('status', 'active')
            ->whereNotNull('doctor_id')
            ->with(['user', 'doctor'])
            ->get()
            ->filter(fn ($member) => $member->user && $member->doctor)
            ->map(fn ($member) => [
                'name' => $member->doctor->name,
                'email' => $member->user->email,
                'photo_url' => \App\Support\PrivateImageUrl::for($member->doctor->profile_photo_path),
            ])->values()]);
    }

    /**
     * Next patient ready for the doctor today — powers the "Finalizar y atender
     * al siguiente" button of the mandatory-closure screen. Returns the earliest
     * appointment that is ready (vitals_done) or at least present (checked_in),
     * optionally narrowed to a doctor, with its open draft so the consultation
     * can pre-fill the nurse's vitals. Null when the queue is clear.
     */
    public function nextPatient(Request $request): JsonResponse
    {
        $query = Appointment::query()
            ->with(['medicalRecords' => fn ($q) => $q->whereIn('status', ['draft', 'vitals_done'])])
            ->whereDate('appointment_date', Carbon::today()->toDateString())
            ->whereIn('status', ['vitals_done', 'checked_in'])
            ->orderByRaw("CASE status WHEN 'vitals_done' THEN 0 ELSE 1 END")
            ->orderBy('appointment_date');

        if ($doctorId = $request->query('doctor_id')) {
            $query->where('doctor_id', $doctorId);
        }

        $appointment = $query->first();

        if (! $appointment) {
            return response()->json(['data' => null]);
        }

        return response()->json([
            'data' => [
                'appointment_id' => $appointment->id,
                'patient_id' => $appointment->patient_id,
                'status' => $appointment->status,
                'draft_record_id' => $appointment->medicalRecords->first()?->id,
            ],
        ]);
    }

    private function doctorOrFail(): Doctor
    {
        $doctor = Auth::user()->doctor;

        abort_if($doctor === null, 403, 'Esta cuenta no está asociada a un médico.');

        return $doctor;
    }

    /**
     * Resolve a clinic the authenticated user OWNS, or 403/404. Uses isOwnedBy()
     * so any account type that owns a clinic (e.g. a nurse-owner) can manage its
     * invite code — not just doctor owners.
     */
    private function ownedClinicOrFail(int $id): Workspace
    {
        $lockedId = Auth::user()->workspace_access_lock_id;
        abort_if($lockedId !== null && (int) $lockedId !== $id, 403, 'Esta cuenta solo puede usar su clínica asignada.');
        abort_if($lockedId !== null && ! Auth::user()->memberships()
            ->where('workspace_id', $id)
            ->where('status', 'active')
            ->exists(), 403, 'No tienes acceso activo a esta clínica.');

        $clinic = Workspace::where('type', 'clinic')->findOrFail($id);

        abort_unless($clinic->isOwnedBy(Auth::user()), 403, 'No eres el propietario de esta clínica.');

        return $clinic;
    }
}

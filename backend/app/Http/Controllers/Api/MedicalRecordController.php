<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMedicalRecordRequest;
use App\Http\Resources\MedicalRecordResource;
use App\Http\Resources\TriageRecordResource;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\Pregnancy;
use App\Models\RecordVital;
use App\Models\Specialty;
use App\Models\StudyOrder;
use App\Services\Clinical\ClinicalTemplateRegistry;
use App\Services\Clinical\WhoChildGrowthAssessment;
use App\Services\Obstetrics\ScoreMama;
use App\Services\PrescriptionService;
use App\Services\WorkspacePermissionService;
use App\Support\AccessLogger;
use App\Support\ActiveWorkspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Full clinical record access, consultation creation/closure, prescription PDF
 * and attachments.
 *
 * Isolation is handled by WorkspaceScope (bound by ResolveActiveWorkspace):
 * records outside the active workspace resolve to 404. The record's structured
 * children (vitals / diagnoses / prescriptions / attachments) have NO scope of
 * their own and are therefore always created and read through the scoped record.
 */
class MedicalRecordController extends Controller
{
    public function show(int $id, AccessLogger $accessLogger): MedicalRecordResource
    {
        $record = MedicalRecord::with([
            'patient',
            'vitals',
            'growthAssessments',
            'diagnoses',
            'prescriptions',
            'services',
            'attachments',
            'studyOrders.items',
        ])->findOrFail($id);

        // LODP: record WHO viewed a full clinical record (reads aren't captured
        // by the write-audit trigger). Resolved after the scoped findOrFail so we
        // never log access to a record outside the active workspace.
        $accessLogger->log('medical_record', $record->id);

        return MedicalRecordResource::make($record);
    }

    /**
     * Create a consultation with its optional structured children. workspace_id
     * is auto-filled by BelongsToWorkspace; created_by is the authenticated
     * doctor. Patient and appointment are resolved through workspace-scoped
     * queries (404 on foreign ids), and children are persisted via the record's
     * relationships so they inherit its tenant isolation.
     */
    public function store(
        StoreMedicalRecordRequest $request,
        ActiveWorkspace $activeWorkspace,
        WorkspacePermissionService $permissions,
        ClinicalTemplateRegistry $templates,
        WhoChildGrowthAssessment $growth,
    ): JsonResponse {
        $data = $request->validated();
        $mayPrice = $this->mayPriceServices($activeWorkspace, $permissions);
        // Absent finalize is the pre-existing one-shot save: finalize=true.
        $finalize = $data['finalize'] ?? true;
        $consultationType = $data['consultation_type'] ?? 'general';
        $professionalTitle = $data['professional_title'] ?? Auth::user()->doctor?->primarySpecialty()?->name;
        $this->ensureSpecialtyCapabilityFor($consultationType);
        $templateVersion = $templates->currentVersion($consultationType);
        $templates->assertValid($consultationType, $templateVersion, $data['structured_exam'] ?? null);

        // 404 if the patient (or appointment) is outside the active workspace.
        $patient = Patient::findOrFail($data['patient_id']);

        if (! empty($data['appointment_id'])) {
            Appointment::findOrFail($data['appointment_id']);
        }

        $record = DB::transaction(function () use ($data, $mayPrice, $consultationType, $professionalTitle, $templateVersion, $patient, $growth, $finalize) {
            $record = MedicalRecord::create([
                'patient_id' => $data['patient_id'],
                'appointment_id' => $data['appointment_id'] ?? null,
                // created_by is the authoring Doctor (column renamed from doctor_id).
                'created_by' => Auth::user()->doctor?->id,
                'status' => $finalize ? 'completed' : 'in_progress',
                'closed_with_override' => (bool) ($data['closed_with_override'] ?? false),
                'completion_note' => $data['completion_note'] ?? null,
                'reason_for_consultation' => $data['reason_for_consultation'] ?? null,
                'consultation_type' => $consultationType,
                'professional_title' => $professionalTitle,
                // Links the visit to the pregnancy it belongs to, so the timeline
                // exists without asking the doctor to pick it from a list.
                'pregnancy_id' => self::pregnancyFor($consultationType, (int) $data['patient_id']),
                'structured_exam_template_version' => $templateVersion,
                'current_illness' => $data['current_illness'] ?? null,
                'evolution' => $data['evolution'] ?? null,
                'physical_examination' => $data['physical_examination'] ?? null,
                'structured_exam' => $data['structured_exam'] ?? null,
                'review_of_systems' => $data['review_of_systems'] ?? null,
                'pain_scale' => $data['pain_scale'] ?? null,
                'diagnosis' => $data['diagnosis'] ?? null,
                'treatment' => $data['treatment'] ?? null,
                'recommendations' => $data['recommendations'] ?? null,
                'alarm_signs' => $data['alarm_signs'] ?? null,
                'controlled_prescription_no' => $data['controlled_prescription_no'] ?? null,
                'follow_up_date' => $data['follow_up_date'] ?? null,
                'follow_up_notes' => $data['follow_up_notes'] ?? null,
            ]);

            if (! empty($data['vitals']) && array_filter($data['vitals'], fn ($v) => $v !== null)) {
                $vitals = $this->withDerivedFields($data['vitals']);
                $vitals['recorded_at'] ??= now();
                $vital = $record->vitals()->create($vitals);
                $this->syncGrowthAssessment($record, $patient, $vital, $consultationType, $growth);
            }

            foreach ($data['diagnoses'] ?? [] as $diagnosis) {
                $record->diagnoses()->create([
                    'cie10_code' => $diagnosis['cie10_code'],
                    'cie10_description' => $diagnosis['cie10_description'] ?? null,
                    'type' => $diagnosis['type'] ?? 'primary',
                ]);
            }

            foreach ($data['prescriptions'] ?? [] as $prescription) {
                $record->prescriptions()->create([
                    'medication_name' => $prescription['medication_name'],
                    'generic_name' => $prescription['generic_name'] ?? null,
                    'concentration' => $prescription['concentration'] ?? null,
                    'dosage' => $prescription['dosage'] ?? null,
                    'route' => $prescription['route'] ?? null,
                    'frequency' => $prescription['frequency'] ?? null,
                    'duration_days' => $prescription['duration_days'] ?? null,
                    'quantity' => $prescription['quantity'] ?? null,
                    'instructions' => $prescription['instructions'] ?? null,
                    'is_controlled' => (bool) ($prescription['is_controlled'] ?? false),
                ]);
            }

            $this->storeServices($record, $data['services'] ?? [], $mayPrice);

            // Every FINALIZED visit becomes an appointment, including an ad-hoc
            // one — an autosaved draft (finalize=false) does not touch the
            // appointment/queue side of things at all, on purpose: it exists so
            // the prescription/study-order PDFs can be generated mid-visit, not
            // to mark the visit as attended before the doctor actually closes it.
            //
            // This used to be clinic-only, on the reasoning that a solo doctor
            // charges straight from the closing screen and therefore needs no
            // appointment to reach caja. True for billing, wrong for everything
            // else: "Citas y atenciones" lists APPOINTMENTS, so a consultation in
            // a personal workspace was saved and then invisible in the very tab
            // the doctor checks afterwards — which reads as "it was not saved".
            //
            // The consequence to know about: a solo doctor's unbilled visit now
            // also appears in "Por cobrar". That is correct — the work happened
            // and has not been charged — but it is a change in what that list
            // shows for personal workspaces.
            if ($finalize) {
                $this->finalizeVisit($record);
            }

            return $record;
        });

        $record->load(['patient', 'vitals', 'growthAssessments', 'diagnoses', 'prescriptions', 'services', 'attachments']);

        return MedicalRecordResource::make($record)->response()->setStatusCode(201);
    }

    /**
     * Start (or resume) a triage draft from an appointment. Nurse-facing first
     * step of the handoff: creates a `draft` record linked to the appointment so
     * vitals can be captured before the doctor sees the patient. Idempotent — an
     * existing open draft for the appointment is returned instead of duplicated.
     */
    public function draft(Request $request): JsonResponse
    {
        $data = $request->validate([
            'appointment_id' => ['required', 'integer'],
        ]);

        // 404 if the appointment is outside the active workspace.
        $appointment = Appointment::findOrFail($data['appointment_id']);
        abort_if(in_array($appointment->status, ['completed', 'cancelled'], true), 409, 'Esta cita ya no admite triaje.');

        $record = MedicalRecord::query()
            ->where('appointment_id', $appointment->id)
            ->whereIn('status', ['draft', 'vitals_done'])
            ->whereNull('voided_at')
            ->first();

        if (! $record) {
            abort_if(MedicalRecord::query()
                ->where('appointment_id', $appointment->id)
                ->whereNull('voided_at')
                ->whereIn('status', ['in_progress', 'completed'])
                ->exists(), 409, 'Esta cita ya tiene una atención médica.');

            $record = MedicalRecord::create([
                'patient_id' => $appointment->patient_id,
                'appointment_id' => $appointment->id,
                'created_by' => Auth::user()->doctor?->id,
                'status' => 'draft',
                'triaged_by' => Auth::user()->doctor?->id,
                'triaged_at' => now(),
            ]);
        }

        $record->load(['patient', 'vitals']);

        return TriageRecordResource::make($record)->response()->setStatusCode(201);
    }

    /**
     * Record/replace the triage vitals on a draft (nurse only — gated by
     * vitals.create). Writes ONLY vital signs, advances the record to
     * `vitals_done` and moves the linked appointment into the "ready for doctor"
     * stage. Diagnosis/treatment/prescriptions are never accepted here.
     */
    public function vitals(Request $request, int $id, WhoChildGrowthAssessment $growth): TriageRecordResource
    {
        $record = MedicalRecord::findOrFail($id);
        abort_unless($record->voided_at === null && in_array($record->status, ['draft', 'vitals_done'], true), 409, 'Esta atención ya no admite triaje.');

        $vitals = $request->validate([
            'weight_kg' => ['nullable', 'numeric'],
            'height_cm' => ['nullable', 'numeric'],
            'head_circumference_cm' => ['nullable', 'numeric', 'between:20,70'],
            'length_height_measurement_position' => ['nullable', Rule::in(['recumbent_length', 'standing_height'])],
            'growth_assessment' => ['prohibited'],
            'growth_assessment_version' => ['prohibited'],
            'blood_pressure_sys' => ['nullable', 'integer'],
            'blood_pressure_dia' => ['nullable', 'integer'],
            'heart_rate' => ['nullable', 'integer'],
            'temperature' => ['nullable', 'numeric'],
            'oxygen_saturation' => ['nullable', 'numeric'],
            'respiratory_rate' => ['nullable', 'integer'],
            'oxygen_therapy' => ['nullable', Rule::in(ScoreMama::OXYGEN_THERAPY)],
            'consciousness_level' => ['nullable', Rule::in(ScoreMama::CONSCIOUSNESS)],
            'urine_output_ml_h' => ['nullable', 'numeric', 'min:0'],
            'urine_output_not_quantifiable' => ['nullable', 'boolean'],
        ]);

        $vitals = $this->withDerivedFields($vitals);
        $vitals['recorded_at'] = now();

        DB::transaction(function () use ($record, $vitals, $growth): void {
            $record = MedicalRecord::with(['appointment', 'patient'])
                ->lockForUpdate()
                ->findOrFail($record->id);
            abort_unless($record->voided_at === null && in_array($record->status, ['draft', 'vitals_done'], true), 409, 'Esta atención ya no admite triaje.');
            abort_if($record->appointment && in_array($record->appointment->status, ['completed', 'cancelled'], true), 409, 'Esta cita ya no admite triaje.');

            // One triage vital row per draft — update in place if present.
            $existing = $record->vitals()->latest('id')->first();
            if ($existing) {
                $existing->update($vitals);
                $vital = $existing->refresh();
            } else {
                $vital = $record->vitals()->create($vitals);
            }

            $this->syncGrowthAssessment($record, $record->patient, $vital, $record->consultation_type ?? 'general', $growth);

            if ($record->status === 'draft') {
                $record->update([
                    'status' => 'vitals_done',
                    'triaged_by' => $record->triaged_by ?? Auth::user()->doctor?->id,
                    'triaged_at' => $record->triaged_at ?? now(),
                ]);
            }

            if ($record->appointment && in_array($record->appointment->status, ['scheduled', 'checked_in'], true)) {
                $record->appointment->update(['status' => 'vitals_done']);
            }
        });

        $record->refresh()->load(['patient', 'vitals']);

        return TriageRecordResource::make($record);
    }

    /**
     * Doctor completes a (draft) consultation: clinical narrative + structured
     * diagnoses/prescriptions, marking the record `completed`. Gated by
     * records.manage, so a nurse cannot submit a diagnosis here. Replaces any
     * previously stored diagnoses/prescriptions when those arrays are provided.
     */
    public function update(
        Request $request,
        int $id,
        ActiveWorkspace $activeWorkspace,
        WorkspacePermissionService $permissions,
        ClinicalTemplateRegistry $templates,
        WhoChildGrowthAssessment $growth,
    ): MedicalRecordResource {
        $record = MedicalRecord::with(['appointment', 'patient', 'doctor.specialty', 'doctor.specialties'])->findOrFail($id);

        $author = $record->doctor ?? Auth::user()->doctor;

        // The shared waiting room lets two doctors press «Atender» on the same
        // patient. Each screen says who it believed signed the visit when it
        // opened (null for the nurse's draft); if a colleague signed it since,
        // the second doctor must not silently overwrite the first one's notes.
        // A colleague editing a visit knowingly (it opened already signed) is
        // still allowed, as before.
        if ($request->has('expected_created_by')
            && $record->created_by !== null
            && (int) $record->created_by !== (int) Auth::user()->doctor?->id
            && (int) $request->input('expected_created_by') !== (int) $record->created_by) {
            abort(409, 'A este paciente ya lo está atendiendo '.($record->doctor?->name ?? 'otro médico').'.');
        }

        $data = $request->validate([
            'reason_for_consultation' => ['nullable', 'string'],
            'consultation_type' => ['sometimes', 'nullable', Rule::in(MedicalRecord::CONSULTATION_TYPES)],
            // Free text, editable by the signing doctor even after closing
            // (the receta can be edited); see StoreMedicalRecordRequest.
            'professional_title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'current_illness' => ['nullable', 'string'],
            'evolution' => ['nullable', 'string'],
            'physical_examination' => ['nullable', 'string'],
            'structured_exam' => ['sometimes', 'nullable', 'array'],
            'structured_exam_template_version' => ['prohibited'],
            'review_of_systems' => ['nullable', 'string'],
            'pain_scale' => ['nullable', 'integer', 'between:0,10'],
            'diagnosis' => ['nullable', 'string'],
            'treatment' => ['nullable', 'string'],
            'recommendations' => ['nullable', 'string'],
            'alarm_signs' => ['nullable', 'string'],
            'controlled_prescription_no' => ['nullable', 'string', 'max:60'],
            'follow_up_date' => ['nullable', 'date'],
            'follow_up_notes' => ['nullable', 'string'],
            // Mandatory-closure metadata: the visit can be completed with the
            // minimum (motivo); closing without a diagnosis flags the override.
            'closed_with_override' => ['sometimes', 'boolean'],
            'completion_note' => ['nullable', 'string'],
            // Same meaning as in StoreMedicalRecordRequest: false re-syncs an
            // autosaved draft (e.g. before generating a mid-visit PDF) without
            // touching its linked appointment; absent/true finalizes it.
            'finalize' => ['sometimes', 'boolean'],
            'vitals' => ['sometimes', 'array'],
            'vitals.weight_kg' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'vitals.height_cm' => ['nullable', 'numeric', 'min:0', 'max:300'],
            'vitals.head_circumference_cm' => ['nullable', 'numeric', 'between:20,70'],
            'vitals.length_height_measurement_position' => ['nullable', Rule::in(['recumbent_length', 'standing_height'])],
            'vitals.growth_assessment' => ['prohibited'],
            'vitals.growth_assessment_version' => ['prohibited'],
            'growth_assessment' => ['prohibited'],
            'vitals.blood_pressure_sys' => ['nullable', 'integer', 'min:50', 'max:300'],
            'vitals.blood_pressure_dia' => ['nullable', 'integer', 'min:30', 'max:200'],
            'vitals.heart_rate' => ['nullable', 'integer', 'min:20', 'max:300'],
            'vitals.temperature' => ['nullable', 'numeric', 'min:30', 'max:45'],
            'vitals.oxygen_saturation' => ['nullable', 'numeric', 'min:50', 'max:100'],
            'vitals.respiratory_rate' => ['nullable', 'integer', 'min:5', 'max:80'],
            'vitals.oxygen_therapy' => ['nullable', Rule::in(ScoreMama::OXYGEN_THERAPY)],
            'vitals.consciousness_level' => ['nullable', Rule::in(ScoreMama::CONSCIOUSNESS)],
            'vitals.urine_output_ml_h' => ['nullable', 'numeric', 'min:0'],
            'vitals.urine_output_not_quantifiable' => ['nullable', 'boolean'],
            'diagnoses' => ['sometimes', 'array'],
            'diagnoses.*.cie10_code' => ['required_with:diagnoses', 'string'],
            'diagnoses.*.cie10_description' => ['nullable', 'string'],
            'diagnoses.*.type' => ['nullable', 'string'],
            'prescriptions' => ['sometimes', 'array'],
            'prescriptions.*.medication_name' => ['required_with:prescriptions', 'string'],
            'prescriptions.*.generic_name' => ['nullable', 'string'],
            'prescriptions.*.concentration' => ['nullable', 'string'],
            // Both columns became nullable on 2026-09-21 (migration
            // make_prescription_posology_nullable), when the form stopped asking for
            // structured posology. Same contract as StoreMedicalRecordRequest.
            'prescriptions.*.dosage' => ['nullable', 'string', 'max:100'],
            'prescriptions.*.route' => ['nullable', 'string'],
            'prescriptions.*.frequency' => ['nullable', 'string', 'max:100'],
            'prescriptions.*.duration_days' => ['nullable', 'integer'],
            'prescriptions.*.quantity' => ['required_with:prescriptions', 'integer', 'min:1'],
            'prescriptions.*.instructions' => ['nullable', 'string'],
            'prescriptions.*.is_controlled' => ['nullable', 'boolean'],
            // Same contract as POST /records; unit_price is only kept for a
            // caller who may set one.
            'services' => ['sometimes', 'array'],
            'services.*.medical_service_id' => ['nullable', 'integer', 'exists:medical_services,id'],
            'services.*.name' => ['required_with:services', 'string', 'max:255'],
            'services.*.category' => ['nullable', 'string', 'max:40'],
            'services.*.quantity' => ['nullable', 'numeric', 'min:0.01'],
            'services.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'services.*.notes' => ['nullable', 'string', 'max:255'],
        ]);

        $consultationType = $data['consultation_type'] ?? $record->consultation_type ?? 'general';
        if ($record->status === 'completed' && ($data['finalize'] ?? true) === false) {
            throw ValidationException::withMessages([
                'finalize' => ['No se puede reabrir una atención completada.'],
            ]);
        }
        if (array_key_exists('professional_title', $data)
            && $data['professional_title'] !== $record->professional_title
            && $record->created_by !== null) {
            abort_unless((int) $record->created_by === (int) Auth::user()->doctor?->id, 403, 'Solo quien firma esta atención puede cambiar su título profesional.');
        }
        $professionalTitle = $data['professional_title'] ?? $record->professional_title;
        if ($professionalTitle === null && $record->status !== 'completed') {
            $professionalTitle = $author?->primarySpecialty()?->name;
        }
        // Capability applies to both sides of a type change. Otherwise a user
        // without the module could turn a prenatal/gynecological draft into a
        // general record and mutate specialized clinical data through the
        // ordinary record endpoint.
        $this->ensureSpecialtyCapabilityFor($record->consultation_type ?? 'general');
        $this->ensureSpecialtyCapabilityFor($consultationType);
        $changesConsultationType = array_key_exists('consultation_type', $data)
            && $consultationType !== $record->consultation_type;
        $templateVersion = $changesConsultationType
            ? $templates->currentVersion($consultationType)
            : ($record->structured_exam_template_version ?? $templates->currentVersion($consultationType));

        if ($changesConsultationType && $record->status === 'completed') {
            throw ValidationException::withMessages([
                'consultation_type' => ['No se puede cambiar el tipo de una atención ya completada.'],
            ]);
        }

        if ($changesConsultationType && ! empty($record->structured_exam)) {
            throw ValidationException::withMessages([
                'consultation_type' => ['No se puede cambiar el tipo de una atención que ya contiene exploración.'],
            ]);
        }

        if (array_key_exists('structured_exam', $data)) {
            $templates->assertValid(
                $consultationType,
                $templateVersion,
                $data['structured_exam'],
                array_keys($record->structured_exam ?? []),
            );
        }

        $mayPrice = $this->mayPriceServices($activeWorkspace, $permissions);
        $finalize = $data['finalize'] ?? true;

        DB::transaction(function () use ($record, $data, $mayPrice, $consultationType, $professionalTitle, $templateVersion, $growth, $finalize): void {
            // A key that is present wins even when null: the encounter saves as
            // the doctor goes, so emptying a field has to empty it here too.
            // An absent key keeps the stored value.
            $sent = fn (string $key) => array_key_exists($key, $data) ? $data[$key] : $record->{$key};

            $record->update([
                // Author the record on completion if it was opened as a nurse draft.
                'created_by' => $record->created_by ?? Auth::user()->doctor?->id,
                // The reason is the one field a visit cannot lose.
                'reason_for_consultation' => $data['reason_for_consultation'] ?? $record->reason_for_consultation,
                'consultation_type' => $consultationType,
                'professional_title' => $professionalTitle,
                'structured_exam_template_version' => $templateVersion,
                'current_illness' => $sent('current_illness'),
                'evolution' => $sent('evolution'),
                'physical_examination' => $sent('physical_examination'),
                'structured_exam' => $sent('structured_exam'),
                'review_of_systems' => $sent('review_of_systems'),
                'pain_scale' => $sent('pain_scale'),
                'diagnosis' => $sent('diagnosis'),
                'treatment' => $sent('treatment'),
                'recommendations' => $sent('recommendations'),
                'alarm_signs' => $sent('alarm_signs'),
                'controlled_prescription_no' => $sent('controlled_prescription_no'),
                'follow_up_date' => $sent('follow_up_date'),
                'follow_up_notes' => $sent('follow_up_notes'),
                'closed_with_override' => (bool) ($data['closed_with_override'] ?? $record->closed_with_override),
                'completion_note' => $sent('completion_note'),
                'status' => $finalize ? 'completed' : 'in_progress',
            ]);

            if (array_key_exists('diagnoses', $data)) {
                $record->diagnoses()->delete();
                foreach ($data['diagnoses'] as $diagnosis) {
                    $record->diagnoses()->create([
                        'cie10_code' => $diagnosis['cie10_code'],
                        'cie10_description' => $diagnosis['cie10_description'] ?? null,
                        'type' => $diagnosis['type'] ?? 'primary',
                    ]);
                }
            }

            if (array_key_exists('prescriptions', $data)) {
                $record->prescriptions()->delete();
                foreach ($data['prescriptions'] as $prescription) {
                    $record->prescriptions()->create([
                        'medication_name' => $prescription['medication_name'],
                        'generic_name' => $prescription['generic_name'] ?? null,
                        'concentration' => $prescription['concentration'] ?? null,
                        'dosage' => $prescription['dosage'] ?? null,
                        'route' => $prescription['route'] ?? null,
                        'frequency' => $prescription['frequency'] ?? null,
                        'duration_days' => $prescription['duration_days'] ?? null,
                        'quantity' => $prescription['quantity'] ?? null,
                        'instructions' => $prescription['instructions'] ?? null,
                        'is_controlled' => (bool) ($prescription['is_controlled'] ?? false),
                    ]);
                }
            }

            if (array_key_exists('services', $data)) {
                $record->services()->delete();
                $this->storeServices($record, $data['services'], $mayPrice);
            }

            if (! empty($data['vitals']) && array_filter($data['vitals'], fn ($v) => $v !== null)) {
                $vitals = $this->withDerivedFields($data['vitals']);
                $vitals['recorded_at'] = now();
                $existing = $record->vitals()->latest('id')->first();
                if ($existing) {
                    $existing->update($vitals);
                    $vital = $existing->refresh();
                } else {
                    $vital = $record->vitals()->create($vitals);
                }
                $this->syncGrowthAssessment($record, $record->patient, $vital, $consultationType, $growth);
            }

            // A draft (nurse triage OR autosaved by the doctor) reaching here
            // finalized needs the same walk-in appointment a one-shot save
            // would have gotten — draft() never creates one for a walk-in
            // because only the queue opens a nurse draft against an existing
            // appointment; an autosaved walk-in never had one either.
            if ($finalize) {
                $this->finalizeVisit($record);
            }
        });

        $record->load(['patient', 'vitals', 'growthAssessments', 'diagnoses', 'prescriptions', 'services', 'attachments']);

        return MedicalRecordResource::make($record);
    }

    /**
     * Finalize a visit: attach an ad-hoc walk-in appointment if the record
     * still has none (an autosaved draft is saved without one on purpose, so
     * it never shows up as "attended" before the doctor actually closes it),
     * then mark whatever appointment is now linked as completed. Shared by
     * store() and update() so a visit autosaved via POST and later finalized
     * via PUT gets the same treatment a one-shot save would have.
     */
    private function finalizeVisit(MedicalRecord $record): void
    {
        // «Iniciar atención» on a patient who already has an appointment today
        // (booked with whoever, maybe a doctor who did not come) attends THAT
        // appointment. Opening a walk-in instead left the booking «Agendada»
        // forever and the agenda showing the patient twice.
        if (! $record->appointment_id && $record->created_by) {
            $booked = Appointment::query()
                ->where('patient_id', $record->patient_id)
                ->whereDate('appointment_date', today())
                ->whereIn('status', ['scheduled', 'checked_in', 'vitals_done', 'with_doctor'])
                ->whereDoesntHave('medicalRecords', fn ($q) => $q->whereNull('voided_at')->whereKeyNot($record->id))
                ->orderByRaw('abs(extract(epoch from (appointment_date - ?::timestamp)))', [now()->format('Y-m-d H:i:s')])
                ->first();
            if ($booked) {
                $record->update(['appointment_id' => $booked->id]);
            }
        }

        if (! $record->appointment_id && $record->created_by) {
            $workspace = app(ActiveWorkspace::class)->current();
            if ($workspace) {
                $appointment = Appointment::create([
                    'patient_id' => $record->patient_id,
                    'doctor_id' => $record->created_by,
                    'appointment_date' => now(),
                    'status' => 'completed',
                    'checked_in_at' => now(),
                    'type' => 'walk_in',
                    'channel' => 'in_person',
                ]);
                $record->update(['appointment_id' => $appointment->id, 'opened_own_appointment' => true]);
            }
        }

        if ($record->appointment_id) {
            // Always re-fetched rather than trusting a possibly-cached relation:
            // the block above may have just set appointment_id on this same
            // instance, and a stale `$record->appointment` would still be null.
            $appointment = Appointment::find($record->appointment_id);
            if ($appointment && $appointment->status !== 'completed') {
                $appointment->update(['status' => 'completed']);
            }
            // In a clinic any doctor may attend a colleague's patient (one did not
            // come in). The visit then belongs to whoever attended it: left on the
            // absent doctor, it read as «attended» by someone who never was there.
            if ($appointment && $record->created_by && $appointment->doctor_id !== $record->created_by) {
                $appointment->update(['doctor_id' => $record->created_by]);
            }
        }
    }

    /**
     * BMI = weight(kg) / height(m)^2, rounded to 2 decimals. No-op when either
     * measurement is missing. Shared by the one-shot create and triage vitals.
     *
     * @param  array<string, mixed>  $vitals
     * @return array<string, mixed>
     */
    /**
     * Everything a vitals row derives from what was measured: BMI, and the Score
     * MAMA.
     *
     * The score is computed HERE, from the values the server received, and any
     * score_mama_* the caller sent is dropped first. A client is a display, not a
     * source of clinical truth — a stale bundle must not be able to write a calm
     * score over an alarming reading.
     */
    /**
     * The pregnancy a prenatal visit belongs to, or null.
     *
     * Resolved server-side and never asked of the client: the patient can only
     * have one active pregnancy (a partial unique index guarantees it), so there
     * is nothing to choose. Making the doctor pick it from a list would be a
     * question with one possible answer, and the timeline would be empty every
     * time somebody skipped it.
     *
     * Only prenatal visits link. A gynecological consultation during a pregnancy
     * is not a prenatal control, and putting it on the follow-up timeline would
     * misrepresent the record.
     */
    private static function pregnancyFor(?string $consultationType, int $patientId): ?int
    {
        if ($consultationType !== 'prenatal') {
            return null;
        }

        return Pregnancy::query()
            ->where('patient_id', $patientId)
            ->where('status', 'active')
            ->value('id');
    }

    /**
     * Records stay generally available under records.*. Only the two curated
     * gyneco-obstetric consultation types require the specialty capability,
     * including edits to an existing record of either type.
     */
    private function ensureSpecialtyCapabilityFor(string $consultationType): void
    {
        if (! in_array($consultationType, ['gynecological', 'prenatal'], true)) {
            return;
        }

        if (! Auth::user()?->doctor?->hasSpecialtyCapability(Specialty::CAPABILITY_GYNECO_OBSTETRICS)) {
            throw new AuthorizationException('No tienes acceso a este módulo clínico.');
        }
    }

    private function withDerivedFields(array $vitals): array
    {
        unset($vitals['score_mama_total'], $vitals['score_mama_level'], $vitals['score_mama_protocol']);
        unset($vitals['growth_assessment'], $vitals['growth_assessment_version'], $vitals['growth_percentile'], $vitals['growth_z_score']);

        if (! empty($vitals['weight_kg']) && ! empty($vitals['height_cm'])) {
            $meters = ((float) $vitals['height_cm']) / 100;
            if ($meters > 0) {
                $vitals['bmi'] = round(((float) $vitals['weight_kg']) / ($meters ** 2), 2);
            }
        }

        return array_merge($vitals, ScoreMama::columnsFor($vitals));
    }

    private function syncGrowthAssessment(
        MedicalRecord $record,
        ?Patient $patient,
        RecordVital $vital,
        string $consultationType,
        WhoChildGrowthAssessment $growth,
    ): void {
        if ($consultationType !== 'pediatric' || $patient === null) {
            return;
        }

        $assessment = $growth->assess($patient, $vital);
        $record->growthAssessments()->updateOrCreate(
            ['record_vital_id' => $vital->id],
            [
                'record_vital_id' => $vital->id,
                'status' => $assessment['status'],
                'reason' => $assessment['reason'],
                'measurement_date' => $assessment['measurement_date'],
                'age_days' => $assessment['age_days'],
                'sex' => $assessment['sex'],
                'weight_kg' => $assessment['weight_kg'],
                'length_height_cm' => $assessment['length_height_cm'],
                'length_height_adjusted_cm' => $assessment['length_height_adjusted_cm'],
                'length_height_measurement_position' => $assessment['length_height_measurement_position'],
                'head_circumference_cm' => $assessment['head_circumference_cm'],
                'standard' => WhoChildGrowthAssessment::STANDARD,
                'standard_version' => WhoChildGrowthAssessment::VERSION,
                'source_url' => WhoChildGrowthAssessment::SOURCE_URL,
                'table_checksums' => $assessment['table_checksums'],
                'indicators' => $assessment['indicators'],
            ],
        );
    }

    /**
     * Whether this caller may attach prices to the services they declare.
     *
     * This is the whole "solo consultorio vs clinic with a cash desk" split, and
     * it needs no setting of its own: a solo doctor owns their workspace and
     * holds billing.manage, while the `doctor` role in a clinic holds only
     * billing.view. So the same screen shows prices to one and not the other,
     * and the server — not the screen — is what enforces it.
     */
    private function mayPriceServices(
        ActiveWorkspace $activeWorkspace,
        WorkspacePermissionService $permissions,
    ): bool {
        $workspace = $activeWorkspace->current();
        $user = Auth::user();

        if (! $workspace || ! $user) {
            return false;
        }

        return $permissions->can($user, $workspace, 'billing.manage');
    }

    /**
     * Persist the services performed during the visit.
     *
     * The price is dropped unless the caller may set one: a clinic doctor who
     * crafts a request with unit_price still stores the service unpriced, and
     * the cash desk decides what it costs.
     *
     * @param  array<int, array<string, mixed>>  $services
     */
    private function storeServices(MedicalRecord $record, array $services, bool $mayPrice): void
    {
        foreach ($services as $service) {
            $record->services()->create([
                'medical_service_id' => $service['medical_service_id'] ?? null,
                'name' => $service['name'],
                'category' => $service['category'] ?? null,
                'quantity' => $service['quantity'] ?? 1,
                'unit_price' => $mayPrice ? ($service['unit_price'] ?? null) : null,
                'notes' => $service['notes'] ?? null,
            ]);
        }
    }

    /**
     * Close the consultation: the system has no per-record status, so closure
     * is modelled at the appointment level (status → completed), matching the
     * "mandatory consultation closure" workflow. Idempotent and a no-op when the
     * record has no linked appointment (e.g. a walk-in with no slot).
     */
    /**
     * Voids a visit saved by mistake — the atención saves itself as soon as it
     * has a reason, so one opened on the wrong patient must be undoable.
     *
     * Only its author or the workspace owner may void it, any day, with a
     * reason. The record is soft-deleted (never hard-deleted: it is clinical
     * history) with who/when/why beside it. The appointment the visit opened
     * for itself is cancelled; one that came from the agenda goes back to
     * pending so the patient can still be attended. A visit already invoiced
     * cannot be voided until the invoice is.
     */
    public function void(Request $request, int $id): Response
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $record = MedicalRecord::findOrFail($id);
        $user = Auth::user();
        $workspace = app(ActiveWorkspace::class)->current();

        $isAuthor = $record->created_by !== null && (int) $record->created_by === (int) $user->doctor?->id;
        abort_unless($isAuthor || ($workspace && $workspace->isOwnedBy($user)), 403, 'Solo quien hizo la atención puede anularla.');

        $invoiced = $record->appointment_id !== null && Invoice::query()
            ->where('appointment_id', $record->appointment_id)
            ->whereNotIn('status', ['cancelled', 'void'])
            ->exists();
        abort_if($invoiced, 422, 'Esta atención ya fue facturada. Anula primero la factura en caja.');

        DB::transaction(function () use ($record, $data, $user): void {
            if ($record->appointment_id) {
                $appointment = Appointment::find($record->appointment_id);
                if ($appointment) {
                    $appointment->update(['status' => $record->opened_own_appointment ? 'cancelled' : 'scheduled']);
                }
            }

            $record->update([
                'voided_at' => now(),
                'voided_by' => $user->id,
                'void_reason' => $data['reason'],
            ]);
            $record->delete();
        });

        return response()->noContent();
    }

    public function close(int $id): MedicalRecordResource
    {
        $record = MedicalRecord::with('appointment')->findOrFail($id);

        $appointment = $record->appointment;

        if ($appointment && $appointment->status !== 'completed') {
            $appointment->update(['status' => 'completed']);
        }

        $record->load(['patient', 'vitals', 'diagnoses', 'prescriptions', 'services', 'attachments']);

        return MedicalRecordResource::make($record);
    }

    /**
     * Stream the prescription PDF (Spanish, patient-facing) generated by the
     * existing PrescriptionService.
     */
    public function prescriptionPdf(int $id, PrescriptionService $service, AccessLogger $accessLogger): Response
    {
        $record = MedicalRecord::findOrFail($id);

        abort_if($record->import_source === 'orpheus', 404);

        $accessLogger->log('medical_record', $record->id, 'prescription_pdf');

        return $service->pdf($record)->download("receta-{$record->id}.pdf");
    }

    /**
     * Upload an attachment (lab result, imaging, PDF) to a record. Stored on the
     * local disk; metadata persisted through the scoped record relationship.
     */
    public function storeAttachment(Request $request, int $id): JsonResponse
    {
        $record = MedicalRecord::findOrFail($id);

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,webp,heic,doc,docx'],
            'title' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            // When this document is the answer to a study order. Resolved
            // through the workspace-scoped model, so an order from another
            // clinic 404s instead of linking.
            'study_order_id' => ['nullable', 'integer'],
        ]);

        $studyOrder = null;
        if (! empty($validated['study_order_id'])) {
            $studyOrder = StudyOrder::findOrFail($validated['study_order_id']);

            // A result belongs to the patient it was ordered for. Without this
            // an order id from a different patient in the same clinic would
            // file a result under the wrong chart.
            abort_if($studyOrder->patient_id !== $record->patient_id, 422, 'La orden es de otro paciente.');
        }

        $file = $request->file('file');
        $path = $file->store("attachments/{$record->id}", 'local');

        $attachment = $record->attachments()->create([
            'patient_id' => $record->patient_id,
            'study_order_id' => $studyOrder?->id,
            'title' => $validated['title'] ?? $file->getClientOriginalName(),
            'file_path' => $path,
            'notes' => $validated['notes'] ?? null,
        ]);

        // Filing the result IS the news that it arrived; making the doctor also
        // flip a status would just create orders with a document and a "pending".
        $studyOrder?->update([
            'result_status' => 'completed',
            'result_received_at' => $studyOrder->result_received_at ?? now(),
        ]);

        return response()->json([
            'id' => $attachment->id,
            'title' => $attachment->title,
            'notes' => $attachment->notes,
            'study_order_id' => $attachment->study_order_id,
        ], 201);
    }
}

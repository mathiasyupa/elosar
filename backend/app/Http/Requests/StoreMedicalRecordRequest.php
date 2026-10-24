<?php

namespace App\Http\Requests;

use App\Models\MedicalRecord;
use App\Services\Obstetrics\ScoreMama;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation boundary for creating a full consultation (medical record) with its
 * optional structured children: vitals, CIE-10 diagnoses and prescriptions.
 *
 * workspace_id / created_by are injected server-side. The patient and (optional)
 * appointment are resolved through workspace-scoped queries in the controller,
 * and the children are persisted via the record relationship so they inherit the
 * parent's tenant isolation (they have no WorkspaceScope of their own).
 */
class StoreMedicalRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // finalize=false is an autosaved draft mid-visit (see EncounterPage's
        // "Prescripción"/"Exámenes" steps): the whole point is to get a real
        // record id before diagnosis/treatment are necessarily filled in, so
        // the full-closure requirement only applies when actually finalizing.
        $finalizing = ! $this->has('finalize') || $this->boolean('finalize');

        return [
            'patient_id' => ['required', 'integer'],
            'appointment_id' => ['nullable', 'integer'],
            'reason_for_consultation' => ['required', 'string'],
            'consultation_type' => ['sometimes', 'nullable', Rule::in(MedicalRecord::CONSULTATION_TYPES)],
            // Free text: the doctor writes how they sign ("Medicina General",
            // "Médico Familiar"…); the profile's specialties are only suggestions.
            'professional_title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'structured_exam' => ['sometimes', 'nullable', 'array'],
            'structured_exam_template_version' => ['prohibited'],
            'finalize' => ['sometimes', 'boolean'],
            // Full closure requires diagnosis + plan; override allows motivo-only (clinic rush).
            'diagnosis' => [$finalizing ? 'required_without:closed_with_override' : 'sometimes', 'nullable', 'string'],
            'treatment' => [$finalizing ? 'required_without:closed_with_override' : 'sometimes', 'nullable', 'string'],
            'closed_with_override' => ['sometimes', 'boolean'],
            'completion_note' => ['nullable', 'string'],
            'current_illness' => ['nullable', 'string'],
            'evolution' => ['nullable', 'string'],
            'physical_examination' => ['nullable', 'string'],
            'review_of_systems' => ['nullable', 'string'],
            'pain_scale' => ['nullable', 'integer', 'min:0', 'max:10'],
            'recommendations' => ['nullable', 'string'],
            'alarm_signs' => ['nullable', 'string'],
            'controlled_prescription_no' => ['nullable', 'string', 'max:60'],
            'follow_up_date' => ['nullable', 'date'],
            'follow_up_notes' => ['nullable', 'string'],

            'vitals' => ['nullable', 'array'],
            'vitals.weight_kg' => ['nullable', 'numeric', 'min:0'],
            'vitals.height_cm' => ['nullable', 'numeric', 'min:0'],
            'vitals.head_circumference_cm' => ['nullable', 'numeric', 'between:20,70'],
            'vitals.length_height_measurement_position' => ['nullable', Rule::in(['recumbent_length', 'standing_height'])],
            // A client supplies raw measurements only. The server owns every
            // OMS-derived value, reference and version.
            'vitals.growth_assessment' => ['prohibited'],
            'vitals.growth_assessment_version' => ['prohibited'],
            'growth_assessment' => ['prohibited'],
            'vitals.blood_pressure_sys' => ['nullable', 'integer', 'min:0'],
            'vitals.blood_pressure_dia' => ['nullable', 'integer', 'min:0'],
            'vitals.heart_rate' => ['nullable', 'integer', 'min:0'],
            'vitals.temperature' => ['nullable', 'numeric', 'min:0'],
            'vitals.oxygen_saturation' => ['nullable', 'integer', 'min:0', 'max:100'],
            'vitals.respiratory_rate' => ['nullable', 'integer', 'min:0'],
            // Score MAMA: conjuntos cerrados, no texto libre. Un valor que el
            // calculo no reconozca es una alarma que no suena.
            'vitals.oxygen_therapy' => ['nullable', Rule::in(ScoreMama::OXYGEN_THERAPY)],
            'vitals.consciousness_level' => ['nullable', Rule::in(ScoreMama::CONSCIOUSNESS)],
            'vitals.urine_output_ml_h' => ['nullable', 'numeric', 'min:0'],
            'vitals.urine_output_not_quantifiable' => ['nullable', 'boolean'],

            'diagnoses' => ['nullable', 'array'],
            'diagnoses.*.cie10_code' => ['required_with:diagnoses', 'string', 'max:10'],
            'diagnoses.*.cie10_description' => ['nullable', 'string', 'max:500'],
            'diagnoses.*.type' => ['nullable', 'in:primary,secondary,presumptive'],

            // The form captures name + dispensing quantity + instructions. Structured
            // posology (dosage/frequency) became optional on 2026-09-21 and nullable in
            // the schema; `quantity` stays required — it is the MSP "cantidad a
            // dispensar" and printing it blank was a real defect once.
            'prescriptions' => ['nullable', 'array'],
            'prescriptions.*.medication_name' => ['required_with:prescriptions', 'string', 'max:255'],
            'prescriptions.*.generic_name' => ['nullable', 'string', 'max:255'],
            'prescriptions.*.concentration' => ['nullable', 'string', 'max:100'],
            'prescriptions.*.dosage' => ['nullable', 'string', 'max:100'],
            'prescriptions.*.route' => ['nullable', 'string', 'max:50'],
            'prescriptions.*.frequency' => ['nullable', 'string', 'max:100'],
            'prescriptions.*.duration_days' => ['nullable', 'integer', 'min:0'],
            'prescriptions.*.quantity' => ['required_with:prescriptions', 'integer', 'min:1'],
            'prescriptions.*.instructions' => ['nullable', 'string'],
            'prescriptions.*.is_controlled' => ['nullable', 'boolean'],

            // Services and procedures performed, for the cash desk to invoice.
            // `unit_price` is accepted here but only KEPT for a caller who can
            // manage billing — the controller drops it otherwise, so a clinic
            // doctor cannot price their own work by crafting the request.
            'services' => ['nullable', 'array'],
            'services.*.medical_service_id' => ['nullable', 'integer', 'exists:medical_services,id'],
            'services.*.name' => ['required_with:services', 'string', 'max:255'],
            'services.*.category' => ['nullable', 'string', 'max:40'],
            'services.*.quantity' => ['nullable', 'numeric', 'min:0.01'],
            'services.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'services.*.notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}

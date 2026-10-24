<?php

namespace App\Http\Resources;

use App\Models\MedicalRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MedicalRecord
 */
class MedicalRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'appointment_id' => $this->appointment_id,
            'patient_id' => $this->patient_id,
            'status' => $this->status,
            'import_source' => $this->import_source,
            'closed_with_override' => (bool) $this->closed_with_override,
            'completion_note' => $this->completion_note,
            'triaged_at' => $this->triaged_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'reason_for_consultation' => $this->reason_for_consultation,
            'consultation_type' => $this->consultation_type,
            'professional_title' => $this->professional_title,
            'structured_exam_template_version' => $this->structured_exam_template_version,
            'current_illness' => $this->current_illness,
            'evolution' => $this->evolution,
            'physical_examination' => $this->physical_examination,
            'structured_exam' => $this->structured_exam,
            'review_of_systems' => $this->review_of_systems,
            'pain_scale' => $this->pain_scale,
            'diagnosis' => $this->diagnosis,
            'treatment' => $this->treatment,
            'recommendations' => $this->recommendations,
            'alarm_signs' => $this->alarm_signs,
            'controlled_prescription_no' => $this->controlled_prescription_no,
            'follow_up_date' => $this->follow_up_date?->toDateString(),
            'follow_up_notes' => $this->follow_up_notes,

            'patient' => PatientResource::make($this->whenLoaded('patient')),

            'vitals' => $this->whenLoaded('vitals', fn () => $this->vitals->map(fn ($vital) => [
                'id' => $vital->id,
                'weight_kg' => $vital->weight_kg,
                'height_cm' => $vital->height_cm,
                'head_circumference_cm' => $vital->head_circumference_cm,
                'length_height_measurement_position' => $vital->length_height_measurement_position,
                'bmi' => $vital->bmi,
                'blood_pressure_sys' => $vital->blood_pressure_sys,
                'blood_pressure_dia' => $vital->blood_pressure_dia,
                'heart_rate' => $vital->heart_rate,
                'temperature' => $vital->temperature,
                'oxygen_saturation' => $vital->oxygen_saturation,
                'oxygen_therapy' => $vital->oxygen_therapy,
                'consciousness_level' => $vital->consciousness_level,
                'urine_output_ml_h' => $vital->urine_output_ml_h,
                'urine_output_not_quantifiable' => $vital->urine_output_not_quantifiable,
                'score_mama_total' => $vital->score_mama_total,
                'score_mama_level' => $vital->score_mama_level,
                'score_mama_protocol' => $vital->score_mama_protocol,
                'respiratory_rate' => $vital->respiratory_rate,
                'recorded_at' => $vital->recorded_at?->toIso8601String(),
            ])),

            'growth_assessments' => $this->whenLoaded('growthAssessments', fn () => $this->growthAssessments->map(fn ($assessment) => [
                'id' => $assessment->id,
                'record_vital_id' => $assessment->record_vital_id,
                'status' => $assessment->status,
                'reason_code' => $assessment->reason,
                'measured_at' => $assessment->measurement_date?->toDateString(),
                'age_days' => $assessment->age_days,
                'sex' => $assessment->sex,
                'inputs' => [
                    'weight_kg' => $assessment->weight_kg,
                    'height_cm' => $assessment->length_height_cm,
                    'adjusted_height_cm' => $assessment->length_height_adjusted_cm,
                    'head_circumference_cm' => $assessment->head_circumference_cm,
                    'length_height_measurement_position' => $assessment->length_height_measurement_position,
                ],
                'indicators' => collect($assessment->indicators ?? [])->map(fn ($indicator, $code) => [
                    'code' => $code,
                    'status' => $indicator['status'] ?? 'not_calculable',
                    'z_score' => $indicator['z_score'] ?? null,
                    'percentile' => $indicator['percentile'] ?? null,
                    'reason_code' => $indicator['reason_code'] ?? null,
                ])->values(),
                'standard' => [
                    'source' => $assessment->standard,
                    'version' => $assessment->standard_version,
                    'source_url' => $assessment->source_url,
                    'table_checksums' => $assessment->table_checksums,
                ],
            ])),

            'diagnoses' => $this->whenLoaded('diagnoses', fn () => $this->diagnoses->map(fn ($diagnosis) => [
                'id' => $diagnosis->id,
                'cie10_code' => $diagnosis->cie10_code,
                'cie10_description' => $diagnosis->cie10_description,
                'type' => $diagnosis->type,
            ])),

            // What was performed, for whoever invoices. Money as strings
            // (bcmath): a float here becomes a wrong invoice line.
            'services' => $this->whenLoaded('services', fn () => $this->services->map(fn ($service) => [
                'id' => $service->id,
                'medical_service_id' => $service->medical_service_id,
                'name' => $service->name,
                'category' => $service->category,
                'quantity' => (string) $service->quantity,
                'unit_price' => $service->unit_price === null ? null : (string) $service->unit_price,
                'line_total' => $service->lineTotal(),
                'notes' => $service->notes,
            ])),

            'prescriptions' => $this->whenLoaded('prescriptions', fn () => $this->prescriptions->map(fn ($prescription) => [
                'id' => $prescription->id,
                'medication_name' => $prescription->medication_name,
                'generic_name' => $prescription->generic_name,
                'concentration' => $prescription->concentration,
                'dosage' => $prescription->dosage,
                'route' => $prescription->route,
                'frequency' => $prescription->frequency,
                'duration_days' => $prescription->duration_days,
                'quantity' => $prescription->quantity,
                'instructions' => $prescription->instructions,
                'is_controlled' => (bool) $prescription->is_controlled,
            ])),

            'attachments' => $this->whenLoaded('attachments', fn () => $this->attachments->map(fn ($attachment) => [
                'id' => $attachment->id,
                'title' => $attachment->title,
                'notes' => $attachment->notes,
            ])),

            'study_orders' => $this->whenLoaded('studyOrders', fn () => $this->studyOrders->map(fn ($order) => [
                'id' => $order->id,
                'type' => $order->type,
                'type_label' => $order->typeLabel(),
                'priority' => $order->priority,
                'to_be_performed_at' => $order->to_be_performed_at?->toDateString(),
                'created_at' => $order->created_at?->toIso8601String(),
                'items' => $order->relationLoaded('items')
                    ? $order->items->map(fn ($item) => [
                        'id' => $item->id,
                        'name' => $item->name,
                        'category' => $item->category,
                    ])
                    : [],
            ])),
        ];
    }
}

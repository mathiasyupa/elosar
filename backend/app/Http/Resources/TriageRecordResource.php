<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Only the identity and measurements needed by nursing, never clinical notes. */
class TriageRecordResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'appointment_id' => $this->appointment_id,
            'patient_id' => $this->patient_id,
            'status' => $this->status,
            'patient' => $this->whenLoaded('patient', fn () => [
                'id' => $this->patient->id,
                'name' => $this->patient->name,
            ]),
            'vitals' => $this->whenLoaded('vitals', fn () => $this->vitals->map(fn ($vital) => [
                'id' => $vital->id,
                'weight_kg' => $vital->weight_kg,
                'height_cm' => $vital->height_cm,
                'blood_pressure_sys' => $vital->blood_pressure_sys,
                'blood_pressure_dia' => $vital->blood_pressure_dia,
                'heart_rate' => $vital->heart_rate,
                'temperature' => $vital->temperature,
                'oxygen_saturation' => $vital->oxygen_saturation,
                'respiratory_rate' => $vital->respiratory_rate,
                'oxygen_therapy' => $vital->oxygen_therapy,
                'consciousness_level' => $vital->consciousness_level,
                'urine_output_ml_h' => $vital->urine_output_ml_h,
                'urine_output_not_quantifiable' => $vital->urine_output_not_quantifiable,
                'recorded_at' => $vital->recorded_at?->toIso8601String(),
            ])),
        ];
    }
}

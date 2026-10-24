<?php

namespace App\Http\Resources;

use App\Models\PatientVitalObservation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PatientVitalObservation */
class PatientVitalObservationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'patient_id' => $this->patient_id,
            'source' => 'standalone',
            'recorded_at' => $this->recorded_at?->toIso8601String(),
            'weight_kg' => $this->weight_kg,
            'height_cm' => $this->height_cm,
            'head_circumference_cm' => $this->head_circumference_cm,
            'length_height_measurement_position' => $this->length_height_measurement_position,
            'bmi' => $this->bmi,
            'blood_pressure_sys' => $this->blood_pressure_sys,
            'blood_pressure_dia' => $this->blood_pressure_dia,
            'heart_rate' => $this->heart_rate,
            'temperature' => $this->temperature,
            'oxygen_saturation' => $this->oxygen_saturation,
            'respiratory_rate' => $this->respiratory_rate,
            'oxygen_therapy' => $this->oxygen_therapy,
            'consciousness_level' => $this->consciousness_level,
            'urine_output_ml_h' => $this->urine_output_ml_h,
            'urine_output_not_quantifiable' => $this->urine_output_not_quantifiable,
            'score_mama_total' => $this->score_mama_total,
            'score_mama_level' => $this->score_mama_level,
            'score_mama_protocol' => $this->score_mama_protocol,
        ];
    }
}

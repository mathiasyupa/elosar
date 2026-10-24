<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\PatientImmunization
 */
class ImmunizationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'patient_id'      => $this->patient_id,
            'vaccine_id'      => $this->vaccine_id,
            'vaccine_name'    => $this->vaccine_name,
            'dose_label'      => $this->dose_label,
            'dose_label_text' => $this->doseLabelText(),
            'administered_at' => $this->administered_at?->toDateString(),
            'lot'             => $this->lot,
            'notes'           => $this->notes,
            'reminder_at'     => $this->reminder_at?->toDateString(),
        ];
    }
}

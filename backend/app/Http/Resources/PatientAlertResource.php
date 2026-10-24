<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\PatientAlert
 */
class PatientAlertResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'patient_id'     => $this->patient_id,
            'text'           => $this->text,
            'severity'       => $this->severity,
            'severity_label' => $this->severityLabel(),
            'resolved_at'    => $this->resolved_at?->toIso8601String(),
            'is_active'      => $this->resolved_at === null,
            'created_at'     => $this->created_at?->toIso8601String(),
        ];
    }
}

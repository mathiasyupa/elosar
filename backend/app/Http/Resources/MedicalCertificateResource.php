<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\MedicalCertificate */
class MedicalCertificateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'patient_id'        => $this->patient_id,
            'medical_record_id' => $this->medical_record_id,
            'type'              => $this->type,
            'type_label'        => $this->typeLabel(),
            'title'             => $this->title,
            'body'              => $this->body,
            'rest_days'         => $this->rest_days,
            'valid_from'        => $this->valid_from?->toDateString(),
            'valid_until'       => $this->valid_until?->toDateString(),
            'issued_at'         => $this->issued_at?->toDateString(),
            'created_at'        => $this->created_at?->toIso8601String(),
            'doctor_name'       => $this->whenLoaded('doctor', fn () => $this->doctor?->name),

            // Parameterised (labour) certificate.
            'city'             => $this->city,
            'hc_number'        => $this->hc_number,
            'address'          => $this->address,
            'phone'            => $this->phone,
            'contingency_type' => $this->contingency_type,
            'company'          => $this->company,
            'activity'         => $this->activity,
            'attended_at'      => $this->attended_at?->toDateString(),
            'symptoms'         => $this->symptoms,
            'diagnosis'        => $this->diagnosis,
            'cie10_codes'      => $this->cie10_codes,
            'show_cie10'       => (bool) ($this->show_cie10 ?? true),
            'treatment'        => $this->treatment,
            'absolute_rest'    => $this->absolute_rest,
        ];
    }
}

<?php

namespace App\Http\Resources;

use App\Services\Obstetrics\GestationalAge;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Pregnancy
 */
class PregnancyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $dating = $this->datingInput();
        $resolved = GestationalAge::resolve($dating);
        // Computed on read, never stored: the age changes every morning.
        $age = GestationalAge::at($dating);

        return [
            'id'           => $this->id,
            'patient_id'   => $this->patient_id,
            'status'       => $this->status,
            'status_label' => $this->statusLabel(),

            'lmp_date'         => $this->lmp_date?->toDateString(),
            'lmp_is_reliable'  => $this->lmp_is_reliable,
            'ultrasound_date'  => $this->ultrasound_date?->toDateString(),
            'ultrasound_weeks' => $this->ultrasound_weeks,
            'ultrasound_days'  => $this->ultrasound_days,
            'dating_source'    => $this->dating_source,

            'edd_date'     => $this->edd_date?->toDateString(),
            'edd_protocol' => $this->edd_protocol,
            // Derived on read from edd_date, never stored: it is always exactly
            // 7 days behind it, so a second column would just be edd_date
            // written twice — and twice as many places to forget a correction.
            'elective_cesarean_date' => GestationalAge::electiveCesareanDate($this->edd_date)?->toDateString(),

            // Both datings and how far apart they are, so the clinician can see
            // what the other one would have said before choosing. The product
            // never re-dates on its own — the MSP guideline publishes no
            // threshold, so inventing one would be inventing clinical practice.
            'dating' => [
                'edd_by_lmp'        => $resolved['edd_by_lmp']?->toDateString(),
                'edd_by_ultrasound' => $resolved['edd_by_ultrasound']?->toDateString(),
                'discrepancy_days'  => $resolved['discrepancy_days'],
                'source_used'       => $resolved['source'],
                'missing'           => $resolved['missing'],
            ],

            'gestational_age' => [
                'days'           => $age['days'],
                'weeks'          => $age['weeks'],
                'remainder_days' => $age['remainder_days'],
                'label'          => $age['label'],
                'trimester'      => $age['trimester'],
                'is_term'        => $age['is_term'],
                'is_post_term'   => $age['is_post_term'],
                'is_valid'       => $age['is_valid'],
            ],

            'fetus_count'   => $this->fetus_count,
            'risk_notes'    => $this->risk_notes,
            'outcome'       => $this->outcome,
            'outcome_label' => $this->outcomeLabel(),
            'outcome_date'  => $this->outcome_date?->toDateString(),
            'outcome_notes' => $this->outcome_notes,

            'screenings' => PregnancyScreeningResource::collection($this->whenLoaded('screenings')),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

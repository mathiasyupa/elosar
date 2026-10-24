<?php

namespace App\Http\Resources;

use App\Services\Obstetrics\PrenatalScreenings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\PregnancyScreening
 */
class PregnancyScreeningResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'pregnancy_id' => $this->pregnancy_id,
            'code'         => $this->code,
            'label'        => $this->label(),
            // Whether the MSP asks for this in every pregnancy or only on a risk
            // factor. Without it, an empty box reads as an omission.
            'universal'    => PrenatalScreenings::isUniversal($this->code),
            'result'       => $this->result,
            'result_at'    => $this->result_at?->toDateString(),
            'notes'        => $this->notes,
        ];
    }
}

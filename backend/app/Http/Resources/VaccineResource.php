<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Vaccine
 */
class VaccineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'code'         => $this->code,
            'name'         => $this->name,
            'category'     => $this->category,
            'max_doses'    => $this->max_doses,
            'max_boosters' => $this->max_boosters,
        ];
    }
}

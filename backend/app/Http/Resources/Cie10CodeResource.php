<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * CIE-10 catalog entry (global, not workspace-scoped). Descriptions are Spanish
 * (OPS/OMS) — clinical content for the Ecuadorian market.
 *
 * @mixin \App\Models\Cie10Code
 */
class Cie10CodeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'code'        => $this->code,
            'description' => $this->description,
            'category'    => $this->category,
        ];
    }
}

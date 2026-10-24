<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fee-schedule entry (catalog) used to build invoice items in the app.
 * Monetary fields are strings (decimal:2 / bcmath).
 *
 * @mixin \App\Models\MedicalService
 */
class MedicalServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'description' => $this->description,
            'category'    => $this->category,
            'base_price'  => (string) $this->base_price,
            'iva_rate'    => (string) $this->iva_rate,
            'is_active'   => (bool) $this->is_active,
        ];
    }
}

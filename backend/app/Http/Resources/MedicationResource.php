<?php

namespace App\Http\Resources;

use App\Models\Medication;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Medication */
class MedicationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                     => $this->id,
            'workspace_id'           => $this->workspace_id,
            'is_global'              => $this->isGlobal(),
            'name'                   => $this->name,
            'concentration'          => $this->concentration,
            'default_dose_quantity'  => $this->default_dose_quantity,
            'default_dose_unit'      => $this->default_dose_unit,
            'default_dose_unit_label'=> $this->doseUnitLabel(),
            'default_frequency'      => $this->default_frequency,
            'default_frequency_label'=> $this->frequencyLabel(),
            'default_duration_days'  => $this->default_duration_days,
            'route'                  => $this->route,
            'route_label'            => $this->routeLabel(),
            'is_controlled'          => $this->is_controlled,
            'default_instructions'   => $this->default_instructions,
            'suggested_dosage'       => $this->suggestedDosage(),
        ];
    }
}

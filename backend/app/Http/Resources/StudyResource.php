<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Study
 */
class StudyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'        => $this->id,
            'type'      => $this->type,
            'category'  => $this->category,
            'name'      => $this->name,
            'code'      => $this->code,
            'is_global' => $this->workspace_id === null,
        ];
    }
}

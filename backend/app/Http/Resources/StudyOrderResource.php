<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\StudyOrder
 */
class StudyOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'medical_record_id'  => $this->medical_record_id,
            'patient_id'         => $this->patient_id,
            'type'               => $this->type,
            'type_label'         => $this->typeLabel(),
            'priority'           => $this->priority,
            'priority_label'     => $this->priorityLabel(),
            'clinical_info'      => $this->clinical_info,
            'notes'              => $this->notes,
            'to_be_performed_at' => $this->to_be_performed_at?->toDateString(),
            'created_at'         => $this->created_at?->toIso8601String(),

            // Whether the study came back. Before this the order was
            // fire-and-forget and nobody could tell a pending one from a done one.
            'result_status'       => $this->result_status,
            'result_status_label' => $this->resultStatusLabel(),
            'result_received_at'  => $this->result_received_at?->toIso8601String(),
            'result_notes'        => $this->result_notes,

            'results' => $this->whenLoaded('results', fn () => $this->results->map(fn ($file) => [
                'id'           => $file->id,
                'title'        => $file->title,
                'file_name'    => $file->file_name,
                'created_at'   => $file->created_at?->toIso8601String(),
                'download_url' => "/attachments/{$file->id}/download",
            ])),

            // Enough to label the row with the visit it came from. An order can
            // have none: issued for the patient, not inside a consultation.
            'medical_record' => $this->whenLoaded('medicalRecord', fn () => $this->medicalRecord ? [
                'id'                      => $this->medicalRecord->id,
                'created_at'              => $this->medicalRecord->created_at?->toIso8601String(),
                'reason_for_consultation' => $this->medicalRecord->reason_for_consultation,
            ] : null),

            'patient' => PatientResource::make($this->whenLoaded('patient')),

            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id'       => $item->id,
                'study_id' => $item->study_id,
                'name'     => $item->name,
                'category' => $item->category,
                'notes'    => $item->notes,
            ])),
        ];
    }
}

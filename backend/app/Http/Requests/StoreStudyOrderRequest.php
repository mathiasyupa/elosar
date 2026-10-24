<?php

namespace App\Http\Requests;

use App\Models\StudyOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation boundary for creating a lab / imaging order.
 *
 * Serves both entry points: the one nested under a consultation, where the
 * record comes from the URL, and the one nested under a patient, where the
 * consultation is optional and travels in the body as `medical_record_id`.
 *
 * workspace_id / patient_id / created_by are injected server-side from the
 * scoped parent (resolved in the controller); the client never sends them. At
 * least one requested study (item) is required.
 *
 * Whether `clinical_info` is mandatory is decided in the controller, because it
 * depends on something this class cannot see: whether a consultation was
 * resolved. Without one, that field is the only thing on the order that says
 * why the study was requested — and that is what a laboratory and an insurer
 * ask for.
 */
class StoreStudyOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Only read on the patient-scoped route; ignored when the record
            // already comes from the URL. Resolved through the scoped model in
            // the controller, so another clinic's id 404s instead of linking.
            'medical_record_id'  => ['nullable', 'integer'],

            'type'               => ['required', Rule::in(array_keys(StudyOrder::TYPES))],
            'priority'           => ['nullable', Rule::in(array_keys(StudyOrder::PRIORITIES))],
            'clinical_info'      => ['nullable', 'string', 'max:2000'],
            'notes'              => ['nullable', 'string', 'max:2000'],
            'to_be_performed_at' => ['nullable', 'date'],

            'items'            => ['required', 'array', 'min:1'],
            'items.*.study_id' => ['nullable', 'integer'],
            'items.*.name'     => ['required', 'string', 'max:255'],
            'items.*.category' => ['nullable', 'string', 'max:80'],
            'items.*.notes'    => ['nullable', 'string', 'max:255'],
        ];
    }
}

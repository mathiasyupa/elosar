<?php

namespace App\Http\Requests;

use App\Models\PatientGynecoHistory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The patient's gyneco-obstetric history. An upsert: the row is created on the
 * first write and corrected afterwards.
 *
 * Every field is typed and bounded. This is deliberately NOT the open JSONB that
 * `personal_background` is — a whole-object write there loses whatever it does not
 * know about, and the obstetric formula is queried, not narrated.
 */
class UpdateGynecoHistoryRequest extends FormRequest
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
        $count = ['sometimes', 'nullable', 'integer', 'min:0', 'max:30'];

        return [
            'menarche_age'            => ['sometimes', 'nullable', 'integer', 'min:6', 'max:25'],
            'cycle_length_days'       => ['sometimes', 'nullable', 'integer', 'min:15', 'max:90'],
            'cycle_duration_days'     => ['sometimes', 'nullable', 'integer', 'min:1', 'max:15'],
            'cycle_is_regular'        => ['sometimes', 'nullable', 'boolean'],
            'dysmenorrhea'            => ['sometimes', 'nullable', 'boolean'],
            'sexual_debut_age'        => ['sometimes', 'nullable', 'integer', 'min:8', 'max:60'],
            'contraceptive_method'    => ['sometimes', 'nullable', Rule::in(array_keys(PatientGynecoHistory::CONTRACEPTIVE_METHODS))],
            'contraceptive_notes'     => ['sometimes', 'nullable', 'string', 'max:255'],
            'last_pap_at'             => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'last_pap_result'         => ['sometimes', 'nullable', 'string', 'max:60'],
            'last_mammography_at'     => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'last_mammography_result' => ['sometimes', 'nullable', 'string', 'max:60'],
            'gestations'              => $count,
            'term_births'             => $count,
            'preterm_births'          => $count,
            'cesareans'               => $count,
            'abortions'               => $count,
            'ectopics'                => $count,
            'stillbirths'             => $count,
            'living_children'         => $count,
            'is_menopausal'           => ['sometimes', 'nullable', 'boolean'],
            'menopause_at'            => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'notes'                   => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'menarche_age'         => 'menarquia',
            'sexual_debut_age'     => 'IVSA',
            'contraceptive_method' => 'método de planificación',
            'last_pap_at'          => 'fecha del Papanicolaou',
            'gestations'           => 'gestas',
            'term_births'          => 'partos a término',
            'preterm_births'       => 'partos pretérmino',
            'cesareans'            => 'cesáreas',
            'abortions'            => 'abortos',
            'living_children'      => 'hijos vivos',
        ];
    }
}

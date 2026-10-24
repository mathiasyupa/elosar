<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\PatientGynecoHistory
 *
 * The obstetric formula is returned twice, on purpose:
 *
 *  - the DECLARED counts, which the patient reported and which include
 *    pregnancies this clinic never saw, and
 *  - the counts DERIVED from the pregnancies actually registered here.
 *
 * They will not match, and that is fine — one is history, the other is what this
 * system witnessed. The server never overwrites the first with the second; the UI
 * shows both, labelled, and the clinician decides.
 */
class GynecoHistoryResource extends JsonResource
{
    /** @var array<string, int>|null */
    public ?array $registered = null;

    /** @param array<string, int> $registered */
    public function withRegistered(array $registered): static
    {
        $this->registered = $registered;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'patient_id' => $this->patient_id,

            'menarche_age'         => $this->menarche_age,
            'cycle_length_days'    => $this->cycle_length_days,
            'cycle_duration_days'  => $this->cycle_duration_days,
            'cycle_is_regular'     => $this->cycle_is_regular,
            'dysmenorrhea'         => $this->dysmenorrhea,

            'sexual_debut_age'         => $this->sexual_debut_age,
            'contraceptive_method'     => $this->contraceptive_method,
            'contraceptive_method_label' => $this->contraceptiveMethodLabel(),
            'contraceptive_notes'      => $this->contraceptive_notes,

            'last_pap_at'             => $this->last_pap_at?->toDateString(),
            'last_pap_result'         => $this->last_pap_result,
            'last_mammography_at'     => $this->last_mammography_at?->toDateString(),
            'last_mammography_result' => $this->last_mammography_result,

            'declared' => [
                'gestations'      => $this->gestations,
                'term_births'     => $this->term_births,
                'preterm_births'  => $this->preterm_births,
                'cesareans'       => $this->cesareans,
                'abortions'       => $this->abortions,
                'ectopics'        => $this->ectopics,
                'stillbirths'     => $this->stillbirths,
                'living_children' => $this->living_children,
            ],

            'registered_in_elosar' => $this->registered,

            'is_menopausal' => $this->is_menopausal,
            'menopause_at'  => $this->menopause_at?->toDateString(),
            'notes'         => $this->notes,

            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

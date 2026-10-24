<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The patient's gyneco-obstetric history — one row per patient.
 *
 * Menarche, cycles, sexual debut, contraception, the last Pap and the obstetric
 * formula belong to the WOMAN, not to whichever consultation happened to record
 * them. Keeping them per-consultation meant the doctor retyped them at every
 * check-up and nobody held her actual file.
 *
 * The obstetric formula here is DECLARED — what the patient reports, including
 * pregnancies this clinic never saw. The server never overwrites it with what
 * `pregnancies` holds; the resource returns the derived counts alongside so the
 * UI can show both, labelled. Overwriting would erase antecedents the system was
 * never told about.
 */
class PatientGynecoHistory extends Model
{
    use HasFactory, BelongsToWorkspace;

    /** @var array<string, string> */
    public const CONTRACEPTIVE_METHODS = [
        'none'         => 'Ninguno',
        'combined_oral' => 'Anticonceptivo oral combinado',
        'progestin_only' => 'Progestágeno solo',
        'injectable'   => 'Inyectable',
        'implant'      => 'Implante subdérmico',
        'iud_copper'   => 'DIU de cobre',
        'iud_hormonal' => 'DIU hormonal',
        'condom'       => 'Preservativo',
        'tubal_ligation' => 'Ligadura de trompas',
        'vasectomy_partner' => 'Vasectomía de la pareja',
        'rhythm'       => 'Método del ritmo',
        'other'        => 'Otro',
    ];

    protected $fillable = [
        'workspace_id',
        'patient_id',
        'menarche_age',
        'cycle_length_days',
        'cycle_duration_days',
        'cycle_is_regular',
        'dysmenorrhea',
        'sexual_debut_age',
        'contraceptive_method',
        'contraceptive_notes',
        'last_pap_at',
        'last_pap_result',
        'last_mammography_at',
        'last_mammography_result',
        'gestations',
        'term_births',
        'preterm_births',
        'cesareans',
        'abortions',
        'ectopics',
        'stillbirths',
        'living_children',
        'is_menopausal',
        'menopause_at',
        'notes',
        'updated_by_user_id',
    ];

    protected $casts = [
        'menarche_age'        => 'integer',
        'cycle_length_days'   => 'integer',
        'cycle_duration_days' => 'integer',
        'cycle_is_regular'    => 'boolean',
        'dysmenorrhea'        => 'boolean',
        'sexual_debut_age'    => 'integer',
        'last_pap_at'         => 'date',
        'last_mammography_at' => 'date',
        'gestations'          => 'integer',
        'term_births'         => 'integer',
        'preterm_births'      => 'integer',
        'cesareans'           => 'integer',
        'abortions'           => 'integer',
        'ectopics'            => 'integer',
        'stillbirths'         => 'integer',
        'living_children'     => 'integer',
        'is_menopausal'       => 'boolean',
        'menopause_at'        => 'date',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function contraceptiveMethodLabel(): ?string
    {
        return $this->contraceptive_method === null
            ? null
            : (self::CONTRACEPTIVE_METHODS[$this->contraceptive_method] ?? $this->contraceptive_method);
    }
}

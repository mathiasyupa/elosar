<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One pregnancy of one patient, with its prenatal visits hanging off it.
 *
 * A patient may have several over the years; only one may be `active` at a time,
 * and that is enforced by a partial unique index rather than by a controller.
 *
 * `edd_date` and `edd_protocol` are derived server-side by `GestationalAge` and
 * are never accepted from a client.
 */
class Pregnancy extends Model
{
    use HasFactory, BelongsToWorkspace, SoftDeletes;

    /** @var array<string, string> */
    public const STATUSES = [
        'active'    => 'En curso',
        'completed' => 'Terminado',
        'lost'      => 'Perdido',
        'unknown'   => 'Sin datos',
    ];

    /** @var array<string, string> */
    public const OUTCOMES = [
        'term_birth'    => 'Parto a término',
        'preterm_birth' => 'Parto pretérmino',
        'cesarean'      => 'Cesárea',
        'abortion'      => 'Aborto',
        'ectopic'       => 'Embarazo ectópico',
        'stillbirth'    => 'Óbito fetal',
        'molar'         => 'Embarazo molar',
    ];

    protected $fillable = [
        'workspace_id',
        'patient_id',
        'status',
        'lmp_date',
        'lmp_is_reliable',
        'ultrasound_date',
        'ultrasound_weeks',
        'ultrasound_days',
        'dating_source',
        // Derived server-side by GestationalAge, never accepted from a client.
        'edd_date',
        'edd_protocol',
        'fetus_count',
        'risk_notes',
        'outcome',
        'outcome_date',
        'outcome_notes',
        'opened_by_user_id',
        'closed_by_user_id',
    ];

    protected $casts = [
        'lmp_date'         => 'date',
        'lmp_is_reliable'  => 'boolean',
        'ultrasound_date'  => 'date',
        'ultrasound_weeks' => 'integer',
        'ultrasound_days'  => 'integer',
        'edd_date'         => 'date',
        'fetus_count'      => 'integer',
        'outcome_date'     => 'date',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function medicalRecords(): HasMany
    {
        return $this->hasMany(MedicalRecord::class);
    }

    public function screenings(): HasMany
    {
        return $this->hasMany(PregnancyScreening::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function outcomeLabel(): ?string
    {
        return $this->outcome === null ? null : (self::OUTCOMES[$this->outcome] ?? $this->outcome);
    }

    /**
     * The shape `GestationalAge` reads. Kept here so callers cannot forget a field
     * and silently get a due date computed from half the inputs.
     *
     * @return array<string, mixed>
     */
    public function datingInput(): array
    {
        return [
            'lmp_date'         => $this->lmp_date?->toDateString(),
            'ultrasound_date'  => $this->ultrasound_date?->toDateString(),
            'ultrasound_weeks' => $this->ultrasound_weeks,
            'ultrasound_days'  => $this->ultrasound_days,
            'dating_source'    => $this->dating_source,
            'status'           => $this->status,
            'outcome_date'     => $this->outcome_date?->toDateString(),
        ];
    }
}

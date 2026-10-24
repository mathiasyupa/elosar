<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A single administered vaccine dose for a patient (one row per dose/booster
 * given). Workspace-scoped clinical data; the UI aggregates these into the
 * dose-by-dose vaccination card.
 */
class PatientImmunization extends Model
{
    use HasFactory, BelongsToWorkspace, SoftDeletes;

    /** @var array<string, string> */
    public const DOSE_LABELS = [
        'dose_1'    => 'Dosis 1',
        'dose_2'    => 'Dosis 2',
        'dose_3'    => 'Dosis 3',
        'booster_1' => 'Refuerzo 1',
        'booster_2' => 'Refuerzo 2',
        'booster_3' => 'Refuerzo 3',
        'single'    => 'Dosis única',
    ];

    protected $fillable = [
        'workspace_id',
        'patient_id',
        'vaccine_id',
        'vaccine_name',
        'dose_label',
        'administered_at',
        'lot',
        'notes',
        'reminder_at',
        'administered_by_user_id',
    ];

    protected $casts = [
        'administered_at' => 'date',
        'reminder_at'     => 'date',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function vaccine(): BelongsTo
    {
        return $this->belongsTo(Vaccine::class, 'vaccine_id');
    }

    public function administeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'administered_by_user_id');
    }

    public function doseLabelText(): string
    {
        return self::DOSE_LABELS[$this->dose_label] ?? $this->dose_label;
    }
}

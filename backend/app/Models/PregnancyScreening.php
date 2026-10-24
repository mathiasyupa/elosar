<?php

namespace App\Models;

use App\Services\Obstetrics\PrenatalScreenings;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One prenatal screening recorded against a pregnancy.
 *
 * A row per screening rather than a fixed column per test: the MSP schedule can
 * gain a test without a migration, and each result carries its own date and the
 * person who recorded it. The catalogue of valid codes lives in
 * `PrenatalScreenings`, transcribed from the MSP guideline.
 *
 * No `workspace_id`: reached through `pregnancies`, which is scoped.
 */
class PregnancyScreening extends Model
{
    use HasFactory;

    protected $fillable = [
        'pregnancy_id',
        'code',
        'result',
        'result_at',
        'notes',
        'recorded_by_user_id',
    ];

    protected $casts = [
        'result_at' => 'date',
    ];

    public function pregnancy(): BelongsTo
    {
        return $this->belongsTo(Pregnancy::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    /** Falls back to the raw code rather than hiding a result whose code changed. */
    public function label(): string
    {
        return PrenatalScreenings::labelFor($this->code) ?? $this->code;
    }
}

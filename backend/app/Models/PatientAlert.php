<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A standing warning about a patient, shown wherever the patient is on screen.
 *
 * Three anonymous free-text slots — what the systems these clinics use today
 * offer — cannot say who wrote a warning, when, or whether it still applies. An
 * alert nobody can close is an alert everybody learns to ignore.
 */
class PatientAlert extends Model
{
    use HasFactory, BelongsToWorkspace;

    /** @var array<string, string> */
    public const SEVERITIES = [
        'info'     => 'Informativa',
        'warning'  => 'Advertencia',
        'critical' => 'Crítica',
    ];

    protected $fillable = [
        'workspace_id',
        'patient_id',
        'text',
        'severity',
        'created_by_user_id',
        'resolved_at',
        'resolved_by_user_id',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function severityLabel(): string
    {
        return self::SEVERITIES[$this->severity] ?? $this->severity;
    }
}

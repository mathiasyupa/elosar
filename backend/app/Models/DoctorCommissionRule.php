<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-service commission override for a doctor within a clinic. Not
 * BelongsToWorkspace — the managing endpoints filter by the active workspace
 * explicitly (like WorkspaceMember), and the settlement service reads it keyed
 * by workspace_id.
 */
class DoctorCommissionRule extends Model
{
    public const TYPE_PERCENTAGE = 'percentage';

    public const TYPE_FIXED = 'fixed';

    protected $fillable = [
        'workspace_id',
        'doctor_id',
        'medical_service_id',
        'rule_type',
        'value',
    ];

    protected $casts = [
        'value' => 'decimal:2',
    ];

    public function medicalService(): BelongsTo
    {
        return $this->belongsTo(MedicalService::class, 'medical_service_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Structured prescription item within a medical record.
 *
 * Replaces the free-text `treatment` field with structured data that
 * allows rendering the prescription PDF in tabular format.
 *
 * Does not need BelongsToWorkspace — access is controlled through MedicalRecord.
 */
class RecordPrescription extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'medical_record_id',
        'medication_name',
        'generic_name',
        'concentration',
        'dosage',
        'route',
        'frequency',
        'duration_days',
        'quantity',
        'instructions',
        'is_controlled',
    ];

    protected $casts = [
        'is_controlled' => 'boolean',
    ];

    public function medicalRecord(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class, 'medical_record_id');
    }
}

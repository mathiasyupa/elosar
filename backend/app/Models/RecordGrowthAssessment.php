<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable-at-creation WHO growth calculation snapshot for a clinical record.
 *
 * The source patient can later be corrected. This row intentionally preserves
 * the inputs and reference data used when the clinician saved the measurement.
 */
class RecordGrowthAssessment extends Model
{
    use BelongsToWorkspace;
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'medical_record_id',
        'record_vital_id',
        'status',
        'reason',
        'measurement_date',
        'age_days',
        'sex',
        'weight_kg',
        'length_height_cm',
        'length_height_adjusted_cm',
        'length_height_measurement_position',
        'head_circumference_cm',
        'standard',
        'standard_version',
        'source_url',
        'table_checksums',
        'indicators',
    ];

    protected $casts = [
        'measurement_date' => 'date',
        'age_days' => 'integer',
        'weight_kg' => 'decimal:2',
        'length_height_cm' => 'decimal:2',
        'length_height_adjusted_cm' => 'decimal:2',
        'head_circumference_cm' => 'decimal:2',
        'table_checksums' => 'array',
        'indicators' => 'array',
    ];

    public function medicalRecord(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class, 'medical_record_id');
    }

    public function vital(): BelongsTo
    {
        return $this->belongsTo(RecordVital::class, 'record_vital_id');
    }
}

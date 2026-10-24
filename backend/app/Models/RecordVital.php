<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Vital signs taken at consultation time.
 *
 * Each medical record can have zero or more vital signs entries
 * (useful when measured several times during the same consultation).
 */
class RecordVital extends Model
{
    use HasFactory;

    protected $fillable = [
        'medical_record_id',
        'weight_kg',
        'height_cm',
        'head_circumference_cm',
        'length_height_measurement_position',
        'bmi',
        'blood_pressure_sys',
        'blood_pressure_dia',
        'heart_rate',
        'temperature',
        'oxygen_saturation',
        'respiratory_rate',
        'oxygen_therapy',
        'consciousness_level',
        'urine_output_ml_h',
        'urine_output_not_quantifiable',
        // Score MAMA: derived server-side by ScoreMama, never accepted from a
        // client. Fillable so the controller can pass the computed values in.
        'score_mama_total',
        'score_mama_level',
        'score_mama_protocol',
        'recorded_at',
    ];

    protected $casts = [
        'weight_kg' => 'decimal:2',
        'height_cm' => 'decimal:2',
        'head_circumference_cm' => 'decimal:2',
        'bmi' => 'decimal:2',
        'temperature' => 'decimal:1',
        'oxygen_saturation' => 'decimal:1',
        'urine_output_ml_h' => 'decimal:1',
        'urine_output_not_quantifiable' => 'boolean',
        'score_mama_total' => 'integer',
        'recorded_at' => 'datetime',
    ];

    public function medicalRecord(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class, 'medical_record_id');
    }

    public function growthAssessment(): HasOne
    {
        return $this->hasOne(RecordGrowthAssessment::class, 'record_vital_id');
    }

    /**
     * Calculates BMI automatically when weight and height are available.
     * Returns null when data is missing.
     */
    public function calculateBmi(): ?float
    {
        if (! $this->weight_kg || ! $this->height_cm || $this->height_cm <= 0) {
            return null;
        }

        $heightM = (float) $this->height_cm / 100;

        return round((float) $this->weight_kg / ($heightM * $heightM), 2);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PatientVitalObservation extends Model
{
    use BelongsToWorkspace, SoftDeletes;

    protected $fillable = [
        'workspace_id', 'patient_id', 'recorded_by_user_id', 'recorded_at',
        'weight_kg', 'height_cm', 'head_circumference_cm',
        'length_height_measurement_position', 'bmi',
        'blood_pressure_sys', 'blood_pressure_dia', 'heart_rate',
        'temperature', 'oxygen_saturation', 'respiratory_rate',
        'oxygen_therapy', 'consciousness_level', 'urine_output_ml_h',
        'urine_output_not_quantifiable', 'score_mama_total',
        'score_mama_level', 'score_mama_protocol',
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

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}

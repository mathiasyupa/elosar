<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicalCertificate extends Model
{
    use BelongsToWorkspace;

    public const TYPE_ATTENDANCE = 'attendance';

    public const TYPE_REST = 'rest';

    public const TYPE_FITNESS = 'fitness';

    /**
     * The parameterised certificate an employer or the IESS expects: fixed
     * fields, not free text. Rendered by its own template.
     */
    public const TYPE_MEDICAL = 'medical';

    /** @var array<string, string> */
    public const TYPE_LABELS = [
        self::TYPE_MEDICAL    => 'Certificado médico',
        self::TYPE_ATTENDANCE => 'Constancia de atención médica',
        self::TYPE_REST       => 'Certificado de reposo',
        self::TYPE_FITNESS    => 'Certificado de aptitud física',
    ];

    /** Contingencia declared on the labour certificate (IESS wording). */
    public const CONTINGENCY_TYPES = [
        'ENFERMEDAD GENERAL',
        'ACCIDENTE DE TRABAJO',
        'ACCIDENTE COMÚN',
        'ENFERMEDAD PROFESIONAL',
        'MATERNIDAD',
    ];

    protected $fillable = [
        'workspace_id',
        'patient_id',
        'created_by',
        'medical_record_id',
        'type',
        'title',
        'body',
        'rest_days',
        'valid_from',
        'valid_until',
        'issued_at',
        // Parameterised certificate
        'city',
        'hc_number',
        'address',
        'phone',
        'contingency_type',
        'company',
        'activity',
        'attended_at',
        'symptoms',
        'diagnosis',
        'cie10_codes',
        'show_cie10',
        'treatment',
        'absolute_rest',
    ];

    protected $casts = [
        'rest_days'     => 'integer',
        'valid_from'    => 'date',
        'valid_until'   => 'date',
        'issued_at'     => 'date',
        'attended_at'   => 'date',
        'absolute_rest' => 'boolean',
    ];

    /** Whether this certificate uses the parameterised (labour) layout. */
    public function isParameterised(): bool
    {
        return $this->type === self::TYPE_MEDICAL;
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'created_by');
    }

    public function medicalRecord(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class);
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->type] ?? $this->title;
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Lab / imaging order ("solicitud de exámenes") issued during a consultation,
 * aligned with the MSP HCU order forms.
 *
 * Workspace-scoped document; its items are isolated through this scoped parent.
 */
class StudyOrder extends Model
{
    use HasFactory, BelongsToWorkspace, SoftDeletes;

    public const TYPE_LABORATORY = 'laboratory';

    public const TYPE_IMAGING = 'imaging';

    /** @var array<string, string> */
    public const TYPES = [
        self::TYPE_LABORATORY => 'Laboratorio',
        self::TYPE_IMAGING    => 'Imagenología',
    ];

    /** @var array<string, string> */
    public const PRIORITIES = [
        'routine' => 'Rutina',
        'urgent'  => 'Urgente',
    ];

    /**
     * Whether the study came back. The order used to be fire-and-forget: issued,
     * printed, and never heard from again.
     *
     * @var array<string, string>
     */
    public const RESULT_STATUSES = [
        'pending'   => 'Pendiente',
        'completed' => 'Resultado recibido',
        'cancelled' => 'No se realizó',
    ];

    protected $fillable = [
        'workspace_id',
        'medical_record_id',
        'patient_id',
        'created_by',
        'type',
        'priority',
        'clinical_info',
        'notes',
        'to_be_performed_at',
        'result_status',
        'result_received_at',
        'result_notes',
    ];

    protected $casts = [
        'to_be_performed_at' => 'date',
        'result_received_at' => 'datetime',
    ];

    /**
     * The column default lives in the database, so a freshly created model had
     * `result_status` null in memory until something re-read it — and the API
     * resource serialises the in-memory instance. Declaring it here keeps the
     * object honest from the moment it is created.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'result_status' => 'pending',
    ];

    public function medicalRecord(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class, 'medical_record_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    /** Issuing doctor (created_by → doctors). */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StudyOrderItem::class, 'study_order_id');
    }

    /** Result documents answering this order (reuses MedicalAttachment). */
    public function results(): HasMany
    {
        return $this->hasMany(MedicalAttachment::class, 'study_order_id');
    }

    public function resultStatusLabel(): string
    {
        return self::RESULT_STATUSES[$this->result_status] ?? $this->result_status;
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function priorityLabel(): string
    {
        return self::PRIORITIES[$this->priority] ?? $this->priority;
    }
}

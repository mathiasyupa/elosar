<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Appointment extends Model
{
    use HasFactory, BelongsToWorkspace;

    /**
     * What the visit is FOR. Closed set, English in the schema as everywhere
     * else; medico-web holds the Spanish labels (i18n/labels.ts).
     *
     * `exams` exists because a patient who comes only for a laboratory test is
     * a real and frequent visit — the busiest line in the clinics we looked at —
     * and until now billing one meant inventing a consultation that never
     * happened.
     *
     * This used to be free text validated as `string|max:50`, so the web form
     * happily stored the Spanish label 'Consulta' while the backend wrote
     * 'consultation'. Nothing matched the label map, and the raw value leaked
     * on screen as "walk_in".
     *
     * @var list<string>
     */
    public const TYPES = ['consultation', 'exams', 'procedure', 'follow_up', 'control', 'walk_in'];

    protected $fillable = [
        'workspace_id',
        'patient_id',
        'doctor_id',
        'appointment_date',
        'status',
        'type',
        'medical_service_id',
        'duration_minutes',
        'notes',
        'channel',
        'confirmed_at',
        'reminder_sent_at',
        'whatsapp_reminded_at',
        'checked_in_at',
        'department_id',
    ];

    protected $casts = [
        'appointment_date'     => 'datetime',
        'confirmed_at'         => 'datetime',
        'reminder_sent_at'     => 'datetime',
        'whatsapp_reminded_at' => 'datetime',
        'checked_in_at'        => 'datetime',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'doctor_id');
    }

    public function medicalService(): BelongsTo
    {
        return $this->belongsTo(MedicalService::class, 'medical_service_id');
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class, 'appointment_id');
    }

    /** Medical records linked to this appointment. */
    public function medicalRecords(): HasMany
    {
        return $this->hasMany(MedicalRecord::class, 'appointment_id');
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class MedicalRecord extends Model
{
    use BelongsToWorkspace, HasFactory, SoftDeletes;

    /**
     * Attention templates whose structured exam sections differ
     * (drives the typed "Exploración" sections; payload kept in structured_exam).
     *
     * @var list<string>
     */
    public const CONSULTATION_TYPES = ['general', 'gynecological', 'pediatric', 'prenatal', 'preventive'];

    /**
     * Professional descriptions declared on the author's profile. These are
     * profile claims, not a verification of an individual credential.
     *
     * @return list<string>
     */
    public static function selectableProfessionalTitles(?Doctor $doctor): array
    {
        if ($doctor === null) {
            return [];
        }

        $titles = $doctor->specialties()->pluck('specialties.name')->all();
        $primary = $doctor->primarySpecialty()?->name;

        if ($primary !== null) {
            $titles[] = $primary;
        }

        return array_values(array_unique($titles));
    }

    /**
     * Mass assignable attributes (Mass Assignment Protection).
     * Allows direct insertion of relations and clinical text fields.
     */
    protected $fillable = [
        'workspace_id',
        'appointment_id',
        'patient_id',
        'pregnancy_id',
        'created_by',
        'status',
        'import_source',
        'closed_with_override',
        'completion_note',
        'triaged_by',
        'triaged_at',
        'reason_for_consultation',
        'consultation_type',
        'professional_title',
        'structured_exam_template_version',
        'current_illness',
        'evolution',
        'physical_examination',
        'structured_exam',
        'review_of_systems',
        'pain_scale',
        'diagnosis',
        'treatment',
        'recommendations',
        'alarm_signs',
        'controlled_prescription_no',
        'follow_up_date',
        'follow_up_notes',
        'opened_own_appointment',
        'voided_at',
        'voided_by',
        'void_reason',
    ];

    protected $casts = [
        'follow_up_date' => 'date',
        'pain_scale' => 'integer',
        'triaged_at' => 'datetime',
        'closed_with_override' => 'boolean',
        'structured_exam_template_version' => 'integer',
        'structured_exam' => 'array',
        'opened_own_appointment' => 'boolean',
        'voided_at' => 'datetime',
    ];

    /** Appointment that originated this record (optional). */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'appointment_id');
    }

    /** Patient this clinical record belongs to. */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    /** The pregnancy this visit is a prenatal control of, when it is one. */
    public function pregnancy(): BelongsTo
    {
        return $this->belongsTo(Pregnancy::class, 'pregnancy_id');
    }

    /** Doctor who authored this record (created_by after doctor_id rename). */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'created_by');
    }

    /** Clinical evidence/documents attached to this record (moved from appointments). */
    public function attachments(): HasMany
    {
        return $this->hasMany(MedicalAttachment::class, 'medical_record_id');
    }

    /** Vital signs recorded during this consultation (multiple measurements allowed). */
    public function vitals(): HasMany
    {
        return $this->hasMany(RecordVital::class, 'medical_record_id')->orderBy('recorded_at', 'asc');
    }

    /** Latest (or only) vital signs entry for this consultation. */
    public function latestVital(): HasOne
    {
        return $this->hasOne(RecordVital::class, 'medical_record_id')->latestOfMany('recorded_at');
    }

    /** Calculated, versioned WHO growth snapshots linked to this consultation. */
    public function growthAssessments(): HasMany
    {
        return $this->hasMany(RecordGrowthAssessment::class, 'medical_record_id')->latest('id');
    }

    /** Structured ICD-10 diagnoses for this consultation. */
    public function diagnoses(): HasMany
    {
        return $this->hasMany(RecordDiagnosis::class, 'medical_record_id');
    }

    /** Structured prescriptions for this consultation. */
    public function prescriptions(): HasMany
    {
        return $this->hasMany(RecordPrescription::class, 'medical_record_id');
    }

    /** Services and procedures performed, for the cash desk to invoice. */
    public function services(): HasMany
    {
        return $this->hasMany(RecordService::class, 'medical_record_id');
    }

    /** Lab / imaging orders ("solicitud de exámenes") issued in this consultation. */
    public function studyOrders(): HasMany
    {
        return $this->hasMany(StudyOrder::class, 'medical_record_id')->latest('id');
    }
}

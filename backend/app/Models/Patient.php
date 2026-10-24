<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;

class Patient extends Model
{
    // Notifiable lets us email the patient ($patient->email via the trait's
    // routeNotificationForMail). Without it, Notification::send($patient, …)
    // throws — which is why the daily reminder job had been silently failing.
    use BelongsToWorkspace, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'workspace_id',
        'name',
        'id_number',
        'document_type',
        'document_country',
        'birth_date',
        'gender',
        'civil_status',
        'city',
        'address',
        'nationality',
        'ethnicity',
        'priority_group',
        'disability_type',
        'disability_pct',
        'email',
        'phone',
        'personal_background',
        'blood_type',
        'emergency_contact_name',
        'emergency_contact_phone',
        'photo_path',
        'insurance_provider',
        'insurance_policy_number',
        // LOPDP consent (audit fields set server-side, never from raw input).
        'data_consent_at',
        'data_consent_version',
        'data_consent_by_user_id',
        'consent_marketing',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'personal_background' => 'array',
        'data_consent_at' => 'datetime',
        'consent_marketing' => 'boolean',
    ];

    public function medicalRecords(): HasMany
    {
        return $this->hasMany(MedicalRecord::class, 'patient_id');
    }

    /**
     * Retention-only relation. It deliberately includes soft-deleted records:
     * archiving a consultation must never make its patient eligible for a
     * physical deletion.
     */
    public function retainedMedicalRecords(): HasMany
    {
        return $this->medicalRecords()->withTrashed();
    }

    public function familyBackground(): HasMany
    {
        return $this->hasMany(FamilyBackground::class, 'patient_id');
    }

    public function allergies(): HasMany
    {
        return $this->hasMany(PatientAllergy::class, 'patient_id');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'patient_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'patient_id');
    }

    /** Retention-only relation; invoices may be soft-deleted. */
    public function retainedInvoices(): HasMany
    {
        return $this->invoices()->withTrashed();
    }

    public function immunizations(): HasMany
    {
        return $this->hasMany(PatientImmunization::class, 'patient_id');
    }

    public function gynecoHistory(): HasOne
    {
        return $this->hasOne(PatientGynecoHistory::class, 'patient_id');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(PatientAlert::class, 'patient_id');
    }

    public function pregnancies(): HasMany
    {
        return $this->hasMany(Pregnancy::class, 'patient_id');
    }

    /** Retention-only relation; pregnancies may be soft-deleted. */
    public function retainedPregnancies(): HasMany
    {
        return $this->pregnancies()->withTrashed();
    }

    /** Retention-only relation; immunization entries may be soft-deleted. */
    public function retainedImmunizations(): HasMany
    {
        return $this->immunizations()->withTrashed();
    }

    public function studyOrders(): HasMany
    {
        return $this->hasMany(StudyOrder::class, 'patient_id');
    }

    /** Retention-only relation; study orders may be soft-deleted. */
    public function retainedStudyOrders(): HasMany
    {
        return $this->studyOrders()->withTrashed();
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(MedicalCertificate::class, 'patient_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MedicalAttachment::class, 'patient_id');
    }

    /**
     * Everything whose existence turns a row into a real patient with a past.
     *
     * Used to decide whether deleting is allowed to mean *delete*. A patient with
     * none of these is a mistake someone typed; one with any of them carries a
     * retention duty that no button in a list should be able to undo.
     *
     * The relation names prefixed with `retained` include soft-deleted child
     * rows on purpose. Removing a child from the day-to-day UI does not remove
     * the professional's duty to keep its patient record.
     *
     * @var list<string>
     */
    public const CLINICAL_TRACE_RELATIONS = [
        'retainedMedicalRecords',
        'appointments',
        'retainedInvoices',
        'retainedImmunizations',
        'retainedStudyOrders',
        'certificates',
        'attachments',
        'allergies',
        'familyBackground',
        // A registered pregnancy is care this clinic gave: without it here, a
        // patient whose only trace is her pregnancy would be hard-deleted from
        // the list instead of archived, and that does not come back.
        'retainedPregnancies',
        'gynecoHistory',
    ];

    /**
     * True when any trace of care or billing exists. Reads the `*_exists` flags
     * loaded by `withExists()` when present, so a list of patients answers this
     * without a query per row.
     */
    public function hasClinicalTrace(): bool
    {
        foreach (self::CLINICAL_TRACE_RELATIONS as $relation) {
            $attribute = \Illuminate\Support\Str::snake($relation).'_exists';

            $exists = array_key_exists($attribute, $this->attributes)
                ? (bool) $this->attributes[$attribute]
                : $this->{$relation}()->exists();

            if ($exists) {
                return true;
            }
        }

        return false;
    }
}

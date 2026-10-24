<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RuntimeException;

class Workspace extends Model
{
    use HasFactory;

    public const SRI_ENVIRONMENT_TEST = 'test';

    public const SRI_ENVIRONMENT_PRODUCTION = 'production';

    /** The only values `sri_environment` may hold, mapped to the SRI `ambiente` digit. */
    public const SRI_ENVIRONMENTS = [
        self::SRI_ENVIRONMENT_TEST => '1',
        self::SRI_ENVIRONMENT_PRODUCTION => '2',
    ];

    protected $fillable = [
        'name',
        'type',
        'invite_code',
        'logo_path',
        'header_image_path',
        'sheet_image_path',
        'prescription_settings',
        'owner_doctor_id',
        'owner_user_id',
        'invoice_counter',
        'credit_note_counter',
        'suspended_at',
        'suspended_reason',
        // SRI / fiscal configuration
        'ruc',
        'razon_social',
        'nombre_comercial',
        'dir_matriz',
        'dir_establecimiento',
        'cod_establecimiento',
        'pto_emision',
        'contribuyente_especial',
        'obligado_contabilidad',
        'acess_establishment_code',
        'senescyt_note',
        'sri_environment',
        'sri_certificate_path',
        'sri_certificate_password',
        'sri_enabled',
    ];

    protected $casts = [
        'prescription_settings' => 'array',
        'obligado_contabilidad' => 'boolean',
        'sri_enabled' => 'boolean',
        'sri_certificate_password' => 'encrypted',
        'suspended_at' => 'datetime',
    ];

    /**
     * Never expose the signing credential or its storage path through array /
     * JSON serialization (defense-in-depth for the future API).
     */
    protected $hidden = [
        'sri_certificate_password',
        'sri_certificate_path',
    ];

    /**
     * Whether this workspace is fully configured to issue SRI vouchers.
     */
    public function canIssueSriVouchers(): bool
    {
        return $this->sri_enabled
            && ! empty($this->ruc)
            && ! empty($this->sri_certificate_path)
            && isset(self::SRI_ENVIRONMENTS[(string) $this->sri_environment]);
    }

    /**
     * The SRI `ambiente` digit ("1" pruebas / "2" producción) for this workspace.
     *
     * An unrecognized environment must never silently degrade to pruebas: that
     * would emit a voucher with no fiscal validity while the workspace believes
     * it is invoicing for real. Callers reach this only past canIssueSriVouchers().
     *
     * @throws RuntimeException on an unrecognized environment.
     */
    public function sriAmbienteCode(): string
    {
        return self::SRI_ENVIRONMENTS[(string) $this->sri_environment]
            ?? throw new RuntimeException(
                "Workspace [{$this->id}] has an unrecognized sri_environment [{$this->sri_environment}]."
            );
    }

    /**
     * Establishment address, falling back to the head-office address.
     */
    public function establishmentAddress(): ?string
    {
        return $this->dir_establecimiento ?: $this->dir_matriz;
    }

    /**
     * Whether a patient-facing document (receta, orden, certificado) would come
     * out on something other than a blank page.
     *
     * Three ways to get there, and a workspace only needs one:
     *  - the system letterhead: a typed header line, a logo, or a banner image;
     *  - a designed sheet printed behind the content (`background`);
     *  - printing on the clinic's own physical pre-designed paper (`margins`),
     *    where we deliberately print no letterhead of our own.
     *
     * Single source of truth: the onboarding checklist and the contextual
     * warnings in the app both read this, so they cannot disagree.
     */
    public function hasLetterhead(): bool
    {
        $settings = is_array($this->prescription_settings) ? $this->prescription_settings : [];

        // Any document on the clinic's own paper counts: printed on the physical
        // sheet, or with the designed sheet behind it (once one is uploaded).
        foreach (\App\Support\LetterheadData::DOCUMENTS as $document) {
            $paper = \App\Support\LetterheadData::paperFor($settings, $document);

            if ($paper === 'preprinted' || ($paper === 'sheet' && filled($this->sheet_image_path))) {
                return true;
            }
        }

        return trim((string) ($settings['header'] ?? '')) !== ''
            || filled($this->logo_path)
            || filled($this->header_image_path);
    }

    /** Whether a platform_admin has locked this workspace (access blocked for every member). */
    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /**
     * The doctor who owns/administers this workspace.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'owner_doctor_id');
    }

    /**
     * The user who owns/administers this workspace (preferred over the legacy
     * doctor-based ownership). Any account type can own a clinic.
     */
    public function ownerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * Membership rows (authoritative access + role table) for this workspace.
     */
    public function members(): HasMany
    {
        return $this->hasMany(WorkspaceMember::class, 'workspace_id');
    }

    public function entitlement(): HasOne
    {
        return $this->hasOne(WorkspaceEntitlement::class);
    }

    /** What this workspace paid Elosar. Never patient money — see Payment. */
    public function subscriptionPayments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
    }

    /**
     * The last day this workspace's subscription is paid for, or null when it
     * never paid. Read from the furthest period end rather than the latest
     * payment: paying two months up front should push the date two months out,
     * not one.
     */
    public function paidUntil(): ?CarbonImmutable
    {
        $furthest = $this->relationLoaded('subscriptionPayments')
            ? $this->subscriptionPayments->max('period_end')
            : $this->subscriptionPayments()->max('period_end');

        return $furthest === null ? null : CarbonImmutable::parse($furthest);
    }

    /**
     * Owes money: it was paying and the paid period lapsed. An account that
     * never paid is not "overdue" — it is on a trial or under review, which is
     * a different conversation and a different column.
     */
    public function isSubscriptionOverdue(): bool
    {
        $paidUntil = $this->paidUntil();

        return $paidUntil !== null && $paidUntil->isBefore(today());
    }

    /**
     * Whether the given user owns this workspace. Prefers owner_user_id, falling
     * back to the legacy owner_doctor_id resolved through the user's doctor.
     */
    public function isOwnedBy(User $user): bool
    {
        if ($this->owner_user_id !== null) {
            return (int) $this->owner_user_id === (int) $user->id;
        }

        $doctorId = $user->doctor?->id;

        return $doctorId !== null && (int) $this->owner_doctor_id === (int) $doctorId;
    }

    /**
     * Patients belonging to this workspace.
     */
    public function patients(): HasMany
    {
        return $this->hasMany(Patient::class, 'workspace_id');
    }

    /**
     * Appointments scheduled within this workspace.
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'workspace_id');
    }

    /**
     * Medical records created in this workspace.
     */
    public function medicalRecords(): HasMany
    {
        return $this->hasMany(MedicalRecord::class, 'workspace_id');
    }

    /**
     * Doctor members of this workspace (clinics only).
     * Accessed through the workspace_members pivot table.
     */
    public function memberDoctors(): BelongsToMany
    {
        return $this->belongsToMany(Doctor::class, 'workspace_members', 'workspace_id', 'doctor_id')
            ->withPivot('role', 'status')
            ->withTimestamps();
    }

    /**
     * Only the doctors currently active in this workspace.
     */
    public function activeDoctors(): BelongsToMany
    {
        return $this->memberDoctors()->wherePivot('status', 'active');
    }

    /**
     * Whether this workspace is a clinic.
     */
    public function isClinic(): bool
    {
        return $this->type === 'clinic';
    }

    /**
     * Whether this workspace is a doctor's personal workspace.
     */
    public function isPersonal(): bool
    {
        return $this->type === 'personal';
    }

    /**
     * Invoices issued in this workspace.
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'workspace_id');
    }

    /**
     * Medical services / fee schedule configured in this workspace.
     */
    public function medicalServices(): HasMany
    {
        return $this->hasMany(MedicalService::class, 'workspace_id');
    }

    /**
     * Payments registered in this workspace.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'workspace_id');
    }

    /**
     * Credit notes issued in this workspace.
     */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class, 'workspace_id');
    }
}

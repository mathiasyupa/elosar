<?php

namespace App\Models;

use App\Services\WorkspaceEntitlementService;
use App\Support\DefaultMedicalServices;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Doctor extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'honorific',
        'uses_physical_stamp_signature',
        'specialty_id',
        'id_number',
        'city',
        'email',
        'phone',
        'signature_path',
        'profile_photo_path',
        'medical_license',
        'acess_code',
        'senescyt_registration',
        'workspace_id',
    ];

    /**
     * When a doctor is created, their personal workspace is generated
     * automatically and linked back via workspace_id. Covers every creation
     * path (Filament, seeders, API) because it listens to the model event.
     */
    protected static function booted(): void
    {
        static::created(function (Doctor $doctor) {
            if ($doctor->workspace_id) {
                return;
            }

            $workspace = Workspace::create([
                'name' => 'Consultorio '.$doctor->name,
                'type' => 'personal',
                'owner_doctor_id' => $doctor->id,
                'owner_user_id' => $doctor->user_id,
            ]);

            // Always entitle the workspace. A missing entitlement resolves to
            // read-only everywhere, so skipping this for an account without a
            // grant — a doctor invited into another clinic, for instance —
            // would hand them a personal workspace they cannot write to.
            app(WorkspaceEntitlementService::class)
                ->provisionPersonalWorkspace($workspace, $doctor->user);

            // saveQuietly avoids triggering an unnecessary second round of (updated) events.
            $doctor->workspace_id = $workspace->id;
            $doctor->saveQuietly();

            // Starter fee schedule so "Servicios" isn't empty on day one.
            DefaultMedicalServices::seed($workspace->id);
        });
    }

    /** Doctor's primary specialty (legacy FK field, used in the prescription PDF). */
    public function specialty(): BelongsTo
    {
        return $this->belongsTo(Specialty::class, 'specialty_id');
    }

    /**
     * All the doctor's specialties via the doctor_specialties pivot table.
     * Allows a doctor to have multiple specialties.
     */
    public function specialties(): BelongsToMany
    {
        return $this->belongsToMany(Specialty::class, 'doctor_specialties')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    /**
     * Returns the doctor's primary specialty.
     *
     * Priority: 1) First specialty with is_primary=true on the pivot.
     *           2) Specialty from the legacy specialty_id field.
     *           3) null when none exists.
     */
    public function primarySpecialty(): ?Specialty
    {
        $primary = $this->specialties->firstWhere('pivot.is_primary', true);
        if ($primary) {
            return $primary;
        }

        return $this->specialty;
    }

    /**
     * True when any of this doctor's approved specialties enables a clinical
     * module. The legacy primary FK remains a fallback for pre-pivot doctors;
     * capability matching itself always happens against server-owned JSONB,
     * never against a specialty name supplied by the client.
     */
    public function hasSpecialtyCapability(string $capability): bool
    {
        if ($this->specialty?->hasCapability($capability)) {
            return true;
        }

        return $this->specialties()
            ->whereJsonContains('capabilities', $capability)
            ->exists();
    }

    /**
     * Clinical modules the authenticated doctor may use. This is deliberately
     * server-derived so consumers can present a module without becoming its
     * authorization authority.
     *
     * @return list<string>
     */
    public function clinicalModules(): array
    {
        return $this->hasSpecialtyCapability(Specialty::CAPABILITY_GYNECO_OBSTETRICS)
            ? [Specialty::CAPABILITY_GYNECO_OBSTETRICS]
            : [];
    }

    /** User account (authentication) linked to this doctor. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** This doctor's personal workspace. */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'workspace_id');
    }

    /** Medical records authored by this doctor (field renamed to created_by). */
    public function medicalRecords(): HasMany
    {
        return $this->hasMany(MedicalRecord::class, 'created_by');
    }

    /** Clinics (clinic-type workspaces) this doctor belongs to. */
    public function clinics(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'clinic_doctor', 'doctor_id', 'workspace_id')
            ->withTimestamps();
    }

    /** Memberships in external clinics. */
    public function workspaceMemberships(): HasMany
    {
        return $this->hasMany(WorkspaceMember::class, 'doctor_id');
    }
}

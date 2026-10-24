<?php

namespace App\Services;

use App\Exceptions\ClinicalSeatLimitException;
use App\Models\AccountTrialGrant;
use App\Models\Doctor;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Clinic management business logic.
 *
 * Centralizes the operations consumed by Filament actions (create clinic,
 * join by invite_code, regenerate code). Keeping this out of the UI layer
 * makes it reusable and testable.
 */
class ClinicService
{
    public function __construct(
        private readonly WorkspaceEntitlementService $entitlements,
    ) {}

    /**
     * Creates a clinic-type workspace.
     *
     * The creating doctor becomes the owner (owner_doctor_id) and an active
     * member with the 'doctor' role. A unique invite_code is generated.
     */
    public function createClinic(Doctor $owner, string $name): Workspace
    {
        return DB::transaction(function () use ($owner, $name) {
            $grant = $this->claimableClinicGrant($owner->user_id);

            $clinic = Workspace::create([
                'name' => $name,
                'type' => 'clinic',
                'invite_code' => $this->generateUniqueInviteCode(),
                'owner_doctor_id' => $owner->id,
                'owner_user_id' => $owner->user_id,
            ]);

            $this->entitlements->attachFirstClinic($clinic, $grant);
            $this->attachDoctor($clinic, $owner, role: 'doctor');

            return $clinic;
        });
    }

    /**
     * Creates a clinic-type workspace owned by a NON-DOCTOR user (e.g. a clinic
     * administrator / manager who does not see patients). The owner becomes an
     * active member with the 'admin' role; the owner overlay in
     * WorkspacePermissionService layers settings/team management on top.
     */
    public function createClinicForUser(User $owner, string $name): Workspace
    {
        return DB::transaction(function () use ($owner, $name) {
            $grant = $this->claimableClinicGrant($owner->id);

            $clinic = Workspace::create([
                'name' => $name,
                'type' => 'clinic',
                'invite_code' => $this->generateUniqueInviteCode(),
                'owner_doctor_id' => null,
                'owner_user_id' => $owner->id,
            ]);

            $this->entitlements->attachFirstClinic($clinic, $grant);
            $this->attachMember($clinic, $owner, role: 'admin');

            return $clinic;
        });
    }

    /**
     * A doctor joins a clinic using its invite_code.
     *
     * @throws ValidationException when the code does not exist or does not belong to a clinic.
     */
    public function joinByInviteCode(Doctor $doctor, string $inviteCode): Workspace
    {
        $clinic = Workspace::where('invite_code', trim($inviteCode))
            ->where('type', 'clinic')
            ->first();

        if (! $clinic) {
            throw ValidationException::withMessages([
                'invite_code' => 'El código de invitación no es válido o no corresponde a ninguna clínica.',
            ]);
        }

        $this->attachDoctor($clinic, $doctor, role: 'doctor');

        return $clinic;
    }

    /**
     * Regenerates a clinic's invite_code, invalidating the previous one.
     */
    public function regenerateInviteCode(Workspace $clinic): string
    {
        if (! $clinic->isClinic()) {
            throw ValidationException::withMessages([
                'invite_code' => 'Solo los workspaces de tipo clínica tienen código de invitación.',
            ]);
        }

        $code = $this->generateUniqueInviteCode();
        $clinic->update(['invite_code' => $code]);

        return $code;
    }

    /**
     * Links a doctor to a clinic. Thin shim over the membership writer for
     * callers (Filament, seeders) that hold a Doctor instance — the doctor may or
     * may not have a login account.
     */
    public function attachDoctor(Workspace $clinic, Doctor $doctor, string $role = 'doctor'): void
    {
        $this->writeMembership($clinic, $doctor->user, $doctor, $role);
    }

    /**
     * Links a USER to a clinic idempotently. The authoritative path — works for
     * doctors and staff alike.
     */
    public function attachMember(Workspace $clinic, User $user, string $role = 'doctor', ?Doctor $doctor = null): void
    {
        $this->writeMembership($clinic, $user, $doctor ?? $user->doctor, $role);
    }

    /**
     * Idempotently writes a membership row and keeps the clinic_doctor pivot in
     * sync.
     *  - workspace_members → keyed by user_id when a login exists, else by
     *    doctor_id (legacy doctors with no account); reactivates if inactive.
     *  - clinic_doctor (Eloquent N:M convenience pivot) → synced ONLY for doctors,
     *    so $doctor->clinics() and $clinic->memberDoctors() keep working.
     */
    private function writeMembership(Workspace $clinic, ?User $user, ?Doctor $doctor, string $role): void
    {
        $key = $user !== null
            ? ['user_id' => $user->id, 'workspace_id' => $clinic->id]
            : ['doctor_id' => $doctor->id, 'workspace_id' => $clinic->id];

        $this->assertClinicalSeatAvailable($clinic, $doctor, $key);

        WorkspaceMember::updateOrCreate($key, [
            'user_id' => $user?->id,
            'doctor_id' => $doctor?->id,
            'staff_profile_id' => $user?->staffProfile?->id,
            // `roles` is the source of truth (multi-role); `role` kept in sync as
            // the legacy principal. attachMember/attachDoctor set a single base
            // role; extra roles are added later via the team screen.
            'role' => $role,
            'roles' => [$role],
            'status' => 'active',
        ]);

        if ($doctor) {
            $doctor->clinics()->syncWithoutDetaching([$clinic->id]);
        }
    }

    /**
     * A clinic pays per attending doctor: the plan includes two, and each one
     * beyond that is billed. Refuse the seat before the membership row exists,
     * so a rejected invitation leaves nothing half-written.
     *
     * Only clinical seats count. Reception, nursing, billing and administration
     * are free, so a membership without a Doctor never reaches the check. A
     * doctor who is already an active member is not a new seat either — this
     * writer is idempotent and gets called again on role edits.
     *
     * @param  array<string, int>  $key
     *
     * @throws ClinicalSeatLimitException
     */
    private function assertClinicalSeatAvailable(Workspace $clinic, ?Doctor $doctor, array $key): void
    {
        if ($doctor === null || ! $clinic->isClinic()) {
            return;
        }

        $entitlement = $clinic->entitlement;

        if ($entitlement === null) {
            return;
        }

        $alreadyActive = WorkspaceMember::query()
            ->where($key)
            ->where('status', 'active')
            ->exists();

        if ($alreadyActive) {
            return;
        }

        if ($entitlement->isSeatLimitReached()) {
            throw new ClinicalSeatLimitException($entitlement->includedClinicalSeats());
        }
    }

    /**
     * Lock and validate the one clinic trial that an account can claim before
     * creating any workspace or membership records.
     *
     * @throws DomainException
     */
    private function claimableClinicGrant(?int $userId): AccountTrialGrant
    {
        $grant = AccountTrialGrant::query()
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->first();

        if (! $grant
            || ! $grant->allowsFirstClinicEntitlement()
            || $grant->first_clinic_claimed_at !== null
            || ! $grant->ends_at->greaterThan(now('America/Guayaquil'))) {
            throw new DomainException('The account cannot claim another clinic trial.');
        }

        return $grant;
    }

    /**
     * Generates a unique uppercase alphanumeric invite code.
     */
    protected function generateUniqueInviteCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (Workspace::where('invite_code', $code)->exists());

        return $code;
    }
}

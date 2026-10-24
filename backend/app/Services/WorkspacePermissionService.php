<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Collection;

/**
 * Single source of truth for "what can this user do in this workspace".
 *
 * Resolves four independent axes into a flat permission set:
 *   - Workspace   → WHERE data lives (personal vs clinic)
 *   - Member role → WHAT you can do operationally (doctor/nurse/receptionist/...)
 *   - is_admin    → operational admin overlay (billing, reports, team view)
 *   - Ownership   → WHAT you can configure (team, letterhead, SRI, billing admin)
 *
 * Both the API gate (EnsurePermission middleware) and the auth bootstrap
 * (/auth/me, /workspaces) consume this — the matrix is never duplicated.
 */
class WorkspacePermissionService
{
    /** Spanish labels for the clinic roles (UI reference + invitation emails). */
    public const ROLE_LABELS = [
        'doctor' => 'Médico',
        'nurse' => 'Enfermería',
        'receptionist' => 'Recepción',
        'billing' => 'Caja',
        'admin' => 'Administración',
    ];

    /**
     * Human-readable capability groups for the "what does each role do"
     * reference table. Each maps to a representative permission so the table is
     * DERIVED from the real matrix below and can never drift from what the
     * server actually enforces.
     *
     * @var list<array{key: string, label: string, permission: string}>
     */
    private const CAPABILITIES = [
        ['key' => 'agenda', 'label' => 'Agenda y citas', 'permission' => 'appointments.manage'],
        ['key' => 'patients', 'label' => 'Pacientes', 'permission' => 'patients.manage'],
        ['key' => 'records', 'label' => 'Historia clínica', 'permission' => 'records.view'],
        ['key' => 'vitals', 'label' => 'Signos vitales', 'permission' => 'vitals.create'],
        ['key' => 'billing', 'label' => 'Cobros y facturas', 'permission' => 'billing.manage'],
        ['key' => 'reports', 'label' => 'Reportes financieros', 'permission' => 'reports.view'],
        ['key' => 'finance', 'label' => 'Gastos y ganancia neta', 'permission' => 'finance.view'],
        ['key' => 'clinical_reports', 'label' => 'Reportes clínicos', 'permission' => 'reports.clinical'],
    ];

    /**
     * Base permissions granted by each membership role. Owner permissions are
     * layered on top separately (see OWNER_PERMISSIONS) so any role can own.
     *
     * @var array<string, list<string>>
     */
    private const ROLE_PERMISSIONS = [
        'doctor' => [
            'dashboard.view',
            'appointments.view', 'appointments.manage', 'appointments.checkin',
            'patients.view', 'patients.manage',
            'records.view', 'records.manage', 'vitals.create',
            'assistant.use',
            'team.view',
            'billing.view',
            // reports.clinical (diagnoses/RDACAA — patient-level data) is tied to
            // the clinical role and lives ONLY here, so a non-doctor admin/owner
            // never receives individual diagnoses (LOPDP). Financial reports
            // (reports.view) stay available to admins/owners.
            'reports.view', 'reports.clinical',
            'settings.view',
        ],
        'nurse' => [
            'dashboard.view',
            'appointments.view', 'appointments.checkin',
            'patients.view', 'patients.manage',
            'vitals.create',
            'team.view',
            'settings.view',
        ],
        // Front desk of a small clinic: schedules, registers AND collects
        // payments (in Ecuador the receptionist typically also runs the cash
        // desk). Still NO clinical records access (LOPDP). Clinics that want a
        // dedicated cashier use the 'billing' role below instead.
        'receptionist' => [
            'dashboard.view',
            'appointments.view', 'appointments.manage', 'appointments.checkin',
            'patients.view', 'patients.manage',
            'billing.view', 'billing.manage',
            'team.view',
            'settings.view',
            'settings.letterhead',
        ],
        // Dedicated cashier: only money, no agenda/patients (larger clinics that
        // segregate the front desk from the cash desk).
        'billing' => [
            'dashboard.view',
            'billing.view', 'billing.manage',
            'reports.view',
            'settings.view',
        ],
        'admin' => [
            'dashboard.view',
            'appointments.view', 'appointments.manage', 'appointments.checkin',
            'patients.view', 'patients.manage',
            'team.view',
            'billing.view', 'billing.manage',
            // Financial/aggregate reports only — a non-clinical admin never sees
            // patient-level diagnoses (no reports.clinical).
            'reports.view',
            // The clinic's books: operating expenses, other income, doctor
            // deductions and the resulting net profit. Deliberately NOT granted
            // to billing/receptionist — the cashier collects money but the
            // margin of the business is not theirs to read.
            'finance.view', 'finance.manage',
            'settings.view',
            'settings.letterhead',
        ],
    ];

    /**
     * Permissions layered on the workspace owner regardless of their base role:
     * configuration (membrete/SRI/team) plus running the front desk — a
     * nurse-owner or billing-owner must be able to schedule appointments and
     * manage patient demographics in their own clinic. Clinical records access
     * (records.*, vitals.create) and clinical reports (reports.clinical) are
     * deliberately NOT included: they stay tied to the clinical (doctor) role,
     * owner or not, so a non-doctor owner never sees patient-level diagnoses
     * (LOPDP). Only financial data (reports.view, finance.*) is granted here.
     *
     * @var list<string>
     */
    private const OWNER_PERMISSIONS = [
        'dashboard.view',
        'appointments.view', 'appointments.manage', 'appointments.checkin',
        'patients.view', 'patients.manage',
        // Emptying a directory is intentionally owner-only. Reception and
        // nursing may register or correct individual patients, never erase an
        // entire clinic's operational registry.
        'patients.purge',
        'settings.view', 'settings.manage', 'settings.letterhead',
        'team.view', 'team.manage',
        'billing.view', 'billing.manage',
        'reports.view',
        // Whoever owns the clinic reads its books, whatever their base role.
        'finance.view', 'finance.manage',
    ];

    /**
     * Memoized by (user, workspace) for the lifetime of THIS instance. A
     * controller method that receives one `WorkspacePermissionService` via
     * method injection and calls `for()`/`can()` on it two or three times
     * (DashboardController::clinicStats() does exactly this) used to re-run
     * the member-role query every single time.
     *
     * Deliberately NOT bound as a per-request singleton in AppServiceProvider:
     * that would also share this cache across the EnsurePermission middleware
     * and the controller it guards, and a request that changes the acting
     * user's own role mid-flight (there is none today, but a test simulating
     * one caught it immediately) would keep serving the pre-change answer for
     * the rest of that request. One instance per resolution keeps that
     * impossible; the memoization here only ever pays off within a single
     * call site holding onto its own instance.
     *
     * @var array<string, array{membership_role: ?string, roles: list<string>, is_owner: bool, permissions: list<string>}>
     */
    private array $resolvedCache = [];

    /**
     * Resolve the member's roles, ownership and the effective permission set for
     * a user within a workspace. Permissions are the UNION of every role's set
     * (a member may hold several) plus the owner overlay when applicable.
     *
     * @return array{membership_role: ?string, roles: list<string>, is_owner: bool, permissions: list<string>}
     */
    public function for(User $user, Workspace $workspace): array
    {
        $cacheKey = $user->getKey().':'.$workspace->getKey();
        if (isset($this->resolvedCache[$cacheKey])) {
            return $this->resolvedCache[$cacheKey];
        }

        return $this->resolvedCache[$cacheKey] = $this->resolveFor($user, $workspace);
    }

    private function resolveFor(User $user, Workspace $workspace): array
    {
        $isOwner = $workspace->isOwnedBy($user);

        // Personal workspaces are solo-doctor: full clinical access + owner.
        if ($workspace->isPersonal()) {
            return [
                'membership_role' => 'doctor',
                'roles' => ['doctor'],
                'is_owner' => $isOwner,
                'permissions' => $this->merge(self::ROLE_PERMISSIONS['doctor'], self::OWNER_PERMISSIONS),
            ];
        }

        $roles = $this->rolesFor($user, $workspace);

        // Union of every held role's permissions.
        $permissions = [];
        foreach ($roles as $role) {
            $permissions = $this->merge($permissions, self::ROLE_PERMISSIONS[$role] ?? []);
        }

        // Owner overlay (configuration + front desk) on top of the base roles.
        if ($isOwner) {
            $permissions = $this->merge($permissions, self::OWNER_PERMISSIONS);
        }

        return [
            'membership_role' => $this->principalRole($roles),
            'roles' => $roles,
            'is_owner' => $isOwner,
            'permissions' => array_values(array_unique($permissions)),
        ];
    }

    /**
     * Convenience check used by the EnsurePermission middleware.
     */
    public function can(User $user, Workspace $workspace, string $permission): bool
    {
        return in_array($permission, $this->for($user, $workspace)['permissions'], true);
    }

    /**
     * Roles that run the clinic as a whole. Holding any of them (or owning the
     * clinic) means seeing the WHOLE clinic's agenda and revenue; a member with
     * none of them is "clinical-only" and is scoped to their own data.
     *
     * @var list<string>
     */
    private const MANAGEMENT_ROLES = ['admin', 'receptionist', 'billing'];

    /**
     * The Doctor id a member's own data must be scoped to, or null when they may
     * see the whole clinic. A CLINICAL-ONLY member (holds 'doctor', is not the
     * owner and holds no management role) only sees their own agenda and their
     * own revenue — a colleague's numbers and the clinic's margin are not theirs
     * to read. Personal workspaces return null (the solo doctor owns everything).
     */
    public function selfScopedDoctorId(User $user, Workspace $workspace): ?int
    {
        if (! $workspace->isClinic()) {
            return null;
        }

        $resolved = $this->for($user, $workspace);
        $roles = $resolved['roles'];

        $isClinicalOnly = in_array('doctor', $roles, true)
            && ! $resolved['is_owner']
            && empty(array_intersect($roles, self::MANAGEMENT_ROLES));

        return $isClinicalOnly ? $user->doctor?->id : null;
    }

    /**
     * Reference catalog for the UI ("¿Qué hace cada rol?" table): every role with
     * the capabilities it grants, DERIVED from ROLE_PERMISSIONS so the help can
     * never contradict what the server enforces. Ownership is listed apart: it
     * layers settings/team management on top of any role.
     *
     * @return array{capabilities: list<array{key: string, label: string}>, roles: list<array{role: string, label: string, grants: array<string, bool>}>}
     */
    public function catalog(): array
    {
        return [
            'capabilities' => array_map(
                fn (array $c): array => ['key' => $c['key'], 'label' => $c['label']],
                self::CAPABILITIES,
            ),
            'roles' => array_values(array_map(function (string $role): array {
                $permissions = self::ROLE_PERMISSIONS[$role] ?? [];

                $grants = [];
                foreach (self::CAPABILITIES as $capability) {
                    $grants[$capability['key']] = in_array($capability['permission'], $permissions, true);
                }

                return [
                    'role' => $role,
                    'label' => self::ROLE_LABELS[$role] ?? $role,
                    'grants' => $grants,
                ];
            }, array_keys(self::ROLE_PERMISSIONS))),
        ];
    }

    /**
     * Decorate each workspace with membership_role / roles / is_owner /
     * permissions as transient attributes, so WorkspaceResource can serialize
     * them. Used by /auth/me, /auth/login and /workspaces.
     *
     * @param  Collection<int, Workspace>  $workspaces
     * @return Collection<int, Workspace>
     */
    public function decorate(User $user, Collection $workspaces): Collection
    {
        return $workspaces->each(function (Workspace $workspace) use ($user): void {
            $resolved = $this->for($user, $workspace);
            $workspace->membership_role = $resolved['membership_role'];
            $workspace->roles = $resolved['roles'];
            $workspace->is_owner = $resolved['is_owner'];
            $workspace->permissions = $resolved['permissions'];
        });
    }

    /**
     * Active roles held by the user in a clinic workspace. Reads from the
     * authoritative workspace_members table (roles array, source of truth).
     *
     * @return list<string>
     */
    private function rolesFor(User $user, Workspace $workspace): array
    {
        $membership = $workspace->members()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if ($membership) {
            return $membership->roleList();
        }

        // Fallback: a doctor linked only through the clinic_doctor convenience
        // pivot (no explicit workspace_members row) is a 'doctor' member.
        $doctor = $user->doctor;
        if ($doctor && $doctor->clinics()->whereKey($workspace->id)->exists()) {
            return ['doctor'];
        }

        return [];
    }

    /**
     * The representative role for badges/labels: 'doctor' when the member is
     * clinical, otherwise the first held role.
     *
     * @param  list<string>  $roles
     */
    private function principalRole(array $roles): ?string
    {
        if (in_array('doctor', $roles, true)) {
            return 'doctor';
        }

        return $roles[0] ?? null;
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return list<string>
     */
    private function merge(array $a, array $b): array
    {
        return array_values(array_unique([...$a, ...$b]));
    }
}

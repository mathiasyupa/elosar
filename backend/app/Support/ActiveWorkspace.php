<?php

namespace App\Support;

use App\Models\Doctor;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Active workspace in the doctor's session.
 *
 * Resolves and persists, within the HTTP session, which workspace the
 * authenticated doctor is currently working in. WorkspaceScope depends on it
 * to isolate data: patients, appointments and records are filtered by the id
 * this class exposes.
 *
 * Rules:
 *  - No authenticated user or user without a Doctor → no active workspace (null).
 *    In that case WorkspaceScope does NOT filter (console, seeders, jobs see everything).
 *  - If the session has no workspace set, the doctor's personal workspace is used.
 *  - Only workspaces the doctor actually has access to can be activated.
 */
class ActiveWorkspace
{
    public const SESSION_KEY = 'active_workspace_id';

    /**
     * Stateless workspace binding (no session): queued jobs, API requests,
     * superadmin acting-as. Takes precedence over the session resolution.
     */
    protected ?int $boundId = null;

    /**
     * When true, WorkspaceScope performs NO filtering. Reserved for explicit,
     * auditable cross-tenant access (seeders, CLI maintenance, superadmin
     * reporting) — never a tenant-facing request.
     */
    protected bool $unguarded = false;

    /**
     * Doctor for the authenticated user (or null).
     */
    public function doctor(): ?Doctor
    {
        $user = Auth::user();

        return $user?->doctor;
    }

    /**
     * Active workspace id, or null when not applicable (no authenticated doctor
     * and no explicit binding).
     */
    public function id(): ?int
    {
        // Explicit stateless binding wins (jobs / API / acting-as).
        if ($this->boundId !== null) {
            return $this->boundId;
        }

        $doctor = $this->doctor();

        if (! $doctor) {
            return null;
        }

        $sessionId = session(self::SESSION_KEY);

        // Make sure the session id is still accessible to this user.
        if ($sessionId && $this->accessibleIds()->contains((int) $sessionId)) {
            return (int) $sessionId;
        }

        // Default: the doctor's personal workspace — but only if it's actually
        // accessible (not suspended). Trusting workspace_id directly here would
        // silently bypass the suspension a platform_admin just applied, since a
        // personal workspace's id equals workspace_id itself.
        $default = $doctor->workspace_id;

        if ($default && $this->accessibleIds()->contains((int) $default)) {
            session([self::SESSION_KEY => $default]);

            return $default;
        }

        // An account locked to a clinic must start there even when its Doctor
        // still owns a preserved personal workspace.
        if (Auth::user()?->workspace_access_lock_id !== null) {
            $locked = $this->accessibleIds()->first();
            if ($locked !== null) {
                session([self::SESSION_KEY => $locked]);

                return $locked;
            }
        }

        return null;
    }

    /**
     * Active workspace instance.
     */
    public function current(): ?Workspace
    {
        $id = $this->id();

        return $id ? Workspace::find($id) : null;
    }

    /**
     * Sets the active workspace in the session.
     * Aborts when the doctor has no access to that workspace.
     */
    public function set(int $workspaceId): void
    {
        if (! $this->accessibleIds()->contains($workspaceId)) {
            abort(403, 'No tienes acceso a este workspace.');
        }

        session([self::SESSION_KEY => $workspaceId]);
    }

    /**
     * Clears the active workspace (e.g. on logout).
     */
    public function clear(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    /**
     * Binds a workspace id without a session (stateless contexts: queued jobs,
     * API requests, superadmin acting-as). This is a trusted, low-level setter:
     * accessibility must be validated by the caller (e.g. API middleware against
     * accessibleIds()) before binding.
     */
    public function bind(int $workspaceId): void
    {
        $this->boundId = $workspaceId;
    }

    /**
     * Removes a stateless binding set via bind().
     */
    public function unbind(): void
    {
        $this->boundId = null;
    }

    /**
     * Whether the workspace scope is currently disabled (see runUnguarded()).
     */
    public function isUnguarded(): bool
    {
        return $this->unguarded;
    }

    /**
     * Runs $callback with a stateless workspace binding, restoring the previous
     * binding afterwards. Nesting-safe. Used by queued jobs that act on a single
     * known tenant.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function runBound(int $workspaceId, callable $callback): mixed
    {
        $previous = $this->boundId;
        $this->boundId = $workspaceId;

        try {
            return $callback();
        } finally {
            $this->boundId = $previous;
        }
    }

    /**
     * Runs $callback with the workspace scope DISABLED (sees every tenant),
     * restoring the previous state afterwards. Nesting-safe.
     *
     * For seeders, CLI maintenance and superadmin reporting only — NEVER inside
     * a tenant-facing request, as it bypasses multi-tenant isolation entirely.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function runUnguarded(callable $callback): mixed
    {
        $previous = $this->unguarded;
        $this->unguarded = true;

        try {
            return $callback();
        } finally {
            $this->unguarded = $previous;
        }
    }

    /**
     * Every workspace the authenticated user can access:
     *  - their personal workspace (doctors only), plus
     *  - the clinics where they are an active member (workspace_members — the
     *    authoritative table, keyed by user, so this works for staff too).
     *
     * @return Collection<int, Workspace>
     */
    public function available(): Collection
    {
        $user = Auth::user();

        if (! $user) {
            return collect();
        }

        $ids = collect();

        $doctor = $user->doctor;

        // Personal workspace — doctor accounts only (staff have none).
        if ($doctor && $doctor->workspace_id) {
            $ids->push((int) $doctor->workspace_id);
        }

        // Clinics from active memberships (workspace_members — authoritative,
        // user-centric, the only source for staff).
        $ids = $ids->merge(
            $user->memberships()->where('status', 'active')->pluck('workspace_id')
        );

        // Clinics from the clinic_doctor convenience pivot (doctors only). Kept
        // in sync with memberships by ClinicService; unioned here so either
        // setup grants access.
        if ($doctor) {
            $ids = $ids->merge($doctor->clinics()->pluck('workspaces.id'));
        }

        $ids = $ids->map(fn ($id): int => (int) $id)->unique()->values();

        // A lock only narrows existing access. It never grants access to a
        // workspace without an active membership or other valid relationship.
        if ($user->workspace_access_lock_id !== null) {
            $lockedId = (int) $user->workspace_access_lock_id;
            $hasActiveMembership = $user->memberships()
                ->where('workspace_id', $lockedId)
                ->where('status', 'active')
                ->exists();

            $ids = $hasActiveMembership
                ? $ids->filter(fn (int $id): bool => $id === $lockedId)->values()
                : collect();
        }

        if ($ids->isEmpty()) {
            return collect();
        }

        // A platform_admin suspension locks the whole workspace out for every
        // member, without touching each controller individually.
        return Workspace::whereIn('id', $ids)->whereNull('suspended_at')->get()->values();
    }

    /**
     * Accessible workspace ids for the authenticated user.
     *
     * @return Collection<int, int>
     */
    protected function accessibleIds(): Collection
    {
        return $this->available()
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }
}

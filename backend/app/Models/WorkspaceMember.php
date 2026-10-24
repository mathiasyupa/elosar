<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceMember extends Model
{
    /** The assignable clinic roles. A member may hold several; permissions sum. */
    public const ROLES = ['doctor', 'nurse', 'receptionist', 'billing', 'admin'];

    protected $fillable = [
        'user_id',
        'doctor_id',
        'staff_profile_id',
        'workspace_id',
        'role',
        'roles',
        'is_admin',
        'can_triage',
        'commission_pct',
        'status',
    ];

    protected $casts = [
        'roles' => 'array',
        'is_admin' => 'boolean',
        'can_triage' => 'boolean',
        'commission_pct' => 'decimal:2',
    ];

    /**
     * Roles held by this membership (source of truth). Falls back to the legacy
     * singular `role` for rows written before the multi-role migration.
     *
     * @return list<string>
     */
    public function roleList(): array
    {
        $roles = $this->roles ?: [];

        if (empty($roles) && $this->role) {
            $roles = [$this->role];
        }

        return array_values(array_unique($roles));
    }

    /** Whether this membership holds the given role. */
    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roleList(), true);
    }

    /**
     * The user holding this membership (authoritative — covers doctors and staff).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The doctor holding this membership (null for staff members).
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'doctor_id');
    }

    /**
     * The staff profile holding this membership (null for doctor members).
     */
    public function staffProfile(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'staff_profile_id');
    }

    /**
     * The clinic workspace this membership belongs to.
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'workspace_id');
    }

    /**
     * Whether the membership is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Deactivates the membership without deleting it.
     * The doctor keeps read access to records they previously authored.
     */
    public function deactivate(): void
    {
        $this->update(['status' => 'inactive']);
    }
}

<?php

use App\Models\Appointment;
use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\User;
use App\Models\Workspace;
use App\Policies\AppointmentPolicy;
use App\Policies\MedicalRecordPolicy;
use App\Policies\PatientPolicy;
use App\Policies\WorkspacePolicy;
use Filament\Facades\Filament;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

// ─────────────────────────────────────────────────────────────────────────
// Test helpers
// ─────────────────────────────────────────────────────────────────────────

/**
 * Creates a user with the given role and resets the Spatie cache.
 * Note: these role names are on-the-fly test fixtures (created via firstOrCreate
 * with permissions assigned manually), independent of the real ShieldSeeder roles
 * (which are English: 'doctor', 'receptionist', 'admin', 'super_admin').
 * TODO: align these fixtures to the real English role names for consistency.
 */
function userWithRole(string $roleName): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/**
 * Creates all standard permissions for a model.
 * Returns the list of created names.
 *
 * @return string[]
 */
function createPermissionsFor(string $model): array
{
    $actions = ['ViewAny', 'View', 'Create', 'Update', 'Delete', 'DeleteAny'];
    $names   = [];
    foreach ($actions as $action) {
        Permission::firstOrCreate(['name' => "{$action}:{$model}", 'guard_name' => 'web']);
        $names[] = "{$action}:{$model}";
    }

    return $names;
}

/**
 * Minimal permission/role seed needed for the Shield tests.
 * Called in the beforeEach hooks that require it.
 */
function seedShieldForTest(): void
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $models = ['Appointment', 'Doctor', 'MedicalRecord', 'Patient', 'Role', 'Workspace'];
    foreach ($models as $model) {
        createPermissionsFor($model);
    }
}

// ─────────────────────────────────────────────────────────────────────────
// GRUPO 1 — canAccessPanel()
// ─────────────────────────────────────────────────────────────────────────

describe('canAccessPanel', function () {
    it('user without a role CANNOT access the panel', function () {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user  = User::factory()->create();
        $panel = Filament::getPanel('admin');

        expect($user->canAccessPanel($panel))->toBeFalse();
    });

    it('user with panel_user role can access the panel', function () {
        $user  = userWithRole('panel_user');
        $panel = Filament::getPanel('admin');

        expect($user->canAccessPanel($panel))->toBeTrue();
    });

    it('user with medico role can access the panel', function () {
        $user  = userWithRole('medico');
        $panel = Filament::getPanel('admin');

        expect($user->canAccessPanel($panel))->toBeTrue();
    });

    it('user with super_admin role can access the panel', function () {
        $user  = userWithRole('super_admin');
        $panel = Filament::getPanel('admin');

        expect($user->canAccessPanel($panel))->toBeTrue();
    });
});

// ─────────────────────────────────────────────────────────────────────────
// GROUP 2 — HTTP panel access
// ─────────────────────────────────────────────────────────────────────────

describe('HTTP panel access', function () {
    it('unauthenticated user is redirected to login', function () {
        $response = $this->get('/admin');

        $response->assertRedirectContains('/admin/login');
    });

    it('authenticated user WITHOUT a role is redirected away from the panel', function () {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/admin');

        // Filament throws NotAuthorizedException → 403 Forbidden
        $response->assertForbidden();
    });

    it('authenticated user with a role can enter the panel', function () {
        $user = userWithRole('panel_user');

        $response = $this->actingAs($user)->get('/admin');

        // Could be 200 or a redirect to /admin/dashboard — either way not 403/401
        expect($response->status())->not->toBe(403)
            ->and($response->status())->not->toBe(401);
    });
});

// ─────────────────────────────────────────────────────────────────────────
// GROUP 3 — medico role permissions
// ─────────────────────────────────────────────────────────────────────────

describe('medico role — correct permissions', function () {
    beforeEach(function () {
        seedShieldForTest();
    });

    it('medico can view patients', function () {
        $user = userWithRole('medico');
        $user->givePermissionTo('ViewAny:Patient', 'View:Patient');

        expect($user->can('ViewAny:Patient'))->toBeTrue()
            ->and($user->can('View:Patient'))->toBeTrue();
    });

    it('medico can create and update patients', function () {
        $user = userWithRole('medico');
        $user->givePermissionTo('Create:Patient', 'Update:Patient');

        expect($user->can('Create:Patient'))->toBeTrue()
            ->and($user->can('Update:Patient'))->toBeTrue();
    });

    it('medico CANNOT delete patients', function () {
        $user = userWithRole('medico');
        $user->givePermissionTo('ViewAny:Patient', 'View:Patient', 'Create:Patient', 'Update:Patient');

        expect($user->can('Delete:Patient'))->toBeFalse()
            ->and($user->can('DeleteAny:Patient'))->toBeFalse();
    });

    it('medico can view and create medical records', function () {
        $user = userWithRole('medico');
        $user->givePermissionTo('ViewAny:MedicalRecord', 'Create:MedicalRecord');

        expect($user->can('ViewAny:MedicalRecord'))->toBeTrue()
            ->and($user->can('Create:MedicalRecord'))->toBeTrue();
    });

    it('medico CANNOT manage workspaces', function () {
        $user = userWithRole('medico');

        expect($user->can('ViewAny:Workspace'))->toBeFalse()
            ->and($user->can('Create:Workspace'))->toBeFalse();
    });

    it('medico CANNOT manage roles', function () {
        $user = userWithRole('medico');

        expect($user->can('ViewAny:Role'))->toBeFalse()
            ->and($user->can('Create:Role'))->toBeFalse();
    });
});

// ─────────────────────────────────────────────────────────────────────────
// GROUP 4 — recepcion role permissions
// ─────────────────────────────────────────────────────────────────────────

describe('recepcion role — correct permissions', function () {
    beforeEach(function () {
        seedShieldForTest();
    });

    it('recepcion can fully manage appointments', function () {
        $user = userWithRole('recepcion');
        $user->givePermissionTo(
            'ViewAny:Appointment', 'View:Appointment',
            'Create:Appointment', 'Update:Appointment',
            'Delete:Appointment', 'DeleteAny:Appointment'
        );

        expect($user->can('ViewAny:Appointment'))->toBeTrue()
            ->and($user->can('Create:Appointment'))->toBeTrue()
            ->and($user->can('Delete:Appointment'))->toBeTrue();
    });

    it('recepcion can view and create patients but NOT edit or delete them', function () {
        $user = userWithRole('recepcion');
        $user->givePermissionTo('ViewAny:Patient', 'View:Patient', 'Create:Patient');

        expect($user->can('ViewAny:Patient'))->toBeTrue()
            ->and($user->can('Create:Patient'))->toBeTrue()
            ->and($user->can('Update:Patient'))->toBeFalse()
            ->and($user->can('Delete:Patient'))->toBeFalse();
    });

    it('recepcion CANNOT access medical records', function () {
        $user = userWithRole('recepcion');

        expect($user->can('ViewAny:MedicalRecord'))->toBeFalse()
            ->and($user->can('Create:MedicalRecord'))->toBeFalse();
    });

    it('recepcion CANNOT manage roles', function () {
        $user = userWithRole('recepcion');

        expect($user->can('ViewAny:Role'))->toBeFalse();
    });
});

// ─────────────────────────────────────────────────────────────────────────
// GROUP 5 — admin role — full access
// ─────────────────────────────────────────────────────────────────────────

describe('admin role — full access', function () {
    beforeEach(function () {
        seedShieldForTest();
    });

    it('admin can manage roles', function () {
        $user = userWithRole('admin');
        $user->givePermissionTo('ViewAny:Role', 'View:Role', 'Create:Role', 'Update:Role', 'Delete:Role');

        expect($user->can('ViewAny:Role'))->toBeTrue()
            ->and($user->can('Create:Role'))->toBeTrue();
    });

    it('admin can access all clinical resources', function () {
        $allPerms = Permission::all()->pluck('name')->toArray();
        $user     = userWithRole('admin');
        $user->givePermissionTo($allPerms);

        expect($user->can('ViewAny:Patient'))->toBeTrue()
            ->and($user->can('Create:MedicalRecord'))->toBeTrue()
            ->and($user->can('Delete:Appointment'))->toBeTrue()
            ->and($user->can('ViewAny:Workspace'))->toBeTrue();
    });
});

// ─────────────────────────────────────────────────────────────────────────
// GROUP 6 — super_admin role — full gate bypass
// ─────────────────────────────────────────────────────────────────────────

describe('super_admin role — gate bypass', function () {
    beforeEach(function () {
        seedShieldForTest();
    });

    it('super_admin can perform any action on any resource', function () {
        $user = userWithRole('super_admin');

        // With define_via_gate: true, Gate::before intercepts and returns true
        expect($user->can('ViewAny:Patient'))->toBeTrue()
            ->and($user->can('Create:MedicalRecord'))->toBeTrue()
            ->and($user->can('Delete:Workspace'))->toBeTrue()
            ->and($user->can('Create:Role'))->toBeTrue()
            ->and($user->can('ViewAny:Doctor'))->toBeTrue();
    });

    it('super_admin can access the panel', function () {
        $user  = userWithRole('super_admin');
        $panel = Filament::getPanel('admin');

        expect($user->canAccessPanel($panel))->toBeTrue();
    });
});

// ─────────────────────────────────────────────────────────────────────────
// GROUP 7 — Auto-generated policies
// ─────────────────────────────────────────────────────────────────────────

describe('Shield-generated policies', function () {
    beforeEach(function () {
        seedShieldForTest();
    });

    it('PatientPolicy denies viewAny without permission', function () {
        $user    = userWithRole('usuario');
        $policy  = new PatientPolicy();

        expect($policy->viewAny($user))->toBeFalse();
    });

    it('PatientPolicy allows viewAny with the correct permission', function () {
        $user = userWithRole('usuario');
        $user->givePermissionTo('ViewAny:Patient');

        $policy = new PatientPolicy();

        expect($policy->viewAny($user))->toBeTrue();
    });

    it('AppointmentPolicy denies create without permission', function () {
        $user   = userWithRole('usuario');
        $policy = new AppointmentPolicy();

        expect($policy->create($user))->toBeFalse();
    });

    it('MedicalRecordPolicy denies delete without permission', function () {
        $user    = userWithRole('usuario');
        $record  = MedicalRecord::factory()->make();
        $policy  = new MedicalRecordPolicy();

        expect($policy->delete($user, $record))->toBeFalse();
    });

    it('WorkspacePolicy denies create to the usuario role', function () {
        $user   = userWithRole('usuario');
        $policy = new WorkspacePolicy();

        expect($policy->create($user))->toBeFalse();
    });
});

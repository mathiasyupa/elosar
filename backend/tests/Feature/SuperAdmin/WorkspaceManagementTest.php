<?php

use App\Filament\SuperAdmin\Resources\WorkspaceResource\Pages\ListWorkspaces;
use App\Models\User;
use App\Support\ActiveWorkspace;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('superadmin'));
});

/**
 * WorkspaceResource shares App\Models\Workspace (and its Policy) with the
 * clinical /admin panel's own resource — so a bare `platform_admin` role
 * isn't enough here (unlike UserResource, which has no policy). Mirrors what
 * the real ShieldSeeder does: platform_admin gets every Shield permission.
 */
function platformAdminForWorkspace(): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach (['ViewAny', 'View', 'Create', 'Update', 'Delete', 'DeleteAny'] as $action) {
        Permission::firstOrCreate(['name' => "{$action}:Workspace", 'guard_name' => 'web']);
    }

    $role = Role::firstOrCreate(['name' => 'platform_admin', 'guard_name' => 'web']);
    $role->syncPermissions(Permission::where('name', 'like', '%:Workspace')->get());

    $user = User::factory()->create();
    $user->assignRole('platform_admin');

    return $user;
}

describe('workspace suspension blocks access for every member', function () {
    it('suspending a doctor\'s own PERSONAL workspace also locks ActiveWorkspace::id()', function () {
        // Regression test: id()'s fallback-to-personal-workspace branch used to
        // re-derive $doctor->workspace_id directly without checking suspension,
        // so a suspended personal workspace (the common case — every doctor
        // has one) stayed fully usable via the session-based /admin panel even
        // though available() correctly excluded it.
        [$doctor, $doctorUser, $personal] = makeDoctorWithWorkspace();

        test()->actingAs($doctorUser);
        expect(app(ActiveWorkspace::class)->id())->toBe($personal->id);

        $personal->update(['suspended_at' => now()]);

        expect(app(ActiveWorkspace::class)->id())->toBeNull();
    });

    it('excludes a suspended clinic from ActiveWorkspace::available() for its members', function () {
        [, $memberUser] = makeDoctorWithWorkspace();
        $svc = app(App\Services\ClinicService::class);
        [$owner] = makeDoctorWithWorkspace();
        $ownerClinic = $svc->createClinic($owner, 'Clinic to suspend');
        $svc->attachMember($ownerClinic, $memberUser, 'doctor');

        test()->actingAs($memberUser);
        expect(app(ActiveWorkspace::class)->available()->pluck('id'))->toContain($ownerClinic->id);

        $ownerClinic->update(['suspended_at' => now()]);

        expect(app(ActiveWorkspace::class)->available()->pluck('id'))->not->toContain($ownerClinic->id);
    });

    it('restores access once reactivated', function () {
        $svc = app(App\Services\ClinicService::class);
        [$owner, $ownerUser] = makeDoctorWithWorkspace();
        $clinic = $svc->createClinic($owner, 'Clinic to reactivate');
        $clinic->update(['suspended_at' => now()]);

        test()->actingAs($ownerUser);
        expect(app(ActiveWorkspace::class)->available()->pluck('id'))->not->toContain($clinic->id);

        $clinic->update(['suspended_at' => null]);

        expect(app(ActiveWorkspace::class)->available()->pluck('id'))->toContain($clinic->id);
    });

    it('the API middleware rejects a suspended workspace id', function () {
        $svc = app(App\Services\ClinicService::class);
        [$owner, $ownerUser] = makeDoctorWithWorkspace();
        $clinic = $svc->createClinic($owner, 'Clinic API test');
        $clinic->update(['suspended_at' => now()]);

        $token = $ownerUser->createToken('test')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization'   => "Bearer {$token}",
            'X-Workspace-Id'  => (string) $clinic->id,
        ])->getJson('/api/v1/dashboard/stats');

        $response->assertForbidden();
    });
});

describe('suspend / reactivate table action', function () {
    it('suspends a workspace via the Filament action', function () {
        $admin = platformAdminForWorkspace();
        [$owner] = makeDoctorWithWorkspace();
        $clinic = app(App\Services\ClinicService::class)->createClinic($owner, 'Filament-suspended clinic');

        Livewire::actingAs($admin)
            ->test(ListWorkspaces::class)
            ->callTableAction('suspend', $clinic, data: ['suspended_reason' => 'Impago'])
            ->assertHasNoTableActionErrors();

        $clinic->refresh();
        expect($clinic->suspended_at)->not->toBeNull()
            ->and($clinic->suspended_reason)->toBe('Impago');
    });

    it('reactivates a workspace via the Filament action', function () {
        $admin = platformAdminForWorkspace();
        [$owner] = makeDoctorWithWorkspace();
        $clinic = app(App\Services\ClinicService::class)->createClinic($owner, 'Filament-reactivated clinic');
        $clinic->update(['suspended_at' => now()]);

        Livewire::actingAs($admin)
            ->test(ListWorkspaces::class)
            ->callTableAction('reactivate', $clinic)
            ->assertHasNoTableActionErrors();

        expect($clinic->fresh()->suspended_at)->toBeNull();
    });
});

<?php

use App\Models\User;
use Filament\Facades\Filament;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function genericWorkspaceCreator(): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach (['ViewAny', 'View', 'Create', 'Update', 'Delete', 'DeleteAny'] as $action) {
        Permission::firstOrCreate(['name' => "{$action}:Workspace", 'guard_name' => 'web']);
    }

    $role = Role::firstOrCreate(['name' => 'workspace_creator', 'guard_name' => 'web']);
    $role->syncPermissions(Permission::where('name', 'like', '%:Workspace')->get());

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('does not expose the direct generic admin workspace creation route', function () {
    $this->actingAs(genericWorkspaceCreator())
        ->get('/admin/workspaces/create')
        ->assertNotFound();
});

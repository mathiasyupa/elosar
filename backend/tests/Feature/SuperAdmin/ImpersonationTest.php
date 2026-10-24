<?php

use App\Filament\SuperAdmin\Resources\UserResource\Pages\ListUsers;
use App\Models\ImpersonationGrant;
use App\Models\ImpersonationLog;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('superadmin'));
});

function platformAdminForImpersonation(): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Role::firstOrCreate(['name' => 'platform_admin', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole('platform_admin');

    return $user;
}

it('issues a one-time exchange grant and audits the impersonation', function () {
    $admin  = platformAdminForImpersonation();
    [$doctor, $target] = makeDoctorWithWorkspace();

    Livewire::actingAs($admin)
        ->test(ListUsers::class)
        ->callTableAction('impersonate', $target)
        ->assertHasNoTableActionErrors();

    expect(ImpersonationLog::where('actor_user_id', $admin->id)->where('target_user_id', $target->id)->exists())
        ->toBeTrue();

    $grant = ImpersonationGrant::where('target_user_id', $target->id)->latest('id')->first();
    expect($grant)->not->toBeNull()
        ->and($grant->used_at)->toBeNull()
        ->and($grant->expires_at->isBefore(now()->addMinutes(6)))->toBeTrue();
});

// Deliberately its own test (no prior Livewire::actingAs in this test): mixing
// Livewire's actingAs (which binds a user directly on the guard) with a plain
// HTTP request in the same test lets Sanctum's stateful-domain guard resolve
// the *session* identity instead of the token — a false negative, not a real
// security gap. Kept isolated so this proves the exchange flow alone works.
it('redeems a grant exactly once and the resulting token authenticates as the target', function () {
    $admin  = platformAdminForImpersonation();
    [$doctor, $target] = makeDoctorWithWorkspace();

    $grant = ImpersonationGrant::create([
        'code'           => bin2hex(random_bytes(32)),
        'target_user_id' => $target->id,
        'actor_user_id'  => $admin->id,
        'expires_at'     => now()->addMinutes(5),
    ]);

    $exchange = $this->postJson('/api/v1/auth/impersonate', ['code' => $grant->code]);

    $exchange->assertOk()->assertJsonPath('user.id', $target->id);
    $token = $exchange->json('token');

    expect($grant->fresh()->used_at)->not->toBeNull();

    // The code is single-use — a second redemption must fail.
    $this->postJson('/api/v1/auth/impersonate', ['code' => $grant->code])->assertNotFound();

    // And the freshly minted token genuinely authenticates as the target.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('user.id', $target->id);
});

it('rejects an unknown or expired exchange code', function () {
    $this->postJson('/api/v1/auth/impersonate', ['code' => 'does-not-exist'])->assertNotFound();

    [$doctor, $target] = makeDoctorWithWorkspace();
    $expired = ImpersonationGrant::create([
        'code'           => bin2hex(random_bytes(32)),
        'target_user_id' => $target->id,
        'actor_user_id'  => $target->id,
        'expires_at'     => now()->subMinute(),
    ]);

    $this->postJson('/api/v1/auth/impersonate', ['code' => $expired->code])->assertNotFound();
});

it('blocks redeeming a grant for a suspended target', function () {
    [$doctor, $target] = makeDoctorWithWorkspace();
    $target->update(['suspended_at' => now()]);

    $grant = ImpersonationGrant::create([
        'code'           => bin2hex(random_bytes(32)),
        'target_user_id' => $target->id,
        'actor_user_id'  => $target->id,
        'expires_at'     => now()->addMinutes(5),
    ]);

    $this->postJson('/api/v1/auth/impersonate', ['code' => $grant->code])->assertForbidden();
});

it('never offers impersonation for another platform_admin', function () {
    $admin      = platformAdminForImpersonation();
    $otherAdmin = platformAdminForImpersonation();

    Livewire::actingAs($admin)
        ->test(ListUsers::class)
        ->assertTableActionHidden('impersonate', $otherAdmin);
});

it('hides impersonation for a user with no usable app access', function () {
    $admin        = platformAdminForImpersonation();
    $bareUser     = User::factory()->create(); // no Doctor, no membership

    Livewire::actingAs($admin)
        ->test(ListUsers::class)
        ->assertTableActionHidden('impersonate', $bareUser);
});

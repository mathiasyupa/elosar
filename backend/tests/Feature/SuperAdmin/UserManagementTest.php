<?php

use App\Filament\SuperAdmin\Resources\UserResource;
use App\Filament\SuperAdmin\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use App\Services\ClinicService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('superadmin'));
});

function platformAdmin(): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Role::firstOrCreate(['name' => 'platform_admin', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole('platform_admin');

    return $user;
}

describe('superadmin panel gating', function () {
    it('a plain user cannot access /superadmin', function () {
        $user = User::factory()->create();

        expect($user->canAccessPanel(Filament::getPanel('superadmin')))->toBeFalse();

        $this->actingAs($user)->get('/superadmin')->assertForbidden();
    });

    it('a platform_admin can access /superadmin', function () {
        $admin = platformAdmin();

        expect($admin->canAccessPanel(Filament::getPanel('superadmin')))->toBeTrue();
    });

    it('a suspended platform_admin loses panel access', function () {
        $admin = platformAdmin();
        $admin->update(['suspended_at' => now()]);

        expect($admin->canAccessPanel(Filament::getPanel('superadmin')))->toBeFalse();
    });
});

describe('user suspend / reactivate action', function () {
    it('suspends a user via the table action', function () {
        $admin = platformAdmin();
        $target = User::factory()->create();

        Livewire::actingAs($admin)
            ->test(ListUsers::class)
            ->callTableAction('suspend', $target, data: ['suspended_reason' => 'Fraude reportado'])
            ->assertHasNoTableActionErrors();

        $target->refresh();
        expect($target->suspended_at)->not->toBeNull()
            ->and($target->suspended_reason)->toBe('Fraude reportado');
    });

    it('reactivates a suspended user', function () {
        $admin = platformAdmin();
        $target = User::factory()->create(['suspended_at' => now(), 'suspended_reason' => 'x']);

        Livewire::actingAs($admin)
            ->test(ListUsers::class)
            ->callTableAction('reactivate', $target)
            ->assertHasNoTableActionErrors();

        $target->refresh();
        expect($target->suspended_at)->toBeNull()
            ->and($target->suspended_reason)->toBeNull();
    });

    it('hides the suspend action for the acting admin itself', function () {
        $admin = platformAdmin();

        Livewire::actingAs($admin)
            ->test(ListUsers::class)
            ->assertTableActionHidden('suspend', $admin);
    });

    it('hides the impersonate action against another platform_admin', function () {
        $admin = platformAdmin();
        $otherAdmin = platformAdmin();

        Livewire::actingAs($admin)
            ->test(ListUsers::class)
            ->assertTableActionHidden('impersonate', $otherAdmin);
    });
});

describe('reset password action', function () {
    it('issues a temporary password and revokes existing tokens', function () {
        $admin = platformAdmin();
        $target = User::factory()->create();
        $oldHash = $target->password;
        $target->createToken('existing-device');

        Livewire::actingAs($admin)
            ->test(ListUsers::class)
            ->callTableAction('resetPassword', $target)
            ->assertHasNoTableActionErrors();

        expect($target->fresh()->password)->not->toBe($oldHash)
            ->and($target->fresh()->must_change_password)->toBeTrue()
            ->and($target->fresh()->tokens()->count())->toBe(0);
    });
});

describe('suspended accounts are locked out of the API', function () {
    it('rejects login for a suspended user', function () {
        $user = User::factory()->create([
            'password' => bcrypt('correct-password'),
            'suspended_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        $response->assertForbidden();
    });
});

describe('platform role toggles', function () {
    it('grants platform_admin via the edit form and syncPlatformRoles', function () {
        $admin = platformAdmin();
        $target = User::factory()->create();

        UserResource::syncPlatformRoles($target, ['platform_admin' => true]);

        expect($target->fresh()->hasRole('platform_admin'))->toBeTrue();
    });

    it('revokes a role without touching other roles the user holds', function () {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $target = User::factory()->create();
        Role::firstOrCreate(['name' => 'platform_admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $target->assignRole(['platform_admin', 'super_admin']);

        // The real form always submits both toggle states; simulate that here
        // (super_admin stays "on" — that's the one we must NOT touch).
        UserResource::syncPlatformRoles($target, [
            'platform_admin' => false,
            'super_admin' => true,
        ]);

        $target->refresh();
        expect($target->hasRole('platform_admin'))->toBeFalse()
            ->and($target->hasRole('super_admin'))->toBeTrue();
    });
});

/*
|--------------------------------------------------------------------------
| Dar de alta una cuenta desde el panel
|--------------------------------------------------------------------------
| La página de creación existía desde que se escribió el recurso y la lista no
| declaraba el botón que lleva a ella: la función estaba construida y no tenía
| puerta. Se descubrió cuando un administrador de plataforma fue a crear una
| cuenta y no encontró por dónde — ninguna prueba lo cubría porque todas
| entraban por la URL.
*/
describe('creating an account', function () {
    it('offers a way to create one from the user list', function () {
        $this->actingAs(platformAdmin());

        Livewire::test(ListUsers::class)->assertActionExists('create');
    });

    // No se prueba la URL de creación con un GET: la MFA obligatoria del panel
    // redirige a la inscripción del segundo factor antes que a cualquier página,
    // así que un 302 ahí no diría nada sobre el botón. Lo que faltaba era la
    // acción en la lista, y eso es lo que fija el test de arriba.
});

describe('allow clinic creation action', function () {
    it('hands a spent account its clinic back, and the app lets it create one', function () {
        $admin = platformAdmin();
        [$doctor, $user, $workspace] = makeDoctorWithWorkspace();
        $user->accountTrialGrant()->update([
            'ends_at' => now()->subDays(3),
            'first_clinic_claimed_at' => now()->subDays(10),
        ]);

        // Before: the column says why, and the app refuses.
        expect(UserResource::clinicCreationState($user->fresh('accountTrialGrant')))
            ->toBe('Ya creó su clínica');
        expect(fn () => app(ClinicService::class)->createClinic($doctor->fresh(), 'Antes'))
            ->toThrow(DomainException::class);

        Livewire::actingAs($admin)
            ->test(ListUsers::class)
            ->callTableAction('allow_clinic', $user, data: [
                'ends_at' => now()->addDays(20)->toDateString(),
                'operator_note' => 'Dra. del centro médico',
            ])
            ->assertHasNoTableActionErrors();

        $grant = $user->fresh()->accountTrialGrant;
        expect($grant->first_clinic_claimed_at)->toBeNull()
            ->and($grant->ends_at->isFuture())->toBeTrue();
        expect(UserResource::clinicCreationState($user->fresh('accountTrialGrant')))
            ->toStartWith('Sí, hasta');

        expect(fn () => app(ClinicService::class)->createClinic($doctor->fresh(), 'Centro Médico'))
            ->not->toThrow(DomainException::class);

        $this->assertDatabaseHas('workspace_entitlement_events', [
            'workspace_id' => $workspace->id,
            'action' => 'operator_issued_account_grant',
            'actor_user_id' => $admin->id,
        ]);
    });

    it('never shortens a longer deadline the account already had', function () {
        $admin = platformAdmin();
        [, $user] = makeDoctorWithWorkspace();
        $far = now()->addDays(90);
        $user->accountTrialGrant()->update(['ends_at' => $far, 'first_clinic_claimed_at' => now()]);

        Livewire::actingAs($admin)
            ->test(ListUsers::class)
            ->callTableAction('allow_clinic', $user, data: ['ends_at' => now()->addDays(5)->toDateString()])
            ->assertHasNoTableActionErrors();

        expect($user->fresh()->accountTrialGrant->ends_at->diffInDays($far, true))->toBeLessThan(1);
    });

    it('issues a grant to an account that never had one', function () {
        $admin = platformAdmin();
        $user = User::factory()->create(['account_type' => 'doctor']);
        expect($user->accountTrialGrant)->toBeNull();

        Livewire::actingAs($admin)
            ->test(ListUsers::class)
            ->callTableAction('allow_clinic', $user, data: ['ends_at' => now()->addDays(10)->toDateString()])
            ->assertHasNoTableActionErrors();

        $grant = $user->fresh()->accountTrialGrant;
        expect($grant)->not->toBeNull()
            ->and($grant->allowsFirstClinicEntitlement())->toBeTrue()
            ->and($grant->first_clinic_claimed_at)->toBeNull();
    });

    it('is not offered for platform admins', function () {
        $admin = platformAdmin();
        $other = platformAdmin();

        Livewire::actingAs($admin)
            ->test(ListUsers::class)
            ->assertTableActionHidden('allow_clinic', $other);
    });
});

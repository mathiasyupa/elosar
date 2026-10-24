<?php

use App\Models\Specialty;
use App\Models\User;
use App\Models\Workspace;
use App\Services\SuperAdminAccountService;
use App\Filament\SuperAdmin\Resources\UserResource\Pages\CreateUser;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('superadmin'));
});

it('creates a doctor with a personal workspace and a temporary password', function () {
    Event::fake([Registered::class]);
    $specialty = Specialty::factory()->create();
    $data = [
        'name' => fake()->name(),
        'email' => fake()->unique()->safeEmail(),
        'id_number' => fake()->numerify('##########'),
        'city' => fake()->city(),
        'phone' => fake()->phoneNumber(),
        'specialty_id' => $specialty->id,
    ];

    $result = app(SuperAdminAccountService::class)->createDoctor($data);
    $user = $result['user']->fresh();
    $doctor = $user->doctor;
    $personal = $doctor->workspace;

    expect($user->account_type)->toBe('doctor')
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->must_change_password)->toBeTrue()
        ->and(Hash::check($result['temporary_password'], $user->password))->toBeTrue()
        ->and($doctor->id_number)->toBe($data['id_number'])
        ->and($personal->type)->toBe('personal')
        ->and($personal->owner_user_id)->toBe($user->id)
        ->and($personal->entitlement)->not->toBeNull();

    Event::assertDispatched(Registered::class, fn (Registered $event) => $event->user->is($user));
});

it('creates a doctor-owned clinic while preserving the personal workspace', function () {
    $specialty = Specialty::factory()->create();
    $clinicName = fake()->company();

    $user = app(SuperAdminAccountService::class)->createDoctor([
        'name' => fake()->name(),
        'email' => fake()->unique()->safeEmail(),
        'id_number' => fake()->numerify('##########'),
        'city' => fake()->city(),
        'phone' => fake()->phoneNumber(),
        'specialty_id' => $specialty->id,
        'clinic_name' => $clinicName,
    ])['user'];

    $personal = $user->fresh()->doctor->workspace;
    $clinic = Workspace::query()->where('owner_user_id', $user->id)->where('type', 'clinic')->sole();

    expect($personal->type)->toBe('personal')
        ->and($clinic->id)->not->toBe($personal->id)
        ->and($clinic->name)->toBe($clinicName)
        ->and($clinic->members()->where('user_id', $user->id)->where('status', 'active')->exists())->toBeTrue();
});

it('joins a new doctor to an existing clinic while keeping the personal trial', function () {
    $clinic = Workspace::factory()->clinic()->create();
    $specialty = Specialty::factory()->create();

    $user = app(SuperAdminAccountService::class)->createDoctor([
        'name' => fake()->name(),
        'email' => fake()->unique()->safeEmail(),
        'id_number' => fake()->numerify('##########'),
        'city' => fake()->city(),
        'phone' => fake()->phoneNumber(),
        'specialty_id' => $specialty->id,
        'clinic_id' => $clinic->id,
    ])['user']->fresh();

    expect($user->doctor->workspace->type)->toBe('personal')
        ->and($user->doctor->workspace->entitlement)->not->toBeNull()
        ->and($user->memberships()->where('workspace_id', $clinic->id)->where('role', 'doctor')->where('status', 'active')->exists())->toBeTrue()
        ->and($user->ownedWorkspaces()->where('type', 'clinic')->exists())->toBeFalse();
});

it('creates a non-clinical clinic owner with an active admin membership', function () {
    Event::fake([Registered::class]);
    $result = app(SuperAdminAccountService::class)->createClinicOwner([
        'name' => fake()->name(),
        'email' => fake()->unique()->safeEmail(),
        'clinic_name' => fake()->company(),
    ]);
    $user = $result['user']->fresh();
    $clinic = Workspace::query()->where('owner_user_id', $user->id)->sole();
    $membership = $clinic->members()->where('user_id', $user->id)->sole();

    expect($user->account_type)->toBe('staff')
        ->and($user->doctor)->toBeNull()
        ->and($user->staffProfile)->not->toBeNull()
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->must_change_password)->toBeTrue()
        ->and(Hash::check($result['temporary_password'], $user->password))->toBeTrue()
        ->and($clinic->type)->toBe('clinic')
        ->and($clinic->entitlement)->not->toBeNull()
        ->and($membership->role)->toBe('admin')
        ->and($membership->status)->toBe('active');

    Event::assertDispatched(Registered::class, fn (Registered $event) => $event->user->is($user));
});

it('creates staff in the selected clinic without a personal workspace', function () {
    Event::fake([Registered::class]);
    $clinic = Workspace::factory()->clinic()->create();
    $result = app(SuperAdminAccountService::class)->createStaff([
        'name' => fake()->name(),
        'email' => fake()->unique()->safeEmail(),
        'clinic_id' => $clinic->id,
        'role' => 'receptionist',
    ]);
    $user = $result['user']->fresh();
    $membership = $user->memberships()->sole();

    expect($user->account_type)->toBe('staff')
        ->and($user->doctor)->toBeNull()
        ->and($user->staffProfile)->not->toBeNull()
        ->and($user->ownedWorkspaces()->exists())->toBeFalse()
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->must_change_password)->toBeTrue()
        ->and(Hash::check($result['temporary_password'], $user->password))->toBeTrue()
        ->and($membership->workspace_id)->toBe($clinic->id)
        ->and($membership->role)->toBe('receptionist')
        ->and($membership->status)->toBe('active');

    Event::assertDispatched(Registered::class, fn (Registered $event) => $event->user->is($user));
});

it('creates clinic staff through the actual superadmin form', function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Role::firstOrCreate(['name' => 'platform_admin', 'guard_name' => 'web']);
    $operator = User::factory()->create();
    $operator->assignRole('platform_admin');
    $clinic = Workspace::factory()->clinic()->create();
    $email = fake()->unique()->safeEmail();

    Livewire::actingAs($operator)
        ->test(CreateUser::class)
        ->fillForm([
            'creation_mode' => 'staff',
            'name' => fake()->name(),
            'email' => $email,
            'clinic_id' => $clinic->id,
            'role' => 'nurse',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $user = User::query()->where('email', $email)->sole();
    expect($user->must_change_password)->toBeTrue()
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->staffProfile)->not->toBeNull()
        ->and($user->memberships()->where('workspace_id', $clinic->id)->where('role', 'nurse')->exists())->toBeTrue();
});

<?php

use App\Console\Commands\DemoClinicCommand;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\User;
use App\Models\Workspace;
use App\Support\ActiveWorkspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

function demoClinic(): ?Workspace
{
    return Workspace::query()->where('type', 'clinic')->where('name', DemoClinicCommand::CLINIC_NAME)->first();
}

it('refuses a short password, since the accounts live in production', function () {
    $this->artisan('elosar:demo-clinic', ['--password' => 'corta'])->assertFailed();

    expect(demoClinic())->toBeNull();
});

it('builds the demo clinic with verified demo-only accounts and a full day', function () {
    $real = User::factory()->create(['email' => 'real@example.com', 'password' => Hash::make('secreta-real')]);

    $this->artisan('elosar:demo-clinic', ['--password' => 'DemoElosar2026!'])->assertSuccessful();

    $clinic = demoClinic();
    expect($clinic)->not->toBeNull();

    $accounts = User::query()->where('email', 'like', '%@'.DemoClinicCommand::EMAIL_DOMAIN)->get();
    expect($accounts)->toHaveCount(5)
        ->and($accounts->every(fn (User $u) => $u->email_verified_at !== null && Hash::check('DemoElosar2026!', $u->password)))->toBeTrue()
        ->and(Hash::check('secreta-real', $real->fresh()->password))->toBeTrue();

    app(ActiveWorkspace::class)->runUnguarded(function () use ($clinic) {
        $patients = Patient::query()->where('workspace_id', $clinic->id)->get();
        expect($patients)->toHaveCount(10)
            ->and($patients->every(fn (Patient $p) => str_starts_with($p->id_number, '99')))->toBeTrue();

        $star = $patients->firstWhere('name', 'María José Andrade Ruiz');
        expect($star->allergies()->pluck('allergen_name')->all())->toBe(['Penicilina']);

        expect(Appointment::query()->where('workspace_id', $clinic->id)->where('notes', 'like', 'Agenda demo%')->whereDate('appointment_date', Carbon::today())->count())->toBe(8);
    });
});

it('only moves the agenda to today when run again', function () {
    $this->artisan('elosar:demo-clinic', ['--password' => 'DemoElosar2026!'])->assertSuccessful();
    $clinic = demoClinic();

    app(ActiveWorkspace::class)->runUnguarded(function () use ($clinic) {
        Appointment::query()->where('workspace_id', $clinic->id)->update(['appointment_date' => Carbon::today()->subDays(5), 'status' => 'completed']);
    });

    $this->artisan('elosar:demo-clinic', ['--password' => 'OtraClaveLarga1'])->assertSuccessful();

    app(ActiveWorkspace::class)->runUnguarded(function () use ($clinic) {
        expect(Patient::query()->where('workspace_id', $clinic->id)->count())->toBe(10)
            ->and(Appointment::query()->where('workspace_id', $clinic->id)->where('notes', 'like', 'Agenda demo%')->whereDate('appointment_date', Carbon::today())->count())->toBe(8)
            ->and(Appointment::query()->where('workspace_id', $clinic->id)->where('status', 'checked_in')->count())->toBe(1)
            ->and(Appointment::query()->where('workspace_id', $clinic->id)->where('status', 'vitals_done')->count())->toBe(2);
    });

    // The password chosen on the first run stays.
    expect(Hash::check('DemoElosar2026!', User::query()->where('email', 'dr.salazar@'.DemoClinicCommand::EMAIL_DOMAIN)->value('password')))->toBeTrue();
});

it('leaves today\'s waiting room ready for the video, and clean of earlier takes', function () {
    $this->artisan('elosar:demo-clinic', ['--password' => 'DemoElosar2026!'])->assertSuccessful();

    $waiting = fn () => app(ActiveWorkspace::class)->runUnguarded(fn () => App\Models\MedicalRecord::query()
        ->where('workspace_id', demoClinic()->id)->where('status', 'vitals_done')->with('vitals')->get());
    expect($waiting())->toHaveCount(2)
        ->and($waiting()->pluck('vitals')->flatten()->pluck('temperature')->map(fn ($t) => (float) $t)->sort()->values()->all())->toBe([36.7, 38.6]);

    // A take attends one of them; the next deploy puts the room back.
    app(ActiveWorkspace::class)->runUnguarded(fn () => $waiting()->first()->update(['status' => 'completed']));
    $this->artisan('elosar:demo-clinic', ['--password' => 'DemoElosar2026!'])->assertSuccessful();

    expect($waiting())->toHaveCount(2);
});

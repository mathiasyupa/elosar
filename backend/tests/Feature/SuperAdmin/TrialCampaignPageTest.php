<?php

use App\Filament\SuperAdmin\Pages\TrialCampaign;
use App\Models\User;
use App\Services\TrialCampaignService;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('superadmin'));
});

it('shows the campaign that is currently in force', function () {
    $operator = entitlementOperator();

    Livewire::actingAs($operator)
        ->test(TrialCampaign::class)
        ->assertFormSet([
            'trial_duration_days' => 30,
            'trial_enabled_until' => '2026-12-31',
        ]);
});

it('changes the length new accounts get, without a SQL client', function () {
    $operator = entitlementOperator();

    Livewire::actingAs($operator)
        ->test(TrialCampaign::class)
        ->fillForm([
            'trial_duration_days' => 45,
            'trial_enabled_until' => '2027-03-31',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(TrialCampaignService::class)->settings())->toMatchArray([
        'trial_duration_days' => 45,
        'trial_enabled_until' => '2027-03-31',
    ]);

    // And a doctor signing up now actually gets 45 days.
    $user = User::factory()->create(['account_type' => 'doctor']);
    $grant = app(TrialCampaignService::class)->grantForDoctor($user);

    expect((int) today()->diffInDays($grant->ends_at->startOfDay()))->toBe(45);
});

it('leaves a running trial on the deadline it was given', function () {
    $operator = entitlementOperator();

    $early = User::factory()->create(['account_type' => 'doctor']);
    $originalEnd = app(TrialCampaignService::class)->grantForDoctor($early)->ends_at->toDateString();

    Livewire::actingAs($operator)
        ->test(TrialCampaign::class)
        ->fillForm(['trial_duration_days' => 90, 'trial_enabled_until' => '2027-12-31'])
        ->call('save');

    // Moving somebody's finish line after the fact is a decision, not a
    // setting: it belongs in the audited per-account actions.
    expect($early->fresh()->accountTrialGrant->ends_at->toDateString())->toBe($originalEnd);
});

it('refuses a length that would issue no trial at all', function () {
    $operator = entitlementOperator();

    Livewire::actingAs($operator)
        ->test(TrialCampaign::class)
        ->fillForm(['trial_duration_days' => 0, 'trial_enabled_until' => '2027-01-01'])
        ->call('save')
        ->assertHasFormErrors(['trial_duration_days']);
});

<?php

use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('blocks a stale terms session from reading workspaces', function () {
    [, $user] = makeDoctorWithWorkspace();
    $user->forceFill([
        'terms_version' => null,
        'terms_accepted_at' => null,
    ])->save();

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/workspaces')
        ->assertForbidden()
        ->assertJsonPath('code', 'terms_acceptance_required');
});

it('restores access after accepting the current terms', function () {
    [, $user] = makeDoctorWithWorkspace();
    $user->forceFill([
        'terms_version' => null,
        'terms_accepted_at' => null,
    ])->save();

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/auth/accept-terms', ['accept_terms' => true])
        ->assertOk()
        ->assertJsonPath('legal.terms_acceptance_required', false);

    $this->getJson('/api/v1/workspaces')->assertOk();
});

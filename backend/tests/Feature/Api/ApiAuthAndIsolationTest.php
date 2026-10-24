<?php

use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Workspace;
use Laravel\Sanctum\Sanctum;

/** Headers that select the active workspace for a tenant API request. */
function workspaceHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

// ─────────────────────────────────────────────
// Authentication
// ─────────────────────────────────────────────

it('logs in and returns a token, doctor and workspaces', function () {
    [$doctor, $user] = makeDoctorWithWorkspace();

    $this->postJson('/api/v1/auth/login', [
        'email'    => $user->email,
        'password' => 'password',
    ])
        ->assertOk()
        ->assertJsonStructure(['token', 'doctor' => ['id', 'name', 'specialty'], 'workspaces'])
        ->assertJsonPath('doctor.id', $doctor->id);
});

it('rejects login with an invalid password', function () {
    [, $user] = makeDoctorWithWorkspace();

    $this->postJson('/api/v1/auth/login', [
        'email'    => $user->email,
        'password' => 'wrong-password',
    ])->assertStatus(422);
});

it('rejects a user account with no linked doctor', function () {
    $user = \App\Models\User::factory()->create();

    $this->postJson('/api/v1/auth/login', [
        'email'    => $user->email,
        'password' => 'password',
    ])->assertStatus(403);
});

it('requires authentication on protected endpoints', function () {
    $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    $this->getJson('/api/v1/appointments', workspaceHeaders(1))->assertUnauthorized();
});

// ─────────────────────────────────────────────
// Workspace resolution middleware
// ─────────────────────────────────────────────

it('returns 400 when the X-Workspace-Id header is missing', function () {
    [, $user] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/appointments')->assertStatus(400);
});

it('returns 403 for a workspace the doctor does not belong to', function () {
    [, $user] = makeDoctorWithWorkspace();
    $foreign = Workspace::factory()->create();

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/appointments', workspaceHeaders($foreign->id))
        ->assertStatus(403);
});

it('lists workspaces without requiring an active workspace header', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/workspaces')
        ->assertOk()
        ->assertJsonPath('data.0.id', $ws->id);
});

// ─────────────────────────────────────────────
// Multi-tenant isolation through the API
// ─────────────────────────────────────────────

it('only exposes patients from the active workspace', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $otherWs = Workspace::factory()->create();

    Patient::factory()->create(['workspace_id' => $ws->id, 'name' => 'Paciente Propio']);
    $foreignPatient = Patient::factory()->create(['workspace_id' => $otherWs->id, 'name' => 'Paciente Ajeno']);

    Sanctum::actingAs($user);

    $names = collect(
        $this->getJson('/api/v1/patients', workspaceHeaders($ws->id))->assertOk()->json('data')
    )->pluck('name');

    expect($names)->toContain('Paciente Propio')
        ->and($names)->not->toContain('Paciente Ajeno');

    // Direct ID access to another tenant's patient must 404, not leak.
    $this->getJson("/api/v1/patients/{$foreignPatient->id}", workspaceHeaders($ws->id))
        ->assertNotFound();
});

// ─────────────────────────────────────────────
// Billing serialization contract
// ─────────────────────────────────────────────

it('emits invoice monetary amounts as strings (never floats)', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    $invoice = Invoice::factory()->create([
        'workspace_id' => $ws->id,
        'patient_id'   => $patient->id,
    ]);

    Sanctum::actingAs($user);

    $data = $this->getJson("/api/v1/invoices/{$invoice->id}", workspaceHeaders($ws->id))
        ->assertOk()
        ->json('data');

    expect($data['total'])->toBeString()
        ->and($data['subtotal'])->toBeString()
        ->and($data['amount_due'])->toBeString();
});

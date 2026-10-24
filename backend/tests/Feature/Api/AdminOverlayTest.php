<?php

use App\Models\MedicalRecord;
use App\Models\Patient;
use Laravel\Sanctum\Sanctum;

/*
| Multi-role: the 'admin' role summed on top of a base role grants operational
| admin powers (billing/reports/team) WITHOUT granting clinical access the base
| role lacks. Actors from makeClinicScenario().
*/

function overlayHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

function overlayInvoicePayload(int $workspaceId, int $patientId): array
{
    return [
        'workspace_id' => $workspaceId,
        'patient_id'   => $patientId,
        'issued_at'    => now()->toDateString(),
        'items'        => [['description' => 'Consulta médica', 'quantity' => 1, 'unit_price' => 30]],
    ];
}

it('lets a doctor+admin keep clinical access and gain billing manage', function () {
    $s = makeClinicScenario();
    setMemberRoles($s,'memUser', ['doctor', 'admin']);
    $h = overlayHeaders($s['clinic']->id);
    Sanctum::actingAs($s['memUser']);

    $patient = Patient::factory()->create(['workspace_id' => $s['clinic']->id]);
    $record = MedicalRecord::factory()->create([
        'workspace_id' => $s['clinic']->id,
        'patient_id'   => $patient->id,
        'created_by'   => $s['memDoctor']->id,
    ]);

    $this->getJson("/api/v1/records/{$record->id}", $h)->assertOk();

    $this->postJson('/api/v1/invoices', overlayInvoicePayload($s['clinic']->id, $patient->id), $h)
        ->assertStatus(201);

    $this->getJson('/api/v1/settings', $h)->assertOk();

    // The letterhead (papelería) is shared work since reception and admin
    // were allowed to edit it; the rest of settings.manage stays owner-only.
    $this->putJson('/api/v1/settings/letterhead', [
        'prescription_settings' => ['header' => 'Membrete'],
    ], $h)->assertOk();
});

it('lets a receptionist+admin manage billing but not read records', function () {
    $s = makeClinicScenario();
    setMemberRoles($s,'recUser', ['receptionist', 'admin']);
    $h = overlayHeaders($s['clinic']->id);
    Sanctum::actingAs($s['recUser']);

    $patient = Patient::factory()->create(['workspace_id' => $s['clinic']->id]);
    $record = MedicalRecord::factory()->create([
        'workspace_id' => $s['clinic']->id,
        'patient_id'   => $patient->id,
        'created_by'   => $s['memDoctor']->id,
    ]);

    $this->getJson('/api/v1/patients', $h)->assertOk();
    $this->getJson("/api/v1/records/{$record->id}", $h)->assertStatus(403);

    $this->postJson('/api/v1/invoices', overlayInvoicePayload($s['clinic']->id, $patient->id), $h)
        ->assertStatus(201);
});

it('returns the summed roles and billing.manage in /auth/me', function () {
    $s = makeClinicScenario();
    setMemberRoles($s,'memUser', ['doctor', 'admin']);
    Sanctum::actingAs($s['memUser']);

    $ws = collect($this->getJson('/api/v1/auth/me')->assertOk()->json('workspaces'))
        ->firstWhere('id', $s['clinic']->id);

    expect($ws['roles'])->toContain('admin')
        ->and($ws['roles'])->toContain('doctor')
        ->and($ws['permissions'])->toContain('billing.manage')
        ->and($ws['permissions'])->toContain('records.view');
});

it('lets the owner add the admin role to a member via team update', function () {
    $s = makeClinicScenario();
    Sanctum::actingAs($s['ownerUser']);

    $member = $s['clinic']->members()->where('user_id', $s['memUser']->id)->first();

    $data = $this->putJson(
        "/api/v1/clinic/team/{$member->id}",
        ['roles' => ['doctor', 'admin']],
        overlayHeaders($s['clinic']->id),
    )->assertOk()->json('data');

    expect($data['roles'])->toContain('admin')->toContain('doctor');
    expect($member->fresh()->hasRole('admin'))->toBeTrue();
});

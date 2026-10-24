<?php

use App\Models\Appointment;
use App\Models\Patient;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| Multi-role triage: receptionist + nurse
|--------------------------------------------------------------------------
| Adding the `nurse` role on top of `receptionist` grants vitals.create so a
| polyvalent front-desk auxiliary can also take vital signs — WITHOUT clinical
| records access. The owner assigns roles from the team screen (team.manage).
*/

function triageOverlayHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

function checkedInAppointment(int $workspaceId, int $doctorId): Appointment
{
    $patient = Patient::factory()->create(['workspace_id' => $workspaceId]);

    return Appointment::create([
        'workspace_id'     => $workspaceId,
        'patient_id'       => $patient->id,
        'doctor_id'        => $doctorId,
        'appointment_date' => now(),
        'status'           => 'checked_in',
    ]);
}

it('forbids a plain receptionist from recording vitals', function () {
    $s = makeClinicScenario();
    $appt = checkedInAppointment($s['clinic']->id, $s['memDoctor']->id);
    $h = triageOverlayHeaders($s['clinic']->id);

    Sanctum::actingAs($s['recUser']);
    $this->postJson('/api/v1/records/draft', ['appointment_id' => $appt->id], $h)
        ->assertStatus(403);
});

it('lets a receptionist+nurse open a draft and record vitals', function () {
    $s = makeClinicScenario();
    setMemberRoles($s, 'recUser', ['receptionist', 'nurse']);

    $appt = checkedInAppointment($s['clinic']->id, $s['memDoctor']->id);
    $h = triageOverlayHeaders($s['clinic']->id);
    Sanctum::actingAs($s['recUser']);

    $draftId = $this->postJson('/api/v1/records/draft', ['appointment_id' => $appt->id], $h)
        ->assertStatus(201)
        ->assertJsonPath('data.status', 'draft')
        ->json('data.id');

    $this->patchJson("/api/v1/records/{$draftId}/vitals", [
        'weight_kg' => 68, 'height_cm' => 165, 'heart_rate' => 80,
    ], $h)
        ->assertOk()
        ->assertJsonPath('data.status', 'vitals_done');
});

it('exposes vitals.create in /auth/me for a receptionist+nurse', function () {
    $s = makeClinicScenario();
    setMemberRoles($s, 'recUser', ['receptionist', 'nurse']);

    Sanctum::actingAs($s['recUser']);
    $ws = collect($this->getJson('/api/v1/auth/me')->assertOk()->json('workspaces'))
        ->firstWhere('id', $s['clinic']->id);

    expect($ws['permissions'])->toContain('vitals.create')
        // Keeps front-desk + cash powers, still no clinical records.
        ->and($ws['permissions'])->toContain('billing.manage')
        ->and($ws['permissions'])->not->toContain('records.view');
});

it('does NOT grant records access — triage is vitals only', function () {
    $s = makeClinicScenario();
    setMemberRoles($s, 'recUser', ['receptionist', 'nurse']);

    $appt = checkedInAppointment($s['clinic']->id, $s['memDoctor']->id);
    $h = triageOverlayHeaders($s['clinic']->id);
    Sanctum::actingAs($s['recUser']);

    $draftId = $this->postJson('/api/v1/records/draft', ['appointment_id' => $appt->id], $h)->json('data.id');

    // Can prep vitals, but cannot read the clinical record itself.
    $this->getJson("/api/v1/records/{$draftId}", $h)->assertStatus(403);
});

it('lets the owner add the nurse role via team update', function () {
    $s = makeClinicScenario();
    $member = $s['clinic']->members()->where('user_id', $s['recUser']->id)->first();
    $h = triageOverlayHeaders($s['clinic']->id);

    Sanctum::actingAs($s['ownerUser']);
    $this->putJson("/api/v1/clinic/team/{$member->id}", ['roles' => ['receptionist', 'nurse']], $h)
        ->assertOk()
        ->assertJsonPath('data.roles', ['receptionist', 'nurse']);

    expect($member->fresh()->hasRole('nurse'))->toBeTrue();
});

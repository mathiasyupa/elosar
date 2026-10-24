<?php

use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\Workspace;
use Laravel\Sanctum\Sanctum;

/*
| Patient `?sort=recent` ordering and the `last_visit_at` field. Both are driven
| by withMax('medicalRecords', 'created_at'); the subquery inherits the
| MedicalRecord WorkspaceScope, so the last-visit date must stay isolated to the
| active workspace.
*/

function recentHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

it('sorts patients by most recent medical record first with ?sort=recent', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();

    $stale  = Patient::factory()->create(['workspace_id' => $ws->id, 'name' => 'Ana Antigua']);
    $recent = Patient::factory()->create(['workspace_id' => $ws->id, 'name' => 'Beto Reciente']);
    $never  = Patient::factory()->create(['workspace_id' => $ws->id, 'name' => 'Carla SinVisita']);

    MedicalRecord::factory()->forWorkspace($ws, $stale, $doctor)->create(['created_at' => now()->subDays(30)]);
    MedicalRecord::factory()->forWorkspace($ws, $recent, $doctor)->create(['created_at' => now()->subDay()]);

    Sanctum::actingAs($user);

    $order = collect(
        $this->getJson('/api/v1/patients?sort=recent', recentHeaders($ws->id))
            ->assertOk()
            ->json('data')
    )->pluck('name');

    // Most recent visit first; the never-visited patient sorts last (NULLS LAST).
    expect($order->first())->toBe('Beto Reciente')
        ->and($order->take(2)->all())->toBe(['Beto Reciente', 'Ana Antigua'])
        ->and($order->last())->toBe('Carla SinVisita');
});

it('exposes last_visit_at as the date of the most recent record', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    MedicalRecord::factory()->forWorkspace($ws, $patient, $doctor)->create(['created_at' => now()->subDays(5)]);
    $last = now()->subDay();
    MedicalRecord::factory()->forWorkspace($ws, $patient, $doctor)->create(['created_at' => $last]);

    Sanctum::actingAs($user);

    $this->getJson("/api/v1/patients/{$patient->id}", recentHeaders($ws->id))
        ->assertOk()
        ->assertJsonPath('data.last_visit_at', $last->toDateString());
});

it('returns null last_visit_at for a patient with no records', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    Sanctum::actingAs($user);

    $this->getJson("/api/v1/patients/{$patient->id}", recentHeaders($ws->id))
        ->assertOk()
        ->assertJsonPath('data.last_visit_at', null);
});

it('does not let another workspace record bleed into last_visit_at', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();

    // A patient in the active workspace and a twin in a foreign workspace, each
    // with its own record. The active workspace's patient has an OLD visit; the
    // foreign workspace's twin has a much more recent one. (id_number is globally
    // unique in the schema, so the twins carry distinct ones — the isolation
    // point is that the foreign record must not bleed into our last_visit_at.)
    $ownPatient = Patient::factory()->create([
        'workspace_id' => $ws->id,
        'name'         => 'Paciente Propio',
    ]);
    MedicalRecord::factory()->forWorkspace($ws, $ownPatient, $doctor)
        ->create(['created_at' => now()->subDays(40)]);

    $foreignWs      = Workspace::factory()->create();
    $foreignPatient = Patient::factory()->create([
        'workspace_id' => $foreignWs->id,
        'name'         => 'Paciente Ajeno',
    ]);
    MedicalRecord::factory()->forWorkspace($foreignWs, $foreignPatient)
        ->create(['created_at' => now()->subHour()]); // much more recent

    Sanctum::actingAs($user);

    // last_visit_at for our patient must reflect ONLY our workspace's record.
    $this->getJson("/api/v1/patients/{$ownPatient->id}", recentHeaders($ws->id))
        ->assertOk()
        ->assertJsonPath('data.last_visit_at', now()->subDays(40)->toDateString());

    // The foreign patient is not even reachable from this workspace.
    $this->getJson("/api/v1/patients/{$foreignPatient->id}", recentHeaders($ws->id))
        ->assertNotFound();
});

it('filters the directory by the day of a completed consultation', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();

    $attended = Patient::factory()->create([
        'workspace_id' => $ws->id,
        'name' => 'Ana Atendida Hoy',
    ]);
    $draftOnly = Patient::factory()->create([
        'workspace_id' => $ws->id,
        'name' => 'Beto Aun En Consulta',
    ]);
    $olderVisit = Patient::factory()->create([
        'workspace_id' => $ws->id,
        'name' => 'Carla Atendida Antes',
    ]);

    MedicalRecord::factory()->forWorkspace($ws, $attended, $doctor)->create([
        'status' => 'completed',
        'created_at' => '2026-08-14 09:00:00',
    ]);
    MedicalRecord::factory()->forWorkspace($ws, $draftOnly, $doctor)->create([
        'status' => 'draft',
        'created_at' => '2026-08-14 10:00:00',
    ]);
    MedicalRecord::factory()->forWorkspace($ws, $olderVisit, $doctor)->create([
        'status' => 'completed',
        'created_at' => '2026-08-13 16:00:00',
    ]);

    $foreignWs = Workspace::factory()->create();
    $foreignPatient = Patient::factory()->create([
        'workspace_id' => $foreignWs->id,
        'name' => 'Paciente De Otra Clinica',
    ]);
    MedicalRecord::factory()->forWorkspace($foreignWs, $foreignPatient)->create([
        'status' => 'completed',
        'created_at' => '2026-08-14 11:00:00',
    ]);

    Sanctum::actingAs($user);

    $names = collect(
        $this->getJson(
            '/api/v1/patients?attended_from=2026-08-14&attended_to=2026-08-14',
            recentHeaders($ws->id),
        )
            ->assertOk()
            ->json('data'),
    )->pluck('name')->all();

    expect($names)->toBe(['Ana Atendida Hoy']);
});

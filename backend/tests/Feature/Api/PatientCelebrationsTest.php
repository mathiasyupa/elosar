<?php

use App\Models\MedicalRecord;
use App\Models\Patient;
use Laravel\Sanctum\Sanctum;

/*
| GET /patients/celebrations — the "Felicita a tu paciente" milestones.
| Buckets patients into birthdays (today / this week), patient anniversaries,
| recently welcomed, and re-engage (no visit in 6+ months). Workspace-isolated.
*/

function celebHeaders(int $wsId): array
{
    return ['X-Workspace-Id' => (string) $wsId];
}

it('buckets patients into the right milestone groups', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    // A — birthday today (same month-day, past year), no visits.
    $a = Patient::factory()->create(['workspace_id' => $ws->id, 'name' => 'Cumple Hoy', 'birth_date' => now()->subYears(30)->toDateString()]);
    // B — birthday in 3 days.
    Patient::factory()->create(['workspace_id' => $ws->id, 'name' => 'Cumple Semana', 'birth_date' => now()->addDays(3)->subYears(20)->toDateString()]);
    // C — anniversary today: first visit 2 years ago (today's MM-DD), last visit recent → not reactivate/new.
    $c = Patient::factory()->create(['workspace_id' => $ws->id, 'name' => 'Aniversario', 'birth_date' => '1980-01-15']);
    MedicalRecord::factory()->create(['workspace_id' => $ws->id, 'patient_id' => $c->id, 'created_at' => now()->subYears(2)]);
    MedicalRecord::factory()->create(['workspace_id' => $ws->id, 'patient_id' => $c->id, 'created_at' => now()->subDays(10)]);
    // D — new patient: only visit 5 days ago.
    $d = Patient::factory()->create(['workspace_id' => $ws->id, 'name' => 'Nuevo', 'birth_date' => '1990-02-20']);
    MedicalRecord::factory()->create(['workspace_id' => $ws->id, 'patient_id' => $d->id, 'created_at' => now()->subDays(5)]);
    // E — reactivate: only visit 8 months ago.
    $e = Patient::factory()->create(['workspace_id' => $ws->id, 'name' => 'Reactivar', 'birth_date' => '1975-03-25']);
    MedicalRecord::factory()->create(['workspace_id' => $ws->id, 'patient_id' => $e->id, 'created_at' => now()->subMonths(8)]);

    $data = $this->getJson('/api/v1/patients/celebrations', celebHeaders($ws->id))
        ->assertOk()
        ->json('data');

    expect(collect($data['birthdays_today'])->pluck('id'))->toContain($a->id)
        ->and(collect($data['birthdays_week'])->pluck('name'))->toContain('Cumple Semana')
        ->and(collect($data['anniversaries_today'])->firstWhere('id', $c->id)['years'])->toBe(2)
        ->and(collect($data['new_patients'])->pluck('id'))->toContain($d->id)
        ->and(collect($data['reactivate'])->pluck('id'))->toContain($e->id);

    // The recently-welcomed patient is not stale, and the anniversary patient
    // (recent last visit) is not in the re-engage bucket.
    expect(collect($data['reactivate'])->pluck('id'))->not->toContain($d->id)
        ->and(collect($data['reactivate'])->pluck('id'))->not->toContain($c->id);
});

it('does not leak birthdays from another workspace', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    [, , $otherWs] = makeDoctorWithWorkspace();

    Patient::factory()->create(['workspace_id' => $otherWs->id, 'name' => 'Ajeno', 'birth_date' => now()->subYears(40)->toDateString()]);

    Sanctum::actingAs($user);

    $data = $this->getJson('/api/v1/patients/celebrations', celebHeaders($ws->id))
        ->assertOk()
        ->json('data');

    expect(collect($data['birthdays_today'])->pluck('name'))->not->toContain('Ajeno');
});

it('shows newly imported patients and birthdays in a personal workspace without creating medical records', function () {
    [, $user, $personalWorkspace] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $imported = Patient::factory()->create([
        'workspace_id' => $personalWorkspace->id,
        'name' => 'Importada Personal',
        'birth_date' => now()->subYears(35)->toDateString(),
        'created_at' => now()->subDays(2),
        'updated_at' => now()->subDays(2),
    ]);

    $data = $this->getJson('/api/v1/patients/celebrations', celebHeaders($personalWorkspace->id))
        ->assertOk()
        ->json('data');

    expect(collect($data['new_patients'])->pluck('id'))->toContain($imported->id)
        ->and(collect($data['birthdays_today'])->pluck('id'))->toContain($imported->id);
});

<?php

use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| Patient outreach call lists
|--------------------------------------------------------------------------
| Two lists meant to be worked top to bottom, not just read:
|   - re-engage (celebrations.reactivate): seen long ago, nothing booked
|   - pending follow-ups: the doctor ordered a control that never got scheduled
| Both must never surface a patient who is already coming back.
*/

function outreachHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

/** A patient last seen $monthsAgo months ago, having paid $spent in total. */
function seenLongAgo(int $workspaceId, string $name, int $monthsAgo, string $spent = '0.00'): Patient
{
    $patient = Patient::factory()->create(['workspace_id' => $workspaceId, 'name' => $name]);

    MedicalRecord::factory()->create([
        'workspace_id' => $workspaceId,
        'patient_id' => $patient->id,
        'created_at' => now()->subMonths($monthsAgo),
    ]);

    if (bccomp($spent, '0.00', 2) > 0) {
        $invoice = Invoice::factory()->create([
            'workspace_id' => $workspaceId,
            'patient_id' => $patient->id,
            'total' => $spent,
        ]);
        Payment::factory()->create([
            'workspace_id' => $workspaceId,
            'invoice_id' => $invoice->id,
            'amount' => $spent,
            'payment_date' => now()->subMonths($monthsAgo)->toDateString(),
        ]);
    }

    return $patient;
}

// ─────────────────────────────────────────────
// Re-engage list
// ─────────────────────────────────────────────

it('leaves out patients who already have an appointment booked', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();

    $lapsed = seenLongAgo($ws->id, 'Sin agendar', 10);
    $coming = seenLongAgo($ws->id, 'Ya viene el martes', 10);

    Appointment::factory()->create([
        'workspace_id' => $ws->id,
        'patient_id' => $coming->id,
        'doctor_id' => $doctor->id,
        'appointment_date' => now()->addDays(4),
        'status' => 'scheduled',
    ]);

    Sanctum::actingAs($user);

    $names = collect($this->getJson('/api/v1/patients/celebrations', outreachHeaders($ws->id))
        ->assertOk()
        ->json('data.reactivate'))
        ->pluck('name');

    expect($names)->toContain('Sin agendar')
        ->not->toContain('Ya viene el martes');
});

it('still lists a patient whose only upcoming appointment was cancelled', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = seenLongAgo($ws->id, 'Canceló y no reagendó', 8);

    Appointment::factory()->create([
        'workspace_id' => $ws->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'appointment_date' => now()->addDays(3),
        'status' => 'cancelled',
    ]);

    Sanctum::actingAs($user);

    expect(collect($this->getJson('/api/v1/patients/celebrations', outreachHeaders($ws->id))
        ->assertOk()
        ->json('data.reactivate'))->pluck('name'))
        ->toContain('Canceló y no reagendó');
});

it('ranks the call list by what the patient leaves at the practice, not by staleness', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();

    // The stalest patient is worth the least: they must NOT lead the list.
    seenLongAgo($ws->id, 'Una visita en 2019', 60, '15.00');
    seenLongAgo($ws->id, 'Paciente habitual', 7, '900.00');
    seenLongAgo($ws->id, 'Ocasional', 9, '120.00');

    Sanctum::actingAs($user);

    $rows = $this->getJson('/api/v1/patients/celebrations', outreachHeaders($ws->id))
        ->assertOk()
        ->json('data.reactivate');

    expect(collect($rows)->pluck('name')->all())
        ->toBe(['Paciente habitual', 'Ocasional', 'Una visita en 2019']);

    expect($rows[0]['spent'])->toBe('900.00')
        ->and($rows[0]['visits'])->toBe(1)
        ->and($rows[0]['months_since'])->toBe(7);
});

it('hides the amount from a nurse, who may see patients but not money', function () {
    $s = makeClinicScenario();
    setMemberRoles($s, 'nurseUser', ['nurse']);
    seenLongAgo($s['clinic']->id, 'Paciente', 9, '300.00');

    Sanctum::actingAs($s['nurseUser']);

    $rows = $this->getJson('/api/v1/patients/celebrations', outreachHeaders($s['clinic']->id))
        ->assertOk()
        ->json('data.reactivate');

    expect($rows[0]['name'])->toBe('Paciente')
        ->and($rows[0]['spent'])->toBeNull()
        // The non-financial signal still reaches them.
        ->and($rows[0]['visits'])->toBe(1);
});

// ─────────────────────────────────────────────
// Pending follow-ups
// ─────────────────────────────────────────────

/** A record ordering a control on $dueAt for a fresh patient. */
function orderFollowUp(int $workspaceId, string $name, string $dueAt, ?int $createdBy = null, string $notes = 'Control de presión'): Patient
{
    $patient = Patient::factory()->create(['workspace_id' => $workspaceId, 'name' => $name]);

    MedicalRecord::factory()->create([
        'workspace_id' => $workspaceId,
        'patient_id' => $patient->id,
        'created_by' => $createdBy,
        'created_at' => now()->subMonths(3),
        'follow_up_date' => $dueAt,
        'follow_up_notes' => $notes,
    ]);

    return $patient;
}

it('lists an ordered control that never became an appointment', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    orderFollowUp($ws->id, 'Debía volver hace un mes', now()->subMonth()->toDateString());

    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/patients/pending-follow-ups', outreachHeaders($ws->id))
        ->assertOk();

    $rows = $response->json('data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['name'])->toBe('Debía volver hace un mes')
        ->and($rows[0]['overdue'])->toBeTrue()
        ->and($rows[0]['notes'])->toBe('Control de presión')
        ->and($response->json('meta.overdue'))->toBe(1);
});

it('drops a follow-up once the patient came back on their own', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = orderFollowUp($ws->id, 'Ya volvió', now()->subMonths(2)->toDateString());

    // A later consultation, after the control was due → nothing to chase.
    MedicalRecord::factory()->create([
        'workspace_id' => $ws->id,
        'patient_id' => $patient->id,
        'created_at' => now()->subDays(5),
    ]);

    Sanctum::actingAs($user);

    expect($this->getJson('/api/v1/patients/pending-follow-ups', outreachHeaders($ws->id))
        ->assertOk()
        ->json('data'))->toBeEmpty();
});

it('drops a follow-up when the patient already has the appointment booked', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = orderFollowUp($ws->id, 'Ya agendó', now()->subDays(10)->toDateString());

    Appointment::factory()->create([
        'workspace_id' => $ws->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'appointment_date' => now()->addDays(2),
        'status' => 'scheduled',
    ]);

    Sanctum::actingAs($user);

    expect($this->getJson('/api/v1/patients/pending-follow-ups', outreachHeaders($ws->id))
        ->assertOk()
        ->json('data'))->toBeEmpty();
});

it('keeps only the latest control per patient and sorts the most overdue first', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();

    $patient = orderFollowUp($ws->id, 'Con dos indicaciones', now()->subMonths(6)->toDateString());
    // A newer order supersedes the old one.
    MedicalRecord::factory()->create([
        'workspace_id' => $ws->id,
        'patient_id' => $patient->id,
        'created_at' => now()->subMonth(),
        'follow_up_date' => now()->subDays(3)->toDateString(),
        'follow_up_notes' => 'Nuevo control',
    ]);

    orderFollowUp($ws->id, 'El más atrasado', now()->subMonths(4)->toDateString());

    Sanctum::actingAs($user);

    $rows = $this->getJson('/api/v1/patients/pending-follow-ups', outreachHeaders($ws->id))
        ->assertOk()
        ->json('data');

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['name'])->toBe('El más atrasado')
        ->and($rows[1]['name'])->toBe('Con dos indicaciones')
        ->and($rows[1]['follow_up_date'])->toBe(now()->subDays(3)->toDateString());
});

it('ignores controls that are still far in the future', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    orderFollowUp($ws->id, 'Control en tres meses', now()->addMonths(3)->toDateString());
    orderFollowUp($ws->id, 'Control la próxima semana', now()->addDays(7)->toDateString());

    Sanctum::actingAs($user);

    $rows = $this->getJson('/api/v1/patients/pending-follow-ups', outreachHeaders($ws->id))
        ->assertOk()
        ->json('data');

    expect(collect($rows)->pluck('name')->all())->toBe(['Control la próxima semana'])
        ->and($rows[0]['overdue'])->toBeFalse();
});

it('gives reception the call list without the clinical reason', function () {
    $s = makeClinicScenario();
    setMemberRoles($s, 'recUser', ['receptionist']);
    orderFollowUp($s['clinic']->id, 'Paciente', now()->subWeek()->toDateString(), $s['ownerDoctor']->id);

    Sanctum::actingAs($s['recUser']);

    $rows = $this->getJson('/api/v1/patients/pending-follow-ups', outreachHeaders($s['clinic']->id))
        ->assertOk()
        ->json('data');

    // Enough to phone and schedule; not why the doctor wants them back.
    expect($rows[0]['name'])->toBe('Paciente')
        ->and($rows[0]['phone'])->not->toBeNull()
        ->and($rows[0]['notes'])->toBeNull();
});

it('shows an employed doctor only the controls they ordered', function () {
    $s = makeClinicScenario();
    orderFollowUp($s['clinic']->id, 'Del titular', now()->subWeek()->toDateString(), $s['ownerDoctor']->id);
    orderFollowUp($s['clinic']->id, 'Del empleado', now()->subWeek()->toDateString(), $s['memDoctor']->id);

    Sanctum::actingAs($s['memUser']);

    expect(collect($this->getJson('/api/v1/patients/pending-follow-ups', outreachHeaders($s['clinic']->id))
        ->assertOk()
        ->json('data'))->pluck('name')->all())
        ->toBe(['Del empleado']);
});

/**
 * Regression guard for a real N+1: checking whether a patient "came back"
 * used to run one query per candidate inside the loop. Fifteen candidates and
 * fifteen queries would look identical to "it lists them all" without this —
 * the query count is the only thing that actually catches it going back.
 */
it('answers a page of pending follow-ups without one query per candidate', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();

    for ($i = 0; $i < 15; $i++) {
        orderFollowUp($ws->id, "Paciente {$i}", now()->subWeeks($i + 1)->toDateString());
    }

    Sanctum::actingAs($user);

    DB::enableQueryLog();
    $rows = $this->getJson('/api/v1/patients/pending-follow-ups', outreachHeaders($ws->id))
        ->assertOk()
        ->json('data');
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($rows)->toHaveCount(15)
        // A handful of fixed queries (auth, workspace, booked list, records,
        // the one batched "later visits" lookup) — not one growing with N.
        ->and($queryCount)->toBeLessThan(15);
});

it('never leaks follow-ups from another workspace', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    orderFollowUp($ws->id, 'Propio', now()->subWeek()->toDateString());

    [, , $otherWs] = makeDoctorWithWorkspace();
    orderFollowUp($otherWs->id, 'Ajeno', now()->subWeek()->toDateString());

    Sanctum::actingAs($user);

    expect(collect($this->getJson('/api/v1/patients/pending-follow-ups', outreachHeaders($ws->id))
        ->assertOk()
        ->json('data'))->pluck('name')->all())
        ->toBe(['Propio']);
});

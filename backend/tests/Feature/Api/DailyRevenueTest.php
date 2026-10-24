<?php

use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Workspace;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| Daily finance report (/reports/daily)
|--------------------------------------------------------------------------
| Day-level view of one calendar month: the cash close for today (amount +
| payment methods), a bar per day, and the same-point comparison against the
| previous month. Same tenant isolation and self-scoping as /reports/revenue.
*/

function dailyHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

/** A collected payment of $amount on $date, through an invoice of the same value. */
function seedCollection(
    Workspace $workspace,
    Patient $patient,
    string $amount,
    string $date,
    string $method = 'cash',
    ?int $attendingDoctorId = null,
): void {
    $invoice = Invoice::factory()->create([
        'workspace_id' => $workspace->id,
        'patient_id' => $patient->id,
        'attending_doctor_id' => $attendingDoctorId,
        'total' => $amount,
        'issued_at' => $date,
    ]);
    Payment::factory()->create([
        'workspace_id' => $workspace->id,
        'invoice_id' => $invoice->id,
        'amount' => $amount,
        'payment_method' => $method,
        'payment_date' => $date,
    ]);
}

it('returns a bar for every day of the month with money on the right days', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    $month = now()->startOfMonth();
    $day3 = $month->copy()->addDays(2)->toDateString();

    seedCollection($ws, $patient, '150.00', $day3);
    seedCollection($ws, $patient, '50.00', $day3);

    Sanctum::actingAs($user);

    $data = $this->getJson('/api/v1/reports/daily', dailyHeaders($ws->id))
        ->assertOk()
        ->json('data');

    expect($data['days'])->toHaveCount($month->copy()->endOfMonth()->day)
        ->and($data['month'])->toBe($month->format('Y-m'))
        ->and($data['is_current_month'])->toBeTrue();

    $third = collect($data['days'])->firstWhere('date', $day3);
    expect($third['total'])->toBe('200.00')
        ->and($third['count'])->toBe(2)
        ->and($third['day'])->toBe(3);

    // Money is a string (bcmath), and untouched days are a real zero.
    $first = collect($data['days'])->firstWhere('day', 1);
    expect($first['total'])->toBe('0.00');

    expect($data['summary']['collected'])->toBe('200.00')
        ->and($data['summary']['active_days'])->toBe(1)
        ->and($data['summary']['best_day']['total'])->toBe('200.00');
});

it('breaks the cash close down by payment method', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    $today = now()->toDateString();

    seedCollection($ws, $patient, '80.00', $today, 'cash');
    seedCollection($ws, $patient, '120.00', $today, 'transfer');

    Sanctum::actingAs($user);

    $data = $this->getJson('/api/v1/reports/daily', dailyHeaders($ws->id))
        ->assertOk()
        ->json('data');

    $methods = collect($data['payment_methods'])->keyBy('method');
    expect($methods['transfer']['total'])->toBe('120.00')
        ->and($methods['cash']['total'])->toBe('80.00')
        // Ordered by money, so the biggest method leads.
        ->and($data['payment_methods'][0]['method'])->toBe('transfer');

    expect($data['today']['collected'])->toBe('200.00')
        ->and($data['today']['billed'])->toBe('200.00')
        ->and($data['today']['invoices'])->toBe(2)
        ->and($data['today']['pending'])->toBe('0.00');
});

it('counts patients seen today and the ticket left pending', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    // Billed $300 today, only $100 collected.
    $invoice = Invoice::factory()->create([
        'workspace_id' => $ws->id,
        'patient_id' => $patient->id,
        'total' => '300.00',
        'issued_at' => now()->toDateString(),
    ]);
    Payment::factory()->create([
        'workspace_id' => $ws->id,
        'invoice_id' => $invoice->id,
        'amount' => '100.00',
        'payment_date' => now()->toDateString(),
    ]);

    Appointment::factory()->count(2)->create([
        'workspace_id' => $ws->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'appointment_date' => now(),
        'status' => 'completed',
    ]);
    Appointment::factory()->create([
        'workspace_id' => $ws->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'appointment_date' => now(),
        'status' => 'scheduled',
    ]);

    Sanctum::actingAs($user);

    $today = $this->getJson('/api/v1/reports/daily', dailyHeaders($ws->id))
        ->assertOk()
        ->json('data.today');

    expect($today['patients'])->toBe(2)
        ->and($today['billed'])->toBe('300.00')
        ->and($today['pending'])->toBe('200.00');
});

it('compares the month against the previous one cut at the same day', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    // $100 on the 1st of this month, $60 on the 1st of last month, and $500
    // late last month (after today's day number → outside the fair comparison).
    seedCollection($ws, $patient, '100.00', now()->startOfMonth()->toDateString());
    seedCollection($ws, $patient, '60.00', now()->startOfMonth()->subMonth()->toDateString());
    seedCollection($ws, $patient, '500.00', now()->subMonth()->endOfMonth()->toDateString());

    Sanctum::actingAs($user);

    $comparison = $this->getJson('/api/v1/reports/daily', dailyHeaders($ws->id))
        ->assertOk()
        ->json('data.comparison');

    expect($comparison['previous_month'])->toBe(now()->subMonth()->format('Y-m'))
        ->and($comparison['previous_to_date'])->toBe('60.00')
        ->and($comparison['previous_total'])->toBe('560.00')
        ->and($comparison['delta'])->toBe('40.00');
})->skip(fn () => now()->day === now()->endOfMonth()->day, 'On the last day of the month the cut covers everything.');

it('serves a past month through ?month= and drops the projection', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    $lastMonth = now()->subMonth()->startOfMonth();
    seedCollection($ws, $patient, '75.00', $lastMonth->copy()->addDays(4)->toDateString());

    Sanctum::actingAs($user);

    $data = $this->getJson('/api/v1/reports/daily?month='.$lastMonth->format('Y-m'), dailyHeaders($ws->id))
        ->assertOk()
        ->json('data');

    expect($data['month'])->toBe($lastMonth->format('Y-m'))
        ->and($data['is_current_month'])->toBeFalse()
        ->and($data['summary']['collected'])->toBe('75.00')
        ->and($data['summary']['projection'])->toBeNull()
        ->and($data['days'])->toHaveCount($lastMonth->copy()->endOfMonth()->day);
});

it('falls back to the current month when ?month= is garbage', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $data = $this->getJson('/api/v1/reports/daily?month=not-a-month', dailyHeaders($ws->id))
        ->assertOk()
        ->json('data');

    expect($data['month'])->toBe(now()->format('Y-m'));
});

it('never leaks another workspace money into the daily series', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    seedCollection($ws, $patient, '100.00', now()->toDateString());

    $otherWs = Workspace::factory()->create();
    $otherPatient = Patient::factory()->create(['workspace_id' => $otherWs->id]);
    seedCollection($otherWs, $otherPatient, '999.00', now()->toDateString());

    Sanctum::actingAs($user);

    $data = $this->getJson('/api/v1/reports/daily', dailyHeaders($ws->id))
        ->assertOk()
        ->json('data');

    expect($data['summary']['collected'])->toBe('100.00')
        ->and($data['today']['collected'])->toBe('100.00');
});

it('shows an employed doctor only their own day, not the clinic day', function () {
    $s = makeClinicScenario();
    $patient = Patient::factory()->create(['workspace_id' => $s['clinic']->id]);
    $today = now()->toDateString();

    seedCollection($s['clinic'], $patient, '100.00', $today, 'cash', $s['ownerDoctor']->id);
    seedCollection($s['clinic'], $patient, '40.00', $today, 'cash', $s['memDoctor']->id);

    Sanctum::actingAs($s['memUser']);

    $data = $this->getJson('/api/v1/reports/daily', dailyHeaders($s['clinic']->id))
        ->assertOk()
        ->json('data');

    expect($data['scope'])->toBe('own')
        ->and($data['today']['collected'])->toBe('40.00')
        ->and($data['summary']['collected'])->toBe('40.00');

    // The owner sees the whole clinic.
    Sanctum::actingAs($s['ownerUser']);

    $ownerData = $this->getJson('/api/v1/reports/daily', dailyHeaders($s['clinic']->id))
        ->assertOk()
        ->json('data');

    expect($ownerData['scope'])->toBe('clinic')
        ->and($ownerData['today']['collected'])->toBe('140.00');
});

it('reports the average price and revenue share of each top service', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    $invoice = Invoice::factory()->create([
        'workspace_id' => $ws->id,
        'patient_id' => $patient->id,
        'total' => '400.00',
    ]);
    // $300 across 2 consultations + $100 in one procedure.
    $invoice->items()->createMany([
        ['description' => 'Consulta', 'quantity' => 1, 'unit_price' => '150.00', 'line_total' => '150.00'],
        ['description' => 'Consulta', 'quantity' => 1, 'unit_price' => '150.00', 'line_total' => '150.00'],
        ['description' => 'Procedimiento', 'quantity' => 1, 'unit_price' => '100.00', 'line_total' => '100.00'],
    ]);

    Sanctum::actingAs($user);

    $data = $this->getJson('/api/v1/reports/revenue', dailyHeaders($ws->id))
        ->assertOk()
        ->json('data');

    // Money leads: the biggest earner is first even though both have counts.
    expect($data['services_total'])->toBe('400.00')
        ->and($data['top_services'][0]['description'])->toBe('Consulta')
        ->and($data['top_services'][0]['total'])->toBe('300.00')
        ->and($data['top_services'][0]['avg'])->toBe('150.00')
        ->and($data['top_services'][0]['share'])->toBe('75.0')
        ->and($data['top_services'][1]['share'])->toBe('25.0');
});

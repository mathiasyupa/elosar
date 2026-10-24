<?php

use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Payment;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| Revenue report scoping
|--------------------------------------------------------------------------
| An employed doctor (clinical-only member) sees ONLY their own figures — never
| the clinic's totals nor a colleague's settlement. Owner / admin / cashier see
| the whole clinic.
*/

function revenueHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

/** $100 collected for the owner doctor + $40 for the member doctor. */
function seedTwoDoctorRevenue(array $s): void
{
    $patient = Patient::factory()->create(['workspace_id' => $s['clinic']->id]);

    foreach ([[$s['ownerDoctor']->id, '100.00'], [$s['memDoctor']->id, '40.00']] as [$doctorId, $amount]) {
        $invoice = Invoice::factory()->create([
            'workspace_id' => $s['clinic']->id,
            'patient_id' => $patient->id,
            'attending_doctor_id' => $doctorId,
            'total' => $amount,
        ]);
        Payment::factory()->create([
            'workspace_id' => $s['clinic']->id,
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'payment_date' => now()->toDateString(),
        ]);
    }
}

it('shows an employed doctor only their own revenue, never the clinic total', function () {
    $s = makeClinicScenario();
    seedTwoDoctorRevenue($s);

    Sanctum::actingAs($s['memUser']); // plain doctor member
    $data = $this->getJson('/api/v1/reports/revenue', revenueHeaders($s['clinic']->id))
        ->assertOk()->json('data');

    expect($data['scope'])->toBe('own')
        // Only their $40 — the owner's $100 is not theirs to see.
        ->and($data['collected_total'])->toBe('40.00')
        ->and($data['billed_total'])->toBe('40.00');

    // The settlement shows only their own row.
    $doctorIds = collect($data['by_doctor'])->pluck('doctor_id')->all();
    expect($doctorIds)->toBe([$s['memDoctor']->id]);
});

it('shows the owner the whole clinic revenue', function () {
    $s = makeClinicScenario();
    seedTwoDoctorRevenue($s);

    Sanctum::actingAs($s['ownerUser']);
    $data = $this->getJson('/api/v1/reports/revenue', revenueHeaders($s['clinic']->id))
        ->assertOk()->json('data');

    expect($data['scope'])->toBe('clinic')
        ->and($data['collected_total'])->toBe('140.00');

    expect(collect($data['by_doctor'])->pluck('doctor_id')->sort()->values()->all())
        ->toEqual(collect([$s['ownerDoctor']->id, $s['memDoctor']->id])->sort()->values()->all());
});

it('shows the cashier the whole clinic revenue', function () {
    $s = makeClinicScenario();
    seedTwoDoctorRevenue($s);
    $cashier = makeStaff('Caja Demo', 'caja_'.uniqid().'@clinica.test');
    app(App\Services\ClinicService::class)->attachMember($s['clinic'], $cashier, 'billing');

    Sanctum::actingAs($cashier);
    $data = $this->getJson('/api/v1/reports/revenue', revenueHeaders($s['clinic']->id))
        ->assertOk()->json('data');

    expect($data['scope'])->toBe('clinic')
        ->and($data['collected_total'])->toBe('140.00');
});

it('shows a doctor who also administers the whole clinic revenue', function () {
    $s = makeClinicScenario();
    seedTwoDoctorRevenue($s);
    // doctor + admin → runs the clinic, so no self-scoping.
    setMemberRoles($s, 'memUser', ['doctor', 'admin']);

    Sanctum::actingAs($s['memUser']);
    $data = $this->getJson('/api/v1/reports/revenue', revenueHeaders($s['clinic']->id))
        ->assertOk()->json('data');

    expect($data['scope'])->toBe('clinic')
        ->and($data['collected_total'])->toBe('140.00');
});

it('keeps the solo doctor seeing their personal workspace revenue', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    $invoice = Invoice::factory()->create([
        'workspace_id' => $ws->id,
        'patient_id' => $patient->id,
        'attending_doctor_id' => $doctor->id,
        'total' => '75.00',
    ]);
    Payment::factory()->create([
        'workspace_id' => $ws->id,
        'invoice_id' => $invoice->id,
        'amount' => '75.00',
        'payment_date' => now()->toDateString(),
    ]);

    Sanctum::actingAs($user);
    $data = $this->getJson('/api/v1/reports/revenue', revenueHeaders($ws->id))
        ->assertOk()->json('data');

    expect($data['scope'])->toBe('clinic') // personal workspace = everything is theirs
        ->and($data['collected_total'])->toBe('75.00');
});

<?php

use App\Models\FinanceEntry;
use App\Models\Invoice;
use App\Models\MedicalService;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Workspace;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| Doctor settlement report (/reports/settlement)
|--------------------------------------------------------------------------
| "Liquidación por médico" for a period: what each attending doctor earned,
| what the clinic kept, and the same money opened up attention by attention.
| The invariant that makes the report trustworthy: the detail rows add up
| EXACTLY to the per-doctor totals, and doctor + clinic add up to collected.
*/

function settlementHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

/** An invoice for $total attributed to $doctorId, collected in full on $date. */
function seedAttention(
    Workspace $workspace,
    Patient $patient,
    ?int $doctorId,
    string $total,
    string $date,
    array $lines = [],
): Invoice {
    $invoice = Invoice::factory()->create([
        'workspace_id' => $workspace->id,
        'patient_id' => $patient->id,
        'attending_doctor_id' => $doctorId,
        'total' => $total,
        'amount_paid' => $total,
        'status' => 'paid',
        'issued_at' => $date,
    ]);

    if ($lines !== []) {
        $invoice->items()->createMany($lines);
    }

    Payment::factory()->create([
        'workspace_id' => $workspace->id,
        'invoice_id' => $invoice->id,
        'amount' => $total,
        'payment_date' => $date,
    ]);

    return $invoice;
}

it('settles only the money collected inside the selected period', function () {
    $s = makeClinicScenario();
    $clinic = $s['clinic'];
    $headers = settlementHeaders($clinic->id);
    $patient = Patient::factory()->create(['workspace_id' => $clinic->id]);

    $member = $clinic->members()->where('doctor_id', $s['memDoctor']->id)->firstOrFail();
    $member->update(['commission_pct' => 60]);

    seedAttention($clinic, $patient, $s['memDoctor']->id, '100.00', now()->startOfMonth()->toDateString());
    // Collected last month — outside the default (current month) window.
    seedAttention($clinic, $patient, $s['memDoctor']->id, '500.00', now()->subMonth()->startOfMonth()->toDateString());

    Sanctum::actingAs($s['ownerUser']);

    $data = $this->getJson('/api/v1/reports/settlement', $headers)->assertOk()->json('data');

    expect($data['totals']['collected'])->toBe('100.00')
        ->and($data['totals']['doctor_net'])->toBe('60.00')
        ->and($data['totals']['clinic_amount'])->toBe('40.00')
        ->and($data['totals']['attentions'])->toBe(1);

    // Widening the window brings the previous month in.
    $wide = $this->getJson('/api/v1/reports/settlement?from='
        .now()->subMonth()->startOfMonth()->toDateString().'&to='.now()->endOfMonth()->toDateString(), $headers)
        ->assertOk()->json('data');

    expect($wide['totals']['collected'])->toBe('600.00')
        ->and($wide['totals']['doctor_net'])->toBe('360.00');
});

it('opens the settlement attention by attention, adding up to the doctor total', function () {
    $s = makeClinicScenario();
    $clinic = $s['clinic'];
    $headers = settlementHeaders($clinic->id);
    $patient = Patient::factory()->create(['workspace_id' => $clinic->id]);

    $consulta = MedicalService::factory()->create(['workspace_id' => $clinic->id]);
    $eco = MedicalService::factory()->create(['workspace_id' => $clinic->id]);

    $member = $clinic->members()->where('doctor_id', $s['memDoctor']->id)->firstOrFail();
    $member->update(['commission_pct' => 70]);

    seedAttention($clinic, $patient, $s['memDoctor']->id, '65.00', now()->toDateString(), [
        ['medical_service_id' => $consulta->id, 'description' => 'Consulta', 'quantity' => '1', 'unit_price' => '30.00', 'line_total' => '30.00'],
        ['medical_service_id' => $eco->id, 'description' => 'Ecografía', 'quantity' => '1', 'unit_price' => '35.00', 'line_total' => '35.00'],
    ]);

    Sanctum::actingAs($s['ownerUser']);

    $data = $this->getJson('/api/v1/reports/settlement', $headers)->assertOk()->json('data');

    $detail = collect($data['detail']);
    expect($detail)->toHaveCount(2)
        ->and($detail->pluck('service')->all())->toContain('Consulta', 'Ecografía');

    // Every line carries the patient, the doctor and its own split…
    $consultaRow = $detail->firstWhere('service', 'Consulta');
    expect($consultaRow['doctor_name'])->toBe($s['memDoctor']->name)
        ->and($consultaRow['patient_name'])->toBe($patient->name)
        ->and($consultaRow['price'])->toBe('30.00')
        ->and($consultaRow['doctor_amount'])->toBe('21.00')
        ->and($consultaRow['clinic_amount'])->toBe('9.00');

    // …and the lines reconcile with the totals, to the cent.
    $sumDoctor = $detail->reduce(fn ($carry, $row) => bcadd($carry, (string) $row['doctor_amount'], 2), '0.00');
    $sumCollected = $detail->reduce(fn ($carry, $row) => bcadd($carry, (string) $row['collected'], 2), '0.00');

    expect($sumDoctor)->toBe($data['totals']['doctor_amount'])
        ->and($sumDoctor)->toBe('45.50')
        ->and($sumCollected)->toBe($data['totals']['collected']);
});

it('discounts an agreed deduction from the doctor payout and leaves it with the clinic', function () {
    $s = makeClinicScenario();
    $clinic = $s['clinic'];
    $headers = settlementHeaders($clinic->id);
    $patient = Patient::factory()->create(['workspace_id' => $clinic->id]);

    $member = $clinic->members()->where('doctor_id', $s['memDoctor']->id)->firstOrFail();
    $member->update(['commission_pct' => 50]);

    seedAttention($clinic, $patient, $s['memDoctor']->id, '200.00', now()->toDateString());

    FinanceEntry::create([
        'workspace_id' => $clinic->id,
        'type' => FinanceEntry::TYPE_DOCTOR_ADJUSTMENT,
        'category' => 'supplies',
        'description' => 'Insumos consumidos',
        'amount' => '30.00',
        'entry_date' => now()->toDateString(),
        'doctor_id' => $s['memDoctor']->id,
    ]);

    Sanctum::actingAs($s['ownerUser']);

    $data = $this->getJson('/api/v1/reports/settlement', $headers)->assertOk()->json('data');
    $row = collect($data['by_doctor'])->firstWhere('doctor_id', $s['memDoctor']->id);

    expect($row['doctor_amount'])->toBe('100.00')   // 50% of what was collected
        ->and($row['adjustments'])->toBe('30.00')
        ->and($row['doctor_net'])->toBe('70.00')    // what they actually take home
        ->and($row['clinic_amount'])->toBe('130.00')
        ->and($data['totals']['clinic_amount'])->toBe('130.00');
});

it('shows an employed doctor only their own settlement', function () {
    $s = makeClinicScenario();
    $clinic = $s['clinic'];
    $headers = settlementHeaders($clinic->id);
    $patient = Patient::factory()->create(['workspace_id' => $clinic->id]);

    $member = $clinic->members()->where('doctor_id', $s['memDoctor']->id)->firstOrFail();
    $member->update(['commission_pct' => 50]);

    seedAttention($clinic, $patient, $s['memDoctor']->id, '100.00', now()->toDateString());
    seedAttention($clinic, $patient, $s['ownerDoctor']->id, '900.00', now()->toDateString());

    Sanctum::actingAs($s['memUser']);

    $data = $this->getJson('/api/v1/reports/settlement', $headers)->assertOk()->json('data');

    expect($data['scope'])->toBe('own')
        ->and($data['totals']['collected'])->toBe('100.00')
        ->and(collect($data['by_doctor'])->pluck('doctor_id')->all())->toBe([$s['memDoctor']->id])
        ->and(collect($data['detail'])->pluck('doctor_id')->unique()->all())->toBe([$s['memDoctor']->id]);

    // The owner reads the whole clinic.
    Sanctum::actingAs($s['ownerUser']);
    $ownerData = $this->getJson('/api/v1/reports/settlement', $headers)->assertOk()->json('data');

    expect($ownerData['scope'])->toBe('clinic')
        ->and($ownerData['totals']['collected'])->toBe('1000.00');
});

it('never mixes another clinic settlement in', function () {
    $s = makeClinicScenario();
    $patient = Patient::factory()->create(['workspace_id' => $s['clinic']->id]);
    seedAttention($s['clinic'], $patient, $s['memDoctor']->id, '50.00', now()->toDateString());

    $other = Workspace::factory()->create();
    $otherPatient = Patient::factory()->create(['workspace_id' => $other->id]);
    seedAttention($other, $otherPatient, null, '777.00', now()->toDateString());

    Sanctum::actingAs($s['ownerUser']);

    $data = $this->getJson('/api/v1/reports/settlement', settlementHeaders($s['clinic']->id))
        ->assertOk()->json('data');

    expect($data['totals']['collected'])->toBe('50.00')
        ->and(collect($data['detail'])->pluck('patient_name')->all())->not->toContain($otherPatient->name);
});

it('filters the settlement by doctor', function () {
    $s = makeClinicScenario();
    $clinic = $s['clinic'];
    $headers = settlementHeaders($clinic->id);
    $patient = Patient::factory()->create(['workspace_id' => $clinic->id]);

    seedAttention($clinic, $patient, $s['memDoctor']->id, '100.00', now()->toDateString());
    seedAttention($clinic, $patient, $s['ownerDoctor']->id, '40.00', now()->toDateString());

    Sanctum::actingAs($s['ownerUser']);

    $data = $this->getJson('/api/v1/reports/settlement?doctor_id='.$s['memDoctor']->id, $headers)
        ->assertOk()->json('data');

    expect($data['totals']['collected'])->toBe('100.00')
        ->and(collect($data['detail'])->pluck('doctor_id')->unique()->all())->toBe([$s['memDoctor']->id]);
});

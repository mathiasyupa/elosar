<?php

use App\Models\Invoice;
use App\Models\MedicalService;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Workspace;
use Laravel\Sanctum\Sanctum;

function commissionHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

it('lets the owner set a doctor commission and splits collected revenue in the settlement', function () {
    $scenario = makeClinicScenario();
    $clinic = $scenario['clinic'];
    $member = $clinic->members()->where('doctor_id', $scenario['memDoctor']->id)->firstOrFail();
    $headers = commissionHeaders($clinic->id);

    // Owner sets a 60% revenue-share for the member doctor.
    Sanctum::actingAs($scenario['ownerUser']);
    $this->putJson("/api/v1/clinic/team/{$member->id}", ['commission_pct' => 60], $headers)
        ->assertOk()
        ->assertJsonPath('data.commission_pct', '60.00');

    // $100 collected, attributed to that doctor → 60 doctor / 40 clinic.
    $patient = Patient::factory()->create(['workspace_id' => $clinic->id]);
    $invoice = Invoice::factory()->create([
        'workspace_id' => $clinic->id,
        'patient_id' => $patient->id,
        'attending_doctor_id' => $scenario['memDoctor']->id,
        'total' => '100.00',
    ]);
    Payment::factory()->create([
        'workspace_id' => $clinic->id,
        'invoice_id' => $invoice->id,
        'amount' => '100.00',
        'payment_date' => now()->toDateString(),
    ]);

    // $50 collected for the owner doctor, who has NO commission set.
    $invoice2 = Invoice::factory()->create([
        'workspace_id' => $clinic->id,
        'patient_id' => $patient->id,
        'attending_doctor_id' => $scenario['ownerDoctor']->id,
        'total' => '50.00',
    ]);
    Payment::factory()->create([
        'workspace_id' => $clinic->id,
        'invoice_id' => $invoice2->id,
        'amount' => '50.00',
        'payment_date' => now()->toDateString(),
    ]);

    $byDoctor = collect(
        $this->getJson('/api/v1/reports/revenue', $headers)->assertOk()->json('data.by_doctor')
    );

    $mem = $byDoctor->firstWhere('doctor_id', $scenario['memDoctor']->id);
    expect($mem['commission_pct'])->toBe('60.00')
        ->and($mem['doctor_amount'])->toBe('60.00')
        ->and($mem['clinic_amount'])->toBe('40.00');

    // A doctor without a commission has no split (null), not zero.
    $owner = $byDoctor->firstWhere('doctor_id', $scenario['ownerDoctor']->id);
    expect($owner['commission_pct'])->toBeNull()
        ->and($owner['doctor_amount'])->toBeNull()
        ->and($owner['clinic_amount'])->toBeNull();
});

it('rejects an out-of-range commission and forbids non-owners from setting it', function () {
    $scenario = makeClinicScenario();
    $clinic = $scenario['clinic'];
    $member = $clinic->members()->where('doctor_id', $scenario['memDoctor']->id)->firstOrFail();
    $headers = commissionHeaders($clinic->id);

    // Over 100% is invalid.
    Sanctum::actingAs($scenario['ownerUser']);
    $this->putJson("/api/v1/clinic/team/{$member->id}", ['commission_pct' => 150], $headers)
        ->assertStatus(422);

    // Only the owner (team.manage) may set commissions.
    Sanctum::actingAs($scenario['memUser']);
    $this->putJson("/api/v1/clinic/team/{$member->id}", ['commission_pct' => 30], $headers)
        ->assertForbidden();
});

it('applies per-service commission rules (percentage + fixed) with a global fallback', function () {
    $scenario = makeClinicScenario();
    $clinic = $scenario['clinic'];
    $member = $clinic->members()->where('doctor_id', $scenario['memDoctor']->id)->firstOrFail();
    $headers = commissionHeaders($clinic->id);

    $consulta = MedicalService::factory()->create(['workspace_id' => $clinic->id]);
    $cirugia = MedicalService::factory()->create(['workspace_id' => $clinic->id]);
    $ecografia = MedicalService::factory()->create(['workspace_id' => $clinic->id]);

    // Owner: global 50% + overrides (consulta 60%, cirugía $200 fijo por unidad).
    Sanctum::actingAs($scenario['ownerUser']);
    $this->putJson("/api/v1/clinic/team/{$member->id}", ['commission_pct' => 50], $headers)->assertOk();
    $this->putJson("/api/v1/clinic/team/{$member->id}/commission-rules", [
        'rules' => [
            ['medical_service_id' => $consulta->id, 'rule_type' => 'percentage', 'value' => 60],
            ['medical_service_id' => $cirugia->id, 'rule_type' => 'fixed', 'value' => 200],
        ],
    ], $headers)->assertOk()->assertJsonCount(2, 'data');

    // Fully-paid $640: consulta 100 (60%), cirugía 500 ($200 fijo), ecografía 40 (global 50%).
    $patient = Patient::factory()->create(['workspace_id' => $clinic->id]);
    $invoice = Invoice::factory()->create([
        'workspace_id' => $clinic->id,
        'patient_id' => $patient->id,
        'attending_doctor_id' => $scenario['memDoctor']->id,
        'total' => '640.00',
    ]);
    $invoice->items()->createMany([
        ['medical_service_id' => $consulta->id, 'description' => 'Consulta', 'quantity' => '1', 'unit_price' => '100.00', 'line_total' => '100.00'],
        ['medical_service_id' => $cirugia->id, 'description' => 'Cirugía', 'quantity' => '1', 'unit_price' => '500.00', 'line_total' => '500.00'],
        ['medical_service_id' => $ecografia->id, 'description' => 'Ecografía', 'quantity' => '1', 'unit_price' => '40.00', 'line_total' => '40.00'],
    ]);
    Payment::factory()->create([
        'workspace_id' => $clinic->id,
        'invoice_id' => $invoice->id,
        'amount' => '640.00',
        'payment_date' => now()->toDateString(),
    ]);

    $row = collect(
        $this->getJson('/api/v1/reports/revenue', $headers)->assertOk()->json('data.by_doctor')
    )->firstWhere('doctor_id', $scenario['memDoctor']->id);

    // 60 + 200 + 20 = 280 al médico / 360 a la clínica.
    expect($row['total'])->toBe('640.00')
        ->and($row['doctor_amount'])->toBe('280.00')
        ->and($row['clinic_amount'])->toBe('360.00');
});

it('prefers the per-invoice commission % over the doctor global % in the settlement', function () {
    $scenario = makeClinicScenario();
    $clinic = $scenario['clinic'];
    $member = $clinic->members()->where('doctor_id', $scenario['memDoctor']->id)->firstOrFail();
    $headers = commissionHeaders($clinic->id);

    // Doctor global share is 60%.
    Sanctum::actingAs($scenario['ownerUser']);
    $this->putJson("/api/v1/clinic/team/{$member->id}", ['commission_pct' => 60], $headers)->assertOk();

    $patient = Patient::factory()->create(['workspace_id' => $clinic->id]);

    // Invoice A ($100) carries a per-attention 10% set by the cashier → 10 to the doctor.
    $invoiceA = Invoice::factory()->create([
        'workspace_id' => $clinic->id,
        'patient_id' => $patient->id,
        'attending_doctor_id' => $scenario['memDoctor']->id,
        'attending_doctor_commission_pct' => '10.00',
        'total' => '100.00',
    ]);
    Payment::factory()->create(['workspace_id' => $clinic->id, 'invoice_id' => $invoiceA->id, 'amount' => '100.00', 'payment_date' => now()->toDateString()]);

    // Invoice B ($100) has no per-invoice % → falls back to the global 60% → 60.
    $invoiceB = Invoice::factory()->create([
        'workspace_id' => $clinic->id,
        'patient_id' => $patient->id,
        'attending_doctor_id' => $scenario['memDoctor']->id,
        'total' => '100.00',
    ]);
    Payment::factory()->create(['workspace_id' => $clinic->id, 'invoice_id' => $invoiceB->id, 'amount' => '100.00', 'payment_date' => now()->toDateString()]);

    $row = collect(
        $this->getJson('/api/v1/reports/revenue', $headers)->assertOk()->json('data.by_doctor')
    )->firstWhere('doctor_id', $scenario['memDoctor']->id);

    // 10 (A) + 60 (B) = 70 to the doctor / 130 to the clinic on $200 collected.
    expect($row['total'])->toBe('200.00')
        ->and($row['doctor_amount'])->toBe('70.00')
        ->and($row['clinic_amount'])->toBe('130.00');
});

it('lets the cashier list workspace doctors with their default commission %', function () {
    $scenario = makeClinicScenario();
    $clinic = $scenario['clinic'];
    $member = $clinic->members()->where('doctor_id', $scenario['memDoctor']->id)->firstOrFail();
    $headers = commissionHeaders($clinic->id);

    Sanctum::actingAs($scenario['ownerUser']);
    $this->putJson("/api/v1/clinic/team/{$member->id}", ['commission_pct' => 25], $headers)->assertOk();

    $doctors = collect(
        $this->getJson('/api/v1/billing/doctors', $headers)->assertOk()->json('data')
    );

    $mem = $doctors->firstWhere('id', $scenario['memDoctor']->id);
    expect($mem)->not->toBeNull()
        ->and($mem['commission_pct'])->toBe('25.00')
        ->and($mem['name'])->toBe($scenario['memDoctor']->name);
});

it('validates per-service rules and restricts them to the owner', function () {
    $scenario = makeClinicScenario();
    $clinic = $scenario['clinic'];
    $member = $clinic->members()->where('doctor_id', $scenario['memDoctor']->id)->firstOrFail();
    $headers = commissionHeaders($clinic->id);
    $service = MedicalService::factory()->create(['workspace_id' => $clinic->id]);

    Sanctum::actingAs($scenario['ownerUser']);

    // Percentage over 100 → 422.
    $this->putJson("/api/v1/clinic/team/{$member->id}/commission-rules", [
        'rules' => [['medical_service_id' => $service->id, 'rule_type' => 'percentage', 'value' => 120]],
    ], $headers)->assertStatus(422);

    // A service from another workspace → 422 (isolation).
    $otherWs = Workspace::factory()->create();
    $foreign = MedicalService::factory()->create(['workspace_id' => $otherWs->id]);
    $this->putJson("/api/v1/clinic/team/{$member->id}/commission-rules", [
        'rules' => [['medical_service_id' => $foreign->id, 'rule_type' => 'fixed', 'value' => 50]],
    ], $headers)->assertStatus(422);

    // A valid rule persists and is returned.
    $this->putJson("/api/v1/clinic/team/{$member->id}/commission-rules", [
        'rules' => [['medical_service_id' => $service->id, 'rule_type' => 'fixed', 'value' => 30]],
    ], $headers)->assertOk()->assertJsonCount(1, 'data');

    // Non-owner cannot read or manage the rules → 403.
    Sanctum::actingAs($scenario['memUser']);
    $this->getJson("/api/v1/clinic/team/{$member->id}/commission-rules", $headers)->assertForbidden();
    $this->putJson("/api/v1/clinic/team/{$member->id}/commission-rules", [
        'rules' => [['medical_service_id' => $service->id, 'rule_type' => 'fixed', 'value' => 10]],
    ], $headers)->assertForbidden();
});

// ─────────────────────────────────────────────
// Per-invoice FIXED amount ("cobramos 25, al médico 19, la clínica 6")
// ─────────────────────────────────────────────

it('splits a fully collected invoice by a fixed doctor amount', function () {
    $s = makeClinicScenario();
    $clinic = $s['clinic'];
    $headers = commissionHeaders($clinic->id);

    $patient = Patient::factory()->create(['workspace_id' => $clinic->id]);
    $invoice = Invoice::factory()->create([
        'workspace_id' => $clinic->id,
        'patient_id' => $patient->id,
        'attending_doctor_id' => $s['memDoctor']->id,
        'total' => '25.00',
        'attending_doctor_commission_amount' => '19.00',
    ]);
    Payment::factory()->create([
        'workspace_id' => $clinic->id,
        'invoice_id' => $invoice->id,
        'amount' => '25.00',
        'payment_date' => now()->toDateString(),
    ]);

    Sanctum::actingAs($s['ownerUser']);
    $row = collect($this->getJson('/api/v1/reports/revenue', $headers)->assertOk()->json('data.by_doctor'))
        ->firstWhere('doctor_id', $s['memDoctor']->id);

    expect($row['total'])->toBe('25.00')
        ->and($row['doctor_amount'])->toBe('19.00')
        ->and($row['clinic_amount'])->toBe('6.00');
});

it('pays the doctor first when the invoice is only partially collected', function () {
    $s = makeClinicScenario();
    $clinic = $s['clinic'];
    $headers = commissionHeaders($clinic->id);

    $patient = Patient::factory()->create(['workspace_id' => $clinic->id]);
    $invoice = Invoice::factory()->create([
        'workspace_id' => $clinic->id,
        'patient_id' => $patient->id,
        'attending_doctor_id' => $s['memDoctor']->id,
        'total' => '25.00',
        'attending_doctor_commission_amount' => '19.00',
    ]);
    // Only half collected: the doctor takes it all (up to the agreed 19).
    Payment::factory()->create([
        'workspace_id' => $clinic->id,
        'invoice_id' => $invoice->id,
        'amount' => '12.50',
        'payment_date' => now()->toDateString(),
    ]);

    Sanctum::actingAs($s['ownerUser']);
    $row = collect($this->getJson('/api/v1/reports/revenue', $headers)->assertOk()->json('data.by_doctor'))
        ->firstWhere('doctor_id', $s['memDoctor']->id);

    expect($row['total'])->toBe('12.50')
        ->and($row['doctor_amount'])->toBe('12.50')
        ->and($row['clinic_amount'])->toBe('0.00');
});

it('lets the cashier bill with a fixed doctor amount and rejects sending both', function () {
    $s = makeClinicScenario();
    $clinic = $s['clinic'];
    $headers = commissionHeaders($clinic->id);
    $patient = Patient::factory()->create(['workspace_id' => $clinic->id]);

    Sanctum::actingAs($s['ownerUser']);

    $payload = [
        'patient_id' => $patient->id,
        'issued_at' => now()->toDateString(),
        'attending_doctor_id' => $s['memDoctor']->id,
        'items' => [['description' => 'Consulta', 'quantity' => 1, 'unit_price' => 25]],
    ];

    $this->postJson('/api/v1/invoices', $payload + ['attending_doctor_commission_amount' => 19], $headers)
        ->assertStatus(201)
        ->assertJsonPath('data.attending_doctor_commission_amount', '19.00');

    // Percentage and fixed amount are mutually exclusive.
    $this->postJson(
        '/api/v1/invoices',
        $payload + ['attending_doctor_commission_pct' => 60, 'attending_doctor_commission_amount' => 19],
        $headers,
    )->assertStatus(422);
});

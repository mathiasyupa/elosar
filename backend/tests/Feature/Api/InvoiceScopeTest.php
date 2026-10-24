<?php

use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Payment;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| Invoice list scoping + "Tu parte"
|--------------------------------------------------------------------------
| An employed doctor sees only invoices for their OWN attentions, and each one
| carries what they actually earn (computed on money collected, same math as
| the settlement report). Owner / admin / cashier see the whole clinic.
*/

function invoiceScopeHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

/** One paid invoice for each doctor; the member's carries a 25% commission. */
function seedTwoDoctorInvoices(array $s): array
{
    $patient = Patient::factory()->create(['workspace_id' => $s['clinic']->id]);

    $mine = Invoice::factory()->create([
        'workspace_id' => $s['clinic']->id,
        'patient_id' => $patient->id,
        'attending_doctor_id' => $s['memDoctor']->id,
        'total' => '30.00',
        'attending_doctor_commission_pct' => '25.00',
    ]);
    Payment::factory()->create([
        'workspace_id' => $s['clinic']->id,
        'invoice_id' => $mine->id,
        'amount' => '30.00',
        'payment_date' => now()->toDateString(),
    ]);

    $others = Invoice::factory()->create([
        'workspace_id' => $s['clinic']->id,
        'patient_id' => $patient->id,
        'attending_doctor_id' => $s['ownerDoctor']->id,
        'total' => '30.00',
    ]);
    Payment::factory()->create([
        'workspace_id' => $s['clinic']->id,
        'invoice_id' => $others->id,
        'amount' => '30.00',
        'payment_date' => now()->toDateString(),
    ]);

    return [$mine, $others];
}

it('lists only the employed doctor own invoices', function () {
    $s = makeClinicScenario();
    [$mine, $others] = seedTwoDoctorInvoices($s);

    Sanctum::actingAs($s['memUser']);
    $ids = collect(
        $this->getJson('/api/v1/invoices', invoiceScopeHeaders($s['clinic']->id))->assertOk()->json('data')
    )->pluck('id');

    expect($ids)->toContain($mine->id)
        ->not->toContain($others->id);
});

it('returns each invoice with what the doctor actually earns (on collected money)', function () {
    $s = makeClinicScenario();
    [$mine] = seedTwoDoctorInvoices($s);

    Sanctum::actingAs($s['memUser']);
    $row = collect(
        $this->getJson('/api/v1/invoices', invoiceScopeHeaders($s['clinic']->id))->assertOk()->json('data')
    )->firstWhere('id', $mine->id);

    // $30 collected at 25% → the doctor earns $7.50 (the total stays $30).
    expect($row['total'])->toBe('30.00')
        ->and($row['doctor_share'])->toBe('7.50');
});

it('gives zero share while the invoice is not collected', function () {
    $s = makeClinicScenario();
    $patient = Patient::factory()->create(['workspace_id' => $s['clinic']->id]);
    $unpaid = Invoice::factory()->create([
        'workspace_id' => $s['clinic']->id,
        'patient_id' => $patient->id,
        'attending_doctor_id' => $s['memDoctor']->id,
        'total' => '30.00',
        'attending_doctor_commission_pct' => '25.00',
    ]);

    Sanctum::actingAs($s['memUser']);
    $row = collect(
        $this->getJson('/api/v1/invoices', invoiceScopeHeaders($s['clinic']->id))->assertOk()->json('data')
    )->firstWhere('id', $unpaid->id);

    expect($row['doctor_share'])->toBe('0.00');
});

it('404s when an employed doctor opens a colleague invoice', function () {
    $s = makeClinicScenario();
    [, $others] = seedTwoDoctorInvoices($s);

    Sanctum::actingAs($s['memUser']);
    $this->getJson("/api/v1/invoices/{$others->id}", invoiceScopeHeaders($s['clinic']->id))
        ->assertNotFound();
});

it('lets the owner see every invoice without a doctor_share', function () {
    $s = makeClinicScenario();
    [$mine, $others] = seedTwoDoctorInvoices($s);

    Sanctum::actingAs($s['ownerUser']);
    $rows = collect(
        $this->getJson('/api/v1/invoices', invoiceScopeHeaders($s['clinic']->id))->assertOk()->json('data')
    );

    expect($rows->pluck('id'))->toContain($mine->id)->toContain($others->id)
        ->and($rows->firstWhere('id', $mine->id)['doctor_share'])->toBeNull();
});

it('lets the cashier see every invoice', function () {
    $s = makeClinicScenario();
    [$mine, $others] = seedTwoDoctorInvoices($s);
    $cashier = makeStaff('Caja Demo', 'caja_'.uniqid().'@clinica.test');
    app(App\Services\ClinicService::class)->attachMember($s['clinic'], $cashier, 'billing');

    Sanctum::actingAs($cashier);
    $ids = collect(
        $this->getJson('/api/v1/invoices', invoiceScopeHeaders($s['clinic']->id))->assertOk()->json('data')
    )->pluck('id');

    expect($ids)->toContain($mine->id)->toContain($others->id);
});

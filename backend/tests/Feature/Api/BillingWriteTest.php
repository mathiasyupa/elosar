<?php

use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Workspace;
use Laravel\Sanctum\Sanctum;

function billingHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

/** A one-item invoice payload. 100 + 15% IVA = 115.00 total. */
function invoicePayload(int $workspaceId, int $patientId, array $overrides = []): array
{
    return array_merge([
        'workspace_id' => $workspaceId, // overridden server-side, sent to satisfy the request rule
        'patient_id'   => $patientId,
        'issued_at'    => now()->toDateString(),
        'items'        => [
            ['description' => 'Consulta médica general', 'quantity' => 1, 'unit_price' => 100, 'iva_rate' => 15],
        ],
    ], $overrides);
}

// ─────────────────────────────────────────────
// Create
// ─────────────────────────────────────────────

it('creates an invoice with correct totals and money as strings', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    Sanctum::actingAs($user);

    $data = $this->postJson('/api/v1/invoices', invoicePayload($ws->id, $patient->id), billingHeaders($ws->id))
        ->assertStatus(201)
        ->json('data');

    expect($data['subtotal'])->toBeString()->toBe('100.00')
        ->and($data['iva_amount'])->toBeString()->toBe('15.00')
        ->and($data['total'])->toBeString()->toBe('115.00')
        ->and($data['amount_due'])->toBeString()->toBe('115.00')
        ->and($data['status'])->toBe('issued');

    $this->assertDatabaseHas('invoices', [
        'id'           => $data['id'],
        'workspace_id' => $ws->id,
        'created_by'   => $doctor->id, // injected server-side
        'total'        => '115.00',
    ]);
});

// ─────────────────────────────────────────────
// Payments
// ─────────────────────────────────────────────

it('flips an invoice to partial then paid as payments are registered', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    Sanctum::actingAs($user);

    $invoiceId = $this->postJson('/api/v1/invoices', invoicePayload($ws->id, $patient->id), billingHeaders($ws->id))
        ->json('data.id');

    // Partial payment.
    $this->postJson("/api/v1/invoices/{$invoiceId}/payments", [
        'amount'         => 50,
        'payment_method' => 'cash',
        'payment_date'   => now()->toDateString(),
    ], billingHeaders($ws->id))->assertStatus(201);

    expect(Invoice::find($invoiceId)->status)->toBe('partial');

    // Settle the remaining balance.
    $this->postJson("/api/v1/invoices/{$invoiceId}/payments", [
        'amount'         => 65,
        'payment_method' => 'transfer',
        'payment_date'   => now()->toDateString(),
    ], billingHeaders($ws->id))->assertStatus(201);

    $invoice = Invoice::find($invoiceId);
    expect($invoice->status)->toBe('paid')
        ->and((string) $invoice->amount_paid)->toBe('115.00');
});

it('rejects a payment that exceeds the outstanding balance', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    Sanctum::actingAs($user);

    $invoiceId = $this->postJson('/api/v1/invoices', invoicePayload($ws->id, $patient->id), billingHeaders($ws->id))
        ->json('data.id');

    $this->postJson("/api/v1/invoices/{$invoiceId}/payments", [
        'amount'         => 999,
        'payment_method' => 'cash',
        'payment_date'   => now()->toDateString(),
    ], billingHeaders($ws->id))
        ->assertStatus(422)
        ->assertJsonValidationErrors('amount');
});

it('deleting a payment recalculates the invoice back to issued', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    Sanctum::actingAs($user);

    $invoiceId = $this->postJson('/api/v1/invoices', invoicePayload($ws->id, $patient->id), billingHeaders($ws->id))
        ->json('data.id');

    $paymentId = $this->postJson("/api/v1/invoices/{$invoiceId}/payments", [
        'amount'         => 115,
        'payment_method' => 'cash',
        'payment_date'   => now()->toDateString(),
    ], billingHeaders($ws->id))->json('data.id');

    expect(Invoice::find($invoiceId)->status)->toBe('paid');

    $this->deleteJson("/api/v1/payments/{$paymentId}", [], billingHeaders($ws->id))
        ->assertNoContent();

    $invoice = Invoice::find($invoiceId);
    expect($invoice->status)->toBe('issued')
        ->and((string) $invoice->amount_paid)->toBe('0.00');
});

// ─────────────────────────────────────────────
// Voiding generates a credit note
// ─────────────────────────────────────────────

it('voiding a paid invoice generates a credit note for the collected amount', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    Sanctum::actingAs($user);

    $invoiceId = $this->postJson('/api/v1/invoices', invoicePayload($ws->id, $patient->id), billingHeaders($ws->id))
        ->json('data.id');

    $this->postJson("/api/v1/invoices/{$invoiceId}/payments", [
        'amount'         => 115,
        'payment_method' => 'cash',
        'payment_date'   => now()->toDateString(),
    ], billingHeaders($ws->id))->assertStatus(201);

    $this->postJson("/api/v1/invoices/{$invoiceId}/void", [
        'reason' => 'Error de facturación',
    ], billingHeaders($ws->id))
        ->assertOk()
        ->assertJsonPath('data.status', 'void');

    $this->assertDatabaseHas('credit_notes', [
        'workspace_id' => $ws->id,
        'invoice_id'   => $invoiceId,
        'amount'       => '115.00',
    ]);

    // The credit note is exposed through the scoped collection endpoint.
    $this->getJson('/api/v1/credit-notes', billingHeaders($ws->id))
        ->assertOk()
        ->assertJsonPath('data.0.invoice_id', $invoiceId);
});

// ─────────────────────────────────────────────
// Isolation
// ─────────────────────────────────────────────

it('returns 404 for a cross-workspace invoice id', function () {
    [, $user, $ws]  = makeDoctorWithWorkspace();
    $foreignWs       = Workspace::factory()->create();
    $foreignPatient  = Patient::factory()->create(['workspace_id' => $foreignWs->id]);
    $foreignInvoice  = Invoice::factory()->create([
        'workspace_id' => $foreignWs->id,
        'patient_id'   => $foreignPatient->id,
    ]);

    Sanctum::actingAs($user);

    $this->getJson("/api/v1/invoices/{$foreignInvoice->id}", billingHeaders($ws->id))
        ->assertNotFound();

    // A payment against it must not be reachable either.
    $this->postJson("/api/v1/invoices/{$foreignInvoice->id}/payments", [
        'amount'         => 10,
        'payment_method' => 'cash',
        'payment_date'   => now()->toDateString(),
    ], billingHeaders($ws->id))
        ->assertNotFound();
});

it('returns 404 when deleting a payment of another workspace', function () {
    [, $user, $ws]  = makeDoctorWithWorkspace();
    $foreignWs       = Workspace::factory()->create();
    $foreignPatient  = Patient::factory()->create(['workspace_id' => $foreignWs->id]);
    $foreignInvoice  = Invoice::factory()->create([
        'workspace_id' => $foreignWs->id,
        'patient_id'   => $foreignPatient->id,
    ]);
    $foreignPayment  = Payment::factory()->create([
        'workspace_id' => $foreignWs->id,
        'invoice_id'   => $foreignInvoice->id,
    ]);

    Sanctum::actingAs($user);

    $this->deleteJson("/api/v1/payments/{$foreignPayment->id}", [], billingHeaders($ws->id))
        ->assertNotFound();

    $this->assertDatabaseHas('payments', ['id' => $foreignPayment->id]);
});

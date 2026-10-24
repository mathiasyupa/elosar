<?php

use App\Data\InvoiceData;
use App\Data\InvoiceItemData;
use App\Data\PaymentData;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Workspace;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Support\ActiveWorkspace;

// ── DTO contract ─────────────────────────────────────────────────────────────

it('builds an InvoiceData from an array and round-trips it', function () {
    $dto = InvoiceData::fromArray([
        'workspace_id'    => 1,
        'patient_id'      => 2,
        'issued_at'       => '2026-06-15',
        'discount_amount' => '5.00',
        'items'           => [
            ['description' => 'Consulta', 'quantity' => '1', 'unit_price' => '100.00', 'iva_rate' => '15.00'],
        ],
    ]);

    expect($dto->workspaceId)->toBe(1)
        ->and($dto->items)->toHaveCount(1)
        ->and($dto->items[0])->toBeInstanceOf(InvoiceItemData::class);

    $array = $dto->toArray();
    expect($array['workspace_id'])->toBe(1)
        ->and($array['items'][0]['unit_price'])->toBe('100.00');
});

it('InvoiceService::create accepts a typed InvoiceData', function () {
    $ws      = Workspace::factory()->create(['invoice_counter' => 0]);
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    app(ActiveWorkspace::class)->bind($ws->id);

    $invoice = app(InvoiceService::class)->create(new InvoiceData(
        workspaceId: $ws->id,
        patientId: $patient->id,
        issuedAt: '2026-06-15',
        items: [new InvoiceItemData(description: 'Consulta', quantity: '2', unitPrice: '100.00', ivaRate: '15.00')],
    ));

    expect($invoice->subtotal)->toBe('200.00')
        ->and($invoice->iva_amount)->toBe('30.00')
        ->and($invoice->total)->toBe('230.00')
        ->and($invoice->items)->toHaveCount(1);
});

it('PaymentService::register accepts a typed PaymentData', function () {
    $ws      = Workspace::factory()->create(['invoice_counter' => 0]);
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    app(ActiveWorkspace::class)->bind($ws->id);

    $invoice = app(InvoiceService::class)->create(new InvoiceData(
        workspaceId: $ws->id,
        patientId: $patient->id,
        issuedAt: '2026-06-15',
        items: [new InvoiceItemData(description: 'C', quantity: '1', unitPrice: '100.00', ivaRate: '0.00')],
    ));

    $payment = app(PaymentService::class)->register($invoice, new PaymentData(
        amount: '40.00',
        paymentMethod: 'cash',
        paymentDate: '2026-06-15',
    ));

    expect($payment->amount)->toBe('40.00')
        ->and($invoice->fresh()->status)->toBe('partial');
});

// ── Serialization hardening ──────────────────────────────────────────────────

it('never serializes the workspace signing credential', function () {
    $ws = Workspace::factory()->create([
        'sri_certificate_path'     => 'sri-certificates/cert.p12',
        'sri_certificate_password' => 'super-secret',
    ]);

    $array = $ws->fresh()->toArray();

    expect($array)->not->toHaveKey('sri_certificate_password')
        ->and($array)->not->toHaveKey('sri_certificate_path')
        ->and($ws->fresh()->toJson())->not->toContain('super-secret');
});

it('never serializes internal SRI artifacts on invoices', function () {
    $ws      = Workspace::factory()->create();
    $patient = Patient::withoutGlobalScopes()->create(minimalPatientData(['workspace_id' => $ws->id]));
    app(ActiveWorkspace::class)->bind($ws->id);

    $invoice = Invoice::create([
        'workspace_id'   => $ws->id,
        'patient_id'     => $patient->id,
        'invoice_number' => 'F-0001',
        'issued_at'      => '2026-06-15',
        'status'         => 'issued',
        'subtotal'       => '100.00',
        'iva_amount'     => '15.00',
        'total'          => '115.00',
        'amount_paid'    => '0.00',
        'sri_xml'        => '<factura>secret-signed-xml</factura>',
        'sri_last_error' => 'internal error detail',
    ]);

    $array = $invoice->fresh()->toArray();

    expect($array)->not->toHaveKey('sri_xml')
        ->and($array)->not->toHaveKey('sri_last_error');
});

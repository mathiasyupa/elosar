<?php

use App\Models\Patient;
use App\Models\Payment;
use App\Models\Workspace;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Support\ActiveWorkspace;
use Illuminate\Validation\ValidationException;

function paymentService(): PaymentService
{
    return app(PaymentService::class);
}

function createIssuedInvoice(Workspace $workspace, Patient $patient, array $itemOverrides = []): \App\Models\Invoice
{
    // Bind the workspace so service-internal scoped reads resolve (fail-closed).
    app(ActiveWorkspace::class)->bind($workspace->id);

    return app(InvoiceService::class)->create([
        'workspace_id' => $workspace->id,
        'patient_id'   => $patient->id,
        'issued_at'    => '2026-06-07',
        'items'        => [
            array_merge([
                'description' => 'Consulta',
                'quantity'    => '1',
                'unit_price'  => '100.00',
                'iva_rate'    => '0.00',
            ], $itemOverrides),
        ],
    ]);
}

function validPaymentData(array $overrides = []): array
{
    return array_merge([
        'amount'         => '50.00',
        'payment_method' => 'cash',
        'payment_date'   => '2026-06-07',
    ], $overrides);
}

// ─────────────────────────────────────────────
// register
// ─────────────────────────────────────────────

it('registra pago parcial y actualiza status de la factura', function () {
    $workspace = Workspace::factory()->create(['invoice_counter' => 0]);
    $patient   = Patient::factory()->create(['workspace_id' => $workspace->id]);
    $invoice   = createIssuedInvoice($workspace, $patient);

    $payment = paymentService()->register($invoice, validPaymentData(['amount' => '40.00']));

    expect($payment)->toBeInstanceOf(Payment::class)
        ->and($payment->amount)->toBe('40.00')
        ->and($invoice->fresh()->status)->toBe('partial')
        ->and($invoice->fresh()->amount_paid)->toBe('40.00');
});

it('registra pago total y marca la factura como paid', function () {
    $workspace = Workspace::factory()->create(['invoice_counter' => 0]);
    $patient   = Patient::factory()->create(['workspace_id' => $workspace->id]);
    $invoice   = createIssuedInvoice($workspace, $patient);

    paymentService()->register($invoice, validPaymentData(['amount' => '100.00']));

    expect($invoice->fresh()->status)->toBe('paid')
        ->and($invoice->fresh()->amount_paid)->toBe('100.00');
});

it('rechaza pago con monto cero o negativo', function () {
    $workspace = Workspace::factory()->create(['invoice_counter' => 0]);
    $patient   = Patient::factory()->create(['workspace_id' => $workspace->id]);
    $invoice   = createIssuedInvoice($workspace, $patient);

    paymentService()->register($invoice, validPaymentData(['amount' => '0.00']));
})->throws(ValidationException::class);

it('rechaza pago que supera el saldo pendiente', function () {
    $workspace = Workspace::factory()->create(['invoice_counter' => 0]);
    $patient   = Patient::factory()->create(['workspace_id' => $workspace->id]);
    $invoice   = createIssuedInvoice($workspace, $patient);

    paymentService()->register($invoice, validPaymentData(['amount' => '100.01']));
})->throws(ValidationException::class);

it('rechaza pago en factura cancelada', function () {
    $workspace = Workspace::factory()->create(['invoice_counter' => 0]);
    $patient   = Patient::factory()->create(['workspace_id' => $workspace->id]);
    $invoice   = createIssuedInvoice($workspace, $patient);

    app(InvoiceService::class)->cancel($invoice, 'Cancelada');

    paymentService()->register($invoice->fresh(), validPaymentData());
})->throws(ValidationException::class);

it('rechaza pago en factura anulada', function () {
    $workspace = Workspace::factory()->create(['invoice_counter' => 0]);
    $patient   = Patient::factory()->create(['workspace_id' => $workspace->id]);
    $invoice   = createIssuedInvoice($workspace, $patient);

    app(InvoiceService::class)->void($invoice, 'Anulada');

    paymentService()->register($invoice->fresh(), validPaymentData());
})->throws(ValidationException::class);

it('permite pagos sucesivos hasta cubrir el total', function () {
    $workspace = Workspace::factory()->create(['invoice_counter' => 0]);
    $patient   = Patient::factory()->create(['workspace_id' => $workspace->id]);
    $invoice   = createIssuedInvoice($workspace, $patient);

    paymentService()->register($invoice, validPaymentData(['amount' => '60.00']));
    paymentService()->register($invoice->fresh(), validPaymentData(['amount' => '40.00']));

    expect($invoice->fresh()->status)->toBe('paid')
        ->and($invoice->fresh()->amount_paid)->toBe('100.00');
});

it('persiste referencia y notas opcionales del pago', function () {
    $workspace = Workspace::factory()->create(['invoice_counter' => 0]);
    $patient   = Patient::factory()->create(['workspace_id' => $workspace->id]);
    $invoice   = createIssuedInvoice($workspace, $patient);

    $payment = paymentService()->register($invoice, validPaymentData([
        'amount'         => '25.00',
        'payment_method' => 'transfer',
        'reference'      => 'TRF-12345',
        'notes'          => 'Abono inicial',
    ]));

    expect($payment->reference)->toBe('TRF-12345')
        ->and($payment->notes)->toBe('Abono inicial')
        ->and($payment->workspace_id)->toBe($workspace->id);
});

// ─────────────────────────────────────────────
// delete
// ─────────────────────────────────────────────

it('elimina pago y recalcula la factura a issued', function () {
    $workspace = Workspace::factory()->create(['invoice_counter' => 0]);
    $patient   = Patient::factory()->create(['workspace_id' => $workspace->id]);
    $invoice   = createIssuedInvoice($workspace, $patient);

    $payment = paymentService()->register($invoice, validPaymentData(['amount' => '100.00']));
    expect($invoice->fresh()->status)->toBe('paid');

    paymentService()->delete($payment);

    expect($invoice->fresh()->status)->toBe('issued')
        ->and($invoice->fresh()->amount_paid)->toBe('0.00')
        ->and(Payment::find($payment->id))->toBeNull();
});

it('elimina un pago parcial y deja la factura en partial', function () {
    $workspace = Workspace::factory()->create(['invoice_counter' => 0]);
    $patient   = Patient::factory()->create(['workspace_id' => $workspace->id]);
    $invoice   = createIssuedInvoice($workspace, $patient);

    paymentService()->register($invoice, validPaymentData(['amount' => '30.00']));
    $second = paymentService()->register($invoice->fresh(), validPaymentData(['amount' => '20.00']));

    paymentService()->delete($second);

    expect($invoice->fresh()->status)->toBe('partial')
        ->and($invoice->fresh()->amount_paid)->toBe('30.00');
});

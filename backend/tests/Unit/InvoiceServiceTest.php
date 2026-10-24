<?php

use App\Models\MedicalService;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Workspace;
use App\Services\InvoiceService;
use App\Support\ActiveWorkspace;
use Illuminate\Validation\ValidationException;

function invoiceService(): InvoiceService
{
    return app(InvoiceService::class);
}

function makeBillingContext(): array
{
    $workspace = Workspace::factory()->create(['invoice_counter' => 0]);
    $patient   = Patient::factory()->create(['workspace_id' => $workspace->id]);

    // The service always runs with a resolved workspace in production
    // (authenticated Filament); bind it so the fail-closed scope resolves.
    app(ActiveWorkspace::class)->bind($workspace->id);

    return [$workspace, $patient];
}

function sampleInvoicePayload(Workspace $workspace, Patient $patient, array $overrides = []): array
{
    return array_merge([
        'workspace_id' => $workspace->id,
        'patient_id'   => $patient->id,
        'issued_at'    => '2026-06-07',
        'items'        => [
            [
                'description' => 'Consulta general',
                'quantity'    => '1',
                'unit_price'  => '100.00',
                'iva_rate'    => '15.00',
            ],
        ],
    ], $overrides);
}

// ─────────────────────────────────────────────
// nextNumber (numbering)
// ─────────────────────────────────────────────

it('generates sequential numbers F-0001, F-0002 per workspace', function () {
    $workspace = Workspace::factory()->create(['invoice_counter' => 0]);

    expect(invoiceService()->nextNumber($workspace))->toBe('F-0001')
        ->and(invoiceService()->nextNumber($workspace->fresh()))->toBe('F-0002');

    expect($workspace->fresh()->invoice_counter)->toBe(2);
});

it('continues numbering from an existing invoice_counter', function () {
    $workspace = Workspace::factory()->create(['invoice_counter' => 41]);

    expect(invoiceService()->nextNumber($workspace))->toBe('F-0042');
});

// ─────────────────────────────────────────────
// create
// ─────────────────────────────────────────────

it('rejects creating an invoice without items', function () {
    [$workspace, $patient] = makeBillingContext();

    invoiceService()->create([
        'workspace_id' => $workspace->id,
        'patient_id'   => $patient->id,
        'issued_at'    => '2026-06-07',
        'items'        => [],
    ]);
})->throws(InvalidArgumentException::class, 'La factura debe tener al menos un ítem.');

it('creates an invoice with totals computed with bcmath', function () {
    [$workspace, $patient] = makeBillingContext();

    $invoice = invoiceService()->create(sampleInvoicePayload($workspace, $patient, [
        'items' => [
            [
                'description' => 'Consulta',
                'quantity'    => '2',
                'unit_price'  => '100.00',
                'iva_rate'    => '15.00',
            ],
            [
                'description' => 'Curación',
                'quantity'    => '1',
                'unit_price'  => '50.00',
                'iva_rate'    => '0.00',
            ],
        ],
    ]));

    expect($invoice->subtotal)->toBe('250.00')
        ->and($invoice->iva_amount)->toBe('30.00')
        ->and($invoice->total)->toBe('280.00')
        ->and($invoice->amount_paid)->toBe('0.00')
        ->and($invoice->status)->toBe('issued')
        ->and($invoice->items)->toHaveCount(2);

    $firstItem = $invoice->items->firstWhere('description', 'Consulta');
    expect($firstItem->iva_amount)->toBe('30.00')
        ->and($firstItem->line_total)->toBe('230.00');
});

it('assigns an invoice number on creation', function () {
    [$workspace, $patient] = makeBillingContext();

    $invoice = invoiceService()->create(sampleInvoicePayload($workspace, $patient));

    expect($invoice->invoice_number)->toBe('F-0001');
});

it('persists items with optional medical_service_id', function () {
    [$workspace, $patient] = makeBillingContext();
    $service = MedicalService::factory()->create(['workspace_id' => $workspace->id]);

    $invoice = invoiceService()->create(sampleInvoicePayload($workspace, $patient, [
        'items' => [
            [
                'medical_service_id' => $service->id,
                'description'        => 'Servicio catalogado',
                'quantity'           => '1',
                'unit_price'         => '75.00',
                'iva_rate'           => '15.00',
                'sort_order'         => 5,
            ],
        ],
    ]));

    $item = $invoice->items->first();
    expect($item->medical_service_id)->toBe($service->id)
        ->and($item->sort_order)->toBe(5);
});

// ─────────────────────────────────────────────
// recalculate
// ─────────────────────────────────────────────

it('recalculates issued status with no payments', function () {
    [$workspace, $patient] = makeBillingContext();
    $invoice               = invoiceService()->create(sampleInvoicePayload($workspace, $patient));

    invoiceService()->recalculate($invoice->fresh());

    expect($invoice->fresh()->status)->toBe('issued')
        ->and($invoice->fresh()->amount_paid)->toBe('0.00');
});

it('recalculates partial status with a partial payment', function () {
    [$workspace, $patient] = makeBillingContext();
    $invoice               = invoiceService()->create(sampleInvoicePayload($workspace, $patient));

    Payment::create([
        'invoice_id'     => $invoice->id,
        'workspace_id'   => $workspace->id,
        'amount'         => '50.00',
        'payment_method' => 'cash',
        'payment_date'   => '2026-06-07',
    ]);

    invoiceService()->recalculate($invoice->fresh());

    expect($invoice->fresh()->status)->toBe('partial')
        ->and($invoice->fresh()->amount_paid)->toBe('50.00');
});

it('recalculates paid status when payments cover the total', function () {
    [$workspace, $patient] = makeBillingContext();
    $invoice               = invoiceService()->create(sampleInvoicePayload($workspace, $patient));
    $total                 = (string) $invoice->total;

    Payment::create([
        'invoice_id'     => $invoice->id,
        'workspace_id'   => $workspace->id,
        'amount'         => $total,
        'payment_method' => 'transfer',
        'payment_date'   => '2026-06-07',
    ]);

    invoiceService()->recalculate($invoice->fresh());

    expect($invoice->fresh()->status)->toBe('paid')
        ->and($invoice->fresh()->amount_paid)->toBe($total);
});

// ─────────────────────────────────────────────
// cancel / void
// ─────────────────────────────────────────────

it('cancels an invoice with no registered payments', function () {
    [$workspace, $patient] = makeBillingContext();
    $invoice               = invoiceService()->create(sampleInvoicePayload($workspace, $patient));

    invoiceService()->cancel($invoice, 'Error de digitación');

    $invoice->refresh();
    expect($invoice->status)->toBe('cancelled')
        ->and($invoice->cancellation_reason)->toBe('Error de digitación')
        ->and($invoice->cancelled_at)->not->toBeNull();
});

it('does not allow cancelling an invoice with payments', function () {
    [$workspace, $patient] = makeBillingContext();
    $invoice               = invoiceService()->create(sampleInvoicePayload($workspace, $patient));

    Payment::create([
        'invoice_id'     => $invoice->id,
        'workspace_id'   => $workspace->id,
        'amount'         => '10.00',
        'payment_method' => 'cash',
        'payment_date'   => '2026-06-07',
    ]);

    invoiceService()->cancel($invoice, 'Intento inválido');
})->throws(ValidationException::class);

it('voids an invoice regardless of payments', function () {
    [$workspace, $patient] = makeBillingContext();
    $invoice               = invoiceService()->create(sampleInvoicePayload($workspace, $patient));

    Payment::create([
        'invoice_id'     => $invoice->id,
        'workspace_id'   => $workspace->id,
        'amount'         => '10.00',
        'payment_method' => 'cash',
        'payment_date'   => '2026-06-07',
    ]);

    invoiceService()->void($invoice, 'Anulación administrativa');

    $invoice->refresh();
    expect($invoice->status)->toBe('void')
        ->and($invoice->voided_at)->not->toBeNull()
        ->and($invoice->cancellation_reason)->toBe('Anulación administrativa');
});

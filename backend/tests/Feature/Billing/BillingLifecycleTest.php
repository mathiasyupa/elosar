<?php

use App\Data\InvoiceData;
use App\Data\InvoiceItemData;
use App\Data\PaymentData;
use App\Models\CreditNote;
use App\Models\Patient;
use App\Models\Workspace;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Support\ActiveWorkspace;

/**
 * End-to-end billing lifecycle: create → pay → void → auto credit note.
 * The void-with-payments path that auto-generates a credit note had no
 * end-to-end coverage before.
 */
it('runs the full create -> pay -> void -> credit note lifecycle', function () {
    $ws      = Workspace::factory()->create(['invoice_counter' => 0, 'credit_note_counter' => 0]);
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    app(ActiveWorkspace::class)->bind($ws->id);

    // 1. Create
    $invoice = app(InvoiceService::class)->create(new InvoiceData(
        workspaceId: $ws->id,
        patientId: $patient->id,
        issuedAt: '2026-06-15',
        items: [new InvoiceItemData(description: 'Consulta', quantity: '1', unitPrice: '100.00', ivaRate: '0.00')],
    ));

    expect($invoice->total)->toBe('100.00')
        ->and($invoice->status)->toBe('issued')
        ->and($invoice->invoice_number)->toBe('F-0001');

    // 2. Pay in full
    app(PaymentService::class)->register($invoice, new PaymentData(
        amount: '100.00',
        paymentMethod: 'cash',
        paymentDate: '2026-06-15',
    ));

    expect($invoice->fresh()->status)->toBe('paid')
        ->and($invoice->fresh()->amount_paid)->toBe('100.00');

    // 3. Void (with payments) → auto credit note
    app(InvoiceService::class)->void($invoice->fresh(), 'Anulación administrativa');

    $invoice->refresh();
    expect($invoice->status)->toBe('void')
        ->and($invoice->voided_at)->not->toBeNull();

    // 4. Credit note generated for the paid amount
    $creditNote = CreditNote::where('invoice_id', $invoice->id)->first();

    expect($creditNote)->not->toBeNull()
        ->and($creditNote->credit_note_number)->toBe('NC-0001')
        ->and($creditNote->amount)->toBe('100.00')
        ->and($creditNote->status)->toBe('issued');
});

it('cancels an unpaid invoice without generating a credit note', function () {
    $ws      = Workspace::factory()->create(['invoice_counter' => 0]);
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    app(ActiveWorkspace::class)->bind($ws->id);

    $invoice = app(InvoiceService::class)->create(new InvoiceData(
        workspaceId: $ws->id,
        patientId: $patient->id,
        issuedAt: '2026-06-15',
        items: [new InvoiceItemData(description: 'Consulta', quantity: '1', unitPrice: '50.00', ivaRate: '0.00')],
    ));

    app(InvoiceService::class)->cancel($invoice, 'Error de digitación');

    expect($invoice->fresh()->status)->toBe('cancelled')
        ->and(CreditNote::where('invoice_id', $invoice->id)->exists())->toBeFalse();
});

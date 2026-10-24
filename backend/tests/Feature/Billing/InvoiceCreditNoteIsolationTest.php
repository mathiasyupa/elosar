<?php

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Workspace;
use App\Support\ActiveWorkspace;

/**
 * Multi-tenant isolation for the billing domain (previously untested — the
 * domain with the highest legal/fiscal risk).
 */
function isoInvoiceIn(int $workspaceId, string $number = 'F-0001'): Invoice
{
    $patient = Patient::withoutGlobalScopes()->create(minimalPatientData(['workspace_id' => $workspaceId]));

    return Invoice::withoutGlobalScopes()->create([
        'workspace_id'   => $workspaceId,
        'patient_id'     => $patient->id,
        'invoice_number' => $number,
        'issued_at'      => now()->toDateString(),
        'status'         => 'issued',
        'subtotal'       => '100.00',
        'iva_amount'     => '0.00',
        'total'          => '100.00',
        'amount_paid'    => '0.00',
    ]);
}

function isoCreditNoteFor(Invoice $invoice, string $number = 'NC-0001'): CreditNote
{
    return CreditNote::withoutGlobalScopes()->create([
        'workspace_id'       => $invoice->workspace_id,
        'invoice_id'         => $invoice->id,
        'credit_note_number' => $number,
        'issued_at'          => now()->toDateString(),
        'reason'             => 'Anulación',
        'amount'             => '100.00',
        'status'             => 'issued',
    ]);
}

it('a workspace only sees its own invoices', function () {
    $ws1 = Workspace::factory()->create();
    $ws2 = Workspace::factory()->create();
    isoInvoiceIn($ws1->id);
    isoInvoiceIn($ws2->id);

    $visible = app(ActiveWorkspace::class)->runBound($ws1->id, fn () => Invoice::all());

    expect($visible)->toHaveCount(1)
        ->and($visible->first()->workspace_id)->toBe($ws1->id);
});

it('cannot fetch another workspace invoice by id', function () {
    $ws1   = Workspace::factory()->create();
    $ws2   = Workspace::factory()->create();
    $other = isoInvoiceIn($ws2->id);

    $found = app(ActiveWorkspace::class)->runBound($ws1->id, fn () => Invoice::find($other->id));

    expect($found)->toBeNull();
});

it('a workspace only sees its own credit notes', function () {
    $ws1 = Workspace::factory()->create();
    $ws2 = Workspace::factory()->create();
    isoCreditNoteFor(isoInvoiceIn($ws1->id));
    isoCreditNoteFor(isoInvoiceIn($ws2->id));

    $visible = app(ActiveWorkspace::class)->runBound($ws1->id, fn () => CreditNote::all());

    expect($visible)->toHaveCount(1)
        ->and($visible->first()->workspace_id)->toBe($ws1->id);
});

it('cannot fetch another workspace credit note by id', function () {
    $ws1   = Workspace::factory()->create();
    $ws2   = Workspace::factory()->create();
    $other = isoCreditNoteFor(isoInvoiceIn($ws2->id));

    $found = app(ActiveWorkspace::class)->runBound($ws1->id, fn () => CreditNote::find($other->id));

    expect($found)->toBeNull();
});

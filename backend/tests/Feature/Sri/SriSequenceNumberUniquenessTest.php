<?php

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Workspace;
use Illuminate\Database\QueryException;

/**
 * Regression: the SRI secuencial is unique PER WORKSPACE, not globally.
 *
 * Two workspaces sharing the default establishment / emission point both
 * generate "001-001-000000001" for their first electronic invoice. The old
 * global UNIQUE(sri_sequence_number) made the second workspace's INSERT fail.
 * See migration 2026_06_15_000001_fix_sri_sequence_number_unique_per_workspace.
 */
function makeInvoiceWithSequence(int $workspaceId, string $sequence, string $invoiceNumber): Invoice
{
    $patient = Patient::withoutGlobalScopes()->create(minimalPatientData([
        'workspace_id' => $workspaceId,
    ]));

    return Invoice::withoutGlobalScopes()->create([
        'workspace_id'        => $workspaceId,
        'patient_id'          => $patient->id,
        'invoice_number'      => $invoiceNumber,
        'issued_at'           => now()->toDateString(),
        'status'              => 'issued',
        'subtotal'            => '100.00',
        'iva_amount'          => '15.00',
        'total'               => '115.00',
        'amount_paid'         => '0.00',
        'sri_sequence_number' => $sequence,
    ]);
}

it('allows two workspaces to share the same SRI sequence number', function () {
    $ws1 = Workspace::factory()->create();
    $ws2 = Workspace::factory()->create();

    $sequence = '001-001-000000001';

    $a = makeInvoiceWithSequence($ws1->id, $sequence, 'F-0001');
    $b = makeInvoiceWithSequence($ws2->id, $sequence, 'F-0001');

    expect($a->sri_sequence_number)->toBe($sequence)
        ->and($b->sri_sequence_number)->toBe($sequence);

    $this->assertDatabaseCount('invoices', 2);
});

it('still rejects a duplicate SRI sequence number within the same workspace', function () {
    $ws = Workspace::factory()->create();

    makeInvoiceWithSequence($ws->id, '001-001-000000001', 'F-0001');

    expect(fn () => makeInvoiceWithSequence($ws->id, '001-001-000000001', 'F-0002'))
        ->toThrow(QueryException::class);
});

function makeCreditNoteWithSequence(int $workspaceId, string $sequence, string $creditNoteNumber): CreditNote
{
    // Deterministic, collision-free identifiers for the parent invoice so the
    // test only ever fails on the credit-note constraint under test.
    static $seq = 0;
    $seq++;

    $invoice = makeInvoiceWithSequence(
        $workspaceId,
        '001-001-' . str_pad((string) (900000000 + $seq), 9, '0', STR_PAD_LEFT),
        'F-' . str_pad((string) (9000 + $seq), 4, '0', STR_PAD_LEFT),
    );

    return CreditNote::withoutGlobalScopes()->create([
        'workspace_id'        => $workspaceId,
        'invoice_id'          => $invoice->id,
        'credit_note_number'  => $creditNoteNumber,
        'issued_at'           => now()->toDateString(),
        'reason'              => 'Anulación de la factura',
        'amount'              => '115.00',
        'status'              => 'issued',
        'sri_sequence_number' => $sequence,
    ]);
}

it('allows two workspaces to share the same SRI credit-note sequence number', function () {
    $ws1 = Workspace::factory()->create();
    $ws2 = Workspace::factory()->create();

    $sequence = '001-001-000000001';

    $a = makeCreditNoteWithSequence($ws1->id, $sequence, 'NC-0001');
    $b = makeCreditNoteWithSequence($ws2->id, $sequence, 'NC-0001');

    expect($a->sri_sequence_number)->toBe($sequence)
        ->and($b->sri_sequence_number)->toBe($sequence);

    $this->assertDatabaseCount('credit_notes', 2);
});

it('still rejects a duplicate SRI credit-note sequence number within the same workspace', function () {
    $ws = Workspace::factory()->create();

    makeCreditNoteWithSequence($ws->id, '001-001-000000001', 'NC-0001');

    expect(fn () => makeCreditNoteWithSequence($ws->id, '001-001-000000001', 'NC-0002'))
        ->toThrow(QueryException::class);
});

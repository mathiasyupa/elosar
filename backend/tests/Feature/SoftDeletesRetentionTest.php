<?php

use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Workspace;
use App\Support\ActiveWorkspace;

/**
 * Soft deletes for clinical/billing records + hard-delete protection for
 * SRI-authorized vouchers (legal 7-year retention). See migration
 * 2026_06_15_000002 and App\Models\Concerns\GuardsSriRetention.
 */
function makeInvoice(int $workspaceId, string $number, ?string $sriStatus): Invoice
{
    $patient = Patient::withoutGlobalScopes()->create(minimalPatientData([
        'workspace_id' => $workspaceId,
    ]));

    app(ActiveWorkspace::class)->bind($workspaceId);

    return Invoice::create([
        'workspace_id'   => $workspaceId,
        'patient_id'     => $patient->id,
        'invoice_number' => $number,
        'issued_at'      => now()->toDateString(),
        'status'         => 'issued',
        'subtotal'       => '100.00',
        'iva_amount'     => '15.00',
        'total'          => '115.00',
        'amount_paid'    => '0.00',
        'sri_status'     => $sriStatus,
    ]);
}

it('soft-deletes a patient and excludes it from default queries', function () {
    $ws = Workspace::factory()->create();
    app(ActiveWorkspace::class)->bind($ws->id);

    $patient = Patient::create(minimalPatientData(['workspace_id' => $ws->id]));
    $patient->delete();

    $this->assertSoftDeleted('patients', ['id' => $patient->id]);

    expect(Patient::count())->toBe(0)
        ->and(Patient::withTrashed()->count())->toBe(1);
});

it('soft-deletes an invoice instead of removing the row', function () {
    $ws      = Workspace::factory()->create();
    $invoice = makeInvoice($ws->id, 'F-0001', 'authorized');

    $invoice->delete();

    $this->assertSoftDeleted('invoices', ['id' => $invoice->id]);

    expect(Invoice::find($invoice->id))->toBeNull()
        ->and(Invoice::withTrashed()->find($invoice->id))->not->toBeNull();
});

it('blocks force-deleting an SRI-authorized invoice (7-year retention)', function () {
    $ws      = Workspace::factory()->create();
    $invoice = makeInvoice($ws->id, 'F-0001', 'authorized');

    expect(fn () => $invoice->forceDelete())
        ->toThrow(RuntimeException::class);

    expect(Invoice::withTrashed()->find($invoice->id))->not->toBeNull();
});

it('allows force-deleting a non-authorized invoice', function () {
    $ws      = Workspace::factory()->create();
    $invoice = makeInvoice($ws->id, 'F-0001', null);

    $invoice->forceDelete();

    expect(Invoice::withTrashed()->find($invoice->id))->toBeNull();
});

<?php

use App\Jobs\AuthorizeSriVoucherJob;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Workspace;
use App\Services\Sri\SriService;
use Illuminate\Support\Facades\Queue;

/**
 * Covers the rejected-voucher resend flow: the SriService::resend() reset and
 * the sri:resend bulk command, both exercised across multiple tenants.
 */
function resendInvoice(array $overrides = []): Invoice
{
    $ws      = $overrides['workspace'] ?? Workspace::factory()->create();
    unset($overrides['workspace']);

    $patient = Patient::withoutGlobalScopes()->create(minimalPatientData(['workspace_id' => $ws->id]));

    return Invoice::withoutGlobalScopes()->create(array_merge([
        'workspace_id'            => $ws->id,
        'patient_id'              => $patient->id,
        'invoice_number'          => 'F-' . fake()->unique()->numerify('####'),
        'issued_at'               => now()->toDateString(),
        'status'                  => 'issued',
        'subtotal'                => '100.00',
        'iva_amount'              => '0.00',
        'total'                   => '100.00',
        'amount_paid'             => '0.00',
        'sri_status'              => 'rejected',
        'sri_submission_attempts' => 3,
        'sri_last_error'          => 'Comprobante DEVUELTO por el SRI.',
    ], $overrides));
}

function resendCreditNote(array $overrides = []): CreditNote
{
    $ws = $overrides['workspace'] ?? Workspace::factory()->create();
    unset($overrides['workspace']);

    // The source invoice of a credit note is already authorized, so it must
    // not be picked up by the rejected-voucher sweep.
    $invoice = resendInvoice(['workspace' => $ws, 'sri_status' => 'authorized']);

    return CreditNote::withoutGlobalScopes()->create(array_merge([
        'workspace_id'            => $ws->id,
        'invoice_id'              => $invoice->id,
        'credit_note_number'      => 'NC-' . fake()->unique()->numerify('####'),
        'issued_at'               => now()->toDateString(),
        'reason'                  => 'Anulación',
        'amount'                  => '100.00',
        'status'                  => 'issued',
        'sri_status'              => 'rejected',
        'sri_submission_attempts' => 4,
        'sri_last_error'          => 'Comprobante NO AUTORIZADO por el SRI.',
    ], $overrides));
}

it('resets and re-queues a rejected invoice and credit note', function () {
    Queue::fake();

    $invoice    = resendInvoice();
    $creditNote = resendCreditNote();

    $sri = app(SriService::class);

    expect($sri->resend($invoice))->toBeTrue()
        ->and($sri->resend($creditNote))->toBeTrue();

    $invoice->refresh();
    $creditNote->refresh();

    expect($invoice->sri_status)->toBe('pending')
        ->and($invoice->sri_submission_attempts)->toBe(0)
        ->and($invoice->sri_last_error)->toBeNull()
        ->and($creditNote->sri_status)->toBe('pending')
        ->and($creditNote->sri_submission_attempts)->toBe(0)
        ->and($creditNote->sri_last_error)->toBeNull();

    Queue::assertPushed(AuthorizeSriVoucherJob::class, 2);
});

it('never re-arms an annulled invoice', function (string $status) {
    // Reached from the "Reintentar" button and from the `sri:resend` sweep.
    // Re-arming a voided invoice would file it with the tax authority after the
    // workspace decided it no longer exists.
    Queue::fake();

    $invoice = resendInvoice([
        'status'                  => $status,
        'sri_status'              => 'rejected',
        'sri_submission_attempts' => 3,
    ]);

    expect(app(SriService::class)->resend($invoice))->toBeFalse();

    $invoice->refresh();

    expect($invoice->sri_status)->toBe('rejected')
        ->and($invoice->sri_submission_attempts)->toBe(3);

    Queue::assertNotPushed(AuthorizeSriVoucherJob::class);
})->with(['void', 'cancelled']);

it('never re-sends an already authorized voucher', function () {
    Queue::fake();

    $invoice = resendInvoice(['sri_status' => 'authorized', 'sri_submission_attempts' => 1]);

    expect(app(SriService::class)->resend($invoice))->toBeFalse();

    $invoice->refresh();

    expect($invoice->sri_status)->toBe('authorized')
        ->and($invoice->sri_submission_attempts)->toBe(1);

    Queue::assertNotPushed(AuthorizeSriVoucherJob::class);
});

it('bulk resends rejected vouchers across workspaces via the command', function () {
    Queue::fake();

    $wsA = Workspace::factory()->create();
    $wsB = Workspace::factory()->create();

    resendInvoice(['workspace' => $wsA]);
    resendInvoice(['workspace' => $wsB]);
    resendCreditNote(['workspace' => $wsB]);

    // An authorized voucher must be ignored by the default --status=rejected.
    resendInvoice(['workspace' => $wsA, 'sri_status' => 'authorized']);

    $this->artisan('sri:resend', ['--status' => 'rejected'])
        ->expectsOutputToContain('Re-queued 3 SRI voucher(s)')
        ->assertSuccessful();

    Queue::assertPushed(AuthorizeSriVoucherJob::class, 3);

    expect(Invoice::withoutGlobalScopes()->where('sri_status', 'rejected')->count())->toBe(0)
        ->and(CreditNote::withoutGlobalScopes()->where('sri_status', 'rejected')->count())->toBe(0);
});

it('limits the command to a single workspace with --workspace', function () {
    Queue::fake();

    $wsA = Workspace::factory()->create();
    $wsB = Workspace::factory()->create();

    resendInvoice(['workspace' => $wsA]);
    resendInvoice(['workspace' => $wsB]);

    $this->artisan('sri:resend', ['--workspace' => $wsA->id, '--status' => 'rejected'])
        ->assertSuccessful();

    Queue::assertPushed(AuthorizeSriVoucherJob::class, 1);

    expect(Invoice::withoutGlobalScopes()->where('workspace_id', $wsB->id)->where('sri_status', 'rejected')->count())->toBe(1);
});

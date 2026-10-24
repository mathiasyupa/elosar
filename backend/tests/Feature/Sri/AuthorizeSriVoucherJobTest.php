<?php

use App\Exceptions\Sri\SriPermanentException;
use App\Jobs\AuthorizeSriVoucherJob;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Workspace;
use App\Services\Sri\SriService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * State machine of AuthorizeSriVoucherJob, exercised with a mocked SriService
 * (no network, no certificate). Covers: no-op on terminal states, requeue while
 * pending, transient-error handling, and the max-attempts cap.
 */
function sriJobInvoice(array $overrides = []): Invoice
{
    $ws      = Workspace::factory()->create();
    $patient = Patient::withoutGlobalScopes()->create(minimalPatientData(['workspace_id' => $ws->id]));

    return Invoice::withoutGlobalScopes()->create(array_merge([
        'workspace_id'            => $ws->id,
        'patient_id'              => $patient->id,
        'invoice_number'          => 'F-0001',
        'issued_at'               => now()->toDateString(),
        'status'                  => 'issued',
        'subtotal'                => '100.00',
        'iva_amount'              => '0.00',
        'total'                   => '100.00',
        'amount_paid'             => '0.00',
        'sri_status'              => 'pending',
        'sri_submission_attempts' => 0,
    ], $overrides));
}

it('does nothing when the voucher is already authorized', function () {
    $invoice = sriJobInvoice(['sri_status' => 'authorized']);
    $sri     = Mockery::mock(SriService::class);
    $sri->shouldNotReceive('authorizeInvoice');

    (new AuthorizeSriVoucherJob($invoice))->handle($sri);

    expect($invoice->fresh()->sri_status)->toBe('authorized');
});

it('stops filing an invoice that was voided or cancelled while in flight', function (string $status) {
    // The SRI can take minutes to resolve a voucher, and the invoice can be
    // annulled in that window. Without this guard the job kept filing it with
    // the tax authority — and if the SRI authorized it, the workspace ended up
    // with a fiscally valid comprobante for an invoice it considers annulled.
    Queue::fake();
    $invoice = sriJobInvoice(['status' => $status, 'sri_status' => 'pending']);
    $sri     = Mockery::mock(SriService::class);
    $sri->shouldNotReceive('authorizeInvoice');

    (new AuthorizeSriVoucherJob($invoice))->handle($sri);

    Queue::assertNotPushed(AuthorizeSriVoucherJob::class);
})->with(['void', 'cancelled']);

it('requeues while the voucher stays pending', function () {
    Queue::fake();
    $invoice = sriJobInvoice(['sri_status' => 'pending']);
    $sri     = Mockery::mock(SriService::class);
    $sri->shouldReceive('authorizeInvoice')->once(); // leaves the row pending

    (new AuthorizeSriVoucherJob($invoice))->handle($sri);

    Queue::assertPushed(AuthorizeSriVoucherJob::class);
});

it('marks pending and requeues on a transient error', function () {
    Queue::fake();
    $invoice = sriJobInvoice(['sri_status' => 'pending']);
    $sri     = Mockery::mock(SriService::class);
    $sri->shouldReceive('authorizeInvoice')->andThrow(new RuntimeException('connection timeout'));

    (new AuthorizeSriVoucherJob($invoice))->handle($sri);

    expect($invoice->fresh()->sri_status)->toBe('pending')
        ->and($invoice->fresh()->sri_last_error)->toContain('connection timeout');

    Queue::assertPushed(AuthorizeSriVoucherJob::class);
});

it('rejects after reaching the maximum attempts', function () {
    Queue::fake();
    $invoice = sriJobInvoice(['sri_status' => 'pending', 'sri_submission_attempts' => 10]);
    $sri     = Mockery::mock(SriService::class);
    $sri->shouldNotReceive('authorizeInvoice');

    (new AuthorizeSriVoucherJob($invoice))->handle($sri);

    expect($invoice->fresh()->sri_status)->toBe('rejected');
    Queue::assertNotPushed(AuthorizeSriVoucherJob::class);
});

it('rejects without requeueing on a permanent error', function () {
    Queue::fake();
    $invoice = sriJobInvoice(['sri_status' => 'pending']);
    $sri     = Mockery::mock(SriService::class);
    $sri->shouldReceive('authorizeInvoice')
        ->andThrow(new SriPermanentException('Workspace [1] is not configured for SRI electronic invoicing.'));

    (new AuthorizeSriVoucherJob($invoice))->handle($sri);

    expect($invoice->fresh()->sri_status)->toBe('rejected')
        ->and($invoice->fresh()->sri_last_error)->toContain('not configured');

    // Permanent errors are not retried.
    Queue::assertNotPushed(AuthorizeSriVoucherJob::class);
});

it('marks the voucher rejected and logs when the job fails definitively', function () {
    Log::spy();
    $invoice = sriJobInvoice(['sri_status' => 'pending']);

    (new AuthorizeSriVoucherJob($invoice))->failed(new RuntimeException('soap fault: gateway timeout'));

    expect($invoice->fresh()->sri_status)->toBe('rejected')
        ->and($invoice->fresh()->sri_last_error)->toContain('gateway timeout');

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context): bool =>
            str_contains($message, 'SRI voucher authorization failed')
            && $context['voucher_id'] === $invoice->id
            && $context['workspace_id'] === $invoice->workspace_id);
});

it('does not clobber an already authorized voucher on failed()', function () {
    $invoice = sriJobInvoice(['sri_status' => 'authorized']);

    (new AuthorizeSriVoucherJob($invoice))->failed(new RuntimeException('late transport error'));

    expect($invoice->fresh()->sri_status)->toBe('authorized');
});

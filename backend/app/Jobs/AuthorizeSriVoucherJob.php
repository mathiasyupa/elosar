<?php

namespace App\Jobs;

use App\Exceptions\Sri\SriPermanentException;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Services\Sri\SriError;
use App\Services\Sri\SriService;
use App\Support\ActiveWorkspace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Authorizes an invoice or credit note against the SRI.
 *
 * Because the SRI may take time to authorize ("EN PROCESAMIENTO"), the job
 * re-queues itself with a delay while the voucher stays `pending`, up to
 * MAX_ATTEMPTS. A returned/rejected voucher stops retrying.
 *
 * Failure handling distinguishes two error classes:
 *   - SriPermanentException (config / XSD / certificate): not retryable — the
 *     voucher is marked rejected and the job is dead-lettered via fail().
 *   - any other Throwable (transport / transient): kept pending and re-queued
 *     within the attempt cap; once the cap is exhausted it is dead-lettered too.
 *
 * Either way, a definitive failure lands in failed_jobs and goes through
 * failed(), which logs it and leaves the voucher in a consistent state.
 */
class AuthorizeSriVoucherJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const MAX_ATTEMPTS = 10;

    public const RETRY_DELAY_SECONDS = 60;

    /**
     * After this many seconds without resolving, a `pending` voucher is stuck
     * rather than slow — the retry budget is spent. Derived so the front-end
     * never has to repeat the numbers and drift out of sync.
     */
    public static function staleAfterSeconds(): int
    {
        return (int) (self::MAX_ATTEMPTS * self::RETRY_DELAY_SECONDS * 1.5);
    }

    public function __construct(public Invoice|CreditNote $voucher) {}

    public function handle(SriService $sri): void
    {
        // Queued jobs have no session: bind the voucher's workspace so the
        // fail-closed tenant scope resolves to the right tenant (e.g. the
        // ->fresh() reloads below) instead of throwing or leaking.
        app(ActiveWorkspace::class)->runBound((int) $this->voucher->workspace_id, function () use ($sri) {
            $voucher = $this->voucher->fresh();

            // Idempotency: nothing to do on terminal states or a vanished row.
            if ($voucher === null || $voucher->sri_status === 'authorized' || $voucher->sri_status === 'rejected') {
                return;
            }

            // An invoice annulled while the SRI was still deciding must not keep
            // being filed. The window is real: authorization takes minutes, and
            // voiding or cancelling in the meantime used to leave the job
            // retrying — with the SRI possibly authorizing a comprobante for an
            // invoice this workspace considers annulled.
            if ($voucher instanceof Invoice && $voucher->isCancelled()) {
                return;
            }

            // A platform_admin suspension (e.g. for fraud) must stop new SRI
            // submissions immediately — don't keep filing with the tax
            // authority on behalf of a workspace that was just locked out.
            // Leave it pending; a reactivation lets the next retry proceed.
            if ($voucher->workspace?->isSuspended()) {
                return;
            }

            // A commercial read-only state deliberately does NOT stop this job.
            // The voucher already exists and may already be filed with the SRI;
            // abandoning it mid-cycle would leave Elosar's record disagreeing
            // with the tax authority's. Issuing a NEW voucher is what read-only
            // blocks, and that happens at the route, before anything is filed.

            if ($voucher->sri_submission_attempts >= self::MAX_ATTEMPTS) {
                $voucher->forceFill([
                    'sri_status' => 'rejected',
                    'sri_last_error' => 'Se alcanzó el número máximo de intentos de autorización SRI.',
                ])->save();

                return;
            }

            try {
                if ($voucher instanceof Invoice) {
                    $sri->authorizeInvoice($voucher);
                } else {
                    $sri->authorizeCreditNote($voucher);
                }
            } catch (SriPermanentException $e) {
                // Structural / configuration error: retrying cannot help. Mark
                // the voucher rejected and surface a definitive job failure.
                $voucher->forceFill([
                    'sri_status' => 'rejected',
                    'sri_last_error' => $e->getMessage(),
                    'sri_error' => SriError::internal(SriError::categoryFor($e)),
                ])->save();

                $this->fail($e);

                return;
            } catch (Throwable $e) {
                // Transport / transient error: keep pending and retry later.
                $voucher->forceFill([
                    'sri_status' => 'pending',
                    'sri_last_error' => $e->getMessage(),
                    'sri_error' => SriError::internal(SriError::categoryFor($e)),
                ])->save();

                $fresh = $voucher->fresh();

                if ($fresh && $fresh->sri_submission_attempts >= self::MAX_ATTEMPTS) {
                    // Retry budget exhausted on transient errors: dead-letter it.
                    $this->fail($e);

                    return;
                }

                $this->requeueIfPending($fresh);

                return;
            }

            // No exception: the SRI may still be processing — poll again later.
            $this->requeueIfPending($voucher->fresh());
        });
    }

    /**
     * Definitive failure handler: invoked by the queue after the job is failed
     * (either via fail() above or by the worker after exhausting retries).
     *
     * Logs the failure with voucher + workspace context and leaves the voucher
     * in a consistent terminal state without clobbering an already-authorized
     * comprobante.
     */
    public function failed(Throwable $e): void
    {
        Log::error('SRI voucher authorization failed permanently.', [
            'voucher_type' => $this->voucher instanceof Invoice ? 'invoice' : 'credit_note',
            'voucher_id' => $this->voucher->getKey(),
            'workspace_id' => $this->voucher->workspace_id,
            'error' => $e->getMessage(),
        ]);

        app(ActiveWorkspace::class)->runBound((int) $this->voucher->workspace_id, function () use ($e) {
            $voucher = $this->voucher->fresh();

            if ($voucher === null || $voucher->sri_status === 'authorized') {
                return;
            }

            $voucher->forceFill([
                'sri_status' => 'rejected',
                'sri_last_error' => $e->getMessage(),
                'sri_error' => SriError::internal(SriError::categoryFor($e)),
            ])->save();
        });
    }

    /**
     * @param  Invoice|CreditNote|null  $voucher
     */
    private function requeueIfPending($voucher): void
    {
        if ($voucher
            && $voucher->sri_status === 'pending'
            && $voucher->sri_submission_attempts < self::MAX_ATTEMPTS) {
            self::dispatch($voucher)->delay(now()->addSeconds(self::RETRY_DELAY_SECONDS));
        }
    }
}

<?php

namespace App\Console\Commands;

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Services\Sri\SriService;
use Illuminate\Console\Command;

/**
 * Bulk re-submits SRI vouchers that did not get authorized.
 *
 * By default it re-queues every `rejected` invoice and credit note across all
 * tenants; `--status` accepts a comma-separated list (e.g. `rejected,pending`)
 * to also pick up stuck `pending` vouchers, and `--workspace` limits the sweep
 * to a single tenant.
 *
 * Iterates with withoutGlobalScopes() on purpose: the WorkspaceScope is
 * fail-closed and there is no session in the console, so this is the documented
 * cross-tenant access pattern.
 *
 *   php artisan sri:resend
 *   php artisan sri:resend --status=rejected,pending
 *   php artisan sri:resend --workspace=1
 */
class SriResendCommand extends Command
{
    protected $signature = 'sri:resend
        {--workspace= : Limit to a single workspace ID}
        {--status=rejected : Comma-separated SRI statuses to resend (e.g. rejected,pending)}';

    protected $description = 'Re-queue rejected (or stuck pending) SRI vouchers for authorization';

    public function handle(SriService $sri): int
    {
        $statuses = collect(explode(',', (string) $this->option('status')))
            ->map(fn (string $s) => trim($s))
            ->filter()
            ->values()
            ->all();

        if ($statuses === []) {
            $this->error('No valid --status provided.');

            return self::FAILURE;
        }

        $workspaceId = $this->option('workspace');

        $queued = 0;

        foreach ([Invoice::class, CreditNote::class] as $model) {
            $query = $model::withoutGlobalScopes()
                ->whereIn('sri_status', $statuses);

            if ($workspaceId !== null) {
                $query->where('workspace_id', (int) $workspaceId);
            }

            foreach ($query->cursor() as $voucher) {
                if ($sri->resend($voucher)) {
                    $queued++;
                }
            }
        }

        $this->info("Re-queued {$queued} SRI voucher(s) for authorization.");

        return self::SUCCESS;
    }
}

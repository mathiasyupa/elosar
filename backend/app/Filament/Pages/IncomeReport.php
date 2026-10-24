<?php

namespace App\Filament\Pages;

use App\Models\Payment;
use App\Support\ActiveWorkspace;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

/**
 * Income report by period.
 *
 * Standalone Filament page (not a Resource Page) showing:
 * - Total collected in the selected period
 * - Breakdown by payment method
 * - Daily payment trend
 */
class IncomeReport extends Page
{
    protected string $view = 'filament.pages.income-report';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Income';

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $title = 'Income Report';

    protected static ?int $navigationSort = 1;

    // Date range (Livewire properties for reactivity)
    public string $dateFrom = '';
    public string $dateTo   = '';

    public function mount(): void
    {
        // Default: current month
        $this->dateFrom = now()->startOfMonth()->format('Y-m-d');
        $this->dateTo   = now()->endOfMonth()->format('Y-m-d');
    }

    /**
     * Total collected in the period (sum of payments within the date range).
     */
    public function getTotalCollected(): float
    {
        return (float) $this->paymentsQuery()
            ->sum('amount');
    }

    /**
     * Breakdown by payment method: [ 'Cash' => 1200.00, 'Transfer' => 800.00, ... ]
     * (keys are English display labels).
     *
     * @return array<string,float>
     */
    public function getBreakdownByMethod(): array
    {
        return $this->paymentsQuery()
            ->select('payment_method', DB::raw('SUM(amount) as total'))
            ->groupBy('payment_method')
            ->orderByDesc('total')
            ->pluck('total', 'payment_method')
            ->mapWithKeys(fn ($total, $method) => [
                match ($method) {
                    'cash'     => 'Cash',
                    'transfer' => 'Transfer',
                    'card'     => 'Card',
                    default    => 'Other',
                } => (float) $total,
            ])
            ->all();
    }

    /**
     * Daily payment trend: date => total.
     *
     * @return array<string,float>
     */
    public function getDailyTrend(): array
    {
        return $this->paymentsQuery()
            ->select(
                DB::raw("DATE(payment_date) as day"),
                DB::raw('SUM(amount) as total')
            )
            ->groupBy('day')
            ->orderBy('day')
            ->pluck('total', 'day')
            ->mapWithKeys(fn ($total, $day) => [$day => (float) $total])
            ->all();
    }

    /**
     * Number of invoices paid in the period.
     */
    public function getPaidInvoicesCount(): int
    {
        $workspaceId = app(ActiveWorkspace::class)->id();

        if (! $workspaceId) {
            return 0;
        }

        return \App\Models\Invoice::where('workspace_id', $workspaceId)
            ->where('status', 'paid')
            ->whereBetween('issued_at', [$this->dateFrom, $this->dateTo])
            ->count();
    }

    /**
     * Number of unique patients who paid in the period.
     */
    public function getUniquePatientsCount(): int
    {
        return $this->paymentsQuery()
            ->join('invoices', 'payments.invoice_id', '=', 'invoices.id')
            ->distinct('invoices.patient_id')
            ->count('invoices.patient_id');
    }

    /**
     * Base query of payments for the active workspace within the date range.
     */
    private function paymentsQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $workspaceId = app(ActiveWorkspace::class)->id();

        $query = Payment::query();

        if ($workspaceId) {
            $query->where('workspace_id', $workspaceId);
        }

        if ($this->dateFrom) {
            $query->whereDate('payment_date', '>=', $this->dateFrom);
        }

        if ($this->dateTo) {
            $query->whereDate('payment_date', '<=', $this->dateTo);
        }

        return $query;
    }
}

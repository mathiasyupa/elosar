<?php

namespace App\Filament\Widgets;

use App\Models\Invoice;
use App\Models\Payment;
use App\Support\ActiveWorkspace;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class FinancialOverviewWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected function getStats(): array
    {
        $workspaceId = app(ActiveWorkspace::class)->id();

        // Without an active workspace no data is shown (console, seeders, etc.)
        if (! $workspaceId) {
            return [
                Stat::make("Today's Revenue", '$0.00'),
                Stat::make('Monthly Revenue', '$0.00'),
                Stat::make('Receivable', '$0.00'),
            ];
        }

        $today = today();

        $incomeToday = Payment::where('workspace_id', $workspaceId)
            ->whereDate('payment_date', $today)
            ->sum('amount');

        $incomeThisMonth = Payment::where('workspace_id', $workspaceId)
            ->whereMonth('payment_date', now()->month)
            ->whereYear('payment_date', now()->year)
            ->sum('amount');

        $amountReceivable = Invoice::where('workspace_id', $workspaceId)
            ->whereIn('status', ['issued', 'partial'])
            ->selectRaw('COALESCE(SUM(total - amount_paid), 0) as pending')
            ->value('pending') ?? 0;

        return [
            Stat::make("Today's Revenue", '$' . number_format((float) $incomeToday, 2))
                ->icon('heroicon-o-banknotes')
                ->color('success'),

            Stat::make('Monthly Revenue', '$' . number_format((float) $incomeThisMonth, 2))
                ->icon('heroicon-o-chart-bar')
                ->color('primary'),

            Stat::make('Receivable', '$' . number_format((float) $amountReceivable, 2))
                ->icon('heroicon-o-clock')
                ->color($amountReceivable > 0 ? 'warning' : 'success'),
        ];
    }
}

<?php

namespace App\Filament\SuperAdmin\Widgets;

use App\Models\Workspace;
use App\Models\WorkspaceEntitlement;
use App\Services\WorkspaceEntitlementService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * How the business is doing, first thing on the dashboard.
 *
 * Deliberately separate from PlatformStatsWidget: user counts and queue health
 * are real but they are not the question you open this panel to answer.
 */
class CommercialStatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 0;

    protected function getStats(): array
    {
        $entitlements = app(WorkspaceEntitlementService::class);

        $byState = WorkspaceEntitlement::query()
            ->selectRaw('state, count(*) as total')
            ->groupBy('state')
            ->pluck('total', 'state');

        $expiringSoon = WorkspaceEntitlement::query()
            ->where(function ($query): void {
                $query->whereBetween('trial_ends_at', [now(), now()->addDays(7)])
                    ->orWhereBetween('access_ends_at', [now(), now()->addDays(7)]);
            })
            ->count();

        // Was paying and stopped. Accounts that never paid are on a trial or
        // under review — not a debt.
        $overdue = Workspace::query()
            ->whereHas('subscriptionPayments')
            ->whereDoesntHave('subscriptionPayments', fn ($query) => $query->where('period_end', '>=', today()))
            ->count();

        return [
            Stat::make('Ingresos mensuales estimados', 'USD '.$entitlements->monthlyRecurringRevenueUsd())
                ->description('Planes activos; paquetes por dueño. Sin cobro automático.')
                ->color('success'),

            Stat::make('Activos (estado guardado)', $byState[WorkspaceEntitlement::STATE_ACTIVE] ?? 0),

            Stat::make('En prueba (estado guardado)', $byState[WorkspaceEntitlement::STATE_TRIAL] ?? 0)
                ->color('warning'),

            Stat::make('Solo lectura (estado guardado)', $byState[WorkspaceEntitlement::STATE_READ_ONLY] ?? 0)
                ->description('Revisa el acceso efectivo en Planes y accesos')
                ->color($byState[WorkspaceEntitlement::STATE_READ_ONLY] ?? 0 ? 'danger' : 'gray'),

            Stat::make('Vencen en 7 días', $expiringSoon)
                ->description('Requieren seguimiento esta semana')
                ->color($expiringSoon > 0 ? 'warning' : 'success'),

            Stat::make('Pago vencido', $overdue)
                ->description('Con pagos anteriores, sin período vigente')
                ->color($overdue > 0 ? 'danger' : 'success'),
        ];
    }
}

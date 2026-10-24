<?php

namespace App\Filament\SuperAdmin\Widgets;

use App\Models\User;
use App\Models\Workspace;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Who is on the platform. Sits below the commercial figures, because how many
 * accounts exist is context — not the question the panel is opened to answer.
 *
 * Queue health moved out entirely: pending and failed jobs are system health,
 * and mixing them in here made a broken worker look like a business metric.
 * They live with FailedJobsWidget now.
 */
class PlatformStatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected function getStats(): array
    {
        return [
            Stat::make('Usuarios', User::count())
                ->description(User::where('created_at', '>=', now()->subDays(7))->count().' nuevos en 7 días'),

            Stat::make('Médicos', User::where('account_type', 'doctor')->count()),

            Stat::make('Personal', User::where('account_type', 'staff')->count()),

            Stat::make('Espacios', Workspace::count())
                ->description(Workspace::where('created_at', '>=', now()->subDays(7))->count().' nuevos en 7 días'),

            Stat::make('Consultorios', Workspace::where('type', 'personal')->count()),

            Stat::make('Clínicas', Workspace::where('type', 'clinic')->count()),

            Stat::make(
                'Cuentas o espacios suspendidos',
                User::whereNotNull('suspended_at')->count() + Workspace::whereNotNull('suspended_at')->count(),
            )
                ->description('Bloqueo de plataforma, separado del plan')
                ->color('danger'),
        ];
    }
}

<?php

namespace App\Filament\SuperAdmin\Widgets;

use App\Filament\SuperAdmin\Support\EntitlementActions;
use App\Filament\SuperAdmin\Support\CommercialDisplay;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlement;
use Filament\Actions\ActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * The accounts worth a message this week: a trial about to run out, or a
 * subscription that was being paid and stopped.
 *
 * Actionable from the row — an operator who sees the problem should be able to
 * extend or record a payment without navigating away and losing the list.
 */
class NeedsAttentionWidget extends TableWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                WorkspaceEntitlement::query()
                    ->with(['workspace.subscriptionPayments'])
                    ->where(function (Builder $query): void {
                        $query
                            // Running out within the week.
                            ->whereBetween('trial_ends_at', [now(), now()->addDays(7)])
                            ->orWhereBetween('access_ends_at', [now(), now()->addDays(7)])
                            ->orWhere(fn (Builder $q): Builder => $q
                                ->where('state', WorkspaceEntitlement::STATE_ACTIVE)
                                ->where('access_ends_at', '<', now()))
                            // Or stopped paying: had payments, none still current.
                            ->orWhereHas('workspace', fn (Builder $w): Builder => $w
                                ->whereHas('subscriptionPayments')
                                ->whereDoesntHave(
                                    'subscriptionPayments',
                                    fn (Builder $p): Builder => $p->where('period_end', '>=', today()),
                                ));
                    })
            )
            ->heading('Requieren atención esta semana')
            ->emptyStateHeading('Sin vencimientos ni pagos pendientes')
            ->emptyStateDescription('No hay planes que requieran seguimiento en esta lista.')
            ->paginated([5, 10, 25])
            ->columns([
                TextColumn::make('workspace.name')
                    ->label('Espacio')
                    ->description(fn (WorkspaceEntitlement $record): string => $record->workspace?->isClinic() ? 'Clínica' : 'Consultorio'),

                TextColumn::make('state')
                    ->label('Acceso efectivo')
                    ->badge()
                    ->state(fn (WorkspaceEntitlement $record): string => CommercialDisplay::access($record->workspace))
                    ->color(fn (WorkspaceEntitlement $record): string => CommercialDisplay::accessColor($record->workspace)),

                TextColumn::make('why')
                    ->label('Motivo')
                    ->state(function (WorkspaceEntitlement $record): string {
                        if ($record->workspace?->isSubscriptionOverdue()) {
                            return 'Pago vencido desde '.$record->workspace->paidUntil()->format('d/m/Y');
                        }

                        $deadline = CommercialDisplay::deadline($record);
                        $days = $deadline ? (int) today()->diffInDays($deadline->startOfDay(), false) : null;

                        return match (true) {
                            $days === null => '—',
                            $days < 0 && $record->state === WorkspaceEntitlement::STATE_ACTIVE => 'Plazo vencido; revisar acceso',
                            $days < 0 => 'Venció hace '.abs($days).' días',
                            $days === 0 => 'Vence hoy',
                            $days === 1 => 'Vence mañana',
                            default => "Vence en {$days} días",
                        };
                    })
                    ->color('danger'),

                TextColumn::make('price')
                    ->label('Tarifa mensual')
                    ->state(fn (WorkspaceEntitlement $record): string => CommercialDisplay::price($record))
                    ->alignEnd(),
            ])
            ->recordActions([
                ActionGroup::make([
                    EntitlementActions::recordPayment(fn (WorkspaceEntitlement $record): Workspace => $record->workspace),
                    EntitlementActions::extendTrial(fn (WorkspaceEntitlement $record): Workspace => $record->workspace),
                    EntitlementActions::activate(fn (WorkspaceEntitlement $record): Workspace => $record->workspace),
                ])->label('Gestionar')->icon('heroicon-m-ellipsis-horizontal')->button(),
            ]);
    }
}

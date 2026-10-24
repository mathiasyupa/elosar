<?php

namespace App\Filament\SuperAdmin\Resources;

use App\Filament\SuperAdmin\Resources\SubscriptionResource\Pages\ListSubscriptions;
use App\Filament\SuperAdmin\Support\EntitlementActions;
use App\Filament\SuperAdmin\Support\CommercialDisplay;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlement;
use App\Services\WorkspaceEntitlementService;
use Filament\Actions\ActionGroup;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The commercial view of the platform: one row per subscription.
 *
 * Built on WorkspaceEntitlement rather than Workspace because the row *is* the
 * subscription — its plan, its deadline, its seats and its money. `Workspaces`
 * stays for the technical side (owner, members, suspension, SRI).
 *
 * Every column here exists so an operator does not have to open the record to
 * answer a question they ask daily.
 */
class SubscriptionResource extends Resource
{
    protected static ?string $model = WorkspaceEntitlement::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?string $navigationLabel = 'Planes y accesos';

    protected static string|\UnitEnum|null $navigationGroup = 'Gestión comercial';

    protected static ?string $modelLabel = 'plan y acceso';

    protected static ?string $pluralModelLabel = 'planes y accesos';

    protected static ?int $navigationSort = 1;

    /** Subscriptions are provisioned by the lifecycle, never typed in by hand. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['workspace.subscriptionPayments']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('workspace.name')
                    ->label('Espacio')
                    ->description(fn (WorkspaceEntitlement $record): string => $record->workspace?->isClinic() ? 'Clínica' : 'Consultorio')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('plan_code')
                    ->label('Plan')
                    ->badge()
                    ->formatStateUsing(fn (WorkspaceEntitlement $record): string => CommercialDisplay::plan($record))
                    ->color(fn (string $state): string => $state === WorkspaceEntitlement::PLAN_PERSONAL ? 'gray' : 'info'),

                TextColumn::make('state')
                    ->label('Acceso efectivo')
                    ->badge()
                    ->state(fn (WorkspaceEntitlement $record): string => CommercialDisplay::access($record->workspace))
                    ->description(fn (WorkspaceEntitlement $record): ?string => CommercialDisplay::accessNote($record->workspace))
                    ->color(fn (WorkspaceEntitlement $record): string => CommercialDisplay::accessColor($record->workspace)),

                TextColumn::make('doctors')
                    ->label('Médicos que atienden')
                    ->placeholder('—')
                    ->state(fn (WorkspaceEntitlement $record): string => CommercialDisplay::seats($record->workspace))
                    // Over the included seats means extras are being billed.
                    ->color(fn (WorkspaceEntitlement $record): string => $record->additionalClinicalSeats() > 0 ? 'warning' : 'gray'),

                TextColumn::make('price')
                    ->label('Tarifa mensual')
                    ->state(fn (WorkspaceEntitlement $record): string => CommercialDisplay::price($record))
                    ->alignEnd(),

                TextColumn::make('expires')
                    ->label('Fin de prueba / plazo registrado')
                    ->placeholder('—')
                    ->state(function (WorkspaceEntitlement $record): ?string {
                        $deadline = CommercialDisplay::deadline($record);

                        if ($deadline === null) {
                            return null;
                        }

                        $days = (int) today()->diffInDays($deadline->startOfDay(), false);

                        return match (true) {
                            $days < 0 && $record->state === WorkspaceEntitlement::STATE_ACTIVE => $deadline->format('d/m/Y').' · plazo vencido; revisar acceso',
                            $days < 0 => $deadline->format('d/m/Y').' · prueba vencida',
                            $days === 0 => $deadline->format('d/m/Y').' · hoy',
                            default => $deadline->format('d/m/Y')." · {$days} días",
                        };
                    })
                    ->color(function (WorkspaceEntitlement $record): string {
                        $deadline = CommercialDisplay::deadline($record);

                        if ($deadline === null) {
                            return 'gray';
                        }

                        $days = (int) today()->diffInDays($deadline->startOfDay(), false);

                        return match (true) {
                            $days < 0 && $record->state === WorkspaceEntitlement::STATE_ACTIVE => 'warning',
                            $days < 0 => 'danger',
                            $days <= 7 => 'warning',
                            default => 'gray',
                        };
                    }),

                TextColumn::make('paid_until')
                    ->label('Pagado hasta')
                    ->placeholder('Sin pagos')
                    ->state(fn (WorkspaceEntitlement $record): ?string => $record->workspace?->paidUntil()?->format('d/m/Y'))
                    ->color(fn (WorkspaceEntitlement $record): string => $record->workspace?->isSubscriptionOverdue() ? 'danger' : 'gray'),

                TextColumn::make('source')
                    ->label('Origen')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->label('Estado registrado')
                    ->options([
                        WorkspaceEntitlement::STATE_TRIAL => 'Prueba',
                        WorkspaceEntitlement::STATE_ACTIVE => 'Activo',
                        WorkspaceEntitlement::STATE_READ_ONLY => 'Solo lectura',
                    ]),

                SelectFilter::make('plan_code')
                    ->label('Plan')
                    ->options([
                        WorkspaceEntitlement::PLAN_PERSONAL => 'Consultorio personal',
                        WorkspaceEntitlement::PLAN_CLINIC => 'Clínica',
                        WorkspaceEntitlement::PLAN_CLINIC_UNLIMITED => 'Clínica ilimitada',
                    ]),

                // The question an operator actually asks: who do I need to talk
                // to this week? Already-expired accounts are a different list.
                Filter::make('expiring_soon')
                    ->label('Vence en 7 días')
                    ->query(fn (Builder $query): Builder => $query->where(function (Builder $q): void {
                        $q->whereBetween('trial_ends_at', [now(), now()->addDays(7)])
                            ->orWhereBetween('access_ends_at', [now(), now()->addDays(7)]);
                    })),

                Filter::make('active_past_deadline')
                    ->label('Plazo vencido; revisar acceso')
                    ->query(fn (Builder $query): Builder => $query
                        ->where('state', WorkspaceEntitlement::STATE_ACTIVE)
                        ->where('access_ends_at', '<', now())),

                Filter::make('overdue')
                    ->label('Pago vencido')
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'workspace',
                        fn (Builder $w): Builder => $w->whereHas('subscriptionPayments')
                            ->whereDoesntHave(
                                'subscriptionPayments',
                                fn (Builder $p): Builder => $p->where('period_end', '>=', today()),
                            ),
                    )),

                Filter::make('legacy_unreviewed')
                    ->label('Legado sin revisar')
                    ->query(fn (Builder $query): Builder => $query->where('source', WorkspaceEntitlement::SOURCE_LEGACY_REVIEW)),
            ])
            ->recordActions([
                ActionGroup::make(EntitlementActions::all(
                    fn (WorkspaceEntitlement $record): Workspace => $record->workspace,
                ))->label('Gestionar')->icon('heroicon-m-ellipsis-horizontal')->button(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSubscriptions::route('/'),
        ];
    }
}

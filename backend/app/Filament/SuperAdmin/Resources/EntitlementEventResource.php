<?php

namespace App\Filament\SuperAdmin\Resources;

use App\Filament\SuperAdmin\Resources\EntitlementEventResource\Pages\ListEntitlementEvents;
use App\Models\WorkspaceEntitlementEvent;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Everything that has ever been done to a subscription, and why.
 *
 * Every operator action requires a note, and until now nothing could read it
 * back — an audit trail that only accepts writes is not an audit trail. This
 * makes it answerable: who granted that extension, when, and what reason they
 * gave.
 *
 * Strictly read-only. The underlying table is append-only through PostgreSQL
 * triggers that reject UPDATE, DELETE and TRUNCATE, so the panel reflects that
 * guarantee instead of offering buttons the database will refuse.
 */
class EntitlementEventResource extends Resource
{
    protected static ?string $model = WorkspaceEntitlementEvent::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'Historial comercial';

    protected static string|\UnitEnum|null $navigationGroup = 'Gestión comercial';

    protected static ?string $modelLabel = 'cambio comercial';

    protected static ?string $pluralModelLabel = 'historial comercial';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    /** Human labels for the actions the service records. */
    public static function actionLabels(): array
    {
        return [
            'signup_trial_started' => 'Prueba iniciada',
            'signup_trial_unavailable' => 'Alta sin prueba',
            'legacy_entitlement_provisioned' => 'Acceso legado creado',
            'trial_expired' => 'Prueba vencida',
            'operator_set_active' => 'Acceso activado',
            'operator_extend_access' => 'Acceso extendido',
            'operator_extend_trial' => 'Prueba extendida',
            'operator_set_read_only' => 'Solo lectura',
            'operator_restore_access' => 'Acceso restaurado',
            'operator_set_seat_limit' => 'Cupos médicos modificados',
            'operator_issued_account_grant' => 'Prueba otorgada',
            'operator_changed_plan' => 'Plan cambiado',
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['workspace', 'actor']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('workspace.name')
                    ->label('Espacio')
                    ->searchable()
                    ->default('—'),

                TextColumn::make('action')
                    ->label('Cambio')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::actionLabels()[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'operator_set_read_only', 'trial_expired' => 'danger',
                        'operator_set_active', 'operator_restore_access' => 'success',
                        'operator_extend_trial', 'operator_extend_access', 'operator_issued_account_grant' => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('actor.name')
                    ->label('Realizado por')
                    // Automatic transitions (the nightly expiry) have no actor.
                    ->default('Sistema'),

                TextColumn::make('note')
                    ->label('Motivo')
                    ->state(fn (WorkspaceEntitlementEvent $record): ?string => $record->after['operator_note'] ?? null)
                    ->placeholder('—')
                    ->wrap()
                    ->limit(80),
            ])
            ->filters([
                SelectFilter::make('action')
                    ->label('Cambio')
                    ->options(self::actionLabels()),

                SelectFilter::make('actor_user_id')
                    ->label('Operador')
                    ->relationship('actor', 'name'),

                Filter::make('occurred')
                    ->schema([
                        DatePicker::make('from')->label('Desde')->native(false),
                        DatePicker::make('until')->label('Hasta')->native(false),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([
                // The before/after snapshots are the full record but too dense
                // for a row; they belong behind a click.
                Action::make('detail')
                    ->label('Detalle')
                    ->icon('heroicon-m-magnifying-glass')
                    ->modalHeading('Cambio comercial')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Cerrar')
                    ->infolist([
                        TextEntry::make('action')
                            ->label('Cambio')
                            ->formatStateUsing(fn (string $state): string => self::actionLabels()[$state] ?? $state),
                        TextEntry::make('before')
                            ->label('Antes')
                            ->placeholder('Aún no existía el plan')
                            ->formatStateUsing(fn (?array $state): string => self::formatSnapshot($state)),
                        TextEntry::make('after')
                            ->label('Después')
                            ->formatStateUsing(fn (?array $state): string => self::formatSnapshot($state)),
                    ]),
            ]);
    }

    private static function formatSnapshot(?array $state): string
    {
        if (empty($state)) {
            return '—';
        }

        return collect($state)
            ->map(fn ($value, $key): string => $key.': '.(is_scalar($value) ? (string) $value : json_encode($value)))
            ->implode("\n");
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEntitlementEvents::route('/'),
        ];
    }
}

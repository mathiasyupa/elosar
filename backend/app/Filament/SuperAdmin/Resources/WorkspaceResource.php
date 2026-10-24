<?php

namespace App\Filament\SuperAdmin\Resources;

use App\Filament\SuperAdmin\Resources\WorkspaceResource\Pages\EditWorkspace;
use App\Filament\SuperAdmin\Resources\WorkspaceResource\Pages\ListWorkspaces;
use App\Filament\SuperAdmin\Resources\WorkspaceResource\Pages\ViewWorkspace;
use App\Filament\SuperAdmin\Resources\WorkspaceResource\RelationManagers\MembersRelationManager;
use App\Filament\SuperAdmin\Support\EntitlementActions;
use App\Filament\SuperAdmin\Support\CommercialDisplay;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlement;
use App\Services\WorkspaceEntitlementService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class WorkspaceResource extends Resource
{
    protected static ?string $model = Workspace::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $navigationLabel = 'Consultorios y clínicas';

    protected static string|\UnitEnum|null $navigationGroup = 'Plataforma';

    protected static ?string $modelLabel = 'espacio';

    protected static ?string $pluralModelLabel = 'consultorios y clínicas';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nombre')
                ->required()
                ->maxLength(255),

        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'clinic' ? 'Clínica' : 'Consultorio')
                    ->color(fn (string $state) => match ($state) {
                        'clinic' => 'success',
                        'personal' => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('entitlement.state')
                    ->label('Acceso efectivo')
                    ->badge()
                    ->state(fn (Workspace $record): string => CommercialDisplay::access($record))
                    ->description(fn (Workspace $record): ?string => CommercialDisplay::accessNote($record))
                    ->color(fn (Workspace $record): string => CommercialDisplay::accessColor($record)),

                TextColumn::make('entitlement.plan_code')
                    ->label('Plan')
                    ->badge()
                    ->state(fn (Workspace $record): string => CommercialDisplay::plan($record->entitlement)),

                TextColumn::make('clinical_seats')
                    ->label('Médicos que atienden')
                    ->placeholder('—')
                    ->getStateUsing(fn (Workspace $record): string => CommercialDisplay::seats($record))
                    // Over the included seats means the clinic is being billed
                    // for extras, which the operator needs to see at a glance.
                    ->color(fn (Workspace $record): string => $record->entitlement?->additionalClinicalSeats() > 0 ? 'warning' : 'gray'),

                TextColumn::make('entitlement.trial_ends_at')
                    ->label('Fin de prueba')
                    ->date()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('entitlement.access_ends_at')
                    ->label('Plazo registrado de acceso')
                    ->date()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('suspended')
                    ->label('Habilitado')
                    ->getStateUsing(fn (Workspace $record): bool => ! $record->isSuspended())
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-no-symbol')
                    ->trueColor('success')
                    ->falseColor('danger'),

                TextColumn::make('owner_name')
                    ->label('Dueño')
                    ->getStateUsing(function (Workspace $record): string {
                        // Prefer owner_user_id, fall back to owner_doctor_id -> user
                        if ($record->ownerUser) {
                            return $record->ownerUser->name;
                        }

                        return $record->owner?->user?->name ?? '—';
                    })
                    ->searchable(false),

                TextColumn::make('members_count')
                    ->label('Miembros')
                    ->counts('members')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Tipo')
                    ->options([
                        'personal' => 'Consultorio',
                        'clinic' => 'Clínica',
                    ]),

                TernaryFilter::make('suspended_at')
                    ->label('Suspensión')
                    ->placeholder('Todos')
                    ->trueLabel('Suspendido')
                    ->falseLabel('Habilitado')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('suspended_at'),
                        false: fn ($query) => $query->whereNull('suspended_at'),
                    ),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),

                Action::make('suspend')
                    ->label('Suspender')
                    ->icon('heroicon-m-no-symbol')
                    ->color('danger')
                    ->visible(fn (Workspace $record): bool => ! $record->isSuspended())
                    ->requiresConfirmation()
                    ->modalDescription('Todos los miembros de este espacio perderán acceso inmediatamente.')
                    ->form([
                        TextInput::make('suspended_reason')
                            ->label('Motivo (opcional)')
                            ->maxLength(255),
                    ])
                    ->action(function (array $data, Workspace $record): void {
                        $record->update([
                            'suspended_at' => now(),
                            'suspended_reason' => $data['suspended_reason'] ?? null,
                        ]);

                        Notification::make()->title('Espacio suspendido.')->warning()->send();
                    }),

                Action::make('reactivate')
                    ->label('Reactivar')
                    ->icon('heroicon-m-check-circle')
                    ->color('success')
                    ->visible(fn (Workspace $record): bool => $record->isSuspended())
                    ->requiresConfirmation()
                    ->action(function (Workspace $record): void {
                        $record->update(['suspended_at' => null, 'suspended_reason' => null]);

                        Notification::make()->title('Espacio reactivado.')->success()->send();
                    }),

                // The commercial actions live in Subscriptions, but an operator
                // who arrived here from a support conversation should not have
                // to navigate away to act. Same audited code path either way.
                ...EntitlementActions::all(fn (Workspace $record): Workspace => $record),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            MembersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkspaces::route('/'),
            'view' => ViewWorkspace::route('/{record}'),
            'edit' => EditWorkspace::route('/{record}/edit'),
        ];
    }
}

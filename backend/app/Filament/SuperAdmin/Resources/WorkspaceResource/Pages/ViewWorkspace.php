<?php

namespace App\Filament\SuperAdmin\Resources\WorkspaceResource\Pages;

use App\Filament\SuperAdmin\Resources\WorkspaceResource;
use App\Filament\SuperAdmin\Support\CommercialDisplay;
use App\Filament\SuperAdmin\Support\EntitlementActions;
use App\Models\Workspace;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewWorkspace extends ViewRecord
{
    protected static string $resource = WorkspaceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label('Editar nombre'),
            ActionGroup::make(EntitlementActions::all(fn (Workspace $record): Workspace => $record))
                ->label('Gestionar acceso y plan')
                ->icon('heroicon-m-ellipsis-horizontal')
                ->button(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Espacio')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('name')
                            ->label('Nombre')
                            ->weight('bold'),

                        TextEntry::make('type')
                            ->label('Tipo')
                            ->badge()
                            ->formatStateUsing(fn (string $state) => $state === 'clinic' ? 'Clínica' : 'Consultorio'),

                        // Commercial state lives in Subscriptions. Repeating a
                        // legacy column here would be a third answer to the
                        // same question, and the one nobody maintains.
                        TextEntry::make('entitlement.state')
                            ->label('Acceso efectivo')
                            ->badge()
                            ->state(fn (Workspace $record): string => CommercialDisplay::access($record))
                            ->color(fn (Workspace $record): string => CommercialDisplay::accessColor($record)),

                        TextEntry::make('access_deadline_note')
                            ->label('Revisión de acceso')
                            ->state(fn (Workspace $record): ?string => CommercialDisplay::accessNote($record))
                            ->visible(fn (Workspace $record): bool => CommercialDisplay::accessNote($record) !== null),

                        TextEntry::make('entitlement.plan_code')
                            ->label('Plan')
                            ->badge()
                            ->state(fn (Workspace $record): string => CommercialDisplay::plan($record->entitlement)),

                        TextEntry::make('clinical_seats')
                            ->label('Médicos que atienden')
                            ->state(fn (Workspace $record): string => CommercialDisplay::seats($record)),

                        TextEntry::make('ownerName')
                            ->label('Dueño')
                            ->state(fn (Workspace $record): string => $record->ownerUser?->name
                                ?? $record->owner?->user?->name
                                ?? '—'),

                        TextEntry::make('suspended_at')
                            ->label('Suspendido desde')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('No suspendido'),

                        TextEntry::make('suspended_reason')
                            ->label('Motivo de suspensión')
                            ->placeholder('—'),

                        TextEntry::make('created_at')
                            ->label('Creado')
                            ->dateTime('d/m/Y'),
                    ]),

                Section::make('Datos fiscales / SRI')
                    ->columns(3)
                    ->collapsible()
                    ->schema([
                        TextEntry::make('ruc')->label('RUC')->placeholder('—'),
                        TextEntry::make('razon_social')->label('Razón social')->placeholder('—'),
                        TextEntry::make('sri_environment')->label('Ambiente SRI')->placeholder('—'),
                    ]),
            ]);
    }
}

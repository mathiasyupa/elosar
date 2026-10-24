<?php

namespace App\Filament\Resources\Workspaces\Tables;

use App\Models\Workspace;
use App\Services\ClinicService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Workspaces table.
 * Includes the action to regenerate a clinic's invite_code.
 */
class WorkspacesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'clinic' ? 'Clinic' : 'Personal')
                    ->color(fn (string $state) => $state === 'clinic' ? 'info' : 'gray')
                    ->sortable(),

                TextColumn::make('owner.name')
                    ->label('Owner')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('invite_code')
                    ->label('Invite code')
                    ->badge()
                    ->copyable()
                    ->placeholder('—'),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Type')
                    ->options([
                        'personal' => 'Personal',
                        'clinic' => 'Clinic',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),

                // Regenerate invite_code (clinics only).
                Action::make('regenerateInviteCode')
                    ->label('Regenerate code')
                    ->icon('heroicon-m-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('The previous code will stop working immediately.')
                    ->visible(fn (Workspace $record) => $record->isClinic())
                    ->action(function (Workspace $record) {
                        $code = app(ClinicService::class)->regenerateInviteCode($record);

                        Notification::make()
                            ->title('Code regenerated')
                            ->body("New invite code: {$code}")
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

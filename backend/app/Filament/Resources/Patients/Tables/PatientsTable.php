<?php

namespace App\Filament\Resources\Patients\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * PATIENTS LIST TABLE CONFIGURATION
 * Modular class that abstracts the patients grid presentation.
 */
class PatientsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                // Patient name
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                // National ID number (Ecuador)
                TextColumn::make('id_number')
                    ->searchable()
                    ->sortable(),

                // Civil status
                TextColumn::make('civil_status')
                    ->badge()
                    ->label('Civil Status')
                    ->toggleable(),

                // Contact phone
                TextColumn::make('phone')
                    ->searchable()
                    ->sortable(),

                // Email
                TextColumn::make('email')
                    ->searchable()
                    ->sortable(),

                // Creation timestamp (hidden by default)
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                // Update timestamp (hidden by default)
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

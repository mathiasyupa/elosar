<?php

namespace App\Filament\Resources\Doctors\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * DOCTORS LIST TABLE CONFIGURATION
 * Modular class that abstracts the doctors grid presentation.
 */
class DoctorsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                // Doctor name
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                // Primary specialty (legacy FK)
                TextColumn::make('specialty.name')
                    ->searchable()
                    ->sortable()
                    ->label('Primary specialty'),

                // Additional specialties via pivot (not sortable — collection)
                TextColumn::make('specialties.name')
                    ->label('Other specialties')
                    ->badge()
                    ->color('info')
                    ->separator(', ')
                    ->toggleable(isToggledHiddenByDefault: true),

                // National ID (cédula)
                TextColumn::make('id_number')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

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

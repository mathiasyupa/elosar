<?php

namespace App\Filament\Resources\Patients\RelationManagers;

use App\Models\FamilyBackground;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Patient family history, inside the Patients module.
 *
 * Implemented as a RelationManager (not a top-level resource) because a
 * family history entry always exists in the context of a specific patient:
 * managed from the patient profile via the familyBackground() relation.
 */
class FamilyBackgroundRelationManager extends RelationManager
{
    protected static string $relationship = 'familyBackground';

    protected static ?string $title = 'Family History';

    protected static ?string $modelLabel = 'family history entry';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('disease')
                    ->required()
                    ->maxLength(255)
                    ->prefixIcon('heroicon-m-beaker')
                    ->label('Disease / Condition')
                    ->placeholder('e.g. Type 2 Diabetes, Hypertension'),

                // Stored in English (DB convention), displayed in English
                Select::make('relative_type')
                    ->required()
                    ->options(FamilyBackground::RELATIVE_TYPES)
                    ->native(false)
                    ->prefixIcon('heroicon-m-users')
                    ->label('Relative'),

                Textarea::make('notes')
                    ->label('Notes')
                    ->rows(3)
                    ->columnSpanFull()
                    ->placeholder('Additional notes from the physician (optional)'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('disease')
            ->columns([
                TextColumn::make('disease')
                    ->label('Disease / Condition')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('relative_type')
                    ->label('Relative')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => FamilyBackground::RELATIVE_TYPES[$state] ?? $state)
                    ->searchable(),

                TextColumn::make('notes')
                    ->label('Notes')
                    ->limit(50)
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

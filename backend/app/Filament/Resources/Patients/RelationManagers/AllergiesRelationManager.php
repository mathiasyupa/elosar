<?php

namespace App\Filament\Resources\Patients\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AllergiesRelationManager extends RelationManager
{
    protected static string $relationship = 'allergies';

    protected static ?string $title = 'Allergies';

    protected static ?string $modelLabel = 'allergy';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('allergen_type')
                    ->label('Allergen type')
                    ->required()
                    ->options([
                        'drug'          => 'Drug',
                        'food'          => 'Food',
                        'environmental' => 'Environmental',
                        'other'         => 'Other',
                    ])
                    ->native(false),

                TextInput::make('allergen_name')
                    ->label('Allergen name')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('e.g. Penicillin, Shellfish, Pollen'),

                Select::make('severity')
                    ->label('Severity')
                    ->required()
                    ->options([
                        'mild'     => 'Mild',
                        'moderate' => 'Moderate',
                        'severe'   => 'Severe',
                    ])
                    ->native(false),

                DatePicker::make('diagnosed_at')
                    ->label('Diagnosis date')
                    ->native(false)
                    ->displayFormat('d/m/Y'),

                Textarea::make('notes')
                    ->label('Notes')
                    ->rows(2)
                    ->columnSpanFull()
                    ->placeholder('Observed reaction, emergency treatment, etc.'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('allergen_name')
            ->columns([
                TextColumn::make('allergen_name')
                    ->label('Allergen')
                    ->searchable()
                    ->weight('semibold'),

                TextColumn::make('allergen_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'drug'          => 'Drug',
                        'food'          => 'Food',
                        'environmental' => 'Environmental',
                        default         => 'Other',
                    }),

                TextColumn::make('severity')
                    ->label('Severity')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'mild'     => 'success',
                        'moderate' => 'warning',
                        'severe'   => 'danger',
                        default    => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'mild'     => 'Mild',
                        'moderate' => 'Moderate',
                        'severe'   => 'Severe',
                        default    => $state,
                    }),

                TextColumn::make('diagnosed_at')
                    ->label('Diagnosed')
                    ->date('d/m/Y')
                    ->toggleable(),
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

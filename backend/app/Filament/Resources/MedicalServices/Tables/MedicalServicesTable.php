<?php

namespace App\Filament\Resources\MedicalServices\Tables;

use App\Models\MedicalService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class MedicalServicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('category')
                    ->label('Category')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => MedicalService::CATEGORIES[$state] ?? ($state ?? '—'))
                    ->sortable(),

                TextColumn::make('base_price')
                    ->label('Base price')
                    ->formatStateUsing(fn ($state): string => '$' . number_format((float) $state, 2))
                    ->sortable(),

                TextColumn::make('iva_rate')
                    ->label('VAT')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        '0'  => 'gray',
                        '15' => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        '0'  => 'Exempt',
                        '15' => '15%',
                        default => $state . '%',
                    }),

                ToggleColumn::make('is_active')
                    ->label('Active'),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->label('Category')
                    ->options(MedicalService::CATEGORIES),
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

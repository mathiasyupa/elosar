<?php

namespace App\Filament\Resources\MedicalRecords\Tables;

use App\Filament\Actions\GeneratePrescriptionAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * MEDICAL RECORDS LIST TABLE CONFIGURATION
 * Modular class that renders the patients' clinical history in tabular format.
 */
class MedicalRecordsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                // Patient name (loaded via Eloquent relation)
                TextColumn::make('patient.name')
                    ->searchable()
                    ->sortable()
                    ->label('Patient'),

                // Doctor name (loaded via Eloquent relation)
                TextColumn::make('doctor.name')
                    ->searchable()
                    ->sortable()
                    ->label('Doctor'),

                // Reason for consultation, truncated to 40 chars for readability
                TextColumn::make('reason_for_consultation')
                    ->limit(40)
                    ->tooltip(fn ($record) => $record->reason_for_consultation)
                    ->label('Reason'),

                // Truncated diagnosis
                TextColumn::make('diagnosis')
                    ->limit(40)
                    ->tooltip(fn ($record) => $record->diagnosis)
                    ->label('ICD-10 Diagnosis'),

                // Consultation date
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->label('Recorded At'),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                GeneratePrescriptionAction::make(), // Downloads the prescription PDF
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

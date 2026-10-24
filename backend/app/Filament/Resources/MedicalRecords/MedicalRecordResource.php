<?php

namespace App\Filament\Resources\MedicalRecords;

use App\Filament\Resources\MedicalRecords\Pages\CreateMedicalRecord;
use App\Filament\Resources\MedicalRecords\Pages\EditMedicalRecord;
use App\Filament\Resources\MedicalRecords\Pages\ListMedicalRecords;
use App\Filament\Resources\MedicalRecords\Schemas\MedicalRecordForm;
use App\Filament\Resources\MedicalRecords\Tables\MedicalRecordsTable;
use App\Models\MedicalRecord;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class MedicalRecordResource extends Resource
{
    // Linked business model
    protected static ?string $model = MedicalRecord::class;

    // Sidebar navigation icon
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    // Diagnosis is used for global search
    protected static ?string $recordTitleAttribute = 'diagnosis';

    /**
     * Wires the modular form configuration using Filament's standard Schema component.
     */
    public static function form(Schema $schema): Schema
    {
        return MedicalRecordForm::configure($schema);
    }

    /**
     * Wires the medical records list table configuration.
     */
    public static function table(Table $table): Table
    {
        return MedicalRecordsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    /**
     * Defines the routes and pages for the resource CRUD screens.
     */
    public static function getPages(): array
    {
        return [
            'index' => ListMedicalRecords::route('/'),
            'create' => CreateMedicalRecord::route('/create'),
            'edit' => EditMedicalRecord::route('/{record}/edit'),
        ];
    }
}

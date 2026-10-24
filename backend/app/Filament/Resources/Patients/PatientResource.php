<?php

namespace App\Filament\Resources\Patients;

use App\Filament\Resources\Patients\Pages\CreatePatient;
use App\Filament\Resources\Patients\Pages\EditPatient;
use App\Filament\Resources\Patients\Pages\ListPatients;
use App\Filament\Resources\Patients\Pages\ViewPatientAccount;
use App\Filament\Resources\Patients\Schemas\PatientForm;
use App\Filament\Resources\Patients\Tables\PatientsTable;
use App\Models\Patient;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PatientResource extends Resource
{
    // Linked business model
    protected static ?string $model = Patient::class;

    // Sidebar navigation icon
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    // Key attribute used for suggestions and global search
    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Wires the modular form configuration using Filament's standard Schema component.
     */
    public static function form(Schema $schema): Schema
    {
        return PatientForm::configure($schema);
    }

    /**
     * Wires the modular table configuration.
     */
    public static function table(Table $table): Table
    {
        return PatientsTable::configure($table);
    }

    public static function getRelations(): array
    {
        // FamilyBackgroundRelationManager is NOT registered here: it renders inside
        // the "Antecedentes Familiares" tab of the form (PatientForm), embedded as a
        // Livewire component. Registering it here would duplicate it below the form.
        return [];
    }

    /**
     * Defines the routes and pages for the resource CRUD screens.
     */
    public static function getPages(): array
    {
        return [
            'index'   => ListPatients::route('/'),
            'create'  => CreatePatient::route('/create'),
            'edit'    => EditPatient::route('/{record}/edit'),
            'account' => ViewPatientAccount::route('/{record}/account'),
        ];
    }
}

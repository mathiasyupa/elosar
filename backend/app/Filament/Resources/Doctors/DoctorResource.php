<?php

namespace App\Filament\Resources\Doctors;

use App\Filament\Resources\Doctors\Pages\CreateDoctor;
use App\Filament\Resources\Doctors\Pages\EditDoctor;
use App\Filament\Resources\Doctors\Pages\ListDoctors;
use App\Filament\Resources\Doctors\Schemas\DoctorForm;
use App\Filament\Resources\Doctors\Tables\DoctorsTable;
use App\Models\Doctor;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class DoctorResource extends Resource
{
    // Linked business model
    protected static ?string $model = Doctor::class;

    // Sidebar navigation icon
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    // Main field used for global doctor search
    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Wires the modular form configuration using Filament's standard Schema component.
     */
    public static function form(Schema $schema): Schema
    {
        return DoctorForm::configure($schema);
    }

    /**
     * Wires the doctors list table configuration.
     */
    public static function table(Table $table): Table
    {
        return DoctorsTable::configure($table);
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
            'index' => ListDoctors::route('/'),
            'create' => CreateDoctor::route('/create'),
            'edit' => EditDoctor::route('/{record}/edit'),
        ];
    }
}

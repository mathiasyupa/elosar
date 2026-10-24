<?php

namespace App\Filament\Resources\Appointments;

use App\Filament\Resources\Appointments\Pages\CreateAppointment;
use App\Filament\Resources\Appointments\Pages\EditAppointment;
use App\Filament\Resources\Appointments\Pages\ListAppointments;
use App\Filament\Resources\Appointments\Schemas\AppointmentForm;
use App\Filament\Resources\Appointments\Tables\AppointmentsTable;
use App\Models\Appointment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AppointmentResource extends Resource
{
    // Linked business model
    protected static ?string $model = Appointment::class;

    // Sidebar navigation icon (schedule/calendar)
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    // Status is used for secondary global search
    protected static ?string $recordTitleAttribute = 'status';

    /**
     * Wires the modular form configuration using Filament's standard Schema component.
     */
    public static function form(Schema $schema): Schema
    {
        return AppointmentForm::configure($schema);
    }

    /**
     * Wires the appointments list table configuration.
     */
    public static function table(Table $table): Table
    {
        return AppointmentsTable::configure($table);
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
            'index' => ListAppointments::route('/'),
            'create' => CreateAppointment::route('/create'),
            'edit' => EditAppointment::route('/{record}/edit'),
        ];
    }
}

<?php

namespace App\Filament\Resources\Appointments\Schemas;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;

class AppointmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Schedule Information')
                    ->description('Set up the patient, attending doctor and date for the medical appointment.')
                    ->icon('heroicon-m-calendar')
                    ->columns(2)
                    ->components([
                        Select::make('patient_id')
                            ->relationship(name: 'patient', titleAttribute: 'name')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->prefixIcon('heroicon-m-user')
                            ->label('Patient'),

                        Select::make('doctor_id')
                            ->relationship(name: 'doctor', titleAttribute: 'name')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->prefixIcon('heroicon-m-academic-cap')
                            ->label('Attending Physician'),

                        DateTimePicker::make('appointment_date')
                            ->required()
                            ->native(false)
                            ->seconds(false)
                            ->displayFormat('Y-m-d H:i')
                            ->label('Date & Time'),

                        Select::make('status')
                            ->options([
                                'scheduled' => 'Scheduled',
                                'completed' => 'Completed',
                                'cancelled' => 'Cancelled',
                            ])
                            ->default('scheduled')
                            ->required()
                            ->selectablePlaceholder(false)
                            ->label('Status'),

                        Select::make('type')
                            ->label('Appointment type')
                            ->options([
                                'first_visit'  => 'First visit',
                                'follow_up'    => 'Follow-up',
                                'procedure'    => 'Procedure',
                                'emergency'    => 'Emergency',
                                'telemedicine' => 'Telemedicine',
                            ])
                            ->default('first_visit')
                            ->native(false)
                            ->required(),

                        TextInput::make('duration_minutes')
                            ->label('Duration (minutes)')
                            ->numeric()
                            ->default(30)
                            ->minValue(5)
                            ->maxValue(480)
                            ->suffix('min')
                            ->required(),

                        Select::make('channel')
                            ->label('Scheduling channel')
                            ->options([
                                'in_person' => 'In person',
                                'phone'     => 'Phone',
                                'web'       => 'Web',
                                'referral'  => 'Referral',
                            ])
                            ->native(false)
                            ->nullable(),

                        Textarea::make('notes')
                            ->label('Appointment notes')
                            ->rows(2)
                            ->nullable()
                            ->columnSpanFull()
                            ->placeholder('Reason for visit, prior instructions, etc.'),
                    ]),
            ]);
    }
}
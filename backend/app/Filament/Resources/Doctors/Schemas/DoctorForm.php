<?php

namespace App\Filament\Resources\Doctors\Schemas;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\FileUpload;

/**
 * DOCTORS FORM CONFIGURATION
 * Built with Filament's standard Schema component.
 * Maps and validates the full professional profile.
 */
class DoctorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Professional data section
                Section::make('Medical Professional Profile')
                    ->description('Register details of the professional including credentials and medical specialty.')
                    ->icon('heroicon-m-academic-cap')
                    ->columns(2)
                    ->components([
                        // Full name
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->prefixIcon('heroicon-m-user')
                            ->label('Full Name')
                            ->placeholder('e.g. Dr. Gregory House'),

                        // Specialty: existing list (enum-like) BUT with the option to
                        // CREATE a new specialty on the fly if the doctor can't find it.
                        Select::make('specialty_id')
                            ->relationship(name: 'specialty', titleAttribute: 'name')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->prefixIcon('heroicon-m-academic-cap')
                            ->label('Medical Specialty')
                            ->placeholder('Select or create a specialty')
                            // Popup form to register a new specialty
                            ->createOptionForm([
                                TextInput::make('name')
                                    ->label('Specialty name')
                                    ->required()
                                    ->maxLength(255)
                                    ->unique(table: 'specialties', column: 'name')
                                    ->placeholder('e.g. Neurology, Dermatology'),
                                Textarea::make('description')
                                    ->label('Description (optional)')
                                    ->rows(2),
                            ])
                            ->createOptionModalHeading('Create new specialty'),

                        // National ID (cédula)
                        TextInput::make('id_number')
                            ->required()
                            ->maxLength(10)
                            ->unique(table: 'doctors', column: 'id_number', ignoreRecord: true)
                            ->prefixIcon('heroicon-m-identification')
                            ->label('Identification Number')
                            ->placeholder('e.g. 1712345678')
                            ->validationMessages([
                                'unique' => 'This identification number is already registered in the system.',
                            ]),
                    ]),

                // Contact and location section
                Section::make('Professional Contact & Location')
                    ->description('Provide the physician work phone, email and city location.')
                    ->icon('heroicon-m-envelope')
                    ->columns(2)
                    ->components([
                        // City
                        TextInput::make('city')
                            ->required()
                            ->maxLength(255)
                            ->prefixIcon('heroicon-m-map-pin')
                            ->label('City')
                            ->placeholder('e.g. Guayaquil'),

                        // Email
                        TextInput::make('email')
                            ->required()
                            ->email()
                            ->maxLength(255)
                            ->prefixIcon('heroicon-m-envelope')
                            ->label('Email Address')
                            ->placeholder('e.g. g.house@hospital.com'),

                        // Phone
                        TextInput::make('phone')
                            ->required()
                            ->maxLength(255)
                            ->prefixIcon('heroicon-m-phone')
                            ->label('Phone Number')
                            ->placeholder('e.g. +593912345678'),
                    ]),

                // SIGNATURE AND MEDICAL LICENSE — used in the prescription PDF
                Section::make('Signature & Medical License')
                    ->description('The signature (image) and license number are printed on prescriptions.')
                    ->icon('heroicon-m-pencil-square')
                    ->columns(2)
                    ->components([
                        TextInput::make('medical_license')
                            ->label('Medical license / registration')
                            ->prefixIcon('heroicon-m-identification')
                            ->placeholder('e.g. ACESS-12345')
                            ->maxLength(255),

                        \Filament\Forms\Components\Toggle::make('uses_physical_stamp_signature')
                            ->label('Uses physical stamp and signature')
                            ->helperText('Clinical documents leave a blank signature line instead of printing professional details.'),

                        FileUpload::make('signature_path')
                            ->label('Signature (image)')
                            ->image()
                            ->disk('local')
                            ->visibility('private')
                            ->directory('signatures')
                            ->helperText('Upload an image of your signature (transparent PNG background recommended).')
                            ->columnSpanFull(),
                    ]),

                // ADDITIONAL SPECIALTIES — doctor_specialties pivot
                Section::make('Additional Specialties')
                    ->description('Register all the doctor\'s specialties. The primary specialty (above) is printed on prescriptions.')
                    ->icon('heroicon-m-tag')
                    ->collapsible()
                    ->collapsed()
                    ->components([
                        Select::make('specialties')
                            ->relationship('specialties', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->label('Additional specialties')
                            ->helperText('Select all specialties this doctor practices. You can choose multiple.')
                            ->columnSpanFull(),
                    ]),

                // PROFILE PHOTO
                Section::make('Profile Photo')
                    ->description('Doctor photo for visual identification in the system.')
                    ->icon('heroicon-m-camera')
                    ->collapsible()
                    ->components([
                        FileUpload::make('profile_photo_path')
                            ->label('Profile photo')
                            ->image()
                            ->disk('local')
                            ->visibility('private')
                            ->directory('doctors/photos')
                            ->imageEditor()
                            ->columnSpanFull()
                            ->helperText('Professional photo of the doctor (JPG or PNG recommended).'),
                    ]),
            ]);
    }
}

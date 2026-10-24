<?php

namespace App\Filament\Resources\Patients\Schemas;

use App\Filament\Resources\Patients\RelationManagers\FamilyBackgroundRelationManager;
use App\Filament\Resources\Patients\RelationManagers\AllergiesRelationManager;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/**
 * PATIENT FORM — PAGE-LEVEL TABBED PROFILE
 *
 * The patient profile is organized in sibling tabs:
 *   1. "General"              → demographics and contact information.
 *   2. "Clinical"             → photo, blood type, emergency contact, insurance.
 *   3. "Personal History"     → personal_background jsonb (structured fields).
 *   4. "Family History"       → FamilyBackground RelationManager embedded as a
 *                               Livewire component (edit-only, since it needs a
 *                               persisted patient as ownerRecord).
 *   5. "Allergies"            → structured allergies RelationManager.
 *
 * Architecture notes:
 *   - Uses Filament\Schemas\Schema with form(Schema $schema) and ->components([...]).
 *   - RelationManagers are registered HERE (in their tab) and NOT in
 *     PatientResource::getRelations(), to avoid rendering them duplicated
 *     below the form.
 *   - Only heroicon-m- (mini) icons are used.
 */
class PatientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('PatientTabs')
                    ->persistTabInQueryString()
                    ->columnSpanFull()
                    ->tabs([
                        self::demographicsTab(),
                        self::clinicalDataTab(),
                        self::personalHistoryTab(),
                        self::familyHistoryTab(),
                        self::allergiesTab(),
                    ]),
            ]);
    }

    /**
     * Tab 1 — Patient demographics and contact information.
     */
    protected static function demographicsTab(): Tab
    {
        return Tab::make('Demographics')
            ->icon('heroicon-m-user')
            ->schema([
                Section::make('Personal Information')
                    ->description('Patient demographics and basic profile.')
                    ->icon('heroicon-m-user')
                    ->columns(2)
                    ->components([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->prefixIcon('heroicon-m-user')
                            ->label('Full Name')
                            ->placeholder('e.g. John Doe'),

                        TextInput::make('id_number')
                            ->required()
                            ->maxLength(10)
                            ->unique(table: 'patients', column: 'id_number', ignoreRecord: true)
                            ->prefixIcon('heroicon-m-identification')
                            ->label('Identification Number')
                            ->placeholder('e.g. 1712345678')
                            ->validationMessages([
                                'unique' => 'This identification number is already registered in the system.',
                            ]),

                        DatePicker::make('birth_date')
                            ->required()
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->label('Birth Date')
                            ->maxDate(now())
                            ->placeholder('Select date'),

                        Select::make('gender')
                            ->options([
                                'male'   => 'Male',
                                'female' => 'Female',
                                'other'  => 'Other',
                            ])
                            ->required()
                            ->label('Gender')
                            ->placeholder('Select gender'),

                        Select::make('civil_status')
                            ->options([
                                'single'      => 'Single',
                                'married'     => 'Married',
                                'common_law'  => 'Common law',
                                'divorced'    => 'Divorced',
                                'widowed'     => 'Widowed',
                                'separated'   => 'Separated',
                            ])
                            ->native(false)
                            ->prefixIcon('heroicon-m-heart')
                            ->label('Civil Status')
                            ->placeholder('Select civil status'),
                    ]),

                Section::make('Contact & Location')
                    ->description('Phone number, email and home address.')
                    ->icon('heroicon-m-map-pin')
                    ->columns(2)
                    ->components([
                        // Replaced "City of Residence": the street address is what
                        // the consulting room actually needs. City is kept on the
                        // model for the existing records and the by-city report,
                        // but is no longer captured anywhere.
                        TextInput::make('address')
                            ->required()
                            ->maxLength(255)
                            ->prefixIcon('heroicon-m-map-pin')
                            ->label('Address')
                            ->placeholder('e.g. Av. Amazonas N34-120 y Av. Atahualpa'),

                        TextInput::make('email')
                            ->email()
                            ->maxLength(255)
                            ->prefixIcon('heroicon-m-envelope')
                            ->label('Email Address')
                            ->placeholder('e.g. john.doe@example.com'),

                        TextInput::make('phone')
                            ->required()
                            ->maxLength(255)
                            ->prefixIcon('heroicon-m-phone')
                            ->label('Phone Number')
                            ->placeholder('e.g. +593987654321'),
                    ]),
            ]);
    }

    /**
     * Tab 2 — Essential clinical data: blood type, emergency contact, insurance.
     */
    protected static function clinicalDataTab(): Tab
    {
        return Tab::make('Clinical Data')
            ->icon('heroicon-m-heart')
            ->schema([
                Section::make('Photo & Blood Type')
                    ->description('Patient photo for visual identification and blood type.')
                    ->icon('heroicon-m-camera')
                    ->columns(2)
                    ->components([
                        FileUpload::make('photo_path')
                            ->label('Patient photo')
                            ->image()
                            // A patient photo is identifiable health data under
                            // LOPDP: private disk, reachable only through a
                            // signed URL. Never the public disk.
                            ->disk('local')
                            ->visibility('private')
                            ->directory('patients/photos')
                            ->imageEditor()
                            ->columnSpanFull()
                            ->helperText('Upload a photo of the patient for easy identification.'),

                        Select::make('blood_type')
                            ->label('Blood type')
                            ->options([
                                'A+'  => 'A+', 'A-'  => 'A-',
                                'B+'  => 'B+', 'B-'  => 'B-',
                                'O+'  => 'O+', 'O-'  => 'O-',
                                'AB+' => 'AB+', 'AB-' => 'AB-',
                            ])
                            ->native(false)
                            ->placeholder('Select type'),
                    ]),

                Section::make('Emergency Contact')
                    ->description('Person to contact in case of a medical emergency.')
                    ->icon('heroicon-m-phone-arrow-up-right')
                    ->columns(2)
                    ->components([
                        TextInput::make('emergency_contact_name')
                            ->label('Contact name')
                            ->maxLength(255)
                            ->prefixIcon('heroicon-m-user')
                            ->placeholder('e.g. María González'),

                        TextInput::make('emergency_contact_phone')
                            ->label('Contact phone')
                            ->maxLength(50)
                            ->prefixIcon('heroicon-m-phone')
                            ->placeholder('e.g. +593987654321'),
                    ]),

                Section::make('Medical Insurance')
                    ->description('Insurance provider and policy number.')
                    ->icon('heroicon-m-shield-check')
                    ->columns(2)
                    ->components([
                        TextInput::make('insurance_provider')
                            ->label('Insurance provider')
                            ->maxLength(255)
                            ->prefixIcon('heroicon-m-building-office')
                            ->placeholder('e.g. IESS, Seguros Sucre, ACS'),

                        TextInput::make('insurance_policy_number')
                            ->label('Policy number')
                            ->maxLength(100)
                            ->prefixIcon('heroicon-m-document-text')
                            ->placeholder('e.g. POL-123456'),
                    ]),
            ]);
    }

    /**
     * Tab 3 — Personal history (personal_background jsonb).
     * Friendly structured fields mapped with dot notation onto the jsonb.
     */
    protected static function personalHistoryTab(): Tab
    {
        return Tab::make('Personal History')
            ->icon('heroicon-m-clipboard-document-list')
            ->schema([
                Section::make('Clinical History')
                    ->description('Known allergies, prior surgeries and current medication.')
                    ->icon('heroicon-m-clipboard-document-list')
                    ->columns(2)
                    ->components([
                        TextInput::make('personal_background.allergies')
                            ->label('Allergies')
                            ->prefixIcon('heroicon-m-exclamation-triangle')
                            ->placeholder('e.g. Penicillin, shellfish / None known'),

                        TextInput::make('personal_background.surgeries')
                            ->label('Prior surgeries')
                            ->prefixIcon('heroicon-m-plus-circle')
                            ->placeholder('e.g. Appendectomy / None'),

                        TextInput::make('personal_background.current_medication')
                            ->label('Current medication')
                            ->prefixIcon('heroicon-m-beaker')
                            ->placeholder('e.g. Losartan 50mg / None')
                            ->columnSpanFull(),
                    ]),

                Section::make('Habits')
                    ->description('Tobacco and alcohol use.')
                    ->icon('heroicon-m-no-symbol')
                    ->columns(2)
                    ->components([
                        Toggle::make('personal_background.habits.tobacco')
                            ->label('Uses tobacco')
                            ->inline(false),

                        Select::make('personal_background.habits.alcohol')
                            ->label('Alcohol use')
                            ->options([
                                'never'      => 'Never',
                                'occasional' => 'Occasional',
                                'frequent'   => 'Frequent',
                            ])
                            ->native(false)
                            ->placeholder('Select frequency'),
                    ]),

                Section::make('Other History')
                    ->description('Flexible additional data by specialty (key → value).')
                    ->icon('heroicon-m-document-text')
                    ->collapsible()
                    ->collapsed()
                    ->components([
                        KeyValue::make('personal_background.other')
                            ->label('Other history')
                            ->keyLabel('Type')
                            ->valueLabel('Detail')
                            ->addActionLabel('Add entry')
                            ->reorderable()
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * Tab 4 — Family history (embedded RelationManager).
     * Edit-only (requires a persisted patient as ownerRecord).
     */
    protected static function familyHistoryTab(): Tab
    {
        return Tab::make('Family History')
            ->icon('heroicon-m-users')
            ->visible(fn (?Model $record): bool => filled($record))
            ->schema([
                Livewire::make(
                    FamilyBackgroundRelationManager::class,
                    fn (Model $record, $livewire): array => [
                        'ownerRecord' => $record,
                        'pageClass'   => $livewire::class,
                    ],
                )->key('patient-family-background-rm'),
            ]);
    }

    /**
     * Tab 5 — Structured allergies (embedded RelationManager).
     */
    protected static function allergiesTab(): Tab
    {
        return Tab::make('Allergies')
            ->icon('heroicon-m-exclamation-triangle')
            ->visible(fn (?Model $record): bool => filled($record))
            ->schema([
                Livewire::make(
                    AllergiesRelationManager::class,
                    fn (Model $record, $livewire): array => [
                        'ownerRecord' => $record,
                        'pageClass'   => $livewire::class,
                    ],
                )->key('patient-allergies-rm'),
            ]);
    }
}

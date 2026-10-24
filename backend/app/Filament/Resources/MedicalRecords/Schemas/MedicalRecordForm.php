<?php

namespace App\Filament\Resources\MedicalRecords\Schemas;

use App\Models\Cie10Code;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Set;

/**
 * MEDICAL RECORDS FORM CONFIGURATION
 * Built with Filament's standard Schema component.
 * Wires the clinical history information interactively and relationally.
 */
class MedicalRecordForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Relations section
                Section::make('Clinical Actors')
                    ->description('Associate the patient and the responsible physician for this clinical event.')
                    ->icon('heroicon-m-users')
                    ->columns(2)
                    ->components([
                        // Patient selection — pre-filled when coming from "Start consultation"
                        Select::make('patient_id')
                            ->relationship(name: 'patient', titleAttribute: 'name')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->default(fn () => request()->query('patient_id') ? (int) request()->query('patient_id') : null)
                            ->label('Patient'),

                        // Doctor selection — pre-filled from the doctor_id query param
                        Select::make('created_by')
                            ->relationship(name: 'doctor', titleAttribute: 'name')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->default(fn () => request()->query('doctor_id') ? (int) request()->query('doctor_id') : null)
                            ->label('Doctor'),
                    ]),

                // Vital signs section
                Section::make('Vital Signs')
                    ->description('Blood pressure, heart rate, temperature and other parameters measured during the visit.')
                    ->icon('heroicon-m-heart')
                    ->collapsible()
                    ->collapsed()
                    ->components([
                        Repeater::make('vitals')
                            ->relationship()
                            ->label('Measurements')
                            ->addActionLabel('Add vital signs reading')
                            ->defaultItems(0)
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => $state['recorded_at'] ?? 'New reading')
                            ->columns(4)
                            ->schema([
                                DateTimePicker::make('recorded_at')
                                    ->label('Date / Time')
                                    ->default(now())
                                    ->required()
                                    ->native(false)
                                    ->displayFormat('d/m/Y H:i')
                                    ->columnSpan(2),

                                TextInput::make('weight_kg')
                                    ->label('Weight (kg)')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(300)
                                    ->suffix('kg')
                                    ->nullable(),

                                TextInput::make('height_cm')
                                    ->label('Height (cm)')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(250)
                                    ->suffix('cm')
                                    ->nullable(),

                                TextInput::make('blood_pressure_sys')
                                    ->label('Systolic BP')
                                    ->numeric()
                                    ->minValue(50)
                                    ->maxValue(300)
                                    ->suffix('mmHg')
                                    ->nullable(),

                                TextInput::make('blood_pressure_dia')
                                    ->label('Diastolic BP')
                                    ->numeric()
                                    ->minValue(20)
                                    ->maxValue(200)
                                    ->suffix('mmHg')
                                    ->nullable(),

                                TextInput::make('heart_rate')
                                    ->label('Heart rate')
                                    ->numeric()
                                    ->minValue(20)
                                    ->maxValue(300)
                                    ->suffix('bpm')
                                    ->nullable(),

                                TextInput::make('temperature')
                                    ->label('Temperature')
                                    ->numeric()
                                    ->minValue(30)
                                    ->maxValue(45)
                                    ->suffix('°C')
                                    ->nullable(),

                                TextInput::make('oxygen_saturation')
                                    ->label('SpO2')
                                    ->numeric()
                                    ->minValue(50)
                                    ->maxValue(100)
                                    ->suffix('%')
                                    ->nullable(),

                                TextInput::make('respiratory_rate')
                                    ->label('Respiratory rate')
                                    ->numeric()
                                    ->minValue(5)
                                    ->maxValue(80)
                                    ->suffix('rpm')
                                    ->nullable(),

                                TextInput::make('bmi')
                                    ->label('BMI')
                                    ->numeric()
                                    ->minValue(5)
                                    ->maxValue(70)
                                    ->helperText('Calculated automatically or enter manually.')
                                    ->nullable(),
                            ]),
                    ]),

                // Clinical details section
                Section::make('Clinical Assessment & Treatment')
                    ->description('Provide the diagnostic data, symptomatology and prescribed plan.')
                    ->icon('heroicon-m-document-text')
                    ->columns(2)
                    ->components([
                        Textarea::make('reason_for_consultation')
                            ->required()
                            ->rows(3)
                            ->label('Reason for consultation'),

                        Textarea::make('current_illness')
                            ->nullable()
                            ->rows(3)
                            ->label('Current illness / History'),

                        Textarea::make('evolution')
                            ->nullable()
                            ->rows(3)
                            ->label('Evolution'),

                        Textarea::make('physical_examination')
                            ->nullable()
                            ->rows(3)
                            ->label('Physical examination'),

                        Textarea::make('review_of_systems')
                            ->nullable()
                            ->rows(3)
                            ->label('Review of systems'),

                        Textarea::make('diagnosis')
                            ->required()
                            ->rows(3)
                            ->label('Diagnosis'),

                        Textarea::make('treatment')
                            ->required()
                            ->rows(3)
                            ->label('Treatment / Prescription'),

                        Select::make('pain_scale')
                            ->label('Pain scale (0-10)')
                            ->options(collect(range(0, 10))->mapWithKeys(fn ($i) => [$i => "$i"]))
                            ->native(false)
                            ->nullable(),

                        TextInput::make('follow_up_date')
                            ->label('Follow-up date')
                            ->type('date')
                            ->nullable(),

                        Textarea::make('follow_up_notes')
                            ->label('Follow-up notes')
                            ->rows(2)
                            ->nullable()
                            ->columnSpanFull(),
                    ]),

                // Structured ICD-10 diagnoses section
                Section::make('ICD-10 Diagnoses')
                    ->description('Select diagnoses from the ICD-10 catalogue. You can adjust the description or enter a free-text code.')
                    ->icon('heroicon-m-clipboard-document-list')
                    ->collapsible()
                    ->components([
                        Repeater::make('diagnoses')
                            ->relationship()
                            ->label('Diagnoses')
                            ->addActionLabel('Add diagnosis')
                            ->defaultItems(0)
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string =>
                                ($state['cie10_code'] ? $state['cie10_code'] . ' — ' : '') . ($state['cie10_description'] ?? 'New diagnosis'))
                            ->columns(3)
                            ->schema([
                                Select::make('cie10_code')
                                    ->label('ICD-10 code')
                                    ->options(fn () => Cie10Code::selectOptions())
                                    ->searchable()
                                    ->nullable()
                                    ->live()
                                    ->afterStateUpdated(function ($state, Set $set): void {
                                        if ($state) {
                                            $code = Cie10Code::where('code', $state)->first();
                                            if ($code) {
                                                $set('cie10_description', $code->description);
                                            }
                                        }
                                    })
                                    ->placeholder('Search ICD-10 code...')
                                    ->columnSpan(1),

                                TextInput::make('cie10_description')
                                    ->label('Diagnosis description')
                                    ->required()
                                    ->maxLength(255)
                                    ->placeholder('Editable diagnosis description')
                                    ->columnSpan(2),

                                Select::make('type')
                                    ->label('Type')
                                    ->options([
                                        'primary'      => 'Primary',
                                        'secondary'    => 'Secondary',
                                        'presumptive'  => 'Presumptive',
                                    ])
                                    ->default('primary')
                                    ->native(false)
                                    ->required(),
                            ]),
                    ]),

                // Structured prescription section
                Section::make('Structured Prescription')
                    ->description('Detail each medication with dose, route and duration. The prescription PDF will use this format when available.')
                    ->icon('heroicon-m-beaker')
                    ->collapsible()
                    ->components([
                        Repeater::make('prescriptions')
                            ->relationship()
                            ->label('Medications')
                            ->addActionLabel('Add medication')
                            ->defaultItems(0)
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string =>
                                trim(($state['medication_name'] ?? '') . ' ' . ($state['concentration'] ?? '')) ?: 'New medication')
                            ->columns(3)
                            ->schema([
                                TextInput::make('medication_name')
                                    ->label('Medication')
                                    ->required()
                                    ->maxLength(255)
                                    ->placeholder('e.g. Amoxicillin, Ibuprofen, Metformin')
                                    ->columnSpan(2),

                                TextInput::make('concentration')
                                    ->label('Concentration')
                                    ->maxLength(100)
                                    ->placeholder('e.g. 500mg, 10mg/5ml')
                                    ->nullable(),

                                TextInput::make('dosage')
                                    ->label('Dose')
                                    ->required()
                                    ->maxLength(100)
                                    ->placeholder('e.g. 1 tablet, 5ml'),

                                Select::make('route')
                                    ->label('Administration route')
                                    ->options([
                                        'oral'        => 'Oral',
                                        'sublingual'  => 'Sublingual',
                                        'iv'          => 'Intravenous (IV)',
                                        'im'          => 'Intramuscular (IM)',
                                        'sc'          => 'Subcutaneous (SC)',
                                        'topical'     => 'Topical',
                                        'inhaled'     => 'Inhaled',
                                        'rectal'      => 'Rectal',
                                        'other'       => 'Other',
                                    ])
                                    ->default('oral')
                                    ->native(false)
                                    ->nullable(),

                                TextInput::make('frequency')
                                    ->label('Frequency')
                                    ->required()
                                    ->maxLength(100)
                                    ->placeholder('e.g. every 8 hours, once daily'),

                                TextInput::make('duration_days')
                                    ->label('Duration (days)')
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(365)
                                    ->suffix('days')
                                    ->nullable(),

                                // Same contract the API already enforces
                                // (StoreMedicalRecordRequest: required, min:1). Without
                                // this field the panel silently wrote NULL and the
                                // "Cantidad" column of the printed prescription came
                                // out blank.
                                TextInput::make('quantity')
                                    ->label('Quantity to dispense')
                                    ->required()
                                    ->numeric()
                                    ->minValue(1)
                                    ->helperText('Total the patient collects at the pharmacy. Printed on the prescription.'),

                                Textarea::make('instructions')
                                    ->label('Additional instructions')
                                    ->placeholder('e.g. Take with food, avoid sunlight...')
                                    ->rows(2)
                                    ->nullable()
                                    ->columnSpanFull(),

                                Toggle::make('is_controlled')
                                    ->label('Controlled substance')
                                    ->default(false)
                                    ->inline(false)
                                    ->helperText('Psychotropic, narcotic or other substance subject to special control.'),
                            ]),
                    ]),

                // Clinical evidence / findings (moved here from appointments).
                // Each item is stored in medical_attachments via the record's
                // attachments() relation. Supports drag & drop.
                Section::make('Clinical Findings & Evidence')
                    ->description('Attach X-rays, PDFs, lab results or other patient documents.')
                    ->icon('heroicon-m-document-arrow-up')
                    ->components([
                        Repeater::make('attachments')
                            ->relationship() // Persists to medical_attachments
                            ->label('Documents')
                            ->addActionLabel('Add evidence')
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => $state['title'] ?? 'New evidence')
                            ->columnSpanFull()
                            ->defaultItems(0)
                            ->schema([
                                TextInput::make('title')
                                    ->label('Finding name')
                                    ->placeholder('e.g. Chest X-ray, Blood test, Renal ultrasound')
                                    ->prefixIcon('heroicon-m-bookmark')
                                    ->maxLength(255),

                                // Drag & drop zone. Stored on a PRIVATE disk
                                // (PHI must not be reachable by guessable URL);
                                // existing files are retrieved through the
                                // authenticated medical-attachments.download route.
                                FileUpload::make('file_path')
                                    ->label('File / Document')
                                    ->disk('local')
                                    ->visibility('private')
                                    ->directory('medical-attachments')
                                    ->acceptedFileTypes([
                                        'application/pdf',
                                        'image/jpeg',
                                        'image/png',
                                        'image/webp',
                                    ])
                                    ->maxSize(20480), // 20 MB

                                Textarea::make('notes')
                                    ->label('Finding notes')
                                    ->placeholder('Quick notes about the test result...')
                                    ->rows(3)
                                    ->columnSpanFull(),
                            ])
                            // Existing files are served through the authenticated,
                            // workspace-scoped route (the upload itself is private).
                            ->extraItemActions([
                                Action::make('downloadAttachment')
                                    ->label('Download')
                                    ->icon('heroicon-m-arrow-down-tray')
                                    ->color('gray')
                                    ->visible(fn (array $arguments): bool => is_numeric($arguments['item'] ?? null))
                                    ->url(
                                        fn (array $arguments): ?string => is_numeric($arguments['item'] ?? null)
                                            ? route('medical-attachments.download', $arguments['item'])
                                            : null,
                                        shouldOpenInNewTab: true,
                                    ),
                            ]),
                    ]),
            ]);
    }
}

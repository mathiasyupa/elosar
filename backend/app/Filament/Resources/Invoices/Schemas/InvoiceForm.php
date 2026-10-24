<?php

namespace App\Filament\Resources\Invoices\Schemas;

use App\Models\MedicalService;
use App\Models\Patient;
use App\Rules\EcuadorianId;
use App\Support\ActiveWorkspace;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class InvoiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Invoice Details')
                    ->description('Patient, issue date and general notes.')
                    ->icon('heroicon-m-document-text')
                    ->columns(2)
                    ->components([
                        Select::make('patient_id')
                            ->label('Patient')
                            ->relationship(name: 'patient', titleAttribute: 'name')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(function ($state, Set $set): void {
                                if (! $state) {
                                    return;
                                }

                                $patient = Patient::find($state);
                                if (! $patient) {
                                    return;
                                }

                                // Auto-fill the buyer (comprador) from the patient.
                                $set('buyer_name', $patient->name);
                                $set('buyer_email', $patient->email);
                                $set('buyer_address', $patient->city);

                                if ($patient->id_number) {
                                    $set('buyer_id', $patient->id_number);
                                    // 13 digits → RUC (04), otherwise assume cédula (05).
                                    $set('buyer_id_type', strlen(preg_replace('/\D/', '', $patient->id_number)) === 13 ? '04' : '05');
                                }
                            }),

                        DatePicker::make('issued_at')
                            ->label('Issue Date')
                            ->required()
                            ->default(today())
                            ->native(false)
                            ->displayFormat('d/m/Y'),

                        Hidden::make('appointment_id'),

                        TextInput::make('discount_amount')
                            ->label('Global Discount ($)')
                            ->numeric()
                            ->default('0')
                            ->prefix('$')
                            ->minValue(0)
                            ->helperText('Additional discount at invoice level (applied to the subtotal).'),

                        Textarea::make('notes')
                            ->label('Notes')
                            ->nullable()
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),

                Section::make('Buyer (SRI)')
                    ->description('Fiscal data of the buyer for the electronic voucher. Auto-filled from the patient.')
                    ->icon('heroicon-m-identification')
                    ->columns(2)
                    ->collapsible()
                    ->collapsed()
                    ->components([
                        Select::make('buyer_id_type')
                            ->label('ID type')
                            ->options([
                                '05' => 'Cédula',
                                '04' => 'RUC',
                                '06' => 'Passport',
                                '07' => 'Final consumer',
                            ])
                            ->default('05')
                            ->native(false)
                            ->live()
                            ->required(),

                        TextInput::make('buyer_id')
                            ->label('Identification')
                            ->maxLength(20)
                            ->rule(fn (callable $get): ?EcuadorianId => match ($get('buyer_id_type')) {
                                '05'    => EcuadorianId::cedula(),
                                '04'    => EcuadorianId::ruc(),
                                default => null,
                            })
                            ->requiredUnless('buyer_id_type', '07'),

                        TextInput::make('buyer_name')
                            ->label('Name / Legal name')
                            ->maxLength(300)
                            ->requiredUnless('buyer_id_type', '07'),

                        TextInput::make('buyer_email')
                            ->label('Email')
                            ->email()
                            ->maxLength(255),

                        TextInput::make('buyer_address')
                            ->label('Address')
                            ->maxLength(300)
                            ->columnSpanFull(),
                    ]),

                Section::make('Services')
                    ->description('Line items that make up the invoice.')
                    ->icon('heroicon-m-list-bullet')
                    ->components([
                        Repeater::make('items')
                            ->label('Items')
                            ->minItems(1)
                            ->columns(2)
                            ->addActionLabel('Add item')
                            ->schema([
                                Select::make('medical_service_id')
                                    ->label('Medical service')
                                    ->options(
                                        fn () => MedicalService::query()
                                            ->where('is_active', true)
                                            ->orderBy('sort_order')
                                            ->orderBy('name')
                                            ->pluck('name', 'id')
                                    )
                                    ->nullable()
                                    ->searchable()
                                    ->live()
                                    ->afterStateUpdated(function ($state, Set $set): void {
                                        if ($state) {
                                            $service = MedicalService::find($state);
                                            if ($service) {
                                                $set('description', $service->name);
                                                $set('unit_price', $service->base_price);
                                                $set('iva_rate', (string) $service->iva_rate);
                                            }
                                        }
                                    })
                                    // ── Create service on the fly ────────────────────────────
                                    ->createOptionModalHeading('Create new medical service')
                                    ->createOptionForm([
                                        TextInput::make('name')
                                            ->label('Service name')
                                            ->required()
                                            ->maxLength(150)
                                            ->placeholder('e.g. General consultation, Abdominal ultrasound'),

                                        Textarea::make('description')
                                            ->label('Description (optional)')
                                            ->nullable()
                                            ->rows(2),

                                        TextInput::make('base_price')
                                            ->label('Base price')
                                            ->required()
                                            ->numeric()
                                            ->prefix('$')
                                            ->minValue(0)
                                            ->placeholder('0.00'),

                                        Toggle::make('is_active')
                                            ->label('Active')
                                            ->default(true)
                                            ->inline(false),
                                    ])
                                    ->createOptionUsing(function (array $data): int {
                                        $workspaceId = app(ActiveWorkspace::class)->id();

                                        $service = MedicalService::create([
                                            'workspace_id' => $workspaceId,
                                            'name'         => $data['name'],
                                            'description'  => $data['description'] ?? null,
                                            'base_price'   => $data['base_price'],
                                            'iva_rate'     => $data['iva_rate'] ?? '0',
                                            'is_active'    => $data['is_active'] ?? true,
                                            'sort_order'   => 0,
                                        ]);

                                        return $service->id;
                                    })
                                    ->columnSpanFull(),

                                TextInput::make('description')
                                    ->label('Description')
                                    ->required()
                                    ->live()
                                    ->columnSpanFull(),

                                TextInput::make('quantity')
                                    ->label('Quantity')
                                    ->required()
                                    ->numeric()
                                    ->default('1')
                                    ->minValue(1)
                                    ->live(),

                                TextInput::make('unit_price')
                                    ->label('Unit price')
                                    ->required()
                                    ->numeric()
                                    ->prefix('$')
                                    ->minValue(0)
                                    ->live(),

                                TextInput::make('discount_percentage')
                                    ->label('Discount')
                                    ->numeric()
                                    ->default('0')
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->suffix('%')
                                    ->live()
                                    ->helperText('Item-level discount percentage (0 = no discount).'),
                            ])
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}

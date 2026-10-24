<?php

namespace App\Filament\Resources\Workspaces\Schemas;

use App\Rules\EcuadorianId;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Workspace management form.
 */
class WorkspaceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Workspace Details')
                    ->description('Workspace: personal practice of a doctor or a clinic organization.')
                    ->icon('heroicon-m-building-office-2')
                    ->columns(2)
                    ->components([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->prefixIcon('heroicon-m-building-office-2')
                            ->label('Name')
                            ->placeholder('e.g. Dr. García\'s Practice / Pichincha Clinic'),

                        Select::make('type')
                            ->options([
                                'personal' => 'Personal (practice)',
                                'clinic' => 'Clinic (organization)',
                            ])
                            ->required()
                            ->native(false)
                            ->live()
                            ->label('Type'),

                        // invite_code only applies to clinics and is managed through
                        // the "Regenerate" action — read-only here.
                        TextInput::make('invite_code')
                            ->label('Invite code')
                            ->prefixIcon('heroicon-m-key')
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(fn ($get) => $get('type') === 'clinic')
                            ->placeholder('Auto-generated for clinics'),

                        Select::make('owner_doctor_id')
                            ->relationship(name: 'owner', titleAttribute: 'name')
                            ->searchable()
                            ->preload()
                            ->prefixIcon('heroicon-m-user')
                            ->label('Owner doctor'),
                    ]),

                // PRESCRIPTION LETTERHEAD / FORMAT
                // What the doctor or clinic wants printed on their prescription PDFs.
                Section::make('Prescription Letterhead')
                    ->description('Logo and header/footer data to be printed on prescription PDFs.')
                    ->icon('heroicon-m-document-text')
                    ->columns(2)
                    ->components([
                        Select::make('prescription_settings.header_mode')
                            ->label('Header style')
                            ->helperText('Digital: the system draws the header from the fields below. Image: upload your own pre-designed letterhead banner.')
                            ->options([
                                'digital' => 'Digital (text fields)',
                                'image' => 'Image (uploaded banner)',
                            ])
                            ->default('digital')
                            ->native(false)
                            ->columnSpanFull(),

                        FileUpload::make('header_image_path')
                            ->label('Letterhead banner (image)')
                            ->helperText('Used when Header style = Image. Logo + contact + line baked into one image.')
                            ->image()
                            ->disk('local')
                            ->visibility('private')
                            ->directory('letterheads')
                            ->imageEditor()
                            ->columnSpanFull(),

                        FileUpload::make('logo_path')
                            ->label('Logo')
                            ->image()
                            ->disk('local')
                            ->visibility('private')
                            ->directory('letterheads')
                            ->imageEditor()
                            ->columnSpanFull(),

                        TextInput::make('prescription_settings.header')
                            ->label('Header')
                            ->placeholder('e.g. Metropolitan Clinic / Dr. Gregory House')
                            ->maxLength(255),

                        TextInput::make('prescription_settings.subheader')
                            ->label('Subtitle')
                            ->placeholder('e.g. Internal Medicine · RUC 1790000000001')
                            ->maxLength(255),

                        TextInput::make('prescription_settings.address')
                            ->label('Address')
                            ->placeholder('e.g. Av. Amazonas N34-100 y Atahualpa, Quito')
                            ->maxLength(255),

                        TextInput::make('prescription_settings.phone')
                            ->label('Phone')
                            ->placeholder('e.g. +593 2 222 3333')
                            ->maxLength(255),

                        TextInput::make('prescription_settings.email')
                            ->label('Email')
                            ->placeholder('e.g. contact@clinic.com')
                            ->maxLength(255),

                        TextInput::make('prescription_settings.issue_city')
                            ->label('Issuing place')
                            ->helperText('Canton/parish printed before the date on prescriptions, e.g. "Rumiñahui".')
                            ->placeholder('e.g. Rumiñahui')
                            ->maxLength(120),

                        Textarea::make('prescription_settings.footer')
                            ->label('Footer')
                            ->placeholder('e.g. Office hours, social media, legal disclaimer...')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),

                // FISCAL IDENTITY (SRI)
                // Legal data of the issuer required on every electronic voucher.
                Section::make('Fiscal Identity (SRI)')
                    ->description('Legal data of the issuer used on electronic vouchers (Ecuador / SRI).')
                    ->icon('heroicon-m-identification')
                    ->columns(2)
                    ->collapsible()
                    ->collapsed()
                    ->components([
                        TextInput::make('ruc')
                            ->label('RUC')
                            ->placeholder('1790000000001')
                            ->rule(EcuadorianId::ruc())
                            ->maxLength(13)
                            ->helperText('13-digit taxpayer ID.'),

                        TextInput::make('razon_social')
                            ->label('Legal name (razón social)')
                            ->maxLength(300),

                        TextInput::make('nombre_comercial')
                            ->label('Trade name (nombre comercial)')
                            ->maxLength(300),

                        TextInput::make('contribuyente_especial')
                            ->label('Special taxpayer resolution #')
                            ->maxLength(13)
                            ->helperText('Leave empty if not a special taxpayer.'),

                        Textarea::make('dir_matriz')
                            ->label('Head-office address (dir. matriz)')
                            ->rows(2)
                            ->columnSpanFull(),

                        Textarea::make('dir_establecimiento')
                            ->label('Establishment address')
                            ->rows(2)
                            ->helperText('Leave empty to reuse the head-office address.')
                            ->columnSpanFull(),

                        TextInput::make('cod_establecimiento')
                            ->label('Establishment code')
                            ->default('001')
                            ->maxLength(3)
                            ->placeholder('001'),

                        TextInput::make('pto_emision')
                            ->label('Emission point')
                            ->default('001')
                            ->maxLength(3)
                            ->placeholder('001'),

                        Toggle::make('obligado_contabilidad')
                            ->label('Required to keep accounting')
                            ->inline(false),

                        TextInput::make('acess_establishment_code')
                            ->label('ACESS code (UNICÓDIGO)')
                            ->maxLength(50)
                            ->helperText('Health establishment permit. Informational.'),

                        TextInput::make('senescyt_note')
                            ->label('SENESCYT note')
                            ->maxLength(200)
                            ->helperText('Informational only — no live integration.')
                            ->columnSpanFull(),
                    ]),

                // ELECTRONIC BILLING (SRI)
                // Certificate + environment toggle that enables XAdES signing and
                // automatic authorization of issued vouchers.
                Section::make('Electronic Billing (SRI)')
                    ->description('Digital certificate and environment to authorize vouchers with the SRI.')
                    ->icon('heroicon-m-shield-check')
                    ->columns(2)
                    ->collapsible()
                    ->collapsed()
                    ->components([
                        Toggle::make('sri_enabled')
                            ->label('Enable electronic invoicing')
                            ->helperText('When on, issued invoices are sent to the SRI for authorization.')
                            ->inline(false)
                            ->columnSpanFull(),

                        Select::make('sri_environment')
                            ->label('Environment')
                            ->options([
                                'test' => 'Test (pruebas)',
                                'production' => 'Production',
                            ])
                            ->default('test')
                            ->native(false)
                            ->required(),

                        FileUpload::make('sri_certificate_path')
                            ->label('Signing certificate (.p12 / .pfx)')
                            ->disk('local')
                            ->directory('sri-certificates')
                            ->visibility('private')
                            ->acceptedFileTypes([
                                'application/x-pkcs12',
                                'application/pkcs12',
                                'application/octet-stream',
                            ])
                            ->helperText('BCE digital certificate. Stored privately, never exposed.')
                            ->columnSpanFull(),

                        TextInput::make('sri_certificate_password')
                            ->label('Certificate password')
                            ->password()
                            ->revealable()
                            ->maxLength(255)
                            ->helperText('Encrypted at rest.')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}

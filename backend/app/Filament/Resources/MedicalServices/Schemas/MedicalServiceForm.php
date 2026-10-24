<?php

namespace App\Filament\Resources\MedicalServices\Schemas;

use App\Models\MedicalService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MedicalServiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Service Details')
                    ->description('Information about the medical service offered at the practice.')
                    ->icon('heroicon-m-briefcase')
                    ->columns(2)
                    ->components([
                        TextInput::make('name')
                            ->label('Service name')
                            ->required()
                            ->maxLength(150)
                            ->columnSpanFull(),

                        Textarea::make('description')
                            ->label('Description')
                            ->nullable()
                            ->rows(3)
                            ->columnSpanFull(),

                        // Stored in English, displayed in English
                        Select::make('category')
                            ->label('Category')
                            ->options(MedicalService::CATEGORIES)
                            ->default('general')
                            ->required()
                            ->native(false),

                        TextInput::make('base_price')
                            ->label('Base price')
                            ->required()
                            ->numeric()
                            ->prefix('$')
                            ->minValue(0),

                        Select::make('iva_rate')
                            ->label('VAT')
                            ->required()
                            ->options([
                                '0'  => 'Exempt (0%)',
                                '15' => 'VAT 15%',
                            ])
                            ->native(false),

                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->inline(false),

                        TextInput::make('sort_order')
                            ->label('Display order')
                            ->numeric()
                            ->default(0),
                    ]),
            ]);
    }
}

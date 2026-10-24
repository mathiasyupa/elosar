<?php

namespace App\Filament\Resources\MedicalServices\Pages;

use App\Filament\Resources\MedicalServices\MedicalServiceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMedicalServices extends ListRecords
{
    protected static string $resource = MedicalServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

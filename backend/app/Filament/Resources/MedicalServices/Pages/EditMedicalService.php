<?php

namespace App\Filament\Resources\MedicalServices\Pages;

use App\Filament\Resources\MedicalServices\MedicalServiceResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMedicalService extends EditRecord
{
    protected static string $resource = MedicalServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

<?php

namespace App\Filament\Resources\MedicalRecords\Pages;

use App\Filament\Actions\GeneratePrescriptionAction;
use App\Filament\Resources\MedicalRecords\MedicalRecordResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMedicalRecord extends EditRecord
{
    protected static string $resource = MedicalRecordResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GeneratePrescriptionAction::make(),
            DeleteAction::make(),
        ];
    }
}

<?php

namespace App\Filament\Resources\Patients\Pages;

use App\Filament\Resources\Patients\PatientResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPatient extends EditRecord
{
    protected static string $resource = PatientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('account')
                ->label('Account statement')
                ->icon('heroicon-m-banknotes')
                ->color('info')
                ->url(fn () => PatientResource::getUrl('account', ['record' => $this->getRecord()])),

            DeleteAction::make(),
        ];
    }
}

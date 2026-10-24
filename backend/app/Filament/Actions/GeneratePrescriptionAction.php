<?php

namespace App\Filament\Actions;

use App\Models\MedicalRecord;
use App\Services\PrescriptionService;
use Filament\Actions\Action;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Filament "Generate prescription" action: takes a medical record, builds the PDF
 * with the workspace letterhead and the doctor's signature, and downloads it.
 *
 * Exposed as a static factory so it can be reused in the table and the medical
 * record edit page without duplicating configuration.
 */
class GeneratePrescriptionAction
{
    public static function make(string $name = 'prescription'): Action
    {
        return Action::make($name)
            ->label('Generate prescription')
            ->icon('heroicon-m-document-text')
            ->color('success')
            ->action(function (MedicalRecord $record): StreamedResponse {
                $pdf = app(PrescriptionService::class)->pdf($record);

                $fileName = 'receta-' . $record->id . '.pdf';

                return response()->streamDownload(
                    fn () => print($pdf->output()),
                    $fileName,
                    ['Content-Type' => 'application/pdf'],
                );
            });
    }
}

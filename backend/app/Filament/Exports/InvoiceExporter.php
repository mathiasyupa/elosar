<?php

namespace App\Filament\Exports;

use App\Models\Invoice;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class InvoiceExporter extends Exporter
{
    protected static ?string $model = Invoice::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('invoice_number')->label('Invoice #'),
            ExportColumn::make('patient.name')->label('Patient'),
            ExportColumn::make('issued_at')->label('Issue date'),
            ExportColumn::make('status')
                ->label('Status')
                ->formatStateUsing(fn (string $state): string => match ($state) {
                    'draft'     => 'Draft',
                    'issued'    => 'Issued',
                    'partial'   => 'Partial',
                    'paid'      => 'Paid',
                    'cancelled' => 'Cancelled',
                    'void'      => 'Void',
                    default     => $state,
                }),
            ExportColumn::make('subtotal')->label('Subtotal'),
            ExportColumn::make('total')->label('Total'),
            ExportColumn::make('amount_paid')->label('Paid'),
            ExportColumn::make('amount_due')
                ->label('Receivable')
                ->state(fn (Invoice $record): string => bcsub(
                    (string) $record->total,
                    (string) $record->amount_paid,
                    2
                )),
            ExportColumn::make('notes')->label('Notes'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $count = $export->successful_rows;

        return "Successfully exported {$count} " . str('invoice')->plural($count) . '.';
    }
}

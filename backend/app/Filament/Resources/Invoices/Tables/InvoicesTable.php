<?php

namespace App\Filament\Resources\Invoices\Tables;

use App\Filament\Exports\InvoiceExporter;
use App\Services\Sri\SriService;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ExportAction;
use Filament\Actions\ViewAction;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('invoice_number')
                    ->label('Invoice #')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('patient.name')
                    ->label('Patient')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('issued_at')
                    ->label('Issued')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('total')
                    ->label('Total')
                    ->formatStateUsing(fn ($state): string => '$' . number_format((float) $state, 2))
                    ->sortable(),

                TextColumn::make('amount_paid')
                    ->label('Paid')
                    ->formatStateUsing(fn ($state): string => '$' . number_format((float) $state, 2)),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'draft'     => 'gray',
                        'issued'    => 'primary',
                        'partial'   => 'warning',
                        'paid'      => 'success',
                        'cancelled' => 'gray',
                        'void'      => 'danger',
                        default     => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'draft'     => 'Draft',
                        'issued'    => 'Issued',
                        'partial'   => 'Partial',
                        'paid'      => 'Paid',
                        'cancelled' => 'Cancelled',
                        'void'      => 'Void',
                        default     => $state,
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'draft'     => 'Draft',
                        'issued'    => 'Issued',
                        'partial'   => 'Partial',
                        'paid'      => 'Paid',
                        'cancelled' => 'Cancelled',
                        'void'      => 'Void',
                    ]),
            ])
            ->headerActions([
                ExportAction::make()
                    ->label('Export')
                    ->exporter(InvoiceExporter::class)
                    ->formats([
                        ExportFormat::Csv,
                        ExportFormat::Xlsx,
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('resendSri')
                        ->label('Resend to SRI')
                        ->icon('heroicon-m-paper-airplane')
                        ->color('primary')
                        ->requiresConfirmation()
                        ->modalDescription('Re-queue the selected invoices for SRI authorization.')
                        ->action(function (Collection $records): void {
                            $sri = app(SriService::class);

                            $queued = $records
                                ->filter(fn ($record): bool => (bool) $record->workspace?->canIssueSriVouchers())
                                ->reduce(fn (int $carry, $record): int => $carry + (int) $sri->resend($record), 0);

                            Notification::make()
                                ->title("{$queued} invoice(s) queued for SRI authorization.")
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

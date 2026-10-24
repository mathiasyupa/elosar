<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Jobs\AuthorizeSriVoucherJob;
use App\Models\Invoice;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use Filament\Actions\Action;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Invoice Details')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('invoice_number')
                            ->label('Invoice #')
                            ->weight('bold'),

                        TextEntry::make('patient.name')
                            ->label('Patient'),

                        TextEntry::make('issued_at')
                            ->label('Issue Date')
                            ->date('d/m/Y'),

                        TextEntry::make('status')
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
                                'partial'   => 'Partial payment',
                                'paid'      => 'Paid',
                                'cancelled' => 'Cancelled',
                                'void'      => 'Void',
                                default     => $state,
                            }),

                        TextEntry::make('notes')
                            ->label('Notes')
                            ->columnSpanFull()
                            ->placeholder('No notes'),
                    ]),

                Section::make('Totals')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('subtotal')
                            ->label('Subtotal')
                            ->formatStateUsing(fn ($state): string => '$' . number_format((float) $state, 2)),

                        TextEntry::make('total')
                            ->label('Total')
                            ->formatStateUsing(fn ($state): string => '$' . number_format((float) $state, 2))
                            ->weight('bold'),

                        TextEntry::make('amount_paid')
                            ->label('Paid')
                            ->formatStateUsing(fn ($state): string => '$' . number_format((float) $state, 2)),

                        TextEntry::make('amount_due')
                            ->label('Receivable')
                            ->formatStateUsing(fn ($state): string => '$' . number_format((float) $state, 2))
                            ->color(fn ($state): string => $state > 0 ? 'warning' : 'success'),
                    ]),

                Section::make('SRI Electronic Invoice')
                    ->columns(3)
                    ->visible(fn (Invoice $record): bool => (bool) $record->workspace?->sri_enabled)
                    ->schema([
                        TextEntry::make('sri_status')
                            ->label('SRI status')
                            ->badge()
                            ->placeholder('Not sent')
                            ->color(fn (?string $state): string => match ($state) {
                                'authorized' => 'success',
                                'pending'    => 'warning',
                                'rejected'   => 'danger',
                                default      => 'gray',
                            })
                            ->formatStateUsing(fn (?string $state): string => match ($state) {
                                'authorized' => 'Authorized',
                                'pending'    => 'Pending',
                                'rejected'   => 'Rejected',
                                default      => 'Not sent',
                            }),

                        TextEntry::make('sri_sequence_number')
                            ->label('Voucher #')
                            ->placeholder('—'),

                        TextEntry::make('sri_authorization_date')
                            ->label('Authorized at')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),

                        TextEntry::make('sri_clave_acceso')
                            ->label('Access key')
                            ->copyable()
                            ->placeholder('—')
                            ->columnSpanFull(),

                        TextEntry::make('sri_authorization_number')
                            ->label('Authorization #')
                            ->copyable()
                            ->placeholder('—')
                            ->columnSpanFull(),

                        TextEntry::make('sri_last_error')
                            ->label('Last SRI message')
                            ->color('danger')
                            ->visible(fn (Invoice $record): bool => filled($record->sri_last_error))
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        /** @var Invoice $invoice */
        $invoice = $this->getRecord();

        return [
            Action::make('downloadPdf')
                ->label('Download PDF')
                ->icon('heroicon-m-arrow-down-tray')
                ->color('gray')
                ->action(function (Invoice $record): StreamedResponse {
                    $pdf      = app(InvoiceService::class)->pdf($record);
                    $fileName = 'invoice-' . $record->invoice_number . '.pdf';

                    return response()->streamDownload(
                        fn () => print($pdf->output()),
                        $fileName,
                        ['Content-Type' => 'application/pdf'],
                    );
                }),

            Action::make('sendToSri')
                ->label('Send to SRI')
                ->icon('heroicon-m-paper-airplane')
                ->color('primary')
                ->visible(fn (Invoice $record): bool =>
                    (bool) $record->workspace?->canIssueSriVouchers()
                    && in_array($record->sri_status, [null, 'rejected', 'pending'], true))
                ->requiresConfirmation()
                ->modalDescription('Queue this invoice for SRI authorization.')
                ->action(function (Invoice $record): void {
                    AuthorizeSriVoucherJob::dispatch($record);

                    Notification::make()
                        ->title('Invoice queued for SRI authorization.')
                        ->success()
                        ->send();

                    $this->refreshFormData(['sri_status']);
                }),

            Action::make('downloadRide')
                ->label('Download RIDE')
                ->icon('heroicon-m-document-check')
                ->color('success')
                ->visible(fn (Invoice $record): bool => $record->isSriAuthorized())
                ->action(function (Invoice $record): StreamedResponse {
                    $pdf      = app(InvoiceService::class)->pdf($record);
                    $fileName = 'ride-' . ($record->sri_sequence_number ?: $record->invoice_number) . '.pdf';

                    return response()->streamDownload(
                        fn () => print($pdf->output()),
                        $fileName,
                        ['Content-Type' => 'application/pdf'],
                    );
                }),

            Action::make('registerPayment')
                ->label('Register Payment')
                ->icon('heroicon-m-banknotes')
                ->color('success')
                ->visible(fn (Invoice $record): bool => ! $record->isPaid() && ! $record->isCancelled())
                ->form([
                    TextInput::make('amount')
                        ->label('Amount')
                        ->required()
                        ->numeric()
                        ->prefix('$')
                        ->minValue(0.01),

                    Select::make('payment_method')
                        ->label('Payment method')
                        ->required()
                        ->options([
                            'cash'     => 'Cash',
                            'transfer' => 'Transfer',
                            'card'     => 'Card',
                            'other'    => 'Other',
                        ])
                        ->native(false),

                    DatePicker::make('payment_date')
                        ->label('Payment date')
                        ->required()
                        ->default(today())
                        ->native(false)
                        ->displayFormat('d/m/Y'),

                    TextInput::make('reference')
                        ->label('Reference')
                        ->nullable(),

                    Textarea::make('notes')
                        ->label('Notes')
                        ->nullable()
                        ->rows(2),
                ])
                ->action(function (array $data, Invoice $record): void {
                    app(PaymentService::class)->register($record, $data);

                    Notification::make()
                        ->title('Payment registered successfully.')
                        ->success()
                        ->send();

                    $this->refreshFormData(['status', 'amount_paid']);
                }),

            Action::make('cancelInvoice')
                ->label('Cancel Invoice')
                ->icon('heroicon-m-x-circle')
                ->color('danger')
                ->visible(
                    fn (Invoice $record): bool =>
                        $record->status === 'issued'
                        && ! $record->payments()->exists()
                )
                ->requiresConfirmation()
                ->form([
                    TextInput::make('cancellation_reason')
                        ->label('Cancellation reason')
                        ->required(),
                ])
                ->action(function (array $data, Invoice $record): void {
                    app(InvoiceService::class)->cancel($record, $data['cancellation_reason']);

                    Notification::make()
                        ->title('Invoice cancelled.')
                        ->warning()
                        ->send();

                    $this->refreshFormData(['status', 'cancelled_at']);
                }),
        ];
    }
}

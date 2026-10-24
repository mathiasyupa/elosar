<?php

namespace App\Filament\Resources\CreditNotes;

use App\Filament\Resources\CreditNotes\Pages\ListCreditNotes;
use App\Models\CreditNote;
use App\Services\Sri\SriService;
use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

/**
 * Filament resource for credit notes.
 *
 * Read-only view: notes are generated automatically by
 * InvoiceService::void() when an invoice with payments is voided.
 */
class CreditNoteResource extends Resource
{
    protected static ?string $model = CreditNote::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptRefund;

    protected static ?string $navigationLabel = 'Credit Notes';

    protected static string|\UnitEnum|null $navigationGroup = 'Billing';

    protected static ?string $modelLabel = 'Credit Note';

    protected static ?string $pluralModelLabel = 'Credit Notes';

    protected static ?string $recordTitleAttribute = 'credit_note_number';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('credit_note_number')
                    ->label('Credit Note #')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('invoice.invoice_number')
                    ->label('Source Invoice')
                    ->searchable()
                    ->url(fn (CreditNote $record): string =>
                        route('filament.admin.resources.invoices.view', ['record' => $record->invoice_id])
                    )
                    ->color('primary'),

                TextColumn::make('invoice.patient.name')
                    ->label('Patient')
                    ->searchable(),

                TextColumn::make('amount')
                    ->label('Amount')
                    ->formatStateUsing(fn ($state): string => '$' . number_format((float) $state, 2))
                    ->sortable(),

                TextColumn::make('issued_at')
                    ->label('Date')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'issued'  => 'warning',
                        'applied' => 'success',
                        default   => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'issued'  => 'Issued',
                        'applied' => 'Applied',
                        default   => $state,
                    }),

                TextColumn::make('reason')
                    ->label('Reason')
                    ->limit(40)
                    ->tooltip(fn (CreditNote $record): string => $record->reason),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([])
            ->recordActions([])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('resendSri')
                        ->label('Resend to SRI')
                        ->icon('heroicon-m-paper-airplane')
                        ->color('primary')
                        ->requiresConfirmation()
                        ->modalDescription('Re-queue the selected credit notes for SRI authorization.')
                        ->action(function (Collection $records): void {
                            $sri = app(SriService::class);

                            $queued = $records
                                ->filter(fn (CreditNote $record): bool => (bool) $record->workspace?->canIssueSriVouchers())
                                ->reduce(fn (int $carry, CreditNote $record): int => $carry + (int) $sri->resend($record), 0);

                            Notification::make()
                                ->title("{$queued} credit note(s) queued for SRI authorization.")
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCreditNotes::route('/'),
        ];
    }

    /**
     * Solo lectura: no permitir crear ni editar desde Filament.
     */
    public static function canCreate(): bool
    {
        return false;
    }
}

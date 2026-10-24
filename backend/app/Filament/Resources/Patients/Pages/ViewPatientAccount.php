<?php

namespace App\Filament\Resources\Patients\Pages;

use App\Filament\Resources\Patients\PatientResource;
use App\Models\Invoice;
use App\Models\Patient;
use App\Support\AccessLogger;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ViewPatientAccount extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = PatientResource::class;

    protected string $view = 'filament.pages.patient-account';

    protected static ?string $title = 'Account Statement';

    public Patient $record;

    public function mount(Patient $record): void
    {
        $this->record = $record;

        // Read-access audit: record who viewed this patient's file.
        app(AccessLogger::class)->log('patient', $record->id);
    }

    public function getTableQuery(): Builder
    {
        return Invoice::where('patient_id', $this->record->id)
            ->orderByDesc('issued_at');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getTableQuery())
            ->columns([
                TextColumn::make('invoice_number')
                    ->label('Invoice #')
                    ->weight('bold'),

                TextColumn::make('issued_at')
                    ->label('Issued')
                    ->date('d/m/Y'),

                TextColumn::make('total')
                    ->label('Total')
                    ->formatStateUsing(fn ($state): string => '$' . number_format((float) $state, 2)),

                TextColumn::make('amount_paid')
                    ->label('Paid')
                    ->formatStateUsing(fn ($state): string => '$' . number_format((float) $state, 2)),

                TextColumn::make('amount_due')
                    ->label('Receivable')
                    ->formatStateUsing(fn ($state): string => '$' . number_format((float) $state, 2))
                    ->color(fn ($state): string => $state > 0 ? 'danger' : 'success'),

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
            ->paginated(false);
    }

    public function getTotalInvoiced(): float
    {
        return (float) Invoice::where('patient_id', $this->record->id)
            ->whereNotIn('status', ['cancelled', 'void'])
            ->sum('total');
    }

    public function getTotalPaid(): float
    {
        return (float) Invoice::where('patient_id', $this->record->id)
            ->whereNotIn('status', ['cancelled', 'void'])
            ->sum('amount_paid');
    }

    public function getTotalDue(): float
    {
        return $this->getTotalInvoiced() - $this->getTotalPaid();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('← Back to patient')
                ->url(PatientResource::getUrl('edit', ['record' => $this->record]))
                ->color('gray'),
        ];
    }
}

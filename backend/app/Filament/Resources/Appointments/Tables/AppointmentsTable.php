<?php

namespace App\Filament\Resources\Appointments\Tables;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\MedicalRecords\MedicalRecordResource;
use App\Models\Appointment;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * APPOINTMENTS LIST TABLE CONFIGURATION
 * Modular class that renders the medical schedule in tabular format.
 */
class AppointmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                // Patient name through relation
                TextColumn::make('patient.name')
                    ->searchable()
                    ->sortable()
                    ->label('Patient'),

                // Doctor name through relation
                TextColumn::make('doctor.name')
                    ->searchable()
                    ->sortable()
                    ->label('Doctor'),

                // Appointment date and time
                TextColumn::make('appointment_date')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->label('Date & Time'),

                // Appointment status rendered as a badge
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'scheduled' => 'info',
                        'completed' => 'success',
                        'cancelled' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'scheduled' => 'Scheduled',
                        'completed' => 'Completed',
                        'cancelled' => 'Cancelled',
                        default => $state,
                    })
                    ->sortable()
                    ->label('Status'),

                // Internal creation timestamp (hidden by default)
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('quickView')
                    ->label('Quick View')
                    ->icon('heroicon-m-user-circle')
                    ->color('gray')
                    ->modalHeading(fn (Appointment $record): string => 'Quick view — ' . $record->patient?->name)
                    ->modalContent(function (Appointment $record): \Illuminate\Contracts\View\View {
                        $patient = $record->patient?->load('allergies');
                        $lastRecord = $patient?->medicalRecords()->latest()->first();

                        return view('filament.modals.patient-quick-view', compact('patient', 'lastRecord'));
                    })
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),

                Action::make('startConsultation')
                    ->label('Start consultation')
                    ->icon('heroicon-m-clipboard-document-check')
                    ->color('primary')
                    ->visible(
                        fn (Appointment $record): bool =>
                            $record->status === 'completed' &&
                            ! $record->medicalRecords()->exists()
                    )
                    ->url(
                        fn (Appointment $record): string =>
                            MedicalRecordResource::getUrl('create') . '?' . http_build_query([
                                'appointment_id' => $record->id,
                                'patient_id'     => $record->patient_id,
                                'doctor_id'      => $record->doctor_id,
                            ])
                    ),

                EditAction::make(),
                Action::make('generateInvoice')
                    ->label('Invoice')
                    ->icon('heroicon-o-document-text')
                    ->color('success')
                    ->visible(
                        fn ($record): bool =>
                            $record->status === 'completed' &&
                            ! $record->invoice()->exists()
                    )
                    ->url(
                        fn ($record): string =>
                            InvoiceResource::getUrl('create') . '?' . http_build_query([
                                'patient_id'     => $record->patient_id,
                                'appointment_id' => $record->id,
                            ])
                    ),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

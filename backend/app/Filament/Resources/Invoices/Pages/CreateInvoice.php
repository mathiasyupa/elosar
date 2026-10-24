<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Services\InvoiceService;
use App\Support\ActiveWorkspace;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateInvoice extends CreateRecord
{
    protected static string $resource = InvoiceResource::class;

    public function mount(): void
    {
        parent::mount();

        if (request()->has('patient_id') || request()->has('appointment_id')) {
            $this->form->fill([
                'patient_id'     => request()->integer('patient_id') ?: null,
                'appointment_id' => request()->integer('appointment_id') ?: null,
            ]);
        }
    }

    /**
     * Delegates invoice creation to InvoiceService so everything is computed
     * correctly: subtotal, IVA, total, invoice number and item relations.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['workspace_id'] = app(ActiveWorkspace::class)->id();
        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        return app(InvoiceService::class)->create($data);
    }
}

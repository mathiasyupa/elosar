<?php

namespace App\Services;

use App\Data\PaymentData;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Validation\ValidationException;

/**
 * Business logic for registering and deleting payments.
 *
 * After each operation it delegates to InvoiceService::recalculate()
 * to keep the invoice's amount_paid and status in sync.
 */
class PaymentService
{
    public function __construct(
        private readonly InvoiceService $invoiceService,
    ) {}

    /**
     * Registers a payment against an invoice.
     *
     * Accepts a typed PaymentData (preferred, used by the API via
     * StorePaymentRequest) or the equivalent snake_case array (Filament and
     * existing callers).
     *
     * @throws ValidationException when the invoice is cancelled/voided,
     *                             the amount is <= 0, or it exceeds the amount due.
     */
    public function register(Invoice $invoice, PaymentData|array $data): Payment
    {
        $data = ($data instanceof PaymentData ? $data : PaymentData::fromArray($data))->toArray();

        if ($invoice->isCancelled()) {
            throw ValidationException::withMessages([
                'invoice' => 'No se pueden registrar pagos en una factura cancelada o anulada.',
            ]);
        }

        $amount = (string) $data['amount'];

        if (bccomp($amount, '0.00', 2) <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'El monto del pago debe ser mayor a cero.',
            ]);
        }

        $amountDue = (string) $invoice->amount_due;

        if (bccomp($amount, $amountDue, 2) > 0) {
            throw ValidationException::withMessages([
                'amount' => "El monto no puede superar el saldo pendiente ({$amountDue}).",
            ]);
        }

        $payment = Payment::create([
            'invoice_id'     => $invoice->id,
            'workspace_id'   => $invoice->workspace_id,
            'amount'         => $amount,
            'payment_method' => $data['payment_method'],
            'payment_date'   => $data['payment_date'],
            'reference'      => $data['reference'] ?? null,
            'notes'          => $data['notes'] ?? null,
            'registered_by'  => $data['registered_by'] ?? null,
            'registered_by_user_id' => $data['registered_by_user_id'] ?? null,
        ]);

        $this->invoiceService->recalculate($invoice);

        return $payment;
    }

    /**
     * Deletes a payment and recalculates the related invoice.
     */
    public function delete(Payment $payment): void
    {
        $invoice = $payment->invoice;

        $payment->delete();

        $this->invoiceService->recalculate($invoice);
    }
}

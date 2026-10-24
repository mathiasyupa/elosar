<?php

namespace App\Services\Sri;

use App\Exceptions\Sri\SriPermanentException;
use App\Jobs\AuthorizeSriVoucherJob;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Workspace;
use App\Services\Sri\SriError;
use Carbon\Carbon;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use RuntimeException;

/**
 * Orchestrates the SRI offline authorization flow:
 *   assign sequence + clave de acceso → build XML → sign → reception → authorize.
 *
 * Each method is idempotent enough to be retried by AuthorizeSriVoucherJob:
 * the sequence, clave and signed XML are assigned only once, after which the
 * voucher can be re-submitted / re-polled safely.
 */
class SriService
{
    /** SRI message id returned when a clave de acceso was already received. */
    private const ALREADY_REGISTERED = '43';

    public function __construct(
        private SriClaveAccesoBuilder $claveBuilder,
        private SriXmlBuilder $xmlBuilder,
        private SriXsdValidator $xsdValidator,
        private SriCertificateLoader $certificateLoader,
        private SriXmlSigner $signer,
        private SriSoapClient $soapClient,
    ) {
    }

    /**
     * Runs the full authorization flow for an invoice (codDoc 01).
     */
    public function authorizeInvoice(Invoice $invoice): void
    {
        $workspace = $invoice->workspace;
        $this->assertConfigured($workspace);

        $this->prepareInvoice($invoice, $workspace);

        $signedXml = $invoice->sri_xml;
        $this->submitAndAuthorize($invoice, $signedXml, $workspace->sri_environment);
    }

    /**
     * Runs the full authorization flow for a credit note (codDoc 04).
     */
    public function authorizeCreditNote(CreditNote $creditNote): void
    {
        $workspace = $creditNote->workspace;
        $this->assertConfigured($workspace);

        $this->prepareCreditNote($creditNote, $workspace);

        $signedXml = $creditNote->sri_xml;
        $this->submitAndAuthorize($creditNote, $signedXml, $workspace->sri_environment);
    }

    /**
     * Re-queues a previously rejected (or stuck pending) voucher for SRI
     * authorization: resets the SRI state machine and dispatches the job again.
     *
     * Works for both invoices and credit notes. Two vouchers are left untouched:
     * an already-authorized one (idempotent, never files the same comprobante
     * twice) and an annulled invoice — re-arming a voided or cancelled invoice
     * would file it with the tax authority after the workspace decided it no
     * longer exists. Both the "Reintentar" button and the `sri:resend` sweep
     * come through here, so the rule holds for either.
     *
     * @param  Invoice|CreditNote  $voucher
     * @return bool  whether the voucher was actually re-queued
     */
    public function resend(Invoice|CreditNote $voucher): bool
    {
        if ($voucher->sri_status === 'authorized') {
            return false;
        }

        if ($voucher instanceof Invoice && $voucher->isCancelled()) {
            return false;
        }

        $voucher->forceFill([
            'sri_status'              => 'pending',
            'sri_submission_attempts' => 0,
            'sri_last_error'          => null,
        ])->save();

        AuthorizeSriVoucherJob::dispatch($voucher);

        return true;
    }

    /**
     * Builds a "estab-ptoEmi-secuencial" number from a numeric counter.
     */
    public function generateSequenceNumber(Workspace $workspace, int $counter): string
    {
        return str_pad((string) $workspace->cod_establecimiento, 3, '0', STR_PAD_LEFT)
            . '-' . str_pad((string) $workspace->pto_emision, 3, '0', STR_PAD_LEFT)
            . '-' . str_pad((string) $counter, 9, '0', STR_PAD_LEFT);
    }

    /**
     * SRI public query URL embedded in the RIDE QR code.
     */
    public function consultaUrl(string $claveAcceso): string
    {
        return 'https://srienlinea.sri.gob.ec/movil-servicios/api/v1.0/comprobantes/consultaPorClaveAcceso/'
            . $claveAcceso;
    }

    /**
     * Returns a PNG data-URI QR code for the access key (for the RIDE PDF).
     */
    public function qrDataUri(string $claveAcceso): string
    {
        $result = Builder::create()
            ->writer(new PngWriter())
            ->data($claveAcceso)
            ->size(160)
            ->margin(4)
            ->build();

        return $result->getDataUri();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Internal flow
    // ─────────────────────────────────────────────────────────────────────

    private function prepareInvoice(Invoice $invoice, Workspace $workspace): void
    {
        if ($invoice->sri_clave_acceso && $invoice->sri_xml) {
            return; // already prepared
        }

        $counter = $this->counterFromNumber($invoice->invoice_number);
        $secuencial = str_pad((string) $counter, 9, '0', STR_PAD_LEFT);

        $clave = $this->claveBuilder->build(
            $workspace,
            $invoice->issued_at instanceof Carbon ? $invoice->issued_at : Carbon::parse($invoice->issued_at),
            SriClaveAccesoBuilder::TIPO_FACTURA,
            $secuencial,
        );

        $invoice->fill([
            'sri_sequence_number' => $this->generateSequenceNumber($workspace, $counter),
            'sri_clave_acceso' => $clave,
            'sri_environment' => $workspace->sri_environment,
            'sri_status' => 'pending',
        ]);
        $invoice->save();

        $unsignedXml = $this->xmlBuilder->buildInvoice($invoice);
        $this->validateXml($unsignedXml);
        $invoice->sri_xml = $this->signXml($unsignedXml, $workspace);
        $invoice->save();
    }

    private function prepareCreditNote(CreditNote $creditNote, Workspace $workspace): void
    {
        if ($creditNote->sri_clave_acceso && $creditNote->sri_xml) {
            return;
        }

        $counter = $this->counterFromNumber($creditNote->credit_note_number);
        $secuencial = str_pad((string) $counter, 9, '0', STR_PAD_LEFT);

        $clave = $this->claveBuilder->build(
            $workspace,
            $creditNote->issued_at instanceof Carbon ? $creditNote->issued_at : Carbon::parse($creditNote->issued_at),
            SriClaveAccesoBuilder::TIPO_NOTA_CREDITO,
            $secuencial,
        );

        $creditNote->fill([
            'sri_sequence_number' => $this->generateSequenceNumber($workspace, $counter),
            'sri_clave_acceso' => $clave,
            'sri_environment' => $workspace->sri_environment,
            'sri_status' => 'pending',
        ]);
        $creditNote->save();

        $unsignedXml = $this->xmlBuilder->buildCreditNote($creditNote);
        $this->validateXml($unsignedXml);
        $creditNote->sri_xml = $this->signXml($unsignedXml, $workspace);
        $creditNote->save();
    }

    /**
     * Reception + authorization, updating the SRI columns on the voucher.
     *
     * @param  Invoice|CreditNote  $voucher
     */
    private function submitAndAuthorize($voucher, string $signedXml, string $environment): void
    {
        $voucher->increment('sri_submission_attempts');
        $voucher->forceFill(['sri_last_attempt_at' => now()])->save();

        // ── Reception ────────────────────────────────────────────────────
        $reception = $this->soapClient->send($signedXml, $environment);

        if ($reception['estado'] === 'DEVUELTA' && ! $this->alreadyRegistered($reception['mensajes'])) {
            $voucher->forceFill([
                'sri_status'     => 'rejected',
                'sri_last_error' => $this->formatMessages($reception['mensajes']) ?: 'Comprobante DEVUELTO por el SRI.',
                'sri_error'      => SriError::fromSriMessages($reception['mensajes']),
            ])->save();

            return;
        }

        // ── Authorization ────────────────────────────────────────────────
        $auth = $this->soapClient->authorize($voucher->sri_clave_acceso, $environment);

        switch ($auth['estado']) {
            case 'AUTORIZADO':
                $voucher->forceFill([
                    'sri_status'               => 'authorized',
                    'sri_authorization_number' => $auth['numeroAutorizacion'] ?: $voucher->sri_clave_acceso,
                    'sri_authorization_date'   => $auth['fechaAutorizacion'] ? Carbon::parse($auth['fechaAutorizacion']) : now(),
                    'sri_last_error'           => null,
                    'sri_error'                => null,
                ])->save();
                break;

            case 'NO AUTORIZADO':
            case 'RECHAZADA':
                $voucher->forceFill([
                    'sri_status'     => 'rejected',
                    'sri_last_error' => $this->formatMessages($auth['mensajes']) ?: 'Comprobante NO AUTORIZADO por el SRI.',
                    'sri_error'      => SriError::fromSriMessages($auth['mensajes']),
                ])->save();
                break;

            default: // EN PROCESAMIENTO — leave pending for the job to retry
                $voucher->forceFill([
                    'sri_status'     => 'pending',
                    'sri_last_error' => 'En procesamiento por el SRI.',
                    'sri_error'      => null,
                ])->save();
        }
    }

    private function signXml(string $unsignedXml, Workspace $workspace): string
    {
        $certificate = $this->certificateLoader->load(
            $workspace->sri_certificate_path,
            (string) $workspace->sri_certificate_password,
        );

        return $this->signer->sign($unsignedXml, $certificate);
    }

    private function assertConfigured(Workspace $workspace): void
    {
        if (! $workspace->canIssueSriVouchers()) {
            // Configuration problem: retrying cannot help, so this is permanent.
            throw new SriPermanentException(
                "Workspace [{$workspace->id}] is not configured for SRI electronic invoicing."
            );
        }
    }

    /**
     * Validates voucher XML against the official XSD. A schema violation is a
     * structural defect that resubmitting cannot fix, so it is permanent.
     */
    private function validateXml(string $xml): void
    {
        try {
            $this->xsdValidator->validate($xml);
        } catch (RuntimeException $e) {
            throw new SriPermanentException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Extracts the numeric counter from an internal number like "F-0001".
     */
    private function counterFromNumber(string $number): int
    {
        return (int) preg_replace('/\D/', '', $number);
    }

    /**
     * @param  array<int,array<string,mixed>>  $messages
     */
    private function alreadyRegistered(array $messages): bool
    {
        foreach ($messages as $m) {
            if ((string) ($m['identificador'] ?? '') === self::ALREADY_REGISTERED) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int,array<string,mixed>>  $messages
     */
    private function formatMessages(array $messages): string
    {
        return collect($messages)
            ->map(fn ($m) => trim(($m['identificador'] ?? '') . ' ' . ($m['mensaje'] ?? '') . ' ' . ($m['informacionAdicional'] ?? '')))
            ->filter()
            ->implode(' | ');
    }
}

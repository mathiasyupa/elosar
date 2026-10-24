<?php

namespace App\Services\Sri;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Workspace;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Throwable;

/**
 * Diagnoses a workspace's SRI setup end to end, without touching a single real
 * invoice.
 *
 * WHY IT PROBES FOR REAL: rejections like "56 ESTABLECIMIENTO CERRADO" or an
 * un-enabled RUC live in the SRI's own records. No amount of local validation
 * can predict them — the only way to know before issuing a real voucher is to
 * ask the SRI with a throwaway one.
 *
 * The throwaway voucher is never persisted and carries a sequential far outside
 * the real range, so it cannot collide with the workspace's numbering.
 *
 * NEVER THROWS. A failure is a step with status `fail`, because the caller is a
 * settings screen, not a background job. Exception text is translated through
 * SriError and never reaches the response.
 */
class SriDiagnostics
{
    /** Sequentials for throwaway vouchers live here, far from real invoices. */
    private const PROBE_SEQUENTIAL_MIN = 900000;
    private const PROBE_SEQUENTIAL_MAX = 999999;

    /** A certificate expiring sooner than this is not a healthy setup. */
    private const EXPIRY_WARNING_DAYS = 30;

    /** Every step the diagnosis reports, in order. */
    private const STEPS = ['fiscal', 'certificate', 'schema', 'signature', 'connectivity', 'probe'];

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
     * @return array{environment:string, probed:bool, steps:array<int,array<string,mixed>>}
     */
    public function run(Workspace $workspace, bool $probe = true): array
    {
        $environment = (string) $workspace->sri_environment;

        // Probing in production would file a REAL fiscal document on the
        // emitter's behalf. Never, regardless of what the caller asked for.
        $canProbe = $probe && $environment === Workspace::SRI_ENVIRONMENT_TEST;

        $steps = [];

        $steps[] = $step = $this->fiscalStep($workspace);
        if ($step['status'] !== 'ok') {
            return $this->result($environment, false, $steps);
        }

        $certificate = null;
        $steps[] = $step = $this->certificateStep($workspace, $certificate);
        if ($step['status'] !== 'ok') {
            return $this->result($environment, false, $steps);
        }

        $invoice = $this->throwawayInvoice($workspace);

        $xml = null;
        $steps[] = $step = $this->schemaStep($invoice, $xml);
        if ($step['status'] !== 'ok') {
            return $this->result($environment, false, $steps);
        }

        $signed = null;
        $steps[] = $step = $this->signatureStep((string) $xml, (array) $certificate, $signed);
        if ($step['status'] !== 'ok') {
            return $this->result($environment, false, $steps);
        }

        $steps[] = $step = $this->connectivityStep($environment);
        if ($step['status'] !== 'ok') {
            return $this->result($environment, false, $steps);
        }

        if (! $canProbe) {
            $steps[] = $this->step('probe', 'skipped');

            return $this->result($environment, false, $steps);
        }

        $steps[] = $this->probeStep((string) $signed, (string) $invoice->sri_clave_acceso, $environment);

        return $this->result($environment, true, $steps);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Steps
    // ─────────────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function fiscalStep(Workspace $workspace): array
    {
        $missing = array_keys(array_filter([
            'RUC'                  => ! $workspace->ruc,
            'razón social'         => ! $workspace->razon_social,
            'dirección matriz'     => ! $workspace->dir_matriz,
            'certificado'          => ! $workspace->sri_certificate_path,
        ]));

        if ($missing !== []) {
            return $this->step('fiscal', 'fail', 'Faltan: ' . implode(', ', $missing) . '.');
        }

        // 13 digits: the single most common setup mistake — a doctor's RUC is
        // their cédula plus "001", and pasting only the cédula silently fails.
        if (strlen((string) $workspace->ruc) !== 13) {
            return $this->step('fiscal', 'fail', 'El RUC debe tener 13 dígitos (cédula + 001).');
        }

        if (! isset(Workspace::SRI_ENVIRONMENTS[(string) $workspace->sri_environment])) {
            return $this->step('fiscal', 'fail', 'El ambiente SRI configurado no es válido.');
        }

        return $this->step('fiscal', 'ok', sprintf(
            'RUC %s · serie %s-%s',
            $workspace->ruc,
            str_pad((string) $workspace->cod_establecimiento, 3, '0', STR_PAD_LEFT),
            str_pad((string) $workspace->pto_emision, 3, '0', STR_PAD_LEFT),
        ));
    }

    /**
     * @param  array{certificate:string,private_key:string,extra_certs?:array<int,string>}|null  $certificate
     * @return array<string,mixed>
     */
    private function certificateStep(Workspace $workspace, ?array &$certificate): array
    {
        try {
            $certificate = $this->certificateLoader->load(
                (string) $workspace->sri_certificate_path,
                (string) $workspace->sri_certificate_password,
            );
        } catch (Throwable $e) {
            return $this->step('certificate', 'fail', null, SriError::internal(SriError::CATEGORY_CERTIFICATE));
        }

        $info = openssl_x509_parse($certificate['certificate']);

        if ($info === false || ! isset($info['validTo_time_t'])) {
            return $this->step('certificate', 'ok');
        }

        $expiresAt = Carbon::createFromTimestamp($info['validTo_time_t']);

        if ($expiresAt->isPast()) {
            return $this->step('certificate', 'fail', 'Venció el ' . $expiresAt->format('d/m/Y') . '.');
        }

        // Nobody watches this date, and the day it passes EVERY invoice starts
        // failing at once with no obvious cause. Flag it while there is time.
        if (now()->diffInDays($expiresAt, absolute: true) < self::EXPIRY_WARNING_DAYS) {
            return $this->step(
                'certificate',
                'fail',
                'Vence el ' . $expiresAt->format('d/m/Y') . ' — quedan menos de 30 días.',
            );
        }

        return $this->step('certificate', 'ok', 'Vence el ' . $expiresAt->format('d/m/Y') . '.');
    }

    /** @return array<string,mixed> */
    private function schemaStep(Invoice $invoice, ?string &$xml): array
    {
        try {
            $xml = $this->xmlBuilder->buildInvoice($invoice);
            $this->xsdValidator->validate($xml);
        } catch (Throwable $e) {
            return $this->step('schema', 'fail', null, SriError::internal(SriError::CATEGORY_SCHEMA));
        }

        return $this->step('schema', 'ok');
    }

    /**
     * @param  array{certificate:string,private_key:string,extra_certs?:array<int,string>}  $certificate
     * @return array<string,mixed>
     */
    private function signatureStep(string $xml, array $certificate, ?string &$signed): array
    {
        try {
            $signed = $this->signer->sign($xml, $certificate);
        } catch (Throwable $e) {
            return $this->step('signature', 'fail', null, SriError::internal(SriError::CATEGORY_CERTIFICATE));
        }

        return $this->step('signature', 'ok', strlen($signed) . ' bytes firmados');
    }

    /** @return array<string,mixed> */
    private function connectivityStep(string $environment): array
    {
        try {
            $this->soapClient->ping($environment);
        } catch (Throwable $e) {
            return $this->step('connectivity', 'fail', null, SriError::internal(SriError::CATEGORY_TRANSPORT));
        }

        return $this->step('connectivity', 'ok');
    }

    /** @return array<string,mixed> */
    private function probeStep(string $signed, string $claveAcceso, string $environment): array
    {
        try {
            $reception = $this->soapClient->send($signed, $environment);

            if ($reception['estado'] !== 'RECIBIDA') {
                return $this->step('probe', 'fail', null, SriError::fromSriMessages($reception['mensajes']));
            }

            sleep(3); // give the SRI a moment to index the voucher

            $auth = $this->soapClient->authorize($claveAcceso, $environment);

            if ($auth['estado'] !== 'AUTORIZADO') {
                return $this->step('probe', 'fail', null, SriError::fromSriMessages($auth['mensajes']));
            }
        } catch (Throwable $e) {
            return $this->step('probe', 'fail', null, SriError::internal(SriError::categoryFor($e)));
        }

        return $this->step('probe', 'ok', 'El SRI autorizó el comprobante de prueba.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * An Invoice that never reaches the database, with its relations set in
     * memory so the XML builder's loadMissing() does not query for them.
     */
    private function throwawayInvoice(Workspace $workspace): Invoice
    {
        $sequential = str_pad(
            (string) random_int(self::PROBE_SEQUENTIAL_MIN, self::PROBE_SEQUENTIAL_MAX),
            9,
            '0',
            STR_PAD_LEFT,
        );

        $item = new InvoiceItem([
            'description'     => 'Prueba de configuración',
            'quantity'        => '1.00',
            'unit_price'      => '1.00',
            'discount_amount' => '0.00',
            'iva_rate'        => '0',
            'iva_amount'      => '0.00',
            'sort_order'      => 1,
        ]);

        // SriXmlBuilder derives <codigoPrincipal> from medical_service_id or,
        // failing that, the row id. This item is never saved, so both are null
        // and the code came out as a bare "SRV-" — which passes the XSD but
        // makes the SRI's own persistence layer throw. Give it a real one.
        $item->id = 1;

        $invoice = new Invoice([
            'invoice_number'  => 'PRUEBA',
            'issued_at'       => today(),
            'subtotal'        => '1.00',
            'iva_amount'      => '0.00',
            'discount_amount' => '0.00',
            'total'           => '1.00',
            'buyer_id_type'   => '07',
        ]);

        $invoice->setRelation('workspace', $workspace);
        $invoice->setRelation('items', new EloquentCollection([$item]));
        $invoice->setRelation('payments', new EloquentCollection());

        $invoice->sri_sequence_number = str_pad((string) $workspace->cod_establecimiento, 3, '0', STR_PAD_LEFT)
            . '-' . str_pad((string) $workspace->pto_emision, 3, '0', STR_PAD_LEFT)
            . '-' . $sequential;

        $invoice->sri_clave_acceso = $this->claveBuilder->build(
            $workspace,
            Carbon::parse($invoice->issued_at),
            SriClaveAccesoBuilder::TIPO_FACTURA,
            $sequential,
        );

        return $invoice;
    }

    /**
     * @param  array<string,mixed>|null  $error
     * @return array<string,mixed>
     */
    private function step(string $key, string $status, ?string $detail = null, ?array $error = null): array
    {
        return ['key' => $key, 'status' => $status, 'detail' => $detail, 'error' => $error];
    }

    /**
     * Pads the result with `skipped` for every step that never ran, so the
     * client always receives the full list in a known order.
     *
     * @param  array<int,array<string,mixed>>  $steps
     * @return array{environment:string, probed:bool, steps:array<int,array<string,mixed>>}
     */
    private function result(string $environment, bool $probed, array $steps): array
    {
        $reached = array_column($steps, 'key');

        foreach (self::STEPS as $key) {
            if (! in_array($key, $reached, true)) {
                $steps[] = $this->step($key, 'skipped');
            }
        }

        return ['environment' => $environment, 'probed' => $probed, 'steps' => $steps];
    }
}

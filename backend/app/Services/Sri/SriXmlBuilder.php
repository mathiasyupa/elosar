<?php

namespace App\Services\Sri;

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Workspace;
use RuntimeException;

/**
 * Builds the unsigned SRI XML for invoices (factura, codDoc 01) and credit
 * notes (notaDeCredito, codDoc 04), following the SRI offline schema.
 *
 * The clave de acceso and sequence number must already be assigned on the
 * model (SriService does this before calling the builder).
 *
 * IVA mapping: tarifa 0% → codigoPorcentaje "0"; tarifa 15% → "4".
 */
class SriXmlBuilder
{
    private const FACTURA_VERSION = '2.1.0';
    private const NOTA_CREDITO_VERSION = '1.1.0';

    private const IVA_CODE = '2';

    /**
     * Maps an item IVA rate (string percentage) to the SRI codigoPorcentaje.
     */
    private function ivaPorcentajeCode(string $rate): string
    {
        return match ((int) round((float) $rate)) {
            0       => '0',
            15      => '4',
            12      => '2',
            default => '0',
        };
    }

    public function buildInvoice(Invoice $invoice): string
    {
        $invoice->loadMissing(['workspace', 'items', 'payments']);
        $workspace = $invoice->workspace;

        // SRI only recognizes line-level discounts. The internal invoice-level
        // global discount cannot be represented without breaking total/IVA
        // integrity, so it is rejected for electronic vouchers.
        if (bccomp((string) ($invoice->discount_amount ?? '0'), '0.00', 2) > 0) {
            throw new RuntimeException(
                'Invoice-level global discounts are not supported for SRI electronic invoices. '
                . 'Apply the discount at the item level instead.'
            );
        }

        $infoTributaria = $this->buildInfoTributaria(
            $workspace,
            SriClaveAccesoBuilder::TIPO_FACTURA,
            $invoice->sri_clave_acceso,
            $this->secuencialFromSequence($invoice->sri_sequence_number),
        );

        // ── Detalles + tax groups ────────────────────────────────────────
        $detalles = '';
        $groups = []; // codigoPorcentaje => ['tarifa' => x, 'base' => '..', 'valor' => '..']
        $totalSinImpuestos = '0.00';
        $totalDescuento = '0.00';

        foreach ($invoice->items->sortBy('sort_order') as $item) {
            $lineBase = bcsub(
                bcmul((string) $item->quantity, (string) $item->unit_price, 2),
                (string) $item->discount_amount,
                2,
            );

            $code = $this->ivaPorcentajeCode((string) $item->iva_rate);
            $groups[$code] ??= ['tarifa' => (string) (int) round((float) $item->iva_rate), 'base' => '0.00', 'valor' => '0.00'];
            $groups[$code]['base'] = bcadd($groups[$code]['base'], $lineBase, 2);
            $groups[$code]['valor'] = bcadd($groups[$code]['valor'], (string) $item->iva_amount, 2);

            $totalSinImpuestos = bcadd($totalSinImpuestos, $lineBase, 2);
            $totalDescuento = bcadd($totalDescuento, (string) $item->discount_amount, 2);

            $detalles .= $this->buildDetalle($item, $lineBase, $code);
        }

        $totalConImpuestos = $this->buildTotalConImpuestos($groups);
        $importeTotal = bcadd($totalSinImpuestos, (string) $invoice->iva_amount, 2);

        $infoFactura = $this->buildInfoFactura($invoice, $workspace, $totalSinImpuestos, $totalDescuento, $totalConImpuestos, $importeTotal);

        $body = '<factura id="comprobante" version="' . self::FACTURA_VERSION . '">'
            . $infoTributaria
            . $infoFactura
            . '<detalles>' . $detalles . '</detalles>'
            . $this->buildInfoAdicional($invoice->buyer_email, $invoice->buyer_address)
            . '</factura>';

        return '<?xml version="1.0" encoding="UTF-8"?>' . $body;
    }

    public function buildCreditNote(CreditNote $creditNote): string
    {
        $creditNote->loadMissing(['workspace', 'invoice.items']);
        $workspace = $creditNote->workspace;
        $invoice = $creditNote->invoice;

        $infoTributaria = $this->buildInfoTributaria(
            $workspace,
            SriClaveAccesoBuilder::TIPO_NOTA_CREDITO,
            $creditNote->sri_clave_acceso,
            $this->secuencialFromSequence($creditNote->sri_sequence_number),
        );

        // A credit note for a voided invoice mirrors the original detail lines.
        $detalles = '';
        $groups = [];
        $totalSinImpuestos = '0.00';

        foreach ($invoice->items->sortBy('sort_order') as $item) {
            $lineBase = bcsub(
                bcmul((string) $item->quantity, (string) $item->unit_price, 2),
                (string) $item->discount_amount,
                2,
            );
            $code = $this->ivaPorcentajeCode((string) $item->iva_rate);
            $groups[$code] ??= ['tarifa' => (string) (int) round((float) $item->iva_rate), 'base' => '0.00', 'valor' => '0.00'];
            $groups[$code]['base'] = bcadd($groups[$code]['base'], $lineBase, 2);
            $groups[$code]['valor'] = bcadd($groups[$code]['valor'], (string) $item->iva_amount, 2);
            $totalSinImpuestos = bcadd($totalSinImpuestos, $lineBase, 2);

            $detalles .= $this->buildDetalle($item, $lineBase, $code, forCreditNote: true);
        }

        // Per NotaCredito XSD 1.1.0, totalImpuesto has no <tarifa> element.
        $totalConImpuestos = $this->buildTotalConImpuestos($groups, withTarifa: false);
        $valorModificacion = bcadd($totalSinImpuestos, (string) $invoice->iva_amount, 2);

        $e = fn ($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $infoNota = '<infoNotaCredito>'
            . '<fechaEmision>' . $creditNote->issued_at->format('d/m/Y') . '</fechaEmision>'
            . '<dirEstablecimiento>' . $e($workspace->establishmentAddress()) . '</dirEstablecimiento>'
            . '<tipoIdentificacionComprador>' . $e($invoice->buyer_id_type ?: '07') . '</tipoIdentificacionComprador>'
            . '<razonSocialComprador>' . $e($invoice->buyer_name ?: 'CONSUMIDOR FINAL') . '</razonSocialComprador>'
            . '<identificacionComprador>' . $e($invoice->buyer_id ?: '9999999999999') . '</identificacionComprador>'
            . '<obligadoContabilidad>' . ($workspace->obligado_contabilidad ? 'SI' : 'NO') . '</obligadoContabilidad>'
            . '<codDocModificado>01</codDocModificado>'
            . '<numDocModificado>' . $e($invoice->sri_sequence_number ?: $invoice->invoice_number) . '</numDocModificado>'
            . '<fechaEmisionDocSustento>' . $invoice->issued_at->format('d/m/Y') . '</fechaEmisionDocSustento>'
            . '<totalSinImpuestos>' . $totalSinImpuestos . '</totalSinImpuestos>'
            . '<valorModificacion>' . $valorModificacion . '</valorModificacion>'
            . '<moneda>DOLAR</moneda>'
            . '<totalConImpuestos>' . $totalConImpuestos . '</totalConImpuestos>'
            . '<motivo>' . $e($creditNote->reason) . '</motivo>'
            . '</infoNotaCredito>';

        $body = '<notaCredito id="comprobante" version="' . self::NOTA_CREDITO_VERSION . '">'
            . $infoTributaria
            . $infoNota
            . '<detalles>' . $detalles . '</detalles>'
            . '</notaCredito>';

        return '<?xml version="1.0" encoding="UTF-8"?>' . $body;
    }

    private function buildInfoTributaria(Workspace $workspace, string $codDoc, string $claveAcceso, string $secuencial): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $ambiente = $workspace->sriAmbienteCode();

        $xml = '<infoTributaria>'
            . '<ambiente>' . $ambiente . '</ambiente>'
            . '<tipoEmision>1</tipoEmision>'
            . '<razonSocial>' . $e($workspace->razon_social ?: $workspace->name) . '</razonSocial>';

        if ($workspace->nombre_comercial) {
            $xml .= '<nombreComercial>' . $e($workspace->nombre_comercial) . '</nombreComercial>';
        }

        $xml .= '<ruc>' . $e($workspace->ruc) . '</ruc>'
            . '<claveAcceso>' . $e($claveAcceso) . '</claveAcceso>'
            . '<codDoc>' . $codDoc . '</codDoc>'
            . '<estab>' . str_pad((string) $workspace->cod_establecimiento, 3, '0', STR_PAD_LEFT) . '</estab>'
            . '<ptoEmi>' . str_pad((string) $workspace->pto_emision, 3, '0', STR_PAD_LEFT) . '</ptoEmi>'
            . '<secuencial>' . $secuencial . '</secuencial>'
            . '<dirMatriz>' . $e($workspace->dir_matriz) . '</dirMatriz>';

        if ($workspace->contribuyente_especial) {
            // contribuyente especial is part of infoFactura in some schemas;
            // kept here is invalid, so omitted from infoTributaria.
        }

        $xml .= '</infoTributaria>';

        return $xml;
    }

    private function buildInfoFactura(
        Invoice $invoice,
        Workspace $workspace,
        string $totalSinImpuestos,
        string $totalDescuento,
        string $totalConImpuestos,
        string $importeTotal,
    ): string {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $xml = '<infoFactura>'
            . '<fechaEmision>' . $invoice->issued_at->format('d/m/Y') . '</fechaEmision>'
            . '<dirEstablecimiento>' . $e($workspace->establishmentAddress()) . '</dirEstablecimiento>';

        if ($workspace->contribuyente_especial) {
            $xml .= '<contribuyenteEspecial>' . $e($workspace->contribuyente_especial) . '</contribuyenteEspecial>';
        }

        $xml .= '<obligadoContabilidad>' . ($workspace->obligado_contabilidad ? 'SI' : 'NO') . '</obligadoContabilidad>'
            . '<tipoIdentificacionComprador>' . $e($invoice->buyer_id_type ?: '07') . '</tipoIdentificacionComprador>'
            . '<razonSocialComprador>' . $e($invoice->buyer_name ?: 'CONSUMIDOR FINAL') . '</razonSocialComprador>'
            . '<identificacionComprador>' . $e($invoice->buyer_id ?: '9999999999999') . '</identificacionComprador>';

        if ($invoice->buyer_address) {
            $xml .= '<direccionComprador>' . $e($invoice->buyer_address) . '</direccionComprador>';
        }

        $xml .= '<totalSinImpuestos>' . $totalSinImpuestos . '</totalSinImpuestos>'
            . '<totalDescuento>' . $totalDescuento . '</totalDescuento>'
            . '<totalConImpuestos>' . $totalConImpuestos . '</totalConImpuestos>'
            . '<propina>0.00</propina>'
            . '<importeTotal>' . $importeTotal . '</importeTotal>'
            . '<moneda>DOLAR</moneda>'
            . '<pagos>'
            . '<pago>'
            . '<formaPago>01</formaPago>'
            . '<total>' . $importeTotal . '</total>'
            . '</pago>'
            . '</pagos>'
            . '</infoFactura>';

        return $xml;
    }

    /**
     * The factura 2.1.0 schema includes <tarifa> inside totalImpuesto;
     * the notaCredito 1.1.0 schema does not.
     *
     * @param  array<string,array{tarifa:string,base:string,valor:string}>  $groups
     */
    private function buildTotalConImpuestos(array $groups, bool $withTarifa = true): string
    {
        $xml = '';
        foreach ($groups as $codigoPorcentaje => $group) {
            $xml .= '<totalImpuesto>'
                . '<codigo>' . self::IVA_CODE . '</codigo>'
                . '<codigoPorcentaje>' . $codigoPorcentaje . '</codigoPorcentaje>'
                . '<baseImponible>' . $group['base'] . '</baseImponible>'
                . ($withTarifa ? '<tarifa>' . $group['tarifa'] . '</tarifa>' : '')
                . '<valor>' . $group['valor'] . '</valor>'
                . '</totalImpuesto>';
        }

        return $xml;
    }

    private function buildDetalle(\App\Models\InvoiceItem $item, string $lineBase, string $codigoPorcentaje, bool $forCreditNote = false): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $codigo = $item->medical_service_id ? 'SRV-' . $item->medical_service_id : 'SRV-' . $item->id;
        $tarifa = (string) (int) round((float) $item->iva_rate);

        // Schema difference: factura 2.1.0 names the item code <codigoPrincipal>;
        // notaCredito 1.1.0 names it <codigoInterno>.
        $codigoTag = $forCreditNote ? 'codigoInterno' : 'codigoPrincipal';

        return '<detalle>'
            . '<' . $codigoTag . '>' . $e($codigo) . '</' . $codigoTag . '>'
            . '<descripcion>' . $e($item->description) . '</descripcion>'
            . '<cantidad>' . number_format((float) $item->quantity, 2, '.', '') . '</cantidad>'
            . '<precioUnitario>' . number_format((float) $item->unit_price, 2, '.', '') . '</precioUnitario>'
            . '<descuento>' . number_format((float) $item->discount_amount, 2, '.', '') . '</descuento>'
            . '<precioTotalSinImpuesto>' . $lineBase . '</precioTotalSinImpuesto>'
            . '<impuestos>'
            . '<impuesto>'
            . '<codigo>' . self::IVA_CODE . '</codigo>'
            . '<codigoPorcentaje>' . $codigoPorcentaje . '</codigoPorcentaje>'
            . '<tarifa>' . $tarifa . '</tarifa>'
            . '<baseImponible>' . $lineBase . '</baseImponible>'
            . '<valor>' . number_format((float) $item->iva_amount, 2, '.', '') . '</valor>'
            . '</impuesto>'
            . '</impuestos>'
            . '</detalle>';
    }

    private function buildInfoAdicional(?string $email, ?string $address): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $campos = '';

        if ($email) {
            $campos .= '<campoAdicional nombre="email">' . $e($email) . '</campoAdicional>';
        }
        if ($address) {
            $campos .= '<campoAdicional nombre="direccion">' . $e($address) . '</campoAdicional>';
        }

        return $campos === '' ? '' : '<infoAdicional>' . $campos . '</infoAdicional>';
    }

    /**
     * Extracts the 9-digit secuencial from a "001-001-000000001" sequence.
     */
    private function secuencialFromSequence(?string $sequence): string
    {
        $digits = preg_replace('/\D/', '', (string) $sequence);
        $secuencial = strlen($digits) >= 9 ? substr($digits, -9) : $digits;

        return str_pad($secuencial, 9, '0', STR_PAD_LEFT);
    }
}

<?php

use App\Models\Patient;
use App\Models\Workspace;
use App\Services\InvoiceService;
use App\Services\Sri\SriXmlBuilder;
use App\Services\Sri\SriXsdValidator;
use App\Support\ActiveWorkspace;

/**
 * Validates the XML actually produced by SriXmlBuilder against the OFFICIAL
 * SRI XSD schemas (factura 2.1.0 / notaCredito 1.1.0) vendored under
 * resources/sri/xsd/. This is the strongest structural guarantee available
 * without submitting to the SRI test environment.
 */

function xsdBillingContext(): array
{
    $workspace = Workspace::factory()->create([
        'invoice_counter'     => 0,
        'credit_note_counter' => 0,
        'ruc'                 => '1790011674001',
        'razon_social'        => 'CLINICA DEMO S.A.',
        'nombre_comercial'    => 'Clínica Demo',
        'dir_matriz'          => 'Av. Amazonas N34-100, Quito',
        'cod_establecimiento' => '001',
        'pto_emision'         => '001',
        'sri_environment'     => 'test',
    ]);
    $patient = Patient::factory()->create(['workspace_id' => $workspace->id]);

    app(ActiveWorkspace::class)->bind($workspace->id);

    return [$workspace, $patient];
}

function xsdSampleInvoice(Workspace $workspace, Patient $patient)
{
    $invoice = app(InvoiceService::class)->create([
        'workspace_id'  => $workspace->id,
        'patient_id'    => $patient->id,
        'issued_at'     => '2026-06-12',
        'buyer_id_type' => '05',
        'buyer_id'      => '1710034065',
        'buyer_name'    => 'JUAN PEREZ',
        'buyer_email'   => 'juan@example.com',
        'buyer_address' => 'Quito',
        'items'         => [
            ['description' => 'Consulta general', 'quantity' => '1', 'unit_price' => '100.00', 'iva_rate' => '15.00'],
            ['description' => 'Examen exento', 'quantity' => '2', 'unit_price' => '20.00', 'iva_rate' => '0.00', 'discount_percentage' => '10'],
        ],
    ]);

    $invoice->update([
        'sri_sequence_number' => '001-001-000000001',
        'sri_clave_acceso'    => '1206202601179001167400110010010000000011234567813',
    ]);

    return $invoice->fresh();
}

it('the generated factura XML validates against the official SRI XSD 2.1.0', function () {
    [$workspace, $patient] = xsdBillingContext();
    $invoice = xsdSampleInvoice($workspace, $patient);

    $xml = app(SriXmlBuilder::class)->buildInvoice($invoice);

    // Throws RuntimeException with full libxml details on violation
    app(SriXsdValidator::class)->validate($xml);

    expect(true)->toBeTrue();
});

it('the generated notaCredito XML validates against the official SRI XSD 1.1.0', function () {
    [$workspace, $patient] = xsdBillingContext();
    $invoice = xsdSampleInvoice($workspace, $patient);

    $creditNote = app(InvoiceService::class)->generateCreditNote($invoice, 'Anulación de la factura');
    $creditNote->update([
        'sri_sequence_number' => '001-001-000000001',
        'sri_clave_acceso'    => '1206202604179001167400110010010000000011234567810',
    ]);

    $xml = app(SriXmlBuilder::class)->buildCreditNote($creditNote->fresh());

    app(SriXsdValidator::class)->validate($xml);

    expect(true)->toBeTrue();
});

it('reports schema violations with libxml details', function () {
    $bad = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<factura id="comprobante" version="2.1.0"><foo>bar</foo></factura>';

    expect(fn () => app(SriXsdValidator::class)->validate($bad))
        ->toThrow(RuntimeException::class, 'SRI XSD validation failed');
});

it('rejects XML with an unknown root element', function () {
    $xml = '<?xml version="1.0"?><otroDocumento id="comprobante"/>';

    expect(fn () => app(SriXsdValidator::class)->validate($xml))
        ->toThrow(RuntimeException::class, 'No SRI schema registered');
});

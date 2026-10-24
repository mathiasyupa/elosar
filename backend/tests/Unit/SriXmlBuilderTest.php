<?php

use App\Models\Patient;
use App\Models\Workspace;
use App\Services\InvoiceService;
use App\Services\Sri\SriXmlBuilder;
use App\Support\ActiveWorkspace;

function sriBillingContext(): array
{
    $workspace = Workspace::factory()->create([
        'invoice_counter'     => 0,
        'ruc'                 => '1790011674001',
        'razon_social'        => 'CLINICA DEMO S.A.',
        'dir_matriz'          => 'Av. Amazonas N34-100, Quito',
        'cod_establecimiento' => '001',
        'pto_emision'         => '001',
        'sri_environment'     => 'test',
    ]);
    $patient = Patient::factory()->create(['workspace_id' => $workspace->id]);

    app(ActiveWorkspace::class)->bind($workspace->id);

    return [$workspace, $patient];
}

it('builds a well-formed factura XML with the expected fiscal nodes', function () {
    [$workspace, $patient] = sriBillingContext();

    $invoice = app(InvoiceService::class)->create([
        'workspace_id'  => $workspace->id,
        'patient_id'    => $patient->id,
        'issued_at'     => '2026-06-12',
        'buyer_id_type' => '05',
        'buyer_id'      => '1710034065',
        'buyer_name'    => 'JUAN PEREZ',
        'items'         => [
            ['description' => 'Consulta general', 'quantity' => '1', 'unit_price' => '100.00', 'iva_rate' => '15.00'],
            ['description' => 'Examen exento',    'quantity' => '2', 'unit_price' => '20.00',  'iva_rate' => '0.00'],
        ],
    ]);

    $invoice->update([
        'sri_sequence_number' => '001-001-000000001',
        'sri_clave_acceso'    => str_repeat('1', 49),
    ]);

    $xml = app(SriXmlBuilder::class)->buildInvoice($invoice->fresh());

    // Well-formed
    $doc = new DOMDocument();
    expect($doc->loadXML($xml))->toBeTrue();

    expect($xml)
        ->toContain('<factura id="comprobante"')
        ->toContain('<codDoc>01</codDoc>')
        ->toContain('<ruc>1790011674001</ruc>')
        ->toContain('<secuencial>000000001</secuencial>')
        ->toContain('<tipoIdentificacionComprador>05</tipoIdentificacionComprador>')
        ->toContain('<identificacionComprador>1710034065</identificacionComprador>')
        // 15% line: base 100, IVA 15; 0% line: base 40
        ->toContain('<totalSinImpuestos>140.00</totalSinImpuestos>')
        ->toContain('<importeTotal>155.00</importeTotal>')
        ->toContain('<codigoPorcentaje>4</codigoPorcentaje>')   // 15%
        ->toContain('<codigoPorcentaje>0</codigoPorcentaje>');  // exempt
});

it('rejects an invoice-level global discount for SRI vouchers', function () {
    [$workspace, $patient] = sriBillingContext();

    $invoice = app(InvoiceService::class)->create([
        'workspace_id'    => $workspace->id,
        'patient_id'      => $patient->id,
        'issued_at'       => '2026-06-12',
        'discount_amount' => '10.00',
        'items'           => [
            ['description' => 'Consulta', 'quantity' => '1', 'unit_price' => '100.00', 'iva_rate' => '15.00'],
        ],
    ]);

    $invoice->update([
        'sri_sequence_number' => '001-001-000000001',
        'sri_clave_acceso'    => str_repeat('1', 49),
    ]);

    expect(fn () => app(SriXmlBuilder::class)->buildInvoice($invoice->fresh()))
        ->toThrow(RuntimeException::class);
});

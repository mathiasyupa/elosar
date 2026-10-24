<?php

use App\Models\Patient;
use App\Models\Workspace;
use App\Services\InvoiceService;
use App\Services\Sri\SriService;
use Illuminate\Support\Facades\Storage;

/**
 * Live integration test against the SRI TEST environment (celcer.sri.gob.ec).
 *
 * Skipped unless a real signing certificate is configured via env:
 *
 *   SRI_TEST_CERTIFICATE_PATH=/absolute/path/to/certificate.p12
 *   SRI_TEST_CERTIFICATE_PASSWORD=secret
 *   SRI_TEST_RUC=1790011674001        # RUC of the certificate holder,
 *                                     # enabled for "pruebas" in SRI en Línea
 *
 * The day a BCE certificate arrives: set the variables and run
 *   ./vendor/bin/sail pest tests/Feature/Sri/SriIntegrationTest.php
 */

function sriIntegrationConfigured(): bool
{
    return (bool) (env('SRI_TEST_CERTIFICATE_PATH')
        && env('SRI_TEST_CERTIFICATE_PASSWORD')
        && env('SRI_TEST_RUC'));
}

it('authorizes a real invoice against the SRI test environment', function () {
    $certSource = env('SRI_TEST_CERTIFICATE_PATH');
    Storage::disk('local')->put('sri-certificates/integration.p12', file_get_contents($certSource));

    $workspace = Workspace::factory()->create([
        'invoice_counter'          => random_int(900000, 999999), // avoid clave collisions across runs
        'ruc'                      => env('SRI_TEST_RUC'),
        'razon_social'             => 'CONTRIBUYENTE DE PRUEBAS',
        'dir_matriz'               => 'Av. Amazonas N34-100, Quito',
        'cod_establecimiento'      => '001',
        'pto_emision'              => '001',
        'sri_environment'          => 'test',
        'sri_enabled'              => true,
        'sri_certificate_path'     => 'sri-certificates/integration.p12',
        'sri_certificate_password' => env('SRI_TEST_CERTIFICATE_PASSWORD'),
    ]);

    $patient = Patient::factory()->create(['workspace_id' => $workspace->id]);

    $invoice = app(InvoiceService::class)->create([
        'workspace_id'  => $workspace->id,
        'patient_id'    => $patient->id,
        'issued_at'     => today()->toDateString(),
        'buyer_id_type' => '05',
        'buyer_id'      => '1710034065',
        'buyer_name'    => 'CONSUMIDOR DE PRUEBA',
        'items'         => [
            ['description' => 'Consulta de prueba SRI', 'quantity' => '1', 'unit_price' => '10.00', 'iva_rate' => '15.00'],
        ],
    ]);

    $sri = app(SriService::class);
    $sri->authorizeInvoice($invoice->fresh());

    $invoice = $invoice->fresh();

    expect($invoice->sri_status)->not->toBe('rejected', "SRI error: {$invoice->sri_last_error}");

    // The SRI may take a few seconds to authorize; poll briefly.
    $attempts = 0;
    while ($invoice->sri_status === 'pending' && $attempts < 5) {
        sleep(5);
        $sri->authorizeInvoice($invoice);
        $invoice = $invoice->fresh();
        $attempts++;
    }

    expect($invoice->sri_status)->toBe('authorized', "Final SRI state: {$invoice->sri_status} — {$invoice->sri_last_error}")
        ->and($invoice->sri_authorization_number)->not->toBeNull();
})->skip(
    fn () => ! sriIntegrationConfigured(),
    'SRI integration: set SRI_TEST_CERTIFICATE_PATH / SRI_TEST_CERTIFICATE_PASSWORD / SRI_TEST_RUC to run.',
);

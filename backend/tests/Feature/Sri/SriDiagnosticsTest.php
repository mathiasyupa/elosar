<?php

use App\Models\Workspace;
use App\Services\Sri\SriDiagnostics;
use App\Services\Sri\SriSoapClient;

/*
| Diagnoses an SRI setup without touching a single real invoice.
|
| Origin: configuring a doctor's workspace burned two full trial-and-error
| cycles because "establecimiento 001 está cerrado" only surfaces from the SRI
| itself — no form can know it. This asks the SRI before any real voucher exists.
*/

function diagnosticsWorkspace(array $overrides = []): Workspace
{
    return Workspace::factory()->create(array_merge([
        'ruc'                      => '1790012345001',
        'razon_social'             => 'CONSULTORIO PRUEBA',
        'dir_matriz'               => 'Av. Amazonas N34-100, Quito',
        'cod_establecimiento'      => '004',
        'pto_emision'              => '001',
        'sri_environment'          => Workspace::SRI_ENVIRONMENT_TEST,
        'sri_certificate_path'     => 'sri/certificates/probe.p12',
        'sri_certificate_password' => 'secreto123',
        'sri_enabled'              => true,
    ], $overrides));
}

/** Puts a real PKCS#12 on the fake local disk where SriCertificateLoader reads. */
function putProbeCertificate(string $path = 'sri/certificates/probe.p12'): void
{
    [$bundle] = makeTestPkcs12('secreto123');
    Illuminate\Support\Facades\Storage::disk('local')->put($path, $bundle);
}

beforeEach(function () {
    Illuminate\Support\Facades\Storage::fake('local');
});

it('reports every step as ok when the setup is sound', function () {
    putProbeCertificate();

    $this->mock(SriSoapClient::class, function ($mock) {
        $mock->shouldReceive('ping')->once();
        $mock->shouldReceive('send')->once()->andReturn(['estado' => 'RECIBIDA', 'mensajes' => []]);
        $mock->shouldReceive('authorize')->once()->andReturn([
            'estado' => 'AUTORIZADO', 'numeroAutorizacion' => str_repeat('7', 49),
            'fechaAutorizacion' => now()->toIso8601String(), 'mensajes' => [],
        ]);
    });

    $result = app(SriDiagnostics::class)->run(diagnosticsWorkspace());

    expect($result['probed'])->toBeTrue();
    expect(collect($result['steps'])->pluck('status')->unique()->all())->toBe(['ok']);
    expect(collect($result['steps'])->pluck('key')->all())
        ->toBe(['fiscal', 'certificate', 'schema', 'signature', 'connectivity', 'probe']);
});

it('surfaces the SRI rejection as a structured error on the probe step', function () {
    putProbeCertificate();

    $this->mock(SriSoapClient::class, function ($mock) {
        $mock->shouldReceive('ping')->once();
        $mock->shouldReceive('send')->once()->andReturn([
            'estado' => 'DEVUELTA',
            'mensajes' => [['identificador' => '56', 'mensaje' => 'ERROR ESTABLECIMIENTO CERRADO',
                            'informacionAdicional' => 'El establecimiento 004 está cerrado', 'tipo' => 'ERROR']],
        ]);
    });

    $steps = collect(app(SriDiagnostics::class)->run(diagnosticsWorkspace())['steps'])->keyBy('key');

    expect($steps['probe']['status'])->toBe('fail')
        ->and($steps['probe']['error'])->toEqual([
            'source' => 'sri', 'code' => '56',
            'message' => 'ERROR ESTABLECIMIENTO CERRADO',
            'info' => 'El establecimiento 004 está cerrado',
        ]);
});

it('stops at the certificate and skips what depends on it', function () {
    // No certificate file on disk at all.
    $steps = collect(app(SriDiagnostics::class)->run(diagnosticsWorkspace())['steps'])->keyBy('key');

    expect($steps['certificate']['status'])->toBe('fail')
        ->and($steps['signature']['status'])->toBe('skipped')
        ->and($steps['probe']['status'])->toBe('skipped');
});

it('fails a certificate that expires within 30 days', function () {
    // A 10-day certificate: valid today, but not a healthy setup.
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    $csr = openssl_csr_new(['commonName' => 'Casi Vencido'], $key);
    $cert = openssl_csr_sign($csr, null, $key, 10);
    $bundle = '';
    openssl_pkcs12_export($cert, $bundle, $key, 'secreto123');
    Illuminate\Support\Facades\Storage::disk('local')->put('sri/certificates/probe.p12', $bundle);

    $steps = collect(app(SriDiagnostics::class)->run(diagnosticsWorkspace())['steps'])->keyBy('key');

    expect($steps['certificate']['status'])->toBe('fail');
});

it('never probes in production', function () {
    putProbeCertificate();

    // Any call to the SOAP client here would mean a REAL fiscal document.
    $this->mock(SriSoapClient::class, function ($mock) {
        // Connectivity is still checked in production; filing a voucher is not.
        $mock->shouldReceive('ping')->once();
        $mock->shouldNotReceive('send');
    });

    $result = app(SriDiagnostics::class)->run(
        diagnosticsWorkspace(['sri_environment' => Workspace::SRI_ENVIRONMENT_PRODUCTION])
    );

    $steps = collect($result['steps'])->keyBy('key');

    expect($result['probed'])->toBeFalse()
        ->and($steps['probe']['status'])->toBe('skipped');
});

it('never leaks exception text into a step', function () {
    putProbeCertificate();

    $this->mock(SriSoapClient::class, function ($mock) {
        $mock->shouldReceive('ping')->once();
        $mock->shouldReceive('send')->andThrow(
            new RuntimeException('SRI transport error: refused at /var/www/html/app/Services/Sri/SriSoapClient.php')
        );
    });

    $json = json_encode(app(SriDiagnostics::class)->run(diagnosticsWorkspace()));

    expect($json)->not->toContain('/var/www/html')
        ->and($json)->not->toContain('refused');
});

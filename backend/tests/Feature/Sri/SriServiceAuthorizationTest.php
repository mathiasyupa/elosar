<?php

use App\Data\InvoiceData;
use App\Data\InvoiceItemData;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Workspace;
use App\Services\InvoiceService;
use App\Services\Sri\SriCertificateLoader;
use App\Services\Sri\SriService;
use App\Services\Sri\SriSoapClient;
use App\Services\Sri\SriXmlSigner;
use App\Support\ActiveWorkspace;
use Illuminate\Support\Facades\Queue;

/**
 * SriService response→status mapping, driven by recorded SOAP responses.
 *
 * The SOAP transport (SriSoapClient) and the certificate steps
 * (SriCertificateLoader + SriXmlSigner) are mocked so the real clave-de-acceso,
 * XML-build and XSD-validation logic runs without network or a real .p12.
 */
function configuredSriInvoice(): Invoice
{
    $ws = Workspace::factory()->create([
        'invoice_counter'          => 0,
        'ruc'                      => '1790011674001',
        'razon_social'             => 'CLINICA DEMO S.A.',
        'nombre_comercial'         => 'Clínica Demo',
        'dir_matriz'               => 'Av. Amazonas N34-100, Quito',
        'cod_establecimiento'      => '001',
        'pto_emision'              => '001',
        'sri_environment'          => 'test',
        'sri_enabled'              => true,
        'sri_certificate_path'     => 'sri-certificates/fake.p12',
        'sri_certificate_password' => 'secret',
    ]);
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    app(ActiveWorkspace::class)->bind($ws->id);

    return app(InvoiceService::class)->create(new InvoiceData(
        workspaceId: $ws->id,
        patientId: $patient->id,
        issuedAt: '2026-06-12',
        buyerIdType: '05',
        buyerId: '1710034065',
        buyerName: 'JUAN PEREZ',
        buyerAddress: 'Quito',
        buyerEmail: 'juan@example.com',
        items: [
            new InvoiceItemData(description: 'Consulta general', quantity: '1', unitPrice: '100.00', ivaRate: '15.00'),
            new InvoiceItemData(description: 'Examen exento', quantity: '2', unitPrice: '20.00', ivaRate: '0.00', discountPercentage: '10'),
        ],
    ));
}

beforeEach(function () {
    Queue::fake(); // create() dispatches the real job; never let it run here.

    // Bypass certificate loading + XAdES signing (no real .p12 in tests).
    $this->mock(SriCertificateLoader::class, fn ($m) => $m->shouldReceive('load')
        ->andReturn(['certificate' => 'cert', 'privateKey' => 'key']));
    $this->mock(SriXmlSigner::class, fn ($m) => $m->shouldReceive('sign')
        ->andReturnUsing(fn (string $xml) => $xml));
});

it('marks the invoice authorized on AUTORIZADO', function () {
    $this->mock(SriSoapClient::class, function ($m) {
        $m->shouldReceive('send')->andReturn(['estado' => 'RECIBIDA', 'mensajes' => []]);
        $m->shouldReceive('authorize')->andReturn([
            'estado'             => 'AUTORIZADO',
            'numeroAutorizacion' => 'AUTH-123',
            'fechaAutorizacion'  => '2026-06-12T10:00:00',
            'mensajes'           => [],
        ]);
    });

    $invoice = configuredSriInvoice();
    app(SriService::class)->authorizeInvoice($invoice);

    expect($invoice->fresh()->sri_status)->toBe('authorized')
        ->and($invoice->fresh()->sri_authorization_number)->toBe('AUTH-123');
});

it('marks the invoice rejected when DEVUELTA at reception', function () {
    $this->mock(SriSoapClient::class, function ($m) {
        $m->shouldReceive('send')->andReturn([
            'estado'   => 'DEVUELTA',
            'mensajes' => [['identificador' => '45', 'mensaje' => 'Error de estructura', 'informacionAdicional' => 'detalle']],
        ]);
        $m->shouldReceive('authorize')->never();
    });

    $invoice = configuredSriInvoice();
    app(SriService::class)->authorizeInvoice($invoice);

    expect($invoice->fresh()->sri_status)->toBe('rejected')
        ->and($invoice->fresh()->sri_last_error)->toContain('Error de estructura');
});

it('keeps the invoice pending on EN PROCESAMIENTO', function () {
    $this->mock(SriSoapClient::class, function ($m) {
        $m->shouldReceive('send')->andReturn(['estado' => 'RECIBIDA', 'mensajes' => []]);
        $m->shouldReceive('authorize')->andReturn([
            'estado'             => 'EN PROCESAMIENTO',
            'numeroAutorizacion' => null,
            'fechaAutorizacion'  => null,
            'mensajes'           => [],
        ]);
    });

    $invoice = configuredSriInvoice();
    app(SriService::class)->authorizeInvoice($invoice);

    expect($invoice->fresh()->sri_status)->toBe('pending');
});

it('marks the invoice rejected on RECHAZADA at authorization', function () {
    $this->mock(SriSoapClient::class, function ($m) {
        $m->shouldReceive('send')->andReturn(['estado' => 'RECIBIDA', 'mensajes' => []]);
        $m->shouldReceive('authorize')->andReturn([
            'estado'             => 'RECHAZADA',
            'numeroAutorizacion' => null,
            'fechaAutorizacion'  => null,
            'mensajes'           => [['identificador' => '70', 'mensaje' => 'Clave de acceso registrada', 'informacionAdicional' => '']],
        ]);
    });

    $invoice = configuredSriInvoice();
    app(SriService::class)->authorizeInvoice($invoice);

    expect($invoice->fresh()->sri_status)->toBe('rejected');
});

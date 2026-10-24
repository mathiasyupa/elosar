<?php

use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Workspace;
use App\Services\Sri\SriService;
use App\Services\Sri\SriSoapClient;
use Illuminate\Support\Facades\Queue;

/*
| The SRI's answer must land on the voucher as a structured, safe payload.
*/

function sriPayloadWorkspace(): Workspace
{
    return Workspace::factory()->create([
        'ruc'                  => '1790012345001',
        'razon_social'         => 'CONSULTORIO PRUEBA',
        'dir_matriz'           => 'Av. Amazonas N34-100, Quito',
        'cod_establecimiento'  => '001',
        'pto_emision'          => '001',
        'sri_environment'      => Workspace::SRI_ENVIRONMENT_TEST,
        'sri_certificate_path' => 'sri/certificates/fake.p12',
        'sri_enabled'          => true,
    ]);
}

it('stores the SRI message as a structured payload when the voucher is returned', function () {
    $ws = sriPayloadWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    $invoice = Invoice::factory()->for($ws)->create([
        'patient_id'       => $patient->id,
        'sri_status'       => 'pending',
        'sri_clave_acceso' => str_repeat('1', 49),
        'sri_xml'          => '<factura/>',
    ]);

    $this->mock(SriSoapClient::class, function ($mock) {
        $mock->shouldReceive('send')->once()->andReturn([
            'estado'   => 'DEVUELTA',
            'mensajes' => [[
                'identificador'        => '56',
                'mensaje'              => 'ERROR ESTABLECIMIENTO CERRADO',
                'informacionAdicional' => 'El establecimiento 001 está cerrado',
                'tipo'                 => 'ERROR',
            ]],
        ]);
    });

    app(SriService::class)->authorizeInvoice($invoice);

    $fresh = $invoice->fresh();

    expect($fresh->sri_status)->toBe('rejected')
        // toEqual (not toBe): PostgreSQL's jsonb column canonicalizes object
        // key order on storage (by key length, then alphabetically), so a
        // strict/ordered array comparison fails on the round-tripped value
        // even though the key/value pairs are unchanged.
        ->and($fresh->sri_error)->toEqual([
            'source'  => 'sri',
            'code'    => '56',
            'message' => 'ERROR ESTABLECIMIENTO CERRADO',
            'info'    => 'El establecimiento 001 está cerrado',
        ])
        ->and($fresh->sri_last_attempt_at)->not->toBeNull();
});

it('clears sri_error once the voucher is authorized', function () {
    $ws = sriPayloadWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    $invoice = Invoice::factory()->for($ws)->create([
        'patient_id'       => $patient->id,
        'sri_status'       => 'pending',
        'sri_clave_acceso' => str_repeat('2', 49),
        'sri_xml'          => '<factura/>',
        'sri_error'        => ['source' => 'internal', 'category' => 'transport'],
    ]);

    $this->mock(SriSoapClient::class, function ($mock) {
        $mock->shouldReceive('send')->once()->andReturn(['estado' => 'RECIBIDA', 'mensajes' => []]);
        $mock->shouldReceive('authorize')->once()->andReturn([
            'estado'             => 'AUTORIZADO',
            'numeroAutorizacion' => str_repeat('2', 49),
            'fechaAutorizacion'  => '2026-08-01T10:00:00-05:00',
            'mensajes'           => [],
        ]);
    });

    app(SriService::class)->authorizeInvoice($invoice);

    expect($invoice->fresh()->sri_error)->toBeNull();
});

it('records an internal failure as a bare category, with no exception text', function () {
    // Without this, the mocked SriService never runs the real attempt-counter
    // increment, so requeueIfPending() keeps seeing 0 < MAX_ATTEMPTS forever;
    // on the sync queue driver used in testing that redispatches in-process,
    // recursing without bound. Queue::fake() breaks the loop, same as every
    // other test in AuthorizeSriVoucherJobTest.php that exercises a retry.
    Queue::fake();

    $ws = sriPayloadWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    $invoice = Invoice::factory()->for($ws)->create([
        'patient_id'       => $patient->id,
        'sri_status'       => 'pending',
        'sri_clave_acceso' => str_repeat('3', 49),
        'sri_xml'          => '<factura/>',
    ]);

    $this->mock(SriService::class, function ($mock) {
        $mock->shouldReceive('authorizeInvoice')
            ->once()
            ->andThrow(new RuntimeException('SRI reception transport error: Connection refused at /var/www/html/app/Services/Sri/SriSoapClient.php'));
    });

    (new App\Jobs\AuthorizeSriVoucherJob($invoice))->handle(app(SriService::class));

    $error = $invoice->fresh()->sri_error;

    expect($error)->toEqual(['source' => 'internal', 'category' => 'transport'])
        ->and(json_encode($error))->not->toContain('/var/www/html')
        ->and(json_encode($error))->not->toContain('Connection refused');
});

it('exposes a stale threshold derived from the retry budget', function () {
    // 10 attempts × 60 s = 10 min of retry window, plus 50 % margin.
    expect(App\Jobs\AuthorizeSriVoucherJob::staleAfterSeconds())->toBe(900);
});

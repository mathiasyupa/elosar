<?php

use App\Models\Workspace;
use App\Services\Sri\SriClaveAccesoBuilder;
use Carbon\Carbon;

function sriWorkspace(array $overrides = []): Workspace
{
    return Workspace::factory()->make(array_merge([
        'ruc'                 => '1790011674001',
        'cod_establecimiento' => '001',
        'pto_emision'         => '001',
        'sri_environment'     => 'test',
    ], $overrides));
}

it('builds a 49-digit access key', function () {
    $clave = app(SriClaveAccesoBuilder::class)->build(
        sriWorkspace(),
        Carbon::parse('2026-06-12'),
        SriClaveAccesoBuilder::TIPO_FACTURA,
        '000000001',
        codigoNumerico: '12345678',
    );

    expect($clave)->toHaveLength(49)
        ->and($clave)->toMatch('/^\d{49}$/');
});

it('embeds date, document type, ruc and environment in order', function () {
    $clave = app(SriClaveAccesoBuilder::class)->build(
        sriWorkspace(),
        Carbon::parse('2026-06-12'),
        SriClaveAccesoBuilder::TIPO_FACTURA,
        '000000001',
        codigoNumerico: '12345678',
    );

    // fecha(8) + tipoDoc(2) + ruc(13) + ambiente(1)
    expect(substr($clave, 0, 8))->toBe('12062026')         // ddmmyyyy
        ->and(substr($clave, 8, 2))->toBe('01')            // factura
        ->and(substr($clave, 10, 13))->toBe('1790011674001')
        ->and(substr($clave, 23, 1))->toBe('1');           // test environment
});

it('appends a valid mod-11 check digit', function () {
    $builder = app(SriClaveAccesoBuilder::class);

    $clave = $builder->build(
        sriWorkspace(),
        Carbon::parse('2026-06-12'),
        SriClaveAccesoBuilder::TIPO_FACTURA,
        '000000001',
        codigoNumerico: '12345678',
    );

    $base48 = substr($clave, 0, 48);
    $checkDigit = substr($clave, 48, 1);

    expect($builder->calculateMod11($base48))->toBe($checkDigit);
});

it('uses environment code 2 in production', function () {
    $clave = app(SriClaveAccesoBuilder::class)->build(
        sriWorkspace(['sri_environment' => 'production']),
        Carbon::parse('2026-06-12'),
        SriClaveAccesoBuilder::TIPO_FACTURA,
        '000000001',
        codigoNumerico: '12345678',
    );

    expect(substr($clave, 23, 1))->toBe('2');
});

it('refuses to build a key for an unrecognized environment', function () {
    // Regression: the settings API used to persist the ambiente digit ("2")
    // instead of "production". That value matched no branch, so the key was
    // silently built as pruebas — a voucher with no fiscal validity.
    app(SriClaveAccesoBuilder::class)->build(
        sriWorkspace(['sri_environment' => '2']),
        Carbon::parse('2026-06-12'),
        SriClaveAccesoBuilder::TIPO_FACTURA,
        '000000001',
        codigoNumerico: '12345678',
    );
})->throws(RuntimeException::class, 'unrecognized sri_environment');

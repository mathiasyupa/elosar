<?php

use App\Services\Sri\SriError;

/*
| The security invariant of this feature: an internal failure must never carry
| exception text out of the server. It is enforced structurally — internal()
| takes a category, not a message — and this test is what keeps it that way.
*/

it('keeps the first SRI message that carries an identifier', function () {
    $payload = SriError::fromSriMessages([
        ['identificador' => null, 'mensaje' => 'sin codigo', 'informacionAdicional' => null, 'tipo' => 'ERROR'],
        ['identificador' => '56', 'mensaje' => 'ERROR ESTABLECIMIENTO CERRADO', 'informacionAdicional' => 'El establecimiento 001 está cerrado', 'tipo' => 'ERROR'],
    ]);

    expect($payload)->toBe([
        'source'  => 'sri',
        'code'    => '56',
        'message' => 'ERROR ESTABLECIMIENTO CERRADO',
        'info'    => 'El establecimiento 001 está cerrado',
    ]);
});

it('returns null when the SRI sent no usable message', function () {
    expect(SriError::fromSriMessages([]))->toBeNull()
        ->and(SriError::fromSriMessages([['identificador' => null, 'mensaje' => null]]))->toBeNull();
});

it('never lets exception text into an internal payload', function () {
    $payload = SriError::internal(SriError::CATEGORY_TRANSPORT);

    expect($payload)->toBe(['source' => 'internal', 'category' => 'transport'])
        ->and(array_keys($payload))->toBe(['source', 'category']);
});

it('rejects a category outside the known set', function () {
    SriError::internal('Undefined property: stdClass::$autorizacion');
})->throws(InvalidArgumentException::class);

it('maps a throwable to a category without copying its message', function () {
    expect(SriError::categoryFor(new RuntimeException('SRI reception transport error: boom')))
        ->toBe(SriError::CATEGORY_TRANSPORT)
        ->and(SriError::categoryFor(new RuntimeException('Could not open the SRI certificate. Check the password.')))
        ->toBe(SriError::CATEGORY_CERTIFICATE)
        ->and(SriError::categoryFor(new RuntimeException('SRI XSD validation failed: bad node')))
        ->toBe(SriError::CATEGORY_SCHEMA)
        ->and(SriError::categoryFor(new RuntimeException('Workspace [1] is not configured for SRI electronic invoicing.')))
        ->toBe(SriError::CATEGORY_CONFIG);
});

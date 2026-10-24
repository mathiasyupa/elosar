<?php

use App\Rules\EcuadorianId;
use Illuminate\Support\Facades\Validator;

function passesEcuadorianId(string $value, string $type = 'auto'): bool
{
    return Validator::make(
        ['id' => $value],
        ['id' => new EcuadorianId($type)],
    )->passes();
}

it('accepts a valid cédula', function () {
    expect(passesEcuadorianId('1710034065'))->toBeTrue();
});

it('rejects a cédula with a wrong check digit', function () {
    expect(passesEcuadorianId('1710034064'))->toBeFalse();
});

it('rejects a cédula with an invalid province', function () {
    expect(passesEcuadorianId('9910034065'))->toBeFalse();
});

it('rejects a cédula whose third digit is 6 or greater', function () {
    expect(passesEcuadorianId('1760034065'))->toBeFalse();
});

it('accepts a valid natural-person RUC', function () {
    expect(passesEcuadorianId('1710034065001'))->toBeTrue();
});

it('accepts a valid juridical RUC', function () {
    expect(passesEcuadorianId('1790011674001'))->toBeTrue();
});

it('rejects a juridical RUC with a wrong check digit', function () {
    expect(passesEcuadorianId('1790011675001'))->toBeFalse();
});

it('rejects a RUC whose establishment suffix is 000', function () {
    expect(passesEcuadorianId('1790011674000'))->toBeFalse();
});

it('skips validation for empty values (handled by required)', function () {
    expect(passesEcuadorianId(''))->toBeTrue();
});

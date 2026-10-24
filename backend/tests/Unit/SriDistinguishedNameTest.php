<?php

use App\Services\Sri\SriDistinguishedName;

/*
| Renders <ds:X509IssuerName> the way the SRI's Java validator does.
|
| Regression origin: a real UANATACA certificate was signed with
| "organizationIdentifier=VATES-A66721499" (PHP's long name) where the SRI
| expects "2.5.4.97=#0c0f…" (dotted OID + hex DER, per RFC 2253 §2.4 for
| attribute types Java has no keyword for). Every voucher came back as
| "39 FIRMA INVALIDA — La información sobre el certificado de firma no se
| ajusta a XAdES", with the signature itself being cryptographically fine.
*/

function issuerFixture(): string
{
    return file_get_contents(base_path('tests/Fixtures/sri/issuer-with-oid.pem'));
}

it('emits attribute types Java has no RFC 2253 keyword for as a dotted OID with a hex value', function () {
    $issuer = (new SriDistinguishedName())->issuerFromPem(issuerFixture());

    // organizationIdentifier is OID 2.5.4.97; the value is DER UTF8String
    // ("VATES-A66721499") — tag 0c, length 0f, then the bytes.
    expect($issuer)->toContain('2.5.4.97=#0c0f56415445532d413636373231343939')
        ->and($issuer)->not->toContain('organizationIdentifier');
});

it('keeps the nine RFC 2253 keywords as keywords', function () {
    $issuer = (new SriDistinguishedName())->issuerFromPem(issuerFixture());

    expect($issuer)->toContain('CN=PRUEBA CA 2016')
        ->and($issuer)->toContain('OU=TSP-PRUEBA')
        ->and($issuer)->toContain('O=PRUEBA S.A.')
        ->and($issuer)->toContain('C=ES');
});

it('escapes commas inside an attribute value', function () {
    // "Barcelona, sede" must not read as two RDNs.
    expect((new SriDistinguishedName())->issuerFromPem(issuerFixture()))
        ->toContain('L=Barcelona\\, sede');
});

it('emits the RDNs most-specific first, reversing the DER order', function () {
    expect((new SriDistinguishedName())->issuerFromPem(issuerFixture()))
        ->toBe('C=ES,L=Barcelona\\, sede,O=PRUEBA S.A.,OU=TSP-PRUEBA,CN=PRUEBA CA 2016,2.5.4.97=#0c0f56415445532d413636373231343939');
});

it('returns null on input it cannot walk, so the signer can fall back', function () {
    $dn = new SriDistinguishedName();

    expect($dn->issuerFromPem('no soy un certificado'))->toBeNull()
        ->and($dn->issuerFromPem("-----BEGIN CERTIFICATE-----\nZm9v\n-----END CERTIFICATE-----"))->toBeNull();
});

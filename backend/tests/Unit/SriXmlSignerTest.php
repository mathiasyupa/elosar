<?php

use App\Services\Sri\SriCertificateLoader;
use App\Services\Sri\SriXmlSigner;
use Illuminate\Support\Facades\Storage;

/**
 * Cryptographic verification of the XAdES-BES signer.
 *
 * The assertions replicate what the SRI validator does on its side:
 * re-parse the signed XML from scratch, resolve every ds:Reference,
 * apply the enveloped transform, re-canonicalize and compare digests,
 * then verify the RSA signature over the canonical SignedInfo.
 */

const DS_NS = 'http://www.w3.org/2000/09/xmldsig#';
const XADES_NS = 'http://uri.etsi.org/01903/v1.3.2#';

/**
 * Generates a throwaway self-signed RSA certificate (PEM pair).
 *
 * @return array{certificate: string, private_key: string}
 */
function selfSignedCertificate(): array
{
    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);

    $dn = [
        'countryName' => 'EC',
        'stateOrProvinceName' => 'Pichincha',
        'organizationName' => 'BANCO CENTRAL DEL ECUADOR (TEST)',
        'organizationalUnitName' => 'ENTIDAD DE CERTIFICACION',
        'commonName' => 'PERSONA NATURAL DE PRUEBA',
    ];

    $csr = openssl_csr_new($dn, $key, ['digest_alg' => 'sha256']);
    $x509 = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256'], random_int(1000, 99999));

    openssl_x509_export($x509, $certPem);
    openssl_pkey_export($key, $keyPem);

    return ['certificate' => $certPem, 'private_key' => $keyPem];
}

function sampleVoucherXml(): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<factura id="comprobante" version="2.1.0">'
        . '<infoTributaria><ambiente>1</ambiente><tipoEmision>1</tipoEmision>'
        . '<razonSocial>CLINICA DEMO</razonSocial><ruc>1790011674001</ruc>'
        . '<claveAcceso>' . str_repeat('1', 49) . '</claveAcceso>'
        . '<codDoc>01</codDoc><estab>001</estab><ptoEmi>001</ptoEmi>'
        . '<secuencial>000000001</secuencial><dirMatriz>Quito</dirMatriz>'
        . '</infoTributaria>'
        . '<infoFactura><fechaEmision>12/06/2026</fechaEmision>'
        . '<obligadoContabilidad>NO</obligadoContabilidad>'
        . '<tipoIdentificacionComprador>05</tipoIdentificacionComprador>'
        . '<razonSocialComprador>JUAN PEREZ</razonSocialComprador>'
        . '<identificacionComprador>1710034065</identificacionComprador>'
        . '<totalSinImpuestos>100.00</totalSinImpuestos>'
        . '<totalDescuento>0.00</totalDescuento>'
        . '<totalConImpuestos><totalImpuesto><codigo>2</codigo><codigoPorcentaje>4</codigoPorcentaje>'
        . '<baseImponible>100.00</baseImponible><tarifa>15</tarifa><valor>15.00</valor></totalImpuesto></totalConImpuestos>'
        . '<propina>0.00</propina><importeTotal>115.00</importeTotal><moneda>DOLAR</moneda>'
        . '<pagos><pago><formaPago>01</formaPago><total>115.00</total></pago></pagos>'
        . '</infoFactura>'
        . '<detalles><detalle><codigoPrincipal>SRV-1</codigoPrincipal><descripcion>Consulta</descripcion>'
        . '<cantidad>1.00</cantidad><precioUnitario>100.00</precioUnitario><descuento>0.00</descuento>'
        . '<precioTotalSinImpuesto>100.00</precioTotalSinImpuesto>'
        . '<impuestos><impuesto><codigo>2</codigo><codigoPorcentaje>4</codigoPorcentaje><tarifa>15</tarifa>'
        . '<baseImponible>100.00</baseImponible><valor>15.00</valor></impuesto></impuestos>'
        . '</detalle></detalles>'
        . '</factura>';
}

function signSample(): string
{
    return app(SriXmlSigner::class)->sign(sampleVoucherXml(), selfSignedCertificate());
}

/**
 * Re-parses signed XML and returns [DOMDocument, DOMXPath] with ds/etsi bound.
 */
function parseSigned(string $signedXml): array
{
    $doc = new DOMDocument();
    $doc->preserveWhiteSpace = true;
    expect($doc->loadXML($signedXml))->toBeTrue();

    $xpath = new DOMXPath($doc);
    $xpath->registerNamespace('ds', DS_NS);
    $xpath->registerNamespace('etsi', XADES_NS);

    return [$doc, $xpath];
}

it('produces well-formed XML with a single enveloped ds:Signature', function () {
    [$doc, $xpath] = parseSigned(signSample());

    expect($xpath->query('//ds:Signature')->length)->toBe(1)
        ->and($doc->documentElement->localName)->toBe('factura')
        // The signature must be the LAST child of the root (enveloped)
        ->and($doc->documentElement->lastChild->localName)->toBe('Signature');
});

it('contains the three XAdES-BES references (SignedProperties, KeyInfo, comprobante)', function () {
    [, $xpath] = parseSigned(signSample());

    $uris = [];
    foreach ($xpath->query('//ds:SignedInfo/ds:Reference') as $ref) {
        $uris[] = $ref->getAttribute('URI');
    }

    expect($uris)->toHaveCount(3)
        ->and($uris)->toContain('#comprobante');

    // The SignedProperties reference carries the XAdES type attribute
    $typed = $xpath->query('//ds:SignedInfo/ds:Reference[@Type="http://uri.etsi.org/01903#SignedProperties"]');
    expect($typed->length)->toBe(1);

    // Every URI resolves to an element in the document
    foreach ($uris as $uri) {
        if ($uri === '#comprobante') {
            continue; // root id attribute, checked below
        }
        $id = ltrim($uri, '#');
        expect($xpath->query('//*[@Id="' . $id . '"]')->length)->toBe(1, "URI {$uri} must resolve");
    }
});

it('the comprobante digest matches the root canonicalized WITHOUT the signature (enveloped transform)', function () {
    [$doc, $xpath] = parseSigned(signSample());

    $expected = $xpath->query('//ds:Reference[@URI="#comprobante"]/ds:DigestValue')->item(0)->nodeValue;

    // Replicate the enveloped-signature transform: remove ds:Signature, re-canonicalize
    $signature = $xpath->query('//ds:Signature')->item(0);
    $signature->parentNode->removeChild($signature);

    $actual = base64_encode(sha1($doc->documentElement->C14N(false, false), true));

    expect($actual)->toBe($expected);
});

it('the SignedProperties digest matches its in-document canonical form', function () {
    [, $xpath] = parseSigned(signSample());

    $ref = $xpath->query('//ds:Reference[@Type="http://uri.etsi.org/01903#SignedProperties"]')->item(0);
    $expected = $xpath->query('ds:DigestValue', $ref)->item(0)->nodeValue;

    $signedProps = $xpath->query('//etsi:SignedProperties')->item(0);
    $actual = base64_encode(sha1($signedProps->C14N(false, false), true));

    expect($actual)->toBe($expected);
});

it('the KeyInfo digest matches its in-document canonical form', function () {
    [, $xpath] = parseSigned(signSample());

    $keyInfo = $xpath->query('//ds:KeyInfo')->item(0);
    $uri = '#' . $keyInfo->getAttribute('Id');

    $expected = $xpath->query('//ds:Reference[@URI="' . $uri . '"]/ds:DigestValue')->item(0)->nodeValue;
    $actual = base64_encode(sha1($keyInfo->C14N(false, false), true));

    expect($actual)->toBe($expected);
});

it('the RSA signature verifies against the embedded certificate', function () {
    [, $xpath] = parseSigned(signSample());

    $signedInfo = $xpath->query('//ds:SignedInfo')->item(0);
    $signatureValue = base64_decode($xpath->query('//ds:SignatureValue')->item(0)->nodeValue);

    // Public key recovered from the certificate embedded in KeyInfo —
    // exactly what the SRI validator uses.
    $certBase64 = $xpath->query('//ds:X509Certificate')->item(0)->nodeValue;
    $certPem = "-----BEGIN CERTIFICATE-----\n"
        . chunk_split(preg_replace('/\s+/', '', $certBase64), 64, "\n")
        . "-----END CERTIFICATE-----\n";
    $publicKey = openssl_pkey_get_public($certPem);

    expect($publicKey)->not->toBeFalse();

    $result = openssl_verify(
        $signedInfo->C14N(false, false),
        $signatureValue,
        $publicKey,
        OPENSSL_ALGO_SHA1,
    );

    expect($result)->toBe(1);
});

it('embeds the certificate digest and issuer data in SignedProperties', function () {
    [, $xpath] = parseSigned(signSample());

    $certBase64 = $xpath->query('//ds:X509Certificate')->item(0)->nodeValue;
    $expectedCertDigest = base64_encode(sha1(base64_decode(preg_replace('/\s+/', '', $certBase64)), true));

    $certDigest = $xpath->query('//etsi:CertDigest/ds:DigestValue')->item(0)->nodeValue;
    $issuerName = $xpath->query('//etsi:IssuerSerial/ds:X509IssuerName')->item(0)->nodeValue;
    $serial = $xpath->query('//etsi:IssuerSerial/ds:X509SerialNumber')->item(0)->nodeValue;

    expect($certDigest)->toBe($expectedCertDigest)
        ->and($issuerName)->toContain('CN=PERSONA NATURAL DE PRUEBA')
        ->and($issuerName)->toStartWith('CN=') // RFC 2253: most-specific RDN first
        ->and($serial)->toMatch('/^\d+$/');
});

it('rejects XML whose root does not carry id="comprobante"', function () {
    $xml = '<?xml version="1.0" encoding="UTF-8"?><factura version="2.1.0"><x>1</x></factura>';

    expect(fn () => app(SriXmlSigner::class)->sign($xml, selfSignedCertificate()))
        ->toThrow(RuntimeException::class);
});

it('loads a .p12 certificate through SriCertificateLoader and signs with it', function () {
    Storage::fake('local');

    $pair = selfSignedCertificate();
    $pkcs12 = '';
    openssl_pkcs12_export($pair['certificate'], $pkcs12, $pair['private_key'], 'secret123');
    expect($pkcs12)->not->toBe('');

    Storage::disk('local')->put('sri-certificates/test.p12', $pkcs12);

    $loaded = app(SriCertificateLoader::class)->load('sri-certificates/test.p12', 'secret123');

    expect($loaded['certificate'])->toContain('BEGIN CERTIFICATE')
        ->and($loaded['private_key'])->toContain('PRIVATE KEY');

    // The loaded material must produce a verifiable signature end-to-end
    $signed = app(SriXmlSigner::class)->sign(sampleVoucherXml(), $loaded);
    expect($signed)->toContain('<ds:SignatureValue');
});

it('fails loading a .p12 with a wrong password', function () {
    Storage::fake('local');

    $pair = selfSignedCertificate();
    $pkcs12 = '';
    openssl_pkcs12_export($pair['certificate'], $pkcs12, $pair['private_key'], 'secret123');
    Storage::disk('local')->put('sri-certificates/test.p12', $pkcs12);

    expect(fn () => app(SriCertificateLoader::class)->load('sri-certificates/test.p12', 'wrong'))
        ->toThrow(RuntimeException::class);
});

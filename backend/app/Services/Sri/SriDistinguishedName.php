<?php

namespace App\Services\Sri;

/**
 * Renders a certificate's issuer Distinguished Name the way the SRI's Java
 * validator does, for the XAdES <ds:X509IssuerName> element.
 *
 * WHY THIS EXISTS
 * ---------------
 * The SRI validates the signature with Java's X500Principal.getName(RFC2253),
 * which only knows nine attribute keywords:
 *
 *     CN, L, ST, O, OU, C, STREET, DC, UID
 *
 * Every OTHER attribute type must be emitted as its dotted OID with the value
 * as the hex of its raw DER encoding (RFC 2253 §2.4). PHP's openssl instead
 * prints long names it knows, so a UANATACA-issued certificate came out as:
 *
 *     organizationIdentifier=VATES-A66721499,CN=UANATACA CA2 2016,...
 *
 * when the SRI expects:
 *
 *     2.5.4.97=#0c0f56415445532d413636373231343939,CN=UANATACA CA2 2016,...
 *
 * The mismatch is rejected with "39 FIRMA INVALIDA — La información sobre el
 * certificado de firma no se ajusta a XAdES".
 *
 * Rather than reconstruct the DER (which means guessing each attribute's ASN.1
 * string type), this walks the certificate's own bytes and reuses the exact
 * encoding the CA produced.
 *
 * @see https://uanataca.ec/facturadores.html  the expected string, from the CA
 */
class SriDistinguishedName
{
    /** The only attribute types Java's RFC2253 renderer has a keyword for. */
    private const RFC2253_KEYWORDS = [
        '2.5.4.3'                    => 'CN',
        '2.5.4.7'                    => 'L',
        '2.5.4.8'                    => 'ST',
        '2.5.4.10'                   => 'O',
        '2.5.4.11'                   => 'OU',
        '2.5.4.6'                    => 'C',
        '2.5.4.9'                    => 'STREET',
        '0.9.2342.19200300.100.1.25' => 'DC',
        '0.9.2342.19200300.100.1.1'  => 'UID',
    ];

    /** ASN.1 string types whose contents can be emitted as plain text. */
    private const TEXT_TYPES = [
        0x0C, // UTF8String
        0x13, // PrintableString
        0x16, // IA5String
        0x14, // TeletexString / T61String
        0x1E, // BMPString — decoded below
    ];

    /**
     * Builds the RFC 2253 issuer string for a PEM certificate, most-specific
     * RDN first (Java reverses the DER order).
     *
     * Returns null when the certificate cannot be walked, so the caller can
     * fall back instead of signing with a half-built name.
     */
    public function issuerFromPem(string $pem): ?string
    {
        $der = $this->pemToDer($pem);

        if ($der === null) {
            return null;
        }

        $attributes = $this->issuerAttributes($der);

        if ($attributes === null || $attributes === []) {
            return null;
        }

        $parts = [];
        foreach (array_reverse($attributes) as [$oid, $valueDer]) {
            $parts[] = $this->renderAttribute($oid, $valueDer);
        }

        return implode(',', $parts);
    }

    /**
     * @param  string  $valueDer  the attribute value's complete DER (tag included)
     */
    private function renderAttribute(string $oid, string $valueDer): string
    {
        $keyword = self::RFC2253_KEYWORDS[$oid] ?? null;

        // Unknown type → dotted OID + hex of the raw DER, exactly as the CA
        // encoded it. Lowercase hex, matching Java and the CA's own examples.
        if ($keyword === null) {
            return $oid . '=#' . bin2hex($valueDer);
        }

        $text = $this->decodeTextValue($valueDer);

        // A known keyword whose value is not a plain string still has to fall
        // back to the hex form — that is what RFC 2253 mandates.
        if ($text === null) {
            return $keyword . '=#' . bin2hex($valueDer);
        }

        return $keyword . '=' . $this->escape($text);
    }

    /**
     * Extracts [oid, rawValueDer] for every attribute of the issuer Name.
     *
     * Certificate ::= SEQUENCE { tbsCertificate, signatureAlgorithm, signature }
     * TBSCertificate ::= SEQUENCE { [0] version, serialNumber, signature,
     *                               issuer, validity, subject, ... }
     *
     * @return array<int,array{0:string,1:string}>|null
     */
    private function issuerAttributes(string $der): ?array
    {
        $pos = 0;
        $cert = $this->readTlv($der, $pos);           // Certificate SEQUENCE
        if ($cert === null || $cert['tag'] !== 0x30) {
            return null;
        }

        $pos = $cert['start'];
        $tbs = $this->readTlv($der, $pos);            // TBSCertificate SEQUENCE
        if ($tbs === null || $tbs['tag'] !== 0x30) {
            return null;
        }

        $pos = $tbs['start'];

        // [0] EXPLICIT version — optional (absent on v1 certificates).
        $field = $this->readTlv($der, $pos);
        if ($field !== null && $field['tag'] === 0xA0) {
            $pos = $field['end'];
            $field = $this->readTlv($der, $pos);
        }

        // serialNumber INTEGER
        if ($field === null || $field['tag'] !== 0x02) {
            return null;
        }
        $pos = $field['end'];

        // signature AlgorithmIdentifier SEQUENCE
        $field = $this->readTlv($der, $pos);
        if ($field === null || $field['tag'] !== 0x30) {
            return null;
        }
        $pos = $field['end'];

        // issuer Name ::= SEQUENCE OF RelativeDistinguishedName
        $issuer = $this->readTlv($der, $pos);
        if ($issuer === null || $issuer['tag'] !== 0x30) {
            return null;
        }

        return $this->parseName($der, $issuer['start'], $issuer['end']);
    }

    /**
     * @return array<int,array{0:string,1:string}>|null
     */
    private function parseName(string $der, int $pos, int $end): ?array
    {
        $attributes = [];

        while ($pos < $end) {
            $rdn = $this->readTlv($der, $pos);        // RDN ::= SET OF
            if ($rdn === null || $rdn['tag'] !== 0x31) {
                return null;
            }

            $inner = $rdn['start'];
            while ($inner < $rdn['end']) {
                $pair = $this->readTlv($der, $inner); // AttributeTypeAndValue SEQUENCE
                if ($pair === null || $pair['tag'] !== 0x30) {
                    return null;
                }

                $p = $pair['start'];
                $type = $this->readTlv($der, $p);     // type OBJECT IDENTIFIER
                if ($type === null || $type['tag'] !== 0x06) {
                    return null;
                }

                $value = $this->readTlv($der, $type['end']); // value ANY
                if ($value === null) {
                    return null;
                }

                $attributes[] = [
                    $this->decodeOid(substr($der, $type['start'], $type['length'])),
                    substr($der, $value['offset'], $value['end'] - $value['offset']),
                ];

                $inner = $pair['end'];
            }

            $pos = $rdn['end'];
        }

        return $attributes;
    }

    /**
     * Reads one DER TLV starting at $pos.
     *
     * @return array{tag:int,offset:int,start:int,length:int,end:int}|null
     */
    private function readTlv(string $der, int $pos): ?array
    {
        $total = strlen($der);
        if ($pos + 2 > $total) {
            return null;
        }

        $offset = $pos;
        $tag = ord($der[$pos++]);
        $first = ord($der[$pos++]);

        if ($first < 0x80) {
            $length = $first;
        } else {
            $bytes = $first & 0x7F;
            // Indefinite length is not legal in DER, and a length wider than
            // 4 bytes cannot describe anything inside a certificate.
            if ($bytes === 0 || $bytes > 4 || $pos + $bytes > $total) {
                return null;
            }
            $length = 0;
            for ($i = 0; $i < $bytes; $i++) {
                $length = ($length << 8) | ord($der[$pos++]);
            }
        }

        if ($pos + $length > $total) {
            return null;
        }

        return [
            'tag'    => $tag,
            'offset' => $offset,
            'start'  => $pos,
            'length' => $length,
            'end'    => $pos + $length,
        ];
    }

    /** Decodes OID content bytes into dotted-decimal notation. */
    private function decodeOid(string $content): string
    {
        if ($content === '') {
            return '';
        }

        $first = ord($content[0]);
        $parts = [intdiv($first, 40), $first % 40];

        $value = 0;
        for ($i = 1, $len = strlen($content); $i < $len; $i++) {
            $byte = ord($content[$i]);
            $value = ($value << 7) | ($byte & 0x7F);

            if (($byte & 0x80) === 0) {
                $parts[] = $value;
                $value = 0;
            }
        }

        return implode('.', $parts);
    }

    /** Returns the text of a string-typed value, or null if it is not one. */
    private function decodeTextValue(string $valueDer): ?string
    {
        $pos = 0;
        $tlv = $this->readTlv($valueDer, $pos);

        if ($tlv === null || ! in_array($tlv['tag'], self::TEXT_TYPES, true)) {
            return null;
        }

        $content = substr($valueDer, $tlv['start'], $tlv['length']);

        if ($tlv['tag'] === 0x1E) { // BMPString is UTF-16BE
            $converted = @mb_convert_encoding($content, 'UTF-8', 'UTF-16BE');

            return $converted === false ? null : $converted;
        }

        return $content;
    }

    /** RFC 2253 §2.4 escaping for a plain-text attribute value. */
    private function escape(string $value): string
    {
        $escaped = '';
        $len = strlen($value);

        for ($i = 0; $i < $len; $i++) {
            $char = $value[$i];

            $needsEscape = in_array($char, [',', '+', '"', '\\', '<', '>', ';'], true)
                || ($i === 0 && ($char === '#' || $char === ' '))
                || ($i === $len - 1 && $char === ' ');

            $escaped .= $needsEscape ? '\\' . $char : $char;
        }

        return $escaped;
    }

    private function pemToDer(string $pem): ?string
    {
        if (! preg_match('/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s', $pem, $m)) {
            return null;
        }

        $der = base64_decode(preg_replace('/\s+/', '', $m[1]), true);

        return $der === false || $der === '' ? null : $der;
    }
}

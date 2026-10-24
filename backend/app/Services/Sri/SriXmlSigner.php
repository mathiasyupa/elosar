<?php

namespace App\Services\Sri;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/**
 * Signs an SRI XML voucher with a XAdES-BES enveloped signature.
 *
 * Structure (what the SRI offline reception service validates):
 *   - three references: SignedProperties, KeyInfo and the #comprobante root
 *   - SHA-1 digests, inclusive C14N, RSA-SHA1 signature
 *
 * The signing is done in two phases to guarantee digest consistency:
 *   1. the digest of the root element is taken BEFORE the signature is
 *      appended (equivalent to the enveloped-signature transform), and
 *   2. the SignedProperties/KeyInfo digests and the SignatureValue are
 *      computed over the nodes AS THEY EXIST in the final document, so the
 *      canonical form (inherited namespaces included) is byte-identical to
 *      what the SRI validator will recompute on its side.
 *
 * @see https://www.sri.gob.ec — Ficha técnica de comprobantes electrónicos
 */
class SriXmlSigner
{
    private const C14N = 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315';
    private const SIG_METHOD = 'http://www.w3.org/2000/09/xmldsig#rsa-sha1';
    private const DIGEST_METHOD = 'http://www.w3.org/2000/09/xmldsig#sha1';
    private const ENVELOPED = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';
    private const SIGNED_PROPS_TYPE = 'http://uri.etsi.org/01903#SignedProperties';
    private const XADES_NS = 'http://uri.etsi.org/01903/v1.3.2#';
    private const DS_NS = 'http://www.w3.org/2000/09/xmldsig#';

    public function __construct(private SriDistinguishedName $dn = new SriDistinguishedName())
    {
    }

    /**
     * @param  array{certificate: string, private_key: string, extra_certs?: array<int,string>}  $certificate
     */
    public function sign(string $xml, array $certificate): string
    {
        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = true;

        if (! $doc->loadXML($xml)) {
            throw new RuntimeException('Invalid XML passed to the SRI signer.');
        }

        $root = $doc->documentElement;
        if (! $root || $root->getAttribute('id') !== 'comprobante') {
            throw new RuntimeException('The SRI voucher root must carry id="comprobante".');
        }

        $certInfo = openssl_x509_parse($certificate['certificate']);
        if ($certInfo === false) {
            throw new RuntimeException('Could not parse the SRI signing certificate.');
        }

        $pubKey = openssl_pkey_get_public($certificate['certificate']);
        $keyDetails = $pubKey ? openssl_pkey_get_details($pubKey) : false;
        if ($keyDetails === false || ! isset($keyDetails['rsa'])) {
            throw new RuntimeException('The SRI signing certificate does not contain an RSA public key.');
        }

        // ── Phase 1: digest of the voucher BEFORE the signature exists ───
        // This equals applying the enveloped-signature transform on the
        // validator side (root canonicalized with the ds:Signature removed).
        $compDigest = base64_encode(sha1($root->C14N(false, false), true));

        // ── Identifiers (numeric suffix, as in SRI reference samples) ────
        $n = random_int(100000, 999999);
        $ids = [
            'signature'       => "Signature{$n}",
            'signatureValue'  => "SignatureValue{$n}",
            'signedInfo'      => "Signature-SignedInfo{$n}",
            'signedProps'     => "Signature{$n}-SignedProperties{$n}",
            'certificate'     => "Certificate{$n}",
            'refComp'         => "Reference-ID-{$n}",
            'refSignedProps'  => "SignedPropertiesID{$n}",
            'object'          => "Signature{$n}-Object{$n}",
        ];

        // ── Certificate data ─────────────────────────────────────────────
        $certBase64 = $this->pemToBase64($certificate['certificate']);
        $certDigest = base64_encode(sha1(base64_decode($certBase64), true));
        $modulus = base64_encode($keyDetails['rsa']['n']);
        $exponent = base64_encode($keyDetails['rsa']['e']);
        // Read the issuer straight from the certificate's DER so attribute types
        // Java has no RFC 2253 keyword for (organizationIdentifier, seen on every
        // UANATACA certificate) come out as "2.5.4.97=#0c0f…" and not as a long
        // name the SRI validator will not recognize. See SriDistinguishedName.
        $issuerName = $this->dn->issuerFromPem($certificate['certificate'])
            ?? $this->formatIssuer($certInfo['issuer'] ?? []);
        $serialNumber = $certInfo['serialNumber'] ?? '0';
        $signingTime = now()->format('Y-m-d\TH:i:sP');

        // ── Phase 2: append the signature skeleton to the document ───────
        $signatureXml = $this->signatureSkeleton(
            $ids,
            $compDigest,
            $certBase64,
            $certDigest,
            $modulus,
            $exponent,
            $issuerName,
            (string) $serialNumber,
            $signingTime,
        );

        $fragment = $doc->createDocumentFragment();
        if (! $fragment->appendXML($signatureXml)) {
            throw new RuntimeException('Could not assemble the XAdES signature fragment.');
        }
        $root->appendChild($fragment);

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('ds', self::DS_NS);
        $xpath->registerNamespace('etsi', self::XADES_NS);

        // Digest SignedProperties and KeyInfo exactly as they sit in the
        // final document (inherited namespace context included).
        $signedProps = $this->queryOne($xpath, '//etsi:SignedProperties');
        $keyInfo = $this->queryOne($xpath, '//ds:KeyInfo');

        $this->setDigest($xpath, $ids['refSignedProps'], $signedProps);
        $this->setDigestByUri($xpath, '#' . $ids['certificate'], $keyInfo);

        // ── Sign the canonical SignedInfo ────────────────────────────────
        $signedInfo = $this->queryOne($xpath, '//ds:SignedInfo');
        $signature = '';

        if (! openssl_sign($signedInfo->C14N(false, false), $signature, $certificate['private_key'], OPENSSL_ALGO_SHA1)) {
            throw new RuntimeException('Could not compute the SRI signature value.');
        }

        $signatureValue = $this->queryOne($xpath, '//ds:SignatureValue');
        $signatureValue->nodeValue = base64_encode($signature);

        return $doc->saveXML();
    }

    /**
     * Builds the ds:Signature element with every digest that can be known
     * up-front; SignedProperties/KeyInfo digests and the SignatureValue are
     * filled in afterwards from the in-document nodes.
     *
     * @param  array<string,string>  $ids
     */
    private function signatureSkeleton(
        array $ids,
        string $compDigest,
        string $certBase64,
        string $certDigest,
        string $modulus,
        string $exponent,
        string $issuerName,
        string $serialNumber,
        string $signingTime,
    ): string {
        $e = fn (string $v): string => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return '<ds:Signature xmlns:ds="' . self::DS_NS . '" xmlns:etsi="' . self::XADES_NS . '" Id="' . $ids['signature'] . '">'
            . '<ds:SignedInfo Id="' . $ids['signedInfo'] . '">'
            . '<ds:CanonicalizationMethod Algorithm="' . self::C14N . '"></ds:CanonicalizationMethod>'
            . '<ds:SignatureMethod Algorithm="' . self::SIG_METHOD . '"></ds:SignatureMethod>'
            . '<ds:Reference Id="' . $ids['refSignedProps'] . '" Type="' . self::SIGNED_PROPS_TYPE . '" URI="#' . $ids['signedProps'] . '">'
            . '<ds:DigestMethod Algorithm="' . self::DIGEST_METHOD . '"></ds:DigestMethod>'
            . '<ds:DigestValue></ds:DigestValue>'
            . '</ds:Reference>'
            . '<ds:Reference URI="#' . $ids['certificate'] . '">'
            . '<ds:DigestMethod Algorithm="' . self::DIGEST_METHOD . '"></ds:DigestMethod>'
            . '<ds:DigestValue></ds:DigestValue>'
            . '</ds:Reference>'
            . '<ds:Reference Id="' . $ids['refComp'] . '" URI="#comprobante">'
            . '<ds:Transforms>'
            . '<ds:Transform Algorithm="' . self::ENVELOPED . '"></ds:Transform>'
            . '</ds:Transforms>'
            . '<ds:DigestMethod Algorithm="' . self::DIGEST_METHOD . '"></ds:DigestMethod>'
            . '<ds:DigestValue>' . $compDigest . '</ds:DigestValue>'
            . '</ds:Reference>'
            . '</ds:SignedInfo>'
            . '<ds:SignatureValue Id="' . $ids['signatureValue'] . '"></ds:SignatureValue>'
            . '<ds:KeyInfo Id="' . $ids['certificate'] . '">'
            . '<ds:X509Data>'
            . '<ds:X509Certificate>' . $certBase64 . '</ds:X509Certificate>'
            . '</ds:X509Data>'
            . '<ds:KeyValue>'
            . '<ds:RSAKeyValue>'
            . '<ds:Modulus>' . $modulus . '</ds:Modulus>'
            . '<ds:Exponent>' . $exponent . '</ds:Exponent>'
            . '</ds:RSAKeyValue>'
            . '</ds:KeyValue>'
            . '</ds:KeyInfo>'
            . '<ds:Object Id="' . $ids['object'] . '">'
            . '<etsi:QualifyingProperties Target="#' . $ids['signature'] . '">'
            . '<etsi:SignedProperties Id="' . $ids['signedProps'] . '">'
            . '<etsi:SignedSignatureProperties>'
            . '<etsi:SigningTime>' . $signingTime . '</etsi:SigningTime>'
            . '<etsi:SigningCertificate>'
            . '<etsi:Cert>'
            . '<etsi:CertDigest>'
            . '<ds:DigestMethod Algorithm="' . self::DIGEST_METHOD . '"></ds:DigestMethod>'
            . '<ds:DigestValue>' . $certDigest . '</ds:DigestValue>'
            . '</etsi:CertDigest>'
            . '<etsi:IssuerSerial>'
            . '<ds:X509IssuerName>' . $e($issuerName) . '</ds:X509IssuerName>'
            . '<ds:X509SerialNumber>' . $e($serialNumber) . '</ds:X509SerialNumber>'
            . '</etsi:IssuerSerial>'
            . '</etsi:Cert>'
            . '</etsi:SigningCertificate>'
            . '</etsi:SignedSignatureProperties>'
            . '<etsi:SignedDataObjectProperties>'
            . '<etsi:DataObjectFormat ObjectReference="#' . $ids['refComp'] . '">'
            . '<etsi:Description>contenido comprobante</etsi:Description>'
            . '<etsi:MimeType>text/xml</etsi:MimeType>'
            . '</etsi:DataObjectFormat>'
            . '</etsi:SignedDataObjectProperties>'
            . '</etsi:SignedProperties>'
            . '</etsi:QualifyingProperties>'
            . '</ds:Object>'
            . '</ds:Signature>';
    }

    /**
     * Fills the DigestValue of the Reference identified by its Id attribute
     * with the SHA-1 of the target node canonicalized in-document.
     */
    private function setDigest(DOMXPath $xpath, string $referenceId, DOMElement $target): void
    {
        $digestValue = $this->queryOne($xpath, '//ds:Reference[@Id="' . $referenceId . '"]/ds:DigestValue');
        $digestValue->nodeValue = base64_encode(sha1($target->C14N(false, false), true));
    }

    /**
     * Same as setDigest() but locating the Reference by its URI attribute
     * (the KeyInfo reference carries no Id).
     */
    private function setDigestByUri(DOMXPath $xpath, string $uri, DOMElement $target): void
    {
        $digestValue = $this->queryOne($xpath, '//ds:Reference[@URI="' . $uri . '"]/ds:DigestValue');
        $digestValue->nodeValue = base64_encode(sha1($target->C14N(false, false), true));
    }

    private function queryOne(DOMXPath $xpath, string $query): DOMElement
    {
        $node = $xpath->query($query)?->item(0);

        if (! $node instanceof DOMElement) {
            throw new RuntimeException("XAdES signature node not found for [{$query}].");
        }

        return $node;
    }

    private function pemToBase64(string $pem): string
    {
        $pem = preg_replace('/-----(BEGIN|END) CERTIFICATE-----/', '', $pem);

        return preg_replace('/\s+/', '', $pem);
    }

    /**
     * RFC 2253-style issuer DN, most-specific RDN first (CN=..., OU=..., O=..., C=...),
     * which is how the Java security stack used by the SRI renders it.
     *
     * @param  array<string,string|array<int,string>>  $issuer
     */
    private function formatIssuer(array $issuer): string
    {
        $parts = [];
        foreach (array_reverse($issuer, true) as $key => $value) {
            foreach ((array) $value as $v) {
                $parts[] = $key . '=' . $v;
            }
        }

        return implode(',', $parts);
    }
}

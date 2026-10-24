<?php

namespace App\Services\Sri;

use DOMDocument;
use RuntimeException;

/**
 * Validates generated voucher XML against the official SRI XSD schemas
 * (vendored under resources/sri/xsd/) before signing/submission.
 *
 * Catching schema violations locally avoids burning SRI submissions on
 * structural errors: the reception service would answer DEVUELTA with a
 * generic message, while libxml pinpoints the exact node.
 */
class SriXsdValidator
{
    private const SCHEMAS = [
        'factura' => 'factura_V2.1.0.xsd',
        'notaCredito' => 'NotaCredito_V1.1.0.xsd',
    ];

    /**
     * @throws RuntimeException listing every schema violation found.
     */
    public function validate(string $xml): void
    {
        $doc = new DOMDocument();

        if (! $doc->loadXML($xml)) {
            throw new RuntimeException('The SRI voucher XML is not well-formed.');
        }

        $rootName = $doc->documentElement?->localName;
        $schemaFile = self::SCHEMAS[$rootName] ?? null;

        if ($schemaFile === null) {
            throw new RuntimeException("No SRI schema registered for root element [{$rootName}].");
        }

        $schemaPath = resource_path('sri/xsd/' . $schemaFile);

        if (! is_file($schemaPath)) {
            throw new RuntimeException("SRI schema file missing at [{$schemaPath}].");
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $valid = $doc->schemaValidate($schemaPath);

        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $valid) {
            $details = collect($errors)
                ->map(fn ($e) => trim($e->message) . ' (line ' . $e->line . ')')
                ->unique()
                ->implode(' | ');

            throw new RuntimeException("SRI XSD validation failed: {$details}");
        }
    }
}

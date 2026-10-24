<?php

namespace App\Services\Sri;

use InvalidArgumentException;
use Throwable;

/**
 * Builds the `sri_error` payload — the only failure information that leaves the
 * server.
 *
 * SECURITY INVARIANT: an internal failure never carries its exception message.
 * That text can hold file paths, SQL fragments or certificate details, and it
 * would end up rendered in the doctor's browser. The invariant is structural:
 * internal() accepts a category from a closed set, not free text, so there is
 * no code path that can leak a message even by accident.
 *
 * The SRI's own messages ARE safe: they are written by the tax authority for
 * the emitter to read.
 */
final class SriError
{
    public const CATEGORY_TRANSPORT   = 'transport';
    public const CATEGORY_CERTIFICATE = 'certificate';
    public const CATEGORY_CONFIG      = 'config';
    public const CATEGORY_SCHEMA      = 'schema';

    private const CATEGORIES = [
        self::CATEGORY_TRANSPORT,
        self::CATEGORY_CERTIFICATE,
        self::CATEGORY_CONFIG,
        self::CATEGORY_SCHEMA,
    ];

    /**
     * The SRI answered with messages. Keeps the first one that carries an
     * identifier — that is the code the front-end catalog keys on.
     *
     * @param  array<int,array<string,mixed>>  $messages
     * @return array{source:string,code:string,message:?string,info:?string}|null
     */
    public static function fromSriMessages(array $messages): ?array
    {
        foreach ($messages as $m) {
            $code = (string) ($m['identificador'] ?? '');

            if ($code === '') {
                continue;
            }

            return [
                'source'  => 'sri',
                'code'    => $code,
                'message' => isset($m['mensaje']) ? (string) $m['mensaje'] : null,
                'info'    => isset($m['informacionAdicional']) ? (string) $m['informacionAdicional'] : null,
            ];
        }

        return null;
    }

    /**
     * Our own failure. Category only — see the invariant above.
     *
     * @return array{source:string,category:string}
     *
     * @throws InvalidArgumentException on anything outside the closed set,
     *         which is what stops an exception message from being passed in.
     */
    public static function internal(string $category): array
    {
        if (! in_array($category, self::CATEGORIES, true)) {
            throw new InvalidArgumentException(
                "Unknown SRI error category [{$category}]. Expected one of: "
                . implode(', ', self::CATEGORIES) . '.'
            );
        }

        return ['source' => 'internal', 'category' => $category];
    }

    /**
     * Classifies a throwable WITHOUT copying its message anywhere.
     */
    public static function categoryFor(Throwable $e): string
    {
        $message = $e->getMessage();

        return match (true) {
            str_contains($message, 'certificate')     => self::CATEGORY_CERTIFICATE,
            str_contains($message, 'XSD validation')  => self::CATEGORY_SCHEMA,
            str_contains($message, 'not configured')  => self::CATEGORY_CONFIG,
            default                                   => self::CATEGORY_TRANSPORT,
        };
    }
}

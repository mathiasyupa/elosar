<?php

namespace App\Services\Sri;

use RuntimeException;
use SoapClient;
use SoapFault;

/**
 * SOAP transport for the SRI offline scheme.
 *
 * Two services:
 *   - RecepcionComprobantesOffline::validarComprobante  → RECIBIDA | DEVUELTA
 *   - AutorizacionComprobantesOffline::autorizacionComprobante → AUTORIZADO | …
 */
class SriSoapClient
{
    private const ENDPOINTS = [
        'test' => 'https://celcer.sri.gob.ec/comprobantes-electronicos-ws/',
        'production' => 'https://cel.sri.gob.ec/comprobantes-electronicos-ws/',
    ];

    /**
     * Submits a signed XML voucher to the reception service.
     *
     * @return array{estado: string, mensajes: array<int,array<string,mixed>>}
     */
    public function send(string $signedXml, string $environment): array
    {
        $client = $this->client($environment, 'RecepcionComprobantesOffline');

        try {
            $response = $client->validarComprobante([
                'xml' => $signedXml,
            ]);
        } catch (SoapFault $e) {
            throw new RuntimeException('SRI reception transport error: ' . $e->getMessage(), 0, $e);
        }

        $estado = $response->RespuestaRecepcionComprobante->estado ?? 'DEVUELTA';
        $mensajes = $this->extractReceptionMessages($response);

        return [
            'estado' => $estado,
            'mensajes' => $mensajes,
        ];
    }

    /**
     * Polls the authorization service for a given access key.
     *
     * @return array{estado: string, numeroAutorizacion: ?string, fechaAutorizacion: ?string, mensajes: array<int,array<string,mixed>>}
     */
    public function authorize(string $claveAcceso, string $environment): array
    {
        $client = $this->client($environment, 'AutorizacionComprobantesOffline');

        try {
            $response = $client->autorizacionComprobante([
                'claveAccesoComprobante' => $claveAcceso,
            ]);
        } catch (SoapFault $e) {
            // The SRI throws EntityNotFoundException when the clave is not
            // indexed yet (e.g. polling right after reception). That is a
            // retry-later situation, not a transport failure.
            if (str_contains($e->getMessage(), 'EntityNotFound')) {
                return [
                    'estado' => 'EN PROCESAMIENTO',
                    'numeroAutorizacion' => null,
                    'fechaAutorizacion' => null,
                    'mensajes' => [],
                ];
            }

            throw new RuntimeException('SRI authorization transport error: ' . $e->getMessage(), 0, $e);
        }

        // A voucher that was received but not yet resolved comes back as an
        // EMPTY <autorizaciones/> element, which SoapClient hydrates into a
        // property-less stdClass — not null. Reading ->autorizacion on it threw
        // "Undefined property", which the job then logged as a transport error
        // instead of the plain "still processing" it actually is.
        $autorizacion = $response->RespuestaAutorizacionComprobante->autorizaciones->autorizacion ?? null;

        if ($autorizacion === null) {
            return [
                'estado' => 'EN PROCESAMIENTO',
                'numeroAutorizacion' => null,
                'fechaAutorizacion' => null,
                'mensajes' => [],
            ];
        }

        if (is_array($autorizacion)) {
            $autorizacion = $autorizacion[0];
        }

        return [
            'estado' => $autorizacion->estado ?? 'EN PROCESAMIENTO',
            'numeroAutorizacion' => $autorizacion->numeroAutorizacion ?? null,
            'fechaAutorizacion' => isset($autorizacion->fechaAutorizacion)
                ? (string) $autorizacion->fechaAutorizacion
                : null,
            'mensajes' => $this->extractAuthorizationMessages($autorizacion),
        ];
    }

    /**
     * Proves both SRI services are reachable, by downloading and parsing their
     * WSDL — the cheapest round trip that exercises DNS, TLS and the endpoint.
     *
     * Public on purpose: the diagnostics service used to reach the private
     * client() through reflection, which made the check impossible to fake in a
     * test and coupled two classes through a private method.
     *
     * @throws RuntimeException when either service cannot be reached.
     */
    public function ping(string $environment): void
    {
        $this->client($environment, 'RecepcionComprobantesOffline');
        $this->client($environment, 'AutorizacionComprobantesOffline');
    }

    private function client(string $environment, string $service): SoapClient
    {
        // Never fall back to a default environment: silently routing a
        // production voucher to celcer would authorize it with no fiscal
        // validity, and the emitter would have no way to notice.
        $base = self::ENDPOINTS[$environment] ?? throw new RuntimeException(
            "Unknown SRI environment [{$environment}]. Expected one of: "
            . implode(', ', array_keys(self::ENDPOINTS)) . '.'
        );

        $wsdl = $base . $service . '?wsdl';

        return new SoapClient($wsdl, [
            'trace' => true,
            'exceptions' => true,
            'connection_timeout' => 30,
            'cache_wsdl' => WSDL_CACHE_NONE,
        ]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function extractReceptionMessages(object $response): array
    {
        $comprobantes = $response->RespuestaRecepcionComprobante->comprobantes ?? null;
        if (! $comprobantes || ! isset($comprobantes->comprobante)) {
            return [];
        }

        $comprobante = $comprobantes->comprobante;
        if (is_array($comprobante)) {
            $comprobante = $comprobante[0];
        }

        return $this->normalizeMessages($comprobante->mensajes->mensaje ?? []);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function extractAuthorizationMessages(object $autorizacion): array
    {
        return $this->normalizeMessages($autorizacion->mensajes->mensaje ?? []);
    }

    /**
     * @param  object|array<int,object>  $mensaje
     * @return array<int,array<string,mixed>>
     */
    private function normalizeMessages(mixed $mensaje): array
    {
        if (empty($mensaje)) {
            return [];
        }

        $list = is_array($mensaje) ? $mensaje : [$mensaje];

        return array_map(fn ($m) => [
            'identificador' => $m->identificador ?? null,
            'mensaje' => $m->mensaje ?? null,
            'informacionAdicional' => $m->informacionAdicional ?? null,
            'tipo' => $m->tipo ?? null,
        ], $list);
    }
}

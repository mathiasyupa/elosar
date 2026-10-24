<?php

namespace App\Services\Sri;

use App\Models\Workspace;
use Carbon\CarbonInterface;

/**
 * Builds the 49-digit SRI access key (clave de acceso).
 *
 * Layout (concatenated digits):
 *   fecha(8 ddmmyyyy) + tipoComprobante(2) + ruc(13) + ambiente(1)
 *   + serie(6 = estab+ptoEmi) + secuencial(9) + codigoNumerico(8)
 *   + tipoEmision(1) + digitoVerificador(1 mod-11)
 */
class SriClaveAccesoBuilder
{
    public const TIPO_FACTURA = '01';
    public const TIPO_NOTA_CREDITO = '04';

    public const EMISION_NORMAL = '1';

    /**
     * @param  string  $tipoComprobante  self::TIPO_* (document type code)
     * @param  string  $secuencial       9-digit sequential (zero-padded)
     * @param  string|null  $codigoNumerico  8-digit numeric code; random when null
     */
    public function build(
        Workspace $workspace,
        CarbonInterface $issuedAt,
        string $tipoComprobante,
        string $secuencial,
        ?string $codigoNumerico = null,
    ): string {
        $fecha       = $issuedAt->format('dmY');
        $ruc         = str_pad((string) $workspace->ruc, 13, '0', STR_PAD_LEFT);
        $ambiente    = $workspace->sriAmbienteCode();
        $serie       = str_pad((string) $workspace->cod_establecimiento, 3, '0', STR_PAD_LEFT)
                     . str_pad((string) $workspace->pto_emision, 3, '0', STR_PAD_LEFT);
        $secuencial  = str_pad(preg_replace('/\D/', '', $secuencial), 9, '0', STR_PAD_LEFT);
        $codigoNum   = $codigoNumerico ?? str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        $tipoEmision = self::EMISION_NORMAL;

        $base48 = $fecha
            . $tipoComprobante
            . $ruc
            . $ambiente
            . $serie
            . $secuencial
            . $codigoNum
            . $tipoEmision;

        return $base48 . $this->calculateMod11($base48);
    }

    /**
     * Module-11 check digit over 48 digits with weights 2..7 cycling from the
     * right. Per SRI spec, a result of 11 maps to 0 and 10 maps to 1.
     */
    public function calculateMod11(string $digits48): string
    {
        $weights = [2, 3, 4, 5, 6, 7];
        $sum = 0;
        $w = 0;

        for ($i = strlen($digits48) - 1; $i >= 0; $i--) {
            $sum += (int) $digits48[$i] * $weights[$w];
            $w = ($w + 1) % count($weights);
        }

        $check = 11 - ($sum % 11);

        return match ($check) {
            11      => '0',
            10      => '1',
            default => (string) $check,
        };
    }
}

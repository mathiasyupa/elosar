<?php

namespace App\Models\Concerns;

use RuntimeException;

/**
 * Blocks the permanent (hard) deletion of an SRI-authorized voucher.
 *
 * Authorized electronic vouchers must be retained for 7 years by Ecuadorian
 * law. They may be soft-deleted (the row survives and is recoverable) but never
 * force-deleted. To reverse an authorized voucher use the void / credit-note
 * flow, not a delete.
 *
 * Applied to Invoice and CreditNote (both expose `sri_status`).
 */
trait GuardsSriRetention
{
    public static function bootGuardsSriRetention(): void
    {
        static::forceDeleting(function ($voucher) {
            if ($voucher->sri_status === 'authorized') {
                throw new RuntimeException(
                    'No se puede eliminar definitivamente un comprobante autorizado por el SRI '
                    . '(retención legal de 7 años). Anúlelo en su lugar.'
                );
            }
        });
    }
}

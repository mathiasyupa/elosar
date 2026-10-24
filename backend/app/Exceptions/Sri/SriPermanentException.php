<?php

namespace App\Exceptions\Sri;

use RuntimeException;

/**
 * A non-retryable SRI failure: structural / configuration problems that
 * resubmitting cannot fix (workspace not configured, XSD violation, bad
 * certificate, …).
 *
 * AuthorizeSriVoucherJob treats this as a definitive failure — it marks the
 * voucher rejected and dead-letters the job — instead of re-queueing like it
 * does for transient transport errors.
 */
class SriPermanentException extends RuntimeException
{
}

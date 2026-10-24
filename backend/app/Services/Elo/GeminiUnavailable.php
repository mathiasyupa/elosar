<?php

namespace App\Services\Elo;

use RuntimeException;

/** Gemini is not configured or did not answer usably. */
class GeminiUnavailable extends RuntimeException
{
    /** True when Google refused for quota (429): the plan's daily or per-minute limit. */
    public function overQuota(): bool
    {
        return $this->getCode() === 429;
    }
}

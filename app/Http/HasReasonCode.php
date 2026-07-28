<?php

declare(strict_types=1);

namespace BIomie\Http;

/**
 * Allows exceptions to expose a machine-readable reason code in API error responses.
 */
interface HasReasonCode
{
    public function getReasonCode(): string;
}

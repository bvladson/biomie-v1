<?php

declare(strict_types=1);

namespace BIomie\Support;

use BIomie\Http\HasReasonCode;
use RuntimeException;

/**
 * Thrown when BIOMIE_OPERATION_SCOPE_ENFORCED=1 and operation_scope column is missing.
 *
 * HTTP 503 — the service cannot safely filter commercial data without the scope column.
 * Propagates through ErrorBoundary as a structured JSON error with
 * reason_code = "operation_scope_missing".
 */
final class OperationScopeEnforcedException extends RuntimeException implements HasReasonCode
{
    public function __construct(string $message = '')
    {
        parent::__construct($message, 503);
    }

    public function getReasonCode(): string
    {
        return 'operation_scope_missing';
    }
}

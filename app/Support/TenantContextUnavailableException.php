<?php

declare(strict_types=1);

namespace BIomie\Support;

use BIomie\Http\HasReasonCode;
use RuntimeException;

final class TenantContextUnavailableException extends RuntimeException implements HasReasonCode
{
    public function __construct(
        private readonly string $reasonCode = 'tenant_context_unavailable',
        int $statusCode = 403,
        string $message = 'O contexto desta aba expirou ou não está mais disponível. Selecione a empresa novamente.',
    ) {
        parent::__construct($message, $statusCode);
    }

    public function getReasonCode(): string
    {
        return $this->reasonCode;
    }
}

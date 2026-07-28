<?php

declare(strict_types=1);

namespace BIomie\Modules\Sales\Application\Overview;

use RuntimeException;

final class OverviewResponseException extends RuntimeException
{
    /**
     * @param array<string,mixed> $payload
     */
    public function __construct(
        private readonly array $payload,
        private readonly int $statusCode,
    ) {
        parent::__construct((string) ($payload['message'] ?? 'Overview response error.'), $statusCode);
    }

    /**
     * @return array<string,mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}

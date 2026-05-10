<?php

declare(strict_types=1);

namespace BIomie\Modules\Geo\Infrastructure;

use BIomie\Logger;

final class GeoCnaeMaterializationLogger
{
    public function __construct(private readonly Logger $logger) {}

    /** @param array<string,mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->logger->warning($message, $context);
    }

    /** @param array<string,mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->logger->error($message, $context);
    }
}

<?php

declare(strict_types=1);

namespace BIomie\Migrations;

use RuntimeException;
use Throwable;

final class MigrationFailedException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $schemaVersion,
        public readonly string $description,
        public readonly string $migrationFile,
        public readonly string $connection,
        public readonly int $executionMs,
        Throwable $previous,
    ) {
        parent::__construct($message, 0, $previous);
    }
}

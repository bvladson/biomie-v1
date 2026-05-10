<?php

declare(strict_types=1);

namespace BIomie\AdminConsole;

use PDO;

final class AdminCommandContext
{
    public function __construct(
        public readonly int $userId,
        public readonly string $username,
        public readonly string $role,
        /** @var list<string> */
        public readonly array $permissions,
        public readonly PDO $pdo,
        public readonly string $requestId,
        public readonly string $ipAddress,
        public readonly string $userAgent,
        public readonly string $appEnv,
        public readonly float $startedAt,
    ) {}
}

<?php

declare(strict_types=1);

namespace BIomie\Migrations;

use PDO;

interface MigrationInterface
{
    public function getVersion(): string;

    public function getDescription(): string;

    public function getConnectionName(): string;

    public function getType(): string;

    /**
     * @return list<string>
     */
    public function getChecksumSources(): array;

    public function up(PDO $pdo): void;

    public function down(PDO $pdo): void;
}

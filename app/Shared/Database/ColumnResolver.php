<?php

declare(strict_types=1);

namespace BIomie\Shared\Database;

use PDO;
use Throwable;

final class ColumnResolver
{
    /** @var array<string,bool> */
    private array $cache = [];

    public function __construct(private readonly PDO $pdo) {}

    public function hasColumn(string $table, string $column): bool
    {
        $key = "{$table}.{$column}";
        if (!array_key_exists($key, $this->cache)) {
            try {
                $stmt = $this->pdo->prepare(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
                );
                $stmt->execute([$table, $column]);
                $this->cache[$key] = (int) $stmt->fetchColumn() > 0;
            } catch (Throwable) {
                $this->cache[$key] = false;
            }
        }
        return $this->cache[$key];
    }

    /**
     * Returns the first column from $candidates that exists in $table, or null.
     *
     * @param list<string> $candidates
     */
    public function firstExisting(string $table, array $candidates): ?string
    {
        foreach ($candidates as $col) {
            if ($this->hasColumn($table, $col)) {
                return $col;
            }
        }
        return null;
    }

    /**
     * Like firstExisting but throws if none of the candidates exist.
     *
     * @param list<string> $candidates
     * @throws \RuntimeException
     */
    public function requireAny(string $table, array $candidates): string
    {
        $col = $this->firstExisting($table, $candidates);
        if ($col === null) {
            throw new \RuntimeException(
                "None of the required columns [" . implode(', ', $candidates) . "] exist in `{$table}`."
            );
        }
        return $col;
    }
}

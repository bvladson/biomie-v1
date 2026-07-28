<?php

declare(strict_types=1);

namespace BIomie;

use BIomie\Observability\RequestPerformanceContext;
use PDO;
use Throwable;

/**
 * Shared per-process schema capability cache.
 *
 * Column checks load a table's column list once, replacing many individual
 * information_schema probes in hot read paths.
 */
final class SchemaCapabilities
{
    /** @var array<string,bool> */
    private static array $tables = [];

    /** @var array<string,array<string,true>> */
    private static array $columns = [];

    public static function tableExists(PDO $pdo, string $table): bool
    {
        $key = self::connectionKey($pdo) . ':table:' . $table;
        if (array_key_exists($key, self::$tables)) {
            RequestPerformanceContext::increment('schema_cache_hits');
            return self::$tables[$key];
        }

        try {
            RequestPerformanceContext::increment('schema_queries');
            $stmt = $pdo->prepare(
                'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table LIMIT 1'
            );
            $stmt->execute(['table' => $table]);
            return self::$tables[$key] = (bool) $stmt->fetchColumn();
        } catch (Throwable) {
            return self::$tables[$key] = false;
        }
    }

    public static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $columns = self::columns($pdo, $table);
        return isset($columns[$column]);
    }

    /**
     * @return array<string,true>
     */
    public static function columns(PDO $pdo, string $table): array
    {
        $key = self::connectionKey($pdo) . ':columns:' . $table;
        if (array_key_exists($key, self::$columns)) {
            RequestPerformanceContext::increment('schema_cache_hits');
            return self::$columns[$key];
        }

        try {
            RequestPerformanceContext::increment('schema_queries');
            $stmt = $pdo->prepare(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
            );
            $stmt->execute(['table' => $table]);
            $names = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
            return self::$columns[$key] = array_fill_keys($names, true);
        } catch (Throwable) {
            return self::$columns[$key] = [];
        }
    }

    public static function reset(): void
    {
        self::$tables = [];
        self::$columns = [];
    }

    private static function connectionKey(PDO $pdo): string
    {
        return (string) spl_object_id($pdo);
    }
}

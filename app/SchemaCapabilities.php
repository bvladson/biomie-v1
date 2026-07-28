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

    /**
     * Carrega a existencia e as colunas de varias tabelas em um unico
     * round-trip. Leitores que dependem de mais de uma capacidade podem aquecer
     * o snapshot antes de chamar tableExists()/columnExists().
     *
     * @param list<string> $tables
     */
    public static function warm(PDO $pdo, array $tables): void
    {
        $tables = array_values(array_unique(array_filter(
            array_map('strval', $tables),
            static fn (string $table): bool => preg_match('/^[a-zA-Z0-9_]+$/', $table) === 1,
        )));
        if ($tables === []) {
            return;
        }

        $connectionKey = self::connectionKey($pdo);
        $missing = array_values(array_filter(
            $tables,
            static fn (string $table): bool =>
                !array_key_exists($connectionKey . ':table:' . $table, self::$tables)
                || !array_key_exists($connectionKey . ':columns:' . $table, self::$columns),
        ));
        if ($missing === []) {
            RequestPerformanceContext::increment('schema_cache_hits');
            return;
        }

        $params = [];
        $placeholders = [];
        foreach ($missing as $index => $table) {
            $key = 'schema_table_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $table;
            self::$tables[$connectionKey . ':table:' . $table] = false;
            self::$columns[$connectionKey . ':columns:' . $table] = [];
        }

        try {
            RequestPerformanceContext::increment('schema_queries');
            $stmt = $pdo->prepare(
                'SELECT TABLE_NAME, COLUMN_NAME
                   FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME IN (' . implode(', ', $placeholders) . ')'
            );
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $table = (string) ($row['TABLE_NAME'] ?? '');
                $column = (string) ($row['COLUMN_NAME'] ?? '');
                if ($table === '' || $column === '' || !in_array($table, $missing, true)) {
                    continue;
                }
                self::$tables[$connectionKey . ':table:' . $table] = true;
                self::$columns[$connectionKey . ':columns:' . $table][$column] = true;
            }
        } catch (Throwable) {
            // Os snapshots vazios preservam o comportamento fail-closed dos
            // checks individuais sem repetir varias consultas que ja falharam.
        }
    }

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

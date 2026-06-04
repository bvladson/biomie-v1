<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Support;

use BIomie\Config;
use BIomie\DataPlatform\Query\CnaeQuery;
use BIomie\DataPlatform\Query\GeoQuery;
use BIomie\DataPlatform\ReadModel\CnaeReadModel;
use BIomie\DataPlatform\ReadModel\GeoReadModel;
use BIomie\RequestContext;
use Throwable;

final class ReadModelPerformanceLogger
{
    /** @param array<string,mixed> $payload */
    public static function logGeo(
        Config $config,
        GeoReadModel $model,
        GeoQuery $query,
        string $endpoint,
        string $sourceContract,
        string $mode,
        float $startedAt,
        array $payload,
        bool $ok = true,
        ?string $error = null
    ): void {
        if (!self::enabled($config)) {
            return;
        }

        self::logReadModel(
            config: $config,
            metrics: $model->performanceMetrics(),
            domain: 'geo',
            endpoint: $endpoint,
            sourceContract: $sourceContract,
            readModel: 'GeoReadModel',
            mode: $mode,
            startedAt: $startedAt,
            payload: $payload,
            period: ['date_from' => $query->dateFrom, 'date_to' => $query->dateTo],
            filters: [
                'layer' => $query->layer,
                'city' => $query->city,
                'neighborhood' => $query->neighborhood,
                'cnae' => $query->cnae,
                'seller_id' => $query->sellerId,
            ],
            ok: $ok,
            error: $error
        );
    }

    /** @param array<string,mixed> $payload */
    public static function logCnae(
        Config $config,
        CnaeReadModel $model,
        CnaeQuery $query,
        string $endpoint,
        string $sourceContract,
        string $mode,
        float $startedAt,
        array $payload,
        bool $ok = true,
        ?string $error = null
    ): void {
        if (!self::enabled($config)) {
            return;
        }

        self::logReadModel(
            config: $config,
            metrics: $model->performanceMetrics(),
            domain: 'cnae',
            endpoint: $endpoint,
            sourceContract: $sourceContract,
            readModel: 'CnaeReadModel',
            mode: $mode,
            startedAt: $startedAt,
            payload: $payload,
            period: $query->periodMeta(),
            filters: $query->filtersMeta(),
            ok: $ok,
            error: $error
        );
    }

    /**
     * @param array{query_count:int,sql_time_ms:float} $metrics
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $period
     * @param array<string,mixed> $filters
     */
    public static function logReadModel(
        Config $config,
        array $metrics,
        string $domain,
        string $endpoint,
        string $sourceContract,
        string $readModel,
        string $mode,
        float $startedAt,
        array $payload,
        array $period,
        array $filters,
        bool $ok = true,
        ?string $error = null
    ): void {
        if (!self::enabled($config)) {
            return;
        }

        try {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            $payloadBytes = is_string($json) ? strlen($json) : 0;
            $entry = [
                'ts' => date(DATE_ATOM),
                'domain' => $domain,
                'request_id' => RequestContext::id(),
                'endpoint' => $endpoint,
                'source_contract' => $sourceContract,
                'read_model' => $readModel,
                'mode' => $mode,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'payload_bytes' => $payloadBytes,
                'payload_kb' => round($payloadBytes / 1024, 2),
                'sql_queries' => (int) $metrics['query_count'],
                'sql_time_ms' => (float) $metrics['sql_time_ms'],
                'date_from' => $period['date_from'] ?? null,
                'date_to' => $period['date_to'] ?? null,
                'layer' => $filters['layer'] ?? null,
                'filters' => [
                    'city' => $filters['city'] ?? null,
                    'neighborhood' => $filters['neighborhood'] ?? null,
                    'cnae' => $filters['cnae'] ?? null,
                    'seller_id' => $filters['seller_id'] ?? null,
                    'document_type' => $filters['document_type'] ?? null,
                    'status' => $filters['status'] ?? null,
                ],
                'ok' => $ok,
            ];
            if ($error !== null && $error !== '') {
                $entry['error'] = $error;
            }

            self::append($entry);
        } catch (Throwable) {
            // Performance logging must never affect the user request.
        }
    }

    private static function enabled(Config $config): bool
    {
        $value = $config->get('features.geo_performance_log', true);
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'y', 'sim', 'on'], true);
    }

    /** @param array<string,mixed> $entry */
    private static function append(array $entry): void
    {
        $root = dirname(__DIR__, 3);
        $dir = $root . '/storage/logs/performance';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            return;
        }

        $file = $dir . '/geo_readmodel_' . date('Y-m-d') . '.log';
        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (is_string($line)) {
            @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }
}

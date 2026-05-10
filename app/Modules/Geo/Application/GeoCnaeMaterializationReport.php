<?php

declare(strict_types=1);

namespace BIomie\Modules\Geo\Application;

final class GeoCnaeMaterializationReport
{
    /** @return array<string,mixed> */
    public static function empty(): array
    {
        return [
            'ok'                       => false,
            'status'                   => 'pending',
            'skeletons_created'        => 0,
            'geo_fields_normalized'    => 0,
            'bairros_matched'          => 0,
            'queued_for_enrichment'    => 0,
            'geo_fact_inserted'        => 0,
            'geo_daily_inserted'       => 0,
            'compradores_geo_inserted' => 0,
            'vendas_cnae_inserted'     => 0,
            'coverage'                 => [],
            'zero_reasons'             => [],
            'warnings'                 => [],
            'errors'                   => [],
            'duration_ms'              => 0,
        ];
    }

    public static function finish(array &$report, float $startedAt): void
    {
        $report['duration_ms'] = (int) round((microtime(true) - $startedAt) * 1000);
    }

    /** @return array<string,mixed> */
    public static function spanResult(array $report): array
    {
        return [
            'ok'                    => $report['ok'] ?? false,
            'status'                => $report['status'] ?? 'unknown',
            'skeletons_created'     => $report['skeletons_created'] ?? 0,
            'bairros_matched'       => $report['bairros_matched'] ?? 0,
            'queued_for_enrichment' => $report['queued_for_enrichment'] ?? 0,
            'compradores_geo'       => $report['compradores_geo_inserted'] ?? 0,
            'vendas_cnae'           => $report['vendas_cnae_inserted'] ?? 0,
            'duration_ms'           => $report['duration_ms'] ?? 0,
        ];
    }
}

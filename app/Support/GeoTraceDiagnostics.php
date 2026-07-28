<?php

declare(strict_types=1);

namespace BIomie\Support;

use PDO;
use Throwable;

final class GeoTraceDiagnostics
{
    /** @return array<string,mixed> */
    public static function build(PDO $pdo, string $start, string $end, string $city = 'Fortaleza'): array
    {
        $startComp = substr($start, 0, 7);
        $endComp = substr($end, 0, 7);
        $cityKey = NeighborhoodNormalizer::normalizeCity($city);

        $omie = self::omieLayer($pdo);
        $bronze = self::bronzeLayer($pdo, $start, $end);
        $canonical = self::canonicalLayer($pdo, $start, $end, $cityKey);
        $martCliente = self::martClienteLayer($pdo, $startComp, $endComp, $cityKey);
        $geoMart = self::geoMartLayer($pdo, $startComp, $endComp, $cityKey);
        $api = self::apiLayer($pdo, $startComp, $endComp, $cityKey);
        $map = self::mapLayer($pdo, $startComp, $endComp, $cityKey);
        $materialization = self::materializationLayer($pdo, $start, $end, $startComp, $endComp);

        $freshness = [
            'clients' => self::freshnessStatus($bronze['clients_last_ingested_at'] ?? null, 24),
            'orders' => self::freshnessStatus($bronze['orders_last_ingested_at'] ?? null, 6),
            'invoices' => self::freshnessStatus($bronze['invoices_last_ingested_at'] ?? null, 6),
            'geo_mart' => self::freshnessStatus($geoMart['last_materialized_at'] ?? null, 24),
            'finance' => self::freshnessStatus($bronze['finance_last_ingested_at'] ?? null, 24),
        ];

        $diagnosis = self::diagnose($canonical, $martCliente, $geoMart, $api, $map, $freshness);

        return [
            'period' => [
                'start' => $start,
                'end' => $end,
                'start_competencia' => $startComp,
                'end_competencia' => $endComp,
                'city' => $city,
                'city_key' => $cityKey,
            ],
            'omie' => $omie,
            'bronze' => $bronze,
            'canonical' => $canonical,
            'mart_cliente_periodo' => $martCliente,
            'mart_compradores_por_geo' => $geoMart,
            'api' => $api,
            'map' => $map,
            'materialization' => $materialization,
            'freshness' => $freshness,
            'motivo_zero' => $diagnosis['motivo_zero'],
            'next_action' => $diagnosis['next_action'],
            'map_status' => $diagnosis['map_status'],
            'diagnosis' => $diagnosis,
        ];
    }

    public static function tableExists(PDO $pdo, string $table): bool
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
            );
            $stmt->execute(['table' => $table]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    public static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
            );
            $stmt->execute(['table' => $table, 'column' => $column]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string,mixed> */
    private static function omieLayer(PDO $pdo): array
    {
        $lastSync = self::maxDateFromCandidates($pdo, [
            ['biomie_sync_state', 'last_sync', null],
            ['biomie_omie_bronze', 'ingested_at', null],
            ['biomie_pipeline_jobs', 'completed_at', "status = 'completed'"],
        ]);
        $lastSuccess = self::maxDateFromCandidates($pdo, [
            ['biomie_sync_state', 'completed_at', "last_error IS NULL OR last_error = ''"],
            ['biomie_pipeline_jobs', 'completed_at', "status = 'completed'"],
            ['biomie_omie_status_checks', 'created_at', "status = 'ok'"],
        ]);
        $lastError = self::maxDateFromCandidates($pdo, [
            ['biomie_sync_state', 'updated_at', "last_error IS NOT NULL AND last_error <> ''"],
            ['biomie_pipeline_jobs', 'completed_at', "status = 'failed'"],
            ['biomie_omie_status_checks', 'created_at', "status <> 'ok'"],
        ]);

        return [
            'last_sync_at' => $lastSync,
            'last_success_at' => $lastSuccess,
            'last_error_at' => $lastError,
            'status' => $lastError !== null && ($lastSuccess === null || strtotime($lastError) > strtotime((string) $lastSuccess))
                ? 'error'
                : ($lastSync === null ? 'unknown' : self::freshnessStatus($lastSync, 6)),
        ];
    }

    /** @return array<string,mixed> */
    private static function bronzeLayer(PDO $pdo, string $start, string $end): array
    {
        if (!self::tableExists($pdo, 'biomie_omie_bronze')) {
            return ['available' => false, 'status' => 'missing'];
        }

        $base = [
            'available' => true,
            'status' => 'ok',
            'nfs_raw' => self::countWhere($pdo, 'biomie_omie_bronze', "entity_name = 'invoice'"),
            'clientes_raw' => self::countWhere($pdo, 'biomie_omie_bronze', "entity_name IN ('client','cliente')"),
            'pedidos_raw' => self::countWhere($pdo, 'biomie_omie_bronze', "entity_name = 'order'"),
            'nfs_raw_ingested_in_period' => self::countWhere($pdo, 'biomie_omie_bronze', 'DATE(ingested_at) BETWEEN :start AND :end', ['start' => $start, 'end' => $end]),
            'clients_last_ingested_at' => self::maxColumn($pdo, 'biomie_omie_bronze', 'ingested_at', "entity_name IN ('client','cliente')"),
            'orders_last_ingested_at' => self::maxColumn($pdo, 'biomie_omie_bronze', 'ingested_at', "entity_name = 'order'"),
            'invoices_last_ingested_at' => self::maxColumn($pdo, 'biomie_omie_bronze', 'ingested_at', "entity_name = 'invoice'"),
            'finance_last_ingested_at' => self::maxColumn($pdo, 'biomie_omie_bronze', 'ingested_at', "domain_name = 'finance'"),
        ];

        return $base;
    }

    /** @return array<string,mixed> */
    private static function canonicalLayer(PDO $pdo, string $start, string $end, string $cityKey): array
    {
        if (!self::tableExists($pdo, 'biomie_notas_fiscais_canonicas')) {
            return ['available' => false, 'status' => 'missing'];
        }

        $dateExpr = 'COALESCE(data_competencia, data_emissao)';
        $row = self::fetchOne($pdo, "
            SELECT
                COUNT(*) AS nfs,
                SUM(CASE WHEN status_fiscal = 'autorizada' THEN 1 ELSE 0 END) AS nfs_validas_mapa,
                SUM(CASE WHEN status_fiscal = 'cancelada' THEN 1 ELSE 0 END) AS nfs_canceladas,
                SUM(CASE WHEN codigo_cliente_omie IS NOT NULL AND codigo_cliente_omie > 0 THEN 1 ELSE 0 END) AS nfs_com_cliente,
                SUM(CASE WHEN codigo_cliente_omie IS NULL OR codigo_cliente_omie = 0 THEN 1 ELSE 0 END) AS nfs_sem_cliente,
                SUM(CASE WHEN pedido_id_omie IS NOT NULL AND pedido_id_omie > 0 THEN 1 ELSE 0 END) AS nfs_com_pedido,
                SUM(CASE WHEN pedido_id_omie IS NULL OR pedido_id_omie = 0 THEN 1 ELSE 0 END) AS nfs_sem_pedido,
                COUNT(DISTINCT codigo_cliente_omie) AS clientes_vinculados,
                COALESCE(SUM(CASE WHEN status_fiscal = 'autorizada' THEN valor_mercadorias ELSE 0 END), 0) AS receita_total,
                MAX(sincronizado_em) AS last_sync_at
            FROM biomie_notas_fiscais_canonicas
            WHERE {$dateExpr} BETWEEN :start AND :end
              AND " . TenantContext::sqlFilter() . "
        ", ['start' => $start, 'end' => $end]);

        $clientGeo = self::fetchOne($pdo, "
            SELECT
                COUNT(DISTINCT nf.codigo_cliente_omie) AS clientes_nf,
                COUNT(DISTINCT CASE WHEN COALESCE(cd.bairro_normalizado, cd.bairro, gd.bairro_norm, '') = '' THEN nf.codigo_cliente_omie END) AS clientes_sem_bairro,
                COUNT(DISTINCT CASE WHEN gd.codigo_cliente_omie IS NULL THEN nf.codigo_cliente_omie END) AS clientes_sem_geo_dim,
                COUNT(DISTINCT CASE WHEN gd.codigo_cliente_omie IS NOT NULL AND COALESCE(gd.bairro_norm, '') = '' THEN nf.codigo_cliente_omie END) AS clientes_geo_sem_bairro,
                COUNT(DISTINCT CASE WHEN COALESCE(cd.cidade_normalizada, gd.cidade_norm, '') = :city THEN nf.codigo_cliente_omie END) AS clientes_fortaleza,
                COUNT(DISTINCT CASE WHEN COALESCE(cd.cidade_normalizada, gd.cidade_norm, '') <> '' AND COALESCE(cd.cidade_normalizada, gd.cidade_norm, '') <> :city THEN nf.codigo_cliente_omie END) AS clientes_fora_fortaleza
            FROM biomie_notas_fiscais_canonicas nf
            LEFT JOIN biomie_clientes_dim cd
              ON cd.company_id = nf.company_id AND cd.codigo_cliente_omie = nf.codigo_cliente_omie
            LEFT JOIN biomie_clientes_geo_dim gd
              ON gd.company_id = nf.company_id AND gd.codigo_cliente_omie = nf.codigo_cliente_omie
            WHERE COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end
              AND " . TenantContext::sqlFilter('nf') . "
              AND nf.status_fiscal = 'autorizada'
        ", ['start' => $start, 'end' => $end, 'city' => $cityKey]);

        $orders = self::tableExists($pdo, 'biomie_pedidos_canonicos')
            ? self::fetchOne($pdo, "
                SELECT
                    COUNT(*) AS pedidos,
                    SUM(CASE WHEN codigo_cliente_omie IS NOT NULL AND codigo_cliente_omie > 0 THEN 1 ELSE 0 END) AS pedidos_com_cliente,
                    SUM(CASE WHEN codigo_cliente_omie IS NULL OR codigo_cliente_omie = 0 THEN 1 ELSE 0 END) AS pedidos_sem_cliente,
                    SUM(CASE WHEN codigo_vendedor_omie IS NOT NULL AND codigo_vendedor_omie <> '' THEN 1 ELSE 0 END) AS pedidos_com_vendedor,
                    SUM(CASE WHEN codigo_vendedor_omie IS NULL OR codigo_vendedor_omie = '' THEN 1 ELSE 0 END) AS pedidos_sem_vendedor,
                    SUM(CASE WHEN status_comercial = 'faturado' THEN 1 ELSE 0 END) AS pedidos_faturados,
                    MAX(sincronizado_em) AS last_sync_at
                FROM biomie_pedidos_canonicos
                WHERE COALESCE(data_faturamento, data_emissao) BETWEEN :start AND :end
                  AND " . TenantContext::sqlFilter() . "
            ", ['start' => $start, 'end' => $end])
            : ['pedidos' => 0];

        $nfs = (int) ($row['nfs'] ?? 0);
        return [
            'available' => true,
            'nfs_no_periodo' => $nfs,
            'nfs_validas_mapa' => (int) ($row['nfs_validas_mapa'] ?? 0),
            'nfs_canceladas' => (int) ($row['nfs_canceladas'] ?? 0),
            'nfs_com_cliente' => (int) ($row['nfs_com_cliente'] ?? 0),
            'nfs_sem_cliente' => (int) ($row['nfs_sem_cliente'] ?? 0),
            'nfs_com_pedido' => (int) ($row['nfs_com_pedido'] ?? 0),
            'nfs_sem_pedido' => (int) ($row['nfs_sem_pedido'] ?? 0),
            'clientes_vinculados' => (int) ($row['clientes_vinculados'] ?? 0),
            'clientes_sem_bairro' => (int) ($clientGeo['clientes_sem_bairro'] ?? 0),
            'clientes_sem_geo_dim' => (int) ($clientGeo['clientes_sem_geo_dim'] ?? 0),
            'clientes_geo_sem_bairro' => (int) ($clientGeo['clientes_geo_sem_bairro'] ?? 0),
            'clientes_fortaleza' => (int) ($clientGeo['clientes_fortaleza'] ?? 0),
            'clientes_fora_fortaleza' => (int) ($clientGeo['clientes_fora_fortaleza'] ?? 0),
            'receita_total' => (float) ($row['receita_total'] ?? 0),
            'pedidos' => $orders,
            'last_sync_at' => $row['last_sync_at'] ?? $orders['last_sync_at'] ?? null,
            'status' => $nfs > 0 ? 'ok' : 'empty',
        ];
    }

    /** @return array<string,mixed> */
    private static function martClienteLayer(PDO $pdo, string $startComp, string $endComp, string $cityKey): array
    {
        if (!self::tableExists($pdo, 'mart_cliente_periodo')) {
            return ['available' => false, 'status' => 'missing'];
        }

        $row = self::fetchOne($pdo, "
            SELECT
                COUNT(*) AS rows_count,
                COUNT(DISTINCT cp.codigo_cliente_omie) AS clientes,
                SUM(CASE WHEN cp.valor_nfe > 0 OR cp.mercadorias_faturado > 0 THEN 1 ELSE 0 END) AS clientes_com_faturamento,
                COALESCE(SUM(cp.valor_nfe), 0) AS receita,
                COALESCE(SUM(cp.nfes_emitidas), 0) AS nfs,
                COALESCE(SUM(cp.pedidos_faturados), 0) AS pedidos,
                COUNT(DISTINCT CASE WHEN COALESCE(cd.bairro_normalizado, gd.bairro_norm, '') = '' THEN cp.codigo_cliente_omie END) AS clientes_sem_bairro,
                COUNT(DISTINCT CASE WHEN COALESCE(cd.bairro_normalizado, gd.bairro_norm, '') <> '' THEN cp.codigo_cliente_omie END) AS clientes_com_bairro_key,
                COUNT(DISTINCT CASE WHEN COALESCE(cd.cidade_normalizada, gd.cidade_norm, '') = :city THEN cp.codigo_cliente_omie END) AS clientes_fortaleza,
                MAX(cp.refreshed_at) AS last_materialized_at
            FROM mart_cliente_periodo cp
            LEFT JOIN biomie_clientes_dim cd
              ON cd.company_id = cp.company_id AND cd.codigo_cliente_omie = cp.codigo_cliente_omie
            LEFT JOIN biomie_clientes_geo_dim gd
              ON gd.company_id = cp.company_id AND gd.codigo_cliente_omie = cp.codigo_cliente_omie
            WHERE cp.competencia BETWEEN :start_comp AND :end_comp
              AND " . TenantContext::sqlFilter('cp') . "
        ", ['start_comp' => $startComp, 'end_comp' => $endComp, 'city' => $cityKey]);

        $rows = (int) ($row['rows_count'] ?? 0);
        $revenue = (float) ($row['receita'] ?? 0);
        return [
            'available' => true,
            'rows' => $rows,
            'clientes' => (int) ($row['clientes'] ?? 0),
            'clientes_com_faturamento' => (int) ($row['clientes_com_faturamento'] ?? 0),
            'clientes_sem_bairro' => (int) ($row['clientes_sem_bairro'] ?? 0),
            'clientes_com_bairro_key' => (int) ($row['clientes_com_bairro_key'] ?? 0),
            'clientes_fortaleza' => (int) ($row['clientes_fortaleza'] ?? 0),
            'receita' => $revenue,
            'nfs' => (int) ($row['nfs'] ?? 0),
            'pedidos' => (int) ($row['pedidos'] ?? 0),
            'last_materialized_at' => $row['last_materialized_at'] ?? null,
            'status' => $rows === 0 ? 'empty' : ($revenue <= 0 ? 'zero_value' : 'ok'),
        ];
    }

    /** @return array<string,mixed> */
    private static function geoMartLayer(PDO $pdo, string $startComp, string $endComp, string $cityKey): array
    {
        if (!self::tableExists($pdo, 'mart_compradores_por_geo')) {
            return ['available' => false, 'status' => 'missing'];
        }

        $row = self::fetchOne($pdo, "
            SELECT
                COUNT(*) AS rows_count,
                COUNT(DISTINCT codigo_cliente_omie) AS clientes,
                COUNT(DISTINCT CASE WHEN bairro_norm IS NOT NULL AND TRIM(bairro_norm) <> '' AND LOWER(bairro_norm) <> 'nao informado' THEN bairro_norm END) AS bairros,
                COUNT(DISTINCT CASE WHEN COALESCE(faturamento, 0) > 0 THEN bairro_norm END) AS bairros_com_receita,
                COUNT(DISTINCT CASE WHEN COALESCE(faturamento, 0) <= 0 THEN bairro_norm END) AS bairros_receita_zero,
                COALESCE(SUM(faturamento), 0) AS receita,
                COALESCE(SUM(qtd_notas), 0) AS nfs,
                SUM(CASE WHEN bairro_norm IS NULL OR TRIM(bairro_norm) = '' OR LOWER(bairro_norm) = 'nao informado' THEN 1 ELSE 0 END) AS linhas_sem_bairro,
                MIN(competencia) AS competencia_inicio,
                MAX(competencia) AS competencia_fim,
                MAX(refreshed_at) AS last_materialized_at
            FROM mart_compradores_por_geo
            WHERE competencia BETWEEN :start_comp AND :end_comp
              AND " . CommercialMartScope::condition($pdo, 'mart_compradores_por_geo') . "
              AND LOWER(TRIM(COALESCE(cidade_norm, ''))) = :city
        ", ['start_comp' => $startComp, 'end_comp' => $endComp, 'city' => $cityKey]);

        $rows = (int) ($row['rows_count'] ?? 0);
        $revenue = (float) ($row['receita'] ?? 0);
        return [
            'available' => true,
            'rows' => $rows,
            'bairros' => (int) ($row['bairros'] ?? 0),
            'bairros_com_receita' => (int) ($row['bairros_com_receita'] ?? 0),
            'bairros_receita_zero' => (int) ($row['bairros_receita_zero'] ?? 0),
            'receita' => $revenue,
            'clientes' => (int) ($row['clientes'] ?? 0),
            'nfs' => (int) ($row['nfs'] ?? 0),
            'pedidos' => null,
            'linhas_sem_bairro' => (int) ($row['linhas_sem_bairro'] ?? 0),
            'competencia_inicio' => $row['competencia_inicio'] ?? null,
            'competencia_fim' => $row['competencia_fim'] ?? null,
            'last_materialized_at' => $row['last_materialized_at'] ?? null,
            'status' => $rows === 0 ? 'empty' : ($revenue <= 0 ? 'zero_value' : 'ok'),
        ];
    }

    /** @return array<string,mixed> */
    private static function apiLayer(PDO $pdo, string $startComp, string $endComp, string $cityKey): array
    {
        if (!self::tableExists($pdo, 'mart_compradores_por_geo')) {
            return [
                'sales_by_neighborhood_rows' => 0,
                'bairros_retornados' => 0,
                'status' => 'missing_mart',
            ];
        }

        $hasDim = self::tableExists($pdo, 'biomie_bairros_fortaleza_dim');
        $rows = $hasDim
            ? self::countWhere($pdo, 'biomie_bairros_fortaleza_dim', 'ativo = 1')
            : self::countDistinctNeighborhoods($pdo, $startComp, $endComp, $cityKey);

        return [
            'sales_by_neighborhood_rows' => $rows,
            'bairros_retornados' => $rows,
            'source_mode' => $hasDim ? 'bairros_dim_left_join' : 'gold_mart',
            'status' => $rows > 0 ? 'ok' : 'empty',
        ];
    }

    /** @return array<string,mixed> */
    private static function mapLayer(PDO $pdo, string $startComp, string $endComp, string $cityKey): array
    {
        $geo = self::geoJsonSummary();
        $geoKeys = $geo['keys'] ?? [];
        $apiKeys = [];

        if (self::tableExists($pdo, 'biomie_bairros_fortaleza_dim')) {
            $rows = self::fetchAll($pdo, 'SELECT bairro_nome AS name FROM biomie_bairros_fortaleza_dim WHERE ativo = 1');
            foreach ($rows as $row) {
                $key = NeighborhoodNormalizer::normalizeKey((string) ($row['name'] ?? ''));
                if ($key !== '') {
                    $apiKeys[$key] = true;
                }
            }
        } elseif (self::tableExists($pdo, 'mart_compradores_por_geo')) {
            $rows = self::fetchAll($pdo, "
                SELECT DISTINCT bairro_norm AS name
                FROM mart_compradores_por_geo
                WHERE competencia BETWEEN :start_comp AND :end_comp
                  AND " . CommercialMartScope::condition($pdo, 'mart_compradores_por_geo') . "
                  AND LOWER(TRIM(COALESCE(cidade_norm, ''))) = :city
            ", ['start_comp' => $startComp, 'end_comp' => $endComp, 'city' => $cityKey]);
            foreach ($rows as $row) {
                $key = NeighborhoodNormalizer::normalizeKey((string) ($row['name'] ?? ''));
                if ($key !== '' && $key !== 'nao informado') {
                    $apiKeys[$key] = true;
                }
            }
        }

        $matched = 0;
        foreach (array_keys($apiKeys) as $key) {
            if (isset($geoKeys[$key])) {
                $matched++;
            }
        }
        $apiCount = count($apiKeys);

        return [
            'geojson_loaded' => $geo['loaded'],
            'geojson_features' => $geo['features'],
            'geojson_name_property' => $geo['name_property'],
            'geojson_features_without_name' => $geo['features_without_name'],
            'api_neighborhoods' => $apiCount,
            'matched_neighborhoods' => $matched,
            'match_rate_percent' => $apiCount > 0 ? round(($matched / $apiCount) * 100, 2) : 0.0,
            'status' => !$geo['loaded'] ? 'geojson_failed' : ($apiCount > 0 && $matched === 0 ? 'neighborhood_match_failed' : 'ok'),
        ];
    }

    /** @return array<string,mixed> */
    private static function materializationLayer(PDO $pdo, string $start, string $end, string $startComp, string $endComp): array
    {
        $latest = [];
        if (self::tableExists($pdo, 'biomie_pipeline_jobs')) {
            $latest = self::fetchOne($pdo, "
                SELECT id, status, started_at, completed_at, duration_ms, error_message
                FROM biomie_pipeline_jobs
                WHERE job_name = 'geo_rebuild_period'
                  AND " . self::tenantFilterFor($pdo, 'biomie_pipeline_jobs') . "
                  AND period_start <= :end
                  AND period_end >= :start
                ORDER BY id DESC
                LIMIT 1
            ", ['start' => $start, 'end' => $end]);
        }

        $cliente = self::fetchOne($pdo, "
            SELECT COUNT(*) AS rows_count, COALESCE(SUM(valor_nfe), 0) AS receita, MAX(refreshed_at) AS refreshed_at
            FROM mart_cliente_periodo
            WHERE competencia BETWEEN :start_comp AND :end_comp
              AND " . TenantContext::sqlFilter() . "
        ", ['start_comp' => $startComp, 'end_comp' => $endComp]);

        $geo = self::fetchOne($pdo, "
            SELECT COUNT(*) AS rows_count, COALESCE(SUM(faturamento), 0) AS receita, MAX(refreshed_at) AS refreshed_at
            FROM mart_compradores_por_geo
            WHERE competencia BETWEEN :start_comp AND :end_comp
              AND " . CommercialMartScope::condition($pdo, 'mart_compradores_por_geo') . "
        ", ['start_comp' => $startComp, 'end_comp' => $endComp]);

        $refreshCandidates = array_filter([
            (string) ($cliente['refreshed_at'] ?? ''),
            (string) ($geo['refreshed_at'] ?? ''),
            (string) ($latest['completed_at'] ?? ''),
        ]);
        $lastRefreshed = $refreshCandidates !== [] ? max($refreshCandidates) : null;
        $status = 'unknown';
        if (($latest['status'] ?? '') === 'failed') {
            $status = 'failed';
        } elseif ((int) ($geo['rows_count'] ?? 0) > 0 && (int) ($cliente['rows_count'] ?? 0) > 0) {
            $status = self::freshnessStatus($lastRefreshed, 24) === 'stale' ? 'stale' : 'ok';
        } elseif ((int) ($cliente['rows_count'] ?? 0) === 0 || (int) ($geo['rows_count'] ?? 0) === 0) {
            $status = 'missing';
        }

        return [
            'last_rebuild_at' => $latest['completed_at'] ?? $lastRefreshed,
            'last_rebuild_job_id' => isset($latest['id']) ? (int) $latest['id'] : null,
            'last_rebuild_status' => $latest['status'] ?? 'unknown',
            'period_rows' => [
                'mart_cliente_periodo' => (int) ($cliente['rows_count'] ?? 0),
                'mart_compradores_por_geo' => (int) ($geo['rows_count'] ?? 0),
            ],
            'period_revenue' => [
                'mart_cliente_periodo' => (float) ($cliente['receita'] ?? 0),
                'mart_compradores_por_geo' => (float) ($geo['receita'] ?? 0),
            ],
            'stale' => $status === 'stale',
            'status' => $status,
            'next_action' => match ($status) {
                'ok' => 'Abrir mapa_vendas.php e validar pintura dos bairros.',
                'failed' => 'Revisar ultimo job geo_rebuild_period e executar novamente apos corrigir o erro.',
                'stale' => 'Executar rebuild geografico para o periodo.',
                default => 'Executar php cron/geo_rebuild_period.php --start=' . $start . ' --end=' . $end . ' --assert',
            },
        ];
    }

    /** @return array<string,mixed> */
    private static function diagnose(array $canonical, array $martCliente, array $geoMart, array $api, array $map, array $freshness): array
    {
        $validNfs = (int) ($canonical['nfs_validas_mapa'] ?? 0);
        $martRows = (int) ($martCliente['rows'] ?? 0);
        $geoRows = (int) ($geoMart['rows'] ?? 0);
        $geoRevenue = (float) ($geoMart['receita'] ?? 0);
        $martRevenue = (float) ($martCliente['receita'] ?? 0);
        $apiRows = (int) ($api['sales_by_neighborhood_rows'] ?? 0);
        $stale = in_array('stale', $freshness, true);

        $status = 'ok';
        $motivo = null;
        $technical = 'Fluxo Omie -> bronze -> canonical -> marts -> API -> mapa com sinal operacional.';
        $action = 'Monitorar proximas sincronizacoes.';
        $severity = 'baixa';

        $clientesSemGeoDim = (int) ($canonical['clientes_sem_geo_dim'] ?? 0);
        $clientesGeoSemBairro = (int) ($canonical['clientes_geo_sem_bairro'] ?? 0);
        $clientesVinculados = max(1, (int) ($canonical['clientes_vinculados'] ?? 1));

        if (($map['status'] ?? '') === 'geojson_failed') {
            $status = 'geojson_failed';
            $motivo = 'geojson_failed';
            $technical = 'GeoJSON nao foi carregado ou nao possui features validas.';
            $action = 'Verificar assets/geo/fortaleza_bairros.json.';
            $severity = 'alta';
        } elseif ($validNfs === 0) {
            $status = 'empty_period';
            $motivo = 'canonical_sem_nfs';
            $technical = 'Nao ha NF-e canonica autorizada no periodo para alimentar o mapa.';
            $action = 'Verificar sync canonical ou ampliar o periodo.';
            $severity = 'media';
        } elseif ($martRows === 0) {
            $status = 'mart_cliente_empty';
            $motivo = 'mart_cliente_periodo_nao_materializado';
            $technical = 'Existem NFs canonicas validas, mas mart_cliente_periodo esta vazio no periodo.';
            $action = 'Execute rebuild de mart_cliente_periodo para o periodo.';
            $severity = 'alta';
        } elseif ($geoRows === 0) {
            if ($clientesSemGeoDim > 0 && $clientesSemGeoDim >= (int) ($clientesVinculados * 0.8)) {
                $status = 'geo_mart_empty';
                $motivo = 'sem_endereco';
                $technical = 'Clientes vinculados as NFs nao possuem registro em biomie_clientes_geo_dim. Sem endereco, o mapa nao consegue posicionar as vendas por bairro.';
                $action = 'Enriqueca os dados de endereco dos clientes e reprocesse a materializacao geografica.';
                $severity = 'alta';
            } elseif ($clientesGeoSemBairro > 0 && $clientesGeoSemBairro >= (int) ($clientesVinculados * 0.5)) {
                $status = 'geo_mart_empty';
                $motivo = 'sem_bairro';
                $technical = 'Clientes possuem registro geo, mas o campo bairro_norm esta vazio. O mapa nao consegue atribuir as vendas a um bairro de Fortaleza.';
                $action = 'Verifique a normalizacao de bairros e reprocesse a materializacao geografica.';
                $severity = 'alta';
            } else {
                $status = 'geo_mart_empty';
                $motivo = 'mart_compradores_geo_nao_materializado';
                $technical = 'mart_cliente_periodo tem dados, mas mart_compradores_por_geo esta vazio.';
                $action = 'Execute rebuild geografico para o periodo.';
                $severity = 'alta';
            }
        } elseif ($martRevenue > 0 && $geoRevenue <= 0) {
            $status = 'geo_mart_empty';
            $motivo = 'geo_mart_valor_diferente_do_cliente_periodo';
            $technical = 'mart_cliente_periodo tem receita, mas geo mart esta sem receita.';
            $action = 'Rodar materializacao geografica e revisar clientes sem bairro.';
            $severity = 'alta';
        } elseif ($apiRows === 0) {
            $status = 'api_error';
            $motivo = 'erro_materializacao';
            $technical = 'Geo mart tem dados, mas a API interna retornaria vazio.';
            $action = 'Verificar api/sales_by_neighborhood.php e tabela de dimensao de bairros.';
            $severity = 'alta';
        } elseif (($map['status'] ?? '') === 'neighborhood_match_failed') {
            $status = 'neighborhood_match_failed';
            $motivo = 'bairro_nao_reconhecido';
            $technical = 'API possui bairros, mas nenhum casou com as features do GeoJSON. Os nomes de bairros no mart nao correspondem ao GeoJSON apos normalizacao.';
            $action = 'Rodar php tools/dev/geo_map_match_audit.php para revisar normalizacao e aliases.';
            $severity = 'alta';
        } elseif ($stale) {
            $status = 'omie_sync_stale';
            $motivo = 'materializacao_desatualizada';
            $technical = 'Alguma camada esta stale conforme politica de freshness.';
            $action = 'Verificar php tools/dev/omie_sync_health.php antes de reprocessar.';
            $severity = 'media';
        }

        return [
            'root_cause' => $motivo,
            'motivo_zero' => $motivo,
            'next_action' => $action,
            'human_message' => 'Nao encontramos vendas geograficas para o periodo selecionado.',
            'technical_message' => $technical,
            'map_status' => [
                'map_status' => $status,
                'mensagem_comercial' => $status === 'ok'
                    ? 'Mapa geografico carregado com dados materializados.'
                    : 'Nao encontramos vendas geograficas para o periodo selecionado.',
                'diagnostico_tecnico' => $technical,
                'proxima_acao' => $action,
                'severidade' => $severity,
            ],
        ];
    }

    /** @return array{loaded:bool,features:int,name_property:string,features_without_name:int,keys:array<string,bool>} */
    public static function geoJsonSummary(?string $path = null): array
    {
        $path ??= dirname(__DIR__, 2) . '/assets/geo/fortaleza_bairros.json';
        if (!is_file($path)) {
            return ['loaded' => false, 'features' => 0, 'name_property' => '', 'features_without_name' => 0, 'keys' => []];
        }

        $raw = file_get_contents($path);
        $json = $raw !== false ? json_decode($raw, true) : null;
        $features = is_array($json['features'] ?? null) ? $json['features'] : [];
        $candidates = ['Nome', 'nome', 'NOME', 'bairro', 'Bairro', 'BAIRRO', 'bairro_nome', 'nome_bairro', 'NM_BAIRRO', 'NM_BAIRRO_', 'NM_NOME', 'NOM_BAIRRO', 'name', 'Name'];
        $counts = array_fill_keys($candidates, 0);
        foreach ($features as $feature) {
            $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
            foreach ($candidates as $candidate) {
                if (trim((string) ($props[$candidate] ?? '')) !== '') {
                    $counts[$candidate]++;
                }
            }
        }
        $nameProperty = '';
        $best = 0;
        foreach ($candidates as $candidate) {
            if ($counts[$candidate] > $best) {
                $best = $counts[$candidate];
                $nameProperty = $candidate;
            }
        }

        $withoutName = 0;
        $keys = [];
        foreach ($features as $feature) {
            $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
            $name = trim((string) ($props[$nameProperty] ?? ''));
            if ($name === '') {
                foreach ($candidates as $candidate) {
                    $name = trim((string) ($props[$candidate] ?? ''));
                    if ($name !== '') {
                        break;
                    }
                }
            }
            if ($name === '') {
                $withoutName++;
                continue;
            }
            $key = NeighborhoodNormalizer::normalizeKey($name);
            if ($key !== '') {
                $keys[$key] = true;
            }
        }

        return [
            'loaded' => true,
            'features' => count($features),
            'name_property' => $nameProperty,
            'features_without_name' => $withoutName,
            'keys' => $keys,
        ];
    }

    private static function freshnessStatus(mixed $dateTime, int $maxAgeHours): string
    {
        $value = is_string($dateTime) ? trim($dateTime) : '';
        if ($value === '') {
            return 'missing';
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return 'unknown';
        }
        return (time() - $ts) > ($maxAgeHours * 3600) ? 'stale' : 'fresh';
    }

    /** @param list<array{0:string,1:string,2:?string}> $candidates */
    private static function maxDateFromCandidates(PDO $pdo, array $candidates): ?string
    {
        $dates = [];
        foreach ($candidates as [$table, $column, $where]) {
            $date = self::maxColumn($pdo, $table, $column, $where);
            if ($date !== null) {
                $dates[] = $date;
            }
        }
        if ($dates === []) {
            return null;
        }
        rsort($dates);
        return $dates[0];
    }

    private static function maxColumn(PDO $pdo, string $table, string $column, ?string $where = null): ?string
    {
        if (!self::tableExists($pdo, $table) || !self::columnExists($pdo, $table, $column)) {
            return null;
        }
        $conditions = [];
        if ($where) {
            $conditions[] = "({$where})";
        }
        if (self::columnExists($pdo, $table, 'company_id')) {
            $conditions[] = TenantContext::sqlFilter();
        }
        $sql = "SELECT MAX({$column}) FROM {$table}"
            . ($conditions !== [] ? ' WHERE ' . implode(' AND ', $conditions) : '');
        try {
            $value = $pdo->query($sql)?->fetchColumn();
            return is_string($value) && $value !== '' ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $params */
    private static function countWhere(PDO $pdo, string $table, string $where, array $params = []): int
    {
        if (!self::tableExists($pdo, $table)) {
            return 0;
        }
        try {
            $tenant = self::columnExists($pdo, $table, 'company_id')
                ? ' AND ' . TenantContext::sqlFilter()
                : '';
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE ({$where}){$tenant}");
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    private static function countDistinctNeighborhoods(PDO $pdo, string $startComp, string $endComp, string $cityKey): int
    {
        $row = self::fetchOne($pdo, "
            SELECT COUNT(DISTINCT bairro_norm) AS total
            FROM mart_compradores_por_geo
            WHERE competencia BETWEEN :start_comp AND :end_comp
              AND " . CommercialMartScope::condition($pdo, 'mart_compradores_por_geo') . "
              AND LOWER(TRIM(COALESCE(cidade_norm, ''))) = :city
              AND bairro_norm IS NOT NULL
              AND TRIM(bairro_norm) <> ''
              AND LOWER(bairro_norm) <> 'nao informado'
        ", ['start_comp' => $startComp, 'end_comp' => $endComp, 'city' => $cityKey]);
        return (int) ($row['total'] ?? 0);
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    private static function fetchOne(PDO $pdo, string $sql, array $params = []): array
    {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : [];
        } catch (Throwable) {
            return [];
        }
    }

    private static function tenantFilterFor(PDO $pdo, string $table, string $alias = ''): string
    {
        return self::columnExists($pdo, $table, 'company_id')
            ? TenantContext::sqlFilter($alias)
            : '1=1';
    }

    /** @param array<string,mixed> $params @return list<array<string,mixed>> */
    private static function fetchAll(PDO $pdo, string $sql, array $params = []): array
    {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return is_array($rows) ? $rows : [];
        } catch (Throwable) {
            return [];
        }
    }
}

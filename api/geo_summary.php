<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\Support\DocumentMask;
use BIomie\Support\GeoMapSupport;
use BIomie\Support\GeoTraceDiagnostics;
use BIomie\Support\MapFilterSupport;
use BIomie\Support\NeighborhoodNormalizer;

require __DIR__ . '/bootstrap.php';

Auth::requireModule('geo', true);

try {
    $f              = MapFilterSupport::fromRequest($_GET);
    $range          = ['date_from' => $f['date_from'], 'date_to' => $f['date_to']];
    // Display values (raw input or defaults)
    $city           = $f['filters_received']['city'] ?? 'Fortaleza';
    $neighborhood   = $f['filters_received']['neighborhood'];
    $cnae           = $f['cnae'];
    // Normalized values for SQL binding (state suffix already stripped by MapFilterSupport)
    $cityNorm       = $f['city'] ?? GeoMapSupport::normalizeKey('Fortaleza');
    $neighborhoodNorm = $f['neighborhood'];
    // GEO.R1.3/R1.4/R1.5 — multi-city and metro layers aggregate across cities
    $geoLayer       = trim((string) ($_GET['layer'] ?? ''));
    $isMetroLayer   = $geoLayer === 'grande_fortaleza_bairros';
    $isMultiCityLayer = in_array($geoLayer, [
        'ceara_municipios', 'rmf_municipios',
        'metro_sobral_municipios', 'metro_cariri_municipios', 'metro_todas_municipios',
    ], true);

    $connectionData = Database::getGeoConnection($config);
    $pdo = $connectionData[0];

    if (!Http::tableExists($pdo, 'mart_compradores_por_geo')) {
        Http::jsonConflictMissingTable('mart_compradores_por_geo');
    }

    $where = ['competencia BETWEEN :start_comp AND :end_comp'];
    $params = [
        'start_comp' => substr($range['date_from'], 0, 7),
        'end_comp' => substr($range['date_to'], 0, 7),
    ];

    // City filter — skip for multi-city and metro layers.
    if (!$isMetroLayer && !$isMultiCityLayer) {
        $where[] = '(LOWER(cidade_norm) = :city OR cidade_norm IS NULL OR TRIM(cidade_norm) = \'\')';
        $params['city'] = $cityNorm;
    }

    if ($neighborhoodNorm !== null) {
        $where[] = 'LOWER(bairro_norm) = :neighborhood';
        $params['neighborhood'] = $neighborhoodNorm;
    }

    if ($cnae !== null) {
        if (strtoupper($cnae) === 'SEM_CNAE') {
            $where[] = '(cnae_codigo IS NULL OR TRIM(cnae_codigo) = \'\')';
        } else {
            $where[] = 'cnae_codigo = :cnae';
            $params['cnae'] = $cnae;
        }
    }

    // Vendor filter — only applied when the mart has the vendor grain column (Q.6.DB migration).
    $sellerId = $f['seller_id'];
    if ($sellerId !== null && geoColExists($pdo, 'mart_compradores_por_geo', 'codigo_vendedor_omie')) {
        $where[] = 'LOWER(nome_vendedor) LIKE :seller_pct';
        $params['seller_pct'] = '%' . mb_strtolower(trim($sellerId)) . '%';
    }

    $whereSql = implode(' AND ', $where);

    // Count rows before vs. after city filter to detect when city filter removes all results
    $rowsBeforeCityFilter = 0;
    $rowsAfterCityFilter  = 0;
    try {
        $rbcStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM mart_compradores_por_geo WHERE competencia BETWEEN :sc AND :ec"
        );
        $rbcStmt->execute(['sc' => $params['start_comp'], 'ec' => $params['end_comp']]);
        $rowsBeforeCityFilter = (int) $rbcStmt->fetchColumn();

        $racStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM mart_compradores_por_geo
             WHERE competencia BETWEEN :sc AND :ec
               AND (LOWER(cidade_norm) = :city OR cidade_norm IS NULL OR TRIM(cidade_norm) = '')"
        );
        $racStmt->execute(['sc' => $params['start_comp'], 'ec' => $params['end_comp'], 'city' => $cityNorm]);
        $rowsAfterCityFilter = (int) $racStmt->fetchColumn();
    } catch (\Throwable) {}

    $hasBairrosDim = Http::tableExists($pdo, 'biomie_bairros_fortaleza_dim');
    $bairrosTotais = $hasBairrosDim
        ? (int) $pdo->query('SELECT COUNT(*) FROM biomie_bairros_fortaleza_dim WHERE ativo = 1')->fetchColumn()
        : null;

    $summarySql = "
        SELECT
            COALESCE(SUM(faturamento), 0) AS revenue,
            COALESCE(SUM(CASE WHEN CHAR_LENGTH(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(documento, ''), '.', ''), '-', ''), '/', ''), ' ', '')) = 14 THEN faturamento ELSE 0 END), 0) AS pj_value_raw,
            COALESCE(SUM(CASE WHEN CHAR_LENGTH(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(documento, ''), '.', ''), '-', ''), '/', ''), ' ', '')) = 11 THEN faturamento ELSE 0 END), 0) AS pf_value_raw,
            COUNT(DISTINCT COALESCE(CAST(codigo_cliente_omie AS CHAR), documento, cliente_nome)) AS customers,
            COALESCE(SUM(qtd_notas), 0) AS invoices,
            IF(SUM(qtd_notas) > 0, SUM(faturamento) / SUM(qtd_notas), 0) AS average_ticket,
            COUNT(DISTINCT CASE WHEN bairro_norm IS NOT NULL AND TRIM(bairro_norm) <> '' AND bairro_norm <> 'NAO INFORMADO' THEN bairro_norm END) AS neighborhoods_with_sales,
            SUM(CASE WHEN bairro_norm IS NULL OR TRIM(bairro_norm) = '' OR bairro_norm = 'NAO INFORMADO' THEN 1 ELSE 0 END) AS rows_without_neighborhood,
            MAX(refreshed_at) AS last_materialized_at
        FROM mart_compradores_por_geo
        WHERE {$whereSql}
    ";

    $stmt = $pdo->prepare($summarySql);
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value);
    }
    $stmt->execute();
    $summaryRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $segmentsSql = "
        SELECT
            COALESCE(NULLIF(bairro_norm, ''), 'Nao informado') AS name,
            SUM(faturamento) AS value_raw,
            SUM(qtd_notas) AS notes,
            COUNT(DISTINCT COALESCE(CAST(codigo_cliente_omie AS CHAR), documento, cliente_nome)) AS clients
        FROM mart_compradores_por_geo
        WHERE {$whereSql}
        GROUP BY COALESCE(NULLIF(bairro_norm, ''), 'Nao informado')
        ORDER BY value_raw DESC
        LIMIT 10
    ";

    $stmt = $pdo->prepare($segmentsSql);
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value);
    }
    $stmt->execute();
    $segments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($segments as &$segment) {
        $segmentName = NeighborhoodNormalizer::displayName((string) ($segment['name'] ?? ''));
        $segment['name'] = $segmentName;
        $segment['bairro_nome'] = $segmentName;
        $segment['bairro_key'] = NeighborhoodNormalizer::normalizeKey($segmentName);
        $segment['normalizacao_origem'] = 'NeighborhoodNormalizer';
    }
    unset($segment);

    // ── Top buyers ─────────────────────────────────────────────────────────────
    $topBuyers = [];
    $canViewFullDocuments = Auth::canViewFullDocuments();
    try {
        $topBuyersSql = "
            SELECT
                cliente_nome,
                MAX(codigo_cliente_omie) AS codigo_cliente_omie,
                documento,
                COALESCE(NULLIF(bairro_norm,''), 'Nao informado') AS bairro,
                cidade_norm                                        AS cidade,
                cnae_codigo,
                SUM(qtd_notas)   AS notas,
                SUM(faturamento) AS faturamento,
                MAX(ultima_compra) AS ultima_compra
            FROM mart_compradores_por_geo
            WHERE {$whereSql}
            GROUP BY cliente_nome, documento, bairro_norm, cidade_norm, cnae_codigo
            ORDER BY faturamento DESC
            LIMIT 10
        ";
        $stmt = $pdo->prepare($topBuyersSql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $buyerBairro = NeighborhoodNormalizer::displayName((string) ($r['bairro'] ?? ''));
            $buyerCidade = NeighborhoodNormalizer::displayName((string) ($r['cidade'] ?? ''));
            $buyerBairroKey = NeighborhoodNormalizer::normalizeKey($buyerBairro);
            $buyerCidadeKey = NeighborhoodNormalizer::normalizeCity($buyerCidade);
            $topBuyers[] = [
                'nome'          => (string) ($r['cliente_nome'] ?? ''),
                'documento'     => (string) ($r['documento']    ?? ''),
                'cliente_nome'  => (string) ($r['cliente_nome'] ?? ''),
                'cliente_documento' => $canViewFullDocuments
                    ? (string) ($r['documento'] ?? '')
                    : (DocumentMask::maskDocument((string) ($r['documento'] ?? ''), 'Nao informado') ?? 'Nao informado'),
                'documento_norm' => $canViewFullDocuments ? (preg_replace('/\D+/', '', (string) ($r['documento'] ?? '')) ?? '') : '',
                'codigo_cliente_omie' => (string) ($r['codigo_cliente_omie'] ?? ''),
                'bairro'        => $buyerBairro,
                'bairro_norm'   => $buyerBairroKey,
                'bairro_key'    => $buyerBairroKey,
                'cidade'        => $buyerCidade,
                'cidade_norm'   => $buyerCidadeKey,
                'cidade_key'    => $buyerCidadeKey,
                'cnae_codigo'   => ($r['cnae_codigo'] ?? null) ?: null,
                'notas'         => (int)   ($r['notas']         ?? 0),
                'faturamento'   => (float) ($r['faturamento']   ?? 0),
                'ultima_compra' => ($r['ultima_compra'] ?? null) ?: null,
            ];
        }
    } catch (\Throwable) {}

    // ── Top CNAEs ──────────────────────────────────────────────────────────────
    $topCnaes        = [];
    $cnaeStatus      = 'ok';
    $cnaeMotivo      = null;
    $hasCnaeInDim    = false;
    try {
        // Check if any client has CNAE
        $hasCnaeInDim = (bool) $pdo->query(
            "SELECT 1 FROM biomie_clientes_dim WHERE cnae_codigo IS NOT NULL AND cnae_codigo != '' LIMIT 1"
        )->fetchColumn();

        $cnaeSql = "
            SELECT
                COALESCE(cnae_codigo, 'SEM_CNAE')  AS cnae_codigo,
                MAX(cnae_descricao)                 AS cnae_descricao,
                COUNT(DISTINCT COALESCE(CAST(codigo_cliente_omie AS CHAR), documento, cliente_nome)) AS clientes,
                SUM(faturamento)                    AS faturamento,
                SUM(qtd_notas)                      AS notas
            FROM mart_compradores_por_geo
            WHERE {$whereSql}
            GROUP BY cnae_codigo
            ORDER BY faturamento DESC
            LIMIT 10
        ";
        $stmt = $pdo->prepare($cnaeSql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->execute();
        $cnaeRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Filter out SEM_CNAE rows for the top list but keep for diagnostics
        $comCnae = array_filter($cnaeRows, fn ($r) => ($r['cnae_codigo'] ?? '') !== 'SEM_CNAE' && ($r['cnae_codigo'] ?? '') !== '');
        foreach ($comCnae as $r) {
            $topCnaes[] = [
                'cnae_codigo'    => (string) ($r['cnae_codigo']   ?? ''),
                'cnae_descricao' => ($r['cnae_descricao'] ?? null) ?: null,
                'clientes'       => (int)   ($r['clientes']       ?? 0),
                'faturamento'    => (float) ($r['faturamento']    ?? 0),
                'notas'          => (int)   ($r['notas']          ?? 0),
            ];
        }

        if (count($topCnaes) === 0) {
            if (!$hasCnaeInDim) {
                $cnaeStatus = 'nao_materializado';
                $cnaeMotivo = 'Nenhum cliente possui CNAE em clientes_dim. Execute cnae:materialize e geo:materialize.';
            } else {
                $cnaeStatus = 'sem_venda_com_cnae';
                $cnaeMotivo = 'Ha clientes com CNAE em clientes_dim, mas nenhuma venda vinculada no periodo filtrado.';
            }
        }
    } catch (\Throwable) {
        $cnaeStatus = 'erro';
    }

    // ── Sem bairro stats ───────────────────────────────────────────────────────
    $noBairroFaturamento = 0.0;
    $noBairroClientes    = 0;
    $noBairroNotas       = 0;
    try {
        $nbSql = "
            SELECT COALESCE(SUM(faturamento),0) AS rev,
                   COUNT(DISTINCT COALESCE(CAST(codigo_cliente_omie AS CHAR), documento, cliente_nome)) AS clientes,
                   COALESCE(SUM(qtd_notas),0) AS notas
            FROM mart_compradores_por_geo
            WHERE {$whereSql}
              AND (bairro_norm IS NULL OR bairro_norm = '')
        ";
        $stmt = $pdo->prepare($nbSql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->execute();
        $nbRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $noBairroFaturamento = (float) ($nbRow['rev']      ?? 0);
        $noBairroClientes    = (int)   ($nbRow['clientes'] ?? 0);
        $noBairroNotas       = (int)   ($nbRow['notas']    ?? 0);
    } catch (\Throwable) {}

    // Derive melhor_bairro from segments (first real bairro, not "Nao informado")
    $melhorBairro      = null;
    $melhorBairroValor = 0.0;
    foreach ($segments as $seg) {
        $sName = (string) ($seg['name'] ?? '');
        if ($sName !== '' && strtolower($sName) !== 'nao informado') {
            $melhorBairro      = $sName;
            $melhorBairroValor = (float) ($seg['value_raw'] ?? 0);
            break;
        }
    }
    // Fallback: just take the top segment if every one is "Nao informado"
    if ($melhorBairro === null && !empty($segments)) {
        $melhorBairro      = (string) ($segments[0]['name'] ?? '');
        $melhorBairroValor = (float) ($segments[0]['value_raw'] ?? 0);
    }

    $summary = [
        'revenue' => (float) ($summaryRow['revenue'] ?? 0),
        'total_value' => (float) ($summaryRow['revenue'] ?? 0),
        'total_revenue' => (float) ($summaryRow['revenue'] ?? 0),
        'pj_value_raw' => (float) ($summaryRow['pj_value_raw'] ?? 0),
        'pf_value_raw' => (float) ($summaryRow['pf_value_raw'] ?? 0),
        'customers' => (int) ($summaryRow['customers'] ?? 0),
        'invoices' => (int) ($summaryRow['invoices'] ?? 0),
        'average_ticket' => (float) ($summaryRow['average_ticket'] ?? 0),
        'neighborhoods_with_sales' => (int) ($summaryRow['neighborhoods_with_sales'] ?? 0),
        'rows_without_neighborhood' => (int) ($summaryRow['rows_without_neighborhood'] ?? 0),
        'last_materialized_at' => $summaryRow['last_materialized_at'] ?? null,
        'bairros_total' => $bairrosTotais,
        'bairros_com_venda' => (int) ($summaryRow['neighborhoods_with_sales'] ?? 0),
        'bairros_sem_venda' => $bairrosTotais !== null
            ? max(0, $bairrosTotais - (int) ($summaryRow['neighborhoods_with_sales'] ?? 0))
            : null,
        'cnaes_com_venda'   => count($topCnaes),
        'melhor_bairro'     => $melhorBairro,
        'melhor_bairro_valor' => $melhorBairroValor,
        'sem_bairro' => [
            'faturamento' => $noBairroFaturamento,
            'clientes'    => $noBairroClientes,
            'notas'       => $noBairroNotas,
        ],
    ];

    // When mart has no data for the period, probe whether the table has ever been materialized
    $goldTotalRows = null;
    $materialized = $summary['last_materialized_at'] !== null;
    if ($summary['invoices'] === 0 && $summary['revenue'] <= 0.0) {
        $probeStmt = $pdo->query('SELECT 1 FROM mart_compradores_por_geo LIMIT 1');
        $goldTotalRows = (int) ($probeStmt->fetchColumn() !== false);
        if (!$materialized && $goldTotalRows === 0) {
            $materialized = false;
        }
    }

    // Detect pending-enrichment state: mart has rows but ALL have empty bairro
    $pendingEnrichment = false;
    $clientesSemBairro = 0;
    $clientesPendentes = 0;
    if ($materialized) {
        try {
            $totalMartRows = (int) $pdo->query('SELECT COUNT(*) FROM mart_compradores_por_geo')->fetchColumn();
            $comBairro = (int) $pdo->query(
                "SELECT COUNT(*) FROM mart_compradores_por_geo WHERE bairro_norm IS NOT NULL AND bairro_norm != ''"
            )->fetchColumn();
            if ($totalMartRows > 0 && $comBairro === 0) {
                $pendingEnrichment = true;
            }
            if (Http::tableExists($pdo, 'biomie_clientes_dim')) {
                $clientesSemBairro = (int) $pdo->query(
                    "SELECT COUNT(*) FROM biomie_clientes_dim WHERE (bairro IS NULL OR bairro = '') AND (cidade IS NULL OR cidade = '')"
                )->fetchColumn();
            }
            if (Http::tableExists($pdo, 'biomie_clientes_para_enriquecer')) {
                $clientesPendentes = (int) $pdo->query(
                    "SELECT COUNT(*) FROM biomie_clientes_para_enriquecer WHERE status = 'pendente'"
                )->fetchColumn();
            }
        } catch (\Throwable) {}
    }

    $payload = [
        'ok' => true,
        'filters' => [
            'date_from'   => $range['date_from'],
            'date_to'     => $range['date_to'],
            'city'        => $city,
            'neighborhood' => $neighborhood,
            'cnae'        => $cnae,
            'seller_id'   => $sellerId ?? null,
        ],
        'summary' => $summary,
        'segments' => array_values($segments),
        'top_buyers' => $topBuyers,
        'top_cnaes'  => $topCnaes,
        'no_neighborhood' => [
            'faturamento' => $noBairroFaturamento,
            'clientes'    => $noBairroClientes,
            'notas'       => $noBairroNotas,
            'pct_faturamento' => $summary['revenue'] > 0
                ? round($noBairroFaturamento / $summary['revenue'] * 100, 1)
                : 0.0,
            'reason' => 'Endereço fora da malha de Fortaleza, bairro não propagado para o mart ou cache geo local incompleto',
            'action' => 'Rodar geo_holistic_audit para classificar a causa: fora da malha, bug de propagação ou dado ausente no Omie',
        ],
        'data_quality' => [
            'missing_neighborhood_count'   => $noBairroClientes,
            'missing_neighborhood_revenue' => round($noBairroFaturamento, 2),
            'pct_revenue' => $summary['revenue'] > 0
                ? round($noBairroFaturamento / $summary['revenue'] * 100, 1)
                : 0.0,
            'reason'  => 'Endereço fora da malha de Fortaleza, bairro não propagado para o mart ou cache geo local incompleto',
            'action'  => 'Rodar geo_holistic_audit para classificar a causa: fora da malha, bug de propagação ou dado ausente no Omie',
        ],
        'cnae_status' => [
            'status'         => $cnaeStatus,
            'motivo'         => $cnaeMotivo,
            'has_cnae_in_dim' => $hasCnaeInDim,
        ],
        'diagnostics' => [
            'source_table' => 'mart_compradores_por_geo',
            'table_exists' => true,
            'materialized' => $materialized,
            'gold_rows_in_period' => (int) $summary['invoices'],
            'neighborhoods_with_sales' => (int) $summary['neighborhoods_with_sales'],
            'rows_without_neighborhood' => (int) $summary['rows_without_neighborhood'],
            'last_materialized_at' => $summary['last_materialized_at'],
            'bairros_dim_available' => $hasBairrosDim,
            'bairros_dim_total' => $bairrosTotais,
            'pending_enrichment' => $pendingEnrichment,
            'clientes_sem_bairro' => $clientesSemBairro,
            'clientes_pendentes' => $clientesPendentes,
            'rows_before_city_filter' => $rowsBeforeCityFilter,
            'rows_after_city_filter'  => $rowsAfterCityFilter,
            // Additional FASE 12B diagnostics
            'requested_start' => $range['date_from'],
            'requested_end' => $range['date_to'],
            'requested_city' => $city,
            'requested_neighborhood' => $neighborhood,
            'mart_rows_for_period' => $rowsBeforeCityFilter,
            'mart_rows_after_city_filter' => $rowsAfterCityFilter,
            'neighborhoods_with_revenue' => (int) $summary['neighborhoods_with_sales'],
            'top_neighborhoods_from_mart' => array_slice(array_column($segments, 'name'), 0, 5),
            'layer_payload_count' => count($segments),
            'neighborhood_key_sample' => $segments[0]['name'] ?? null,
            'normalized_neighborhood_key_sample' => isset($segments[0]['name']) ? GeoMapSupport::normalizeKey($segments[0]['name']) : null,
        ],
        'coverage' => [
            'status' => $summary['rows_without_neighborhood'] > 0 ? 'partial' : 'ok',
            'missing_percent' => $summary['customers'] > 0
                ? round(($summary['rows_without_neighborhood'] / max(1, $summary['customers'])) * 100, 2)
                : 100.0,
            'enriched_clients' => $summary['customers'],
            'queued_clients' => 0,
        ],
        'lifecycle' => [
            'status' => 'not_calculated',
            'recurrent' => 0.0,
            'new' => 0.0,
            'rev_recurrent' => 0.0,
        ],
        'meta' => [
            'request_id' => RequestContext::id(),
            'generated_at' => date(DATE_ATOM),
            'source_table' => 'mart_compradores_por_geo',
            'source_mode' => 'gold_mart',
            'pipeline_ready' => true,
        ],
    ];

    if ($summary['revenue'] <= 0.0 && $summary['invoices'] === 0) {
        $payload['empty_state'] = GeoMapSupport::emptyState();

        // Divergence diagnostic: check if mart has data outside the filter
        try {
            $martTotalRows  = (int) $pdo->query('SELECT COUNT(*) FROM mart_compradores_por_geo')->fetchColumn();
            $martTotalRev   = (float) $pdo->query('SELECT COALESCE(SUM(faturamento),0) FROM mart_compradores_por_geo')->fetchColumn();
            $martMinComp    = (string) ($pdo->query('SELECT MIN(competencia) FROM mart_compradores_por_geo')->fetchColumn() ?: '');
            $martMaxComp    = (string) ($pdo->query('SELECT MAX(competencia) FROM mart_compradores_por_geo')->fetchColumn() ?: '');

            $motivoZero = 'desconhecido';
            if ($martTotalRows === 0) {
                $motivoZero = 'mart_compradores_geo_nao_materializado';
            } elseif ($martTotalRev <= 0.0) {
                $motivoZero = 'periodo_sem_vendas';
            } elseif ($summary['last_materialized_at'] === null) {
                $motivoZero = 'mart_compradores_geo_nao_materializado';
            } else {
                $motivoZero = 'filtros_nao_reconhecidos';
            }

            // Probe canonical: if NFs exist for this period but mart_compradores_por_geo has no rows
            // for this specific competencia, the geo mart needs to be rebuilt for the period.
            $canonicalNfsInPeriod = 0;
            $geoMartPeriodRows    = 0;
            if (in_array($motivoZero, ['mart_compradores_geo_nao_materializado', 'filtros_nao_reconhecidos'], true)) {
                try {
                    if (Http::tableExists($pdo, 'biomie_notas_fiscais_canonicas')) {
                        $cnfStmt = $pdo->prepare(
                            "SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas
                             WHERE COALESCE(data_competencia, data_emissao) BETWEEN :s AND :e
                               AND status_fiscal = 'autorizada'"
                        );
                        $cnfStmt->execute(['s' => $range['date_from'], 'e' => $range['date_to']]);
                        $canonicalNfsInPeriod = (int) $cnfStmt->fetchColumn();
                    }
                    // Check if mart_compradores_por_geo itself has rows for this period
                    $gmpStmt = $pdo->prepare(
                        "SELECT COALESCE(SUM(qtd_notas), 0) FROM mart_compradores_por_geo
                         WHERE competencia BETWEEN :sc AND :ec"
                    );
                    $gmpStmt->execute(['sc' => $params['start_comp'], 'ec' => $params['end_comp']]);
                    $geoMartPeriodRows = (int) $gmpStmt->fetchColumn();
                } catch (\Throwable) {}

                if ($canonicalNfsInPeriod > 0 && $geoMartPeriodRows === 0) {
                    $motivoZero = 'mart_cliente_periodo_nao_materializado';
                }
            }

            // City filter ate all rows for the period: data exists but city mismatch
            if ($rowsBeforeCityFilter > 0 && $rowsAfterCityFilter === 0) {
                $motivoZero = 'filtro_cidade_removeu_tudo';
            }

            $payload['diagnostics']['motivo_zero']                = $motivoZero;
            $payload['diagnostics']['total_rows_sem_filtro']      = $martTotalRows;
            $payload['diagnostics']['soma_faturamento_sem_filtro'] = $martTotalRev;
            $payload['diagnostics']['min_competencia']            = $martMinComp;
            $payload['diagnostics']['max_competencia']            = $martMaxComp;
            $payload['diagnostics']['canonical_nfs_no_periodo']   = $canonicalNfsInPeriod;
            $payload['diagnostics']['geo_mart_notas_no_periodo']  = $geoMartPeriodRows;
            $payload['diagnostics']['filtros_recebidos']          = [
                'date_from' => $range['date_from'],
                'date_to'   => $range['date_to'],
                'city'      => $city,
                'neighborhood' => $neighborhood,
                'cnae'      => $cnae,
            ];
        } catch (\Throwable) {}
    }

    // Always fetch monthly coverage for the requested period
    try {
        $monthlyCoverage = [];
        $missingMonths = [];
        $canonicalTotalNfs = 0;
        $canonicalTotalRev = 0.0;
        
        $months = [];
        $start = new DateTime(substr($range['date_from'], 0, 7) . '-01');
        $end = new DateTime(substr($range['date_to'], 0, 7) . '-01');
        while ($start <= $end) {
            $months[] = $start->format('Y-m');
            $start->modify('+1 month');
        }

        if (Http::tableExists($pdo, 'biomie_notas_fiscais_canonicas')) {
            foreach ($months as $month) {
                $mStart = $month . '-01';
                $mEnd = date('Y-m-t', strtotime($mStart));
                
                $cStmt = $pdo->prepare("SELECT COUNT(*) AS c, COALESCE(SUM(valor_total_nota), 0) AS v FROM biomie_notas_fiscais_canonicas WHERE COALESCE(data_competencia, data_emissao) BETWEEN :s AND :e AND status_fiscal = 'autorizada'");
                $cStmt->execute(['s' => $mStart, 'e' => $mEnd]);
                $cRes = $cStmt->fetch(PDO::FETCH_ASSOC);
                
                $gStmt = $pdo->prepare("SELECT COUNT(*) AS c, COALESCE(SUM(faturamento), 0) AS v, COUNT(DISTINCT bairro_norm) AS n FROM mart_compradores_por_geo WHERE competencia = :m");
                $gStmt->execute(['m' => $month]);
                $gRes = $gStmt->fetch(PDO::FETCH_ASSOC);
                
                $c_nfs = (int)($cRes['c'] ?? 0);
                $c_rev = (float)($cRes['v'] ?? 0);
                $g_rows = (int)($gRes['c'] ?? 0);
                $g_rev = (float)($gRes['v'] ?? 0);
                $g_neigh = (int)($gRes['n'] ?? 0);
                
                $canonicalTotalNfs += $c_nfs;
                $canonicalTotalRev += $c_rev;
                
                if ($c_nfs > 0 && $g_rows === 0) {
                    $missingMonths[] = $month;
                }

                $monthlyCoverage[] = [
                    'month' => $month,
                    'canonical_nfs' => $c_nfs,
                    'canonical_revenue' => $c_rev,
                    'mart_geo_rows' => $g_rows,
                    'revenue' => $g_rev,
                    'neighborhoods' => $g_neigh
                ];
            }
            
            $payload['diagnostics']['monthly_coverage'] = $monthlyCoverage;
            $payload['diagnostics']['canonical_nfs_for_period'] = $canonicalTotalNfs;
            $payload['diagnostics']['canonical_revenue_for_period'] = $canonicalTotalRev;
            $payload['diagnostics']['mart_geo_rows_for_period'] = $rowsBeforeCityFilter;
            $payload['diagnostics']['mart_geo_revenue_for_period'] = (float)$summary['revenue'];
            $payload['diagnostics']['mart_geo_neighborhoods_for_period'] = (int)$summary['neighborhoods_with_sales'];
            $payload['diagnostics']['missing_months_in_geo_mart'] = $missingMonths;
            
            if ($canonicalTotalNfs > 0 && $rowsBeforeCityFilter === 0) {
                $payload['diagnostics']['motivo_zero'] = 'mart_compradores_geo_nao_materializado';
            }
        }
    } catch (\Throwable) {}

    try {
        $layerDiagnostics = GeoTraceDiagnostics::build($pdo, $range['date_from'], $range['date_to'], $city ?? 'Fortaleza');
        $payload['diagnostics']['omie'] = $layerDiagnostics['omie'] ?? [];
        $payload['diagnostics']['bronze'] = $layerDiagnostics['bronze'] ?? [];
        $payload['diagnostics']['canonical'] = $layerDiagnostics['canonical'] ?? [];
        $payload['diagnostics']['mart_cliente_periodo'] = $layerDiagnostics['mart_cliente_periodo'] ?? [];
        $payload['diagnostics']['mart_compradores_por_geo'] = $layerDiagnostics['mart_compradores_por_geo'] ?? [];
        $payload['diagnostics']['api'] = $layerDiagnostics['api'] ?? [];
        $payload['diagnostics']['map'] = $layerDiagnostics['map'] ?? [];
        $payload['diagnostics']['materialization'] = $layerDiagnostics['materialization'] ?? [];
        $payload['diagnostics']['freshness'] = $layerDiagnostics['freshness'] ?? [];
        $payload['diagnostics']['next_action'] = $layerDiagnostics['next_action'] ?? null;
        $payload['diagnostics']['map_status'] = $layerDiagnostics['map_status'] ?? null;
        $payload['map_status'] = $layerDiagnostics['map_status'] ?? null;
        $payload['freshness_status'] = $layerDiagnostics['freshness'] ?? [];

        $currentMotivoZero = (string) ($payload['diagnostics']['motivo_zero'] ?? '');
        $layerMotivoZero = $layerDiagnostics['motivo_zero'] ?? null;
        if ($layerMotivoZero !== null && ($currentMotivoZero === '' || in_array($currentMotivoZero, ['desconhecido', 'mart_vazio', 'mart_compradores_geo_nao_materializado', 'periodo_sem_vendas'], true))) {
            $payload['diagnostics']['motivo_zero'] = $layerMotivoZero;
        }
    } catch (\Throwable) {}

    Http::json($payload);
} catch (\Throwable $exception) {
    global $logger;
    $logger?->error('geo_summary api error', ['exception' => $exception->getMessage()]);

    Http::json([
        'ok' => false,
        'error' => 'api_error',
        'message' => 'Nao foi possivel carregar o resumo geografico agora.',
        'request_id' => RequestContext::id(),
    ], 500);
}

function geoColExists(PDO $pdo, string $table, string $col): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $col]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (\Throwable) {
        return false;
    }
}

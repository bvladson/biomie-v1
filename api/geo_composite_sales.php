<?php

declare(strict_types=1);

/**
 * api/geo_composite_sales.php
 *
 * GEO.R1.7 — Endpoint de vendas para o Mapa Composto Inteligente do Ceará.
 * Retorna dados de todos os tipos territoriais em uma única requisição:
 *
 *   - fortaleza_bairro    : bairros de Fortaleza
 *   - metro_bairro        : bairros metropolitanos (Grande Fortaleza)
 *   - rmf_municipio_fallback : municípios RMF sem bairro IBGE
 *   - ceara_municipio     : demais municípios do Ceará
 *
 * Join keys:
 *   - fortaleza bairro     → bairro_norm   (ex: "aldeota")
 *   - metro bairro         → cidade:bairro (ex: "eusebio:olho_dagua")
 *   - municipality         → cidade_norm   (ex: "aquiraz")
 *
 * Uso: GET api/geo_composite_sales.php?date_from=2026-01-01&date_to=2026-05-31
 */

use BIomie\Auth;
use BIomie\Database;
use BIomie\DataPlatform\Query\GeoQuery;
use BIomie\DataPlatform\ReadModel\GeoReadModelFactory;
use BIomie\DataPlatform\Support\FeatureFlags;
use BIomie\DataPlatform\Support\ReadModelPerformanceLogger;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\Support\Geo\GeoKeyNormalizer;
use BIomie\Support\MapFilterSupport;

require __DIR__ . '/bootstrap.php';

Auth::requireModule('geo', true);

$useReadModel = FeatureFlags::geoReadModelV2($config) || FeatureFlags::adminRequestOverride('readmodel');
$forceReadModel = FeatureFlags::adminRequestOverride('readmodel');
$forceLegacy = FeatureFlags::adminRequestOverride('legacy');
if ($useReadModel && !$forceLegacy) {
    try {
        $startedAt = microtime(true);
        $query = GeoQuery::fromRequest($_GET);
        $geo = GeoReadModelFactory::make($config);
        $payload = $geo->legacyCompositePayload($query);
        $payload['read_model'] = 'GeoReadModel';
        $payload['feature_flag_active'] = FeatureFlags::geoReadModelV2($config);
        $payload['request_id'] = RequestContext::id();

        ReadModelPerformanceLogger::logGeo(
            $config,
            $geo,
            $query,
            'geo_composite_sales',
            'geo.map_payload.v1',
            'readmodel',
            $startedAt,
            $payload
        );

        Http::json($payload);
    } catch (\Throwable $exception) {
        global $logger;
        $logger?->error('geo_composite_sales readmodel fallback', [
            'exception' => $exception->getMessage(),
            'request_id' => RequestContext::id(),
        ]);

        if ($forceReadModel) {
            Http::json([
                'ok' => false,
                'error' => 'geo_readmodel_error',
                'message' => 'Nao foi possivel carregar o endpoint composto pelo ReadModel.',
                'request_id' => RequestContext::id(),
            ], 500);
        }
    }
}

try {
    $f     = MapFilterSupport::fromRequest($_GET);
    $range = ['date_from' => $f['date_from'], 'date_to' => $f['date_to']];

    $connectionData = Database::getGeoConnection($config);
    $pdo = $connectionData[0];

    if (!Http::tableExists($pdo, 'mart_compradores_por_geo')) {
        Http::jsonConflictMissingTable('mart_compradores_por_geo');
    }

    // ── Listas de municípios RMF por tipo (nomes normalizados) ────────────────
    // Municípios RMF com bairros IBGE 2022 (camada grande_fortaleza_bairros)
    $rmfWithBairros = [
        'fortaleza', 'caucaia', 'maracanau', 'maranguape', 'eusebio',
        'guaiuba', 'itaitinga', 'pacatuba',
    ];

    // Municípios RMF sem bairros IBGE (fallback municipal)
    $rmfFallback = [
        'aquiraz', 'horizonte', 'pacajus', 'chorozinho', 'cascavel',
        'pindoretama', 'paracuru', 'paraipaba', 'sao_goncalo_do_amarante',
        'sao_luis_do_curu', 'trairi',
    ];

    $rmfAll = array_merge($rmfWithBairros, $rmfFallback);

    // ── Query principal: agrega por (cidade_norm, bairro_norm) ────────────────
    // Sem filtro de cidade — retorna todo o Ceará
    $hasClientDim = Http::tableExists($pdo, 'biomie_clientes_dim');
    $hasCnaeDim = Http::tableExists($pdo, 'biomie_cnae_dim');
    $clientDimJoin = $hasClientDim
        ? 'LEFT JOIN biomie_clientes_dim cd ON cd.company_id = m.company_id AND cd.codigo_cliente_omie = m.codigo_cliente_omie'
        : '';
    $rawCnaeCodeExpr = $hasClientDim
        ? "COALESCE(NULLIF(TRIM(m.cnae_codigo), ''), NULLIF(TRIM(cd.cnae_codigo), ''))"
        : "NULLIF(TRIM(m.cnae_codigo), '')";
    $cnaeDimJoin = $hasCnaeDim
        ? "LEFT JOIN biomie_cnae_dim cdim ON cdim.cnae_codigo_normalizado = REGEXP_REPLACE({$rawCnaeCodeExpr}, '[^0-9]', '')"
        : '';
    $dimJoin = trim($clientDimJoin . "\n" . $cnaeDimJoin);
    $cnaeCodeExpr = $rawCnaeCodeExpr;
    $cnaeDescParts = [];
    if ($hasCnaeDim) {
        $cnaeDescParts[] = "NULLIF(TRIM(cdim.cnae_descricao), '')";
    }
    $cnaeDescParts[] = "NULLIF(NULLIF(TRIM(m.cnae_descricao), ''), 'CNAE não informado')";
    if ($hasClientDim) {
        $cnaeDescParts[] = "NULLIF(NULLIF(TRIM(cd.cnae_descricao), ''), 'CNAE não informado')";
    }
    $cnaeDescParts[] = 'NULL';
    $cnaeDescExpr = 'COALESCE(' . implode(', ', $cnaeDescParts) . ')';

    $where = [
        'm.competencia BETWEEN :start_comp AND :end_comp',
        \BIomie\Support\CommercialMartScope::condition($pdo, 'mart_compradores_por_geo', 'm'),
    ];
    $params = [
        'start_comp' => substr($range['date_from'], 0, 7),
        'end_comp'   => substr($range['date_to'],   0, 7),
    ];

    // ── FILTRO COMERCIAL — vendedores selecionados no mega funil ─────────────
    // Precede o filtro legado por NOME: quando há CÓDIGOS selecionados no
    // seletor global, casar por código é exato (o LIKE por nome confunde
    // homônimos como "CAIO - VAR" e "CAIO - REV"). Sem seleção, o comportamento
    // antigo (seller_id por nome) segue valendo.
    // A existência da tabela já foi garantida acima (jsonConflictMissingTable
    // encerra a requisição), então aqui basta checar a coluna.
    $hasVendorColumn = Http::columnExists($pdo, 'mart_compradores_por_geo', 'codigo_vendedor_omie');
    $selectedVendorIds = \BIomie\Support\VendorScopeFilter::requestedIds();

    if ($selectedVendorIds !== [] && $hasVendorColumn) {
        $vendorPh = [];
        foreach ($selectedVendorIds as $i => $vid) {
            $key = 'geo_vendor_' . $i;
            $vendorPh[] = ':' . $key;
            $params[$key] = (string) $vid;
        }
        $where[] = 'm.codigo_vendedor_omie IN (' . implode(', ', $vendorPh) . ')';
    }

    // Filtros opcionais
    $sellerId = $f['seller_id'];
    if ($selectedVendorIds === [] && $sellerId !== null && $hasVendorColumn) {
        $where[]                  = 'LOWER(m.nome_vendedor) LIKE :seller_pct';
        $params['seller_pct']     = '%' . mb_strtolower(trim($sellerId)) . '%';
    }

    $cnae = $f['cnae'];
    if ($cnae !== null) {
        if (strtoupper(trim($cnae)) === 'SEM_CNAE') {
            $where[] = "{$cnaeCodeExpr} IS NULL";
        } else {
            $cnaeNorm = preg_replace('/\D+/', '', $cnae);
            $cnaeWhere = ["{$cnaeCodeExpr} = :cnae_code", "LOWER({$cnaeDescExpr}) LIKE :cnae_like"];
            $params['cnae_code'] = $cnae;
            $params['cnae_like'] = '%' . mb_strtolower($cnae) . '%';
            if ($cnaeNorm !== '') {
                $cnaeWhere[] = "REGEXP_REPLACE({$cnaeCodeExpr}, '[^0-9]', '') = :cnae_norm";
                $params['cnae_norm'] = $cnaeNorm;
            }
            $where[] = '(' . implode(' OR ', $cnaeWhere) . ')';
        }
    }

    $whereSql = implode(' AND ', $where);

    $sql = "
        SELECT
            LOWER(TRIM(COALESCE(NULLIF(m.cidade_norm,''), 'sem_cidade'))) AS cidade_key,
            LOWER(TRIM(COALESCE(NULLIF(m.bairro_norm,''), '')))           AS bairro_raw,
            SUM(m.faturamento)                                             AS revenue,
            COUNT(DISTINCT COALESCE(CAST(m.codigo_cliente_omie AS CHAR), m.documento, m.cliente_nome)) AS customers,
            SUM(m.qtd_notas)                                               AS invoices,
            IF(SUM(m.qtd_notas)>0, SUM(m.faturamento)/SUM(m.qtd_notas), 0) AS ticket_medio,
            MAX(m.refreshed_at)                                            AS refreshed_at
        FROM mart_compradores_por_geo m
        {$dimJoin}
        WHERE {$whereSql}
        GROUP BY LOWER(TRIM(m.cidade_norm)), LOWER(TRIM(m.bairro_norm))
        ORDER BY revenue DESC
    ";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue(':' . $k, $v);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    // ── Classifica e indexa por join_key ──────────────────────────────────────
    $territories  = [];
    $totalRevenue = 0.0;
    $totalCustomers = 0;
    $totalInvoices  = 0;
    $semBairro      = 0;

    // Agrega receita total por município (para revenue do polígono municipal)
    $muniRevenue = [];

    foreach ($rows as $row) {
        $cidadeKey  = (string)($row['cidade_key'] ?? '');
        $bairroRaw  = (string)($row['bairro_raw'] ?? '');
        $revenue    = (float)($row['revenue'] ?? 0);
        $customers  = (int)($row['customers'] ?? 0);
        $invoices   = (int)($row['invoices'] ?? 0);
        $ticket     = (float)($row['ticket_medio'] ?? 0);

        $totalRevenue   += $revenue;
        $totalCustomers += $customers;
        $totalInvoices  += $invoices;

        if ($bairroRaw === '') {
            $semBairro += $invoices;
        }

        // Classifica tipo territorial
        if ($cidadeKey === 'fortaleza' && $bairroRaw !== '') {
            // Bairro de Fortaleza
            $type    = 'fortaleza_bairro';
            $joinKey = $bairroRaw;
        } elseif (in_array($cidadeKey, $rmfWithBairros, true) && $bairroRaw !== '') {
            // Bairro metropolitano
            $type    = 'metro_bairro';
            $joinKey = $cidadeKey . ':' . $bairroRaw;
        } else {
            // Município — determinar subtipo RMF vs CE
            $type    = in_array($cidadeKey, $rmfFallback, true)
                       ? 'rmf_municipio_fallback'
                       : (in_array($cidadeKey, $rmfAll, true) ? 'rmf_municipio_fallback' : 'ceara_municipio');
            $joinKey = $cidadeKey;

            // Acumula receita total do município (para polígono municipal)
            $muniRevenue[$cidadeKey] = ($muniRevenue[$cidadeKey] ?? 0.0) + $revenue;
        }

        if (isset($territories[$joinKey])) {
            // Agrupa linhas com mesmo join_key
            $territories[$joinKey]['revenue']   += $revenue;
            $territories[$joinKey]['customers'] += $customers;
            $territories[$joinKey]['invoices']  += $invoices;
            if ($territories[$joinKey]['invoices'] > 0) {
                $territories[$joinKey]['ticket_medio'] =
                    $territories[$joinKey]['revenue'] / $territories[$joinKey]['invoices'];
            }
        } else {
            $territories[$joinKey] = [
                'join_key'       => $joinKey,
                'territory_type' => $type,
                'cidade_key'     => $cidadeKey,
                'bairro_norm'    => $bairroRaw ?: null,
                'revenue'        => $revenue,
                'customers'      => $customers,
                'invoices'       => $invoices,
                'ticket_medio'   => $ticket,
                'refreshed_at'   => $row['refreshed_at'] ?? null,
            ];
        }
    }

    // Para municípios (polígono): usar receita agregada total da cidade
    foreach ($muniRevenue as $cidadeKey => $totalMuniRev) {
        if (isset($territories[$cidadeKey])) {
            $territories[$cidadeKey]['revenue'] = $totalMuniRev;
        }
    }

    // ── Escalas por tipo territorial (para coloração proporcional) ────────────
    $scales = [
        'fortaleza_bairro'        => ['max' => 0.0],
        'metro_bairro'            => ['max' => 0.0],
        'rmf_municipio_fallback'  => ['max' => 0.0],
        'ceara_municipio'         => ['max' => 0.0],
    ];
    foreach ($territories as $t) {
        $type = $t['territory_type'] ?? '';
        $rev  = (float)($t['revenue'] ?? 0);
        if (isset($scales[$type]) && $rev > $scales[$type]['max']) {
            $scales[$type]['max'] = $rev;
        }
    }

    // ── Ranking: top 50 territórios com venda ─────────────────────────────────
    $ranking = array_values(array_filter($territories, fn($t) => ($t['revenue'] ?? 0) > 0));
    usort($ranking, fn($a, $b) => $b['revenue'] <=> $a['revenue']);
    $ranking = array_slice($ranking, 0, 50);

    $withVenda = count(array_filter($territories, fn($t) => ($t['revenue'] ?? 0) > 0));

    Http::json([
        'ok'        => true,
        'layer'     => 'mapa_composto_ceara',
        'date_from' => $range['date_from'],
        'date_to'   => $range['date_to'],
        'summary'   => [
            'total_vendido'         => $totalRevenue,
            'territorios_com_venda' => $withVenda,
            'clientes'              => $totalCustomers,
            'notas'                 => $totalInvoices,
            'sem_bairro'            => $semBairro,
        ],
        'territories' => $territories,
        'scales'      => $scales,
        'ranking'     => $ranking,
    ]);
} catch (\Throwable $e) {
    Http::jsonError('geo_composite_sales_error', $e->getMessage());
}

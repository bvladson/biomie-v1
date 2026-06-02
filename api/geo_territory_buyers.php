<?php

declare(strict_types=1);

/**
 * api/geo_territory_buyers.php
 *
 * GEO.R1.9.5 — Compradores agregados de um território.
 *
 * Parâmetros:
 *   territory_type = fortaleza_bairro|metro_bairro|rmf_municipio_fallback|ceara_municipio|sem_geo
 *   cidade_key     = cidade normalizada
 *   bairro_key     = bairro normalizado (obrigatório para tipos bairro)
 *   date_from / date_to
 *   scope, seller_id, cnae (opcionais)
 *   limit (default 10, max 50)
 *
 * Fonte: mart_compradores_por_geo (lookup) → biomie_notas_fiscais_canonicas JOIN biomie_clientes_geo_dim
 */

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\Support\BiVisibilityScope;
use BIomie\Support\DocumentMask;
use BIomie\Support\MapFilterSupport;

require __DIR__ . '/bootstrap.php';

Auth::requireModule('geo', true);

try {
    $f         = MapFilterSupport::fromRequest($_GET);
    $cidadeKey = strtolower(trim((string) ($_GET['cidade_key']    ?? '')));
    $bairroKey = strtolower(trim((string) ($_GET['bairro_key']    ?? '')));
    $ttype     = trim((string) ($_GET['territory_type'] ?? ''));
    $limit     = min(50, max(1, (int) ($_GET['limit'] ?? 10)));
    $isNoGeo   = in_array($ttype, ['sem_geo', 'sem_bairro'], true) || $bairroKey === '__sem_bairro__';
    $filterCity = strtolower(trim((string) ($_GET['filter_city'] ?? '')));

    if ($cidadeKey === '' && !$isNoGeo) {
        Http::json(['ok' => false, 'error' => 'missing_cidade_key'], 400);
        return;
    }

    $connectionData = Database::getGeoConnection($config);
    $pdo = $connectionData[0];

    $canViewDocs = class_exists('BIomie\\Auth') && method_exists('BIomie\\Auth', 'canViewFullDocuments')
        ? \BIomie\Auth::canViewFullDocuments()
        : false;
    $maskFn = class_exists('BIomie\\Support\\DocumentMask') ? ['\BIomie\Support\DocumentMask', 'mask'] : null;

    // Primeiro tenta o mesmo mart que alimenta o mapa, para o painel nao divergir dos KPIs.
    $martTable = 'mart_compradores_por_geo';
    if (Http::tableExists($pdo, $martTable)) {
        $startComp = substr($f['date_from'], 0, 7);
        $endComp   = substr($f['date_to'],   0, 7);
        $hasClientDim = Http::tableExists($pdo, 'biomie_clientes_dim');
        $dimJoin = $hasClientDim
            ? 'LEFT JOIN biomie_clientes_dim dim ON dim.codigo_cliente_omie = m.codigo_cliente_omie'
            : '';
        $martCnaeExpr = Http::columnExists($pdo, $martTable, 'cnae_codigo') ? "NULLIF(TRIM(m.cnae_codigo), '')" : 'NULL';
        $dimCnaeExpr = $hasClientDim ? "NULLIF(TRIM(dim.cnae_codigo), '')" : 'NULL';
        $rawCnaeExpr = $hasClientDim
            ? "COALESCE({$martCnaeExpr}, {$dimCnaeExpr})"
            : $martCnaeExpr;
        $martDescExpr = Http::columnExists($pdo, $martTable, 'cnae_descricao')
            ? "NULLIF(NULLIF(TRIM(m.cnae_descricao), ''), 'CNAE não informado')"
            : 'NULL';
        $dimDescExpr = $hasClientDim
            ? "NULLIF(NULLIF(TRIM(dim.cnae_descricao), ''), 'CNAE não informado')"
            : 'NULL';
        $descExpr = $hasClientDim
            ? "COALESCE({$martDescExpr}, {$dimDescExpr})"
            : $martDescExpr;

        $where = ['m.competencia BETWEEN :s AND :e'];
        $params = [':s' => $startComp, ':e' => $endComp];

        if ($isNoGeo) {
            $where[] = "(m.cidade_norm IS NULL OR TRIM(m.cidade_norm) = '' OR m.bairro_norm IS NULL OR TRIM(m.bairro_norm) = '' OR LOWER(TRIM(m.bairro_norm)) IN ('nao informado', 'sem bairro', 'sem_bairro'))";
            if ($filterCity !== '') {
                $where[] = "(LOWER(TRIM(m.cidade_norm)) = :filter_city OR m.cidade_norm IS NULL OR TRIM(m.cidade_norm) = '')";
                $params[':filter_city'] = $filterCity;
            }
        } elseif ($ttype === 'fortaleza_bairro' && $bairroKey !== '') {
            $where[] = "LOWER(TRIM(m.cidade_norm)) = 'fortaleza'";
            $where[] = 'm.bairro_norm COLLATE utf8mb4_general_ci = :bairro';
            $params[':bairro'] = $bairroKey;
        } elseif ($ttype === 'metro_bairro' && $bairroKey !== '') {
            $where[] = 'LOWER(TRIM(m.cidade_norm)) = :cidade';
            $where[] = 'm.bairro_norm COLLATE utf8mb4_general_ci = :bairro';
            $params[':cidade'] = $cidadeKey;
            $params[':bairro'] = $bairroKey;
        } else {
            $where[] = 'LOWER(TRIM(m.cidade_norm)) = :cidade';
            $params[':cidade'] = $cidadeKey;
        }

        if (!empty($f['seller_id']) && Http::columnExists($pdo, $martTable, 'nome_vendedor')) {
            $where[] = 'LOWER(m.nome_vendedor) LIKE :vendedor';
            $params[':vendedor'] = '%' . mb_strtolower(trim($f['seller_id'])) . '%';
        }

        if (!empty($f['cnae'])) {
            if (strtoupper(trim((string) $f['cnae'])) === 'SEM_CNAE') {
                $where[] = "{$rawCnaeExpr} IS NULL";
            } else {
                $cnaeNorm = preg_replace('/\D+/', '', (string) $f['cnae']);
                $cnaeWhere = ["{$rawCnaeExpr} = :cnae_code", "LOWER({$descExpr}) LIKE :cnae_like"];
                $params[':cnae_code'] = $f['cnae'];
                $params[':cnae_like'] = '%' . mb_strtolower((string) $f['cnae']) . '%';
                if ($cnaeNorm !== '') {
                    $cnaeWhere[] = "REGEXP_REPLACE({$rawCnaeExpr}, '[^0-9]', '') = :cnae_norm";
                    $params[':cnae_norm'] = $cnaeNorm;
                }
                $where[] = '(' . implode(' OR ', $cnaeWhere) . ')';
            }
        }

        $whereSql = implode(' AND ', $where);
        $stmtMart = $pdo->prepare("
            SELECT
                MAX(m.cliente_nome) AS customer_name,
                MAX(m.documento) AS document_raw,
                COALESCE(SUM(m.faturamento), 0) AS revenue,
                COALESCE(SUM(m.qtd_notas), 0) AS invoices,
                MAX(m.ultima_compra) AS last_purchase,
                MAX({$rawCnaeExpr}) AS cnae,
                MAX(m.codigo_cliente_omie) AS codigo_cliente_omie,
                MAX(m.cidade_norm) AS cidade_norm,
                MAX(m.bairro_norm) AS bairro_norm
            FROM {$martTable} m
            {$dimJoin}
            WHERE {$whereSql}
            GROUP BY COALESCE(CAST(m.codigo_cliente_omie AS CHAR), m.documento, m.cliente_nome)
            ORDER BY revenue DESC
            LIMIT :lim
        ");
        foreach ($params as $k => $v) $stmtMart->bindValue($k, $v);
        $stmtMart->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmtMart->execute();
        $martRows = $stmtMart->fetchAll(\PDO::FETCH_ASSOC);

        if ($martRows) {
            $data = array_map(function ($row) use ($canViewDocs, $maskFn) {
                $doc      = (string) ($row['document_raw'] ?? '');
                $revenue  = (float)  ($row['revenue']      ?? 0);
                $invoices = (int)    ($row['invoices']      ?? 0);
                $ticket   = $invoices > 0 ? $revenue / $invoices : 0.0;

                return [
                    'customer_name'  => $row['customer_name'] ?? '',
                    'document_raw'   => $canViewDocs ? $doc : ($maskFn ? call_user_func($maskFn, $doc) : (strlen($doc) > 6 ? substr($doc, 0, 3) . '***' . substr($doc, -2) : '***')),
                    'revenue'        => $revenue,
                    'invoices'       => $invoices,
                    'average_ticket' => round($ticket, 2),
                    'last_purchase'  => $row['last_purchase'] ?? null,
                    'cnae'           => $row['cnae'] ?? null,
                    'segment'        => null,
                    'codigo_cliente_omie' => $row['codigo_cliente_omie'] ?? null,
                    'cidade'         => $row['cidade_norm'] ?? '',
                    'cidade_norm'    => $row['cidade_norm'] ?? '',
                    'bairro'         => $row['bairro_norm'] ?? '',
                    'bairro_norm'    => $row['bairro_norm'] ?? '',
                ];
            }, $martRows);

            Http::json([
                'ok'   => true,
                'data' => $data,
                'meta' => [
                    'source_mode'    => 'mart_compradores_por_geo',
                    'count'          => count($data),
                    'territory_type' => $ttype,
                ],
            ]);
            return;
        }
    }

    $nfTable  = 'biomie_notas_fiscais_canonicas';
    $geoTable = 'biomie_clientes_geo_dim';

    if (!Http::tableExists($pdo, $nfTable) || !Http::tableExists($pdo, $geoTable)) {
        Http::json([
            'ok'   => true,
            'data' => [],
            'meta' => ['source_mode' => 'unavailable', 'count' => 0, 'territory_type' => $ttype],
            'empty_reason' => 'Tabelas de canonical/geo não disponíveis',
        ]);
        return;
    }

    // Detectar colunas opcionais
    $hasVendedor = Http::columnExists($pdo, $nfTable, 'nome_vendedor');
    $hasCnaeNf   = Http::columnExists($pdo, $nfTable, 'cnae_codigo');
    $hasCnaeGeo  = Http::columnExists($pdo, $geoTable, 'cnae_codigo');
    $hasValorNfe = Http::columnExists($pdo, $nfTable, 'valor_total') ? 'valor_total'
                 : (Http::columnExists($pdo, $nfTable, 'valor_nfe') ? 'valor_nfe' : null);
    if (!$hasValorNfe) {
        Http::json([
            'ok'   => true,
            'data' => [],
            'meta' => ['source_mode' => 'no_value_col', 'count' => 0, 'territory_type' => $ttype],
            'empty_reason' => 'Coluna de valor não encontrada',
        ]);
        return;
    }

    // Visibilidade comercial
    $visScope = '';
    if (class_exists('BIomie\\Support\\BiVisibilityScope')) {
        try {
            $visScope = BiVisibilityScope::getCondition($pdo, $nfTable, 'nf');
            if ($visScope) $visScope = 'AND ' . $visScope;
        } catch (\Throwable) { $visScope = ''; }
    }

    // GEO.R1.9.5 — Try mart-based client lookup for consistent data (mirrors geo_territory_invoices.php R1.9.2)
    $useMartLookup = false;
    $martClientCodes = [];

    if ($ttype !== 'fortaleza_bairro' && $ttype !== 'metro_bairro' && Http::tableExists($pdo, $martTable)) {
        $startComp = substr($f['date_from'], 0, 7);
        $endComp   = substr($f['date_to'],   0, 7);
        $martWhere  = ['competencia BETWEEN :mc_s AND :mc_e'];
        $martParams = [':mc_s' => $startComp, ':mc_e' => $endComp];
        if ($isNoGeo) {
            $martWhere[] = "(cidade_norm IS NULL OR TRIM(cidade_norm) = '' OR bairro_norm IS NULL OR TRIM(bairro_norm) = '' OR LOWER(TRIM(bairro_norm)) IN ('nao informado', 'sem bairro', 'sem_bairro'))";
            if ($filterCity !== '') {
                $martWhere[] = "(LOWER(TRIM(cidade_norm)) = :mc_filter_city OR cidade_norm IS NULL OR TRIM(cidade_norm) = '')";
                $martParams[':mc_filter_city'] = $filterCity;
            }
        } else {
            $martWhere[] = 'LOWER(TRIM(cidade_norm)) = :mc_cidade';
            $martParams[':mc_cidade'] = $cidadeKey;
        }
        try {
            $stmtCodes = $pdo->prepare("SELECT DISTINCT codigo_cliente_omie FROM {$martTable} WHERE " . implode(' AND ', $martWhere) . " AND codigo_cliente_omie IS NOT NULL LIMIT 500");
            foreach ($martParams as $k => $v) $stmtCodes->bindValue($k, $v);
            $stmtCodes->execute();
            $martClientCodes = array_column($stmtCodes->fetchAll(\PDO::FETCH_ASSOC), 'codigo_cliente_omie');
            $useMartLookup = !empty($martClientCodes);
        } catch (\Throwable) { $useMartLookup = false; }
    }

    // ── Filtros WHERE ────────────────────────────────────────────────────────
    $where  = ["nf.status_fiscal = 'autorizada'"];
    $params = [
        ':date_from' => $f['date_from'],
        ':date_to'   => $f['date_to'],
    ];

    // Filtro de data
    $where[] = 'DATE(COALESCE(nf.data_competencia, nf.data_emissao)) BETWEEN :date_from AND :date_to';

    if ($useMartLookup) {
        // GEO.R1.9.5 — Use mart-derived client list: no mandatory geo JOIN required
        $codePlaceholders = implode(',', array_fill(0, count($martClientCodes), '?'));
        $where[] = "nf.codigo_cliente_omie IN ({$codePlaceholders})";
    } else {
        // Filtro territorial via JOIN com geo_dim (original path)
        if ($isNoGeo) {
            $where[] = "(geo.cidade_norm IS NULL OR TRIM(geo.cidade_norm) = '' OR geo.bairro_norm IS NULL OR TRIM(geo.bairro_norm) = '' OR LOWER(TRIM(geo.bairro_norm)) IN ('nao informado', 'sem bairro', 'sem_bairro'))";
            if ($filterCity !== '') {
                $where[] = "(LOWER(TRIM(geo.cidade_norm)) = :filter_city OR geo.cidade_norm IS NULL OR TRIM(geo.cidade_norm) = '')";
                $params[':filter_city'] = $filterCity;
            }
        } elseif ($ttype === 'fortaleza_bairro' && $bairroKey !== '') {
            $where[] = "LOWER(TRIM(geo.cidade_norm)) = 'fortaleza'";
            $where[] = 'geo.bairro_norm COLLATE utf8mb4_general_ci = :bairro_key';
            $params[':bairro_key'] = $bairroKey;
        } elseif ($ttype === 'metro_bairro' && $bairroKey !== '') {
            $where[] = 'LOWER(TRIM(geo.cidade_norm)) = :cidade';
            $where[] = 'geo.bairro_norm COLLATE utf8mb4_general_ci = :bairro_key';
            $params[':cidade']     = $cidadeKey;
            $params[':bairro_key'] = $bairroKey;
        } else {
            $where[] = 'LOWER(TRIM(geo.cidade_norm)) = :cidade';
            $params[':cidade'] = $cidadeKey;
        }
    }

    // Filtro de vendedor
    if (!empty($f['seller_id'])) {
        if ($hasVendedor) {
            $where[] = 'LOWER(nf.nome_vendedor) LIKE :vendedor';
            $params[':vendedor'] = '%' . mb_strtolower(trim($f['seller_id'])) . '%';
        }
    }

    // Filtro CNAE
    if (!empty($_GET['cnae'])) {
        if ($hasCnaeNf) {
            $where[] = 'nf.cnae_codigo = :cnae';
            $params[':cnae'] = $_GET['cnae'];
        }
    }

    $whereSql    = implode(' AND ', $where);
    $geoJoinType = $useMartLookup ? 'LEFT' : 'INNER';

    // Coluna CNAE — prefere NF, depois geo_dim
    $cnaeCol = $hasCnaeNf  ? 'MAX(nf.cnae_codigo)'
             : ($hasCnaeGeo ? 'MAX(geo.cnae_codigo)' : "''");

    // ── Compradores agrupados por cliente ─────────────────────────────────────
    $stmtBuyers = $pdo->prepare("
        SELECT
            MAX(nf.nome_cliente)                                                            AS customer_name,
            MAX(nf.documento_cliente)                                                       AS document_raw,
            COALESCE(SUM(nf.{$hasValorNfe}), 0)                                             AS revenue,
            COUNT(DISTINCT nf.id)                                                           AS invoices,
            MAX(DATE(COALESCE(nf.data_competencia, nf.data_emissao)))                       AS last_purchase,
            {$cnaeCol}                                                                      AS cnae,
            nf.codigo_cliente_omie,
            MAX(geo.cidade_norm)                                                            AS cidade_norm,
            MAX(geo.bairro_norm)                                                            AS bairro_norm
        FROM {$nfTable} nf
        {$geoJoinType} JOIN {$geoTable} geo ON geo.codigo_cliente_omie = nf.codigo_cliente_omie
        WHERE {$whereSql} {$visScope}
        GROUP BY nf.codigo_cliente_omie
        ORDER BY revenue DESC
        LIMIT :lim
    ");

    foreach ($params as $k => $v) $stmtBuyers->bindValue($k, $v);
    if ($useMartLookup) {
        foreach (array_values($martClientCodes) as $idx => $code) {
            $stmtBuyers->bindValue($idx + 1, $code);
        }
    }
    $stmtBuyers->bindValue(':lim', $limit, \PDO::PARAM_INT);
    $stmtBuyers->execute();
    $buyerRows = $stmtBuyers->fetchAll(\PDO::FETCH_ASSOC);

    $data = array_map(function ($row) use ($canViewDocs, $maskFn) {
        $doc      = (string) ($row['document_raw'] ?? '');
        $revenue  = (float)  ($row['revenue']      ?? 0);
        $invoices = (int)    ($row['invoices']      ?? 0);
        $ticket   = $invoices > 0 ? $revenue / $invoices : 0.0;

        return [
            'customer_name'  => $row['customer_name'] ?? '',
            'document_raw'   => $canViewDocs ? $doc : ($maskFn ? call_user_func($maskFn, $doc) : (strlen($doc) > 6 ? substr($doc, 0, 3) . '***' . substr($doc, -2) : '***')),
            'revenue'        => $revenue,
            'invoices'       => $invoices,
            'average_ticket' => round($ticket, 2),
            'last_purchase'  => $row['last_purchase'] ?? null,
            'cnae'           => $row['cnae'] ?? null,
            'segment'        => null, // future: CommercialChannelPresenter
            'codigo_cliente_omie' => $row['codigo_cliente_omie'] ?? null,
            'cidade'         => $row['cidade_norm'] ?? '',
            'cidade_norm'    => $row['cidade_norm'] ?? '',
            'bairro'         => $row['bairro_norm'] ?? '',
            'bairro_norm'    => $row['bairro_norm'] ?? '',
        ];
    }, $buyerRows);

    $sourceMode = $useMartLookup ? 'geo_territory_mart' : 'geo_territory_join';

    Http::json([
        'ok'   => true,
        'data' => $data,
        'meta' => [
            'source_mode'    => $sourceMode,
            'count'          => count($data),
            'territory_type' => $ttype,
        ],
    ]);

} catch (\Throwable $e) {
    Http::jsonError('geo_territory_buyers_error', $e->getMessage());
}

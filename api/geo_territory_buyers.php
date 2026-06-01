<?php

declare(strict_types=1);

/**
 * api/geo_territory_buyers.php
 *
 * GEO.R1.9.5 — Compradores agregados de um território.
 *
 * Parâmetros:
 *   territory_type = fortaleza_bairro|metro_bairro|rmf_municipio_fallback|ceara_municipio
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

    if ($cidadeKey === '') {
        Http::json(['ok' => false, 'error' => 'missing_cidade_key'], 400);
        return;
    }

    $connectionData = Database::getGeoConnection($config);
    $pdo = $connectionData[0];

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

    $canViewDocs = class_exists('BIomie\\Auth') && method_exists('BIomie\\Auth', 'canViewFullDocuments')
        ? \BIomie\Auth::canViewFullDocuments()
        : false;

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
    $martTable = 'mart_compradores_por_geo';
    $useMartLookup = false;
    $martClientCodes = [];

    if ($ttype !== 'fortaleza_bairro' && $ttype !== 'metro_bairro' && Http::tableExists($pdo, $martTable)) {
        $startComp = substr($f['date_from'], 0, 7);
        $endComp   = substr($f['date_to'],   0, 7);
        $martWhere  = ['competencia BETWEEN :mc_s AND :mc_e', 'LOWER(TRIM(cidade_norm)) = :mc_cidade'];
        $martParams = [':mc_s' => $startComp, ':mc_e' => $endComp, ':mc_cidade' => $cidadeKey];
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
        if ($ttype === 'fortaleza_bairro' && $bairroKey !== '') {
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
            nf.codigo_cliente_omie
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

    $maskFn = class_exists('BIomie\\Support\\DocumentMask') ? ['\BIomie\Support\DocumentMask', 'mask'] : null;

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

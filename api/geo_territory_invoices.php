<?php

declare(strict_types=1);

/**
 * api/geo_territory_invoices.php
 *
 * GEO.R1.9 — Lista paginada de notas fiscais de um território.
 *
 * Parâmetros:
 *   territory_type = fortaleza_bairro|metro_bairro|rmf_municipio_fallback|ceara_municipio
 *   cidade_key     = cidade normalizada
 *   bairro_key     = bairro normalizado (obrigatório para tipos bairro)
 *   date_from / date_to
 *   page, per_page (default 1, 20)
 *   scope, vendor, cnae (opcionais)
 *
 * Fonte: biomie_notas_fiscais_canonicas JOIN biomie_clientes_geo_dim
 */

use BIomie\Auth;
use BIomie\Database;
use BIomie\DataPlatform\Query\GeoQuery;
use BIomie\DataPlatform\ReadModel\GeoReadModelFactory;
use BIomie\DataPlatform\Support\FeatureFlags;
use BIomie\DataPlatform\Support\ReadModelPerformanceLogger;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\Support\BiVisibilityScope;
use BIomie\Support\DocumentMask;
use BIomie\Support\MapFilterSupport;

require __DIR__ . '/bootstrap.php';

Auth::requireModule('geo', true);

$useReadModel = FeatureFlags::geoReadModelV2($config) || FeatureFlags::adminRequestOverride('readmodel');
$forceReadModel = FeatureFlags::adminRequestOverride('readmodel');
$forceLegacy = FeatureFlags::adminRequestOverride('legacy');
if ($useReadModel && !$forceLegacy) {
    try {
        $startedAt = microtime(true);
        $cidadeKey = strtolower(trim((string) ($_GET['cidade_key'] ?? '')));
        $bairroKey = strtolower(trim((string) ($_GET['bairro_key'] ?? '')));
        $ttype = trim((string) ($_GET['territory_type'] ?? ''));
        $isNoGeo = in_array($ttype, ['sem_geo', 'sem_bairro'], true) || $bairroKey === '__sem_bairro__';
        if ($cidadeKey === '' && !$isNoGeo) {
            Http::json(['ok' => false, 'error' => 'missing_cidade_key'], 400);
        }

        $query = GeoQuery::fromRequest($_GET);
        $geo = GeoReadModelFactory::make($config);
        $payload = $geo->legacyTerritoryInvoicesPayload($query, $_GET, Auth::canViewFullDocuments());
        $payload['feature_flag_active'] = FeatureFlags::geoReadModelV2($config);
        $payload['request_id'] = RequestContext::id();

        ReadModelPerformanceLogger::logGeo(
            $config,
            $geo,
            $query,
            'geo_territory_invoices',
            'geo.territory_invoices.v1',
            'readmodel',
            $startedAt,
            $payload
        );

        Http::json($payload);
    } catch (\Throwable $exception) {
        global $logger;
        $logger?->error('geo_territory_invoices readmodel fallback', [
            'exception' => $exception->getMessage(),
            'request_id' => RequestContext::id(),
        ]);

        if ($forceReadModel) {
            Http::json([
                'ok' => false,
                'error' => 'geo_readmodel_error',
                'message' => 'Nao foi possivel carregar notas do territorio pelo ReadModel.',
                'request_id' => RequestContext::id(),
            ], 500);
        }
    }
}

try {
    $f          = MapFilterSupport::fromRequest($_GET);
    $cidadeKey  = strtolower(trim((string) ($_GET['cidade_key']    ?? '')));
    $bairroKey  = strtolower(trim((string) ($_GET['bairro_key']    ?? '')));
    $ttype      = trim((string) ($_GET['territory_type'] ?? ''));
    $page       = max(1, (int) ($_GET['page']     ?? 1));
    $perPage    = min(50, max(1, (int) ($_GET['per_page'] ?? 20)));
    $offset     = ($page - 1) * $perPage;

    if ($cidadeKey === '') {
        Http::json(['ok' => false, 'error' => 'missing_cidade_key'], 400);
    }

    $connectionData = Database::getGeoConnection($config);
    $pdo = $connectionData[0];

    $nfTable  = 'biomie_notas_fiscais_canonicas';
    $geoTable = 'biomie_clientes_geo_dim';

    if (!Http::tableExists($pdo, $nfTable) || !Http::tableExists($pdo, $geoTable)) {
        Http::json([
            'ok'    => true,
            'summary' => ['notas' => 0, 'valor_total' => 0, 'clientes' => 0, 'ticket_medio' => 0],
            'items' => [],
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => 0],
            'empty_reason' => 'Tabelas de canonical/geo não disponíveis',
        ]);
        return;
    }

    $canViewDocs = class_exists('BIomie\\Auth') && method_exists('BIomie\\Auth', 'canViewFullDocuments')
        ? \BIomie\Auth::canViewFullDocuments()
        : false;

    // Detectar colunas opcionais
    $hasVendedor = Http::columnExists($pdo, $nfTable, 'nome_vendedor');
    $hasChaveNfe = Http::columnExists($pdo, $nfTable, 'chave_nfe');
    $hasCnaeNf   = Http::columnExists($pdo, $nfTable, 'cnae_codigo');
    $hasValorNfe = Http::columnExists($pdo, $nfTable, 'valor_total') ? 'valor_total'
                 : (Http::columnExists($pdo, $nfTable, 'valor_nfe') ? 'valor_nfe' : null);
    if (!$hasValorNfe) {
        Http::json(['ok' => true, 'summary' => ['notas' => 0, 'valor_total' => 0, 'clientes' => 0, 'ticket_medio' => 0], 'items' => [], 'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => 0], 'empty_reason' => 'Coluna de valor não encontrada']);
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

    // GEO.R1.9.2 — Try mart-based client lookup for consistent data (avoids INNER JOIN miss)
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
        // GEO.R1.9.2 — Use mart-derived client list: no mandatory geo JOIN required
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
            $params[':cidade']    = $cidadeKey;
            $params[':bairro_key'] = $bairroKey;
        } else {
            // Municipality — all NFs for this city (aggregated)
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

    $whereSql = implode(' AND ', $where);
    $vendedorCol = $hasVendedor ? 'MAX(nf.nome_vendedor)' : "''";
    $chaveCol    = $hasChaveNfe ? 'MAX(nf.chave_nfe)' : "''";

    // Build JOIN clause: LEFT JOIN geo for display columns (mart path), INNER JOIN for filter path
    $geoJoinType = $useMartLookup ? 'LEFT' : 'INNER';

    // ── Resumo ────────────────────────────────────────────────────────────────
    $stmtSum = $pdo->prepare("
        SELECT
            COUNT(DISTINCT nf.id)                   AS notas,
            COALESCE(SUM(nf.{$hasValorNfe}), 0)     AS valor_total,
            COUNT(DISTINCT COALESCE(CAST(nf.codigo_cliente_omie AS CHAR), nf.documento_cliente, nf.nome_cliente)) AS clientes
        FROM {$nfTable} nf
        {$geoJoinType} JOIN {$geoTable} geo ON geo.codigo_cliente_omie = nf.codigo_cliente_omie
        WHERE {$whereSql} {$visScope}
    ");
    foreach ($params as $k => $v) $stmtSum->bindValue($k, $v);
    if ($useMartLookup) {
        foreach (array_values($martClientCodes) as $idx => $code) {
            $stmtSum->bindValue($idx + 1, $code);
        }
    }
    $stmtSum->execute();
    $sumRow = $stmtSum->fetch(\PDO::FETCH_ASSOC) ?: [];

    $totalNotas  = (int)($sumRow['notas']      ?? 0);
    $totalValor  = (float)($sumRow['valor_total'] ?? 0);
    $totalCli    = (int)($sumRow['clientes']   ?? 0);
    $ticketMedio = $totalNotas > 0 ? $totalValor / $totalNotas : 0.0;

    // ── Listagem paginada ─────────────────────────────────────────────────────
    $stmtList = $pdo->prepare("
        SELECT
            nf.id,
            MAX(nf.numero_nf)                       AS numero_nf,
            MAX(nf.serie_nf)                         AS serie_nf,
            MAX(COALESCE(nf.data_competencia, nf.data_emissao)) AS data_emissao,
            MAX(nf.nome_cliente)                     AS cliente_nome,
            MAX(nf.documento_cliente)                AS cliente_documento,
            MAX(geo.cidade_norm)                     AS cidade,
            MAX(geo.bairro_norm)                     AS bairro,
            {$vendedorCol}                           AS vendedor_nome,
            COALESCE(SUM(nf.{$hasValorNfe}), 0)     AS valor
        FROM {$nfTable} nf
        {$geoJoinType} JOIN {$geoTable} geo ON geo.codigo_cliente_omie = nf.codigo_cliente_omie
        WHERE {$whereSql} {$visScope}
        GROUP BY nf.id
        ORDER BY data_emissao DESC, valor DESC
        LIMIT :lim OFFSET :off
    ");
    foreach ($params as $k => $v) $stmtList->bindValue($k, $v);
    if ($useMartLookup) {
        foreach (array_values($martClientCodes) as $idx => $code) {
            $stmtList->bindValue($idx + 1, $code);
        }
    }
    $stmtList->bindValue(':lim', $perPage, \PDO::PARAM_INT);
    $stmtList->bindValue(':off', $offset,  \PDO::PARAM_INT);
    $stmtList->execute();
    $listRows = $stmtList->fetchAll(\PDO::FETCH_ASSOC);

    $maskFn = class_exists('BIomie\\Support\\DocumentMask') ? ['\BIomie\Support\DocumentMask', 'mask'] : null;

    $items = array_map(function($row) use ($canViewDocs, $maskFn) {
        $doc = (string)($row['cliente_documento'] ?? '');
        return [
            'id'             => $row['id'],
            'numero_nf'      => $row['numero_nf'],
            'serie'          => $row['serie_nf'] ?: null,
            'data_emissao'   => $row['data_emissao'],
            'cliente_nome'   => $row['cliente_nome'],
            'cliente_documento' => $canViewDocs ? $doc : ($maskFn ? call_user_func($maskFn, $doc) : substr($doc, 0, 3) . '***'),
            'cidade'         => $row['cidade'],
            'bairro'         => $row['bairro'],
            'vendedor_nome'  => $row['vendedor_nome'] ?: null,
            'valor'          => (float)$row['valor'],
        ];
    }, $listRows);

    Http::json([
        'ok'     => true,
        'summary' => [
            'notas'       => $totalNotas,
            'valor_total' => $totalValor,
            'clientes'    => $totalCli,
            'ticket_medio'=> $ticketMedio,
        ],
        'items' => $items,
        'pagination' => [
            'page'     => $page,
            'per_page' => $perPage,
            'total'    => $totalNotas,
        ],
    ]);

} catch (\Throwable $e) {
    Http::jsonError('geo_territory_invoices_error', $e->getMessage());
}

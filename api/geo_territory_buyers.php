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
 *   scope, seller_id, cnae, document_type=all|cnpj|cpf (opcionais)
 *   limit (default 10, max 50)
 *
 * Fonte: mart_compradores_por_geo (lookup) → biomie_notas_fiscais_canonicas JOIN biomie_clientes_geo_dim
 */

use BIomie\Auth;
use BIomie\Database;
use BIomie\DataPlatform\ReadModel\CustomerReadModelFactory;
use BIomie\DataPlatform\Query\GeoQuery;
use BIomie\DataPlatform\ReadModel\GeoReadModelFactory;
use BIomie\DataPlatform\Support\CustomerLegacyAdapter;
use BIomie\DataPlatform\Support\FeatureFlags;
use BIomie\DataPlatform\Support\ReadModelPerformanceLogger;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\Support\BiVisibilityScope;
use BIomie\Support\DocumentMask;
use BIomie\Support\MapFilterSupport;
use BIomie\Support\GeoLocationScope;

require __DIR__ . '/bootstrap.php';

/** @var \BIomie\Config $config */
Auth::requireModule('geo', true);

if (!function_exists('biomieGeoBuyerDocumentDigits')) {
    function biomieGeoBuyerDocumentDigits(?string $document): string
    {
        return preg_replace('/\D+/', '', (string) ($document ?? '')) ?? '';
    }
}

if (!function_exists('biomieGeoBuyerDocumentType')) {
    function biomieGeoBuyerDocumentType(?string $document): string
    {
        $digits = biomieGeoBuyerDocumentDigits($document);

        return match (strlen($digits)) {
            14 => 'cnpj',
            11 => 'cpf',
            default => 'unknown',
        };
    }
}

if (!function_exists('biomieGeoBuyerDocumentLabel')) {
    function biomieGeoBuyerDocumentLabel(string $documentType): string
    {
        return match ($documentType) {
            'cnpj' => 'CNPJ',
            'cpf' => 'CPF',
            default => 'Documento',
        };
    }
}

if (!function_exists('biomieGeoBuyerColumnExists')) {
    function biomieGeoBuyerColumnExists(\PDO $pdo, string $table, string $column): bool
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
            );
            $stmt->execute([':table' => $table, ':column' => $column]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (\Throwable) {
            return false;
        }
    }
}

if (!function_exists('biomieGeoBuyerMaskDocument')) {
    function biomieGeoBuyerMaskDocument(?string $document, bool $canViewDocs, ?callable $maskFn): string
    {
        $digits = biomieGeoBuyerDocumentDigits($document);
        if ($digits === '') {
            return 'Nao informado';
        }

        if ($maskFn) {
            $masked = (string) call_user_func($maskFn, $document, 'Nao informado');
            if ($masked !== '' && $masked !== 'Nao informado') {
                return $masked;
            }
        }

        return strlen($digits) > 6
            ? substr($digits, 0, 3) . '***' . substr($digits, -2)
            : '***';
    }
}

if (!function_exists('biomieGeoBuyerBuildSummary')) {
    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,array<string,float|int>>
     */
    function biomieGeoBuyerBuildSummary(array $rows): array
    {
        $summary = [
            'total' => ['count' => 0, 'revenue' => 0.0, 'invoices' => 0],
            'cnpj' => ['count' => 0, 'revenue' => 0.0, 'invoices' => 0],
            'cpf' => ['count' => 0, 'revenue' => 0.0, 'invoices' => 0],
            'unknown' => ['count' => 0, 'revenue' => 0.0, 'invoices' => 0],
        ];

        foreach ($rows as $row) {
            $type = (string) ($row['document_type'] ?? 'unknown');
            if (!isset($summary[$type])) {
                $type = 'unknown';
            }

            $revenue = (float) ($row['revenue'] ?? 0);
            $invoices = (int) ($row['invoices'] ?? 0);
            foreach (['total', $type] as $bucket) {
                $summary[$bucket]['count']++;
                $summary[$bucket]['revenue'] += $revenue;
                $summary[$bucket]['invoices'] += $invoices;
            }
        }

        return $summary;
    }
}

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
        $payload = $geo->legacyTerritoryBuyersPayload($query, $_GET, Auth::canViewFullDocuments());
        $customerModel = CustomerReadModelFactory::make($config);
        $payload['data'] = CustomerLegacyAdapter::enrichRows($customerModel, (array) $payload['data']);
        $payload['meta']['summary'] = biomieGeoBuyerBuildSummary((array) $payload['data']);
        $payload['meta']['customer_source_contract'] = 'customer.profile.v1';
        $payload['meta']['customer_read_model'] = 'CustomerReadModel';
        $payload['feature_flag_active'] = FeatureFlags::geoReadModelV2($config);
        $payload['request_id'] = RequestContext::id();

        ReadModelPerformanceLogger::logGeo(
            $config,
            $geo,
            $query,
            'geo_territory_buyers',
            'geo.territory_buyers.v1',
            'readmodel',
            $startedAt,
            $payload
        );

        Http::json($payload);
    } catch (\Throwable $exception) {
        global $logger;
        $logger?->error('geo_territory_buyers readmodel fallback', [
            'exception' => $exception->getMessage(),
            'request_id' => RequestContext::id(),
        ]);

        if ($forceReadModel) {
            Http::json([
                'ok' => false,
                'error' => 'geo_readmodel_error',
                'message' => 'Nao foi possivel carregar compradores do territorio pelo ReadModel.',
                'request_id' => RequestContext::id(),
            ], 500);
        }
    }
}

try {
    $f         = MapFilterSupport::fromRequest($_GET);
    $cidadeKey = strtolower(trim((string) ($_GET['cidade_key']    ?? '')));
    $bairroKey = strtolower(trim((string) ($_GET['bairro_key']    ?? '')));
    $ttype     = trim((string) ($_GET['territory_type'] ?? ''));
    $limit     = min(50, max(1, (int) ($_GET['limit'] ?? 10)));
    $isNoGeo   = in_array($ttype, ['sem_geo', 'sem_bairro'], true) || $bairroKey === '__sem_bairro__';
    $filterCity = strtolower(trim((string) ($_GET['filter_city'] ?? '')));
    $documentTypeFilter = strtolower(trim((string) ($_GET['document_type'] ?? 'all')));
    $documentTypeFilter = match ($documentTypeFilter) {
        'pj' => 'cnpj',
        'pf' => 'cpf',
        'cnpj', 'cpf' => $documentTypeFilter,
        default => 'all',
    };

    if ($cidadeKey === '' && !$isNoGeo) {
        Http::json(['ok' => false, 'error' => 'missing_cidade_key'], 400);
    }

    $connectionData = Database::getGeoConnection($config);
    $pdo = $connectionData[0];
    $customerModel = CustomerReadModelFactory::make($config);

    $canViewDocs = class_exists('BIomie\\Auth') && method_exists('BIomie\\Auth', 'canViewFullDocuments')
        ? \BIomie\Auth::canViewFullDocuments()
        : false;
    $maskFn = class_exists('BIomie\\Support\\DocumentMask') && method_exists(DocumentMask::class, 'maskDocument')
        ? [DocumentMask::class, 'maskDocument']
        : null;

    // Primeiro tenta o mesmo mart que alimenta o mapa, para o painel nao divergir dos KPIs.
    $martTable = 'mart_compradores_por_geo';
    if (Http::tableExists($pdo, $martTable)) {
        $startComp = substr($f['date_from'], 0, 7);
        $endComp   = substr($f['date_to'],   0, 7);
        $dimJoin = '';
        $martDocExpr = biomieGeoBuyerColumnExists($pdo, $martTable, 'documento') ? "NULLIF(TRIM(m.documento), '')" : 'NULL';
        $documentExpr = $martDocExpr;
        $martCnaeExpr = biomieGeoBuyerColumnExists($pdo, $martTable, 'cnae_codigo') ? "NULLIF(TRIM(m.cnae_codigo), '')" : 'NULL';
        $rawCnaeExpr = $martCnaeExpr;
        $martDescExpr = biomieGeoBuyerColumnExists($pdo, $martTable, 'cnae_descricao')
            ? "NULLIF(NULLIF(TRIM(m.cnae_descricao), ''), 'CNAE não informado')"
            : 'NULL';
        $descExpr = $martDescExpr;

        $where = [
            'm.competencia BETWEEN :s AND :e',
            \BIomie\Support\TenantContext::sqlFilter('m'),
        ];
        $params = [':s' => $startComp, ':e' => $endComp];

        if ($isNoGeo) {
            $where[] = "(m.cidade_norm IS NULL OR TRIM(m.cidade_norm) = '' OR m.bairro_norm IS NULL OR TRIM(m.bairro_norm) = '' OR LOWER(TRIM(m.bairro_norm)) IN ('nao informado', 'sem bairro', 'sem_bairro'))";
            if ($filterCity !== '') {
                $where[] = GeoLocationScope::missingNeighborhoodInCityPredicate('m', ':filter_city');
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

        if (!empty($f['seller_id']) && biomieGeoBuyerColumnExists($pdo, $martTable, 'nome_vendedor')) {
            $where[] = 'LOWER(m.nome_vendedor) LIKE :vendedor';
            $params[':vendedor'] = '%' . mb_strtolower(trim($f['seller_id'])) . '%';
        }

        if ($documentTypeFilter !== 'all') {
            $documentDigitsExpr = "REGEXP_REPLACE(COALESCE({$documentExpr}, ''), '[^0-9]', '')";
            $where[] = $documentTypeFilter === 'cnpj'
                ? "CHAR_LENGTH({$documentDigitsExpr}) = 14"
                : "CHAR_LENGTH({$documentDigitsExpr}) = 11";
        }

        if (!empty($f['cnae'])) {
            if (strtoupper(trim((string) $f['cnae'])) === 'SEM_CNAE') {
                $where[] = "({$rawCnaeExpr} IS NULL OR TRIM({$rawCnaeExpr}) = '' OR UPPER(TRIM({$rawCnaeExpr})) = 'SEM_CNAE')";
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
                MAX({$documentExpr}) AS document_raw,
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
                $documentType = biomieGeoBuyerDocumentType($doc);
                $documentMasked = biomieGeoBuyerMaskDocument($doc, $canViewDocs, $maskFn);

                return [
                    'customer_name'  => $row['customer_name'] ?? '',
                    'document_raw'   => $documentMasked,
                    'document_masked'=> $documentMasked,
                    'document_type'  => $documentType,
                    'document_label' => biomieGeoBuyerDocumentLabel($documentType),
                    'buyer_kind'     => $documentType === 'cnpj' ? 'b2b' : ($documentType === 'cpf' ? 'cpf' : 'unknown'),
                    'is_b2b'         => $documentType === 'cnpj',
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
            $data = CustomerLegacyAdapter::enrichRows($customerModel, $data);
            $summary = biomieGeoBuyerBuildSummary($data);

            Http::json([
                'ok'   => true,
                'data' => $data,
                'meta' => [
                    'source_mode'    => 'mart_compradores_por_geo',
                    'count'          => count($data),
                    'territory_type' => $ttype,
                    'document_type_filter' => $documentTypeFilter,
                    'summary'        => $summary,
                    'customer_source_contract' => 'customer.profile.v1',
                    'customer_read_model' => 'CustomerReadModel',
                    'cnae_filter'    => [
                        'code' => (string) ($f['cnae'] ?? ''),
                        'description' => '',
                    ],
                ],
            ]);
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
    }

    // Detectar colunas opcionais
    $hasVendedor = biomieGeoBuyerColumnExists($pdo, $nfTable, 'nome_vendedor');
    $hasCnaeNf   = biomieGeoBuyerColumnExists($pdo, $nfTable, 'cnae_codigo');
    $hasCnaeGeo  = biomieGeoBuyerColumnExists($pdo, $geoTable, 'cnae_codigo');
    $clientDimJoinFallback = '';
    $nfDocExpr = biomieGeoBuyerColumnExists($pdo, $nfTable, 'documento_cliente') ? "NULLIF(TRIM(nf.documento_cliente), '')" : 'NULL';
    $documentExprFallback = $nfDocExpr;
    $nfCnaeExprFallback = $hasCnaeNf ? "NULLIF(TRIM(nf.cnae_codigo), '')" : 'NULL';
    $geoCnaeExprFallback = $hasCnaeGeo ? "NULLIF(TRIM(geo.cnae_codigo), '')" : 'NULL';
    $rawCnaeExprFallback = "COALESCE({$nfCnaeExprFallback}, {$geoCnaeExprFallback})";
    $hasValorNfe = biomieGeoBuyerColumnExists($pdo, $nfTable, 'valor_total') ? 'valor_total'
                 : (biomieGeoBuyerColumnExists($pdo, $nfTable, 'valor_nfe') ? 'valor_nfe' : null);
    if (!$hasValorNfe) {
        Http::json([
            'ok'   => true,
            'data' => [],
            'meta' => ['source_mode' => 'no_value_col', 'count' => 0, 'territory_type' => $ttype],
            'empty_reason' => 'Coluna de valor não encontrada',
        ]);
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
        $martWhere  = [
            'competencia BETWEEN :mc_s AND :mc_e',
            \BIomie\Support\TenantContext::sqlFilter(),
        ];
        $martParams = [':mc_s' => $startComp, ':mc_e' => $endComp];
        if ($isNoGeo) {
            $martWhere[] = "(cidade_norm IS NULL OR TRIM(cidade_norm) = '' OR bairro_norm IS NULL OR TRIM(bairro_norm) = '' OR LOWER(TRIM(bairro_norm)) IN ('nao informado', 'sem bairro', 'sem_bairro'))";
            if ($filterCity !== '') {
                $martWhere[] = GeoLocationScope::missingNeighborhoodInCityPredicate('mart_compradores_por_geo', ':mc_filter_city');
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
    $where  = [
        "nf.status_fiscal = 'autorizada'",
        \BIomie\Support\TenantContext::sqlFilter('nf'),
    ];
    $params = [
        ':date_from'   => $f['date_from'],
        ':date_to'     => $f['date_to'],
    ];

    // Filtro de data
    $where[] = 'DATE(COALESCE(nf.data_competencia, nf.data_emissao)) BETWEEN :date_from AND :date_to';

    if ($useMartLookup) {
        // GEO.R1.9.5 — Use mart-derived client list: no mandatory geo JOIN required
        $codePlaceholders = [];
        foreach (array_values($martClientCodes) as $idx => $code) {
            $placeholder = ':mart_code_' . $idx;
            $codePlaceholders[] = $placeholder;
            $params[$placeholder] = $code;
        }
        $where[] = 'nf.codigo_cliente_omie IN (' . implode(',', $codePlaceholders) . ')';
    } else {
        // Filtro territorial via JOIN com geo_dim (original path)
        if ($isNoGeo) {
            $where[] = "(geo.cidade_norm IS NULL OR TRIM(geo.cidade_norm) = '' OR geo.bairro_norm IS NULL OR TRIM(geo.bairro_norm) = '' OR LOWER(TRIM(geo.bairro_norm)) IN ('nao informado', 'sem bairro', 'sem_bairro'))";
            if ($filterCity !== '') {
                $where[] = GeoLocationScope::missingNeighborhoodInCityPredicate('geo', ':filter_city');
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

    if ($documentTypeFilter !== 'all') {
        $documentDigitsExpr = "REGEXP_REPLACE(COALESCE({$documentExprFallback}, ''), '[^0-9]', '')";
        $where[] = $documentTypeFilter === 'cnpj'
            ? "CHAR_LENGTH({$documentDigitsExpr}) = 14"
            : "CHAR_LENGTH({$documentDigitsExpr}) = 11";
    }

    // Filtro CNAE
    if (!empty($_GET['cnae'])) {
        if (strtoupper(trim((string) $_GET['cnae'])) === 'SEM_CNAE') {
            $where[] = "({$rawCnaeExprFallback} IS NULL OR TRIM({$rawCnaeExprFallback}) = '' OR UPPER(TRIM({$rawCnaeExprFallback})) = 'SEM_CNAE')";
        } else {
            $cnaeNorm = preg_replace('/\D+/', '', (string) $_GET['cnae']);
            $cnaeWhere = ["{$rawCnaeExprFallback} = :cnae_code"];
            $params[':cnae_code'] = $_GET['cnae'];
            if ($cnaeNorm !== '') {
                $cnaeWhere[] = "REGEXP_REPLACE({$rawCnaeExprFallback}, '[^0-9]', '') = :cnae_norm";
                $params[':cnae_norm'] = $cnaeNorm;
            }
            $where[] = '(' . implode(' OR ', $cnaeWhere) . ')';
        }
    }

    $whereSql    = implode(' AND ', $where);
    $geoJoinType = $useMartLookup ? 'LEFT' : 'INNER';

    // Coluna CNAE — prefere NF, depois geo_dim
    $cnaeCol = "MAX({$rawCnaeExprFallback})";

    // ── Compradores agrupados por cliente ─────────────────────────────────────
    $stmtBuyers = $pdo->prepare("
        SELECT
            MAX(nf.nome_cliente)                                                            AS customer_name,
            MAX({$documentExprFallback})                                                    AS document_raw,
            COALESCE(SUM(nf.{$hasValorNfe}), 0)                                             AS revenue,
            COUNT(DISTINCT nf.id)                                                           AS invoices,
            MAX(DATE(COALESCE(nf.data_competencia, nf.data_emissao)))                       AS last_purchase,
            {$cnaeCol}                                                                      AS cnae,
            nf.codigo_cliente_omie,
            MAX(geo.cidade_norm)                                                            AS cidade_norm,
            MAX(geo.bairro_norm)                                                            AS bairro_norm
        FROM {$nfTable} nf
        {$geoJoinType} JOIN {$geoTable} geo
          ON geo.company_id = nf.company_id
         AND geo.codigo_cliente_omie = nf.codigo_cliente_omie
        {$clientDimJoinFallback}
        WHERE {$whereSql} {$visScope}
        GROUP BY nf.codigo_cliente_omie
        ORDER BY revenue DESC
        LIMIT :lim
    ");

    foreach ($params as $k => $v) $stmtBuyers->bindValue($k, $v);
    $stmtBuyers->bindValue(':lim', $limit, \PDO::PARAM_INT);
    $stmtBuyers->execute();
    $buyerRows = $stmtBuyers->fetchAll(\PDO::FETCH_ASSOC);

    $data = array_map(function ($row) use ($canViewDocs, $maskFn) {
        $doc      = (string) ($row['document_raw'] ?? '');
        $revenue  = (float)  ($row['revenue']      ?? 0);
        $invoices = (int)    ($row['invoices']      ?? 0);
        $ticket   = $invoices > 0 ? $revenue / $invoices : 0.0;
        $documentType = biomieGeoBuyerDocumentType($doc);
        $documentMasked = biomieGeoBuyerMaskDocument($doc, $canViewDocs, $maskFn);

        return [
            'customer_name'  => $row['customer_name'] ?? '',
            'document_raw'   => $documentMasked,
            'document_masked'=> $documentMasked,
            'document_type'  => $documentType,
            'document_label' => biomieGeoBuyerDocumentLabel($documentType),
            'buyer_kind'     => $documentType === 'cnpj' ? 'b2b' : ($documentType === 'cpf' ? 'cpf' : 'unknown'),
            'is_b2b'         => $documentType === 'cnpj',
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
    $data = CustomerLegacyAdapter::enrichRows($customerModel, $data);
    $summary = biomieGeoBuyerBuildSummary($data);

    $sourceMode = $useMartLookup ? 'geo_territory_mart' : 'geo_territory_join';

    Http::json([
        'ok'   => true,
        'data' => $data,
        'meta' => [
            'source_mode'    => $sourceMode,
            'count'          => count($data),
            'territory_type' => $ttype,
            'document_type_filter' => $documentTypeFilter,
            'summary'        => $summary,
            'customer_source_contract' => 'customer.profile.v1',
            'customer_read_model' => 'CustomerReadModel',
            'cnae_filter'    => [
                'code' => (string) ($_GET['cnae'] ?? ''),
                'description' => '',
            ],
        ],
    ]);

} catch (\Throwable $e) {
    Http::json([
        'ok' => false,
        'error' => 'geo_territory_buyers_error',
        'message' => 'Nao foi possivel carregar compradores do territorio.',
        'request_id' => RequestContext::id(),
    ], 500);
}

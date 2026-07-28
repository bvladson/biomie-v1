<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Database;
use BIomie\DataPlatform\ReadModel\CustomerReadModelFactory;
use BIomie\DataPlatform\Support\CustomerLegacyAdapter;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\Support\DocumentMask;
use BIomie\Support\Geo\GeoKeyNormalizer;
use BIomie\Support\GeoMapSupport;
use BIomie\Support\GeoLocationScope;
use BIomie\Support\MapFilterSupport;

require __DIR__ . '/bootstrap.php';

/** @var \BIomie\Config $config */
Auth::requireModule('geo', true);

try {
    $filters      = MapFilterSupport::fromRequest($_GET);
    $cityNorm     = $filters['city'];
    $neighborNorm = $filters['neighborhood'];
    $cnae         = $filters['cnae'];
    $limit        = (int) (Http::query('limit', '10') ?? 10);
    if ($limit < 1 || $limit > 100) {
        $limit = 10;
    }

    // GEO.R1.9.4 — composite mode doesn't send city= so fall back to cidade_key param
    if ($cityNorm === null) {
        $ck = strtolower(trim((string) ($_GET['cidade_key'] ?? '')));
        if ($ck !== '' && $ck !== 'sem_cidade') $cityNorm = $ck;
    }

    $connectionData = Database::getGeoConnection($config);
    $pdo = $connectionData[0];
    $customerModel = CustomerReadModelFactory::make($config);

    if (!Http::tableExists($pdo, 'mart_compradores_por_geo')) {
        Http::jsonConflictMissingTable('mart_compradores_por_geo');
    }

    // Accept bairro_key (underscore, no accent) or bairro_norm (space-based) from JS.
    // Priority: bairro_key param > bairro_norm param > neighborNorm from filter.
    $rawBairroKey  = trim((string) ($_GET['bairro_key'] ?? ''));
    $rawBairroNorm = trim((string) ($_GET['bairro_norm'] ?? ''));
    // Normalize whatever we received to a stable underscore key.
    $resolvedKey = $rawBairroKey !== ''
        ? GeoKeyNormalizer::makeNeighborhoodKey($rawBairroKey)
        : ($rawBairroNorm !== ''
            ? GeoKeyNormalizer::makeNeighborhoodKey($rawBairroNorm)
            : ($neighborNorm !== null ? GeoKeyNormalizer::normToKey($neighborNorm) : null));

    // City key for bairro_key column filter.
    $resolvedCityKey = $cityNorm !== null ? GeoKeyNormalizer::normToKey($cityNorm) : null;

    $hasBairroKeyCol = geoColExists($pdo, 'mart_compradores_por_geo', 'bairro_key');
    $hasCidadeKeyCol = geoColExists($pdo, 'mart_compradores_por_geo', 'cidade_key');

    // ── Sem bairro sentinel ───────────────────────────────────────────────────
    // bairro_key=__sem_bairro__ shows buyers with no resolved neighborhood (geo_unclassified_or_outside_current_mesh).
    // Possible causes: client is outside the Fortaleza bairros mesh, bairro was not propagated
    // to the mart (builder bug), or local geo cache is incomplete. Use geo_holistic_audit to classify.
    if ($rawBairroKey === '__sem_bairro__') {
        $nbWhere  = [
            'competencia BETWEEN :start_comp AND :end_comp',
            \BIomie\Support\CommercialMartScope::condition($pdo, 'mart_compradores_por_geo'),
        ];
        $nbParams = ['start_comp' => $filters['start_comp'], 'end_comp' => $filters['end_comp']];
        if ($cityNorm !== null) {
            if ($hasCidadeKeyCol && $resolvedCityKey !== null) {
                $nbWhere[]             = "(cidade_key = :city_key OR (cidade_key IS NULL AND LOWER(TRIM(cidade_norm)) = :city_norm))";
                $nbParams['city_key']  = $resolvedCityKey;
                $nbParams['city_norm'] = $cityNorm;
            } else {
                $nbWhere[]      = GeoLocationScope::cityPredicate('mart_compradores_por_geo', ':city');
                $nbParams['city'] = $cityNorm;
            }
        }
        $nbWhere[] = "(bairro_norm IS NULL OR TRIM(bairro_norm) = '' OR bairro_norm = 'NAO INFORMADO')";

        $nbSql = '
            SELECT
                MAX(codigo_cliente_omie) AS codigo_cliente_omie,
                MAX(cliente_nome) AS customer_name,
                MAX(documento) AS document_raw,
                NULL AS bairro_norm,
                MAX(cidade_norm) AS cidade_norm,
                SUM(faturamento) AS revenue,
                SUM(qtd_notas) AS invoices,
                IF(SUM(qtd_notas) > 0, SUM(faturamento) / SUM(qtd_notas), 0) AS average_ticket,
                MAX(ultima_compra) AS last_purchase,
                MAX(cnae_codigo) AS cnae,
                MAX(segmento_macro) AS segment
            FROM mart_compradores_por_geo
            WHERE ' . implode(' AND ', $nbWhere) . '
            GROUP BY COALESCE(CAST(codigo_cliente_omie AS CHAR), documento, cliente_nome)
            ORDER BY revenue DESC
            LIMIT :limit
        ';
        $nbStmt = $pdo->prepare($nbSql);
        foreach ($nbParams as $k => $v) {
            $nbStmt->bindValue(':' . $k, $v);
        }
        $nbStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $nbStmt->execute();

        $semBairroData = [];
        foreach ($nbStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $documentRaw    = is_string($row['document_raw'] ?? null) ? $row['document_raw'] : '';
            $documentNorm   = topBuyersDocumentDigits($documentRaw);
            $documentMasked = DocumentMask::maskDocument(
                $documentRaw !== '' ? $documentRaw : null, 'Nao informado'
            ) ?? 'Nao informado';
            $revenue        = (float) ($row['revenue'] ?? 0);
            $invoices       = (int) ($row['invoices'] ?? 0);
            $averageTicket  = (float) ($row['average_ticket'] ?? 0);
            $customerName   = (string) ($row['customer_name'] ?? 'Sem nome');
            $codigoCliente  = (string) ($row['codigo_cliente_omie'] ?? '');
            $semBairroData[] = [
                'customer_name'       => $customerName,
                'document_masked'     => $documentMasked,
                'codigo_cliente_omie' => $codigoCliente,
                'cliente_nome'        => $customerName,
                'cliente_documento'   => $documentMasked,
                'documento_norm'      => '',
                'bairro_norm'         => '',
                'cidade_norm'         => (string) ($row['cidade_norm'] ?? ''),
                'revenue'             => $revenue,
                'invoices'            => $invoices,
                'average_ticket'      => $averageTicket,
                'last_purchase'       => $row['last_purchase'] ?? null,
                'ultima_compra'       => $row['last_purchase'] ?? null,
                'vendedor_principal'  => '',
                'top_nf'              => '',
                'cnae'                => $row['cnae'] ?? null,
                'segment'             => $row['segment'] ?? null,
                'cliente'             => $customerName,
                'documento_mascarado' => $documentMasked,
                'faturamento'         => $revenue,
                'notas'               => $invoices,
                'qtd_notas'           => $invoices,
                'ticket_medio'        => $averageTicket,
                'cnae_codigo'         => $row['cnae'] ?? null,
                'segmento_macro'      => $row['segment'] ?? null,
            ];
        }

        $semBairroData = CustomerLegacyAdapter::enrichRows($customerModel, $semBairroData);

        Http::json([
            'ok'   => true,
            'data' => $semBairroData,
            'no_neighborhood_context' => [
                'type'    => 'omie_data_quality',
                'title'   => 'Bairro nao informado na origem',
                'message' => 'Clientes sem bairro cadastrado no Omie. Atualize o cadastro/endereco no Omie para que aparecam no mapa.',
                'action'  => 'Atualizar cadastro no Omie',
            ],
            'filters' => [
                'date_from'    => $filters['date_from'],
                'date_to'      => $filters['date_to'],
                'city'         => $cityNorm,
                'neighborhood' => $neighborNorm,
                'bairro_key'   => '__sem_bairro__',
                'cnae'         => $cnae,
                'seller_id'    => $filters['seller_id'],
            ],
            'meta' => [
                'request_id'   => RequestContext::id(),
                'generated_at' => date(DATE_ATOM),
                'source_table' => 'mart_compradores_por_geo',
                'source_mode'  => 'no_neighborhood',
                'customer_source_contract' => 'customer.profile.v1',
                'customer_read_model' => 'CustomerReadModel',
                'count'        => count($semBairroData),
            ],
        ]);
    }

    $where = [
        'competencia BETWEEN :start_comp AND :end_comp',
        \BIomie\Support\CommercialMartScope::condition($pdo, 'mart_compradores_por_geo'),
    ];
    $params = [
        'start_comp' => $filters['start_comp'],
        'end_comp'   => $filters['end_comp'],
    ];

    if ($cityNorm !== null) {
        if ($hasCidadeKeyCol && $resolvedCityKey !== null) {
            $where[] = "(cidade_key = :city_key OR (cidade_key IS NULL AND LOWER(TRIM(cidade_norm)) = :city_norm))";
            $params['city_key']  = $resolvedCityKey;
            $params['city_norm'] = $cityNorm;
        } else {
            $where[] = GeoLocationScope::cityPredicate('mart_compradores_por_geo', ':city');
            $params['city'] = $cityNorm;
        }
    }

    if ($resolvedKey !== null && $resolvedKey !== '') {
        if ($hasBairroKeyCol) {
            // Use bairro_key column when available; COLLATE fallback for unbackfilled rows.
            $where[] = '(bairro_key = :bairro_key OR (bairro_key IS NULL AND bairro_norm COLLATE utf8mb4_general_ci = :bairro_key_fb))';
            $params['bairro_key']    = $resolvedKey;
            $params['bairro_key_fb'] = $resolvedKey;
        } else {
            // Legacy: accent+case-insensitive COLLATE comparison on bairro_norm.
            $where[] = 'bairro_norm COLLATE utf8mb4_general_ci = :bairro_key';
            $params['bairro_key'] = $resolvedKey;
        }
    }

    if ($cnae !== null) {
        if (strtoupper($cnae) === 'SEM_CNAE') {
            $where[] = "(cnae_codigo IS NULL OR TRIM(cnae_codigo) = '')";
        } else {
            $where[] = 'cnae_codigo = :cnae';
            $params['cnae'] = $cnae;
        }
    }

    $sql = '
        SELECT
            MAX(codigo_cliente_omie) AS codigo_cliente_omie,
            MAX(cliente_nome) AS customer_name,
            MAX(documento) AS document_raw,
            MAX(bairro_norm) AS bairro_norm,
            MAX(cidade_norm) AS cidade_norm,
            SUM(faturamento) AS revenue,
            SUM(qtd_notas) AS invoices,
            IF(SUM(qtd_notas) > 0, SUM(faturamento) / SUM(qtd_notas), 0) AS average_ticket,
            MAX(ultima_compra) AS last_purchase,
            MAX(cnae_codigo) AS cnae,
            MAX(segmento_macro) AS segment
        FROM mart_compradores_por_geo
        WHERE ' . implode(' AND ', $where) . '
        GROUP BY COALESCE(CAST(codigo_cliente_omie AS CHAR), documento, cliente_nome)
        ORDER BY revenue DESC
        LIMIT :limit
    ';

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $data = [];
    $sourceMode = 'gold_mart';
    $canProbeInvoices = Http::tableExists($pdo, 'biomie_notas_fiscais_canonicas');
    $canProbeGeo = Http::tableExists($pdo, 'biomie_clientes_geo_dim');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $documentRaw = is_string($row['document_raw'] ?? null) ? $row['document_raw'] : '';
        $documentNorm = topBuyersDocumentDigits($documentRaw);
        $documentMasked = DocumentMask::maskDocument(
            $documentRaw !== '' ? $documentRaw : null,
            'Nao informado'
        ) ?? 'Nao informado';
        $revenue       = (float) ($row['revenue'] ?? 0);
        $invoices      = (int) ($row['invoices'] ?? 0);
        $averageTicket = (float) ($row['average_ticket'] ?? 0);
        $customerName  = (string) ($row['customer_name'] ?? 'Sem nome');
        $codigoCliente = (string) ($row['codigo_cliente_omie'] ?? '');
        $latestInvoice = $canProbeInvoices
            ? topBuyersLatestInvoice($pdo, $codigoCliente, $documentNorm, $customerName, $filters['date_from'], $filters['date_to'])
            : ['vendedor_principal' => '', 'top_nf' => ''];

        $data[] = [
            'customer_name'       => $customerName,
            'document_masked'     => $documentMasked,
            'codigo_cliente_omie' => $codigoCliente,
            'cliente_nome'        => $customerName,
            'cliente_documento'   => $documentMasked,
            'documento_norm'      => '',
            'bairro_norm'         => (string) ($row['bairro_norm'] ?? ''),
            'cidade_norm'         => (string) ($row['cidade_norm'] ?? ''),
            'revenue'             => $revenue,
            'invoices'            => $invoices,
            'average_ticket'      => $averageTicket,
            'last_purchase'       => $row['last_purchase'] ?? null,
            'ultima_compra'       => $row['last_purchase'] ?? null,
            'vendedor_principal'  => $latestInvoice['vendedor_principal'],
            'top_nf'              => $latestInvoice['top_nf'],
            'cnae'                => $row['cnae'] ?? null,
            'segment'             => $row['segment'] ?? null,
            'cliente'             => $customerName,
            'documento_mascarado' => $documentMasked,
            'faturamento'         => $revenue,
            'notas'               => $invoices,
            'qtd_notas'           => $invoices,
            'ticket_medio'        => $averageTicket,
            'cnae_codigo'         => $row['cnae'] ?? null,
            'segmento_macro'      => $row['segment'] ?? null,
        ];
    }

    // Canonical fallback: when mart is empty for a specific bairro, query NFs directly.
    if ($data === [] && $resolvedKey !== null && $resolvedKey !== '' && $canProbeInvoices && $canProbeGeo) {
        $canonicalRaws = topBuyersFromCanonical($pdo, $resolvedKey, $filters['date_from'], $filters['date_to'], $limit);
        if ($canonicalRaws !== []) {
            $sourceMode = 'canonical_fallback';
            foreach ($canonicalRaws as $row) {
                $documentRaw   = is_string($row['document_raw'] ?? null) ? $row['document_raw'] : '';
                $documentNorm  = topBuyersDocumentDigits($documentRaw);
                $documentMasked = DocumentMask::maskDocument(
                    $documentRaw !== '' ? $documentRaw : null,
                    'Nao informado'
                ) ?? 'Nao informado';
                $revenue       = (float) ($row['revenue'] ?? 0);
                $invoices      = (int) ($row['invoices'] ?? 0);
                $averageTicket = (float) ($row['average_ticket'] ?? 0);
                $customerName  = (string) ($row['customer_name'] ?? 'Sem nome');
                $codigoCliente = (string) ($row['codigo_cliente_omie'] ?? '');
                $data[] = [
                    'customer_name'       => $customerName,
                    'document_masked'     => $documentMasked,
                    'codigo_cliente_omie' => $codigoCliente,
                    'cliente_nome'        => $customerName,
                    'cliente_documento'   => $documentMasked,
                    'documento_norm'      => '',
                    'bairro_norm'         => (string) ($row['bairro_norm'] ?? ''),
                    'cidade_norm'         => (string) ($row['cidade_norm'] ?? ''),
                    'revenue'             => $revenue,
                    'invoices'            => $invoices,
                    'average_ticket'      => $averageTicket,
                    'last_purchase'       => $row['last_purchase'] ?? null,
                    'ultima_compra'       => $row['last_purchase'] ?? null,
                    'vendedor_principal'  => '',
                    'top_nf'              => '',
                    'cnae'                => null,
                    'segment'             => null,
                    'cliente'             => $customerName,
                    'documento_mascarado' => $documentMasked,
                    'faturamento'         => $revenue,
                    'notas'               => $invoices,
                    'qtd_notas'           => $invoices,
                    'ticket_medio'        => $averageTicket,
                    'cnae_codigo'         => null,
                    'segmento_macro'      => null,
                ];
            }
        }
    }

    $payload = [
        'ok'      => true,
        'filters' => [
            'date_from'    => $filters['date_from'],
            'date_to'      => $filters['date_to'],
            'city'         => $cityNorm,
            'neighborhood' => $neighborNorm,
            'bairro_key'   => $resolvedKey,
            'cnae'         => $cnae,
            'seller_id'    => $filters['seller_id'],
        ],
        'data' => $data,
        'meta' => [
            'request_id'   => RequestContext::id(),
            'generated_at' => date(DATE_ATOM),
            'source_table' => $sourceMode === 'canonical_fallback' ? 'biomie_notas_fiscais_canonicas' : 'mart_compradores_por_geo',
            'source_mode'  => $sourceMode,
            'count'        => count($data),
        ],
    ];

    if ($data === []) {
        $payload['empty_state'] = GeoMapSupport::emptyState();
    }

    $payload['data'] = CustomerLegacyAdapter::enrichRows($customerModel, (array) $payload['data']);
    $payload['meta']['customer_source_contract'] = 'customer.profile.v1';
    $payload['meta']['customer_read_model'] = 'CustomerReadModel';

    Http::json($payload);
} catch (\Throwable $exception) {
    global $logger;
    $logger?->error('top_buyers_by_geo api error', ['exception' => $exception->getMessage()]);

    Http::json([
        'ok'         => false,
        'error'      => 'api_error',
        'message'    => 'Nao foi possivel carregar os principais compradores agora.',
        'request_id' => RequestContext::id(),
    ], 500);
}

function geoColExists(PDO $pdo, string $table, string $col): bool
{
    try {
        return (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND COLUMN_NAME = '{$col}'"
        )->fetchColumn() > 0;
    } catch (\Throwable) {
        return false;
    }
}

function topBuyersDocumentDigits(string $document): string
{
    $digits = preg_replace('/\D+/', '', $document) ?? '';
    return in_array(strlen($digits), [11, 14], true) ? $digits : '';
}

/**
 * Fallback: query buyers directly from NFs + geo_dim when mart is empty.
 *
 * @return list<array<string,mixed>>
 */
function topBuyersFromCanonical(PDO $pdo, string $bairroKey, string $dateFrom, string $dateTo, int $limit): array
{
    try {
        $tenantFilter = \BIomie\Support\TenantContext::sqlFilter('nf');
        $sql = "
            SELECT
                MAX(nf.codigo_cliente_omie) AS codigo_cliente_omie,
                MAX(nf.nome_cliente)        AS customer_name,
                MAX(nf.documento_cliente)   AS document_raw,
                MAX(geo.bairro_norm)        AS bairro_norm,
                MAX(geo.cidade_norm)        AS cidade_norm,
                SUM(nf.valor_total)         AS revenue,
                COUNT(nf.id)                AS invoices,
                IF(COUNT(nf.id) > 0, SUM(nf.valor_total) / COUNT(nf.id), 0) AS average_ticket,
                MAX(nf.data_emissao)        AS last_purchase
            FROM biomie_notas_fiscais_canonicas nf
            INNER JOIN biomie_clientes_geo_dim geo
              ON geo.company_id = nf.company_id
             AND geo.codigo_cliente_omie = nf.codigo_cliente_omie
            WHERE nf.status_fiscal = 'autorizada'
              AND {$tenantFilter}
              AND DATE(COALESCE(nf.data_competencia, nf.data_emissao)) BETWEEN :date_from AND :date_to
              AND geo.bairro_norm COLLATE utf8mb4_general_ci = :bairro_key
            GROUP BY COALESCE(CAST(nf.codigo_cliente_omie AS CHAR), nf.documento_cliente, nf.nome_cliente)
            ORDER BY revenue DESC
            LIMIT :limit
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':date_from', $dateFrom);
        $stmt->bindValue(':date_to', $dateTo);
        $stmt->bindValue(':bairro_key', $bairroKey);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable) {
        return [];
    }
}

/**
 * @return array{vendedor_principal:string,top_nf:string}
 */
function topBuyersLatestInvoice(PDO $pdo, string $codigoCliente, string $documentNorm, string $customerName, string $start, string $end): array
{
    try {
        $tenantFilter = \BIomie\Support\TenantContext::sqlFilter('n');
        $where = [$tenantFilter, "n.status_fiscal = 'autorizada'", 'COALESCE(n.data_competencia, n.data_emissao) BETWEEN :start AND :end'];
        $params = ['start' => $start, 'end' => $end];

        if ($codigoCliente !== '') {
            $where[] = 'n.codigo_cliente_omie = :codigo_cliente';
            $params['codigo_cliente'] = $codigoCliente;
        } elseif ($documentNorm !== '') {
            $where[] = "REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(n.documento_cliente, ''), '.', ''), '-', ''), '/', ''), ' ', '') = :documento";
            $params['documento'] = $documentNorm;
        } elseif (trim($customerName) !== '') {
            $where[] = 'LOWER(TRIM(n.nome_cliente)) = LOWER(TRIM(:cliente_nome))';
            $params['cliente_nome'] = $customerName;
        } else {
            return ['vendedor_principal' => '', 'top_nf' => ''];
        }

        $stmt = $pdo->prepare(
            'SELECT n.numero_nf, n.serie_nf, n.nome_vendedor
             FROM biomie_notas_fiscais_canonicas n
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY COALESCE(n.data_competencia, n.data_emissao) DESC, n.numero_nf DESC
             LIMIT 1'
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $nf = (string) ($row['numero_nf'] ?? '');
        $serie = (string) ($row['serie_nf'] ?? '');
        if ($nf !== '' && $serie !== '') {
            $nf .= '/' . $serie;
        }

        return [
            'vendedor_principal' => (string) ($row['nome_vendedor'] ?? ''),
            'top_nf' => $nf,
        ];
    } catch (Throwable) {
        return ['vendedor_principal' => '', 'top_nf' => ''];
    }
}

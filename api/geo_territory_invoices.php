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
use BIomie\Http;
use BIomie\Support\BiVisibilityScope;
use BIomie\Support\DocumentMask;
use BIomie\Support\MapFilterSupport;

require __DIR__ . '/bootstrap.php';

Auth::requireModule('geo', true);

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

    // ── Filtros WHERE ────────────────────────────────────────────────────────
    $where  = ["nf.status_fiscal = 'autorizada'"];
    $params = [
        ':date_from' => $f['date_from'],
        ':date_to'   => $f['date_to'],
    ];

    // Filtro de data
    $where[] = 'DATE(COALESCE(nf.data_competencia, nf.data_emissao)) BETWEEN :date_from AND :date_to';

    // Filtro territorial via JOIN com geo_dim
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

    // ── Resumo ────────────────────────────────────────────────────────────────
    $stmtSum = $pdo->prepare("
        SELECT
            COUNT(DISTINCT nf.id)                   AS notas,
            COALESCE(SUM(nf.{$hasValorNfe}), 0)     AS valor_total,
            COUNT(DISTINCT COALESCE(CAST(nf.codigo_cliente_omie AS CHAR), nf.documento_cliente, nf.nome_cliente)) AS clientes
        FROM {$nfTable} nf
        INNER JOIN {$geoTable} geo ON geo.codigo_cliente_omie = nf.codigo_cliente_omie
        WHERE {$whereSql} {$visScope}
    ");
    foreach ($params as $k => $v) $stmtSum->bindValue($k, $v);
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
        INNER JOIN {$geoTable} geo ON geo.codigo_cliente_omie = nf.codigo_cliente_omie
        WHERE {$whereSql} {$visScope}
        GROUP BY nf.id
        ORDER BY data_emissao DESC, valor DESC
        LIMIT :lim OFFSET :off
    ");
    foreach ($params as $k => $v) $stmtList->bindValue($k, $v);
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

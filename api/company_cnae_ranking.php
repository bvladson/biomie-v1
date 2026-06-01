<?php

declare(strict_types=1);

/**
 * api/company_cnae_ranking.php
 *
 * GEO.R1.9.1 — Ranking de empresas por CNAE no período.
 *
 * Retorna top clientes agrupados por CNAE e empresa para uso no mapa,
 * index e pedidos.
 *
 * Parâmetros:
 *   date_from, date_to, scope, vendor, city, territory_key
 *   limit (default 20, max 50)
 *   mode = by_cnae|by_company|top_cnaes (default by_cnae)
 */

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\Support\DocumentMask;
use BIomie\Support\MapFilterSupport;

require __DIR__ . '/bootstrap.php';

Auth::requireModule('geo', true);

try {
    $f      = MapFilterSupport::fromRequest($_GET);
    $limit  = min(50, max(1, (int)($_GET['limit'] ?? 20)));
    $mode   = in_array($_GET['mode'] ?? '', ['by_cnae', 'by_company', 'top_cnaes'], true)
            ? ($_GET['mode'] ?? 'by_cnae')
            : 'by_cnae';

    $startComp = substr($f['date_from'], 0, 7);
    $endComp   = substr($f['date_to'],   0, 7);

    $connectionData = Database::getGeoConnection($config);
    $pdo = $connectionData[0];

    $mart = 'mart_compradores_por_geo';

    if (!Http::tableExists($pdo, $mart)) {
        Http::json([
            'ok'      => true,
            'summary' => ['cnaes' => 0, 'empresas' => 0, 'faturamento' => 0],
            'items'   => [],
            'empty_reason' => 'mart_compradores_por_geo não materializado',
        ]);
        return;
    }

    $hasCnae = Http::columnExists($pdo, $mart, 'cnae_codigo');
    if (!$hasCnae) {
        Http::json(['ok' => true, 'summary' => ['cnaes' => 0, 'empresas' => 0, 'faturamento' => 0], 'items' => [], 'empty_reason' => 'cnae_codigo não disponível no mart']);
        return;
    }

    $where  = ['competencia BETWEEN :s AND :e'];
    $params = [':s' => $startComp, ':e' => $endComp];

    // Filtro vendedor
    if (!empty($f['seller_id'])) {
        if (Http::columnExists($pdo, $mart, 'nome_vendedor')) {
            $where[] = 'LOWER(nome_vendedor) LIKE :vendedor';
            $params[':vendedor'] = '%' . mb_strtolower(trim($f['seller_id'])) . '%';
        }
    }

    $whereSql = implode(' AND ', $where);
    $canViewDocs = class_exists('BIomie\\Auth') && method_exists('BIomie\\Auth', 'canViewFullDocuments')
        ? \BIomie\Auth::canViewFullDocuments()
        : false;
    $maskFn = class_exists('BIomie\\Support\\DocumentMask') ? ['\BIomie\Support\DocumentMask', 'mask'] : null;

    if ($mode === 'top_cnaes') {
        // Apenas CNAEs agregados (sem empresa)
        $stmt = $pdo->prepare("
            SELECT
                COALESCE(NULLIF(TRIM(cnae_codigo),''),'SEM_CNAE')        AS cnae_codigo,
                COALESCE(NULLIF(TRIM(cnae_descricao),''),'CNAE não informado') AS cnae_descricao,
                COUNT(DISTINCT COALESCE(CAST(codigo_cliente_omie AS CHAR), documento, cliente_nome)) AS empresas,
                SUM(faturamento)  AS faturamento,
                SUM(qtd_notas)    AS notas
            FROM {$mart}
            WHERE {$whereSql}
            GROUP BY cnae_codigo, cnae_descricao
            ORDER BY faturamento DESC
            LIMIT :lim
        ");
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $items = array_map(fn($r) => [
            'cnae_codigo'   => $r['cnae_codigo'],
            'cnae_descricao'=> $r['cnae_descricao'],
            'empresas'      => (int)$r['empresas'],
            'faturamento'   => (float)$r['faturamento'],
            'notas'         => (int)$r['notas'],
        ], $rows);

    } else {
        // by_cnae or by_company — empresa + CNAE
        $stmt = $pdo->prepare("
            SELECT
                COALESCE(NULLIF(TRIM(cnae_codigo),''),'SEM_CNAE')             AS cnae_codigo,
                COALESCE(NULLIF(TRIM(cnae_descricao),''),'CNAE não informado') AS cnae_descricao,
                COALESCE(NULLIF(TRIM(segmento_macro),''),'Outros')             AS segmento_macro,
                COALESCE(cliente_nome,'Sem nome')  AS cliente_nome,
                COALESCE(documento,'')             AS documento,
                LOWER(TRIM(COALESCE(cidade_norm,''))) AS cidade_key,
                SUM(faturamento)   AS faturamento,
                SUM(qtd_notas)     AS notas,
                MAX(ultima_compra) AS ultima_compra
            FROM {$mart}
            WHERE {$whereSql}
            GROUP BY cnae_codigo, cnae_descricao, segmento_macro, cliente_nome, documento, cidade_norm
            ORDER BY faturamento DESC
            LIMIT :lim
        ");
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $items = array_map(function($r) use ($canViewDocs, $maskFn) {
            $doc = (string)($r['documento'] ?? '');
            return [
                'cnae_codigo'   => $r['cnae_codigo'],
                'cnae_descricao'=> $r['cnae_descricao'],
                'segmento_macro'=> $r['segmento_macro'],
                'cliente_nome'  => $r['cliente_nome'],
                'documento'     => $canViewDocs ? $doc : ($maskFn ? call_user_func($maskFn, $doc) : substr($doc, 0, 3) . '***'),
                'cidade_key'    => $r['cidade_key'],
                'faturamento'   => (float)$r['faturamento'],
                'notas'         => (int)$r['notas'],
                'ultima_compra' => $r['ultima_compra'],
            ];
        }, $rows);
    }

    // Summary
    $stmtSum = $pdo->prepare("
        SELECT
            COUNT(DISTINCT NULLIF(TRIM(COALESCE(cnae_codigo,'')),'')),
            COUNT(DISTINCT COALESCE(CAST(codigo_cliente_omie AS CHAR), documento, cliente_nome)),
            COALESCE(SUM(faturamento), 0)
        FROM {$mart}
        WHERE {$whereSql}
    ");
    foreach ($params as $k => $v) $stmtSum->bindValue($k, $v);
    $stmtSum->execute();
    [$cnaes, $empresas, $totalFat] = $stmtSum->fetch(\PDO::FETCH_NUM) ?: [0, 0, 0];

    Http::json([
        'ok'      => true,
        'mode'    => $mode,
        'summary' => ['cnaes' => (int)$cnaes, 'empresas' => (int)$empresas, 'faturamento' => (float)$totalFat],
        'items'   => $items,
    ]);

} catch (\Throwable $e) {
    Http::jsonError('company_cnae_ranking_error', $e->getMessage());
}

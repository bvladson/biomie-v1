<?php

declare(strict_types=1);

/**
 * api/geo_sales_coverage_audit.php
 *
 * GEO.R1.9 — Auditoria de cobertura geográfica das vendas.
 *
 * Responde: "Tem venda no Ceará que não entrou no mapa?"
 *
 * Compara:
 *   - Total vendido do mart
 *   - Total mapeado (com cidade + bairro reconhecidos)
 *   - Sem bairro (cidade presente, bairro ausente)
 *   - Sem cidade (cidade ausente)
 *   - Cidade não reconhecida (não está na lista de municípios CE)
 *
 * Uso: GET api/geo_sales_coverage_audit.php?date_from=...&date_to=...
 */

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\Support\DocumentMask;
use BIomie\Support\MapFilterSupport;

require __DIR__ . '/bootstrap.php';

Auth::requireModule('geo', true);

try {
    $f     = MapFilterSupport::fromRequest($_GET);
    $start = substr($f['date_from'], 0, 7);
    $end   = substr($f['date_to'],   0, 7);

    $connectionData = Database::getGeoConnection($config);
    $pdo = $connectionData[0];

    if (!Http::tableExists($pdo, 'mart_compradores_por_geo')) {
        Http::json([
            'ok' => true,
            'totals'  => ['commercial_total' => 0, 'mapped_total' => 0, 'unmapped_total' => 0, 'coverage_pct' => 0],
            'buckets' => [],
            'empty_reason' => 'mart_compradores_por_geo não materializado',
        ]);
        return;
    }

    $baseWhere  = 'competencia BETWEEN :s AND :e AND '
        . \BIomie\Support\CommercialMartScope::condition($pdo, 'mart_compradores_por_geo');
    $baseParams = [':s' => $start, ':e' => $end];

    // ── Totais agregados ──────────────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(faturamento), 0)  AS total_revenue,
            COALESCE(SUM(qtd_notas), 0)    AS total_invoices,
            COUNT(DISTINCT COALESCE(CAST(codigo_cliente_omie AS CHAR), documento, cliente_nome)) AS total_customers,
            -- Mapeado: tem cidade e bairro
            COALESCE(SUM(CASE WHEN TRIM(COALESCE(cidade_norm,'')) != '' AND TRIM(COALESCE(bairro_norm,'')) != '' THEN faturamento ELSE 0 END), 0) AS mapped_revenue,
            COALESCE(SUM(CASE WHEN TRIM(COALESCE(cidade_norm,'')) != '' AND TRIM(COALESCE(bairro_norm,'')) != '' THEN qtd_notas  ELSE 0 END), 0) AS mapped_invoices,
            -- Sem bairro (cidade existe, bairro não)
            COALESCE(SUM(CASE WHEN TRIM(COALESCE(cidade_norm,'')) != '' AND TRIM(COALESCE(bairro_norm,'')) = ''  THEN faturamento ELSE 0 END), 0) AS sem_bairro_revenue,
            COALESCE(SUM(CASE WHEN TRIM(COALESCE(cidade_norm,'')) != '' AND TRIM(COALESCE(bairro_norm,'')) = ''  THEN qtd_notas  ELSE 0 END), 0) AS sem_bairro_invoices,
            COUNT(DISTINCT CASE WHEN TRIM(COALESCE(cidade_norm,'')) != '' AND TRIM(COALESCE(bairro_norm,'')) = ''
                THEN COALESCE(CAST(codigo_cliente_omie AS CHAR), documento, cliente_nome) END) AS sem_bairro_customers,
            -- Sem cidade
            COALESCE(SUM(CASE WHEN TRIM(COALESCE(cidade_norm,'')) = '' THEN faturamento ELSE 0 END), 0) AS sem_cidade_revenue,
            COALESCE(SUM(CASE WHEN TRIM(COALESCE(cidade_norm,'')) = '' THEN qtd_notas  ELSE 0 END), 0) AS sem_cidade_invoices
        FROM mart_compradores_por_geo
        WHERE {$baseWhere}
    ");
    $stmt->execute($baseParams);
    $totRow = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

    $totalRev    = (float)($totRow['total_revenue']    ?? 0);
    $mappedRev   = (float)($totRow['mapped_revenue']   ?? 0);
    $semBairroRev= (float)($totRow['sem_bairro_revenue']?? 0);
    $semCidadeRev= (float)($totRow['sem_cidade_revenue']?? 0);
    $unmappedRev = $totalRev - $mappedRev;
    $coveragePct = $totalRev > 0 ? round($mappedRev / $totalRev * 100, 1) : 0.0;
    $semBairroPct= $totalRev > 0 ? round($semBairroRev / $totalRev * 100, 1) : 0.0;

    // ── Municípios CE com venda (não apenas bairros) ──────────────────────────
    $stmtMuni = $pdo->prepare("
        SELECT
            LOWER(TRIM(cidade_norm)) AS cidade_key,
            SUM(faturamento)         AS revenue,
            SUM(qtd_notas)           AS invoices,
            COUNT(DISTINCT COALESCE(CAST(codigo_cliente_omie AS CHAR), documento, cliente_nome)) AS customers
        FROM mart_compradores_por_geo
        WHERE {$baseWhere}
          AND TRIM(COALESCE(cidade_norm,'')) != ''
          AND TRIM(COALESCE(bairro_norm,'')) = ''
        GROUP BY cidade_norm
        ORDER BY revenue DESC
        LIMIT 20
    ");
    $stmtMuni->execute($baseParams);
    $muniRows = $stmtMuni->fetchAll(\PDO::FETCH_ASSOC);

    $muniNotMapped = array_map(fn($r) => [
        'cidade_key' => $r['cidade_key'],
        'revenue'    => (float)$r['revenue'],
        'invoices'   => (int)$r['invoices'],
        'customers'  => (int)$r['customers'],
        'action'     => 'Corrija o bairro dos clientes desta cidade no Omie.',
    ], $muniRows);

    // ── Top clientes sem bairro (maior impacto) ───────────────────────────────
    $canViewDocs = class_exists('BIomie\\Auth') && method_exists('BIomie\\Auth', 'canViewFullDocuments')
        ? \BIomie\Auth::canViewFullDocuments()
        : false;

    $stmtTop = $pdo->prepare("
        SELECT
            COALESCE(cliente_nome, 'Sem nome')  AS cliente_nome,
            COALESCE(documento, '')              AS documento,
            LOWER(TRIM(COALESCE(cidade_norm, 'sem_cidade'))) AS cidade_key,
            SUM(faturamento)                     AS revenue,
            SUM(qtd_notas)                       AS invoices,
            MAX(ultima_compra)                   AS ultima_nf
        FROM mart_compradores_por_geo
        WHERE {$baseWhere}
          AND TRIM(COALESCE(bairro_norm, '')) = ''
          AND TRIM(COALESCE(cidade_norm, '')) != ''
        GROUP BY cliente_nome, documento, cidade_norm
        ORDER BY revenue DESC
        LIMIT 15
    ");
    $stmtTop->execute($baseParams);
    $topRows = $stmtTop->fetchAll(\PDO::FETCH_ASSOC);

    $maskFn = class_exists('BIomie\\Support\\DocumentMask') ? ['\BIomie\Support\DocumentMask', 'mask'] : null;
    $topClientes = array_map(function($r) use ($canViewDocs, $maskFn) {
        $doc = (string)($r['documento'] ?? '');
        return [
            'cliente_nome' => $r['cliente_nome'],
            'documento'    => $canViewDocs ? $doc : ($maskFn ? call_user_func($maskFn, $doc) : substr($doc, 0, 3) . '***'),
            'cidade_key'   => $r['cidade_key'],
            'revenue'      => (float)$r['revenue'],
            'invoices'     => (int)$r['invoices'],
            'ultima_nf'    => $r['ultima_nf'],
            'sugestao'     => 'Corrija o CEP/bairro deste cliente no Omie para que apareça no mapa.',
        ];
    }, $topRows);

    // ── Buckets classificados ─────────────────────────────────────────────────
    $buckets = [
        [
            'type'      => 'mapeado',
            'label'     => 'Mapeado no mapa',
            'valor'     => $mappedRev,
            'invoices'  => (int)($totRow['mapped_invoices'] ?? 0),
            'pct'       => $coveragePct,
            'action'    => null,
        ],
        [
            'type'      => 'sem_bairro',
            'label'     => 'Com cidade, sem bairro',
            'valor'     => $semBairroRev,
            'invoices'  => (int)($totRow['sem_bairro_invoices'] ?? 0),
            'customers' => (int)($totRow['sem_bairro_customers'] ?? 0),
            'pct'       => $semBairroPct,
            'action'    => 'Corrija o bairro nos cadastros Omie. Clientes listados abaixo.',
        ],
        [
            'type'      => 'sem_cidade',
            'label'     => 'Sem cidade cadastrada',
            'valor'     => $semCidadeRev,
            'invoices'  => (int)($totRow['sem_cidade_invoices'] ?? 0),
            'pct'       => $totalRev > 0 ? round($semCidadeRev / $totalRev * 100, 1) : 0.0,
            'action'    => 'Verifique clientes sem endereço no Omie.',
        ],
    ];

    Http::json([
        'ok'      => true,
        'period'  => $f['date_from'] . ' → ' . $f['date_to'],
        'totals'  => [
            'commercial_total' => $totalRev,
            'mapped_total'     => $mappedRev,
            'unmapped_total'   => $unmappedRev,
            'coverage_pct'     => $coveragePct,
            'total_invoices'   => (int)($totRow['total_invoices'] ?? 0),
            'total_customers'  => (int)($totRow['total_customers'] ?? 0),
        ],
        'buckets'                   => $buckets,
        'municipios_sem_bairro'     => $muniNotMapped,
        'top_clientes_sem_bairro'   => $topClientes,
    ]);

} catch (\Throwable $e) {
    Http::jsonError('geo_sales_coverage_audit_error', $e->getMessage());
}

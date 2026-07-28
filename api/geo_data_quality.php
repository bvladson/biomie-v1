<?php

declare(strict_types=1);

/**
 * api/geo_data_quality.php
 *
 * GEO.R1.8.3 — Qualidade geográfica dos dados de vendas.
 *
 * Retorna diagnóstico de registros sem bairro/sem geografia:
 *   - resumo: contagem e faturamento sem bairro
 *   - top_clientes: clientes com maior impacto sem bairro
 *   - por_cidade: distribuição por cidade
 *   - sugestao: orientação de ação por tipo de problema
 *
 * Uso: GET api/geo_data_quality.php?date_from=2026-01-01&date_to=2026-05-31
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
            'ok'        => true,
            'resumo'    => ['sem_bairro_notas' => 0, 'sem_bairro_revenue' => 0, 'total_notas' => 0, 'pct' => 0],
            'top_clientes' => [],
            'por_cidade'   => [],
            'empty_reason' => 'mart_compradores_por_geo não materializado',
        ]);
        return;
    }

    $baseWhere  = 'competencia BETWEEN :s AND :e AND '
        . \BIomie\Support\CommercialMartScope::condition($pdo, 'mart_compradores_por_geo');
    $baseParams = [':s' => $start, ':e' => $end];

    // ── Resumo: total vs sem bairro ────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(faturamento), 0)                                             AS total_revenue,
            COALESCE(SUM(qtd_notas), 0)                                               AS total_invoices,
            COALESCE(SUM(CASE WHEN TRIM(COALESCE(bairro_norm,'')) = '' THEN faturamento ELSE 0 END), 0) AS sem_bairro_revenue,
            COALESCE(SUM(CASE WHEN TRIM(COALESCE(bairro_norm,'')) = '' THEN qtd_notas ELSE 0 END), 0)   AS sem_bairro_invoices,
            COUNT(DISTINCT COALESCE(CAST(codigo_cliente_omie AS CHAR), documento, cliente_nome))         AS total_customers,
            COUNT(DISTINCT CASE WHEN TRIM(COALESCE(bairro_norm,'')) = '' THEN COALESCE(CAST(codigo_cliente_omie AS CHAR), documento, cliente_nome) END) AS sem_bairro_customers
        FROM mart_compradores_por_geo
        WHERE {$baseWhere}
    ");
    $stmt->execute($baseParams);
    $resumoRow = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

    $totalRevenue   = (float)($resumoRow['total_revenue']   ?? 0);
    $totalInvoices  = (int)  ($resumoRow['total_invoices']  ?? 0);
    $semBairroRev   = (float)($resumoRow['sem_bairro_revenue']   ?? 0);
    $semBairroInv   = (int)  ($resumoRow['sem_bairro_invoices']  ?? 0);
    $semBairroCus   = (int)  ($resumoRow['sem_bairro_customers'] ?? 0);

    $pct = $totalInvoices > 0 ? round($semBairroInv / $totalInvoices * 100, 1) : 0.0;

    // ── Top clientes com maior faturamento sem bairro ─────────────────────
    $canViewDocs = class_exists('BIomie\\Auth') && method_exists('BIomie\\Auth', 'canViewFullDocuments')
        ? \BIomie\Auth::canViewFullDocuments()
        : false;

    $topLimit = 15;
    $stmtTop = $pdo->prepare("
        SELECT
            COALESCE(cliente_nome, 'Sem nome')                                   AS cliente_nome,
            COALESCE(documento, '')                                               AS documento,
            LOWER(TRIM(COALESCE(cidade_norm, 'sem_cidade')))                     AS cidade_key,
            COALESCE(MAX(cep), '')                                                AS cep,
            SUM(faturamento)                                                      AS revenue,
            SUM(qtd_notas)                                                        AS invoices,
            MAX(ultima_compra)                                                    AS ultima_nf
        FROM mart_compradores_por_geo
        WHERE {$baseWhere}
          AND TRIM(COALESCE(bairro_norm, '')) = ''
        GROUP BY cliente_nome, documento, cidade_norm
        ORDER BY revenue DESC
        LIMIT {$topLimit}
    ");
    $stmtTop->execute($baseParams);
    $topRows = $stmtTop->fetchAll(\PDO::FETCH_ASSOC);

    $maskFn = class_exists('BIomie\\Support\\DocumentMask') ? ['\BIomie\Support\DocumentMask', 'mask'] : null;

    $topClientes = array_map(function($row) use ($canViewDocs, $maskFn) {
        $doc = (string)($row['documento'] ?? '');
        return [
            'cliente_nome'  => $row['cliente_nome'],
            'documento'     => $canViewDocs ? $doc : ($maskFn ? call_user_func($maskFn, $doc) : substr($doc, 0, 3) . '***'),
            'cidade_key'    => $row['cidade_key'],
            'cep'           => $row['cep'] ?: null,
            'revenue'       => (float)$row['revenue'],
            'invoices'      => (int)$row['invoices'],
            'ultima_nf'     => $row['ultima_nf'],
            'problema'      => 'sem_bairro',
            'sugestao'      => 'Verificar CEP e bairro no cadastro Omie do cliente.',
        ];
    }, $topRows);

    // ── Distribuição por cidade ────────────────────────────────────────────
    $stmtCidade = $pdo->prepare("
        SELECT
            LOWER(TRIM(COALESCE(cidade_norm, 'sem_cidade')))  AS cidade_key,
            SUM(faturamento)                                  AS revenue,
            SUM(qtd_notas)                                    AS invoices,
            COUNT(DISTINCT COALESCE(CAST(codigo_cliente_omie AS CHAR), documento, cliente_nome)) AS customers
        FROM mart_compradores_por_geo
        WHERE {$baseWhere}
          AND TRIM(COALESCE(bairro_norm, '')) = ''
        GROUP BY cidade_norm
        ORDER BY revenue DESC
        LIMIT 10
    ");
    $stmtCidade->execute($baseParams);
    $porCidade = array_map(fn($r) => [
        'cidade_key'  => $r['cidade_key'],
        'revenue'     => (float)$r['revenue'],
        'invoices'    => (int)$r['invoices'],
        'customers'   => (int)$r['customers'],
    ], $stmtCidade->fetchAll(\PDO::FETCH_ASSOC));

    // ── Sugestão de ação baseada no % sem bairro ──────────────────────────
    if ($pct >= 30) {
        $severidade  = 'danger';
        $sugestao    = 'Cobertura geográfica crítica. Mais de 30% das NFs não têm bairro cadastrado. Priorize a correção dos cadastros Omie.';
    } elseif ($pct >= 10) {
        $severidade  = 'warning';
        $sugestao    = 'Cobertura geográfica parcial. Corrija os clientes listados para melhorar a precisão do mapa.';
    } elseif ($pct > 0) {
        $severidade  = 'info';
        $sugestao    = 'Cobertura geográfica boa. Corrija os poucos registros faltantes para maximizar o mapa.';
    } else {
        $severidade  = 'positive';
        $sugestao    = 'Cobertura geográfica completa no período. Todos os registros têm bairro cadastrado.';
    }

    Http::json([
        'ok' => true,
        'resumo' => [
            'total_revenue'      => $totalRevenue,
            'total_invoices'     => $totalInvoices,
            'sem_bairro_revenue' => $semBairroRev,
            'sem_bairro_invoices'=> $semBairroInv,
            'sem_bairro_customers' => $semBairroCus,
            'pct'                => $pct,
        ],
        'top_clientes' => $topClientes,
        'por_cidade'   => $porCidade,
        'diagnostico'  => [
            'severidade' => $severidade,
            'sugestao'   => $sugestao,
        ],
    ]);

} catch (\Throwable $e) {
    Http::jsonError('geo_data_quality_error', $e->getMessage());
}

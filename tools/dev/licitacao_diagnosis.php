<?php

declare(strict_types=1);

/**
 * Fase L — T1: Diagnóstico de Licitação no BIomie
 *
 * Identifica todos os dados de Licitação nas tabelas canônicas ANTES de qualquer alteração.
 * Não modifica nada — apenas lê e reporta.
 *
 * Uso:
 *   php tools/dev/licitacao_diagnosis.php
 *   php tools/dev/licitacao_diagnosis.php --json
 *   php tools/dev/licitacao_diagnosis.php --start=2025-01-01 --end=2026-05-31
 */

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Support\BiVisibilityScope;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$opts   = getopt('', ['json', 'start:', 'end:']);
$isJson = isset($opts['json']);
$start  = $opts['start'] ?? '2024-01-01';
$end    = $opts['end']   ?? date('Y-m-d');

$config = Config::fromEnvironment();
$conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
if (!Database::isConfigured($config, $conn)) {
    fwrite(STDERR, "ERRO: Banco não configurado.\n");
    exit(1);
}
$pdo = Database::connect($config, $conn);
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

function diag_out(mixed $data, bool $json): void
{
    if ($json) {
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    } elseif (is_string($data)) {
        echo $data . "\n";
    } else {
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    }
}

function colExists(\PDO $pdo, string $table, string $col): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $col]);
    return (int) $stmt->fetchColumn() > 0;
}

if (!$isJson) {
    echo "\n=== Fase L — Diagnóstico de Licitação ===\n";
    echo "Período: {$start} a {$end}\n\n";
}

$report = [
    'periodo'   => ['start' => $start, 'end' => $end],
    'generated_at' => date(DATE_ATOM),
];

// ── 1. Vendedores com padrão Licitação ──────────────────────────────────────

$vendedores = [];
try {
    $rows = $pdo->query(
        "SELECT codigo_omie, nome FROM biomie_vendedores_omie
         WHERE UPPER(nome) LIKE '%LICITAC%'
         ORDER BY nome"
    )->fetchAll(\PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $vendedores[] = [
            'codigo_omie' => $r['codigo_omie'],
            'nome'        => $r['nome'],
            'normalizado' => BiVisibilityScope::normalizeName($r['nome']),
        ];
    }
} catch (\Throwable $e) {
    $vendedores = ['erro' => $e->getMessage()];
}

$report['vendedores_identificados'] = $vendedores;
$codigosLic = array_column(is_array($vendedores) ? $vendedores : [], 'codigo_omie');

if (!$isJson) {
    echo "VENDEDORES IDENTIFICADOS:\n";
    if (empty($codigosLic)) {
        echo "  (nenhum vendedor com padrão LICITAC% encontrado em biomie_vendedores_omie)\n";
    } else {
        foreach ($vendedores as $v) {
            echo "  [{$v['codigo_omie']}] {$v['nome']} → norm: {$v['normalizado']}\n";
        }
    }
    echo "\n";
}

// ── 2. NFs canônicas de Licitação ───────────────────────────────────────────

$nfStats = ['total' => 0, 'value_bruto' => 0.0, 'base_comercial' => 0.0,
            'clientes_distintos' => 0, 'periodos' => [], 'por_scope' => []];

if (!empty($codigosLic)) {
    try {
        $placeholders = implode(',', array_fill(0, count($codigosLic), '?'));
        $hasBasisCol  = colExists($pdo, 'biomie_notas_fiscais_canonicas', 'valor_base_comercial_nf');
        $hasScope     = colExists($pdo, 'biomie_notas_fiscais_canonicas', 'operation_scope');
        $basisExpr    = $hasBasisCol ? 'COALESCE(SUM(valor_base_comercial_nf), 0)' : 'COALESCE(SUM(valor_total), 0)';
        $scopeSelect  = $hasScope ? ', operation_scope' : ", 'unknown' AS operation_scope";
        $scopeGroup   = $hasScope ? ', operation_scope' : '';

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(valor_total), 0) AS valor_bruto,
                    {$basisExpr} AS base_comercial,
                    COUNT(DISTINCT codigo_cliente_omie) AS clientes_distintos
             FROM biomie_notas_fiscais_canonicas
             WHERE status_fiscal = 'autorizada'
               AND COALESCE(data_competencia, data_emissao) BETWEEN ? AND ?
               AND codigo_vendedor_omie IN ({$placeholders})"
        );
        $stmt->execute(array_merge([$start, $end], $codigosLic));
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        $nfStats['total']              = (int)   ($row['total']            ?? 0);
        $nfStats['value_bruto']        = (float) ($row['valor_bruto']      ?? 0);
        $nfStats['base_comercial']     = (float) ($row['base_comercial']   ?? 0);
        $nfStats['clientes_distintos'] = (int)   ($row['clientes_distintos'] ?? 0);
        $nfStats['basis_source']       = $hasBasisCol ? 'valor_base_comercial_nf' : 'valor_total_fallback';

        // Por competência
        $stmt2 = $pdo->prepare(
            "SELECT DATE_FORMAT(COALESCE(data_competencia, data_emissao), '%Y-%m') AS comp,
                    COUNT(*) AS nfs,
                    COALESCE(SUM(valor_total), 0) AS valor{$scopeSelect}
             FROM biomie_notas_fiscais_canonicas
             WHERE status_fiscal = 'autorizada'
               AND COALESCE(data_competencia, data_emissao) BETWEEN ? AND ?
               AND codigo_vendedor_omie IN ({$placeholders})
             GROUP BY comp{$scopeGroup}
             ORDER BY comp"
        );
        $stmt2->execute(array_merge([$start, $end], $codigosLic));
        $nfStats['por_competencia'] = $stmt2->fetchAll(\PDO::FETCH_ASSOC);

        // Por operation_scope
        if ($hasScope) {
            $stmt3 = $pdo->prepare(
                "SELECT operation_scope, COUNT(*) AS nfs, COALESCE(SUM(valor_total), 0) AS valor
                 FROM biomie_notas_fiscais_canonicas
                 WHERE status_fiscal = 'autorizada'
                   AND codigo_vendedor_omie IN ({$placeholders})
                 GROUP BY operation_scope"
            );
            $stmt3->execute($codigosLic);
            $nfStats['por_scope'] = $stmt3->fetchAll(\PDO::FETCH_ASSOC);
        }
    } catch (\Throwable $e) {
        $nfStats['erro'] = $e->getMessage();
    }
} else {
    // Fallback: busca por LIKE no nome_vendedor para detectar mesmo sem match na dim
    try {
        $hasBasisCol = colExists($pdo, 'biomie_notas_fiscais_canonicas', 'valor_base_comercial_nf');
        $basisExpr   = $hasBasisCol ? 'COALESCE(SUM(valor_base_comercial_nf), 0)' : 'COALESCE(SUM(valor_total), 0)';
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(valor_total), 0) AS valor_bruto,
                    {$basisExpr} AS base_comercial,
                    COUNT(DISTINCT codigo_cliente_omie) AS clientes_distintos,
                    COUNT(DISTINCT codigo_vendedor_omie) AS codigos_vendedor
             FROM biomie_notas_fiscais_canonicas
             WHERE status_fiscal = 'autorizada'
               AND COALESCE(data_competencia, data_emissao) BETWEEN ? AND ?
               AND UPPER(nome_vendedor) LIKE '%LICITAC%'"
        );
        $stmt->execute([$start, $end]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        $nfStats = array_merge($nfStats, [
            'total'              => (int)   ($row['total']              ?? 0),
            'value_bruto'        => (float) ($row['valor_bruto']        ?? 0),
            'base_comercial'     => (float) ($row['base_comercial']     ?? 0),
            'clientes_distintos' => (int)   ($row['clientes_distintos'] ?? 0),
            'codigos_via_like'   => (int)   ($row['codigos_vendedor']   ?? 0),
            'source'             => 'like_fallback',
        ]);

        // Códigos encontrados via LIKE
        $codigosViaNome = $pdo->prepare(
            "SELECT DISTINCT codigo_vendedor_omie, nome_vendedor
             FROM biomie_notas_fiscais_canonicas
             WHERE UPPER(nome_vendedor) LIKE '%LICITAC%'
             ORDER BY nome_vendedor LIMIT 20"
        );
        $codigosViaNome->execute();
        $nfStats['codigos_via_nome'] = $codigosViaNome->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        $nfStats['erro_like'] = $e->getMessage();
    }
}
$report['nfs_canonicas'] = $nfStats;

if (!$isJson) {
    echo "NFs CANÔNICAS (status=autorizada, período {$start}–{$end}):\n";
    echo "  Total NFs:          {$nfStats['total']}\n";
    echo "  Valor bruto:        R$ " . number_format($nfStats['value_bruto'], 2, ',', '.') . "\n";
    echo "  Base Comercial NF:  R$ " . number_format($nfStats['base_comercial'], 2, ',', '.') . "\n";
    echo "  Clientes distintos: {$nfStats['clientes_distintos']}\n";
    if (!empty($nfStats['por_scope'])) {
        echo "  Por operation_scope:\n";
        foreach ($nfStats['por_scope'] as $s) {
            printf("    %-15s %d NFs  R$ %s\n", $s['operation_scope'], (int)$s['nfs'], number_format((float)$s['valor'], 2, ',', '.'));
        }
    }
    echo "\n";
}

// ── 3. Pedidos canônicos de Licitação ────────────────────────────────────────

$pedidoStats = ['total' => 0, 'value_bruto' => 0.0, 'clientes_distintos' => 0];
if (!empty($codigosLic)) {
    try {
        $placeholders = implode(',', array_fill(0, count($codigosLic), '?'));
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(valor_total), 0) AS valor_bruto,
                    COUNT(DISTINCT codigo_cliente_omie) AS clientes_distintos
             FROM biomie_pedidos_canonicos
             WHERE COALESCE(data_faturamento, data_emissao) BETWEEN ? AND ?
               AND codigo_vendedor_omie IN ({$placeholders})"
        );
        $stmt->execute(array_merge([$start, $end], $codigosLic));
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        $pedidoStats = [
            'total'              => (int)   ($row['total']              ?? 0),
            'value_bruto'        => (float) ($row['valor_bruto']        ?? 0),
            'clientes_distintos' => (int)   ($row['clientes_distintos'] ?? 0),
        ];
    } catch (\Throwable $e) {
        $pedidoStats['erro'] = $e->getMessage();
    }
} else {
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS total, COALESCE(SUM(valor_total), 0) AS valor_bruto,
                    COUNT(DISTINCT codigo_cliente_omie) AS clientes_distintos
             FROM biomie_pedidos_canonicos
             WHERE COALESCE(data_faturamento, data_emissao) BETWEEN ? AND ?
               AND UPPER(nome_vendedor) LIKE '%LICITAC%'"
        );
        $stmt->execute([$start, $end]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        $pedidoStats = [
            'total'              => (int)   ($row['total']              ?? 0),
            'value_bruto'        => (float) ($row['valor_bruto']        ?? 0),
            'clientes_distintos' => (int)   ($row['clientes_distintos'] ?? 0),
        ];
    } catch (\Throwable $e) {
        $pedidoStats['erro'] = $e->getMessage();
    }
}
$report['pedidos_canonicos'] = $pedidoStats;

if (!$isJson) {
    echo "PEDIDOS CANÔNICOS (período {$start}–{$end}):\n";
    echo "  Total pedidos:      {$pedidoStats['total']}\n";
    echo "  Valor bruto:        R$ " . number_format($pedidoStats['value_bruto'], 2, ',', '.') . "\n";
    echo "  Clientes distintos: {$pedidoStats['clientes_distintos']}\n\n";
}

// ── 4. Títulos financeiros de Licitação ─────────────────────────────────────

$tituloStats = ['total' => 0, 'valor_liquido' => 0.0, 'valor_aberto' => 0.0];
$filterCodes = $codigosLic;
try {
    if (!empty($filterCodes)) {
        $placeholders = implode(',', array_fill(0, count($filterCodes), '?'));
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(valor_liquido), 0) AS valor_liquido,
                    COALESCE(SUM(valor_aberto), 0) AS valor_aberto
             FROM biomie_titulos_financeiros_canonicos
             WHERE codigo_vendedor_omie IN ({$placeholders})"
        );
        $stmt->execute($filterCodes);
    } else {
        $stmt = $pdo->query(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(valor_liquido), 0) AS valor_liquido,
                    COALESCE(SUM(valor_aberto), 0) AS valor_aberto
             FROM biomie_titulos_financeiros_canonicos
             WHERE UPPER(nome_vendedor) LIKE '%LICITAC%'"
        );
    }
    $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    $tituloStats = [
        'total'         => (int)   ($row['total']          ?? 0),
        'valor_liquido' => (float) ($row['valor_liquido']  ?? 0),
        'valor_aberto'  => (float) ($row['valor_aberto']   ?? 0),
    ];
} catch (\Throwable $e) {
    $tituloStats['erro'] = $e->getMessage();
}
$report['titulos_financeiros'] = $tituloStats;

if (!$isJson) {
    echo "TÍTULOS FINANCEIROS:\n";
    echo "  Total títulos:   {$tituloStats['total']}\n";
    echo "  Valor líquido:   R$ " . number_format($tituloStats['valor_liquido'], 2, ',', '.') . "\n";
    echo "  Valor aberto:    R$ " . number_format($tituloStats['valor_aberto'], 2, ',', '.') . "\n";
    echo "\n";
}

// ── 5. Produtos/SKUs vinculados ──────────────────────────────────────────────

$produtoStats = ['total_skus' => 0, 'top10' => []];
if (!empty($codigosLic)) {
    try {
        $placeholders = implode(',', array_fill(0, count($codigosLic), '?'));
        $stmt = $pdo->prepare(
            "SELECT COUNT(DISTINCT i.codigo_produto) AS total_skus
             FROM biomie_notas_fiscais_canonicas nf
             JOIN biomie_notas_fiscais_itens_canonicos i ON i.nota_key = nf.nota_key
             WHERE nf.status_fiscal = 'autorizada'
               AND nf.codigo_vendedor_omie IN ({$placeholders})"
        );
        $stmt->execute($codigosLic);
        $produtoStats['total_skus'] = (int) ($stmt->fetchColumn() ?: 0);

        $stmt2 = $pdo->prepare(
            "SELECT i.codigo_produto, i.descricao_produto,
                    SUM(i.quantidade) AS quantidade,
                    SUM(i.valor_total) AS valor
             FROM biomie_notas_fiscais_canonicas nf
             JOIN biomie_notas_fiscais_itens_canonicos i ON i.nota_key = nf.nota_key
             WHERE nf.status_fiscal = 'autorizada'
               AND nf.codigo_vendedor_omie IN ({$placeholders})
             GROUP BY i.codigo_produto, i.descricao_produto
             ORDER BY valor DESC
             LIMIT 10"
        );
        $stmt2->execute($codigosLic);
        $produtoStats['top10'] = $stmt2->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        $produtoStats['erro'] = $e->getMessage();
    }
}
$report['produtos'] = $produtoStats;

if (!$isJson) {
    echo "PRODUTOS/SKUs VINCULADOS:\n";
    echo "  SKUs distintos: {$produtoStats['total_skus']}\n";
    if (!empty($produtoStats['top10'])) {
        echo "  Top 10 produtos:\n";
        foreach ($produtoStats['top10'] as $p) {
            printf("    [%s] %-40s  qtd: %s  valor: R$ %s\n",
                $p['codigo_produto'] ?? 'SEM_SKU',
                mb_strimwidth((string)($p['descricao_produto'] ?? ''), 0, 40, '…'),
                number_format((float)$p['quantidade'], 2, ',', '.'),
                number_format((float)$p['valor'], 2, ',', '.')
            );
        }
    }
    echo "\n";
}

// ── 6. Status das colunas de visibilidade ───────────────────────────────────

$colStatus = [];
foreach (['biomie_notas_fiscais_canonicas', 'biomie_pedidos_canonicos',
          'biomie_titulos_financeiros_canonicos', 'biomie_vendedores_omie'] as $tbl) {
    $colStatus[$tbl] = [
        'is_biomie_commercial_visible' => colExists($pdo, $tbl, 'is_biomie_commercial_visible') ? 'OK' : 'PENDENTE',
        'business_unit_scope'          => colExists($pdo, $tbl, 'business_unit_scope')           ? 'OK' : 'N/A',
    ];
}
$report['migration_status'] = $colStatus;
$report['vendor_scope_rules_table'] = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biomie_vendor_scope_rules'"
)->fetchColumn() > 0 ? 'EXISTS' : 'PENDENTE';

if (!$isJson) {
    echo "STATUS DAS MIGRAÇÕES:\n";
    foreach ($colStatus as $tbl => $cols) {
        echo "  {$tbl}\n";
        foreach ($cols as $col => $status) {
            echo "    {$col}: {$status}\n";
        }
    }
    echo "  biomie_vendor_scope_rules: {$report['vendor_scope_rules_table']}\n\n";
}

// ── 7. Resumo / critérios de aceite ─────────────────────────────────────────

$report['summary'] = [
    'vendedores_identificados'  => count($codigosLic),
    'nfs_afetadas'              => $nfStats['total'],
    'valor_bruto_nfs'           => round($nfStats['value_bruto'], 2),
    'base_comercial_nfs'        => round($nfStats['base_comercial'], 2),
    'pedidos_afetados'          => $pedidoStats['total'],
    'titulos_afetados'          => $tituloStats['total'],
    'skus_afetados'             => $produtoStats['total_skus'],
    'clientes_distintos'        => $nfStats['clientes_distintos'],
    'migrations_pendentes'      => array_filter(
        array_column($colStatus, 'is_biomie_commercial_visible'),
        fn ($v) => $v === 'PENDENTE'
    ),
];

if (!$isJson) {
    echo "RESUMO:\n";
    echo "  Vendedores Licitação:   " . $report['summary']['vendedores_identificados'] . "\n";
    echo "  NFs afetadas:           " . $report['summary']['nfs_afetadas'] . "\n";
    echo "  Valor bruto NFs:        R$ " . number_format($report['summary']['valor_bruto_nfs'], 2, ',', '.') . "\n";
    echo "  Base Comercial NF:      R$ " . number_format($report['summary']['base_comercial_nfs'], 2, ',', '.') . "\n";
    echo "  Pedidos afetados:       " . $report['summary']['pedidos_afetados'] . "\n";
    echo "  Títulos afetados:       " . $report['summary']['titulos_afetados'] . "\n";
    echo "  SKUs afetados:          " . $report['summary']['skus_afetados'] . "\n";
    echo "  Clientes distintos:     " . $report['summary']['clientes_distintos'] . "\n";
    echo "\nPRÓXIMOS PASSOS:\n";
    if (!empty($report['migrations_pendentes'])) {
        echo "  1. Aplicar migrations: Version20260522020000_VendorScopeRules + Version20260522030000_CanonicalVisibilityFields\n";
    } else {
        echo "  1. Migrations já aplicadas.\n";
    }
    echo "  2. php tools/dev/backfill_visibility_scope.php --dry-run --pretty\n";
    echo "  3. php tools/dev/backfill_visibility_scope.php --apply --pretty\n";
    echo "  4. Rematerializar marts comerciais\n";
    echo "  5. php tools/dev/licitacao_visibility_guard.php --pretty\n\n";
}

if ($isJson) {
    diag_out($report, true);
}

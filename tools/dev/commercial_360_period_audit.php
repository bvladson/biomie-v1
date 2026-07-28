<?php

declare(strict_types=1);

/**
 * tools/dev/commercial_360_period_audit.php
 *
 * COM.R3 — Fase 0 — Diagnóstico 360 de período comercial.
 *
 * Compara, para o período informado:
 *   - Canônico NF (biomie_notas_fiscais_canonicas)
 *   - Canônico Pedidos (biomie_pedidos_canonicos)
 *   - Mart cliente_periodo
 *   - Mart produto_periodo
 *   - Mart vendedor_dia
 *   - Resumo por escopo: orc_pd, orcamento, carteira_aceita, faturado, remessa, licitacao, cancelado
 *
 * Detecta divergências por fonte e recomenda ação.
 *
 * Usage:
 *   php tools/dev/commercial_360_period_audit.php --start=2026-01-01 --end=2026-05-29 --pretty
 *   php tools/dev/commercial_360_period_audit.php --start=2026-02-15 --end=2026-03-15 --pretty
 *   php tools/dev/commercial_360_period_audit.php --json
 *
 * Exit: 0=ok 1=divergências 2=erro DB
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;
use BIomie\Support\BiVisibilityScope;

$opts      = getopt('', ['start:', 'end:', 'pretty', 'json']);
$startDate = isset($opts['start']) ? trim((string) $opts['start']) : date('Y-01-01');
$endDate   = isset($opts['end'])   ? trim((string) $opts['end'])   : date('Y-m-d');
$isPretty  = isset($opts['pretty']);
$isJson    = isset($opts['json']);

try {
    $config = new Config();
    $conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo    = Database::connect($config, $conn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (\Throwable $e) {
    $msg = 'ERRO DB: ' . $e->getMessage();
    echo ($isJson ? json_encode(['error' => $msg]) : $msg) . "\n";
    exit(2);
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function c360TableExists(PDO $pdo, string $t): bool
{
    try {
        $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t LIMIT 1');
        $s->execute(['t' => $t]);
        return (int) $s->fetchColumn() > 0;
    } catch (\Throwable) { return false; }
}

function c360Q(PDO $pdo, string $sql, array $p = []): array
{
    try {
        $s = $pdo->prepare($sql);
        $s->execute($p);
        return (array) ($s->fetch(PDO::FETCH_ASSOC) ?: []);
    } catch (\Throwable $e) {
        return ['_error' => $e->getMessage()];
    }
}

function c360Fmt(float $v): string
{
    if ($v >= 1_000_000) return 'R$' . number_format($v / 1_000_000, 2, ',', '.') . 'M';
    if ($v >= 1_000)     return 'R$' . number_format($v / 1_000, 1, ',', '.') . 'k';
    return 'R$' . number_format($v, 2, ',', '.');
}

// ── 1. Canônico NF — Escopo Orç-PD ───────────────────────────────────────────

$visCond = BiVisibilityScope::getCondition($pdo, 'biomie_notas_fiscais_canonicas', 'nf');

$nfOrcPd = c360Q($pdo,
    "SELECT
        COUNT(*) AS nfs,
        COALESCE(SUM(COALESCE(nf.valor_base_comercial_nf, nf.valor_mercadorias)), 0) AS base_comercial,
        COUNT(DISTINCT nf.codigo_cliente_omie) AS clientes,
        COUNT(DISTINCT nf.nome_vendedor) AS vendedores
     FROM biomie_notas_fiscais_canonicas nf
     WHERE nf.status_fiscal = 'autorizada'
       AND nf.operation_scope = 'commercial'
       AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :s AND :e
       AND {$visCond}",
    [':s' => $startDate, ':e' => $endDate]
);

// ── 2. Canônico NF — Por escopo ────────────────────────────────────────────

$nfByScope = [];
$scopeQueries = [
    'orcamento'       => "status_fiscal = 'autorizada' AND operation_scope = 'commercial' AND {$visCond}",
    'faturado_nf'     => "status_fiscal = 'autorizada' AND operation_scope = 'commercial' AND {$visCond}",
    'remessa'         => "operation_scope = 'remittance'",
    'licitacao'       => "operation_scope = 'commercial' AND NOT ({$visCond})",
    'todas_nfs'       => "status_fiscal = 'autorizada'",
];

foreach ($scopeQueries as $scope => $where) {
    $r = c360Q($pdo,
        "SELECT COUNT(*) AS nfs,
                COALESCE(SUM(COALESCE(valor_base_comercial_nf, valor_mercadorias)), 0) AS base_comercial
         FROM biomie_notas_fiscais_canonicas nf
         WHERE {$where}
           AND COALESCE(data_competencia, data_emissao) BETWEEN :s AND :e",
        [':s' => $startDate, ':e' => $endDate]
    );
    $nfByScope[$scope] = ['nfs' => (int)($r['nfs'] ?? 0), 'base_comercial' => (float)($r['base_comercial'] ?? 0)];
}

// ── 3. Canônico Pedidos ───────────────────────────────────────────────────────

$pedidos = c360Q($pdo,
    "SELECT
        COUNT(*) AS total,
        SUM(status_comercial = 'faturado') AS faturados,
        SUM(status_comercial = 'autorizado') AS autorizados,
        SUM(status_comercial = 'cancelado') AS cancelados,
        SUM(etapa_omie = '10') AS orcamentos,
        COALESCE(SUM(CASE WHEN status_comercial = 'autorizado' THEN valor_total ELSE 0 END), 0) AS carteira_value,
        COALESCE(SUM(CASE WHEN status_comercial = 'faturado' THEN valor_total ELSE 0 END), 0) AS faturado_value
     FROM biomie_pedidos_canonicos
     WHERE COALESCE(data_faturamento, data_emissao) BETWEEN :s AND :e",
    [':s' => $startDate, ':e' => $endDate]
);

// ── 4. Mart cliente_periodo ────────────────────────────────────────────────

$hasClientMart = c360TableExists($pdo, 'mart_cliente_periodo');
$martCliente = [];
if ($hasClientMart) {
    $months = [];
    $cursor = new DateTimeImmutable(substr($startDate, 0, 7) . '-01');
    $last   = new DateTimeImmutable(substr($endDate, 0, 7) . '-01');
    while ($cursor <= $last) { $months[] = $pdo->quote($cursor->format('Y-m')); $cursor = $cursor->modify('+1 month'); }
    $inClause = implode(',', $months);
    $martCliente = c360Q($pdo,
        "SELECT COUNT(DISTINCT codigo_cliente_omie) AS clientes,
                COALESCE(SUM(nfes_emitidas), 0) AS nfs_mart,
                COALESCE(SUM(valor_nfe), 0) AS valor_nfe,
                COALESCE(SUM(valor_faturado), 0) AS valor_faturado
         FROM mart_cliente_periodo
         WHERE competencia IN ({$inClause})"
    );
}

// ── 5. Mart produto_periodo ─────────────────────────────────────────────────

$hasProdMart = c360TableExists($pdo, 'mart_produto_periodo');
$martProd = [];
if ($hasProdMart) {
    $months = [];
    $cursor = new DateTimeImmutable(substr($startDate, 0, 7) . '-01');
    $last   = new DateTimeImmutable(substr($endDate, 0, 7) . '-01');
    while ($cursor <= $last) { $months[] = $pdo->quote($cursor->format('Y-m')); $cursor = $cursor->modify('+1 month'); }
    $inClause = implode(',', $months);
    $martProd = c360Q($pdo,
        "SELECT COUNT(DISTINCT codigo_produto) AS produtos,
                COALESCE(SUM(nfes_count), 0) AS nfs_mart,
                COALESCE(SUM(valor_total), 0) AS valor_total
         FROM mart_produto_periodo
         WHERE competencia IN ({$inClause})"
    );
}

// ── 6. Mart vendedor_dia ─────────────────────────────────────────────────────

$hasVendMart = c360TableExists($pdo, 'mart_vendedor_dia');
$martVend = [];
if ($hasVendMart) {
    $martVend = c360Q($pdo,
        "SELECT COUNT(DISTINCT codigo_vendedor_omie) AS vendedores,
                COALESCE(SUM(nfes_emitidas), 0) AS nfs_mart,
                COALESCE(SUM(valor_nfe), 0) AS valor_nfe
         FROM mart_vendedor_dia
         WHERE dia BETWEEN :s AND :e",
        [':s' => $startDate, ':e' => $endDate]
    );
}

// ── 7. Divergências ───────────────────────────────────────────────────────────

$divergencias = [];
$nfCanonTotal = (int)($nfOrcPd['nfs'] ?? 0);
$nfMartTotal  = (int)($martCliente['nfs_mart'] ?? 0);

if ($hasClientMart && $nfCanonTotal > 0 && $nfMartTotal === 0) {
    $divergencias[] = 'mart_cliente_periodo vazio mas NFs existem — rematerializar';
}

$baseMart = (float)($martCliente['valor_nfe'] ?? 0);
$baseCanon = (float)($nfOrcPd['base_comercial'] ?? 0);
if ($hasClientMart && $baseCanon > 0 && abs($baseMart - $baseCanon) / max(1, $baseCanon) > 0.05) {
    $divergencias[] = sprintf(
        'Divergência base comercial: canônico=%s mart=%s (%.1f%%)',
        c360Fmt($baseCanon), c360Fmt($baseMart),
        abs($baseMart - $baseCanon) / max(1, $baseCanon) * 100
    );
}

$licitacao = (int)($nfByScope['licitacao']['nfs'] ?? 0);
if ($licitacao > 0) {
    $divergencias[] = "Licitação detectada: {$licitacao} NFs com visible=0 (correto — apenas diagnóstico)";
}

// ── Output ────────────────────────────────────────────────────────────────────

$result = [
    'tool'   => 'commercial_360_period_audit',
    'period' => ['start' => $startDate, 'end' => $endDate],
    'status' => count($divergencias) === 0 ? 'ok' : 'divergencias',
    'canonical_nf' => [
        'orc_pd'     => ['nfs' => (int)($nfOrcPd['nfs'] ?? 0), 'base_comercial' => (float)($nfOrcPd['base_comercial'] ?? 0), 'clientes' => (int)($nfOrcPd['clientes'] ?? 0), 'vendedores' => (int)($nfOrcPd['vendedores'] ?? 0)],
        'by_scope'   => $nfByScope,
    ],
    'canonical_pedidos' => [
        'total'           => (int)($pedidos['total'] ?? 0),
        'faturados'       => (int)($pedidos['faturados'] ?? 0),
        'autorizados'     => (int)($pedidos['autorizados'] ?? 0),
        'cancelados'      => (int)($pedidos['cancelados'] ?? 0),
        'orcamentos'      => (int)($pedidos['orcamentos'] ?? 0),
        'carteira_value'  => (float)($pedidos['carteira_value'] ?? 0),
        'faturado_value'  => (float)($pedidos['faturado_value'] ?? 0),
    ],
    'mart_cliente_periodo' => ['available' => $hasClientMart] + $martCliente,
    'mart_produto_periodo' => ['available' => $hasProdMart] + $martProd,
    'mart_vendedor_dia'    => ['available' => $hasVendMart] + $martVend,
    'divergencias'         => $divergencias,
];

if ($isJson) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit(count($divergencias) > 0 ? 1 : 0);
}

$bar = str_repeat('═', 70);
$sep = str_repeat('─', 70);
echo "\n{$bar}\n Commercial 360 — Diagnóstico de Período\n{$bar}\n";
echo " Período : {$startDate} → {$endDate}\n";
echo " Status  : " . ($result['status'] === 'ok' ? "\033[32mOK\033[0m" : "\033[33mDIVERGÊNCIAS\033[0m") . "\n";
echo "{$sep}\n\n";

$orc = $result['canonical_nf']['orc_pd'];
echo " NFs Canônicas (Orç-PD, visíveis)\n";
printf("   NFs        : %d\n", $orc['nfs']);
printf("   Base Comerc.: %s\n", c360Fmt($orc['base_comercial']));
printf("   Clientes   : %d\n", $orc['clientes']);
printf("   Vendedores : %d\n", $orc['vendedores']);

echo "\n Por Escopo:\n";
foreach ($result['canonical_nf']['by_scope'] as $scope => $data) {
    printf("   %-18s : %4d NFs  %s\n", $scope, $data['nfs'], c360Fmt($data['base_comercial']));
}

echo "\n Pedidos Canônicos:\n";
$ped = $result['canonical_pedidos'];
printf("   Total=%d | Fat=%d | Aut=%d | Canc=%d | Orç=%d\n", $ped['total'], $ped['faturados'], $ped['autorizados'], $ped['cancelados'], $ped['orcamentos']);
printf("   Carteira aceita: %s | Faturado: %s\n", c360Fmt($ped['carteira_value']), c360Fmt($ped['faturado_value']));

echo "\n Marts Gold:\n";
if ($hasClientMart) {
    $mc = $result['mart_cliente_periodo'];
    printf("   cliente_periodo : %d clientes | %d NFs | %s\n", (int)($mc['clientes'] ?? 0), (int)($mc['nfs_mart'] ?? 0), c360Fmt((float)($mc['valor_nfe'] ?? 0)));
} else {
    echo "   cliente_periodo : [ausente]\n";
}
if ($hasProdMart) {
    $mp = $result['mart_produto_periodo'];
    printf("   produto_periodo : %d produtos | %d NFs | %s\n", (int)($mp['produtos'] ?? 0), (int)($mp['nfs_mart'] ?? 0), c360Fmt((float)($mp['valor_total'] ?? 0)));
} else {
    echo "   produto_periodo : [ausente]\n";
}
if ($hasVendMart) {
    $mv = $result['mart_vendedor_dia'];
    printf("   vendedor_dia    : %d vend | %d NFs | %s\n", (int)($mv['vendedores'] ?? 0), (int)($mv['nfs_mart'] ?? 0), c360Fmt((float)($mv['valor_nfe'] ?? 0)));
} else {
    echo "   vendedor_dia    : [ausente]\n";
}

if (count($divergencias) > 0) {
    echo "\n\033[33m Divergências:\033[0m\n";
    foreach ($divergencias as $d) {
        echo "   ⚠ {$d}\n";
    }
} else {
    echo "\n\033[32m ✔ Nenhuma divergência crítica detectada.\033[0m\n";
}

echo "\n{$bar}\n\n";
exit(count($divergencias) > 0 ? 1 : 0);

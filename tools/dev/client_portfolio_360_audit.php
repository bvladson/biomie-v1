<?php

declare(strict_types=1);

/**
 * tools/dev/client_portfolio_360_audit.php
 *
 * COM.R3 — Fase 0 — Diagnóstico de carteira 360 de clientes.
 *
 * Mostra:
 *   - Total de clientes com NF no período
 *   - Clientes com dono (biomie_clientes_dim.vendedor_responsavel_nome)
 *   - Clientes sem dono
 *   - Clientes ativos no período
 *   - Recorrentes (2+ meses distintos com NF)
 *   - Novos (primeira NF dentro do período)
 *   - Conflitos fora da carteira (vendedor da NF ≠ dono da carteira)
 *   - Top 5 conflitos por valor
 *   - Fontes de dono e confidence
 *
 * Usage:
 *   php tools/dev/client_portfolio_360_audit.php --start=2026-01-01 --end=2026-05-29 --pretty
 *
 * Exit: 0=ok 1=sem dados 2=erro DB
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

function cp360TableExists(PDO $pdo, string $t): bool
{
    try {
        $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t LIMIT 1');
        $s->execute(['t' => $t]);
        return (int) $s->fetchColumn() > 0;
    } catch (\Throwable) { return false; }
}

function cp360Fmt(float $v): string
{
    if ($v >= 1_000_000) return 'R$' . number_format($v / 1_000_000, 2, ',', '.') . 'M';
    if ($v >= 1_000)     return 'R$' . number_format($v / 1_000, 1, ',', '.') . 'k';
    return 'R$' . number_format($v, 2, ',', '.');
}

// ── Check tables ──────────────────────────────────────────────────────────────

$hasDimTable = cp360TableExists($pdo, 'biomie_clientes_dim');
$hasNfTable  = cp360TableExists($pdo, 'biomie_notas_fiscais_canonicas');

$visCond = BiVisibilityScope::getCondition($pdo, 'biomie_notas_fiscais_canonicas', 'nf');

if (!$hasNfTable) {
    $out = ['error' => 'biomie_notas_fiscais_canonicas ausente'];
    echo ($isJson ? json_encode($out) : "ERRO: tabela NF canônica ausente\n") . "\n";
    exit(2);
}

// ── 1. Total clientes ativos no período ───────────────────────────────────────

$stmtActive = $pdo->prepare(
    "SELECT COUNT(DISTINCT nf.codigo_cliente_omie) AS clientes_ativos,
            COALESCE(SUM(COALESCE(nf.valor_base_comercial_nf, nf.valor_mercadorias)), 0) AS receita_total
     FROM biomie_notas_fiscais_canonicas nf
     WHERE nf.status_fiscal = 'autorizada'
       AND nf.operation_scope = 'commercial'
       AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :s AND :e
       AND {$visCond}"
);
$stmtActive->execute([':s' => $startDate, ':e' => $endDate]);
$activeRow = $stmtActive->fetch(PDO::FETCH_ASSOC) ?: [];
$clientesAtivos = (int)($activeRow['clientes_ativos'] ?? 0);
$receitaTotal   = (float)($activeRow['receita_total'] ?? 0);

// ── 2. Clientes com dono (biomie_clientes_dim) ────────────────────────────────

$clientesComDono = 0;
$clientesSemDono = 0;
$dimCoverage = [];

if ($hasDimTable) {
    $stmtDono = $pdo->prepare(
        "SELECT
            COUNT(DISTINCT nf.codigo_cliente_omie) AS total,
            COUNT(DISTINCT CASE WHEN d.vendedor_responsavel_nome IS NOT NULL AND d.vendedor_responsavel_nome <> '' THEN nf.codigo_cliente_omie END) AS com_dono,
            COUNT(DISTINCT CASE WHEN d.vendedor_responsavel_nome IS NULL OR d.vendedor_responsavel_nome = '' THEN nf.codigo_cliente_omie END) AS sem_dono
         FROM biomie_notas_fiscais_canonicas nf
         LEFT JOIN biomie_clientes_dim d ON d.codigo_cliente_omie = nf.codigo_cliente_omie
         WHERE nf.status_fiscal = 'autorizada'
           AND nf.operation_scope = 'commercial'
           AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :s AND :e
           AND {$visCond}"
    );
    $stmtDono->execute([':s' => $startDate, ':e' => $endDate]);
    $donoRow = $stmtDono->fetch(PDO::FETCH_ASSOC) ?: [];
    $clientesComDono = (int)($donoRow['com_dono'] ?? 0);
    $clientesSemDono = (int)($donoRow['sem_dono'] ?? 0);

    // Top owners by client count
    $stmtOwners = $pdo->prepare(
        "SELECT d.vendedor_responsavel_nome AS dono,
                COUNT(DISTINCT nf.codigo_cliente_omie) AS clientes,
                COALESCE(SUM(COALESCE(nf.valor_base_comercial_nf, nf.valor_mercadorias)), 0) AS receita
         FROM biomie_notas_fiscais_canonicas nf
         JOIN biomie_clientes_dim d ON d.codigo_cliente_omie = nf.codigo_cliente_omie
         WHERE nf.status_fiscal = 'autorizada'
           AND nf.operation_scope = 'commercial'
           AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :s AND :e
           AND {$visCond}
           AND d.vendedor_responsavel_nome IS NOT NULL AND d.vendedor_responsavel_nome <> ''
         GROUP BY d.vendedor_responsavel_nome
         ORDER BY clientes DESC
         LIMIT 10"
    );
    $stmtOwners->execute([':s' => $startDate, ':e' => $endDate]);
    $dimCoverage = $stmtOwners->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Fallback: use first NF vendor as proxy
    $stmtFallback = $pdo->prepare(
        "SELECT COUNT(DISTINCT codigo_cliente_omie) AS total
         FROM biomie_notas_fiscais_canonicas nf
         WHERE status_fiscal = 'autorizada'
           AND operation_scope = 'commercial'
           AND COALESCE(data_competencia, data_emissao) BETWEEN :s AND :e
           AND {$visCond}"
    );
    $stmtFallback->execute([':s' => $startDate, ':e' => $endDate]);
    $clientesSemDono = (int)$stmtFallback->fetchColumn();
}

// ── 3. Clientes recorrentes (2+ meses distintos) ──────────────────────────────

$stmtRecurring = $pdo->prepare(
    "SELECT COUNT(*) AS recorrentes
     FROM (
         SELECT nf.codigo_cliente_omie,
                COUNT(DISTINCT DATE_FORMAT(COALESCE(nf.data_competencia, nf.data_emissao), '%Y-%m')) AS meses_distintos
         FROM biomie_notas_fiscais_canonicas nf
         WHERE nf.status_fiscal = 'autorizada'
           AND nf.operation_scope = 'commercial'
           AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :s AND :e
           AND {$visCond}
           AND nf.codigo_cliente_omie IS NOT NULL
         GROUP BY nf.codigo_cliente_omie
         HAVING meses_distintos >= 2
     ) _rec"
);
$stmtRecurring->execute([':s' => $startDate, ':e' => $endDate]);
$recorrentes = (int)$stmtRecurring->fetchColumn();

// ── 4. Clientes novos (primeira NF dentro do período) ─────────────────────────

$stmtNew = $pdo->prepare(
    "SELECT COUNT(*) AS novos
     FROM (
         SELECT nf.codigo_cliente_omie,
                MIN(COALESCE(nf.data_competencia, nf.data_emissao)) AS primeira_nf
         FROM biomie_notas_fiscais_canonicas nf
         WHERE nf.status_fiscal = 'autorizada'
           AND nf.operation_scope = 'commercial'
           AND {$visCond}
           AND nf.codigo_cliente_omie IS NOT NULL
         GROUP BY nf.codigo_cliente_omie
         HAVING primeira_nf BETWEEN :s AND :e
     ) _new"
);
$stmtNew->execute([':s' => $startDate, ':e' => $endDate]);
$novos = (int)$stmtNew->fetchColumn();

// ── 5. Conflitos fora da carteira (vend NF ≠ dono dim) ────────────────────────

$conflitos = 0;
$conflitosValor = 0.0;
$topConflitos = [];

if ($hasDimTable) {
    $stmtConflict = $pdo->prepare(
        "SELECT COUNT(*) AS conflitos,
                COALESCE(SUM(COALESCE(nf.valor_base_comercial_nf, nf.valor_mercadorias)), 0) AS valor_conflitos
         FROM biomie_notas_fiscais_canonicas nf
         JOIN biomie_clientes_dim d ON d.codigo_cliente_omie = nf.codigo_cliente_omie
         WHERE nf.status_fiscal = 'autorizada'
           AND nf.operation_scope = 'commercial'
           AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :s AND :e
           AND {$visCond}
           AND d.vendedor_responsavel_nome IS NOT NULL AND d.vendedor_responsavel_nome <> ''
           AND nf.nome_vendedor <> d.vendedor_responsavel_nome"
    );
    $stmtConflict->execute([':s' => $startDate, ':e' => $endDate]);
    $conflictRow = $stmtConflict->fetch(PDO::FETCH_ASSOC) ?: [];
    $conflitos = (int)($conflictRow['conflitos'] ?? 0);
    $conflitosValor = (float)($conflictRow['valor_conflitos'] ?? 0);

    // Top 5 conflitos por valor
    $stmtTopConfl = $pdo->prepare(
        "SELECT nf.nome_cliente, nf.nome_vendedor AS vendedor_venda,
                d.vendedor_responsavel_nome AS dono_carteira,
                COUNT(*) AS nfs,
                COALESCE(SUM(COALESCE(nf.valor_base_comercial_nf, nf.valor_mercadorias)), 0) AS valor
         FROM biomie_notas_fiscais_canonicas nf
         JOIN biomie_clientes_dim d ON d.codigo_cliente_omie = nf.codigo_cliente_omie
         WHERE nf.status_fiscal = 'autorizada'
           AND nf.operation_scope = 'commercial'
           AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :s AND :e
           AND {$visCond}
           AND d.vendedor_responsavel_nome IS NOT NULL AND d.vendedor_responsavel_nome <> ''
           AND nf.nome_vendedor <> d.vendedor_responsavel_nome
         GROUP BY nf.nome_cliente, nf.nome_vendedor, d.vendedor_responsavel_nome
         ORDER BY valor DESC
         LIMIT 5"
    );
    $stmtTopConfl->execute([':s' => $startDate, ':e' => $endDate]);
    $topConflitos = $stmtTopConfl->fetchAll(PDO::FETCH_ASSOC);
}

// ── Output ────────────────────────────────────────────────────────────────────

$output = [
    'tool'                   => 'client_portfolio_360_audit',
    'period'                 => ['start' => $startDate, 'end' => $endDate],
    'has_clientes_dim'       => $hasDimTable,
    'clientes_ativos_periodo' => $clientesAtivos,
    'receita_total'          => $receitaTotal,
    'com_dono'               => $clientesComDono,
    'sem_dono'               => $clientesSemDono,
    'recorrentes'            => $recorrentes,
    'novos'                  => $novos,
    'conflitos_fora_carteira' => $conflitos,
    'valor_conflitos'        => $conflitosValor,
    'top_owners'             => $dimCoverage,
    'top_conflitos'          => $topConflitos,
];

if ($isJson) {
    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit($clientesAtivos > 0 ? 0 : 1);
}

$bar = str_repeat('═', 70);
$sep = str_repeat('─', 70);

echo "\n{$bar}\n Carteira 360 — Diagnóstico de Portfolio de Clientes\n{$bar}\n";
echo " Período    : {$startDate} → {$endDate}\n";
echo " Dim table  : " . ($hasDimTable ? "\033[32mOK\033[0m" : "\033[33mAUSENTE — usando fallback\033[0m") . "\n";
echo "{$sep}\n\n";

echo " Clientes no período\n";
printf("   Ativos (com NF)  : %d  (%s)\n", $clientesAtivos, cp360Fmt($receitaTotal));
printf("   Com dono dim     : %d\n", $clientesComDono);
printf("   Sem dono         : %d\n", $clientesSemDono);
printf("   Recorrentes      : %d  (2+ meses distintos)\n", $recorrentes);
printf("   Novos/Conquistados: %d  (1ª NF no período)\n", $novos);

if ($hasDimTable) {
    printf("   Conflitos fora   : %d NFs  (%s)\n", $conflitos, cp360Fmt($conflitosValor));
}

if (!empty($dimCoverage)) {
    echo "\n Top donos por clientes:\n";
    foreach ($dimCoverage as $row) {
        printf("   %-35s : %3d clientes | %s\n", mb_substr((string)($row['dono'] ?? '-'), 0, 35), (int)($row['clientes'] ?? 0), cp360Fmt((float)($row['receita'] ?? 0)));
    }
}

if (!empty($topConflitos)) {
    echo "\n Top conflitos (vend NF ≠ dono carteira):\n";
    echo "   [\033[33mATENÇÃO: apenas informativo — não bloqueia faturamento\033[0m]\n";
    foreach ($topConflitos as $row) {
        printf("   %-25s | Vendeu: %-20s | Dono: %-20s | %s\n",
            mb_substr((string)($row['nome_cliente'] ?? ''), 0, 25),
            mb_substr((string)($row['vendedor_venda'] ?? ''), 0, 20),
            mb_substr((string)($row['dono_carteira'] ?? ''), 0, 20),
            cp360Fmt((float)($row['valor'] ?? 0))
        );
    }
}

if ($clientesSemDono > 0 && $hasDimTable) {
    $pct = $clientesAtivos > 0 ? round($clientesSemDono / $clientesAtivos * 100, 1) : 0;
    echo "\n\033[33m ⚠ {$clientesSemDono} clientes ({$pct}%) sem dono em biomie_clientes_dim.\033[0m\n";
    echo "   Recomendação: executar enriquecimento de clientes para popular vendedor_responsavel_nome.\n";
    echo "   Ou: a prioridade 2 (primeiro vendedor NF) será usada no ClientPortfolio360Resolver.\n";
}

echo "\n{$bar}\n\n";
exit($clientesAtivos > 0 ? 0 : 1);

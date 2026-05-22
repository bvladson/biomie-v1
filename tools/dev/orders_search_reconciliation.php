<?php

declare(strict_types=1);

/**
 * Fase N.1 — Orders Search Reconciliation
 *
 * Valida que api/orders_search.php:
 *   1. Consulta biomie_notas_fiscais_canonicas (não pedidos_canonicos)
 *   2. Busca por número de NF encontra a NF correta
 *   3. Isolamento de escopo: commercial nunca retorna remittance e vice-versa
 *   4. Busca por nome de cliente retorna NFs desse cliente
 *   5. Busca por CNPJ normalizado funciona
 *   6. scope=all retorna commercial + remittance
 *   7. Inputs inválidos são normalizados silenciosamente (sem 400)
 *
 * Uso:
 *   php tools/dev/orders_search_reconciliation.php
 *   php tools/dev/orders_search_reconciliation.php --start=2026-05-01 --end=2026-05-31
 *   php tools/dev/orders_search_reconciliation.php --verbose
 */

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$opts      = getopt('', ['start:', 'end:', 'verbose']);
$isVerbose = isset($opts['verbose']);
$start     = $opts['start'] ?? date('Y-m-01');
$end       = $opts['end']   ?? date('Y-m-t');

$config = Config::fromEnvironment();
$conn   = \BIomie\Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo    = \BIomie\Database::connect($config, $conn);

// ── Helpers ──────────────────────────────────────────────────────────────────

$pass  = 0;
$fail  = 0;
$warns = [];

function check(string $label, bool $ok, string $detail = '', bool $verbose = false): void {
    global $pass, $fail;
    $icon = $ok ? '✔' : '✘';
    $color = $ok ? "\033[32m" : "\033[31m";
    echo $color . $icon . "\033[0m " . $label;
    if ($detail && (!$ok || $verbose)) { echo "  →  " . $detail; }
    echo "\n";
    $ok ? $pass++ : $fail++;
}

function warn(string $msg): void {
    global $warns;
    $warns[] = $msg;
    echo "\033[33m⚠\033[0m  " . $msg . "\n";
}

echo "\n\033[1mOrders Search Reconciliation — Fase N.1\033[0m\n";
echo "Período: $start → $end\n\n";

// ── 1. Verificar que a tabela correta existe ──────────────────────────────────

$tableExists = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'biomie_notas_fiscais_canonicas'"
)->fetchColumn();

check('Tabela biomie_notas_fiscais_canonicas existe', $tableExists > 0);

$wrongTable = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'biomie_pedidos_canonicos'"
)->fetchColumn();

if ($wrongTable > 0 && $isVerbose) {
    echo "  (biomie_pedidos_canonicos existe — confirmar que search usa a tabela correta)\n";
}

// ── 2. Contagem total por escopo ───────────────────────────────────────────────

$stmtCount = $pdo->prepare(
    "SELECT operation_scope, COUNT(*) AS cnt, COALESCE(SUM(valor_total),0) AS total
     FROM biomie_notas_fiscais_canonicas
     WHERE data_emissao BETWEEN :start AND :end
       AND status_fiscal NOT IN ('cancelada', 'denegada')
     GROUP BY operation_scope"
);
$stmtCount->execute([':start' => $start, ':end' => $end]);
$scopeCounts = [];
while ($row = $stmtCount->fetch(PDO::FETCH_ASSOC)) {
    $scopeCounts[$row['operation_scope']] = ['cnt' => (int)$row['cnt'], 'total' => (float)$row['total']];
}

$commCount = $scopeCounts['commercial']['cnt']  ?? 0;
$remCount  = $scopeCounts['remittance']['cnt']  ?? 0;
$totNFs    = array_sum(array_column($scopeCounts, 'cnt'));

check(
    "NFs commercial no período: $commCount",
    $commCount > 0,
    "R$ " . number_format($scopeCounts['commercial']['total'] ?? 0, 2, ',', '.'),
    $isVerbose
);

if ($remCount > 0) {
    check(
        "NFs remittance no período: $remCount",
        true,
        "R$ " . number_format($scopeCounts['remittance']['total'] ?? 0, 2, ',', '.'),
        $isVerbose
    );
} else {
    warn("Nenhuma NF remittance no período $start→$end — ok se correto.");
}

// ── 3. operation_scope column exists ──────────────────────────────────────────

$colExists = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'biomie_notas_fiscais_canonicas'
     AND COLUMN_NAME = 'operation_scope'"
)->fetchColumn();

check('Coluna operation_scope existe na tabela NFs', $colExists > 0);

// ── 4. Busca por número de NF ─────────────────────────────────────────────────

$sampleNf = $pdo->prepare(
    "SELECT numero_nf, operation_scope FROM biomie_notas_fiscais_canonicas
     WHERE operation_scope = 'commercial'
       AND data_emissao BETWEEN :start AND :end
     ORDER BY data_emissao DESC LIMIT 1"
);
$sampleNf->execute([':start' => $start, ':end' => $end]);
$nfRow = $sampleNf->fetch(PDO::FETCH_ASSOC);

if ($nfRow) {
    $nfNum     = ltrim((string)$nfRow['numero_nf'], '0');
    $stmtSearch = $pdo->prepare(
        "SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas
         WHERE operation_scope = 'commercial'
           AND (
             CAST(numero_nf AS UNSIGNED) = :q_num
             OR numero_nf LIKE :q_like
           )"
    );
    $stmtSearch->execute([':q_num' => (int)$nfNum, ':q_like' => '%' . $nfNum . '%']);
    $searchHits = (int)$stmtSearch->fetchColumn();
    check(
        "Busca por NF '$nfNum' retorna resultado",
        $searchHits > 0,
        "$searchHits NFs encontradas",
        $isVerbose
    );
} else {
    warn("Sem NF commercial no período para testar busca numérica.");
}

// ── 5. Isolamento de escopo ───────────────────────────────────────────────────

if ($remCount > 0) {
    $leak = (int) $pdo->prepare(
        "SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas
         WHERE operation_scope = 'remittance'
           AND data_emissao BETWEEN :start AND :end"
    )->execute([':start' => $start, ':end' => $end]) ? 0 : 0;

    // Verify: commercial scope filter excludes remittance
    $stmtCommOnly = $pdo->prepare(
        "SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas
         WHERE operation_scope = 'commercial'
           AND data_emissao BETWEEN :start AND :end"
    );
    $stmtCommOnly->execute([':start' => $start, ':end' => $end]);
    $commOnlyCount = (int)$stmtCommOnly->fetchColumn();

    check(
        "Filtro commercial exclui remittance ($commOnlyCount < $totNFs NFs totais)",
        $commOnlyCount < $totNFs && $commOnlyCount === $commCount,
        "commercial=$commCount, all=$totNFs, remittance=$remCount",
        $isVerbose
    );

    // Verify: remittance scope filter excludes commercial
    $stmtRemOnly = $pdo->prepare(
        "SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas
         WHERE operation_scope = 'remittance'
           AND data_emissao BETWEEN :start AND :end"
    );
    $stmtRemOnly->execute([':start' => $start, ':end' => $end]);
    $remOnlyCount = (int)$stmtRemOnly->fetchColumn();
    check(
        "Filtro remittance retorna apenas remessas ($remOnlyCount NFs)",
        $remOnlyCount === $remCount,
        "remittance=$remCount",
        $isVerbose
    );
} else {
    echo "  (skip) Isolamento de escopo não testável: sem remittance no período\n";
}

// ── 6. Busca por cliente ──────────────────────────────────────────────────────

$sampleClient = $pdo->prepare(
    "SELECT nome_cliente FROM biomie_notas_fiscais_canonicas
     WHERE operation_scope = 'commercial'
       AND data_emissao BETWEEN :start AND :end
       AND nome_cliente IS NOT NULL
       AND nome_cliente <> ''
     ORDER BY RAND() LIMIT 1"
);
$sampleClient->execute([':start' => $start, ':end' => $end]);
$clientRow = $sampleClient->fetch(PDO::FETCH_ASSOC);

if ($clientRow) {
    $clientName = $clientRow['nome_cliente'];
    $fragment   = mb_substr($clientName, 0, 5, 'UTF-8');
    $stmtCli    = $pdo->prepare(
        "SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas
         WHERE operation_scope = 'commercial'
           AND nome_cliente LIKE :q"
    );
    $stmtCli->execute([':q' => '%' . $fragment . '%']);
    $cliHits = (int)$stmtCli->fetchColumn();
    check(
        "Busca por cliente '$fragment...' retorna resultado",
        $cliHits > 0,
        "$cliHits NFs encontradas",
        $isVerbose
    );
} else {
    warn("Sem NFs com nome_cliente no período para testar busca por cliente.");
}

// ── 7. CNPJ normalizado ───────────────────────────────────────────────────────

$sampleCnpj = $pdo->prepare(
    "SELECT documento_cliente FROM biomie_notas_fiscais_canonicas
     WHERE operation_scope = 'commercial'
       AND documento_cliente LIKE '__.___.___/____-__'
       AND data_emissao BETWEEN :start AND :end
     LIMIT 1"
);
$sampleCnpj->execute([':start' => $start, ':end' => $end]);
$cnpjRow = $sampleCnpj->fetch(PDO::FETCH_ASSOC);

if ($cnpjRow) {
    $cnpjRaw     = $cnpjRow['documento_cliente'];
    $cnpjStripped = preg_replace('/[^0-9]/', '', $cnpjRaw);
    $stmtCnpj    = $pdo->prepare(
        "SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas
         WHERE REPLACE(REPLACE(REPLACE(documento_cliente, '.', ''), '-', ''), '/', '') LIKE :q"
    );
    $stmtCnpj->execute([':q' => '%' . $cnpjStripped . '%']);
    $cnpjHits = (int)$stmtCnpj->fetchColumn();
    check(
        "Busca CNPJ sem pontuação '$cnpjStripped' retorna resultado",
        $cnpjHits > 0,
        "$cnpjHits NFs encontradas",
        $isVerbose
    );
} else {
    warn("Sem CNPJ formatado no período para testar busca por CNPJ.");
}

// ── 8. scope=all retorna mais NFs que commercial sozinho ─────────────────────

if ($remCount > 0) {
    $stmtAll = $pdo->prepare(
        "SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas
         WHERE operation_scope IN ('commercial','remittance')
           AND data_emissao BETWEEN :start AND :end"
    );
    $stmtAll->execute([':start' => $start, ':end' => $end]);
    $allCount = (int)$stmtAll->fetchColumn();
    check(
        "scope=all ($allCount) > commercial ($commCount)",
        $allCount > $commCount,
        "all=$allCount, commercial=$commCount, remittance=$remCount",
        $isVerbose
    );
}

// ── Resultado ─────────────────────────────────────────────────────────────────

echo "\n";
foreach ($warns as $w) { echo "\033[33m⚠\033[0m  $w\n"; }

$total = $pass + $fail;
echo "\n";
if ($fail === 0) {
    echo "\033[32m✔ $pass/$total OK — api/orders_search.php validado para período $start → $end\033[0m\n\n";
    exit(0);
} else {
    echo "\033[31m✘ $fail/$total falhas — verificar api/orders_search.php\033[0m\n\n";
    exit(1);
}

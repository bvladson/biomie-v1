<?php

declare(strict_types=1);

/**
 * tools/dev/product_360_identity_consistency_audit.php — EST.R1.3 FASE 0
 *
 * Verifica consistência de identidade do produto entre:
 *   - topProductsCommercial (usa TRIM → product_code na URL)
 *   - Product360ReadRepository::summary (usa exact match)
 *
 * Detecta produtos que abririam o drawer com "Produto sem descricao"
 * ou com Resumo vazio mesmo com NFs nas outras abas.
 *
 * Uso:
 *   php tools/dev/product_360_identity_consistency_audit.php \
 *       --start=2026-01-01 --end=2026-05-30 [--product-code=XXX] [--pretty]
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;

$opts   = getopt('', ['start:', 'end:', 'product-code:', 'pretty']);
$pretty = array_key_exists('pretty', $opts);

$start       = (string) ($opts['start']        ?? date('Y-m-01'));
$end         = (string) ($opts['end']          ?? date('Y-m-d'));
$filterCode  = isset($opts['product-code']) ? trim((string) $opts['product-code']) : '';

$config = new Config();
$conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo    = Database::connect($config, $conn);

// ── Helpers ──────────────────────────────────────────────────────────────────

function p360_col(string $c, string $s): string
{
    $map = ['g' => '32', 'r' => '31', 'y' => '33', 'b' => '36'];
    return isset($map[$c]) ? "\033[{$map[$c]}m{$s}\033[0m" : $s;
}

function p360_pass(string $msg): void { echo '  [' . p360_col('g', 'PASS') . "]  {$msg}\n"; }
function p360_fail(string $msg): void { echo '  [' . p360_col('r', 'FAIL') . "]  {$msg}\n"; }
function p360_warn(string $msg): void { echo '  [' . p360_col('y', 'WARN') . "]  {$msg}\n"; }

// ── 1. Top products from API (TRIM) ──────────────────────────────────────────

try {
    $sql = "SELECT
                NULLIF(TRIM(COALESCE(i.codigo_produto, '')), '') AS product_code,
                COALESCE(NULLIF(TRIM(MAX(i.descricao_produto)), ''), 'Produto sem descricao') AS description,
                COUNT(DISTINCT i.nota_key) AS nfs_count,
                SUM(i.quantidade) AS qty_total
            FROM biomie_notas_fiscais_itens_canonicos i
            INNER JOIN biomie_notas_fiscais_canonicas n ON n.nota_key = i.nota_key
            WHERE n.status_fiscal = 'autorizada'
              AND COALESCE(n.data_competencia, n.data_emissao) BETWEEN :start AND :end
              AND n.operation_scope = 'commercial'
            GROUP BY NULLIF(TRIM(COALESCE(i.codigo_produto, '')), '')
            ORDER BY qty_total DESC
            LIMIT 50";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':start' => $start, ':end' => $end]);
    $apiProducts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    echo "Erro ao buscar produtos: " . $e->getMessage() . "\n";
    exit(1);
}

if ($filterCode !== '') {
    $apiProducts = array_filter($apiProducts, fn($r) => stripos((string)($r['product_code'] ?? ''), $filterCode) !== false);
    $apiProducts = array_values($apiProducts);
}

// ── 2. For each product, check exact match vs TRIM match ─────────────────────

$issues = [];
$passCount = 0;

$hr = str_repeat('─', 72);

if ($pretty) {
    echo "\n" . str_repeat('═', 72) . "\n";
    echo "  Product 360 Identity Consistency Audit — EST.R1.3\n";
    echo "  Período: {$start} → {$end}\n";
    echo str_repeat('═', 72) . "\n\n";
}

foreach ($apiProducts as $r) {
    $apiCode     = (string) ($r['product_code'] ?? '');
    $description = (string) ($r['description']  ?? '');
    $nfsApi      = (int)    ($r['nfs_count']     ?? 0);

    if ($apiCode === '') continue;

    // Check 1: exact match (what Product360ReadRepository uses)
    try {
        $s1 = $pdo->prepare(
            "SELECT COUNT(DISTINCT i.nota_key) AS cnt
             FROM biomie_notas_fiscais_itens_canonicos i
             INNER JOIN biomie_notas_fiscais_canonicas n ON n.nota_key = i.nota_key
             WHERE n.status_fiscal = 'autorizada'
               AND n.data_competencia BETWEEN :start AND :end
               AND i.codigo_produto = :code"
        );
        $s1->execute([':start' => $start, ':end' => $end, ':code' => $apiCode]);
        $exactNfs = (int) $s1->fetchColumn();
    } catch (Throwable) {
        $exactNfs = -1;
    }

    // Check 2: TRIM match (what identity resolver should use)
    try {
        $s2 = $pdo->prepare(
            "SELECT COUNT(DISTINCT i.nota_key) AS cnt
             FROM biomie_notas_fiscais_itens_canonicos i
             INNER JOIN biomie_notas_fiscais_canonicas n ON n.nota_key = i.nota_key
             WHERE n.status_fiscal = 'autorizada'
               AND COALESCE(n.data_competencia, n.data_emissao) BETWEEN :start AND :end
               AND TRIM(i.codigo_produto) = :code"
        );
        $s2->execute([':start' => $start, ':end' => $end, ':code' => $apiCode]);
        $coalesceNfs = (int) $s2->fetchColumn();
    } catch (Throwable) {
        $coalesceNfs = -1;
    }

    // Check 3: does DB have spaces in codigo_produto?
    try {
        $s3 = $pdo->prepare(
            "SELECT codigo_produto FROM biomie_notas_fiscais_itens_canonicos
             WHERE TRIM(codigo_produto) = :code AND codigo_produto != :code LIMIT 1"
        );
        $s3->execute([':code' => $apiCode]);
        $spacyCode = $s3->fetchColumn();
        $hasSpaces = $spacyCode !== false;
    } catch (Throwable) {
        $hasSpaces = false;
    }

    // Check 4: description fallback risk
    $descIsPlaceholder = ($description === 'Produto sem descricao' || $description === '');

    // Check 5: NF divergence between API count and exact match
    $nfsDivergence = ($coalesceNfs > 0 && $exactNfs === 0);

    if ($pretty) {
        $label = mb_substr($description, 0, 35);
        if (mb_strlen($description) > 35) $label .= '…';
        echo "  {$apiCode} | {$label}\n";
        echo "    NFs (API/TRIM): {$nfsApi} | NFs (exact match, data_comp): {$exactNfs} | NFs (TRIM+COALESCE): {$coalesceNfs}\n";
    }

    if ($descIsPlaceholder) {
        $issues[] = "WARN: produto '{$apiCode}' tem descrição placeholder '{$description}'";
        p360_warn("  Descrição placeholder: {$apiCode} → '{$description}'");
    }

    if ($hasSpaces) {
        $issues[] = "FAIL: produto '{$apiCode}' tem espaços no DB: '{$spacyCode}'";
        p360_fail("  Espaço em codigo_produto: '{$apiCode}' → '{$spacyCode}' no DB");
    }

    if ($nfsDivergence) {
        $issues[] = "FAIL: produto '{$apiCode}' tem {$coalesceNfs} NFs com COALESCE mas {$exactNfs} com data_competencia exato";
        p360_fail("  Divergência data: '{$apiCode}' exact={$exactNfs} coalesce={$coalesceNfs}");
    }

    if (!$hasSpaces && !$nfsDivergence && !$descIsPlaceholder) {
        if ($pretty) p360_pass("  {$apiCode} consistente");
        $passCount++;
    }

    if ($pretty) echo "\n";
}

// ── Summary ───────────────────────────────────────────────────────────────────

$total = count($apiProducts);
$failCount = count($issues);

if ($pretty) {
    echo str_repeat('─', 72) . "\n";
    if ($failCount === 0) {
        echo '  [' . p360_col('g', '✔ PASS') . "]  {$passCount}/{$total} produtos com identidade consistente\n";
    } else {
        echo '  [' . p360_col('r', '✗ FAIL') . "]  {$failCount} problema(s) detectado(s) em {$total} produtos\n";
        foreach ($issues as $issue) {
            echo "    → {$issue}\n";
        }
    }
    echo str_repeat('═', 72) . "\n\n";
}

$result = [
    'ok'         => $failCount === 0,
    'audit'      => 'product_360_identity_consistency',
    'period'     => ['start' => $start, 'end' => $end],
    'total'      => $total,
    'pass'       => $passCount,
    'issues'     => $issues,
    'issue_count' => $failCount,
];

if (!$pretty) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

exit($failCount === 0 ? 0 : 1);

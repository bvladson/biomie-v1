<?php

/**
 * tools/dev/geo_sales_assert_may2026.php — Homologação Maio 2026 (pipeline geo/vendas).
 *
 * Verifica se o banco de dados contém os mínimos esperados com base no
 * extrato Excel consolidado(3).xlsx fornecido pelo usuário.
 *
 * Mínimos obrigatórios (1–31 Maio 2026):
 *   - NFs autorizadas   >= 70   (Excel: 78)
 *   - Valor mercadorias >= 120 000  (Excel: R$130.109,81)
 *   - Bairros norm.     >= 50   (Excel: 56 normalizados)
 *   - Clientes geo      >= 50
 *   - mart_cliente_periodo nfes >= 70 para competência 2026-05
 *   - mart_compradores_por_geo faturamento >= 120 000 para 2026-05
 *   - best_neighborhood != '' e != 'Aerolandia' e faturamento > 0
 *
 * Uso:
 *   php tools/dev/geo_sales_assert_may2026.php
 *   php tools/dev/geo_sales_assert_may2026.php --json
 *   php tools/dev/geo_sales_assert_may2026.php --start=2026-05-01 --end=2026-05-31
 */

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Logger;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$root   = dirname(__DIR__, 2);
$config = new Config();
$logger = new Logger($root . '/storage/logs/biomie.log');

$opts      = getopt('', ['start:', 'end:', 'json', 'help']);
$startDate = $opts['start'] ?? '2026-05-01';
$endDate   = $opts['end']   ?? '2026-05-31';
$comp      = '2026-05';
$jsonMode  = isset($opts['json']);

if (isset($opts['help'])) {
    echo "Usage: php tools/dev/geo_sales_assert_may2026.php [--start=YYYY-MM-DD] [--end=YYYY-MM-DD] [--json]\n";
    exit(0);
}

// ── Assertions ────────────────────────────────────────────────────────────────

/** @var list<array{name:string,passed:bool,actual:mixed,expected:mixed,message:string}> */
$assertions = [];
$allPass = true;

function assert_check(
    array &$assertions,
    bool  &$allPass,
    string $name,
    mixed $actual,
    string $op,
    mixed $expected,
    string $message
): void {
    $passed = match ($op) {
        '>='    => $actual >= $expected,
        '<='    => $actual <= $expected,
        '>'     => $actual > $expected,
        '<'     => $actual < $expected,
        '=='    => $actual == $expected,
        '!='    => $actual != $expected,
        'not_empty' => $actual !== '' && $actual !== null && $actual !== 0,
        default => false,
    };

    if (!$passed) {
        $allPass = false;
    }

    $assertions[] = [
        'name'     => $name,
        'passed'   => $passed,
        'actual'   => $actual,
        'expected' => "{$op} {$expected}",
        'message'  => $passed ? "OK: {$message}" : "FAIL: {$message} (actual={$actual})",
    ];
}

// ── Connect ───────────────────────────────────────────────────────────────────

try {
    [$pdo] = Database::getGeoConnection($config);
} catch (\Throwable $e) {
    $result = [
        'ok' => false,
        'error' => 'Conexão com banco falhou: ' . $e->getMessage(),
        'assertions' => [],
    ];
    if ($jsonMode) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo "ERRO: " . $result['error'] . "\n";
    }
    exit(1);
}

// ── A1: NFs autorizadas no período ────────────────────────────────────────────

try {
    $nfsAutorizadas = (int) $pdo->prepare(
        "SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas
         WHERE COALESCE(data_competencia, data_emissao) BETWEEN :s AND :e
           AND status_fiscal = 'autorizada'"
    )->execute(['s' => $startDate, 'e' => $endDate]) ? $pdo->query(
        "SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas
         WHERE COALESCE(data_competencia, data_emissao) BETWEEN '{$startDate}' AND '{$endDate}'
           AND status_fiscal = 'autorizada'"
    )->fetchColumn() : 0;
} catch (\Throwable) {
    $nfsAutorizadas = -1;
}

assert_check($assertions, $allPass,
    'nfs_autorizadas_l1',
    $nfsAutorizadas,
    '>=', 70,
    "NFs autorizadas em biomie_notas_fiscais_canonicas >= 70 (Excel: 78)"
);

// ── A2: Valor mercadorias ─────────────────────────────────────────────────────

try {
    $mercStmt = $pdo->query(
        "SELECT COALESCE(SUM(valor_mercadorias), 0)
         FROM biomie_notas_fiscais_canonicas
         WHERE COALESCE(data_competencia, data_emissao) BETWEEN '{$startDate}' AND '{$endDate}'
           AND status_fiscal = 'autorizada'"
    );
    $valorMerc = $mercStmt !== false ? (float) $mercStmt->fetchColumn() : 0.0;
} catch (\Throwable) {
    $valorMerc = 0.0;
}

assert_check($assertions, $allPass,
    'valor_mercadorias_l1',
    round($valorMerc, 2),
    '>=', 120000.0,
    "Valor mercadorias NFs autorizadas >= R\$120.000 (Excel: R\$130.109,81)"
);

// ── A3: mart_cliente_periodo tem dados para 2026-05 ───────────────────────────

try {
    $martNfes = (int) $pdo->query(
        "SELECT COALESCE(SUM(nfes_emitidas), 0) FROM mart_cliente_periodo WHERE competencia = '{$comp}'"
    )->fetchColumn();
} catch (\Throwable) {
    $martNfes = -1;
}

assert_check($assertions, $allPass,
    'mart_cliente_periodo_nfes',
    $martNfes,
    '>=', 70,
    "mart_cliente_periodo.nfes_emitidas para 2026-05 >= 70 (mesmo universo que L1)"
);

// ── A4: mart_cliente_periodo valor_nfe ────────────────────────────────────────

try {
    $martValorNfe = (float) $pdo->query(
        "SELECT COALESCE(SUM(valor_nfe), 0) FROM mart_cliente_periodo WHERE competencia = '{$comp}'"
    )->fetchColumn();
} catch (\Throwable) {
    $martValorNfe = 0.0;
}

assert_check($assertions, $allPass,
    'mart_cliente_periodo_valor_nfe',
    round($martValorNfe, 2),
    '>=', 120000.0,
    "mart_cliente_periodo.valor_nfe para 2026-05 >= R\$120.000"
);

// ── A5: mart_compradores_por_geo faturamento ──────────────────────────────────

try {
    $geoFat = (float) $pdo->query(
        "SELECT COALESCE(SUM(faturamento), 0) FROM mart_compradores_por_geo WHERE competencia = '{$comp}'"
    )->fetchColumn();
} catch (\Throwable) {
    $geoFat = 0.0;
}

assert_check($assertions, $allPass,
    'geo_mart_faturamento',
    round($geoFat, 2),
    '>=', 120000.0,
    "mart_compradores_por_geo.faturamento para 2026-05 >= R\$120.000"
);

// ── A6: bairros com venda no geo mart ────────────────────────────────────────

try {
    $bairrosComVenda = (int) $pdo->query(
        "SELECT COUNT(DISTINCT bairro_norm)
         FROM mart_compradores_por_geo
         WHERE competencia = '{$comp}'
           AND bairro_norm IS NOT NULL AND bairro_norm != '' AND UPPER(bairro_norm) != 'NAO INFORMADO'"
    )->fetchColumn();
} catch (\Throwable) {
    $bairrosComVenda = 0;
}

assert_check($assertions, $allPass,
    'bairros_com_venda_geo_mart',
    $bairrosComVenda,
    '>=', 50,
    "Bairros distintos com venda em mart_compradores_por_geo >= 50 (Excel normalized: 56)"
);

// ── A7: clientes com geo enriquecido ─────────────────────────────────────────

try {
    $clientesGeo = (int) $pdo->query(
        "SELECT COUNT(*) FROM biomie_clientes_geo_dim WHERE bairro_norm IS NOT NULL AND bairro_norm != ''"
    )->fetchColumn();
} catch (\Throwable) {
    $clientesGeo = 0;
}

assert_check($assertions, $allPass,
    'clientes_com_bairro_norm',
    $clientesGeo,
    '>=', 50,
    "Clientes com bairro_norm em biomie_clientes_geo_dim >= 50"
);

// ── A8: best neighborhood não é Aerolândia / não é vazio ─────────────────────

$bestNeighborhood = null;
$bestFaturamento  = 0.0;
try {
    $bestRow = $pdo->query(
        "SELECT bairro_norm, SUM(faturamento) AS fat
         FROM mart_compradores_por_geo
         WHERE competencia = '{$comp}'
           AND bairro_norm IS NOT NULL AND bairro_norm != '' AND UPPER(bairro_norm) != 'NAO INFORMADO'
         GROUP BY bairro_norm ORDER BY fat DESC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);

    if ($bestRow) {
        $bestNeighborhood = (string) ($bestRow['bairro_norm'] ?? '');
        $bestFaturamento  = (float) ($bestRow['fat'] ?? 0);
    }
} catch (\Throwable) {}

assert_check($assertions, $allPass,
    'best_neighborhood_not_empty',
    $bestNeighborhood,
    'not_empty', '',
    "Melhor bairro não é vazio (indica que geo mart tem dados reais)"
);

assert_check($assertions, $allPass,
    'best_neighborhood_not_aerolandia',
    strtolower((string) $bestNeighborhood),
    '!=', 'aerolandia',
    "Melhor bairro não é Aerolândia (que é alfabeticamente primeiro quando tudo é zero)"
);

assert_check($assertions, $allPass,
    'best_neighborhood_faturamento_positive',
    $bestFaturamento,
    '>', 0.0,
    "Melhor bairro tem faturamento > 0 (Excel top: Aldeota)"
);

// ── Summary ───────────────────────────────────────────────────────────────────

$passed = count(array_filter($assertions, fn ($a) => $a['passed']));
$failed = count(array_filter($assertions, fn ($a) => !$a['passed']));
$total  = count($assertions);

$result = [
    'ok'          => $allPass,
    'passed'      => $passed,
    'failed'      => $failed,
    'total'       => $total,
    'period'      => ['start' => $startDate, 'end' => $endDate, 'competencia' => $comp],
    'best_neighborhood_found' => ['name' => $bestNeighborhood, 'faturamento' => $bestFaturamento],
    'assertions'  => $assertions,
    'generated_at' => date('c'),
];

if ($jsonMode) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit($allPass ? 0 : 1);
}

echo "\n=== HOMOLOGAÇÃO GEO/VENDAS — MAIO 2026 ===\n";
echo "Período: {$startDate} a {$endDate} | Competência: {$comp}\n\n";

foreach ($assertions as $a) {
    $status = $a['passed'] ? '[OK  ]' : '[FAIL]';
    echo "{$status} {$a['name']}: {$a['message']}\n";
}

echo "\n";
echo $allPass
    ? "RESULTADO: PASSOU ({$passed}/{$total} assertions)\n"
    : "RESULTADO: FALHOU ({$failed}/{$total} assertions falharam)\n";

if (!$allPass) {
    echo "\nActions necessárias:\n";
    if ($martNfes <= 0) {
        echo "  1. Materializar gold mart: php cron/materialize_competencia_snapsh.php (ou equivalente)\n";
    }
    if ($geoFat <= 0.0) {
        echo "  2. Reconstruir geo mart: php cron/geo_materialize.php (ou equivalente)\n";
    }
    if ($clientesGeo < 50) {
        echo "  3. Enriquecer clientes: php cron/geo_enrich_clients.php\n";
    }
}

echo "\n";
exit($allPass ? 0 : 1);

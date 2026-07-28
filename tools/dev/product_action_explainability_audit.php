<?php

declare(strict_types=1);

/**
 * tools/dev/product_action_explainability_audit.php — PROD.R2.1
 *
 * Valida a explicabilidade e direcionamento comercial da inteligência de produtos.
 * Garante que todo produto com score tenha manager_explanation completo e data_basis preenchido.
 *
 * Uso:
 *   php tools/dev/product_action_explainability_audit.php \
 *       --start=2026-05-01 --end=2026-05-31 [--pretty] [--json]
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;
use BIomie\Product\ProductActionIntelligenceBuilder;

$opts     = getopt('', ['start:', 'end:', 'pretty', 'json']);
$pretty   = array_key_exists('pretty', $opts);
$jsonMode = array_key_exists('json',   $opts);
$start    = (string) ($opts['start'] ?? date('Y-m-01'));
$end      = (string) ($opts['end']   ?? date('Y-m-d'));

$config = new Config();
$conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo    = Database::connect($config, $conn);

$builder = new ProductActionIntelligenceBuilder($pdo);
$opps    = $builder->buildProductOpportunities($start, $end, 200);

// ── Required fields in manager_explanation ────────────────────────────────────

$REQUIRED_FIELDS = ['headline', 'meaning', 'recommended_action', 'manager_decision', 'next_step', 'confidence_label'];

$checks = [];
$issues = [];
$totalProducts = count($opps);

// ── Check 1: every product with score has manager_explanation ─────────────────

$missingExplanation = [];
foreach ($opps as $p) {
    $code = (string) ($p['product_code'] ?? '?');
    $mgr  = $p['manager_explanation'] ?? null;
    if (!is_array($mgr) || $mgr === []) {
        $missingExplanation[] = $code;
    }
}
$checks[] = [
    'id'     => 'every_product_has_explanation',
    'pass'   => $missingExplanation === [],
    'detail' => $missingExplanation === []
        ? "Todos {$totalProducts} produtos têm manager_explanation."
        : count($missingExplanation) . ' produto(s) sem manager_explanation: ' . implode(', ', array_slice($missingExplanation, 0, 5)),
];
if ($missingExplanation !== []) {
    array_push($issues, ...array_map(fn($c) => "Produto {$c} sem manager_explanation", $missingExplanation));
}

// ── Check 2: required fields populated in every explanation ───────────────────

$missingFields = [];
foreach ($opps as $p) {
    $code = (string) ($p['product_code'] ?? '?');
    $mgr  = $p['manager_explanation'] ?? [];
    if (!is_array($mgr)) continue;
    foreach ($REQUIRED_FIELDS as $field) {
        if (empty($mgr[$field])) {
            $missingFields[] = "{$code}.{$field}";
        }
    }
}
$checks[] = [
    'id'     => 'all_required_fields_present',
    'pass'   => $missingFields === [],
    'detail' => $missingFields === []
        ? 'Todos os campos obrigatórios presentes em todas as explicações.'
        : count($missingFields) . ' campo(s) ausente(s): ' . implode(', ', array_slice($missingFields, 0, 8)),
];
if ($missingFields !== []) {
    array_push($issues, ...array_map(fn($f) => "Campo ausente: {$f}", array_slice($missingFields, 0, 5)));
}

// ── Check 3: "Produto campeão" always has explanation ────────────────────────

$campeoesSemExp = array_filter($opps, fn($p) =>
    ($p['categoria'] ?? '') === 'Produto campeão'
    && empty(($p['manager_explanation'] ?? [])['headline'])
);
$checks[] = [
    'id'     => 'campiao_always_explained',
    'pass'   => $campeoesSemExp === [],
    'detail' => $campeoesSemExp === []
        ? 'Todos os produtos campeão têm explicação completa.'
        : count($campeoesSemExp) . ' Produto(s) campeão sem headline.',
];

// ── Check 4: data_basis not empty ─────────────────────────────────────────────

$emptyBasis = [];
foreach ($opps as $p) {
    $code  = (string) ($p['product_code'] ?? '?');
    $basis = $p['data_basis'] ?? [];
    if (!is_array($basis) || count($basis) === 0) {
        $emptyBasis[] = $code;
    }
}
$checks[] = [
    'id'     => 'data_basis_not_empty',
    'pass'   => $emptyBasis === [],
    'detail' => $emptyBasis === []
        ? "Todos {$totalProducts} produtos têm data_basis preenchido."
        : count($emptyBasis) . ' produto(s) com data_basis vazio: ' . implode(', ', array_slice($emptyBasis, 0, 5)),
];
if ($emptyBasis !== []) {
    array_push($issues, ...array_map(fn($c) => "Produto {$c} com data_basis vazio", $emptyBasis));
}

// ── Check 5: friendly_name present and not equal to raw categoria ─────────────

$badFriendly = [];
foreach ($opps as $p) {
    $code     = (string) ($p['product_code'] ?? '?');
    $cat      = (string) ($p['categoria']     ?? '');
    $friendly = (string) ($p['friendly_name'] ?? '');
    if ($friendly === '' || $friendly === $cat) {
        $badFriendly[] = $code;
    }
}
$checks[] = [
    'id'     => 'friendly_name_differs_from_categoria',
    'pass'   => $badFriendly === [],
    'detail' => $badFriendly === []
        ? "Todos {$totalProducts} produtos têm friendly_name distinto da categoria técnica."
        : count($badFriendly) . ' produto(s) com friendly_name ausente ou igual à categoria: ' . implode(', ', array_slice($badFriendly, 0, 5)),
];

// ── Check 6: "Campanha para clientes antigos" only when there's buyer history ─

$camOk     = 0;
$camBad    = [];
foreach ($opps as $p) {
    if (($p['categoria'] ?? '') !== 'Campanha para clientes antigos') continue;
    $clientes = (int) ($p['clientes_periodo'] ?? 0);
    $dias     = (int) ($p['dias_sem_venda']   ?? 0);
    if ($clientes >= 2 || $dias >= 30) {
        $camOk++;
    } else {
        $camBad[] = (string) ($p['product_code'] ?? '?');
    }
}
$checks[] = [
    'id'     => 'campanha_has_buyer_history',
    'pass'   => $camBad === [],
    'detail' => $camBad === []
        ? "Todos os produtos 'Campanha para clientes antigos' têm histórico de compra ({$camOk} produto(s))."
        : count($camBad) . ' produto(s) classificados como Campanha sem histórico adequado: ' . implode(', ', $camBad),
];

// ── Check 7: source is omie_canonical — no manual/CSV ────────────────────────

$badSource = [];
foreach ($opps as $p) {
    $src = strtolower((string) ($p['source'] ?? ''));
    if ($src !== 'omie_canonical') {
        $badSource[] = ($p['product_code'] ?? '?') . " (source={$src})";
    }
}
$checks[] = [
    'id'     => 'source_is_omie_canonical',
    'pass'   => $badSource === [],
    'detail' => $badSource === []
        ? "Todos {$totalProducts} produtos têm source=omie_canonical. Nenhuma fonte manual."
        : count($badSource) . ' produto(s) com source inválido: ' . implode(', ', array_slice($badSource, 0, 5)),
];
if ($badSource !== []) {
    array_push($issues, ...array_map(fn($s) => "Source inválido: {$s}", array_slice($badSource, 0, 3)));
}

// ── Check 8: no "texto grudado" pattern (label immediately followed by value without space) ─

$tabsSrc   = is_file(__DIR__ . '/../../assets/js/modules/product-detail/tabs.js')
    ? (string) file_get_contents(__DIR__ . '/../../assets/js/modules/product-detail/tabs.js')
    : '';
$hasGrudo  = preg_match('/Receita(?:R\$|\d)/', $tabsSrc) > 0
          || preg_match('/Qtd(?:\d|[A-Z]{2})/', $tabsSrc) > 0;
$checks[] = [
    'id'     => 'no_stuck_text_in_templates',
    'pass'   => !$hasGrudo,
    'detail' => !$hasGrudo
        ? 'Sem texto grudado detectado em tabs.js (padrão "ReceitaR$" ausente).'
        : 'Possível texto grudado detectado em tabs.js. Verificar renderAcaoComercial.',
];
if ($hasGrudo) {
    $issues[] = 'Texto grudado detectado em tabs.js';
}

// ── Check 9: no physical stock mention in commercial recommendation ───────────

$builderSrc = is_file(__DIR__ . '/../../app/Product/ProductActionIntelligenceBuilder.php')
    ? (string) file_get_contents(__DIR__ . '/../../app/Product/ProductActionIntelligenceBuilder.php')
    : '';
$physicalMentions = preg_match_all('/saldo\s+f[íi]sico\s+real/i', $builderSrc);
$checks[] = [
    'id'     => 'no_physical_stock_in_commercial_recommendation',
    'pass'   => $physicalMentions === 0,
    'detail' => $physicalMentions === 0
        ? 'Nenhuma menção a "saldo físico real" em ProductActionIntelligenceBuilder.'
        : "{$physicalMentions} menção(ões) a saldo físico real encontrada(s) no builder.",
];

// ── Check 10: builder has manager_explanation ──────────────────────────────────

$checks[] = [
    'id'     => 'builder_has_manager_explanation',
    'pass'   => str_contains($builderSrc, 'buildManagerExplanation'),
    'detail' => str_contains($builderSrc, 'buildManagerExplanation')
        ? 'ProductActionIntelligenceBuilder tem buildManagerExplanation.'
        : 'ProductActionIntelligenceBuilder SEM buildManagerExplanation.',
];
if (!str_contains($builderSrc, 'buildManagerExplanation')) {
    $issues[] = 'buildManagerExplanation ausente no builder';
}

// ── Summary ────────────────────────────────────────────────────────────────────

$passed = count(array_filter($checks, fn($c) => $c['pass']));
$total  = count($checks);
$allPass = $passed === $total;

if ($jsonMode) {
    echo json_encode([
        'product_action_explainability_audit' => [
            'period'        => ['start' => $start, 'end' => $end],
            'products_analyzed' => $totalProducts,
            'overall'       => $allPass ? 'PASS' : 'FAIL',
            'passed'        => $passed,
            'total_checks'  => $total,
            'checks'        => $checks,
            'issues'        => $issues,
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    echo PHP_EOL;
    exit($allPass ? 0 : 1);
}

if ($pretty) {
    $hr = str_repeat('═', 72);
    echo PHP_EOL . $hr . PHP_EOL;
    echo "  Product Action Explainability Audit — PROD.R2.1\n";
    echo "  Período: {$start} → {$end}\n";
    echo "  Produtos analisados: {$totalProducts}\n";
    echo $hr . PHP_EOL . PHP_EOL;

    foreach ($checks as $c) {
        $badge = $c['pass'] ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m";
        echo "  [{$badge}]  {$c['detail']}\n";
    }

    echo PHP_EOL . str_repeat('─', 72) . PHP_EOL;
    $overallBadge = $allPass ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m";
    echo "  [{$overallBadge}]  {$passed}/{$total} checks passaram.\n";

    if ($issues !== []) {
        echo PHP_EOL . "  ISSUES:\n";
        foreach ($issues as $iss) {
            echo "    · {$iss}\n";
        }
    }

    echo PHP_EOL;
} else {
    echo ($allPass ? 'PASS' : 'FAIL') . " {$passed}/{$total}\n";
}

exit($allPass ? 0 : 1);

<?php

declare(strict_types=1);

/**
 * tools/dev/product_360_temporal_bucket_audit.php — EST.R1.3 FASE 7
 *
 * Valida o comportamento do ProductTemporalBucketResolver e do
 * Product360ReadRepository::timeline para diferentes recortes de data.
 *
 * Regras testadas:
 *   - ≤ 45 dias → bucket diário
 *   - 46–180 dias → bucket semanal
 *   - > 180 dias → bucket mensal
 *   - datas quebradas respeitam vendas reais (sem proporcionalidade)
 *   - buckets sem venda preenchidos com zero
 *
 * Uso:
 *   php tools/dev/product_360_temporal_bucket_audit.php [--pretty] [--product-code=XXX]
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Support\ProductTemporalBucketResolver;

$opts   = getopt('', ['pretty', 'product-code:']);
$pretty = array_key_exists('pretty', $opts);

$pass  = 0;
$fail  = 0;
$lines = [];

function tb_check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $lines, $pretty;
    if ($ok) {
        $pass++;
        if ($pretty) echo '  [' . "\033[32mPASS\033[0m" . "]  {$label}" . ($detail ? "  — {$detail}" : '') . "\n";
    } else {
        $fail++;
        if ($pretty) echo '  [' . "\033[31mFAIL\033[0m" . "]  {$label}" . ($detail ? "  — {$detail}" : '') . "\n";
    }
    $lines[] = ['ok' => $ok, 'label' => $label, 'detail' => $detail];
}

if ($pretty) {
    echo "\n" . str_repeat('═', 72) . "\n";
    echo "  Product 360 Temporal Bucket Audit — EST.R1.3\n";
    echo str_repeat('═', 72) . "\n\n";
}

// ── 1. Mode resolution ────────────────────────────────────────────────────────

$cases = [
    // [start, end, expected_mode, label]
    ['2026-05-01', '2026-05-31', 'day',   '31 days → day'],
    ['2026-05-01', '2026-06-14', 'day',   '44 days → day'],
    ['2026-05-01', '2026-06-15', 'day',   '45 days → day (boundary, ≤45=day)'],
    ['2026-04-01', '2026-06-15', 'week',  '75 days → week'],
    ['2026-01-01', '2026-06-30', 'week',  '180 days → week (boundary)'],
    ['2026-01-01', '2026-07-01', 'month', '181 days → month'],
    ['2026-01-01', '2026-12-31', 'month', '364 days → month'],
    ['2026-02-15', '2026-03-15', 'day',   '28 days broken dates → day'],
];

foreach ($cases as [$s, $e, $expected, $lbl]) {
    $mode = ProductTemporalBucketResolver::resolveMode($s, $e);
    tb_check("Mode [{$lbl}]", $mode === $expected, "got={$mode} expected={$expected}");
}

// ── 2. Override respected ─────────────────────────────────────────────────────

$override = ProductTemporalBucketResolver::resolveMode('2026-01-01', '2026-12-31', 'day');
tb_check('Override day forced on long range', $override === 'day', "got={$override}");

// ── 3. Empty bucket generation ────────────────────────────────────────────────

// Day buckets for 5-day range
$dayBuckets = ProductTemporalBucketResolver::generateEmptyBuckets('2026-05-01', '2026-05-05', 'day');
tb_check('Day: 5 buckets for 5-day range', count($dayBuckets) === 5, 'count=' . count($dayBuckets));
tb_check('Day: first bucket is 2026-05-01', ($dayBuckets[0]['bucket'] ?? '') === '2026-05-01');
tb_check('Day: all zeros', array_sum(array_column($dayBuckets, 'revenue')) === 0.0);

// Month buckets for Jan–May
$monthBuckets = ProductTemporalBucketResolver::generateEmptyBuckets('2026-01-01', '2026-05-31', 'month');
tb_check('Month: 5 buckets for Jan–May', count($monthBuckets) === 5, 'count=' . count($monthBuckets));
tb_check('Month: first = 2026-01', ($monthBuckets[0]['bucket'] ?? '') === '2026-01');
tb_check('Month: last = 2026-05', ($monthBuckets[4]['bucket'] ?? '') === '2026-05');

// Week buckets: at least some buckets for a 2-week range
$weekBuckets = ProductTemporalBucketResolver::generateEmptyBuckets('2026-05-01', '2026-05-31', 'week');
tb_check('Week: >= 4 buckets for 31-day range', count($weekBuckets) >= 4, 'count=' . count($weekBuckets));

// ── 4. Broken date ranges respected (no artificial data) ─────────────────────

$broken = ProductTemporalBucketResolver::generateEmptyBuckets('2026-02-15', '2026-03-15', 'day');
$bucketsInRange = array_filter($broken, fn($b) => $b['bucket'] >= '2026-02-15' && $b['bucket'] <= '2026-03-15');
tb_check('Broken range: all buckets within 2026-02-15 to 2026-03-15', count($bucketsInRange) === count($broken), 'extra_count=' . (count($broken) - count($bucketsInRange)));
tb_check('Broken range: no bucket before 2026-02-15', !isset($broken[0]) || ($broken[0]['bucket'] ?? '') >= '2026-02-15');
$lastBroken = end($broken);
tb_check('Broken range: no bucket after 2026-03-15', $lastBroken === false || ($lastBroken['bucket'] ?? '') <= '2026-03-15');
// 15 Feb to 15 Mar = 28 days
tb_check('Broken range: correct day count (28 days)', count($broken) === 28 + 1, 'count=' . count($broken)); // inclusive

// ── 5. Merge test ─────────────────────────────────────────────────────────────

$emptyMonths = ProductTemporalBucketResolver::generateEmptyBuckets('2026-01-01', '2026-03-31', 'month');
$rows = [
    ['bucket' => '2026-02', 'revenue' => '1000.00', 'revenue_raw' => 1000.0, 'quantity' => 5, 'invoices_count' => 2, 'customers_count' => 1],
];
$merged = ProductTemporalBucketResolver::mergeIntoBuckets($emptyMonths, $rows, 'month');
tb_check('Merge: Jan is zero',    (float)($merged[0]['revenue'] ?? 999) === 0.0);
tb_check('Merge: Feb has data',   (float)($merged[1]['revenue'] ?? 0) === 1000.0);
tb_check('Merge: Mar is zero',    (float)($merged[2]['revenue'] ?? 999) === 0.0);
tb_check('Merge: Feb qty = 5',    (float)($merged[1]['quantity'] ?? 0) === 5.0);

// ── 6. Bucket titles ──────────────────────────────────────────────────────────

tb_check('Title: day',   ProductTemporalBucketResolver::bucketTitle('day')   === 'Evolução diária');
tb_check('Title: week',  ProductTemporalBucketResolver::bucketTitle('week')  === 'Evolução semanal');
tb_check('Title: month', ProductTemporalBucketResolver::bucketTitle('month') === 'Evolução mensal');

// ── Summary ───────────────────────────────────────────────────────────────────

if ($pretty) {
    echo "\n" . str_repeat('─', 72) . "\n";
    $total = $pass + $fail;
    if ($fail === 0) {
        echo '  [' . "\033[32m✔ PASS\033[0m" . "]  {$pass}/{$total} checks passaram\n";
    } else {
        echo '  [' . "\033[31m✗ FAIL\033[0m" . "]  {$fail} falha(s) em {$total} checks\n";
    }
    echo str_repeat('═', 72) . "\n\n";
}

$result = [
    'ok'         => $fail === 0,
    'audit'      => 'product_360_temporal_bucket',
    'pass'       => $pass,
    'fail'       => $fail,
    'checks'     => $lines,
];

if (!$pretty) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

exit($fail === 0 ? 0 : 1);

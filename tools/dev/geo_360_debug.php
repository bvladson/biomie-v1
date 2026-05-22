<?php

/**
 * tools/dev/geo_360_debug.php — Geo 360 CLI diagnostic.
 *
 * Usage:
 *   php tools/dev/geo_360_debug.php --neighborhood=BAIRRO_NORM [--city=fortaleza] [--start=YYYY-MM-DD] [--end=YYYY-MM-DD] [--json] [--explain]
 *
 * Output:
 *   - Summary calculado do mart_compradores_por_geo
 *   - Top 5 compradores
 *   - CNAEs
 *   - Evolução temporal
 *   - Freshness e data quality
 *   - Alertas automáticos
 *   - Com --explain: índices recomendados
 */

declare(strict_types=1);

use BIomie\Database;
use BIomie\Modules\Sales\Application\Geo\Geo360ReadService;
use BIomie\Modules\Sales\Infrastructure\Geo360ReadRepository;

require_once __DIR__ . '/../../app/bootstrap_cli.php';

// ── Parse CLI args ─────────────────────────────────────────────────────────

$opts             = getopt('', ['neighborhood:', 'city:', 'start:', 'end:', 'json', 'explain']);
$neighborhoodKey  = trim((string) ($opts['neighborhood'] ?? ''));
$city             = trim((string) ($opts['city'] ?? 'fortaleza'));
$start            = trim((string) ($opts['start'] ?? date('Y-m-01')));
$end              = trim((string) ($opts['end']   ?? date('Y-m-d')));
$jsonMode         = isset($opts['json']);
$explain          = isset($opts['explain']);

if ($neighborhoodKey === '') {
    fwrite(STDERR, "Uso: php tools/dev/geo_360_debug.php --neighborhood=BAIRRO_NORM [--city=fortaleza] [--start=YYYY-MM-DD] [--end=YYYY-MM-DD] [--json] [--explain]\n");
    exit(1);
}

// ── Database ───────────────────────────────────────────────────────────────

$connectionData = Database::getGeoConnection($config);
$pdo = $connectionData[0];

$repo    = new Geo360ReadRepository($pdo);
$service = new Geo360ReadService($repo);

// ── Fetch full payload ────────────────────────────────────────────────────

$payload = $service->build($neighborhoodKey, $neighborhoodKey, $city, $start, $end, 50, null);

// ── Alerts ────────────────────────────────────────────────────────────────

$alerts  = [];
$revenue = (float) ($payload['summary']['revenue'] ?? 0);
$martRows = (int) ($payload['data_quality']['mart_rows_count'] ?? 0);
$freshness = $payload['data_quality']['freshness_status'] ?? 'unknown';

if ($martRows === 0 && $revenue > 0) {
    $alerts[] = ['level' => 'warning', 'msg' => "mart_compradores_por_geo nao tem linhas para {$neighborhoodKey} mas summary retornou receita. Inconsistencia de dados."];
}
if ($revenue <= 0.0) {
    $alerts[] = ['level' => 'warning', 'msg' => "Nenhuma receita encontrada para o bairro '{$neighborhoodKey}' no periodo {$start} — {$end}."];
}
if ($freshness === 'critical') {
    $alerts[] = ['level' => 'critical', 'msg' => 'Freshness CRITICAL: mart_compradores_por_geo desatualizado ha mais de 72h. Execute a materializacao geografica.'];
}
if ($freshness === 'unknown') {
    $alerts[] = ['level' => 'warning', 'msg' => 'Freshness desconhecida: mart_compradores_por_geo nao foi materializado para este bairro.'];
}
if (($payload['period_basis']['realized'] ?? '') !== 'data_competencia') {
    $alerts[] = ['level' => 'critical', 'msg' => 'CRITICAL: period_basis.realized nao e data_competencia! Verificar Geo360ReadService.'];
}

// ── JSON mode ─────────────────────────────────────────────────────────────

if ($jsonMode) {
    $payload['_debug'] = ['alerts' => $alerts];
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

// ── Human-readable output ─────────────────────────────────────────────────

$line = str_repeat('─', 60);
$sep  = fn (string $title) => "\n\033[1;34m{$line}\n  {$title}\n{$line}\033[0m\n";
$kv   = fn (string $k, mixed $v) => sprintf("  %-34s %s\n", $k . ':', is_array($v) ? json_encode($v) : $v);
$warn = fn (string $msg) => "\033[1;33m  ⚠ {$msg}\033[0m\n";
$crit = fn (string $msg) => "\033[1;31m  ✗ {$msg}\033[0m\n";
$ok   = fn (string $msg) => "\033[1;32m  ✓ {$msg}\033[0m\n";

echo "\033[1mGeo 360 — Debug CLI\033[0m\n";
echo $kv('neighborhood_key', $neighborhoodKey);
echo $kv('city', $city);
echo $kv('period', $start . ' → ' . $end);
echo $kv('realized_period_basis', $payload['period_basis']['realized'] ?? '-');

// ── Summary ───────────────────────────────────────────────────────────────

echo $sep('RESUMO');
$s = $payload['summary'] ?? [];
echo $kv('neighborhood_name',  $s['neighborhood_name'] ?? '-');
echo $kv('revenue',            $s['revenue'] ?? '-');
echo $kv('invoices_count',     $s['invoices_count'] ?? 0);
echo $kv('customers_count',    $s['customers_count'] ?? 0);
echo $kv('cnaes_count',        $s['cnaes_count'] ?? 0);
echo $kv('avg_ticket',         $s['avg_ticket'] ?? '-');
echo $kv('first_sale_at',      $s['first_sale_at'] ?? '-');
echo $kv('last_sale_at',       $s['last_sale_at'] ?? '-');
echo $kv('source',             $s['source'] ?? '-');

// ── Buyers ────────────────────────────────────────────────────────────────

$buyers = $payload['buyers'] ?? [];
echo $sep('TOP COMPRADORES (' . count($buyers) . ' — exibindo ate 5)');
foreach (array_slice($buyers, 0, 5) as $b) {
    echo sprintf("  %-34s  receita:%-14s  NFs:%d\n",
        $b['customer_name'] ?? '-',
        $b['revenue'] ?? '-',
        $b['invoices_count'] ?? 0
    );
}
if (!count($buyers)) echo "  (nenhum comprador encontrado)\n";

// ── CNAEs ─────────────────────────────────────────────────────────────────

$cnaes = $payload['cnaes'] ?? [];
echo $sep('CNAEs (' . count($cnaes) . ' — exibindo ate 5)');
foreach (array_slice($cnaes, 0, 5) as $c) {
    echo sprintf("  %-16s  %-30s  receita:%-14s  clientes:%d\n",
        $c['cnae_codigo'] ?? 'SEM_CNAE',
        substr($c['cnae_descricao'] ?? '-', 0, 28),
        $c['revenue'] ?? '-',
        $c['customers_count'] ?? 0
    );
}
if (!count($cnaes)) echo "  (nenhum CNAE encontrado)\n";

// ── Evolução ──────────────────────────────────────────────────────────────

$timeline = $payload['timeline'] ?? [];
echo $sep('EVOLUCAO TEMPORAL (' . count($timeline) . ' buckets)');
foreach (array_slice($timeline, 0, 6) as $t) {
    echo sprintf("  %s  receita:%-14s  clientes:%d  NFs:%d\n",
        $t['bucket'] ?? '-',
        $t['revenue'] ?? '-',
        $t['customers_count'] ?? 0,
        $t['invoices_count'] ?? 0
    );
}
if (!count($timeline)) echo "  (sem dados de evolucao)\n";

// ── Freshness ─────────────────────────────────────────────────────────────

echo $sep('DATA QUALITY / FRESHNESS');
$dq = $payload['data_quality'] ?? [];
echo $kv('freshness_status',          $dq['freshness_status'] ?? '-');
echo $kv('gold_freshness',            $dq['gold_freshness_status'] ?? '-');
echo $kv('canonical_freshness',       $dq['canonical_freshness_status'] ?? '-');
echo $kv('mart_rows_count',           $dq['mart_rows_count'] ?? '-');
echo $kv('last_gold_materialized_at', $dq['last_gold_materialized_at'] ?? '-');
echo $kv('last_canonical_nf_at',      $dq['last_canonical_nf_at'] ?? '-');

// ── Alertas ───────────────────────────────────────────────────────────────

echo $sep('ALERTAS');
if (!$alerts) {
    echo $ok('Nenhum alerta detectado.');
} else {
    foreach ($alerts as $alert) {
        if ($alert['level'] === 'critical') {
            echo $crit($alert['msg']);
        } else {
            echo $warn($alert['msg']);
        }
    }
}

// ── Explain ───────────────────────────────────────────────────────────────

if ($explain) {
    echo $sep('INDICES RECOMENDADOS');
    $indexes = [
        "-- Bairro + competência (acesso principal)\nALTER TABLE mart_compradores_por_geo\n  ADD INDEX idx_geo_mart_bairro_comp (bairro_norm, competencia);",
        "-- CNAE + competência\nALTER TABLE mart_compradores_por_geo\n  ADD INDEX idx_geo_mart_cnae_comp (cnae_codigo, competencia, bairro_norm);",
        "-- Cliente + competência\nALTER TABLE mart_compradores_por_geo\n  ADD INDEX idx_geo_mart_cliente_comp (codigo_cliente_omie, competencia);",
        "-- Bairro normalizado na dim geo\nALTER TABLE biomie_clientes_geo_dim\n  ADD INDEX idx_clientes_geo_bairro_norm (bairro_norm);",
        "-- Código cliente na dim geo\nALTER TABLE biomie_clientes_geo_dim\n  ADD INDEX idx_clientes_geo_codigo (codigo_cliente_omie);",
        "-- Bairro normalizado na dim oficial\nALTER TABLE biomie_bairros_fortaleza_dim\n  ADD INDEX idx_bairros_dim_nome_norm (bairro_nome_normalizado, ativo);",
    ];
    foreach ($indexes as $idx) {
        echo "\n" . $idx . "\n";
    }
    echo "\nNota: Usar migration Version20260516000002_GeoMapIndexes.php para aplicar com seguranca (maybeAddIndex).\n";
}

echo "\n";
exit(0);

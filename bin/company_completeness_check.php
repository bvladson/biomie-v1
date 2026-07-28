<?php

/**
 * bin/company_completeness_check.php
 *
 * Relatório de frescor e completude de dados para cada empresa ativa.
 * Mostra score (0-100), tier, matrix de dimensões e ações corretivas.
 *
 * Uso:
 *   php bin/company_completeness_check.php [--json] [--company=SLUG]
 *
 * Exit: 0 = todas full/operational, 1 = alguma minimal/incomplete, 2 = erro
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;
use BIomie\DataPlatform\Monitoring\CompanyCompletenessService;

exit(run($argv ?? []));

// ---------------------------------------------------------------------------

function run(array $argv): int
{
    // ── Parse args ────────────────────────────────────────────────────────────
    $opts = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--([a-z0-9_-]+)=(.+)$/', $arg, $m)) {
            $opts[$m[1]] = $m[2];
        } elseif (preg_match('/^--([a-z0-9_-]+)$/', $arg, $m)) {
            $opts[$m[1]] = true;
        }
    }
    $outputJson = isset($opts['json']);
    $filterSlug = isset($opts['company']) ? strtoupper((string) $opts['company']) : null;

    // ── Database connection ───────────────────────────────────────────────────
    try {
        $config = new Config();
        $conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
        $pdo    = Database::connect($config, $conn);
    } catch (\Throwable $e) {
        fwrite(STDERR, 'Erro ao conectar ao banco: ' . $e->getMessage() . PHP_EOL);
        return 2;
    }

    // ── TTY-aware color helpers ───────────────────────────────────────────────
    $useColors = !$outputJson && defined('STDOUT') && @stream_isatty(STDOUT);
    $C = [
        'green'  => $useColors ? "\033[32m" : '',
        'red'    => $useColors ? "\033[31m" : '',
        'yellow' => $useColors ? "\033[33m" : '',
        'cyan'   => $useColors ? "\033[36m" : '',
        'grey'   => $useColors ? "\033[90m" : '',
        'reset'  => $useColors ? "\033[0m"  : '',
    ];
    $col = static fn (string $key, string $text): string => $C[$key] . $text . $C['reset'];

    // ── Load companies ────────────────────────────────────────────────────────
    $companies = $pdo->query(
        'SELECT c.id, c.slug, c.nome_fantasia FROM biomie_companies c WHERE c.ativo = 1 ORDER BY c.id'
    )->fetchAll(PDO::FETCH_ASSOC);

    if ($filterSlug !== null) {
        $companies = array_values(array_filter(
            $companies,
            static fn (array $c): bool => strtoupper((string) $c['slug']) === $filterSlug,
        ));
        if ($companies === []) {
            fwrite(STDERR, "Empresa não encontrada: {$filterSlug}" . PHP_EOL);
            return 2;
        }
    }

    $companyIds = array_map('intval', array_column($companies, 'id'));
    $byId       = array_column($companies, null, 'id');

    $service = new CompanyCompletenessService($pdo);
    $results = $service->analyse($companyIds);
    $weights = CompanyCompletenessService::weights();

    // ── JSON output ───────────────────────────────────────────────────────────
    if ($outputJson) {
        $out = [];
        foreach ($companyIds as $cid) {
            $c     = $byId[$cid];
            $r     = $results[$cid] ?? [];
            $out[] = array_merge(['company_id' => $cid, 'slug' => $c['slug'], 'name' => $c['nome_fantasia']], $r);
        }
        echo json_encode(['ok' => true, 'companies' => $out, 'generated_at' => date(DATE_ATOM)], JSON_PRETTY_PRINT) . PHP_EOL;
        return hasProblems($results) ? 1 : 0;
    }

    // ── Text report ───────────────────────────────────────────────────────────
    $W        = 72;
    $slugPad  = 8;
    $slugCols = array_map(
        static fn (array $c): string => str_pad(substr((string) $c['slug'], 0, $slugPad), $slugPad),
        $companies,
    );

    renderHeader($W);
    renderScores($companyIds, $byId, $results, $col, $W);
    renderDimensions($companyIds, $results, $weights, $slugCols, $slugPad, $col, $useColors, $W);
    renderCoverage($companyIds, $results, $slugCols, $slugPad, $col, $useColors, $W);
    renderSyncLag($companyIds, $results, $slugCols, $slugPad, $col, $useColors, $W);
    renderCanonical($companyIds, $results, $slugCols, $slugPad, $col, $useColors, $W);
    renderRemediation($companyIds, $byId, $results, $col, $W);

    return hasProblems($results) ? 1 : 0;
}

// ── Helpers ──────────────────────────────────────────────────────────────────

/** @param array<int,array<string,mixed>> $results */
function hasProblems(array $results): bool
{
    return (bool) array_filter(
        $results,
        static fn (array $r): bool => in_array($r['tier'] ?? '', ['minimal', 'incomplete'], true),
    );
}

function renderHeader(int $W): void
{
    echo PHP_EOL;
    echo '╔' . str_repeat('═', $W - 2) . '╗' . PHP_EOL;
    echo '║' . str_pad('  BIomie — Completude por Empresa — ' . date('Y-m-d'), $W - 2) . '║' . PHP_EOL;
    echo '╚' . str_repeat('═', $W - 2) . '╝' . PHP_EOL . PHP_EOL;
}

function renderScores(array $companyIds, array $byId, array $results, callable $col, int $W): void
{
    printf("%-24s %6s  %-12s  %-22s%s", 'Empresa', 'Score', 'Tier', 'Barra', PHP_EOL);
    echo str_repeat('─', $W) . PHP_EOL;

    foreach ($companyIds as $cid) {
        $c     = $byId[$cid];
        $r     = $results[$cid] ?? ['score' => 0, 'tier' => 'incomplete'];
        $score = (int) $r['score'];
        $tier  = (string) $r['tier'];
        $bars  = (int) round($score / 5);
        $bar   = str_repeat('█', $bars) . str_repeat('░', 20 - $bars);

        $tierColor = match ($tier) {
            'full'        => 'green',
            'operational' => 'cyan',
            'minimal'     => 'yellow',
            default       => 'red',
        };

        printf(
            "%-24s %3d/100  %s  %s %d%%%s",
            substr((string) $c['nome_fantasia'], 0, 24),
            $score,
            $col($tierColor, str_pad($tier, 12)),
            $bar,
            $score,
            PHP_EOL,
        );
    }
    echo PHP_EOL;
}

function renderDimensions(array $companyIds, array $results, array $weights, array $slugCols, int $slugPad, callable $col, bool $useColors, int $W): void
{
    $dims = [
        'credentials_configured' => $weights['credentials_configured'] ?? 0,
        'has_canonical_nfs'      => $weights['has_canonical_nfs']      ?? 0,
        'has_canonical_orders'   => $weights['has_canonical_orders']   ?? 0,
        'has_clients_dim'        => $weights['has_clients_dim']        ?? 0,
        'has_finance_titles'     => $weights['has_finance_titles']     ?? 0,
        'has_geo_mart'           => $weights['has_geo_mart']           ?? 0,
        'has_product_mart'       => $weights['has_product_mart']       ?? 0,
        'sync_commercial_fresh'  => $weights['sync_commercial_fresh']  ?? 0,
        'sync_finance_fresh'     => $weights['sync_finance_fresh']     ?? 0,
        'coverage_no_gaps'       => 0,
    ];

    printf("%-30s %4s  %s%s", 'Dimensão', 'pts', implode('  ', $slugCols), PHP_EOL);
    echo str_repeat('─', $W) . PHP_EOL;

    $pad = $slugPad + ($useColors ? 9 : 0);
    foreach ($dims as $dim => $pts) {
        $cols = [];
        foreach ($companyIds as $cid) {
            $val    = (bool) ($results[$cid]['dims'][$dim] ?? false);
            $cols[] = str_pad($val ? $col('green', '✓') : $col('red', '✗'), $pad);
        }
        printf("%-30s %4s  %s%s", substr($dim, 0, 30), $pts > 0 ? "[{$pts}]" : '[-]', implode('  ', $cols), PHP_EOL);
    }
    echo PHP_EOL;
}

function renderCoverage(array $companyIds, array $results, array $slugCols, int $slugPad, callable $col, bool $useColors, int $W): void
{
    echo 'Cobertura de NFs (últimos 6 meses):' . PHP_EOL;
    echo str_repeat('─', $W) . PHP_EOL;
    printf("%-8s  %s%s", 'Mês', implode('  ', $slugCols), PHP_EOL);

    $firstResult = $results[array_key_first($results)] ?? null;
    $months      = $firstResult !== null ? array_column($firstResult['coverage_months'] ?? [], 'month') : [];
    $pad         = $slugPad + ($useColors ? 9 : 0);

    foreach ($months as $month) {
        $cols = [];
        foreach ($companyIds as $cid) {
            $entry = null;
            foreach (($results[$cid]['coverage_months'] ?? []) as $cm) {
                if ($cm['month'] === $month) { $entry = $cm; break; }
            }
            $cnt    = (int)    ($entry['nf_count'] ?? 0);
            $status = (string) ($entry['status']   ?? 'empty');
            $color  = match ($status) { 'ok' => 'green', 'sparse' => 'yellow', default => 'red' };
            $cols[] = str_pad($col($color, str_pad((string) $cnt, 4, ' ', STR_PAD_LEFT)), $pad);
        }
        printf("%-8s  %s%s", $month, implode('  ', $cols), PHP_EOL);
    }
    echo PHP_EOL;
}

function renderSyncLag(array $companyIds, array $results, array $slugCols, int $slugPad, callable $col, bool $useColors, int $W): void
{
    echo 'Lag de Sync (horas — ok <6h / warning <36h / critical >36h):' . PHP_EOL;
    echo str_repeat('─', $W) . PHP_EOL;
    printf("%-12s  %s%s", 'Domínio', implode('  ', $slugCols), PHP_EOL);

    $pad = $slugPad + ($useColors ? 9 : 0);
    foreach (['commercial', 'finance'] as $domain) {
        $cols = [];
        foreach ($companyIds as $cid) {
            $d   = $results[$cid]['sync_domains'][$domain] ?? [];
            $lag = $d['lag_hours'] ?? null;
            $st  = (string) ($d['status'] ?? 'never');
            if ($lag === null) {
                $cols[] = str_pad($col('grey', '  --'), $pad);
            } else {
                $color  = match ($st) { 'ok' => 'green', 'warning' => 'yellow', 'critical' => 'red', default => 'grey' };
                $cols[] = str_pad($col($color, str_pad("{$lag}h", 4, ' ', STR_PAD_LEFT)), $pad);
            }
        }
        printf("%-12s  %s%s", $domain, implode('  ', $cols), PHP_EOL);
    }
    echo PHP_EOL;
}

function renderCanonical(array $companyIds, array $results, array $slugCols, int $slugPad, callable $col, bool $useColors, int $W): void
{
    echo 'Tabelas Canônicas (contagem de linhas):' . PHP_EOL;
    echo str_repeat('─', $W) . PHP_EOL;
    printf("%-10s  %s%s", 'Tabela', implode('  ', $slugCols), PHP_EOL);

    $pad = $slugPad + ($useColors ? 9 : 0);
    foreach (['nf' => 'NFs', 'orders' => 'Pedidos', 'clients' => 'Clientes', 'finance' => 'Financeiro'] as $key => $label) {
        $cols = [];
        foreach ($companyIds as $cid) {
            $cnt    = (int) ($results[$cid]['canonical'][$key]['count'] ?? 0);
            $color  = $cnt > 0 ? 'green' : 'red';
            $cols[] = str_pad($col($color, str_pad(number_format($cnt), $slugPad, ' ', STR_PAD_LEFT)), $pad);
        }
        printf("%-10s  %s%s", $label, implode('  ', $cols), PHP_EOL);
    }
    echo PHP_EOL;
}

function renderRemediation(array $companyIds, array $byId, array $results, callable $col, int $W): void
{
    $remediations = [];
    foreach ($companyIds as $cid) {
        $slug = (string) $byId[$cid]['slug'];
        $dims = $results[$cid]['dims']      ?? [];
        $can  = $results[$cid]['canonical'] ?? [];

        if (!($dims['has_clients_dim']       ?? true)) {
            $remediations[] = "[{$slug}] php bin/materialize_client_dim.php --company={$slug}";
        }
        if (!($dims['sync_commercial_fresh'] ?? true)) {
            $remediations[] = "[{$slug}] php cron/sync_realtime_pipeline.php --company={$slug} --domains=commercial";
        }
        if (!($dims['sync_finance_fresh']    ?? true)) {
            $remediations[] = "[{$slug}] php cron/sync_realtime_pipeline.php --company={$slug} --domains=finance";
        }
        if (!($dims['has_geo_mart']          ?? true) && ($dims['has_canonical_nfs'] ?? false)) {
            $remediations[] = "[{$slug}] php bin/geo_holistic_audit.php --company={$slug}";
        }
        if (!($dims['has_product_mart']      ?? true) && ($dims['has_canonical_nfs'] ?? false)) {
            $remediations[] = "[{$slug}] php bin/materialize_marts.php --company={$slug}";
        }
        if (!($dims['coverage_no_gaps']      ?? true) && ((int) ($can['nf']['count'] ?? 0)) > 0) {
            $remediations[] = "[{$slug}] php bin/backfill_gaps.php --company={$slug}  # verifique meses vazios acima";
        }
    }

    if ($remediations !== []) {
        echo $col('yellow', 'Ações Recomendadas:') . PHP_EOL;
        echo str_repeat('─', $W) . PHP_EOL;
        foreach (array_unique($remediations) as $action) {
            echo '  ' . $action . PHP_EOL;
        }
    } else {
        echo $col('green', 'Todas as empresas estão completas. Nenhuma ação necessária.') . PHP_EOL;
    }
    echo PHP_EOL;
}

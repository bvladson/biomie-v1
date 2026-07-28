<?php

declare(strict_types=1);

/**
 * flight_plan_debug.php — Diagnostica o endpoint api/flight_plan.php.
 *
 * Diagnósticos:
 *   - Qual caminho é tomado: canonical v2 / snapshot historico / legacy
 *   - Disponibilidade da tabela de metas (biomie_sales_targets_monthly)
 *   - Sinal de dados canonical (realized + pipeline + cancelled)
 *   - Metas ativas no período (targets active vs inactive)
 *   - Vendedores com e sem meta
 *   - Ritmo diário (daily_sales) — linhas por vendedor e série cumulativa
 *   - Realizado x Meta — contagem de datasets e percentual de atingimento
 *
 * Uso:
 *   php tools/dev/flight_plan_debug.php [--start=YYYY-MM-DD] [--end=YYYY-MM-DD]
 *                                       [--vendor=ID] [--json] [--section=...]
 */

use BIomie\Bootstrap;
use BIomie\CanonicalRepository;
use BIomie\Config;
use BIomie\Database;
use BIomie\Logger;
use BIomie\RequestContext;
use BIomie\Support;
use BIomie\TargetsRepository;
use BIomie\WorkingDays;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$root   = dirname(__DIR__, 2);
$config = new Config();
$logger = new Logger($root . '/storage/logs/biomie.log');

$options  = getopt('', ['start:', 'end:', 'vendor:', 'json', 'section:', 'explain']);
$start    = (string) ($options['start']  ?? date('Y-m-01'));
$end      = (string) ($options['end']    ?? date('Y-m-t'));
$vendorId = isset($options['vendor']) ? (string) $options['vendor'] : null;
$isJson   = isset($options['json']);
$explain  = isset($options['explain']);
$section  = isset($options['section']) ? (string) $options['section'] : null;

$requestId = RequestContext::id();

$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
if (!Database::isConfigured($config, $connection)) {
    fwrite(STDERR, "ERRO: Nenhuma conexao MySQL configurada.\n");
    exit(1);
}

try {
    $pdo = Database::connect($config, $connection);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO ao conectar: ' . $e->getMessage() . "\n");
    exit(1);
}

$canonical       = new CanonicalRepository($pdo, $logger);
$canonicalAvail  = $canonical->isAvailable();
$targetsRepo     = new TargetsRepository($pdo, $logger);
$targetsAvail    = $targetsRepo->isAvailable();

$result = [
    'request_id' => $requestId,
    'source'     => 'flight_plan_debug',
    'period'     => ['start' => $start, 'end' => $end],
    'vendor_filter' => $vendorId,
];

// ── Seção 1: Roteamento / qual caminho será tomado ─────────────────────────────

if ($section === null || $section === 'routing') {
    $canonicalFiscalReady = (bool) $config->get('features.canonical_fiscal_ready', false);
    $flightPlanDbConfigured = Database::isConfigured($config, 'flight_plan_db');

    $path = 'legacy';
    $pathReason = [];

    if (!$canonicalFiscalReady) {
        $pathReason[] = 'features.canonical_fiscal_ready = false';
    } elseif (!$canonicalAvail) {
        $pathReason[] = 'canonical indisponivel (biomie_notas_fiscais_canonicas)';
    } else {
        // Simulate signal check
        $invoicedRows = $canonical->aggregateInvoicedByVendorCommercial($start, $end);
        $pipelineRows = $canonical->aggregateOrderPipelineByVendorCommercial($start, $end);
        $cancelledRows = $canonical->aggregateCancelledByVendorCommercial($start, $end);

        $teamRealized = 0.0;
        $teamPipeline = 0.0;
        $teamCancelled = 0.0;
        foreach ($invoicedRows as $row) {
            $teamRealized += Support::toFloat($row['invoice_total'] ?? $row['merchandise_total'] ?? 0);
        }
        foreach ($pipelineRows as $row) {
            $teamPipeline += Support::toFloat($row['order_total'] ?? $row['merchandise_total'] ?? 0);
        }
        foreach ($cancelledRows as $row) {
            $teamCancelled += Support::toFloat($row['merchandise_total'] ?? 0);
        }

        $hasSignal = $teamRealized > 0 || $teamPipeline > 0 || $teamCancelled > 0;

        if ($hasSignal) {
            $path = 'canonical_v2';
        } else {
            $path = 'legacy';
            $pathReason[] = 'canonical sem sinal no periodo (realized=0, pipeline=0, cancelled=0)';
        }

        $result['routing']['canonical_signal'] = [
            'team_realized'  => round($teamRealized, 2),
            'team_pipeline'  => round($teamPipeline, 2),
            'team_cancelled' => round($teamCancelled, 2),
            'has_signal'     => $hasSignal,
        ];
    }

    $result['routing']['path']                     = $path;
    $result['routing']['path_reason']              = $pathReason;
    $result['routing']['canonical_fiscal_ready']   = $canonicalFiscalReady;
    $result['routing']['canonical_available']      = $canonicalAvail;
    $result['routing']['flight_plan_db_configured']= $flightPlanDbConfigured;
    $result['routing']['connection_used']          = $connection;
    $result['routing']['reason_code']              = $path === 'legacy' ? 'fallback_to_legacy' : null;
    $result['routing']['source']                   = 'config + canonical_db';
}

// ── Seção 2: Metas ativas (targets) ──────────────────────────────────────────

if ($section === null || $section === 'targets') {
    $targetsSec = ['source' => 'biomie_sales_targets_monthly'];
    $targetsSec['table_available'] = $targetsAvail;

    if ($targetsAvail) {
        $monthsInRange = [];
        $cursor = new DateTimeImmutable(substr($start, 0, 7) . '-01');
        $last   = new DateTimeImmutable(substr($end, 0, 7) . '-01');
        while ($cursor <= $last) {
            $monthsInRange[] = $cursor->format('Y-m');
            $cursor = $cursor->modify('+1 month');
        }

        $targetsMap = $targetsRepo->getTargetsForMonths($monthsInRange, $vendorId);
        $targetsSec['months_checked'] = $monthsInRange;
        $targetsSec['total_rows']     = count($targetsMap);
        $lastUpdated = $targetsRepo->getLastUpdatedAt();
        $targetsSec['last_updated_at'] = $lastUpdated;

        $vendorTargets = [];
        foreach ($targetsMap as $row) {
            $vid  = (string) ($row['vendor_id'] ?? '');
            $vname = (string) ($row['vendor_name'] ?? $vid);
            $ym   = (string) ($row['year_month'] ?? '');
            $amt  = (float) ($row['target_amount'] ?? 0);
            $vendorTargets[$vid] = $vendorTargets[$vid] ?? ['name' => $vname, 'months' => [], 'total' => 0.0];
            $vendorTargets[$vid]['months'][$ym] = $amt;
            $vendorTargets[$vid]['total'] += $amt;
        }

        $targetsSec['vendors_with_targets'] = count($vendorTargets);
        $targetsSec['team_total_target']    = array_sum(array_column($vendorTargets, 'total'));
        $targetsSec['vendor_breakdown']     = array_map(static fn (string $vid, array $v): array => [
            'vendor_id'   => $vid,
            'vendor_name' => $v['name'],
            'months'      => array_keys($v['months']),
            'target_total'=> round($v['total'], 2),
        ], array_keys($vendorTargets), array_values($vendorTargets));

        $targetsSec['reason_code'] = count($targetsMap) === 0 ? 'no_targets_in_period' : null;
    } else {
        $targetsSec['reason_code'] = 'targets_table_not_found';
    }

    $result['targets'] = $targetsSec;
}

// ── Seção 3: Realizado x Meta ─────────────────────────────────────────────────

if ($section === null || $section === 'realized_vs_target') {
    $rvtSec = ['source' => 'biomie_notas_fiscais_canonicas + biomie_sales_targets_monthly'];

    if (!$canonicalAvail) {
        $rvtSec['reason_code'] = 'canonical_unavailable';
        $result['realized_vs_target'] = $rvtSec;
    } else {
        $invoicedRows = $canonical->aggregateInvoicedByVendorCommercial($start, $end);
        $pipelineRows = $canonical->aggregateOrderPipelineByVendorCommercial($start, $end);

        $realizedByVendor = [];
        foreach ($invoicedRows as $row) {
            $vid    = trim((string) ($row['vendor_id'] ?? $row['codigo_vendedor_omie'] ?? ''));
            $vname  = trim((string) ($row['vendor_name'] ?? $row['nome_vendedor'] ?? 'Sem vendedor'));
            $amount = Support::toFloat($row['invoice_total'] ?? $row['merchandise_total'] ?? 0);
            $key    = $vid !== '' ? $vid : 'name:' . mb_strtolower($vname);
            $realizedByVendor[$key] = ($realizedByVendor[$key] ?? 0) + $amount;
        }

        $pipelineByVendor = [];
        foreach ($pipelineRows as $row) {
            $vid    = trim((string) ($row['vendor_id'] ?? $row['codigo_vendedor_omie'] ?? ''));
            $vname  = trim((string) ($row['vendor_name'] ?? $row['nome_vendedor'] ?? 'Sem vendedor'));
            $amount = Support::toFloat($row['order_total'] ?? $row['merchandise_total'] ?? 0);
            $key    = $vid !== '' ? $vid : 'name:' . mb_strtolower($vname);
            $pipelineByVendor[$key] = ($pipelineByVendor[$key] ?? 0) + $amount;
        }

        $targetsMap3 = [];
        if ($targetsAvail) {
            $monthsInRange3 = [];
            $cursor3 = new DateTimeImmutable(substr($start, 0, 7) . '-01');
            $last3   = new DateTimeImmutable(substr($end, 0, 7) . '-01');
            while ($cursor3 <= $last3) {
                $monthsInRange3[] = $cursor3->format('Y-m');
                $cursor3 = $cursor3->modify('+1 month');
            }
            $targetsMap3 = $targetsRepo->getTargetsForMonths($monthsInRange3, $vendorId);
        }

        $targetByVendor = [];
        foreach ($targetsMap3 as $row) {
            $vid = trim((string) ($row['vendor_id'] ?? ''));
            $targetByVendor[$vid] = ($targetByVendor[$vid] ?? 0.0) + (float) ($row['target_amount'] ?? 0);
        }

        $allKeys = array_unique(array_merge(
            array_keys($realizedByVendor),
            array_keys($targetByVendor)
        ));

        $teamRealized = 0.0;
        $teamTarget   = 0.0;
        $vendorsWithTarget = 0;
        $vendorsWithRealized = 0;
        $vendorRows = [];

        foreach ($allKeys as $key) {
            $realized = (float) ($realizedByVendor[$key] ?? 0.0);
            $pipeline = (float) ($pipelineByVendor[$key] ?? 0.0);
            $target   = (float) ($targetByVendor[$key] ?? 0.0);

            if ($realized <= 0.0 && $target <= 0.0 && $pipeline <= 0.0) {
                continue;
            }

            $attainment = $target > 0 ? round($realized / $target * 100, 2) : null;
            if ($realized > 0.0) {
                $vendorsWithRealized++;
            }
            if ($target > 0.0) {
                $vendorsWithTarget++;
            }
            $teamRealized += $realized;
            $teamTarget   += $target;

            $vendorRows[] = [
                'vendor_key'    => $key,
                'realized'      => round($realized, 2),
                'pipeline'      => round($pipeline, 2),
                'target'        => round($target, 2),
                'attainment_pct'=> $attainment,
                'has_target'    => $target > 0.0,
                'has_realized'  => $realized > 0.0,
            ];
        }

        usort($vendorRows, static fn (array $a, array $b): int => $b['realized'] <=> $a['realized']);

        $teamAttainment = $teamTarget > 0 ? round($teamRealized / $teamTarget * 100, 2) : null;

        $rvtSec['vendors_total']         = count($vendorRows);
        $rvtSec['vendors_with_realized'] = $vendorsWithRealized;
        $rvtSec['vendors_with_target']   = $vendorsWithTarget;
        $rvtSec['vendors_without_target']= $vendorsWithRealized - min($vendorsWithRealized, $vendorsWithTarget);
        $rvtSec['team_realized']         = round($teamRealized, 2);
        $rvtSec['team_target']           = round($teamTarget, 2);
        $rvtSec['team_attainment_pct']   = $teamAttainment;
        $rvtSec['vendor_rows']           = array_slice($vendorRows, 0, 20);

        if ($teamRealized === 0.0 && $teamTarget === 0.0) {
            $rvtSec['reason_code'] = 'no_realized_and_no_target';
        } elseif ($teamTarget === 0.0) {
            $rvtSec['reason_code'] = 'realized_present_but_no_targets';
        } elseif ($teamRealized === 0.0) {
            $rvtSec['reason_code'] = 'targets_present_but_no_realized';
        } else {
            $rvtSec['reason_code'] = null;
        }

        $result['realized_vs_target'] = $rvtSec;
    }
}

// ── Seção 4: Ritmo Diário ─────────────────────────────────────────────────────

if ($section === null || $section === 'daily_pace') {
    $dailySec = ['source' => 'biomie_notas_fiscais_canonicas (data_competencia)'];

    if (!$canonicalAvail) {
        $dailySec['reason_code'] = 'canonical_unavailable';
        $result['daily_pace'] = $dailySec;
    } else {
        try {
            $dailyRows = $canonical->dailyByStatusCommercial($start, $end);
        } catch (Throwable $e) {
            $dailySec['reason_code'] = 'query_failed: ' . $e->getMessage();
            $result['daily_pace'] = $dailySec;
            goto daily_done;
        }

        $today  = new DateTimeImmutable('today');
        $workdays = WorkingDays::summarize($start, $end, $today, (float) $config->get('flight_plan.saturday_weight', 0.33));

        $dailyMap = [];
        $cursor4 = new DateTimeImmutable($start);
        $endDate = new DateTimeImmutable($end);
        while ($cursor4 <= $endDate) {
            $d = $cursor4->format('Y-m-d');
            $dailyMap[$d] = ['date' => $d, 'gross_revenue' => 0.0, 'orders' => 0, 'status' => 'past'];
            if ($cursor4->format('Y-m-d') > $today->format('Y-m-d')) {
                $dailyMap[$d]['status'] = 'future';
            } elseif ($cursor4->format('Y-m-d') === $today->format('Y-m-d')) {
                $dailyMap[$d]['status'] = 'today';
            }
            $cursor4 = $cursor4->modify('+1 day');
        }

        foreach ($dailyRows as $row) {
            if ((string) ($row['status_comercial'] ?? $row['status_fiscal'] ?? '') !== 'faturado') {
                continue;
            }
            $date = (string) ($row['dia'] ?? $row['data_competencia'] ?? '');
            if ($date === '' || !isset($dailyMap[$date])) {
                continue;
            }
            $dailyMap[$date]['gross_revenue'] += Support::toFloat($row['total'] ?? $row['invoice_total'] ?? 0);
            $dailyMap[$date]['orders'] += (int) ($row['pedidos'] ?? $row['orders'] ?? 0);
        }

        $cumulative = 0.0;
        $daysWithRevenue = 0;
        $dailySeries = [];
        foreach ($dailyMap as $d => $entry) {
            $rev = $entry['gross_revenue'];
            $cumulative += $rev;
            if ($rev > 0.0) {
                $daysWithRevenue++;
            }
            $dailySeries[] = [
                'date'       => $d,
                'revenue'    => round($rev, 2),
                'cumulative' => round($cumulative, 2),
                'orders'     => $entry['orders'],
                'status'     => $entry['status'],
            ];
        }

        $dailySec['days_in_period']        = count($dailyMap);
        $dailySec['days_with_revenue']     = $daysWithRevenue;
        $dailySec['days_past_count']       = count(array_filter($dailyMap, static fn (array $r): bool => $r['status'] === 'past'));
        $dailySec['total_revenue']         = round($cumulative, 2);
        $dailySec['workdays']              = $workdays;
        $dailySec['daily_series_count']    = count($dailySeries);
        $dailySec['daily_series_sample']   = array_filter($dailySeries, static fn (array $r): bool => $r['revenue'] > 0.0);
        $dailySec['daily_series_sample']   = array_values(array_slice($dailySec['daily_series_sample'], 0, 10));

        $emptyDays = count(array_filter(
            array_filter($dailySeries, static fn (array $r): bool => $r['status'] === 'past'),
            static fn (array $r): bool => $r['revenue'] === 0.0
        ));
        $dailySec['empty_past_days'] = $emptyDays;

        if ($daysWithRevenue === 0) {
            $dailySec['reason_code'] = 'no_daily_revenue_in_period';
        } elseif ($emptyDays > 5) {
            $dailySec['reason_code'] = 'many_empty_past_days: ' . $emptyDays . ' dias sem faturamento';
        } else {
            $dailySec['reason_code'] = null;
        }

        $result['daily_pace'] = $dailySec;
    }

    daily_done:;
}

// ── Seção 5: Vendors sem meta no período ──────────────────────────────────────

if ($section === null || $section === 'missing_targets') {
    $missSec = ['source' => 'biomie_notas_fiscais_canonicas + biomie_sales_targets_monthly'];

    if (!$canonicalAvail || !$targetsAvail) {
        $missSec['reason_code'] = !$canonicalAvail ? 'canonical_unavailable' : 'targets_table_not_found';
        $result['missing_targets'] = $missSec;
    } else {
        $invoicedRows2 = $canonical->aggregateInvoicedByVendorCommercial($start, $end);
        $vendorsWithRealized2 = [];
        foreach ($invoicedRows2 as $row) {
            $vid   = trim((string) ($row['vendor_id'] ?? $row['codigo_vendedor_omie'] ?? ''));
            $vname = trim((string) ($row['vendor_name'] ?? $row['nome_vendedor'] ?? 'Sem vendedor'));
            $rev   = Support::toFloat($row['invoice_total'] ?? $row['merchandise_total'] ?? 0);
            if ($rev > 0.0 && $vid !== '') {
                $vendorsWithRealized2[$vid] = $vname;
            }
        }

        $monthsInRange4 = [];
        $cursor5 = new DateTimeImmutable(substr($start, 0, 7) . '-01');
        $last5   = new DateTimeImmutable(substr($end, 0, 7) . '-01');
        while ($cursor5 <= $last5) {
            $monthsInRange4[] = $cursor5->format('Y-m');
            $cursor5 = $cursor5->modify('+1 month');
        }

        $targetsMap4 = $targetsRepo->getTargetsForMonths($monthsInRange4);
        $vendorIdsWithTarget = array_unique(array_column(array_values($targetsMap4), 'vendor_id'));

        $missing = [];
        foreach ($vendorsWithRealized2 as $vid => $vname) {
            if (!in_array($vid, $vendorIdsWithTarget, true)) {
                $missing[] = ['vendor_id' => $vid, 'vendor_name' => $vname];
            }
        }

        $missSec['vendors_with_realized']    = count($vendorsWithRealized2);
        $missSec['vendors_with_target']      = count($vendorIdsWithTarget);
        $missSec['vendors_missing_target']   = count($missing);
        $missSec['missing_list']             = $missing;
        $missSec['reason_code']              = count($missing) > 0 ? 'vendors_without_target_have_realized' : null;

        $result['missing_targets'] = $missSec;
    }
}

// ── Seção 6: Feature flags ────────────────────────────────────────────────────

if ($section === null || $section === 'features') {
    $result['features'] = [
        'source'                    => 'config',
        'canonical_fiscal_ready'    => (bool) $config->get('features.canonical_fiscal_ready', false),
        'flight_plan_db_configured' => Database::isConfigured($config, 'flight_plan_db'),
        'saturday_weight'           => (float) $config->get('flight_plan.saturday_weight', 0.33),
        'connection_used'           => $connection,
    ];
}

// ── Output ────────────────────────────────────────────────────────────────────

if ($isJson) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

// Text output
$line = static fn (string $label, mixed $value, string $indent = ''): string =>
    sprintf("%s%-46s %s\n", $indent, $label . ':', is_bool($value) ? ($value ? 'sim' : 'nao') : (string) ($value ?? '-'));
$money = static fn (float $v): string => 'R$ ' . number_format($v, 2, ',', '.');

echo "\n=== Flight Plan Debug ===\n";
echo "Request ID : {$requestId}\n";
echo "Periodo    : {$start} ate {$end}\n";
if ($vendorId !== null) {
    echo "Vendedor   : {$vendorId}\n";
}
if ($section !== null) {
    echo "Secao      : {$section}\n";
}
echo "\n";

if (isset($result['features'])) {
    echo "--- Feature Flags ---\n";
    echo $line('canonical_fiscal_ready', $result['features']['canonical_fiscal_ready']);
    echo $line('flight_plan_db configured', $result['features']['flight_plan_db_configured']);
    echo $line('saturday_weight', $result['features']['saturday_weight']);
    echo $line('connection', $result['features']['connection_used']);
    echo "\n";
}

if (isset($result['routing'])) {
    $r = $result['routing'];
    echo "--- Roteamento ---\n";
    echo $line('Caminho escolhido', strtoupper((string) ($r['path'] ?? '-')));
    echo $line('Canonical disponivel', $r['canonical_available'] ?? false);
    echo $line('canonical_fiscal_ready', $r['canonical_fiscal_ready'] ?? false);
    if (isset($r['canonical_signal'])) {
        $cs = $r['canonical_signal'];
        echo $line('Realizado (canonical)', $money($cs['team_realized'] ?? 0));
        echo $line('Pipeline (canonical)', $money($cs['team_pipeline'] ?? 0));
        echo $line('Cancelado (canonical)', $money($cs['team_cancelled'] ?? 0));
        echo $line('Tem sinal', $cs['has_signal'] ?? false);
    }
    if (!empty($r['path_reason'])) {
        echo $line('Motivo do fallback', implode('; ', $r['path_reason']));
    }
    if (($r['reason_code'] ?? null) !== null) {
        echo $line('[reason_code]', $r['reason_code']);
    }
    echo "\n";
}

if (isset($result['targets'])) {
    $t = $result['targets'];
    echo "--- Metas Ativas ---\n";
    echo $line('Tabela disponivel', $t['table_available'] ?? false);
    if ($t['table_available'] ?? false) {
        echo $line('Total de linhas', $t['total_rows'] ?? 0);
        echo $line('Vendedores com meta', $t['vendors_with_targets'] ?? 0);
        echo $line('Meta total equipe', $money((float) ($t['team_total_target'] ?? 0)));
        echo $line('Ultima atualizacao', $t['last_updated_at'] ?? '-');
        if (!empty($t['vendor_breakdown'])) {
            echo "  Vendedores:\n";
            foreach ($t['vendor_breakdown'] as $v) {
                echo sprintf("  %-30s meses=%-12s meta=%s\n",
                    substr((string) ($v['vendor_name'] ?? $v['vendor_id']), 0, 30),
                    implode(',', $v['months'] ?? []),
                    $money((float) ($v['target_total'] ?? 0)));
            }
        }
    }
    if (($t['reason_code'] ?? null) !== null) {
        echo $line('[reason_code]', $t['reason_code']);
    }
    echo "\n";
}

if (isset($result['realized_vs_target'])) {
    $rvt = $result['realized_vs_target'];
    echo "--- Realizado x Meta ---\n";
    echo $line('Vendedores total', $rvt['vendors_total'] ?? 0);
    echo $line('Vendedores com realizado', $rvt['vendors_with_realized'] ?? 0);
    echo $line('Vendedores com meta', $rvt['vendors_with_target'] ?? 0);
    echo $line('Vendedores sem meta', $rvt['vendors_without_target'] ?? 0);
    echo $line('Realizado equipe', $money((float) ($rvt['team_realized'] ?? 0)));
    echo $line('Meta equipe', $money((float) ($rvt['team_target'] ?? 0)));
    echo $line('Atingimento', ($rvt['team_attainment_pct'] ?? '-') . '%');
    if (($rvt['reason_code'] ?? null) !== null) {
        echo $line('[reason_code]', $rvt['reason_code']);
    }
    if (!empty($rvt['vendor_rows'] ?? [])) {
        echo "  Top vendedores:\n";
        foreach (array_slice($rvt['vendor_rows'], 0, 8) as $v) {
            $ating = $v['attainment_pct'] !== null ? $v['attainment_pct'] . '%' : 'sem_meta';
            echo sprintf("  %-20s real=%s meta=%s ating=%s\n",
                substr((string) $v['vendor_key'], 0, 20),
                $money($v['realized'] ?? 0),
                $money($v['target'] ?? 0),
                $ating);
        }
    }
    echo "\n";
}

if (isset($result['daily_pace'])) {
    $dp = $result['daily_pace'];
    echo "--- Ritmo Diario ---\n";
    echo $line('Dias no periodo', $dp['days_in_period'] ?? 0);
    echo $line('Dias com faturamento', $dp['days_with_revenue'] ?? 0);
    echo $line('Dias passados sem fat.', $dp['empty_past_days'] ?? 0);
    echo $line('Total faturado (NFs)', $money((float) ($dp['total_revenue'] ?? 0)));
    if (isset($dp['workdays'])) {
        $wd = $dp['workdays'];
        echo $line('Dias uteis total', $wd['total_workdays'] ?? ($wd['total_days'] ?? '-'));
        echo $line('Dias uteis passados', $wd['elapsed_workdays'] ?? ($wd['elapsed_days'] ?? '-'));
        echo $line('Dias uteis restantes', $wd['remaining_workdays'] ?? ($wd['remaining_days'] ?? '-'));
    }
    if (($dp['reason_code'] ?? null) !== null) {
        echo $line('[reason_code]', $dp['reason_code']);
    }
    if (!empty($dp['daily_series_sample'])) {
        echo "  Dias com faturamento (amostra):\n";
        foreach ($dp['daily_series_sample'] as $d) {
            echo sprintf("  %s  rev=%s  orders=%-3d  cum=%s\n",
                $d['date'],
                $money($d['revenue']),
                $d['orders'] ?? 0,
                $money($d['cumulative']));
        }
    }
    echo "\n";
}

if (isset($result['missing_targets'])) {
    $mt = $result['missing_targets'];
    echo "--- Vendedores Sem Meta ---\n";
    echo $line('Com realizado', $mt['vendors_with_realized'] ?? 0);
    echo $line('Com meta', $mt['vendors_with_target'] ?? 0);
    echo $line('Sem meta (tendo realizado)', $mt['vendors_missing_target'] ?? 0);
    if (($mt['reason_code'] ?? null) !== null) {
        echo $line('[reason_code]', $mt['reason_code']);
    }
    if (!empty($mt['missing_list'] ?? [])) {
        echo "  Vendedores sem meta:\n";
        foreach ($mt['missing_list'] as $v) {
            echo "  - {$v['vendor_name']} ({$v['vendor_id']})\n";
        }
    }
    echo "\n";
}

echo "Uso: php tools/dev/flight_plan_debug.php [--start=YYYY-MM-DD] [--end=YYYY-MM-DD]\n";
echo "     [--vendor=ID] [--json] [--section=routing|targets|realized_vs_target|daily_pace|missing_targets|features]\n\n";

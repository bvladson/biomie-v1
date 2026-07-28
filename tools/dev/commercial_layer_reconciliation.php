<?php

declare(strict_types=1);

/**
 * Reconcilia, sem escrever, as camadas que alimentam o dashboard comercial.
 *
 * Uso:
 *   php tools/dev/commercial_layer_reconciliation.php \
 *     --company=MAG_FILIAL --start=2026-01-01 --end=2026-05-31 --json
 */

use BIomie\Bootstrap;
use BIomie\CanonicalRepository;
use BIomie\CommercialCompetenciaSnapshotService;
use BIomie\Config;
use BIomie\Database;
use BIomie\GoldMartRepository;
use BIomie\Logger;
use BIomie\Support\TenantCliContext;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$json = in_array('--json', $_SERVER['argv'] ?? [], true);

try {
    $options = clrParseOptions($_SERVER['argv'] ?? []);
    $json = isset($options['json']);
    $start = clrResolveDateOption($options, 'start', 'from', date('Y-01-01'));
    $end = clrResolveDateOption($options, 'end', 'to', date('Y-m-d'));
    clrAssertDateRange($start, $end);

    $config = new Config();
    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo = Database::connect($config, $connection);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $tenant = TenantCliContext::boot($pdo, $_SERVER['argv'] ?? []);
    $logger = new Logger(Bootstrap::rootPath('storage/logs/biomie.log'));
    $canonical = new CanonicalRepository(
        $pdo,
        $logger,
        null,
        $tenant['company_id'],
        $tenant['canal'],
    );
    $gold = new GoldMartRepository($pdo, $logger);
    $bronzeByMonth = clrBronzeInvoiceSignalsByMonth($pdo, $tenant['company_id'], $start, $end);

    $months = [];
    $critical = 0;
    $warnings = 0;
    foreach (clrMonthWindows($start, $end) as $window) {
        $row = clrReconcileMonth(
            $pdo,
            $canonical,
            $gold,
            $tenant['company_id'],
            $tenant['canal'],
            $bronzeByMonth[$window['month']] ?? $bronzeByMonth['*'] ?? clrEmptyBronzeSignal(),
            $window['month'],
            $window['start'],
            $window['end'],
        );
        $months[] = $row;
        if ($row['status'] === 'critical') {
            $critical++;
        } elseif ($row['status'] === 'warning') {
            $warnings++;
        }
    }

    $report = [
        'ok' => $critical === 0,
        'status' => $critical > 0 ? 'critical' : ($warnings > 0 ? 'warning' : 'ok'),
        'tool' => 'commercial_layer_reconciliation',
        'read_only' => true,
        'execution' => [
            'database_writes' => false,
            'omie_api_calls' => false,
            'bronze_payload_policy' => 'status_protocol_evidence_only',
        ],
        'company' => [
            'id' => $tenant['company_id'],
            'slug' => $tenant['slug'],
            'channel' => $tenant['canal'],
        ],
        'period' => ['start' => $start, 'end' => $end],
        'summary' => [
            'months' => count($months),
            'ok' => count($months) - $critical - $warnings,
            'warnings' => $warnings,
            'critical' => $critical,
        ],
        'months' => $months,
        'generated_at' => date(DATE_ATOM),
    ];

    if ($json) {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        clrRender($report);
    }
    exit($critical > 0 ? 1 : 0);
} catch (InvalidArgumentException $exception) {
    $error = ['ok' => false, 'status' => 'validation_error', 'read_only' => true, 'message' => $exception->getMessage()];
    if ($json) {
        echo json_encode($error, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } else {
        fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    }
    exit(2);
} catch (Throwable $exception) {
    $error = ['ok' => false, 'status' => 'environment_error', 'read_only' => true, 'message' => $exception->getMessage()];
    if ($json) {
        echo json_encode($error, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } else {
        fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    }
    exit(2);
}

/** @return array<string,string|bool> */
function clrParseOptions(array $argv): array
{
    $allowed = ['company', 'start', 'end', 'from', 'to', 'json'];
    $flags = ['json'];
    $options = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (!is_string($argument) || !str_starts_with($argument, '--')) {
            throw new InvalidArgumentException('Argumento posicional nao permitido: ' . (string) $argument);
        }
        [$key, $value] = array_pad(explode('=', substr($argument, 2), 2), 2, true);
        if (!in_array($key, $allowed, true)) {
            throw new InvalidArgumentException("Argumento desconhecido: --{$key}.");
        }
        if (array_key_exists($key, $options)) {
            throw new InvalidArgumentException("Argumento duplicado: --{$key}.");
        }
        if (!in_array($key, ['json'], true) && ($value === true || trim((string) $value) === '')) {
            throw new InvalidArgumentException("--{$key} exige um valor.");
        }
        if (in_array($key, $flags, true) && $value !== true) {
            throw new InvalidArgumentException("--{$key} nao aceita valor.");
        }
        $options[$key] = $value;
    }
    return $options;
}

/** @param array<string,string|bool> $options */
function clrResolveDateOption(array $options, string $primary, string $alias, string $default): string
{
    $primaryValue = isset($options[$primary]) ? trim((string) $options[$primary]) : '';
    $aliasValue = isset($options[$alias]) ? trim((string) $options[$alias]) : '';
    if ($primaryValue !== '' && $aliasValue !== '' && $primaryValue !== $aliasValue) {
        throw new InvalidArgumentException("--{$primary} e --{$alias} informam valores diferentes.");
    }
    return $primaryValue !== '' ? $primaryValue : ($aliasValue !== '' ? $aliasValue : $default);
}

function clrAssertDateRange(string $start, string $end): void
{
    foreach (['start' => $start, 'end' => $end] as $label => $value) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("--{$label} deve seguir YYYY-MM-DD.");
        }
    }
    if ($start > $end) {
        throw new InvalidArgumentException('--start deve ser menor ou igual a --end.');
    }
}

/** @return list<array{month:string,start:string,end:string}> */
function clrMonthWindows(string $start, string $end): array
{
    $cursor = new DateTimeImmutable(substr($start, 0, 7) . '-01');
    $limit = new DateTimeImmutable(substr($end, 0, 7) . '-01');
    $windows = [];
    while ($cursor <= $limit) {
        $monthStart = max($start, $cursor->format('Y-m-01'));
        $monthEnd = min($end, $cursor->format('Y-m-t'));
        $windows[] = [
            'month' => $cursor->format('Y-m'),
            'start' => $monthStart,
            'end' => $monthEnd,
        ];
        $cursor = $cursor->modify('+1 month');
    }
    return $windows;
}

/** @return array<string,mixed> */
function clrReconcileMonth(
    PDO $pdo,
    CanonicalRepository $canonical,
    GoldMartRepository $goldRepository,
    int $companyId,
    string $channel,
    array $bronze,
    string $month,
    string $start,
    string $end,
): array {
    $summary = $canonical->summarizeInvoicesCommercial($start, $end);
    $dailyRows = $canonical->dailyByStatusCommercial($start, $end);
    $vendorRows = $canonical->aggregateInvoicesByVendorCommercial($start, $end);

    $canonicalRevenue = round((float) ($summary['billed_value'] ?? 0), 2);
    $canonicalGrossRevenue = round((float) ($summary['billed_value_nf'] ?? $canonicalRevenue), 2);
    $canonicalNfs = (int) ($summary['billed_orders'] ?? 0);
    $canonicalLastSync = $canonical->lastSyncAt($start, $end);

    $dailyRevenue = 0.0;
    $dailyNfs = 0;
    foreach ($dailyRows as $row) {
        if ((string) ($row['status_comercial'] ?? '') !== 'faturado') {
            continue;
        }
        $dailyRevenue += (float) ($row['total'] ?? $row['revenue'] ?? 0);
        $dailyNfs += (int) ($row['pedidos'] ?? $row['orders'] ?? 0);
    }
    $dailyRevenue = round($dailyRevenue, 2);

    $rankingRevenue = 0.0;
    $rankingNfs = 0;
    foreach ($vendorRows as $row) {
        $rankingRevenue += (float) ($row['total'] ?? $row['revenue'] ?? 0);
        $rankingNfs += (int) ($row['pedidos'] ?? $row['orders'] ?? 0);
    }
    $rankingRevenue = round($rankingRevenue, 2);

    $isFullMonth = $start === $month . '-01' && $end === date('Y-m-t', strtotime($month . '-01'));
    $gold = clrGoldSignals($goldRepository, $start, $end);
    $cnae = $isFullMonth
        ? clrCnaeSignals($pdo, $companyId, $channel, $month)
        : ['available' => false, 'state' => 'not_applicable_partial_month', 'monthly_granularity' => true];
    $snapshot = $isFullMonth
        ? clrSnapshotSignals(
            $pdo,
            $companyId,
            $channel,
            $month,
            $canonicalRevenue,
            $canonicalNfs,
            $canonicalLastSync,
        )
        : ['available' => false, 'state' => 'not_applicable_partial_month', 'monthly_granularity' => true];
    if ($isFullMonth && $month >= date('Y-m') && ($snapshot['state'] ?? '') === 'missing') {
        $snapshot['state'] = 'open_month_not_required';
    }
    $issues = [];
    $severity = 'ok';

    clrCompare($issues, $severity, 'daily_nfs_mismatch', $canonicalNfs, $dailyNfs, 0.0, 'critical');
    clrCompare($issues, $severity, 'daily_revenue_mismatch', $canonicalRevenue, $dailyRevenue, 0.01, 'critical');
    clrCompare($issues, $severity, 'ranking_nfs_mismatch', $canonicalNfs, $rankingNfs, 0.0, 'critical');
    clrCompare($issues, $severity, 'ranking_revenue_mismatch', $canonicalRevenue, $rankingRevenue, 0.01, 'critical');

    if ($canonicalNfs > 0 && !$gold['available']) {
        clrIssue($issues, $severity, 'gold_unavailable_with_canonical_data', 'critical');
    } elseif ($gold['available']) {
        clrCompare($issues, $severity, 'gold_summary_nfs_mismatch', $canonicalNfs, (int) $gold['nfs'], 0.0, 'critical');
        clrCompare($issues, $severity, 'gold_summary_revenue_mismatch', $canonicalRevenue, (float) $gold['revenue'], 0.01, 'critical');
        clrCompare($issues, $severity, 'gold_daily_nfs_mismatch', $canonicalNfs, (int) $gold['daily']['nfs'], 0.0, 'critical');
        clrCompare($issues, $severity, 'gold_daily_revenue_mismatch', $canonicalRevenue, (float) $gold['daily']['revenue'], 0.01, 'critical');
        clrCompare($issues, $severity, 'gold_vendor_nfs_mismatch', $canonicalNfs, (int) $gold['vendors']['nfs'], 0.0, 'critical');
        clrCompare($issues, $severity, 'gold_vendor_revenue_mismatch', $canonicalRevenue, (float) $gold['vendors']['revenue'], 0.01, 'critical');
        if ($isFullMonth) {
            clrCompare(
                $issues,
                $severity,
                'gold_clients_mismatch',
                (int) ($summary['active_clients'] ?? 0),
                (int) $gold['clients'],
                0.0,
                'critical',
            );
        }
    }

    if ($isFullMonth && $canonicalNfs === 0 && $cnae['available'] && ((int) $cnae['nfs'] > 0 || (float) $cnae['revenue'] > 0)) {
        clrIssue($issues, $severity, 'canonical_empty_but_cnae_has_signal', 'critical');
    }
    if ($isFullMonth && $cnae['available']) {
        if (($cnae['channel_scope'] ?? '') !== $channel) {
            clrIssue($issues, $severity, 'cnae_channel_scope_unavailable', 'critical');
        } else {
            clrCompare($issues, $severity, 'cnae_nfs_mismatch', $canonicalNfs, (int) $cnae['nfs'], 0.0, 'critical');
            clrCompare($issues, $severity, 'cnae_revenue_mismatch', $canonicalRevenue, (float) $cnae['revenue'], 0.01, 'critical');
        }
    }
    if (($bronze['reconciled'] ?? false) !== true) {
        clrIssue($issues, $severity, 'upstream_bronze_canonical_inconclusive', 'critical', [
            'bronze_state' => (string) ($bronze['state'] ?? 'unknown'),
            'reason' => (string) ($bronze['canonical_comparison'] ?? 'not_performed'),
        ]);
    }
    if ($isFullMonth && !in_array((string) ($snapshot['state'] ?? ''), ['ok', 'open_month_not_required'], true)) {
        clrIssue($issues, $severity, 'snapshot_' . (string) $snapshot['state'], 'critical');
    }

    return [
        'month' => $month,
        'window' => ['start' => $start, 'end' => $end, 'is_full_month' => $isFullMonth],
        'status' => $severity,
        'canonical' => [
            'revenue' => $canonicalRevenue,
            'gross_revenue' => $canonicalGrossRevenue,
            'nfs' => $canonicalNfs,
            'clients' => (int) ($summary['active_clients'] ?? 0),
            'vendors' => (int) ($summary['active_vendors'] ?? 0),
            'last_sync' => $canonicalLastSync,
        ],
        'daily_read_contract' => [
            'revenue' => $dailyRevenue,
            'nfs' => $dailyNfs,
            'revenue_delta' => round($dailyRevenue - $canonicalRevenue, 2),
            'nfs_delta' => $dailyNfs - $canonicalNfs,
        ],
        'bronze_invoice_signal' => $bronze,
        'ranking_read_contract' => [
            'revenue' => $rankingRevenue,
            'nfs' => $rankingNfs,
            'revenue_delta' => round($rankingRevenue - $canonicalRevenue, 2),
            'nfs_delta' => $rankingNfs - $canonicalNfs,
        ],
        'gold_read_contract' => $gold,
        'cnae_mart' => $cnae,
        'snapshot' => $snapshot,
        'issues' => $issues,
    ];
}

/** @param list<array<string,mixed>> $issues */
function clrCompare(
    array &$issues,
    string &$severity,
    string $code,
    int|float $expected,
    int|float $actual,
    float $tolerance,
    string $level,
): void {
    if (abs((float) $actual - (float) $expected) <= $tolerance) {
        return;
    }
    clrIssue($issues, $severity, $code, $level, [
        'expected' => $expected,
        'actual' => $actual,
        'delta' => round((float) $actual - (float) $expected, 2),
    ]);
}

/** @param list<array<string,mixed>> $issues @param array<string,mixed> $details */
function clrIssue(array &$issues, string &$severity, string $code, string $level, array $details = []): void
{
    $issues[] = ['code' => $code, 'severity' => $level] + $details;
    if ($level === 'critical' || ($level === 'warning' && $severity === 'ok')) {
        $severity = $level;
    }
}

/** @return array<string,mixed> */
function clrEmptyBronzeSignal(string $state = 'no_rows_in_period'): array
{
    return [
        'available' => $state !== 'table_missing',
        'state' => $state,
        'entity' => 'invoice',
        'identity_deduplicated' => true,
        'reconciled' => false,
        'coverage_classification' => 'insufficient_for_reconciliation',
        'channel_classification' => 'not_available_in_bronze',
        'canonical_comparison' => 'not_performed_channel_unclassified',
        'source' => 'local_bronze_read_only',
        'omie_api_calls' => false,
        'records' => 0,
        'authorized' => 0,
        'cancelled' => 0,
        'unknown_status' => 0,
        'last_ingested_at' => null,
        'observed_authorization_fields' => [],
        'evidence_policy' => 'sanitized_status_protocol_fields_only',
    ];
}

/** @return array<string,array<string,mixed>> */
function clrBronzeInvoiceSignalsByMonth(PDO $pdo, int $companyId, string $start, string $end): array
{
    if (!clrTableExists($pdo, 'biomie_omie_bronze')) {
        return ['*' => clrEmptyBronzeSignal('table_missing')];
    }

    $stmt = null;
    $bufferAttribute = defined('Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY')
        ? (int) constant('Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY')
        : (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')
            ? (int) constant('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')
            : null);
    $restoreBuffered = null;
    try {
        if ($bufferAttribute !== null) {
            try {
                $restoreBuffered = (bool) $pdo->getAttribute($bufferAttribute);
                $pdo->setAttribute($bufferAttribute, false);
            } catch (Throwable) {
                $restoreBuffered = null;
            }
        }
        $identitySql = "COALESCE(NULLIF(provider_key, ''), NULLIF(natural_key, ''), CONCAT('row:', id))";
        $sql = <<<SQL
            SELECT b.natural_key, b.provider_key, b.payload_json, b.request_params_json, b.ingested_at
              FROM biomie_omie_bronze b
              JOIN (
                    SELECT MAX(id) AS latest_id, {$identitySql} AS identity_key
                      FROM biomie_omie_bronze
                     WHERE company_id = :company_id_inner
                       AND entity_name = 'invoice'
                     GROUP BY identity_key
              ) latest ON latest.latest_id = b.id
             WHERE b.company_id = :company_id_outer
               AND b.entity_name = 'invoice'
             ORDER BY b.id
             LIMIT 200001
        SQL;
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'company_id_inner' => $companyId,
            'company_id_outer' => $companyId,
        ]);
        $truncated = false;
        $seen = 0;
        $signals = [];
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $seen++;
            if ($seen > 200000) {
                $truncated = true;
                break;
            }
            $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
            if (!is_array($payload)) {
                continue;
            }
            $issueDate = clrNormalizeOmieDate(clrPayloadValue($payload, [
                'compl.dEmi',
                'ide.dEmi',
                'dEmi',
                'cabec.dEmi',
                'cabecalho.dEmi',
            ]));
            if ($issueDate === null || $issueDate < $start || $issueDate > $end) {
                continue;
            }

            $month = substr($issueDate, 0, 7);
            $signals[$month] ??= clrEmptyBronzeSignal($truncated ? 'truncated' : 'channel_unclassified');
            $signals[$month]['records']++;
            clrMergeAuthorizationEvidence(
                $signals[$month]['observed_authorization_fields'],
                clrCollectAuthorizationEvidence($payload),
            );
            $status = strtoupper(trim(clrPayloadValue($payload, [
                'compl.cStatus',
                'cStatus',
                'compl.status',
                'status',
            ])));
            $requestParams = json_decode((string) ($row['request_params_json'] ?? ''), true);
            if (is_array($requestParams)) {
                clrMergeAuthorizationEvidence(
                    $signals[$month]['observed_authorization_fields'],
                    clrCollectAuthorizationEvidence($requestParams, 'request'),
                );
                if ($status === '') {
                    $status = strtoupper(trim(clrPayloadValue($requestParams, ['filtrar_por_status'])));
                }
            }
            if (in_array($status, ['N', 'AUTORIZADA', 'AUTORIZADO'], true)) {
                $signals[$month]['authorized']++;
            } elseif (in_array($status, ['C', 'CANCELADA', 'CANCELADO'], true)) {
                $signals[$month]['cancelled']++;
            } else {
                $signals[$month]['unknown_status']++;
            }
            $ingestedAt = (string) ($row['ingested_at'] ?? '');
            if ($ingestedAt !== '' && (
                $signals[$month]['last_ingested_at'] === null
                || $ingestedAt > $signals[$month]['last_ingested_at']
            )) {
                $signals[$month]['last_ingested_at'] = $ingestedAt;
            }
        }
        if ($truncated) {
            foreach ($signals as &$signal) {
                $signal['state'] = 'truncated';
            }
            unset($signal);
        }
        return $signals;
    } catch (Throwable $exception) {
        return ['*' => clrEmptyBronzeSignal('query_error') + ['error' => $exception->getMessage()]];
    } finally {
        $stmt?->closeCursor();
        if ($bufferAttribute !== null && $restoreBuffered !== null) {
            try {
                $pdo->setAttribute($bufferAttribute, $restoreBuffered);
            } catch (Throwable) {
            }
        }
    }
}

/**
 * Extract only status/authorization/cancellation/protocol evidence. Numeric
 * list indexes are normalized and customer/document/key fields are excluded,
 * so the read-only report never emits a complete Bronze payload.
 *
 * @return array<string,list<string>>
 */
function clrCollectAuthorizationEvidence(array $payload, string $prefix = '', int $depth = 0): array
{
    if ($depth > 8) {
        return [];
    }

    $evidence = [];
    foreach ($payload as $key => $value) {
        $segment = is_int($key) || ctype_digit((string) $key) ? '[]' : (string) $key;
        $path = $prefix === '' ? $segment : $prefix . '.' . $segment;
        if (is_array($value)) {
            clrMergeAuthorizationEvidence(
                $evidence,
                clrCollectAuthorizationEvidence($value, $path, $depth + 1),
            );
            continue;
        }
        if (!is_scalar($value)) {
            continue;
        }

        $leaf = strtolower($segment);
        if (preg_match('/(status|situacao|protocol|protocolo|autoriz|cancel)/i', $leaf) !== 1) {
            continue;
        }
        if (preg_match('/(chave|cnpj|cpf|cliente|email|nome|documento)/i', $leaf) === 1) {
            continue;
        }
        $safeValue = trim((string) $value);
        if ($safeValue === '') {
            continue;
        }
        $safeValue = mb_substr(preg_replace('/[\r\n\t]+/', ' ', $safeValue) ?? '', 0, 80);
        if ($safeValue !== '') {
            $evidence[$path] = [$safeValue];
        }
    }
    return $evidence;
}

/** @param array<string,list<string>> $target @param array<string,list<string>> $incoming */
function clrMergeAuthorizationEvidence(array &$target, array $incoming): void
{
    foreach ($incoming as $path => $values) {
        if (count($target) >= 24 && !array_key_exists($path, $target)) {
            continue;
        }
        $target[$path] ??= [];
        foreach ($values as $value) {
            if (count($target[$path]) >= 6) {
                break;
            }
            if (!in_array($value, $target[$path], true)) {
                $target[$path][] = $value;
            }
        }
    }
}

/** @param list<string> $paths */
function clrPayloadValue(array $payload, array $paths): string
{
    foreach ($paths as $path) {
        $value = $payload;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                $value = null;
                break;
            }
            $value = $value[$segment];
        }
        if (is_scalar($value) && trim((string) $value) !== '') {
            return trim((string) $value);
        }
    }
    return '';
}

function clrNormalizeOmieDate(string $value): ?string
{
    foreach (['!d/m/Y' => 'd/m/Y', '!Y-m-d' => 'Y-m-d', '!d-m-Y' => 'd-m-Y'] as $format => $outputFormat) {
        $date = DateTimeImmutable::createFromFormat($format, $value);
        if ($date instanceof DateTimeImmutable && $date->format($outputFormat) === $value) {
            return $date->format('Y-m-d');
        }
    }
    return null;
}

/** @return array<string,mixed> */
function clrGoldSignals(GoldMartRepository $goldRepository, string $start, string $end): array
{
    try {
        $available = $goldRepository->isAvailableForPeriod($start, $end);
        $row = $goldRepository->summarizeInvoicesCommercial($start, $end);
        $dailyRows = $goldRepository->dailyByStatusCommercial($start, $end);
        $vendorRows = $goldRepository->aggregateByVendorCommercial($start, $end);
        $daily = clrSumBilledRows($dailyRows);
        $vendors = clrSumBilledRows($vendorRows);
        return [
            'available' => $available,
            'source' => 'GoldMartRepository',
            'tenant_channel_scoped' => true,
            'nfs' => (int) ($row['billed_orders'] ?? 0),
            'revenue' => round((float) ($row['billed_value'] ?? 0), 2),
            'clients' => (int) ($row['active_clients'] ?? 0),
            'active_vendors' => (int) ($row['active_vendors'] ?? 0),
            'daily' => $daily,
            'vendor_rows' => count(array_filter(
                $vendorRows,
                static fn (array $entry): bool => (string) ($entry['status_comercial'] ?? '') === 'faturado',
            )),
            'vendors' => $vendors,
        ];
    } catch (Throwable $exception) {
        return [
            'available' => false,
            'source' => 'GoldMartRepository',
            'tenant_channel_scoped' => true,
            'error' => $exception->getMessage(),
            'nfs' => 0,
            'revenue' => 0.0,
            'clients' => 0,
            'daily' => ['rows' => 0, 'nfs' => 0, 'revenue' => 0.0],
            'vendors' => ['rows' => 0, 'nfs' => 0, 'revenue' => 0.0],
        ];
    }
}

/** @param list<array<string,mixed>> $rows @return array{rows:int,nfs:int,revenue:float} */
function clrSumBilledRows(array $rows): array
{
    $count = 0;
    $nfs = 0;
    $revenue = 0.0;
    foreach ($rows as $row) {
        if ((string) ($row['status_comercial'] ?? '') !== 'faturado') {
            continue;
        }
        $count++;
        $nfs += (int) ($row['pedidos'] ?? 0);
        $revenue += (float) ($row['total'] ?? 0);
    }
    return ['rows' => $count, 'nfs' => $nfs, 'revenue' => round($revenue, 2)];
}

/** @return array<string,mixed> */
function clrCnaeSignals(PDO $pdo, int $companyId, string $channel, string $month): array
{
    if (!clrTableExists($pdo, 'mart_vendas_por_cnae_cnpj')) {
        return ['available' => false, 'rows' => 0, 'nfs' => 0, 'revenue' => 0.0, 'refreshed_at' => null];
    }
    try {
        $channelAware = clrColumnExists($pdo, 'mart_vendas_por_cnae_cnpj', 'business_channel');
        $channelFilter = $channelAware ? ' AND business_channel = :business_channel' : '';
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) AS rows_count,
                    COALESCE(SUM(qtd_notas), 0) AS nfs,
                    COALESCE(SUM(faturamento), 0) AS revenue,
                    MAX(refreshed_at) AS refreshed_at
               FROM mart_vendas_por_cnae_cnpj
              WHERE company_id = :company_id AND competencia = :month' . $channelFilter
        );
        $params = ['company_id' => $companyId, 'month' => $month];
        if ($channelAware) {
            $params['business_channel'] = $channel;
        }
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'available' => true,
            'state' => 'ok',
            'channel_scope' => $channelAware ? $channel : 'column_unavailable',
            'rows' => (int) ($row['rows_count'] ?? 0),
            'nfs' => (int) ($row['nfs'] ?? 0),
            'revenue' => round((float) ($row['revenue'] ?? 0), 2),
            'refreshed_at' => $row['refreshed_at'] ?? null,
        ];
    } catch (Throwable $exception) {
        return ['available' => false, 'error' => $exception->getMessage(), 'rows' => 0, 'nfs' => 0, 'revenue' => 0.0];
    }
}

/** @return array<string,mixed> */
function clrSnapshotSignals(
    PDO $pdo,
    int $companyId,
    string $channel,
    string $month,
    float $canonicalRevenue,
    int $canonicalNfs,
    ?string $canonicalLastSync,
): array
{
    if (!clrTableExists($pdo, 'biomie_competencia_snapshots')) {
        return ['available' => false, 'state' => 'table_missing'];
    }
    foreach (['biomie_competencia_snapshots', 'biomie_competencia_sync_catalog'] as $table) {
        if (!clrColumnExists($pdo, $table, 'business_channel')
            || !clrColumnExists($pdo, $table, 'visibility_scope')
        ) {
            return ['available' => false, 'state' => 'visibility_schema_missing'];
        }
    }
    $visibilityScope = clrVisibilityScope($companyId, $channel);
    $stmt = $pdo->prepare(
        'SELECT s.schema_version, s.overview_payload, s.generated_at, s.last_sync,
                c.status AS catalog_status, c.snapshot_generated_at
           FROM biomie_competencia_snapshots s
      LEFT JOIN biomie_competencia_sync_catalog c
             ON c.company_id = s.company_id
            AND c.business_channel = s.business_channel
            AND c.visibility_scope = s.visibility_scope
            AND c.competencia = s.competencia
          WHERE s.company_id = :company_id
            AND s.business_channel = :business_channel
            AND s.visibility_scope = :visibility_scope
            AND s.competencia = :month
          LIMIT 1'
    );
    $stmt->execute([
        'company_id' => $companyId,
        'business_channel' => $channel,
        'visibility_scope' => $visibilityScope,
        'month' => $month,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return ['available' => false, 'state' => 'missing'];
    }

    $payload = json_decode((string) ($row['overview_payload'] ?? ''), true);
    if (!is_array($payload)) {
        return [
            'available' => true,
            'state' => 'invalid_json',
            'schema_version' => (int) ($row['schema_version'] ?? 0),
            'generated_at' => $row['generated_at'] ?? null,
        ];
    }
    $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
    // The dashboard contract is the commercial basis; gross_revenue is only a
    // legacy fallback for older snapshot schemas.
    $snapshotRevenue = round((float) ($summary['revenue'] ?? $summary['gross_revenue'] ?? $summary['billed_value'] ?? 0), 2);
    $snapshotNfs = (int) ($summary['orders'] ?? $summary['billed_orders'] ?? 0);
    $schemaVersion = (int) ($row['schema_version'] ?? 0);
    $expectedSchemaVersion = clrExpectedSnapshotSchemaVersion();
    $state = 'ok';
    if ($schemaVersion !== $expectedSchemaVersion) {
        $state = 'schema_version_mismatch';
    } elseif ((string) ($row['catalog_status'] ?? '') !== 'ready') {
        $state = 'catalog_' . ((string) ($row['catalog_status'] ?? '') ?: 'missing');
    } elseif ($canonicalLastSync !== null && (
        empty($row['last_sync'])
        || strtotime((string) $row['last_sync']) < strtotime($canonicalLastSync)
    )) {
        $state = 'older_than_canonical';
    } elseif ($canonicalNfs > 0 && ($snapshotNfs === 0 || $snapshotRevenue <= 0)) {
        $state = 'zero_with_canonical_data';
    } elseif ($snapshotNfs !== $canonicalNfs || abs($snapshotRevenue - $canonicalRevenue) > 0.01) {
        $state = 'divergent';
    }

    return [
        'available' => true,
        'state' => $state,
        'schema_version' => $schemaVersion,
        'expected_schema_version' => $expectedSchemaVersion,
        'catalog_status' => $row['catalog_status'] ?? null,
        'revenue' => $snapshotRevenue,
        'nfs' => $snapshotNfs,
        'revenue_delta' => round($snapshotRevenue - $canonicalRevenue, 2),
        'nfs_delta' => $snapshotNfs - $canonicalNfs,
        'generated_at' => $row['generated_at'] ?? null,
        'last_sync' => $row['last_sync'] ?? null,
    ];
}

function clrVisibilityScope(int $companyId, string $channel): string
{
    return substr(hash('sha256', implode('|', [
        (string) $companyId,
        $channel,
        (string) $companyId,
    ])), 0, 32);
}

function clrExpectedSnapshotSchemaVersion(): int
{
    try {
        $reflection = new ReflectionClass(CommercialCompetenciaSnapshotService::class);
        $constant = $reflection->getReflectionConstant('SCHEMA_VERSION');
        return $constant instanceof ReflectionClassConstant ? (int) $constant->getValue() : 1;
    } catch (Throwable) {
        return 1;
    }
}

function clrTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
    );
    $stmt->execute(['table' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}

function clrColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
    );
    $stmt->execute(['table' => $table, 'column' => $column]);
    return (int) $stmt->fetchColumn() > 0;
}

/** @param array<string,mixed> $report */
function clrRender(array $report): void
{
    echo 'Commercial layer reconciliation (READ ONLY)' . PHP_EOL;
    echo sprintf(
        'Company: %s (id=%d) | Period: %s to %s',
        $report['company']['slug'],
        $report['company']['id'],
        $report['period']['start'],
        $report['period']['end'],
    ) . PHP_EOL . PHP_EOL;

    foreach ($report['months'] as $month) {
        printf(
            "%s [%s] canonical=%d NF / R$ %.2f | daily delta=%d / R$ %.2f | snapshot=%s\n",
            $month['month'],
            strtoupper($month['status']),
            $month['canonical']['nfs'],
            $month['canonical']['revenue'],
            $month['daily_read_contract']['nfs_delta'],
            $month['daily_read_contract']['revenue_delta'],
            $month['snapshot']['state'] ?? 'unknown',
        );
        foreach ($month['issues'] as $issue) {
            echo '  - ' . $issue['code'] . ' (' . $issue['severity'] . ')' . PHP_EOL;
        }
    }

    echo PHP_EOL . sprintf(
        'Result: %s | months=%d ok=%d warnings=%d critical=%d',
        strtoupper((string) $report['status']),
        $report['summary']['months'],
        $report['summary']['ok'],
        $report['summary']['warnings'],
        $report['summary']['critical'],
    ) . PHP_EOL;
}

<?php

declare(strict_types=1);

/**
 * tools/dev/recover_commercial_range.php
 *
 * Diagnóstico + dry-run para recuperação de dados comerciais em um intervalo de datas.
 *
 * Modos:
 *   --dry-run (PADRÃO) — mostra o que SERIA feito, sem executar nada
 *   --apply            — executa as ações sequencialmente via proc_open (CUIDADO em produção)
 *
 * Usage:
 *   php tools/dev/recover_commercial_range.php --company=MAG_FILIAL --start=2026-01-01 --end=2026-05-29
 *   php tools/dev/recover_commercial_range.php --company=MAG_FILIAL --start=2026-01-01 --end=2026-05-29 --dry-run --pretty
 *   php tools/dev/recover_commercial_range.php --company=MAG_FILIAL --start=2026-01-01 --end=2026-05-29 --apply --yes
 *   php tools/dev/recover_commercial_range.php --company=MAG_FILIAL --start=2026-01-01 --end=2026-05-29 --json
 *
 * Exit codes:
 *   0 — nothing to do or all actions succeeded
 *   1 — gaps found (dry-run) or some actions failed (apply)
 *   2 — DB connection error
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\CommercialCompetenciaSnapshotService;
use BIomie\Database;
use BIomie\Support\FiscalValueBasis;
use BIomie\Support\TenantCliContext;

// ── CLI params ────────────────────────────────────────────────────────────────

try {
    $opts = rcrParseOptions($_SERVER['argv'] ?? []);
    $startDate = rcrResolveDateOption($opts, 'start', 'from', date('Y-01-01'));
    $endDate = rcrResolveDateOption($opts, 'end', 'to', date('Y-m-d'));
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(2);
}
$domainsRaw = isset($opts['domains']) ? trim((string) $opts['domains']) : 'commercial,gold,snapshots';
$applyMode  = isset($opts['apply']);
$confirmed  = isset($opts['yes']);
$isJson     = isset($opts['json']);
$isPretty   = isset($opts['pretty']);
$isDryRun   = !$applyMode;   // dry-run is the default
$chunk      = strtolower(trim((string) ($opts['chunk'] ?? 'day')));

if ($chunk !== 'day') {
    fwrite(STDERR, "--chunk aceita somente o valor day.\n");
    exit(2);
}
if ($applyMode && isset($opts['dry-run'])) {
    fwrite(STDERR, "Use --dry-run ou --apply, nunca os dois ao mesmo tempo.\n");
    exit(2);
}

$domains = array_values(array_unique(array_filter(array_map(
    static fn (string $domain): string => strtolower(trim($domain)),
    explode(',', $domainsRaw),
))));
$invalidDomains = array_values(array_diff($domains, ['commercial', 'gold', 'snapshots']));

foreach (['start' => $startDate, 'end' => $endDate] as $label => $dateValue) {
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $dateValue);
    if (!$parsed instanceof DateTimeImmutable || $parsed->format('Y-m-d') !== $dateValue) {
        fwrite(STDERR, "--{$label} deve seguir YYYY-MM-DD.\n");
        exit(2);
    }
}
if ($startDate > $endDate) {
    fwrite(STDERR, "--start deve ser menor ou igual a --end.\n");
    exit(2);
}
if ($invalidDomains !== []) {
    fwrite(STDERR, 'Dominios invalidos: ' . implode(', ', $invalidDomains) . ". Use commercial,gold,snapshots.\n");
    exit(2);
}
if ($applyMode && !$confirmed) {
    fwrite(STDERR, "--apply exige confirmacao explicita com --yes.\n");
    exit(2);
}

$root = dirname(__DIR__, 2);
$php  = PHP_BINARY;

// ── DB ────────────────────────────────────────────────────────────────────────

try {
    $config = new Config();
    $conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo    = Database::connect($config, $conn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $tenant = TenantCliContext::boot($pdo, $_SERVER['argv'] ?? []);
} catch (\Throwable $e) {
    $msg = 'ERRO: Falha ao conectar ao banco: ' . $e->getMessage();
    if ($isJson) {
        echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        fwrite(STDERR, $msg . "\n");
    }
    exit(2);
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/** @return array<string,string|bool> */
function rcrParseOptions(array $argv): array
{
    $allowed = [
        'company', 'start', 'end', 'from', 'to', 'domains', 'chunk',
        'dry-run', 'apply', 'yes', 'pretty', 'json',
    ];
    $flags = ['dry-run', 'apply', 'yes', 'pretty', 'json'];
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
        if (!in_array($key, $flags, true) && ($value === true || trim((string) $value) === '')) {
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
function rcrResolveDateOption(array $options, string $primary, string $alias, string $default): string
{
    $primaryValue = isset($options[$primary]) ? trim((string) $options[$primary]) : '';
    $aliasValue = isset($options[$alias]) ? trim((string) $options[$alias]) : '';
    if ($primaryValue !== '' && $aliasValue !== '' && $primaryValue !== $aliasValue) {
        throw new InvalidArgumentException("--{$primary} e --{$alias} informam valores diferentes.");
    }
    return $primaryValue !== '' ? $primaryValue : ($aliasValue !== '' ? $aliasValue : $default);
}

function rcrTableExists(PDO $pdo, string $table): bool
{
    try {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1'
        );
        $st->execute([':t' => $table]);
        return (int) $st->fetchColumn() > 0;
    } catch (\Throwable) {
        return false;
    }
}

function rcrColumnExists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
        );
        $stmt->execute(['table' => $table, 'column' => $column]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (\Throwable) {
        return false;
    }
}

/**
 * Build list of YYYY-MM strings covering [startDate .. endDate].
 *
 * @return list<string>
 */
function rcrBuildMonths(string $startDate, string $endDate): array
{
    $months = [];
    $cursor = new DateTimeImmutable(substr($startDate, 0, 7) . '-01');
    $endDt  = new DateTimeImmutable(substr($endDate, 0, 7) . '-01');
    while ($cursor <= $endDt) {
        $months[] = $cursor->format('Y-m');
        $cursor   = $cursor->modify('+1 month');
    }
    return $months;
}

/**
 * Coverage check for one month — same logic as commercial_monthly_coverage_audit.
 *
 * @return array<string,mixed>
 */
function rcrMonthCoverage(
    PDO $pdo,
    int $companyId,
    string $channel,
    string $ym,
    string $first,
    string $last,
    bool $isFullMonth,
    bool $hasVendorDayMart,
    bool $hasClientMart,
    bool $hasProdMart,
    bool $hasSnapshots,
): array
{
    // NF count
    $nfCount = 0;
    $canonicalRevenue = 0.0;
    $canonicalStatus = 'ok';
    try {
        $commercialBase = FiscalValueBasis::preComputedBaseSql();
        $st = $pdo->prepare(
            "SELECT COUNT(*) AS nfs,
                    COALESCE(SUM({$commercialBase}), 0) AS revenue
               FROM biomie_notas_fiscais_canonicas
             WHERE status_fiscal = 'autorizada'
               AND operation_scope = 'commercial'
               AND COALESCE(is_biomie_commercial_visible, 1) = 1
               AND company_id = :company_id
               AND business_channel = :business_channel
               AND COALESCE(data_competencia, data_emissao) >= :first
               AND COALESCE(data_competencia, data_emissao) < DATE_ADD(:last, INTERVAL 1 DAY)"
        );
        $st->execute([
            ':company_id' => $companyId,
            ':business_channel' => $channel,
            ':first' => $first,
            ':last' => $last,
        ]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $nfCount = (int) ($row['nfs'] ?? 0);
        $canonicalRevenue = (float) ($row['revenue'] ?? 0);
    } catch (\Throwable) {
        $nfCount = -1;
        $canonicalStatus = 'error';
    }

    // Gold daily grain: this is the contract used by the evolution chart and
    // must reconcile exactly with canonical NF count and commercial revenue.
    $martVendorStatus = 'not_found';
    $martVendorRows = 0;
    $martVendorNfs = 0;
    $martVendorRevenue = 0.0;
    if ($hasVendorDayMart) {
        try {
            $channelAware = rcrColumnExists($pdo, 'mart_vendedor_dia', 'business_channel');
            $channelFilter = $channelAware ? ' AND business_channel = :business_channel' : '';
            $st = $pdo->prepare(
                'SELECT COUNT(*) AS rows_count,
                        COALESCE(SUM(nfes_emitidas), 0) AS nfs,
                        COALESCE(SUM(valor_nfe), 0) AS revenue
                   FROM mart_vendedor_dia
                  WHERE company_id = :company_id
                    AND dia >= :first
                    AND dia < DATE_ADD(:last, INTERVAL 1 DAY)' . $channelFilter
            );
            $params = [
                ':company_id' => $companyId,
                ':first' => $first,
                ':last' => $last,
            ];
            if ($channelAware) {
                $params[':business_channel'] = $channel;
            }
            $st->execute($params);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $martVendorRows = (int) ($row['rows_count'] ?? 0);
            $martVendorNfs = (int) ($row['nfs'] ?? 0);
            $martVendorRevenue = (float) ($row['revenue'] ?? 0);
            $martVendorStatus = $martVendorRows > 0 ? 'ok' : 'empty';
        } catch (\Throwable) {
            $martVendorStatus = 'error';
        }
    }

    // Mart cliente status
    $martClienteStatus = 'not_checked';
    $martClienteRows   = 0;
    $martClienteNfs = 0;
    $martClienteRevenue = 0.0;
    if (!$isFullMonth) {
        $martClienteStatus = 'not_applicable_partial_month';
    } elseif ($hasClientMart) {
        try {
            $channelAware = rcrColumnExists($pdo, 'mart_cliente_periodo', 'business_channel');
            $channelFilter = $channelAware ? ' AND business_channel = :business_channel' : '';
            $st = $pdo->prepare(
                'SELECT COUNT(*) AS rows_count,
                        COALESCE(SUM(nfes_emitidas), 0) AS nfs,
                        COALESCE(SUM(valor_nfe), 0) AS revenue
                   FROM mart_cliente_periodo
                  WHERE company_id = :company_id AND competencia = :ym' . $channelFilter
            );
            $params = [':company_id' => $companyId, ':ym' => $ym];
            if ($channelAware) {
                $params[':business_channel'] = $channel;
            }
            $st->execute($params);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $martClienteRows = (int) ($row['rows_count'] ?? 0);
            $martClienteNfs = (int) ($row['nfs'] ?? 0);
            $martClienteRevenue = (float) ($row['revenue'] ?? 0);
            $martClienteStatus = $martClienteRows > 0 ? 'ok' : 'empty';
        } catch (\Throwable) {
            $martClienteStatus = 'error';
        }
    } else {
        $martClienteStatus = 'not_found';
    }

    // Mart produto status
    $martProdutoStatus = 'not_checked';
    if (!$isFullMonth) {
        $martProdutoStatus = 'not_applicable_partial_month';
    } elseif ($hasProdMart) {
        try {
            $channelAware = rcrColumnExists($pdo, 'mart_produto_periodo', 'business_channel');
            $channelFilter = $channelAware ? ' AND business_channel = :business_channel' : '';
            $st = $pdo->prepare(
                'SELECT COUNT(*) FROM mart_produto_periodo
                  WHERE company_id = :company_id AND competencia = :ym' . $channelFilter
            );
            $params = [':company_id' => $companyId, ':ym' => $ym];
            if ($channelAware) {
                $params[':business_channel'] = $channel;
            }
            $st->execute($params);
            $martProdRows      = (int) $st->fetchColumn();
            $martProdutoStatus = $martProdRows > 0 ? 'ok' : 'empty';
        } catch (\Throwable) {
            $martProdutoStatus = 'error';
        }
    } else {
        $martProdutoStatus = 'not_found';
    }

    // Determine status
    $hasNf = $nfCount > 0;
    $hasMartSignal = $martClienteStatus === 'ok'
        && ($martClienteNfs > 0 || $martClienteRevenue > 0);
    $martMatchesCanonical = $hasMartSignal
        && $martClienteNfs === $nfCount
        && abs($martClienteRevenue - $canonicalRevenue) <= 0.01;
    $hasVendorSignal = $martVendorStatus === 'ok'
        && ($martVendorNfs > 0 || $martVendorRevenue > 0);
    $vendorMartMatchesCanonical = in_array($martVendorStatus, ['ok', 'empty'], true)
        && $martVendorNfs === $nfCount
        && abs($martVendorRevenue - $canonicalRevenue) <= 0.01;

    $recommendation = 'ok';
    if ($canonicalStatus !== 'ok'
        || in_array('error', [$martVendorStatus, $martClienteStatus, $martProdutoStatus], true)
    ) {
        $recommendation = 'diagnostic_error';
    } elseif (!$isFullMonth) {
        $recommendation = 'partial_period_read_only';
    } elseif (!$hasNf && !$hasMartSignal && !$hasVendorSignal) {
        $recommendation = 'no_data';
    } elseif ($hasNf && (!$martMatchesCanonical || !$vendorMartMatchesCanonical)) {
        $recommendation = 'materialize_needed';
    } elseif (!$hasNf && ($hasMartSignal || $hasVendorSignal)) {
        // Gold is derived from canonical. If only Gold has data, clear the
        // stale derivative before considering any external source recovery.
        $recommendation = 'materialize_needed';
    }

    $snapshot = $isFullMonth
        ? rcrSnapshotStatus($pdo, $companyId, $channel, $ym, $nfCount, $canonicalRevenue, $hasSnapshots)
        : ['status' => 'not_applicable_partial_month', 'reason' => 'monthly_granularity'];
    if ((string) ($snapshot['status'] ?? '') === 'error') {
        $recommendation = 'diagnostic_error';
    }

    return [
        'month'               => $ym,
        'window'              => ['start' => $first, 'end' => $last, 'is_full_month' => $isFullMonth],
        'canonical_nfs'       => $nfCount,
        'canonical_revenue'   => round($canonicalRevenue, 2),
        'canonical_status'    => $canonicalStatus,
        'mart_vendedor_status' => $martVendorStatus,
        'mart_vendedor_rows' => $martVendorRows,
        'mart_vendedor_nfs' => $martVendorNfs,
        'mart_vendedor_revenue' => round($martVendorRevenue, 2),
        'mart_cliente_status' => $martClienteStatus,
        'mart_cliente_nfs' => $martClienteNfs,
        'mart_cliente_revenue' => round($martClienteRevenue, 2),
        'mart_produto_status' => $martProdutoStatus,
        'snapshot_status'     => $snapshot['status'],
        'snapshot_reason'     => $snapshot['reason'],
        'recommendation'      => $recommendation,
    ];
}

/** @return array{status:string,reason:?string} */
function rcrSnapshotStatus(
    PDO $pdo,
    int $companyId,
    string $channel,
    string $ym,
    int $canonicalNfs,
    float $canonicalRevenue,
    bool $exists,
): array
{
    if (!$exists) {
        return ['status' => 'not_found', 'reason' => 'snapshot_table_missing'];
    }

    foreach (['biomie_competencia_snapshots', 'biomie_competencia_sync_catalog'] as $table) {
        if (!rcrColumnExists($pdo, $table, 'business_channel')
            || !rcrColumnExists($pdo, $table, 'visibility_scope')
        ) {
            return ['status' => 'stale', 'reason' => 'snapshot_visibility_schema_missing'];
        }
    }

    try {
        $visibilityScope = rcrVisibilityScope($companyId, $channel);
        $stmt = $pdo->prepare(
            'SELECT s.overview_payload, s.schema_version, s.generated_at, s.last_sync,
                    c.status AS catalog_status
               FROM biomie_competencia_snapshots s
          LEFT JOIN biomie_competencia_sync_catalog c
                 ON c.company_id = s.company_id
                AND c.business_channel = s.business_channel
                AND c.visibility_scope = s.visibility_scope
                AND c.competencia = s.competencia
              WHERE s.company_id = :company_id
                AND s.business_channel = :business_channel
                AND s.visibility_scope = :visibility_scope
                AND s.competencia = :ym
              LIMIT 1'
        );
        $stmt->execute([
            ':company_id' => $companyId,
            ':business_channel' => $channel,
            ':visibility_scope' => $visibilityScope,
            ':ym' => $ym,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['status' => 'missing', 'reason' => 'snapshot_missing'];
        }

        $payload = json_decode((string) ($row['overview_payload'] ?? ''), true);
        if (!is_array($payload)) {
            return ['status' => 'stale', 'reason' => 'snapshot_invalid_json'];
        }
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
        $snapshotNfs = (int) ($summary['orders'] ?? $summary['billed_orders'] ?? 0);
        // The canonical comparison uses the commercial basis. Gross revenue
        // remains a fallback solely for historical snapshot schemas.
        $snapshotRevenue = (float) ($summary['revenue'] ?? $summary['gross_revenue'] ?? $summary['billed_value'] ?? 0);
        if ((int) ($row['schema_version'] ?? 0) !== rcrExpectedSnapshotSchemaVersion()) {
            return ['status' => 'stale', 'reason' => 'snapshot_schema_version_mismatch'];
        }
        if ((string) ($row['catalog_status'] ?? '') !== 'ready') {
            return ['status' => 'stale', 'reason' => 'catalog_not_ready'];
        }
        $first = $ym . '-01';
        $last = date('Y-m-t', strtotime($first));
        $visibilityFilter = $channel === 'licitacao'
            ? ''
            : ' AND COALESCE(is_biomie_commercial_visible, 1) = 1';
        $freshnessStmt = $pdo->prepare(
            "SELECT MAX(COALESCE(sincronizado_em, updated_at))
              FROM biomie_notas_fiscais_canonicas
              WHERE company_id = :company_id
                AND business_channel = :business_channel
                AND operation_scope = 'commercial'
                {$visibilityFilter}
                AND COALESCE(data_competencia, data_emissao) BETWEEN :first AND :last"
        );
        $freshnessStmt->execute([
            ':company_id' => $companyId,
            ':business_channel' => $channel,
            ':first' => $first,
            ':last' => $last,
        ]);
        $canonicalLastSync = $freshnessStmt->fetchColumn();
        if (is_string($canonicalLastSync) && $canonicalLastSync !== '' && (
            empty($row['last_sync'])
            || strtotime((string) $row['last_sync']) < strtotime($canonicalLastSync)
        )) {
            return ['status' => 'stale', 'reason' => 'snapshot_older_than_canonical'];
        }
        if ($canonicalNfs > 0 && ($snapshotNfs === 0 || $snapshotRevenue <= 0)) {
            return ['status' => 'stale', 'reason' => 'snapshot_zero_with_canonical_data'];
        }
        if ($snapshotNfs !== max(0, $canonicalNfs)) {
            return ['status' => 'stale', 'reason' => 'snapshot_count_divergent'];
        }
        if (abs($snapshotRevenue - $canonicalRevenue) > 0.01) {
            return ['status' => 'stale', 'reason' => 'snapshot_revenue_divergent'];
        }
        return ['status' => 'ok', 'reason' => null];
    } catch (\Throwable $exception) {
        return ['status' => 'error', 'reason' => $exception->getMessage()];
    }
}

function rcrVisibilityScope(int $companyId, string $channel): string
{
    return substr(hash('sha256', implode('|', [
        (string) $companyId,
        $channel,
        (string) $companyId,
    ])), 0, 32);
}

function rcrExpectedSnapshotSchemaVersion(): int
{
    try {
        $reflection = new ReflectionClass(CommercialCompetenciaSnapshotService::class);
        $constant = $reflection->getReflectionConstant('SCHEMA_VERSION');
        return $constant instanceof ReflectionClassConstant ? (int) $constant->getValue() : 1;
    } catch (\Throwable) {
        return 1;
    }
}

/**
 * Build list of recovery actions for a month based on its coverage status.
 *
 * @param  array<string,mixed> $cov
 * @param  list<string>        $domains
 * @return list<array<string,mixed>>
 */
function rcrBuildActions(
    array $cov,
    array $domains,
    string $companySlug,
    string $root,
    string $php,
): array
{
    $ym  = $cov['month'];
    $rec = $cov['recommendation'];
    $acts = [];

    // First day / last day of month
    $first = $ym . '-01';
    $last  = date('Y-m-t', strtotime($first));

    if (in_array($rec, ['partial_period_read_only', 'diagnostic_error'], true)) {
        return [];
    }

    if ($rec === 'no_data' || $rec === 'backfill_needed') {
        // Need to backfill commercial data from Omie
        if (in_array('commercial', $domains, true)) {
            $acts[] = [
                'type'        => 'backfill',
                'description' => "Backfill comercial idempotente para {$ym}",
                'cmd'         => sprintf(
                    '%s %s --company=%s --start=%s --end=%s --chunk=day --yes',
                    escapeshellarg($php),
                    escapeshellarg($root . '/bin/omie_nfe_backfill.php'),
                    escapeshellarg($companySlug),
                    escapeshellarg($first),
                    escapeshellarg($last)
                ),
            ];
        }
    }

    if ($rec === 'materialize_needed' || $rec === 'backfill_needed' || $rec === 'no_data') {
        // Materialize Gold marts
        if (in_array('gold', $domains, true)) {
            $acts[] = [
                'type'        => 'materialize',
                'description' => "Materialize Gold marts for {$ym}",
                'cmd'         => sprintf(
                    '%s %s --company=%s --start=%s --end=%s --force',
                    escapeshellarg($php),
                    escapeshellarg($root . '/bin/materialize_marts.php'),
                    escapeshellarg($companySlug),
                    escapeshellarg($first),
                    escapeshellarg($last)
                ),
            ];
        }
    }

    $snapshotStatus = (string) ($cov['snapshot_status'] ?? 'missing');
    $isClosedMonth = $ym < date('Y-m');
    if ($isClosedMonth
        && in_array('snapshots', $domains, true)
        && ($snapshotStatus !== 'ok' || $rec !== 'ok')
    ) {
        $acts[] = [
            'type'        => 'snapshot',
            'description' => "Rebuild do snapshot historico de {$ym}",
            'cmd'         => sprintf(
                '%s %s --company=%s --competencia=%s --force',
                escapeshellarg($php),
                escapeshellarg($root . '/cron/materialize_competencia_snapshots.php'),
                escapeshellarg($companySlug),
                escapeshellarg($ym)
            ),
        ];
    }

    if ($acts !== []) {
        $acts[] = [
            'type'        => 'reconcile',
            'description' => "Reconciliacao final, somente leitura, de {$ym}",
            'cmd'         => sprintf(
                '%s %s --company=%s --start=%s --end=%s --json',
                escapeshellarg($php),
                escapeshellarg($root . '/tools/dev/commercial_layer_reconciliation.php'),
                escapeshellarg($companySlug),
                escapeshellarg($first),
                escapeshellarg($last)
            ),
        ];
    }

    return $acts;
}

/**
 * Execute a single command via proc_open and return result.
 *
 * @return array{exit:int,stdout:string,stderr:string,duration_ms:int,timed_out:bool}
 */
function rcrRunCommand(string $cmd, int $timeoutSeconds): array
{
    $desc = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $t0      = microtime(true);
    $process = proc_open($cmd, $desc, $pipes, null, null, ['bypass_shell' => true]);

    if (!is_resource($process)) {
        return ['exit' => 1, 'stdout' => '', 'stderr' => 'proc_open failed', 'duration_ms' => 0, 'timed_out' => false];
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $timedOut = false;
    $exitCode = -1;
    $maxOutputBytes = 2 * 1024 * 1024;

    while (true) {
        rcrDrainPipe($pipes[1], $stdout, $maxOutputBytes);
        rcrDrainPipe($pipes[2], $stderr, $maxOutputBytes);
        $status = proc_get_status($process);
        if (!($status['running'] ?? false)) {
            $exitCode = (int) ($status['exitcode'] ?? -1);
            break;
        }
        if ((microtime(true) - $t0) >= max(1, $timeoutSeconds)) {
            $timedOut = true;
            proc_terminate($process);
            $deadline = microtime(true) + 2.0;
            do {
                rcrDrainPipe($pipes[1], $stdout, $maxOutputBytes);
                rcrDrainPipe($pipes[2], $stderr, $maxOutputBytes);
                $status = proc_get_status($process);
                if (!($status['running'] ?? false)) {
                    $exitCode = (int) ($status['exitcode'] ?? 124);
                    break;
                }
                usleep(20_000);
            } while (microtime(true) < $deadline);
            if ($status['running'] ?? false) {
                proc_terminate($process, 9);
            }
            $exitCode = 124;
            $stderr .= ($stderr !== '' ? PHP_EOL : '') . "Timeout after {$timeoutSeconds}s.";
            break;
        }
        usleep(20_000);
    }

    rcrDrainPipe($pipes[1], $stdout, $maxOutputBytes);
    rcrDrainPipe($pipes[2], $stderr, $maxOutputBytes);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closeExit = proc_close($process);
    if ($exitCode < 0 && $closeExit >= 0) {
        $exitCode = $closeExit;
    }

    return [
        'exit'        => $exitCode,
        'stdout'      => trim($stdout),
        'stderr'      => trim($stderr),
        'duration_ms' => (int) ((microtime(true) - $t0) * 1000),
        'timed_out'   => $timedOut,
    ];
}

/** @param resource $pipe */
function rcrDrainPipe($pipe, string &$buffer, int $maxBytes): void
{
    $chunk = stream_get_contents($pipe);
    if (!is_string($chunk) || $chunk === '' || strlen($buffer) >= $maxBytes) {
        return;
    }
    $remaining = $maxBytes - strlen($buffer);
    $buffer .= substr($chunk, 0, $remaining);
}

function rcrActionTimeoutSeconds(string $type): int
{
    return match ($type) {
        'backfill' => 1800,
        'materialize' => 900,
        'snapshot' => 600,
        'reconcile' => 180,
        default => 300,
    };
}

// ── Coverage analysis ─────────────────────────────────────────────────────────

$months       = rcrBuildMonths($startDate, $endDate);
$hasVendorDayMart = rcrTableExists($pdo, 'mart_vendedor_dia');
$hasClientMart = rcrTableExists($pdo, 'mart_cliente_periodo');
$hasProdMart   = rcrTableExists($pdo, 'mart_produto_periodo');
$hasSnapshots  = rcrTableExists($pdo, 'biomie_competencia_snapshots');

$monthResults = [];
$allActions   = [];

foreach ($months as $ym) {
    $monthFirst = $ym . '-01';
    $monthLast = date('Y-m-t', strtotime($monthFirst));
    $windowStart = max($startDate, $monthFirst);
    $windowEnd = min($endDate, $monthLast);
    $isFullMonth = $windowStart === $monthFirst && $windowEnd === $monthLast;
    $cov = rcrMonthCoverage(
        $pdo,
        $tenant['company_id'],
        $tenant['canal'],
        $ym,
        $windowStart,
        $windowEnd,
        $isFullMonth,
        $hasVendorDayMart,
        $hasClientMart,
        $hasProdMart,
        $hasSnapshots,
    );
    $actions = rcrBuildActions($cov, $domains, $tenant['slug'], $root, $php);

    $monthResults[] = array_merge($cov, ['actions' => $actions]);
    foreach ($actions as $act) {
        $allActions[] = array_merge($act, ['month' => $ym]);
    }
}

$totalActions = count($allActions);
$diagnosticMonths = array_values(array_map(
    static fn (array $month): string => (string) $month['month'],
    array_filter(
        $monthResults,
        static fn (array $month): bool => (string) ($month['recommendation'] ?? '') === 'diagnostic_error',
    ),
));
$diagnosticErrors = count($diagnosticMonths);
$exitCode = $diagnosticErrors > 0 ? 2 : ($totalActions > 0 ? 1 : 0);

// ── Apply mode — execute ──────────────────────────────────────────────────────

$applyLog    = null;
$applyErrors = 0;

if ($applyMode) {
    $logDir  = $root . '/storage/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $logFile = $logDir . '/recover_commercial_range_' . date('Ymd_His') . '.json';

    $applyLog = [
        'tool'        => 'recover_commercial_range',
        'mode'        => 'apply',
        'started_at'  => date('c'),
        'start'       => $startDate,
        'end'         => $endDate,
        'company'     => ['id' => $tenant['company_id'], 'slug' => $tenant['slug']],
        'domains'     => $domains,
        'steps'       => [],
    ];

    if ($diagnosticErrors > 0) {
        $applyErrors = $diagnosticErrors;
        $applyLog['aborted'] = true;
        $applyLog['abort_reason'] = sprintf(
            'Precondicao falhou: consulta canonica indisponivel em %s. Nenhuma acao foi executada.',
            implode(', ', $diagnosticMonths),
        );
    }

    foreach ($diagnosticErrors > 0 ? [] : $allActions as $idx => $act) {
        $stepNum = $idx + 1;
        $timeoutSeconds = rcrActionTimeoutSeconds((string) $act['type']);
        $result  = rcrRunCommand($act['cmd'], $timeoutSeconds);

        $step = [
            'step'        => $stepNum,
            'month'       => $act['month'],
            'type'        => $act['type'],
            'description' => $act['description'],
            'cmd'         => $act['cmd'],
            'exit_code'   => $result['exit'],
            'stdout'      => mb_substr($result['stdout'], 0, 4000),
            'stderr'      => mb_substr($result['stderr'], 0, 2000),
            'duration_ms' => $result['duration_ms'],
            'timeout_seconds' => $timeoutSeconds,
            'timed_out' => $result['timed_out'],
            'status'      => $result['exit'] === 0 ? 'success' : 'failed',
        ];

        $applyLog['steps'][] = $step;

        if ($result['exit'] !== 0) {
            $applyErrors++;
            $applyLog['aborted'] = true;
            $applyLog['abort_reason'] = sprintf(
                'Fail-fast: etapa %d (%s/%s) falhou; dependencias seguintes nao foram executadas.',
                $stepNum,
                (string) $act['month'],
                (string) $act['type'],
            );
            break;
        }
    }

    $applyLog['finished_at'] = date('c');
    $applyLog['errors']      = $applyErrors;
    $applyLog['all_ok']      = $applyErrors === 0;
    $applyLog['executed_steps'] = count($applyLog['steps']);
    $applyLog['skipped_steps'] = max(0, count($allActions) - count($applyLog['steps']));

    file_put_contents($logFile, json_encode($applyLog, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    $exitCode = $applyErrors > 0 ? 1 : 0;
}

// ── Output ────────────────────────────────────────────────────────────────────

if ($isJson) {
    $out = [
        'tool'          => 'recover_commercial_range',
        'mode'          => $isDryRun ? 'dry_run' : 'apply',
        'start'         => $startDate,
        'end'           => $endDate,
        'company'       => ['id' => $tenant['company_id'], 'slug' => $tenant['slug']],
        'domains'       => $domains,
        'months'        => $monthResults,
        'total_actions' => $totalActions,
        'diagnostic_errors' => $diagnosticErrors,
        'diagnostic_months' => $diagnosticMonths,
    ];

    if ($applyMode && $applyLog !== null) {
        $out['apply_log'] = $applyLog;
        $out['errors']    = $applyErrors;
    }

    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit($exitCode);
}

// Plain / pretty
$modeLabel = $isDryRun ? '(DRY-RUN)' : '(APPLY)';
echo "=== recover_commercial_range {$modeLabel} ===\n";
echo "Company: {$tenant['slug']} (id={$tenant['company_id']})\n";
echo "Range: {$startDate} to {$endDate}\n";
echo "Domains: " . implode(', ', $domains) . "\n\n";

if ($applyMode) {
    echo str_repeat('!', 80) . "\n";
    echo "!! WARNING: APPLY MODE — actions are being executed on the database      !!\n";
    echo "!! Only use in production with explicit authorization                    !!\n";
    echo str_repeat('!', 80) . "\n\n";
}

foreach ($monthResults as $mr) {
    $ym    = $mr['month'];
    $nfs   = $mr['canonical_nfs'];
    $rec   = $mr['recommendation'];
    $mcs   = strtoupper($mr['mart_cliente_status']);
    $mvs   = strtoupper((string) ($mr['mart_vendedor_status'] ?? 'unknown'));
    $snapshotStatus = strtoupper((string) ($mr['snapshot_status'] ?? 'unknown'));
    $acts  = $mr['actions'];

    $recLabel = match ($rec) {
        'ok'                 => '[OK]',
        'backfill_needed'    => '[backfill_needed]',
        'materialize_needed' => '[materialize_needed]',
        'partial_period_read_only' => '[not_applicable_partial_month]',
        default              => '[no_data]',
    };

    $nfsLabel = $nfs >= 0 ? (string) $nfs : '?';

    printf(
        "Month %s:\n  NFs canônicas: %-5s | Gold diário: %s | Mart cliente: %s | Snapshot: %s | Status: %s\n",
        $ym,
        $nfsLabel,
        $mvs,
        $mcs,
        $snapshotStatus,
        $rec
    );

    if (empty($acts)) {
        echo "  Nenhuma ação necessária.\n";
    } else {
        foreach ($acts as $act) {
            if ($applyMode && $applyLog !== null) {
                // Find result in log
                $stepResult = null;
                foreach ($applyLog['steps'] as $step) {
                    if ($step['month'] === $ym && $step['type'] === $act['type']) {
                        $stepResult = $step;
                        break;
                    }
                }
                if ($stepResult !== null) {
                    $statusIcon = $stepResult['exit_code'] === 0 ? '[OK]' : '[FAILED]';
                    printf(
                        "  RAN (%s): %s\n       Duration: %dms\n",
                        $statusIcon,
                        $act['description'],
                        $stepResult['duration_ms']
                    );
                    if ($stepResult['exit_code'] !== 0 && $stepResult['stderr'] !== '') {
                        printf("       STDERR: %s\n", mb_substr($stepResult['stderr'], 0, 200));
                    }
                } else {
                    printf("  RAN: %s\n", $act['description']);
                }
            } else {
                printf(
                    "  WOULD RUN: %s\n",
                    $act['cmd']
                );
            }
        }
    }

    echo "\n";
}

if ($isDryRun) {
    echo "Total actions needed: {$totalActions}\n";
    if ($totalActions > 0) {
        echo "Run with --apply to execute (use with caution in production).\n";
    } else {
        echo "All months are up-to-date. No recovery actions needed.\n";
    }
} else {
    echo "Apply complete.\n";
    echo "Total actions: {$totalActions} | Errors: {$applyErrors}\n";
    if ($applyLog !== null) {
        $logDir  = $root . '/storage/logs';
        echo "Log saved to storage/logs/ (recover_commercial_range_*.json)\n";
    }
}

exit($exitCode);

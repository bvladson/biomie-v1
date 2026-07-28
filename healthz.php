<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Http;
use BIomie\Logger;

require_once __DIR__ . '/app/Bootstrap.php';
Bootstrap::init();

$config = new Config();
$logger = new Logger(Bootstrap::rootPath('storage/logs/biomie.log'));

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Gate full diagnostic details behind a token or loopback origin.
// Without it, only the minimal liveness signal is returned — safe for public monitors.
$fullDetails = false;
$internalToken = Http::headerValue('X-Healthz-Token') ?? Http::query('_hk', '') ?? '';
if ($internalToken !== '') {
    $secret = (string) getenv('BIOMIE_SECRET_KEY');
    $expectedToken = substr(hash_hmac('sha256', 'healthz:full', $secret), 0, 32);
    $fullDetails = hash_equals($expectedToken, $internalToken);
}
if (!$fullDetails) {
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
    $fullDetails = in_array($remoteAddr, ['127.0.0.1', '::1'], true);
}

try {
    if (!$fullDetails) {
        // Minimal liveness check for public uptime monitors
        try {
            $conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
            $pdo  = Database::connect($config, $conn);
            $pdo->query('SELECT 1');
            echo json_encode(['ok' => true,  'timestamp' => date(DATE_ATOM)]);
        } catch (\Throwable) {
            http_response_code(503);
            echo json_encode(['ok' => false, 'timestamp' => date(DATE_ATOM)]);
        }
        exit;
    }

    $range = Http::resolveDateRange();
    Http::validateDateRange($range['start'], $range['end']);

    $readsEnabled = [
        'vendors' => $config->isCanonicalEnabled('vendors'),
        'orders' => $config->isCanonicalEnabled('orders'),
        'overview' => $config->isCanonicalEnabled('overview'),
    ];
    $diag = [
        'ok' => true,
        'app' => (string) $config->get('app.name', 'BIomie'),
        'timestamp' => date(DATE_ATOM),
        'period' => $range,
        'canonical' => biomieHealthCanonicalDefaults($config, $readsEnabled),
        'sources' => biomieHealthSourceDefaults(),
        'data_contract' => biomieHealthDataContractDefaults(),
        'cron_path_expected' => __DIR__ . '/cron/',
    ];

    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    if (!Database::isConfigured($config, $connection)) {
        $diag['ok'] = false;
        $diag['db_connected'] = false;
        $diag['db_error'] = 'database_not_configured';
    } else {
        try {
            $pdo = Database::connect($config, $connection);
            $pdo->query('SELECT 1');
            $diag['db_connected'] = true;

            $tables = biomieHealthAvailableTables($pdo, [
                'biomie_vendedores_omie',
                'biomie_pedidos_canonicos',
                'biomie_notas_fiscais_canonicas',
                'biomie_notas_fiscais_itens_canonicos',
                'biomie_titulos_financeiros_canonicos',
                'biomie_divergencias',
                'biomie_sync_cursors',
                'biomie_sync_runs',
            ]);
            $required = [
                'biomie_vendedores_omie',
                'biomie_pedidos_canonicos',
                'biomie_notas_fiscais_canonicas',
                'biomie_divergencias',
            ];
            $tablesPresent = count(array_intersect($required, array_keys($tables))) === count($required);

            if ($tablesPresent) {
                $metrics = biomieHealthCanonicalMetrics($pdo, $tables, $range['start'], $range['end']);
                $lastSyncPeriod = biomieHealthNullableString($metrics['last_sync_period'] ?? null);
                $diag['canonical'] = [
                    'tables_present' => true,
                    'vendor_count' => (int) ($metrics['vendor_count'] ?? 0),
                    'pending_order_count' => (int) ($metrics['order_count'] ?? 0),
                    'invoice_count' => (int) ($metrics['invoice_count'] ?? 0),
                    'invoice_item_count' => (int) ($metrics['invoice_item_count'] ?? 0),
                    'last_sync_period' => $lastSyncPeriod,
                    'ready_for_period' => $lastSyncPeriod !== null,
                    'fiscal_ready_flag' => (bool) $config->get('features.canonical_fiscal_ready', false),
                    'reads_enabled' => $readsEnabled,
                ];
                $diag['sources'] = [
                    'vendors' => $readsEnabled['vendors'] && $lastSyncPeriod !== null ? 'canonical' : 'legacy',
                    'orders' => $readsEnabled['orders'] && $lastSyncPeriod !== null ? 'canonical' : 'legacy',
                    'overview_ranking' => $readsEnabled['overview'] && $lastSyncPeriod !== null ? 'canonical' : 'legacy',
                    'flight_plan_targets' => 'dynamic_fallback',
                ];
                biomieHealthApplyCoverage($diag['data_contract'], $metrics, $readsEnabled['overview']);
            }

            biomieHealthApplyPipelineState($pdo, $tables, $diag['data_contract']);
        } catch (Throwable $exception) {
            $diag['ok'] = false;
            $diag['db_connected'] = false;
            $diag['db_error'] = 'database_unavailable';
            $logger->error('Healthcheck sem acesso ao banco.', [
                'connection' => $connection,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    if ($diag['ok'] !== true) {
        http_response_code(503);
    }

    echo json_encode($diag, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $exception) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'message' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(500);
    $logger->error('Falha em healthz.', [
        'message' => $exception->getMessage(),
        'trace' => $exception->getTraceAsString(),
    ]);
    echo json_encode([
        'ok' => false,
        'message' => 'Erro interno no healthcheck.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * @param array<string,bool> $readsEnabled
 * @return array<string,mixed>
 */
function biomieHealthCanonicalDefaults(Config $config, array $readsEnabled): array
{
    return [
        'tables_present' => false,
        'vendor_count' => 0,
        'pending_order_count' => 0,
        'invoice_count' => 0,
        'invoice_item_count' => 0,
        'last_sync_period' => null,
        'ready_for_period' => false,
        'fiscal_ready_flag' => (bool) $config->get('features.canonical_fiscal_ready', false),
        'reads_enabled' => $readsEnabled,
    ];
}

/** @return array<string,string> */
function biomieHealthSourceDefaults(): array
{
    return [
        'vendors' => 'legacy',
        'orders' => 'legacy',
        'overview_ranking' => 'legacy',
        'flight_plan_targets' => 'dynamic_fallback',
    ];
}

/** @return array<string,array<string,string|null>> */
function biomieHealthDataContractDefaults(): array
{
    $default = [
        'source_of_truth' => 'unknown',
        'coverage_start' => null,
        'coverage_end' => null,
        'last_success_at' => null,
        'last_reconcile_at' => null,
    ];

    return [
        'comercial' => $default,
        'fiscal' => $default,
        'financeiro' => $default,
    ];
}

/**
 * @param list<string> $requested
 * @return array<string,true>
 */
function biomieHealthAvailableTables(\PDO $pdo, array $requested): array
{
    $placeholders = implode(',', array_fill(0, count($requested), '?'));
    $stmt = $pdo->prepare(
        "SELECT TABLE_NAME
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME IN ({$placeholders})"
    );
    $stmt->execute($requested);
    $names = array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);
    return array_fill_keys($names, true);
}

/**
 * @param array<string,true> $tables
 * @return array<string,mixed>
 */
function biomieHealthCanonicalMetrics(\PDO $pdo, array $tables, string $start, string $end): array
{
    $invoiceItemCount = isset($tables['biomie_notas_fiscais_itens_canonicos'])
        ? '(SELECT COUNT(*) FROM biomie_notas_fiscais_itens_canonicos)'
        : '0';
    $financeFields = isset($tables['biomie_titulos_financeiros_canonicos'])
        ? <<<'SQL'
            (SELECT MIN(data_vencimento) FROM biomie_titulos_financeiros_canonicos) AS finance_start,
            (SELECT MAX(data_vencimento) FROM biomie_titulos_financeiros_canonicos) AS finance_end,
            (SELECT MAX(sincronizado_em) FROM biomie_titulos_financeiros_canonicos) AS finance_sync
        SQL
        : 'NULL AS finance_start, NULL AS finance_end, NULL AS finance_sync';

    $sql = <<<SQL
        SELECT
            (SELECT COUNT(*) FROM biomie_vendedores_omie) AS vendor_count,
            (SELECT COUNT(*) FROM biomie_pedidos_canonicos) AS order_count,
            (SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas) AS invoice_count,
            {$invoiceItemCount} AS invoice_item_count,
            (
                SELECT MAX(sincronizado_em)
                FROM biomie_pedidos_canonicos
                WHERE data_emissao BETWEEN :period_start AND :period_end
            ) AS last_sync_period,
            (SELECT MIN(data_emissao) FROM biomie_pedidos_canonicos) AS commercial_start,
            (SELECT MAX(data_emissao) FROM biomie_pedidos_canonicos) AS commercial_end,
            (SELECT MAX(sincronizado_em) FROM biomie_pedidos_canonicos) AS commercial_sync,
            (SELECT MIN(data_competencia) FROM biomie_notas_fiscais_canonicas) AS fiscal_start,
            (SELECT MAX(data_competencia) FROM biomie_notas_fiscais_canonicas) AS fiscal_end,
            (SELECT MAX(sincronizado_em) FROM biomie_notas_fiscais_canonicas) AS fiscal_sync,
            {$financeFields}
    SQL;
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'period_start' => $start,
        'period_end' => $end,
    ]);
    return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
}

/**
 * @param array<string,array<string,string|null>> $contract
 * @param array<string,mixed> $metrics
 */
function biomieHealthApplyCoverage(array &$contract, array $metrics, bool $overviewEnabled): void
{
    foreach ([
        'comercial' => ['prefix' => 'commercial', 'source' => $overviewEnabled ? 'canonical' : 'legacy'],
        'fiscal' => ['prefix' => 'fiscal', 'source' => $overviewEnabled ? 'canonical' : 'legacy'],
        'financeiro' => ['prefix' => 'finance', 'source' => 'canonical'],
    ] as $domain => $definition) {
        $prefix = $definition['prefix'];
        if (biomieHealthNullableString($metrics[$prefix . '_sync'] ?? null) === null) {
            continue;
        }
        $contract[$domain]['source_of_truth'] = $definition['source'];
        $contract[$domain]['coverage_start'] = biomieHealthNullableString($metrics[$prefix . '_start'] ?? null);
        $contract[$domain]['coverage_end'] = biomieHealthNullableString($metrics[$prefix . '_end'] ?? null);
    }
}

/**
 * @param array<string,true> $tables
 * @param array<string,array<string,string|null>> $contract
 */
function biomieHealthApplyPipelineState(\PDO $pdo, array $tables, array &$contract): void
{
    $queries = [];
    if (isset($tables['biomie_sync_cursors'])) {
        $queries[] = <<<'SQL'
            SELECT 'success' AS metric, domain_name, MAX(last_success_at) AS metric_at
            FROM biomie_sync_cursors
            WHERE last_success_at IS NOT NULL
            GROUP BY domain_name
        SQL;
    }
    if (isset($tables['biomie_sync_runs'])) {
        $queries[] = <<<'SQL'
            SELECT 'reconcile' AS metric, domain_name, MAX(COALESCE(finished_at, period_end)) AS metric_at
            FROM biomie_sync_runs
            WHERE run_type = 'reconcile'
              AND status IN ('completed', 'completed_with_warnings')
            GROUP BY domain_name
        SQL;
    }
    if ($queries === []) {
        return;
    }

    $rows = $pdo->query(implode(' UNION ALL ', $queries))->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $row) {
        $domain = biomieHealthDomainKey((string) ($row['domain_name'] ?? ''));
        $metricAt = biomieHealthNullableString($row['metric_at'] ?? null);
        if ($domain === null || $metricAt === null) {
            continue;
        }
        $field = (string) ($row['metric'] ?? '') === 'reconcile'
            ? 'last_reconcile_at'
            : 'last_success_at';
        $current = $contract[$domain][$field] ?? null;
        if ($current === null || strcmp($metricAt, $current) > 0) {
            $contract[$domain][$field] = $metricAt;
        }
    }
}

function biomieHealthDomainKey(string $domain): ?string
{
    return match (strtolower(trim($domain))) {
        'commercial', 'comercial' => 'comercial',
        'fiscal' => 'fiscal',
        'finance', 'financeiro' => 'financeiro',
        default => null,
    };
}

function biomieHealthNullableString(mixed $value): ?string
{
    $normalized = trim((string) $value);
    return $normalized !== '' ? $normalized : null;
}

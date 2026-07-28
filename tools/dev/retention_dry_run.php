<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\SchemaCapabilities;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$opts = getopt('', ['json', 'output:']);
$config = new Config();
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo = Database::connect($config, $connection);

$policies = [
    ['table' => 'biomie_omie_bronze', 'date_column' => 'ingested_at', 'days' => 180, 'action' => 'archive_before_delete'],
    ['table' => 'biomie_operational_spans', 'date_column' => 'created_at', 'days' => 30, 'action' => 'delete_in_batches_after_backup'],
    ['table' => 'biomie_operational_metrics', 'date_column' => 'observed_at', 'days' => 90, 'action' => 'aggregate_then_delete_in_batches'],
    ['table' => 'biomie_operational_events', 'date_column' => 'occurred_at', 'days' => 90, 'action' => 'delete_in_batches_after_backup'],
];

$tables = [];
$ok = true;
foreach ($policies as $policy) {
    $table = $policy['table'];
    $column = $policy['date_column'];
    if (!SchemaCapabilities::tableExists($pdo, $table)) {
        $tables[] = $policy + ['exists' => false, 'status' => 'not_available'];
        continue;
    }
    if (!SchemaCapabilities::columnExists($pdo, $table, $column)) {
        $tables[] = $policy + ['exists' => true, 'status' => 'date_column_missing'];
        $ok = false;
        continue;
    }

    try {
        $days = (int) $policy['days'];
        $quotedTable = '`' . $table . '`';
        $quotedColumn = '`' . $column . '`';
        $row = $pdo->query(sprintf(
            'SELECT COUNT(*) AS total_rows,
                    SUM(%1$s < DATE_SUB(NOW(), INTERVAL %2$d DAY)) AS eligible_rows,
                    MIN(%1$s) AS oldest_at,
                    MAX(%1$s) AS newest_at,
                    MIN(CASE WHEN %1$s < DATE_SUB(NOW(), INTERVAL %2$d DAY) THEN %1$s END) AS eligible_oldest_at,
                    MAX(CASE WHEN %1$s < DATE_SUB(NOW(), INTERVAL %2$d DAY) THEN %1$s END) AS eligible_newest_at
             FROM %3$s',
            $quotedColumn,
            $days,
            $quotedTable,
        ))->fetch(PDO::FETCH_ASSOC) ?: [];
        $sizeStmt = $pdo->prepare(
            'SELECT COALESCE(data_length, 0) + COALESCE(index_length, 0)
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
        );
        $sizeStmt->execute(['table' => $table]);
        $bytes = (int) $sizeStmt->fetchColumn();
        $eligible = (int) ($row['eligible_rows'] ?? 0);
        $total = (int) ($row['total_rows'] ?? 0);
        $tables[] = $policy + [
            'exists' => true,
            'status' => $eligible > 0 ? 'retention_candidate' : 'within_policy',
            'total_rows' => $total,
            'eligible_rows' => $eligible,
            'eligible_pct' => $total > 0 ? round(($eligible / $total) * 100, 2) : 0.0,
            'estimated_batches_1000' => (int) ceil($eligible / 1000),
            'oldest_at' => $row['oldest_at'] ?? null,
            'newest_at' => $row['newest_at'] ?? null,
            'eligible_oldest_at' => $row['eligible_oldest_at'] ?? null,
            'eligible_newest_at' => $row['eligible_newest_at'] ?? null,
            'table_mb' => round($bytes / 1048576, 2),
            'eligible_estimated_mb' => $total > 0 ? round(($bytes * ($eligible / $total)) / 1048576, 2) : 0.0,
            'audit_value' => match ($table) {
                'biomie_omie_bronze' => 'source_payload_archive_required',
                'biomie_operational_metrics' => 'aggregate_before_delete',
                default => 'operational_diagnostics',
            },
            'destructive_action_executed' => false,
        ];
    } catch (Throwable $exception) {
        $tables[] = $policy + [
            'exists' => true,
            'status' => 'audit_failed',
            'error' => $exception->getMessage(),
        ];
        $ok = false;
    }
}

$payload = [
    'ok' => $ok,
    'mode' => 'dry-run',
    'connection' => $connection,
    'generated_at' => date(DATE_ATOM),
    'destructive_action_executed' => false,
    'tables' => $tables,
    'next_gate' => 'Validar backup e necessidade legal antes de qualquer exclusao.',
];

$output = isset($opts['output']) ? (string) $opts['output'] : '';
if ($output !== '') {
    $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false || file_put_contents($output, $encoded . PHP_EOL) === false) {
        throw new RuntimeException('Unable to write retention plan.');
    }
}

if (isset($opts['json'])) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($ok ? 0 : 1);
}

echo "BIomie retention dry-run\n";
foreach ($tables as $table) {
    printf(
        "  %-30s total=%8d eligible=%8d policy=%3dd status=%s\n",
        (string) $table['table'],
        (int) ($table['total_rows'] ?? 0),
        (int) ($table['eligible_rows'] ?? 0),
        (int) $table['days'],
        (string) $table['status'],
    );
}
echo "Nenhum dado foi excluido.\n";
exit($ok ? 0 : 1);

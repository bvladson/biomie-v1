<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Logger;
use BIomie\Queue\DeadLetterRepository;
use BIomie\Queue\FileJobQueue;
use BIomie\Queue\WorkerHeartbeatRepository;
use BIomie\Support\SecretMask;
use BIomie\SyncPipelineRepository;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$args = parseArgs($argv);
$json = isset($args['json']);
$domain = (string) ($args['domain'] ?? 'all');
$root = dirname(__DIR__, 2);
$config = new Config();
$logger = new Logger($root . '/storage/logs/biomie.log');
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';

if (!Database::isConfigured($config, $connection)) {
    output(['ok' => false, 'message' => 'Banco de dados nao configurado.'], $json);
    exit(2);
}

try {
    $pdo = Database::connect($config, $connection);
} catch (Throwable $exception) {
    output([
        'ok' => false,
        'error' => 'database_connection_failed',
        'message' => 'Nao foi possivel conectar ao banco local para diagnostico.',
        'detail' => SecretMask::sanitizeErrorMessage($exception->getMessage(), 220),
        'recommended_action' => 'Verifique driver PDO/MySQL, metodo de autenticacao do usuario e credenciais do ambiente.',
    ], $json);
    exit(2);
}
$pipeline = new SyncPipelineRepository($pdo, $logger);
$pipeline->ensureSchema();
$queue = new FileJobQueue($root . '/storage/cache', $logger);
$dead = new DeadLetterRepository($pdo);
$heartbeat = new WorkerHeartbeatRepository($pdo);

$domains = $domain === 'all' ? [] : [$domain];
$runs = $pipeline->listRecentRuns(20);
$cursors = $pipeline->listCursors($domains);
$state = $pipeline->summarizeOperationalState(10);
$freshness = [
    // Only sincronizado_em / updated_at — never data_competencia/data_vencimento (business dates, not sync timestamps)
    'last_nf_canonical_at'      => lastUpdatedAt($pdo, 'biomie_notas_fiscais_canonicas',        ['sincronizado_em', 'updated_at']),
    'last_order_canonical_at'   => lastUpdatedAt($pdo, 'biomie_pedidos_canonicos',              ['sincronizado_em', 'updated_at']),
    'last_finance_canonical_at' => lastUpdatedAt($pdo, 'biomie_titulos_financeiros_canonicos',  ['sincronizado_em', 'updated_at']),
    'last_gold_materialized_at' => lastUpdatedAt($pdo, 'mart_cliente_periodo',                  ['refreshed_at', 'updated_at']),
];

$result = [
    'ok' => true,
    'domain' => $domain,
    'sync_runs' => array_slice($runs, 0, 10),
    'sync_cursors' => $cursors,
    'webhook_inbox' => $state['webhook_inbox'] ?? null,
    'file_queue' => $queue->summary(),
    'dead_letter' => $dead->counts(),
    'worker_heartbeat' => $heartbeat->summary(),
    'freshness' => $freshness,
    'recommended_commands' => [
        'sync_commercial' => 'php cron/sync_realtime_pipeline.php --domains=commercial --mode=delta --json',
        'sync_finance' => 'php cron/sync_realtime_pipeline.php --domains=finance --mode=delta --json',
        'reconcile_7d' => 'php cron/sync_realtime_pipeline.php --domains=commercial,fiscal --mode=reconcile --reconcile=7 --json',
        'worker' => 'php cron/worker.php --queue=high,default,low --limit=50 --max-runtime=120 --json',
        'materialize' => 'php bin/materialize_marts.php --json',
    ],
];

output($result, $json);

/** @return array<string,string|bool> */
function parseArgs(array $argv): array
{
    $out = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        [$key, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, true);
        $out[$key] = $value;
    }
    return $out;
}

function output(array $payload, bool $json): void
{
    if ($json) {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        return;
    }
    echo "Sync Pipeline Debug\n";
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

/** @param list<string> $columns */
function lastUpdatedAt(PDO $pdo, string $table, array $columns): ?string
{
    if (!tableExists($pdo, $table)) {
        return null;
    }
    foreach ($columns as $column) {
        if (!columnExists($pdo, $table, $column)) {
            continue;
        }
        $stmt = $pdo->query(sprintf('SELECT MAX(`%s`) FROM `%s`', $column, $table));
        $value = $stmt !== false ? $stmt->fetchColumn() : false;
        if ($value !== false && $value !== null && (string) $value !== '') {
            return (string) $value;
        }
    }
    return null;
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

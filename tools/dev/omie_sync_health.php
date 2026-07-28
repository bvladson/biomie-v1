<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\OmieMethodPolicyRegistry;
use BIomie\Support\GeoTraceDiagnostics;
use BIomie\Support\SecretMask;

require dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$options = getopt('', ['json']);
$asJson = isset($options['json']);
$config = new Config();
$dbError = null;
$pdo = null;

try {
    $pdo = Database::getGeoConnection($config)[0];
} catch (Throwable $exception) {
    $dbError = SecretMask::sanitizeErrorMessage($exception->getMessage());
}

function healthScalar(?PDO $pdo, string $sql, array $params = []): mixed
{
    if (!$pdo instanceof PDO) {
        return null;
    }
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    } catch (Throwable) {
        return null;
    }
}

function healthRows(?PDO $pdo, string $sql, array $params = []): array
{
    if (!$pdo instanceof PDO) {
        return [];
    }
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    } catch (Throwable) {
        return [];
    }
}

function healthTable(?PDO $pdo, string $table): bool
{
    return $pdo instanceof PDO && GeoTraceDiagnostics::tableExists($pdo, $table);
}

$appKey = (string) $config->get('omie.app_key', '');
$appSecret = (string) $config->get('omie.app_secret', '');
$endpoints = (array) $config->get('omie.endpoints', []);
$policy = new OmieMethodPolicyRegistry($config);
$policies = [];
foreach (['ListarClientes', 'ConsultarCliente', 'PesquisarCidades', 'ListarPedidos', 'ConsultarPedido', 'ListarNF', 'ConsultarNF', 'ListarVendedores'] as $call) {
    $p = $policy->forCall($call);
    $policies[$call] = [
        'page_size_target' => (int) ($p['page_size_target'] ?? 100),
        'max_concurrency' => (int) ($p['max_concurrency'] ?? 1),
        'retry_max_attempts' => (int) ($p['retry']['max_attempts'] ?? 1),
        'domain' => (string) ($p['domain'] ?? 'generic'),
    ];
}

$bronzeByDomain = healthRows($pdo, "
    SELECT domain_name, entity_name, omie_method, COUNT(*) AS records, MAX(ingested_at) AS last_at
    FROM biomie_omie_bronze
    GROUP BY domain_name, entity_name, omie_method
    ORDER BY MAX(ingested_at) DESC
");
$lastByDomain = [];
foreach ($bronzeByDomain as $row) {
    $domain = (string) ($row['domain_name'] ?? 'unknown');
    $lastByDomain[$domain] ??= [
        'last_ingested_at' => $row['last_at'] ?? null,
        'records' => 0,
        'methods' => [],
    ];
    $lastByDomain[$domain]['records'] += (int) ($row['records'] ?? 0);
    $lastByDomain[$domain]['methods'][] = [
        'entity' => (string) ($row['entity_name'] ?? ''),
        'method' => (string) ($row['omie_method'] ?? ''),
        'records' => (int) ($row['records'] ?? 0),
        'last_at' => $row['last_at'] ?? null,
    ];
}

$lastChecks = healthRows($pdo, "
    SELECT check_type, status, endpoint, method, latency_ms, error_message_sanitized, created_at
    FROM biomie_omie_status_checks
    ORDER BY created_at DESC
    LIMIT 20
");
$lastError = healthRows($pdo, "
    SELECT check_type, status, method, error_message_sanitized, created_at
    FROM biomie_omie_status_checks
    WHERE status <> 'ok'
    ORDER BY created_at DESC
    LIMIT 1
");
$pipelineJobs = healthTable($pdo, 'biomie_pipeline_jobs') ? [
    'running' => (int) healthScalar($pdo, "SELECT COUNT(*) FROM biomie_pipeline_jobs WHERE status = 'running'"),
    'failed' => (int) healthScalar($pdo, "SELECT COUNT(*) FROM biomie_pipeline_jobs WHERE status = 'failed'"),
    'completed_24h' => (int) healthScalar($pdo, "SELECT COUNT(*) FROM biomie_pipeline_jobs WHERE status = 'completed' AND completed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"),
    'last' => healthRows($pdo, 'SELECT job_name, status, completed_at, duration_ms, error_message FROM biomie_pipeline_jobs ORDER BY id DESC LIMIT 1')[0] ?? null,
] : ['available' => false];
$queueJobs = healthTable($pdo, 'biomie_jobs') ? [
    'pending' => (int) healthScalar($pdo, "SELECT COUNT(*) FROM biomie_jobs WHERE status IN ('queued','pending','available')"),
    'failed' => (int) healthScalar($pdo, "SELECT COUNT(*) FROM biomie_jobs WHERE status LIKE 'failed%' OR failed_at IS NOT NULL"),
    'running' => (int) healthScalar($pdo, "SELECT COUNT(*) FROM biomie_jobs WHERE status IN ('running','reserved','processing')"),
] : ['available' => false];

$trace = $pdo instanceof PDO ? GeoTraceDiagnostics::build($pdo, date('Y-m-01'), date('Y-m-t'), 'Fortaleza') : [];
$payload = [
    'status' => $pdo instanceof PDO ? 'ok' : 'partial',
    'database_available' => $pdo instanceof PDO,
    'database_error' => $dbError,
    'credentials' => [
        'app_key_configured' => SecretMask::isConfigured($appKey),
        'app_key_masked' => SecretMask::isConfigured($appKey) ? SecretMask::maskSecret($appKey) : null,
        'app_secret_configured' => SecretMask::isConfigured($appSecret),
        'app_secret_masked' => SecretMask::isConfigured($appSecret) ? SecretMask::maskSecret($appSecret) : null,
    ],
    'endpoints_configured' => array_map(static fn (mixed $value): bool => trim((string) $value) !== '', $endpoints),
    'method_policy' => $policies,
    'last_ingestion_by_domain' => $lastByDomain,
    'last_checks' => $lastChecks,
    'last_error' => $lastError[0] ?? null,
    'calls_last_24h' => [
        'bronze_payloads' => (int) healthScalar($pdo, "SELECT COUNT(*) FROM biomie_omie_bronze WHERE ingested_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"),
        'status_checks' => (int) healthScalar($pdo, "SELECT COUNT(*) FROM biomie_omie_status_checks WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"),
        'failures' => (int) healthScalar($pdo, "SELECT COUNT(*) FROM biomie_omie_status_checks WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND status <> 'ok'"),
    ],
    'jobs' => [
        'pipeline_jobs' => $pipelineJobs,
        'queue_jobs' => $queueJobs,
    ],
    'freshness' => $trace['freshness'] ?? [],
    'recommendations' => [
        'Ferramenta somente local: nao chama Omie.',
        'Se invoices/orders estiverem stale, rodar sync canonico ou cron de reconciliacao conforme janela afetada.',
        'Se geo_mart estiver stale, rodar materializacao geografica apos sync canonical.',
    ],
];

if ($asJson) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

echo "--- OMIE SYNC HEALTH ---\n";
echo "Status: {$payload['status']}\n";
echo 'App key configurada: ' . ($payload['credentials']['app_key_configured'] ? 'Sim (' . $payload['credentials']['app_key_masked'] . ')' : 'Nao') . "\n";
echo 'App secret configurada: ' . ($payload['credentials']['app_secret_configured'] ? 'Sim (' . $payload['credentials']['app_secret_masked'] . ')' : 'Nao') . "\n";
echo "Dominios bronze:\n";
foreach ($lastByDomain as $domain => $info) {
    echo " - {$domain}: {$info['records']} payload(s), ultimo {$info['last_ingested_at']}\n";
}
echo "Jobs pipeline: running=" . ($pipelineJobs['running'] ?? '-') . " failed=" . ($pipelineJobs['failed'] ?? '-') . " completed_24h=" . ($pipelineJobs['completed_24h'] ?? '-') . "\n";
echo "Jobs fila: pending=" . ($queueJobs['pending'] ?? '-') . " running=" . ($queueJobs['running'] ?? '-') . " failed=" . ($queueJobs['failed'] ?? '-') . "\n";
echo "Freshness: " . json_encode($payload['freshness'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
if ($dbError !== null) {
    echo "Aviso banco: {$dbError}\n";
}

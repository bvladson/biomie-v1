<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Support\SecretMask;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$args = parseArgs($argv);
$json = isset($args['json']);
$domain = (string) ($args['domain'] ?? 'all');
$config = new Config();
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
        'message' => 'Nao foi possivel conectar ao banco local para medir freshness.',
        'detail' => SecretMask::sanitizeErrorMessage($exception->getMessage(), 220),
        'recommended_action' => 'Verifique driver PDO/MySQL, metodo de autenticacao do usuario e credenciais do ambiente.',
    ], $json);
    exit(2);
}

$rows = [
    'sync_commercial' => latestSync($pdo, 'commercial'),
    'sync_finance' => latestSync($pdo, 'finance'),
    'sync_fiscal' => latestSync($pdo, 'fiscal'),
    'canonical_orders' => lastUpdatedAt($pdo, 'biomie_pedidos_canonicos', ['sincronizado_em', 'updated_at', 'data_emissao']),
    'canonical_invoices' => lastUpdatedAt($pdo, 'biomie_notas_fiscais_canonicas', ['sincronizado_em', 'updated_at', 'data_competencia']),
    'canonical_finance' => lastUpdatedAt($pdo, 'biomie_titulos_financeiros_canonicos', ['sincronizado_em', 'updated_at', 'data_vencimento']),
    'gold_cliente_periodo' => lastUpdatedAt($pdo, 'mart_cliente_periodo', ['refreshed_at', 'updated_at']),
    'gold_produto_periodo' => lastUpdatedAt($pdo, 'mart_produto_periodo', ['refreshed_at', 'updated_at']),
];

$result = ['ok' => true, 'domain' => $domain, 'freshness' => []];
foreach ($rows as $key => $date) {
    if ($domain !== 'all' && !str_contains($key, $domain)) {
        continue;
    }
    $result['freshness'][$key] = [
        'last_at' => $date,
        'lag_hours' => lagHours($date),
        'status' => statusForDate($date),
    ];
}

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
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

function latestSync(PDO $pdo, string $domain): ?string
{
    if (!tableExists($pdo, 'biomie_sync_runs')) {
        return null;
    }
    $stmt = $pdo->prepare(
        "SELECT MAX(finished_at) FROM biomie_sync_runs WHERE domain_name = :domain AND status IN ('completed','completed_with_warnings')"
    );
    $stmt->execute(['domain' => $domain]);
    $value = $stmt->fetchColumn();
    return $value !== false && $value !== null && (string) $value !== '' ? (string) $value : null;
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

function lagHours(?string $date): ?float
{
    if ($date === null || $date === '') {
        return null;
    }
    $ts = strtotime($date);
    return $ts === false ? null : round(max(0, time() - $ts) / 3600, 2);
}

function statusForDate(?string $date): string
{
    $lag = lagHours($date);
    if ($lag === null) {
        return 'unknown';
    }
    if ($lag <= 24) {
        return 'ok';
    }
    return $lag <= 72 ? 'warning' : 'critical';
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

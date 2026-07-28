<?php

declare(strict_types=1);

/**
 * Safe CLI wrapper for GeoPeriodRebuildService.
 *
 * Default mode is dry-run. Use --apply to write data.
 *
 * Usage:
 *   php bin/geo_rebuild_period.php --start=2026-01-01 --end=2026-06-02
 *   php bin/geo_rebuild_period.php --start=2026-01-01 --end=2026-06-02 --format=json
 *   php bin/geo_rebuild_period.php --start=2026-01-01 --end=2026-06-02 --apply --assert
 */

use BIomie\Config;
use BIomie\Database;
use BIomie\DataPlatform\Gold\GeoPeriodRebuildService;
use BIomie\Logger;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require_once dirname(__DIR__) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

$opts = [];
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (preg_match('/^--([a-z0-9_-]+)=(.*)$/i', $arg, $m)) {
        $opts[$m[1]] = $m[2];
    } elseif (preg_match('/^--([a-z0-9_-]+)$/i', $arg, $m)) {
        $opts[$m[1]] = true;
    }
}

$start = trim((string) ($opts['start'] ?? $opts['date-from'] ?? ''));
$end = trim((string) ($opts['end'] ?? $opts['date-to'] ?? ''));
$format = strtolower((string) ($opts['format'] ?? 'text'));
$json = $format === 'json' || isset($opts['json']);
$apply = isset($opts['apply']);
$dryRun = !$apply || isset($opts['dry-run']);

if ($start === '' || $end === '') {
    $payload = [
        'ok' => false,
        'status' => 'invalid_args',
        'message' => 'Informe --start=YYYY-MM-DD e --end=YYYY-MM-DD.',
    ];
    echo $json
        ? json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL
        : $payload['message'] . PHP_EOL;
    exit(1);
}

foreach (['start' => $start, 'end' => $end] as $name => $value) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        $payload = [
            'ok' => false,
            'status' => 'invalid_args',
            'message' => "Parametro {$name} invalido: use YYYY-MM-DD.",
        ];
        echo $json
            ? json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL
            : $payload['message'] . PHP_EOL;
        exit(1);
    }
}

if ($start > $end) {
    $payload = [
        'ok' => false,
        'status' => 'invalid_args',
        'message' => '--start deve ser anterior ou igual a --end.',
    ];
    echo $json
        ? json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL
        : $payload['message'] . PHP_EOL;
    exit(1);
}

$config = new Config();
$pdo = Database::connect($config, 'db');
$logger = new Logger(dirname(__DIR__) . '/storage/logs/geo_rebuild_period_cli.log');
$service = new GeoPeriodRebuildService($pdo, $logger);

$backup = null;
if ($apply && !isset($opts['no-backup'])) {
    try {
        $backup = geoRebuildPeriodBackup($pdo, substr($start, 0, 7), substr($end, 0, 7), dirname(__DIR__));
    } catch (Throwable $exception) {
        $payload = [
            'ok' => false,
            'status' => 'backup_failed',
            'message' => 'Backup logico falhou; apply cancelado.',
            'errors' => [$exception->getMessage()],
        ];
        echo $json
            ? json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL
            : $payload['message'] . ' ' . $exception->getMessage() . PHP_EOL;
        exit(1);
    }
}

$result = $service->rebuild($start, $end, [
    'dry_run' => $dryRun,
    'assert' => isset($opts['assert']),
    'allow_open_month' => isset($opts['allow-open-month']) || isset($opts['allow_open_month']),
    'force_full_month' => isset($opts['force-full-month']) || isset($opts['force_full_month']),
    'source' => $apply ? 'bin/geo_rebuild_period.php --apply' : 'bin/geo_rebuild_period.php --dry-run',
]);
if ($backup !== null) {
    $result['backup'] = $backup;
}

if ($json) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(!empty($result['ok']) ? 0 : 2);
}

$window = is_array($result['window'] ?? null) ? $result['window'] : [];
$source = is_array($result['source'] ?? null) ? $result['source'] : [];
$deleted = is_array($result['deleted'] ?? null) ? $result['deleted'] : [];
$inserted = is_array($result['inserted'] ?? null) ? $result['inserted'] : [];
$reconciliation = is_array($result['reconciliation'] ?? null) ? $result['reconciliation'] : [];
$diagnosis = is_array($result['diagnosis'] ?? null) ? $result['diagnosis'] : [];

echo '=== GEO REBUILD PERIOD ===' . PHP_EOL;
echo 'Status: ' . (string) ($result['status'] ?? 'unknown') . PHP_EOL;
echo 'Modo: ' . (!empty($result['dry_run']) ? 'dry-run (nenhuma escrita)' : 'apply') . PHP_EOL;
echo 'Periodo solicitado: ' . (string) ($window['requested_start'] ?? $start) . ' ate ' . (string) ($window['requested_end'] ?? $end) . PHP_EOL;
echo 'Periodo efetivo: ' . (string) ($window['effective_start'] ?? '-') . ' ate ' . (string) ($window['effective_end'] ?? '-') . PHP_EOL;
echo 'Competencias: ' . implode(', ', (array) ($window['competencias'] ?? [])) . PHP_EOL;
echo 'NFs canonicas autorizadas: ' . (int) ($source['canonical_nfs_autorizadas'] ?? 0) . PHP_EOL;
echo 'mart_cliente_periodo deleted/inserted: '
    . (int) ($deleted['mart_cliente_periodo'] ?? 0) . '/'
    . (int) ($inserted['mart_cliente_periodo'] ?? 0) . PHP_EOL;
echo 'mart_compradores_por_geo deleted/inserted: '
    . (int) ($deleted['mart_compradores_por_geo'] ?? 0) . '/'
    . (int) ($inserted['mart_compradores_por_geo'] ?? 0) . PHP_EOL;
if (is_array($backup)) {
    echo 'Backup logico: ' . (string) ($backup['path'] ?? '-') . PHP_EOL;
}
echo 'Reconciliacao: ' . (string) ($reconciliation['status'] ?? '-') . PHP_EOL;
echo 'Proxima acao: ' . (string) ($diagnosis['next_action'] ?? '-') . PHP_EOL;

$warnings = array_values(array_map('strval', (array) ($result['warnings'] ?? [])));
$errors = array_values(array_map('strval', (array) ($result['errors'] ?? [])));
if ($warnings !== []) {
    echo PHP_EOL . 'Warnings:' . PHP_EOL;
    foreach ($warnings as $warning) {
        echo '- ' . $warning . PHP_EOL;
    }
}
if ($errors !== []) {
    echo PHP_EOL . 'Errors:' . PHP_EOL;
    foreach ($errors as $error) {
        echo '- ' . $error . PHP_EOL;
    }
}

exit(!empty($result['ok']) ? 0 : 2);

/**
 * @return array{path:string, mart_cliente_periodo:int, mart_compradores_por_geo:int}
 */
function geoRebuildPeriodBackup(PDO $pdo, string $startComp, string $endComp, string $root): array
{
    $dir = $root . '/storage/diagnostics/' . date('Ymd');
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Nao foi possivel criar diretorio de diagnostico: ' . $dir);
    }

    $payload = [
        'created_at' => date('c'),
        'period' => ['start_comp' => $startComp, 'end_comp' => $endComp],
        'tables' => [],
    ];

    foreach (['mart_cliente_periodo', 'mart_compradores_por_geo'] as $table) {
        $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE competencia BETWEEN :s AND :e ORDER BY competencia, codigo_cliente_omie");
        $stmt->execute(['s' => $startComp, 'e' => $endComp]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $payload['tables'][$table] = $rows;
    }

    $path = $dir . '/geo_rebuild_backup_' . date('Ymd_His') . '_' . $startComp . '_' . $endComp . '.json';
    $bytes = file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    if ($bytes === false) {
        throw new RuntimeException('Nao foi possivel gravar backup: ' . $path);
    }

    return [
        'path' => $path,
        'mart_cliente_periodo' => count($payload['tables']['mart_cliente_periodo'] ?? []),
        'mart_compradores_por_geo' => count($payload['tables']['mart_compradores_por_geo'] ?? []),
    ];
}

<?php

/**
 * Materialização diária da camada gold (mart_*).
 *
 * Uso:
 *   php bin/materialize_marts.php --company=SLUG [--start=YYYY-MM-DD] [--end=YYYY-MM-DD] [--months=N]
 *
 * Padrões:
 *   --start  : primeiro dia do mês corrente
 *   --end    : hoje
 *   --months : quantos meses retroativos incluir além do período explícito
 *              (ex.: --months=3 amplia start em 3 meses)
 *
 * Recomendação cron:
 *   0 2 * * * php /caminho/biomie/bin/materialize_marts.php --company=MAG_FILIAL >> /var/log/biomie/marts.log 2>&1
 *
 * Saída: JSON com contadores por mart + status do job.
 */

declare(strict_types=1);

use BIomie\Config;
use BIomie\CommercialMaterializationService;
use BIomie\Database;
use BIomie\GoldMartBuilder;
use BIomie\Logger;
use BIomie\PipelineStatusRepository;
use BIomie\Support\CommercialProjectionInvalidator;
use BIomie\Support\CommercialDataLockSet;
use BIomie\Support\SecretMask;
use BIomie\SyncPipelineRepository;
use BIomie\Support\TenantCliContext;
use BIomie\TableHealthService;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require_once dirname(__DIR__) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

// ── Parse de argumentos ───────────────────────────────────────────────────────

try {
    $opts = mmParseOptions($argv ?? []);
    $today = date('Y-m-d');
    $extraMonths = isset($opts['months']) ? (int) $opts['months'] : 0;
    $endDate = mmResolveAliasedOption($opts, 'end', 'to', $today);
    $startDate = mmResolveAliasedOption($opts, 'start', 'from', date('Y-m-01'));
    $scope = strtolower(trim((string) ($opts['scope'] ?? 'all')));
    if (!in_array($scope, ['all', 'commercial'], true)) {
        throw new InvalidArgumentException('--scope aceita somente all ou commercial.');
    }
    if ($extraMonths > 0) {
        $startDate = (new DateTimeImmutable($startDate))
            ->modify("-{$extraMonths} months")
            ->format('Y-m-01');
    }
    mmAssertDateRange($startDate, $endDate);
    $dryRun = isset($opts['dry-run']);
    $force = isset($opts['force']);
} catch (InvalidArgumentException $exception) {
    echo json_encode([
        'ok' => false,
        'status' => 'validation_error',
        'message' => $exception->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit(2);
}

// ── Bootstrap ────────────────────────────────────────────────────────────────

$logPath = dirname(__DIR__) . '/storage/logs/materialize_marts.log';
$logger  = new Logger($logPath);
$config  = new Config();

$logger->info('materialize_marts: iniciando.', [
    'start'        => $startDate,
    'end'          => $endDate,
    'extra_months' => $extraMonths,
    'scope'        => $scope,
    'dry_run'      => $dryRun,
]);

echo sprintf("[%s] materialize_marts: start=%s end=%s scope=%s%s\n",
    date('Y-m-d H:i:s'), $startDate, $endDate, $scope, $dryRun ? ' DRY-RUN' : '');

if ($dryRun) {
    echo "[DRY-RUN] Nenhuma escrita realizada.\n";
    exit(0);
}

// ── Execução ─────────────────────────────────────────────────────────────────

$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';

if (!Database::isConfigured($config, $connection)) {
    echo "[ERRO] Banco de dados não configurado. Verifique {$connection} em config.\n";
    $logger->error('materialize_marts: banco não configurado.', ['connection' => $connection]);
    exit(1);
}

$lock    = null;
$runId   = 0;
$startMs = (int) round(microtime(true) * 1000);

try {
    $pdo = Database::connect($config, $connection);
    $tenant = TenantCliContext::boot($pdo, $argv ?? []);

    // The public commercial alias must use the validated materialization
    // service. It treats all marts as one atomic projection and refuses a
    // successful result when any required Gold target is partial.
    if ($scope === 'commercial') {
        $source = canonicalFreshnessForMaterialization($pdo, $tenant['company_id']);
        if (($source['status'] ?? 'critical') === 'critical' && !$force) {
            $output = [
                'ok' => false,
                'status' => 'blocked_stale_source',
                'message' => 'Materializacao Gold bloqueada: origem canonica/Omie critica ou sem sync recente. Use --force apenas em manutencao.',
                'source_freshness' => $source,
            ];
            echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            $logger->warning('materialize_marts: bloqueado por fonte stale.', $output);
            exit(1);
        }

        $pipelineStatus = new PipelineStatusRepository($pdo, $logger);
        $builder = new GoldMartBuilder($pdo, $logger, $pipelineStatus);
        $service = new CommercialMaterializationService(
            $pdo,
            $logger,
            $builder,
            new TableHealthService($pdo, $logger),
        );
        $report = $service->run(null, $startDate, $endDate);
        $report['scope'] = 'commercial';
        $report['source_freshness'] = $source;
        $report['origin_used'] = $force
            ? 'forced'
            : (($source['status'] ?? '') === 'ok' ? 'canonical_fresh' : 'canonical_stale');
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        $logger->info('materialize_marts: materializacao comercial concluida.', $report);
        exit(!empty($report['ok']) ? 0 : 1);
    }

    $pipeline = new SyncPipelineRepository($pdo, $logger, $tenant['company_id']);
    $pipeline->ensureSchema();

    $lock = CommercialDataLockSet::acquire(
        $pdo,
        $tenant['company_id'],
        $logger
    );
    if ($lock === null) {
        $skipped = ['ok' => true, 'status' => 'skipped', 'reason' => 'lock_held',
            'message' => 'Outra instancia de materialize_marts ja esta em execucao.'];
        echo json_encode($skipped, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
        exit(0);
    }

    $runId = $pipeline->startRun('materialize', 'gold', $scope, ['trigger_source' => 'cron']);

    $source = canonicalFreshnessForMaterialization($pdo, $tenant['company_id']);
    if (($source['status'] ?? 'critical') === 'critical' && !$force) {
        $pipeline->finishRun($runId, 'failed_retryable', [], 'Bloqueado: origem canonica critica. Use --force em manutencao.');
        $output = [
            'ok' => false,
            'status' => 'blocked_stale_source',
            'message' => 'Materializacao Gold bloqueada: origem canonica/Omie critica ou sem sync recente. Use --force apenas em manutencao.',
            'source_freshness' => $source,
        ];
        echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        $logger->warning('materialize_marts: bloqueado por fonte stale.', $output);
        exit(1);
    }

    $pipelineStatus = new PipelineStatusRepository($pdo, $logger);
    $builder        = new GoldMartBuilder($pdo, $logger, $pipelineStatus);

    $result = $scope === 'commercial'
        ? $builder->refreshCommercialPeriod($startDate, $endDate)
        : $builder->refreshPeriod($startDate, $endDate);

    // Invalide derivados somente depois do commit dos marts. O catalogo e os
    // caches dos dois canais deixam de poder servir a versao anterior.
    $invalidation = (new CommercialProjectionInvalidator(
        $pdo,
        dirname(__DIR__) . '/storage/cache',
    ))->invalidate(
        $tenant['company_id'],
        $tenant['canal'],
        $startDate,
        $endDate,
    );
    $completionStatus = !empty($invalidation['ok']) ? 'completed' : 'completed_with_warnings';

    $durationMs = (int) round(microtime(true) * 1000) - $startMs;
    $totalWritten = (int) array_sum(array_map('intval', array_values($result)));

    $pipeline->finishRun($runId, $completionStatus, [
        'items_processed' => $totalWritten,
        'records_inserted' => $totalWritten,
        'duration_ms' => $durationMs,
        'start' => $startDate,
        'end' => $endDate,
        'scope' => $scope,
        'invalidation' => $invalidation,
    ]);

    $output = [
        'ok'     => $completionStatus === 'completed',
        'completion_status' => $completionStatus,
        'start'  => $startDate,
        'end'    => $endDate,
        'scope'  => $scope,
        'counts' => $result,
        'invalidation' => $invalidation,
        'source_freshness' => $source,
        'origin_used' => $force ? 'forced' : (($source['status'] ?? '') === 'ok' ? 'canonical_fresh' : 'canonical_stale'),
        'status' => $pipelineStatus->pipelineStatus('gold_mart_refresh', $startDate, $endDate),
        'duration_ms' => $durationMs,
    ];

    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

    $logger->info('materialize_marts: concluido.', $output);
    exit($completionStatus === 'completed' ? 0 : 1);
} catch (\Throwable $e) {
    $msg = SecretMask::sanitizeErrorMessage($e->getMessage(), 500);
    if ($runId > 0 && isset($pipeline)) {
        try { $pipeline->finishRun($runId, 'failed_terminal', [], $msg); } catch (\Throwable) {}
    }
    echo "[ERRO] {$msg}\n";
    $logger->error('materialize_marts: falha fatal.', [
        'message' => $msg,
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ]);
    exit(2);
} finally {
    $lock?->release();
}

/**
 * @return array<string,mixed>
 */
function canonicalFreshnessForMaterialization(\PDO $pdo, int $companyId): array
{
    $dates = [];
    foreach ([
        // Only true sync timestamp columns — never data_competencia/data_emissao (business dates, not sync timestamps)
        'biomie_notas_fiscais_canonicas' => ['sincronizado_em', 'updated_at'],
        'biomie_pedidos_canonicos'       => ['sincronizado_em', 'updated_at'],
    ] as $table => $columns) {
        $date = latestTableDate($pdo, $table, $columns, $companyId);
        if ($date !== null) {
            $dates[$table] = $date;
        }
    }
    // Um sync comercial bem-sucedido recente prova que a origem canonica esta
    // atualizada mesmo quando nenhuma NF/pedido mudou (empresas de baixo volume
    // nao bumpam sincronizado_em) — evita o false-block da materializacao.
    $commercialRun = latestCompletedCommercialRun($pdo, $companyId);
    if ($commercialRun !== null) {
        $dates['commercial_sync_run'] = $commercialRun;
    }
    if ($dates === []) {
        return ['status' => 'critical', 'last_canonical_at' => null, 'lag_hours' => null];
    }
    rsort($dates);
    $last = $dates[0];
    $lag = round(max(0, time() - (strtotime($last) ?: time())) / 3600, 2);
    return [
        'status' => $lag <= 24 ? 'ok' : ($lag <= 72 ? 'warning' : 'critical'),
        'last_canonical_at' => $last,
        'lag_hours' => $lag,
    ];
}

function latestCompletedCommercialRun(\PDO $pdo, int $companyId): ?string
{
    if (!tableExists($pdo, 'biomie_sync_runs')) {
        return null;
    }
    try {
        $stmt = $pdo->prepare(
            "SELECT MAX(finished_at) FROM biomie_sync_runs
              WHERE company_id = :cid
                AND domain_name IN ('commercial','comercial')
                AND status IN ('completed','completed_with_warnings')"
        );
        $stmt->execute([':cid' => $companyId]);
        $value = $stmt->fetchColumn();
    } catch (\Throwable) {
        return null;
    }
    return ($value !== false && $value !== null && (string) $value !== '') ? (string) $value : null;
}

/** @param list<string> $columns */
function latestTableDate(\PDO $pdo, string $table, array $columns, int $companyId): ?string
{
    if (!tableExists($pdo, $table)) {
        return null;
    }
    foreach ($columns as $column) {
        if (!columnExists($pdo, $table, $column)) {
            continue;
        }
        $stmt = $pdo->prepare(sprintf(
            'SELECT MAX(`%s`) FROM `%s` WHERE company_id = :company_id',
            $column,
            $table
        ));
        $stmt->execute(['company_id' => $companyId]);
        $value = $stmt->fetchColumn();
        if ($value !== false && $value !== null && (string) $value !== '') {
            return (string) $value;
        }
    }
    return null;
}

function tableExists(\PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

function columnExists(\PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * @param array<int,string> $argv
 * @return array<string,string|bool>
 */
function mmParseOptions(array $argv): array
{
    $allowed = ['company', 'start', 'end', 'from', 'to', 'months', 'scope', 'dry-run', 'force', 'json'];
    $flags = ['dry-run', 'force', 'json'];
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
        if (in_array($key, $flags, true)) {
            if ($value !== true) {
                throw new InvalidArgumentException("--{$key} nao aceita valor.");
            }
        } elseif ($value === true || trim((string) $value) === '') {
            throw new InvalidArgumentException("--{$key} exige um valor.");
        }
        $options[$key] = $value;
    }

    if (isset($options['months'])) {
        $months = (string) $options['months'];
        if (preg_match('/^\d{1,3}$/', $months) !== 1 || (int) $months > 120) {
            throw new InvalidArgumentException('--months deve ser um inteiro entre 0 e 120.');
        }
    }

    return $options;
}

/** @param array<string,string|bool> $options */
function mmResolveAliasedOption(array $options, string $primary, string $alias, string $default): string
{
    $primaryValue = isset($options[$primary]) ? trim((string) $options[$primary]) : '';
    $aliasValue = isset($options[$alias]) ? trim((string) $options[$alias]) : '';
    if ($primaryValue !== '' && $aliasValue !== '' && $primaryValue !== $aliasValue) {
        throw new InvalidArgumentException("--{$primary} e --{$alias} informam valores diferentes.");
    }

    return $primaryValue !== '' ? $primaryValue : ($aliasValue !== '' ? $aliasValue : $default);
}

function mmAssertDateRange(string $start, string $end): void
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

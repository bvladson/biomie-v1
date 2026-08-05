<?php

declare(strict_types=1);

/**
 * Backfill historico estritamente NF-only.
 *
 * Sem --yes o comando apenas valida e mostra o plano. Nenhum pedido, evento ou
 * etapa comercial e substituido por este fluxo.
 *
 * Uso:
 *   php bin/omie_nfe_backfill.php --company=MAG_FILIAL \
 *     --start=2026-01-01 --end=2026-05-31
 *   php bin/omie_nfe_backfill.php --company=MAG_FILIAL \
 *     --start=2026-01-01 --end=2026-05-31 --yes
 */

use BIomie\Bootstrap;
use BIomie\BronzeRepository;
use BIomie\Cache;
use BIomie\CanonicalRepository;
use BIomie\Config;
use BIomie\CronLock;
use BIomie\Database;
use BIomie\Logger;
use BIomie\Omie\OmieClientFactory;
use BIomie\OmieFiscalSyncService;
use BIomie\OmieRepository;
use BIomie\SharedDatabaseConnection;
use BIomie\Support\CommercialProjectionInvalidator;
use BIomie\Support\DatabaseErrorEnvelope;
use BIomie\Support\TenantCliContext;
use BIomie\SyncPipelineRepository;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

try {
    $options = onbParseOptions($_SERVER['argv'] ?? []);
    $start = onbResolveDateOption($options, 'start', 'from');
    $end = onbResolveDateOption($options, 'end', 'to');
    $chunk = strtolower(trim((string) ($options['chunk'] ?? 'day')));
    if ($chunk !== 'day') {
        throw new InvalidArgumentException('--chunk aceita somente o valor day.');
    }
} catch (InvalidArgumentException $exception) {
    echo json_encode([
        'ok' => false,
        'status' => 'validation_error',
        'message' => $exception->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(2);
}

$config = new Config();
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$sharedConnection = new SharedDatabaseConnection($config, $connection);
$pdo = $sharedConnection->current();
$tenant = TenantCliContext::boot($pdo, $_SERVER['argv'] ?? []);
$confirmed = isset($options['yes']);
$explicitDryRun = isset($options['dry-run']);
$dryRun = !$confirmed || $explicitDryRun;

try {
    onbAssertRange($start, $end);
    if ($confirmed && $explicitDryRun) {
        throw new InvalidArgumentException('Use --dry-run ou --yes, nunca os dois ao mesmo tempo.');
    }

    $plan = [
        'ok' => true,
        'mode' => $dryRun ? 'dry_run' : 'apply',
        'entity_scope' => 'invoices_only',
        'company' => ['id' => $tenant['company_id'], 'slug' => $tenant['slug']],
        'period' => ['start' => $start, 'end' => $end],
        'source' => 'cron-backfill',
        'window_days' => 1,
        'chunk' => 'day',
        'statuses' => ['authorized', 'cancelled'],
        'date_basis' => 'issue_date',
        'mutation_strategy' => 'idempotent_upsert',
        'excluded_mutations' => ['pending_orders', 'order_events', 'order_stages'],
    ];

    if ($dryRun) {
        $plan['message'] = 'Plano validado. Nenhuma consulta Omie ou escrita canonica foi executada. Use --yes para aplicar.';
        echo json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }

    $logger = new Logger(Bootstrap::rootPath('storage/logs/biomie.log'));
    $lockNames = [
        'biomie:fiscal_write:company:' . $tenant['company_id'],
        'biomie:materialize_marts:company:' . $tenant['company_id'],
        'biomie:nfe_backfill:company:' . $tenant['company_id'],
    ];
    $locks = onbAcquireLocks($pdo, $lockNames, $logger);
    if ($locks === null) {
        echo json_encode($plan + [
            'ok' => false,
            'status' => 'skipped',
            'reason' => 'conflicting_fiscal_or_gold_job',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(1);
    }

    // GET_LOCK pertence a sessao MySQL. Se a conexao for recuperada durante
    // este processo longo, renove todo o conjunto antes de permitir novas
    // escritas; caso contrario o backfill poderia continuar sem exclusividade.
    $sharedConnection->afterReconnect(static function (PDO $newPdo) use (&$locks, $lockNames, $logger): void {
        $renewed = onbAcquireLocks($newPdo, $lockNames, $logger);
        if ($renewed === null) {
            throw new RuntimeException('Conexao recuperada, mas nao foi possivel renovar os locks do backfill.');
        }
        $locks = $renewed;
    });

    $pipeline = new SyncPipelineRepository($pdo, $logger, $tenant['company_id']);
    $pipeline->ensureSchema();
    $runId = $pipeline->startRun('backfill', 'commercial', 'invoices-only', [
        'trigger_source' => 'cli-nfe-only',
        'period_start' => $start . ' 00:00:00',
        'period_end' => $end . ' 23:59:59',
        'entity_scope' => 'invoices_only',
    ]);

    try {
        $cache = new Cache(Bootstrap::rootPath('storage/cache'));
        $reconnect = static fn () => $sharedConnection->recover();
        $canonical = new CanonicalRepository(
            $pdo,
            $logger,
            $reconnect,
            $tenant['company_id'],
            $tenant['canal'],
        );
        $bronze = new BronzeRepository($pdo, $logger, $tenant['company_id'], $reconnect);
        $bronze->ensureSchema();
        $client = (new OmieClientFactory($pdo, $logger, $config, $cache, $reconnect))
            ->forCompany($tenant['company_id']);
        $omie = new OmieRepository($client, $config, $bronze, 'nfe-backfill-cli');
        $service = new OmieFiscalSyncService($config, $omie, $canonical, $logger);
        $result = $service->backfillInvoicesOnly($start, $end, 'cron-backfill');
        $stats = (array) ($result['invoices'] ?? []);
        $processed = array_sum(array_map('intval', $stats));
        $changed = (int) ($stats['inserted'] ?? 0) + (int) ($stats['updated'] ?? 0);
        $currentPdo = $sharedConnection->current();
        $projectionInvalidator = new CommercialProjectionInvalidator(
            $currentPdo,
            Bootstrap::rootPath('storage/cache'),
        );
        // Invalide mesmo quando o upsert for idempotente: um backfill validado
        // pode estar corrigindo a semantica de leitura/classificacao sem mudar
        // o hash bruto da NF, e nenhum payload antigo deve sobreviver a ele.
        $invalidation = $projectionInvalidator->invalidate(
            $tenant['company_id'],
            $tenant['canal'],
            $start,
            $end,
        ) + [
            'performed' => true,
            'reason' => $changed > 0 ? 'canonical_invoices_changed' : 'successful_idempotent_backfill',
        ];
        $completionStatus = (($invalidation['ok'] ?? false))
            ? 'completed'
            : 'completed_with_warnings';

        // A conexao pode ter sido trocada por qualquer repositorio acima.
        // Finalize a telemetria na sessao atual, nunca no PDO inicial obsoleto.
        $pipeline = new SyncPipelineRepository($currentPdo, $logger, $tenant['company_id']);
        $pipeline->finishRun($runId, $completionStatus, [
            'items_discovered' => $processed,
            'items_processed' => $processed,
            'records_inserted' => (int) ($stats['inserted'] ?? 0),
            'records_updated' => (int) ($stats['updated'] ?? 0),
            'period_start' => $start,
            'period_end' => $end,
            'entity_scope' => 'invoices_only',
            'invalidation' => $invalidation,
        ]);

        echo json_encode($plan + [
            'status' => $completionStatus,
            'run_id' => $runId,
            'result' => $result,
            'invalidation' => $invalidation,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    } catch (Throwable $exception) {
        // P2/Etapa 1 — o backfill morria gravando "Erro de banco de dados";
        // agora o SQLSTATE e o codigo do driver chegam ao registro do run.
        $captured = DatabaseErrorEnvelope::capture($exception, [
            'run_id' => $runId,
            'mode' => 'backfill',
            'domain' => 'fiscal',
            'trigger_source' => 'cron-backfill',
            'company_id' => $tenant['company_id'],
        ]);
        $message = $captured['summary'];
        try {
            $failurePdo = $sharedConnection->current();
            $pipeline = new SyncPipelineRepository($failurePdo, $logger, $tenant['company_id']);
            $pipeline->finishRun($runId, 'failed_terminal', ['error_detail' => $captured['detail']], $message);
        } catch (Throwable) {
        }
        throw $exception;
    } finally {
        // current() aciona a renovacao se a ultima operacao detectou perda de
        // sessao. Depois desabilitamos o gancho e liberamos os locks vigentes.
        try {
            $sharedConnection->current();
        } catch (Throwable) {
        }
        $sharedConnection->afterReconnect(null);
        onbReleaseLocks($locks);
    }
} catch (InvalidArgumentException $exception) {
    echo json_encode([
        'ok' => false,
        'status' => 'validation_error',
        'message' => $exception->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(2);
} catch (Throwable $exception) {
    $captured = DatabaseErrorEnvelope::capture($exception, ['mode' => 'backfill']);
    echo json_encode([
        'ok' => false,
        'status' => 'error',
        'message' => $captured['summary'],
        'error_detail' => $captured['detail'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}

/**
 * @param array<int,string> $argv
 * @return array<string,string|bool>
 */
function onbParseOptions(array $argv): array
{
    $allowed = ['company', 'start', 'end', 'from', 'to', 'chunk', 'yes', 'dry-run', 'json'];
    $flags = ['yes', 'dry-run', 'json'];
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
function onbResolveDateOption(array $options, string $primary, string $alias): string
{
    $primaryValue = isset($options[$primary]) ? trim((string) $options[$primary]) : '';
    $aliasValue = isset($options[$alias]) ? trim((string) $options[$alias]) : '';
    if ($primaryValue !== '' && $aliasValue !== '' && $primaryValue !== $aliasValue) {
        throw new InvalidArgumentException("--{$primary} e --{$alias} informam valores diferentes.");
    }
    return $primaryValue !== '' ? $primaryValue : $aliasValue;
}

function onbAssertRange(string $start, string $end): void
{
    if ($start === '' || $end === '') {
        throw new InvalidArgumentException('Informe --start=YYYY-MM-DD e --end=YYYY-MM-DD.');
    }
    foreach (['start' => $start, 'end' => $end] as $label => $value) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("--{$label} deve seguir YYYY-MM-DD.");
        }
    }
    if ($start > $end) {
        throw new InvalidArgumentException('--start deve ser menor ou igual a --end.');
    }
    $days = (new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days;
    if ($days === false || $days > 366) {
        throw new InvalidArgumentException('O backfill NF-only aceita no maximo 367 dias por execucao. Divida intervalos maiores.');
    }
}

/**
 * @param list<string> $names
 * @return null|list<CronLock>
 */
function onbAcquireLocks(PDO $pdo, array $names, Logger $logger): ?array
{
    $acquired = [];
    foreach ($names as $name) {
        $lock = CronLock::acquire($pdo, (string) $name, $logger);
        if ($lock === null) {
            onbReleaseLocks($acquired);
            return null;
        }
        $acquired[] = $lock;
    }

    return $acquired;
}

/** @param list<CronLock> $locks */
function onbReleaseLocks(array $locks): void
{
    foreach (array_reverse($locks) as $lock) {
        $lock->release();
    }
}

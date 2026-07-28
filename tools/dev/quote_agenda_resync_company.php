<?php

declare(strict_types=1);

/**
 * AGENDA DE ORÇAMENTOS — Fase 2 (metade 2): ressync FOCADA de carteira, 1 empresa.
 *
 * Executa APENAS o passo de pedidos pendentes (autorizado/outro) via
 * FiscalSyncCoordinator::syncPendingOrders — NÃO re-sincroniza NFs/finance,
 * mantendo o blast radius mínimo. Cada janela de 31 dias é atômica
 * (fetch-all → normalize → BEGIN → DELETE → reinsert → COMMIT/ROLLBACK).
 *
 * Reusa a segurança do pipeline: CronLock por empresa, TenantContext, OmieClient
 * por empresa (rate limiter conservador global=3/min), retry/backoff da própria
 * infraestrutura. Sequencial, sem concorrência.
 *
 * Uso:
 *   php tools/dev/quote_agenda_resync_company.php --company=EXPERT --start=2026-04-01 --end=2026-07-31
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
use BIomie\OmieRepository;
use BIomie\SharedDatabaseConnection;
use BIomie\Support\TenantCliContext;
use BIomie\Sync\ChunkedWindowRunner;
use BIomie\Sync\FiscalSyncCoordinator;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

set_time_limit(0);

$opts  = getopt('', ['company:', 'start:', 'end:']);
$start = (string) ($opts['start'] ?? '');
$end   = (string) ($opts['end'] ?? '');
if (($opts['company'] ?? '') === '' || $start === '' || $end === '') {
    fwrite(STDERR, "Uso: --company=SLUG --start=YYYY-MM-DD --end=YYYY-MM-DD\n");
    exit(2);
}

$config = new Config();
$logger = new Logger(dirname(__DIR__, 2) . '/storage/logs/biomie.log');
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';

$shared = new SharedDatabaseConnection($config, $connection);
$pdo = $shared->current();
$reconnect = static fn () => $shared->recover();

$tenant = TenantCliContext::boot($pdo, ['resync', '--company=' . (string) $opts['company']]);
$cid = (int) $tenant['company_id'];

$canonical = new CanonicalRepository($pdo, $logger, $reconnect, $cid, $tenant['canal']);
$canonical->ensureFiscalSchema();
$bronze = new BronzeRepository($pdo, $logger, $cid, $reconnect);
$bronze->ensureSchema();
$cache = new Cache(dirname(__DIR__, 2) . '/storage/cache');

// Mesmo lock do pipeline → evita ressync concorrente com o cron.
$lock = CronLock::acquire($pdo, 'biomie:realtime_pipeline:company:' . $cid, $logger);
if ($lock === null) {
    fwrite(STDERR, "Outro sync desta empresa está em execução (lock). Abortando sem tocar em nada.\n");
    exit(0);
}

$t0 = microtime(true);
try {
    $client = (new OmieClientFactory($pdo, $logger, $config, $cache, $reconnect))->forCompany($cid);
    $omie = new OmieRepository($client, $config, $bronze, 'quote-agenda-resync');
    $coordinator = new FiscalSyncCoordinator($omie, $canonical, $logger, new ChunkedWindowRunner($logger));

    // Replica o comportamento do sync normal: vendedores primeiro (cache local se
    // recente), depois SOMENTE a carteira pendente. Sem NFs, sem finance.
    $vendors = $coordinator->syncVendors();

    // PHP 8.1+ torna métodos privados acessíveis via reflexão sem setAccessible.
    $ref = new ReflectionMethod($coordinator, 'syncPendingOrders');
    /** @var array{stats:array<string,int>,divergences:int} $result */
    $result = $ref->invoke($coordinator, $start, $end, 'quote_agenda_resync');

    echo json_encode([
        'ok'             => true,
        'company_id'     => $cid,
        'company_slug'   => $tenant['slug'],
        'window'         => ['start' => $start, 'end' => $end],
        'vendors_synced' => $vendors,
        'pending_orders' => $result['stats'],
        'divergences'    => $result['divergences'],
        'duration_s'     => round(microtime(true) - $t0, 2),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    $logger->error('quote_agenda_resync: falha.', ['message' => $e->getMessage(), 'company_id' => $cid]);
    fwrite(STDERR, 'ERRO: ' . $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    $lock?->release();
}

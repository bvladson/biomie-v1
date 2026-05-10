<?php

declare(strict_types=1);

/**
 * API de sync canonico - admin-only.
 *
 * GET  -> status do sync + divergencias abertas
 * POST -> dispara sync manual do periodo
 */

use BIomie\Auth;
use BIomie\BronzeRepository;
use BIomie\Cache;
use BIomie\CanonicalRepository;
use BIomie\Database;
use BIomie\Http;
use BIomie\OmieClient;
use BIomie\OmieMethodPolicyRegistry;
use BIomie\OmieOrderEventSyncService;
use BIomie\OmieFiscalSyncService;
use BIomie\OmieRepository;
use BIomie\Queue\FileJobQueue;

require __DIR__ . '/bootstrap.php';

Auth::requireCapability('omie.sync', true);
$cache = new Cache(dirname(__DIR__) . '/storage/cache');

try {
    $range = Http::resolveDateRange();
    Http::validateDateRange($range['start'], $range['end']);

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        Http::enforceCsrf($logger);

        if (!$config->hasOmieCredentials()) {
            Http::json(['ok' => false, 'message' => 'Credenciais Omie nao configuradas.'], 500);
        }

        $jobQueue = new FileJobQueue(dirname(__DIR__) . '/storage/cache', $logger);
        $queueResult = $jobQueue->enqueue('default', 'canonical.sync', [
            'start' => $range['start'],
            'end' => $range['end'],
        ], [
            'idempotency_key' => sprintf('canonical.sync|%s|%s', $range['start'], $range['end']),
            'max_attempts' => 3,
            'created_by' => Auth::currentUser()['id'] ?? null,
        ]);
        $statusUrl = sprintf('refresh_status.php?job_id=%s', rawurlencode((string) $queueResult['job_id']));

        Http::accepted([
            'ok' => true,
            'message' => $queueResult['queued']
                ? 'Sync canonico agendado para execucao em background.'
                : 'Sync canonico ja esta em andamento para esse periodo.',
            'mode' => 'queued',
            'job_id' => $queueResult['job_id'],
            'status_url' => $statusUrl,
            'queue' => $queueResult,
        ], ['Location' => $statusUrl]);
    }

    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    if (!Database::isConfigured($config, $connection)) {
        Http::json(['ok' => false, 'message' => 'Banco nao configurado.'], 500);
    }

    $pdo = Database::connect($config, $connection);
    $reconnect = static fn () => Database::connect($config, $connection);
    $canonical = new CanonicalRepository($pdo, $logger, $reconnect);
    $bronze = new BronzeRepository($pdo, $logger);
    $bronze->ensureSchema();
    $canonical->ensureFiscalSchema();

    if (!$canonical->isAvailable()) {
        Http::json([
            'ok' => false,
            'message' => 'Tabelas canonicas nao encontradas. Execute database/canonical_sales.sql no phpMyAdmin.',
            'setup_required' => true,
        ], 500);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $summary = $canonical->summarize($range['start'], $range['end']);
        $lastSync = $canonical->lastSyncAt($range['start'], $range['end']);
        $divergences = $canonical->openDivergences(20);

        Http::json([
            'ok' => true,
            'period' => $range,
            'summary' => $summary,
            'last_sync' => $lastSync,
            'divergences' => $divergences,
            'divergences_count' => count($divergences),
            'vendors' => $canonical->getVendorMap(),
        ]);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        Http::json(['ok' => false, 'message' => 'Use GET para consultar ou POST para sincronizar.'], 405);
    }
    Http::enforceCsrf($logger);

    if (!$config->hasOmieCredentials()) {
        Http::json(['ok' => false, 'message' => 'Credenciais Omie nao configuradas.'], 500);
    }

    set_time_limit(300);

    $client = new OmieClient(
        (string) $config->get('omie.app_key'),
        (string) $config->get('omie.app_secret'),
        (int) $config->get('omie.request_timeout', 20),
        $logger,
        new OmieMethodPolicyRegistry($config),
        $cache,
    );

    $omie = new OmieRepository($client, $config, $bronze, 'canonical-manual');
    $sync = new OmieFiscalSyncService($config, $omie, $canonical, $logger);
    $eventSync = new OmieOrderEventSyncService($omie, $canonical, $logger);
    $result = $sync->syncPeriod($range['start'], $range['end'], 'manual');
    $eventResult = $eventSync->syncPeriod($range['start'], $range['end'], 'manual', (int) ($result['vendors_synced'] ?? 0));

    $currentUser = Auth::currentUser();
    $logger->info('Sync canonico manual disparado por admin.', [
        'user_id' => $currentUser['id'] ?? null,
        'username' => $currentUser['username'] ?? null,
        'result' => $result,
        'event_result' => $eventResult,
    ]);
    $adminLog->log('omie_sync_executed', null, [
        'start' => $range['start'],
        'end' => $range['end'],
        'vendors_synced' => (int) ($result['vendors_synced'] ?? 0),
    ]);

    $message = sprintf(
        'Sync concluido: %d vendedores, %d NF gravadas, %d pedidos em carteira atualizados, %d eventos canonicos registrados e %d divergencias.',
        $result['vendors_synced'],
        (int) ($result['invoices']['inserted'] ?? 0) + (int) ($result['invoices']['updated'] ?? 0),
        (int) ($result['pending_orders']['inserted'] ?? 0) + (int) ($result['pending_orders']['updated'] ?? 0),
        (int) ($eventResult['events']['inserted'] ?? 0),
        $result['divergences']
    );

    Http::json([
        'ok' => true,
        'message' => $message,
        'result' => $result,
        'event_result' => $eventResult,
    ]);
} catch (\InvalidArgumentException $e) {
    Http::json(['ok' => false, 'message' => $e->getMessage()], 400);
} catch (Throwable $e) {
    $logger->error('Falha em api/canonical_sync.', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    Http::json([
        'ok' => false,
        'message' => 'Erro interno ao processar sync canonico.',
    ], 500);
}

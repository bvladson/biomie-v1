<?php

declare(strict_types=1);

namespace BIomie\Queue;

use BIomie\BronzeRepository;
use BIomie\Cache;
use BIomie\CanonicalRepository;
use BIomie\CommercialMaterializationService;
use BIomie\Config;
use BIomie\CustomerDimensionMaterializationService;
use BIomie\Database;
use BIomie\FinanceAnalyticsService;
use BIomie\FinanceRepository;
use BIomie\GeoCnaeMaterializationService;
use BIomie\GoldMartBuilder;
use BIomie\Logger;
use BIomie\Omie\OmieExecutionContext;
use BIomie\OmieClient;
use BIomie\OmieFiscalSyncService;
use BIomie\OmieMethodPolicyRegistry;
use BIomie\OmieOrderEventSyncService;
use BIomie\OmieRepository;
use BIomie\PipelineStatusRepository;
use BIomie\RefreshQueue;
use BIomie\SalesAnalyticsService;
use BIomie\Sync\WebhookInboxProcessor;
use BIomie\SyncPipelineRepository;
use BIomie\TableHealthService;
use RuntimeException;

final class JobRunner
{
    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly Cache $cache,
        private readonly string $rootDir,
    ) {
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public function run(array $job): array
    {
        $type = (string) ($job['type'] ?? $job['job_type'] ?? '');
        $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
        $startedAt = microtime(true);

        $result = match ($type) {
            'finance.snapshot.refresh' => $this->refreshFinance($payload),
            'sales.snapshot.refresh' => $this->refreshSales($payload),
            'webhook.inbox.process' => $this->processWebhookInbox($payload),
            'canonical.sync' => $this->syncCanonical($payload),
            'clients.materialize' => $this->materializeClients($payload),
            'geo.materialize' => $this->materializeGeo($payload),
            'geo_cnae.materialize' => $this->materializeGeoCnae($payload),
            'commercial.materialize' => $this->materializeCommercial($payload),
            default => throw new RuntimeException('Job nao suportado: ' . $type),
        };

        $result['duration_ms'] = max(0, (int) round((microtime(true) - $startedAt) * 1000));
        return $result;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function refreshFinance(array $payload): array
    {
        $this->requireOmieCredentials();
        $range = $this->range($payload);
        $bronze = BronzeRepository::boot($this->config, $this->logger);
        $client = $this->omieClient();
        $salesRepository = new OmieRepository($client, $this->config, $bronze, 'job-finance-refresh');
        $financeRepository = new FinanceRepository($client, $this->config, $this->logger, $this->cache, $bronze, 'job-finance-refresh');
        $queue = new RefreshQueue($this->rootDir . '/storage/cache', $this->logger);
        $finance = new FinanceAnalyticsService($this->config, $this->cache, $this->logger, $financeRepository, $salesRepository, $queue);
        $snapshot = $finance->getSnapshot($range['start'], $range['end'], true);

        return [
            'ok' => true,
            'type' => 'finance.snapshot.refresh',
            'start' => $range['start'],
            'end' => $range['end'],
            'mode' => $snapshot['mode'] ?? 'live',
            'generated_at' => date(DATE_ATOM),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function refreshSales(array $payload): array
    {
        $this->requireOmieCredentials();
        $range = $this->range($payload);
        $bronze = BronzeRepository::boot($this->config, $this->logger);
        $repository = new OmieRepository($this->omieClient(), $this->config, $bronze, 'job-sales-refresh');
        $queue = new RefreshQueue($this->rootDir . '/storage/cache', $this->logger);
        $sales = new SalesAnalyticsService($this->config, $this->cache, $this->logger, $repository, $queue);
        $snapshot = $sales->getSnapshot($range['start'], $range['end'], true);

        return [
            'ok' => true,
            'type' => 'sales.snapshot.refresh',
            'start' => $range['start'],
            'end' => $range['end'],
            'mode' => $snapshot['mode'] ?? 'live',
            'generated_at' => date(DATE_ATOM),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function processWebhookInbox(array $payload): array
    {
        $pipeline = SyncPipelineRepository::boot($this->config, $this->logger);
        if ($pipeline === null) {
            throw new RuntimeException('Pipeline de sync nao configurado.');
        }

        $queue = new RefreshQueue($this->rootDir . '/storage/cache', $this->logger);
        $processor = new WebhookInboxProcessor($this->config, $pipeline, $this->logger, $queue);

        return $processor->process(
            max(1, (int) ($payload['limit'] ?? 20)),
            array_values(array_filter(array_map('strval', (array) ($payload['domains'] ?? []))))
        ) + ['ok' => true, 'type' => 'webhook.inbox.process'];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function syncCanonical(array $payload): array
    {
        $this->requireOmieCredentials();
        $range = $this->range($payload);
        $pdo = $this->connectAnalytic();
        $reconnect = fn () => $this->connectAnalytic();
        $canonical = new CanonicalRepository($pdo, $this->logger, $reconnect);
        $bronze = new BronzeRepository($pdo, $this->logger);
        $bronze->ensureSchema();
        $canonical->ensureFiscalSchema();

        if (!$canonical->isAvailable()) {
            throw new RuntimeException('Tabelas canonicas nao encontradas.');
        }

        $omie = new OmieRepository($this->omieClient(), $this->config, $bronze, 'job-canonical-sync');
        $sync = new OmieFiscalSyncService($this->config, $omie, $canonical, $this->logger);
        $eventSync = new OmieOrderEventSyncService($omie, $canonical, $this->logger);
        $result = $sync->syncPeriod($range['start'], $range['end'], 'job');
        $eventResult = $eventSync->syncPeriod($range['start'], $range['end'], 'job', (int) ($result['vendors_synced'] ?? 0));

        return [
            'ok' => true,
            'type' => 'canonical.sync',
            'start' => $range['start'],
            'end' => $range['end'],
            'result' => $result,
            'event_result' => $eventResult,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function materializeClients(array $payload): array
    {
        $pdo = $this->connectAnalytic();
        $service = new CustomerDimensionMaterializationService($pdo, $this->logger, new TableHealthService($pdo, $this->logger));

        return $service->run(isset($payload['actor_user_id']) ? (int) $payload['actor_user_id'] : null)
            + ['type' => 'clients.materialize'];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function materializeGeo(array $payload): array
    {
        $range = $this->range($payload);
        $canonical = new CanonicalRepository($this->connectAnalytic(), $this->logger);
        $missingTables = $canonical->missingGeoAnalyticsTables();
        if ($missingTables !== []) {
            throw new RuntimeException('Tabelas geograficas ausentes: ' . implode(', ', $missingTables));
        }

        $canonical->reconstructGeoFactTables($range['start'], $range['end']);
        return [
            'ok' => true,
            'type' => 'geo.materialize',
            'start' => $range['start'],
            'end' => $range['end'],
            'generated_at' => date(DATE_ATOM),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function materializeGeoCnae(array $payload): array
    {
        $pdo = $this->connectAnalytic();
        $service = new GeoCnaeMaterializationService($pdo, $this->logger, new TableHealthService($pdo, $this->logger));

        return $service->run(['dry_run' => false, 'rebuild' => false], isset($payload['actor_user_id']) ? (int) $payload['actor_user_id'] : null)
            + ['type' => 'geo_cnae.materialize'];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function materializeCommercial(array $payload): array
    {
        $pdo = $this->connectAnalytic();
        $tableHealth = new TableHealthService($pdo, $this->logger);
        $pipeline = $tableHealth->tableExists('biomie_pipeline_jobs')
            ? new PipelineStatusRepository($pdo, $this->logger)
            : null;
        $builder = new GoldMartBuilder($pdo, $this->logger, $pipeline);
        $service = new CommercialMaterializationService($pdo, $this->logger, $builder, $tableHealth);

        return $service->run(
            isset($payload['actor_user_id']) ? (int) $payload['actor_user_id'] : null,
            isset($payload['start']) ? (string) $payload['start'] : null,
            isset($payload['end']) ? (string) $payload['end'] : null,
        ) + ['type' => 'commercial.materialize'];
    }

    private function requireOmieCredentials(): void
    {
        if (!$this->config->hasOmieCredentials()) {
            throw new RuntimeException('Credenciais Omie nao configuradas.');
        }
    }

    private function omieClient(): OmieClient
    {
        return new OmieClient(
            (string) $this->config->get('omie.app_key'),
            (string) $this->config->get('omie.app_secret'),
            (int) $this->config->get('omie.request_timeout', 20),
            $this->logger,
            new OmieMethodPolicyRegistry($this->config),
            $this->cache,
            OmieExecutionContext::JOB,
        );
    }

    private function connectAnalytic(): \PDO
    {
        $connection = Database::isConfigured($this->config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
        if (!Database::isConfigured($this->config, $connection)) {
            throw new RuntimeException('Banco de dados nao configurado.');
        }

        return Database::connect($this->config, $connection);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{start:string,end:string}
     */
    private function range(array $payload): array
    {
        $start = trim((string) ($payload['start'] ?? date('Y-m-01')));
        $end = trim((string) ($payload['end'] ?? date('Y-m-t')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            throw new RuntimeException('Periodo invalido para job.');
        }

        return ['start' => $start, 'end' => $end];
    }
}

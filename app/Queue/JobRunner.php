<?php

declare(strict_types=1);

namespace BIomie\Queue;

use BIomie\BronzeRepository;
use BIomie\Cache;
use BIomie\CanonicalRepository;
use BIomie\CommercialMaterializationService;
use BIomie\Config;
use BIomie\CronLock;
use BIomie\CustomerDimensionMaterializationService;
use BIomie\Database;
use BIomie\DataPlatform\Gold\GeoPeriodRebuildService;
use BIomie\FinanceAnalyticsService;
use BIomie\FinanceRepository;
use BIomie\GeoCnaeMaterializationService;
use BIomie\GoldMartBuilder;
use BIomie\Logger;
use BIomie\Omie\OmieExecutionContext;
use BIomie\Omie\OmieClientFactory;
use BIomie\OmieClient;
use BIomie\OmieFinanceCanonicalSyncService;
use BIomie\OmieFiscalSyncService;
use BIomie\OmieMethodPolicyRegistry;
use BIomie\OmieOrderEventSyncService;
use BIomie\OmieRepository;
use BIomie\OmieRealtimeSyncService;
use BIomie\PipelineStatusRepository;
use BIomie\RefreshQueue;
use BIomie\SalesAnalyticsService;
use BIomie\Sync\WebhookInboxProcessor;
use BIomie\SyncPipelineRepository;
use BIomie\TableHealthService;
use BIomie\Support\TenantContext;
use RuntimeException;

final class JobRunner
{
    private ?OmieClientFactory $omieFactory = null;

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
        $companyId = (int) ($payload['company_id'] ?? 0);
        if ($companyId <= 0) {
            throw new RuntimeException('Job sem company_id valido: ' . $type);
        }
        TenantContext::bootForCompany(
            $this->connectAnalytic(),
            $companyId,
            isset($payload['canal']) ? (string) $payload['canal'] : null
        );
        $startedAt = microtime(true);

        $result = match ($type) {
            'finance.snapshot.refresh' => $this->refreshFinance($payload),
            'sales.snapshot.refresh' => $this->refreshSales($payload),
            'webhook.inbox.process' => $this->processWebhookInbox($payload),
            'omie.delta.sync' => $this->syncDelta($payload),
            'canonical.sync' => $this->syncCanonical($payload),
            'clients.materialize' => $this->materializeClients($payload),
            'geo.materialize' => $this->materializeGeo($payload),
            'geo_cnae.materialize' => $this->materializeGeoCnae($payload),
            'commercial.materialize' => $this->materializeCommercial($payload),
            'quote_agenda.order.refresh' => $this->refreshQuoteOrder($payload),
            'quote_agenda.company.scan' => $this->scanQuoteCompany($payload),
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
     * AGENDA DE ORÇAMENTOS (Fase 2) — reconciliação de UMA empresa sob demanda.
     *
     * É o que o botão "Atualizar" enfileira. Reusa o QuoteLiveSnapshotScanner,
     * que já garante o essencial: snapshot completo (todas as páginas, etapa
     * única, sem duplicidade) antes de qualquer desativação — timeout, 425,
     * 429, 500 ou paginação incompleta marcam `failed` e NÃO retiram nenhum
     * orçamento da Agenda.
     *
     * Uma empresa por job, sem paralelismo.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function scanQuoteCompany(array $payload): array
    {
        if (!(bool) $this->config->get('features.quote_agenda_webhook_refresh_enabled', false)) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'flag desligada', 'type' => 'quote_agenda.company.scan'];
        }
        $companyId = (int) ($payload['company_id'] ?? 0);
        if ($companyId <= 0) {
            throw new RuntimeException('quote_agenda.company.scan sem company_id.');
        }

        $pdo = $this->connectAnalytic();
        $reconnect = static fn () => $pdo;
        $canal = (string) ($payload['canal'] ?? 'varejo');

        $canonical = new CanonicalRepository($pdo, $this->logger, $reconnect, $companyId, $canal);
        $canonical->ensureFiscalSchema();
        $bronze = new BronzeRepository($pdo, $this->logger, $companyId, $reconnect);
        $bronze->ensureSchema();
        $client = (new \BIomie\Omie\OmieClientFactory(
            $pdo, $this->logger, $this->config, $this->cache, $reconnect
        ))->forCompany($companyId);

        $service = new \BIomie\QuoteAgenda\QuoteLiveSnapshotService(
            $pdo, $client,
            new \BIomie\OmieRepository($client, $this->config, $bronze, 'quote-agenda-manual'),
            $this->config, $canonical, $companyId
        );
        $scanner = new \BIomie\QuoteAgenda\QuoteLiveSnapshotScanner($pdo, $service, $this->logger, $companyId);

        return $scanner->scan(true, '10') + ['type' => 'quote_agenda.company.scan'];
    }

    /**
     * AGENDA DE ORÇAMENTOS (Fase 2) — refresh direcionado de UM pedido.
     *
     * É aqui que a chamada à Omie acontece: fora da requisição do webhook,
     * assíncrona, uma empresa por job, sem paralelismo. O debounce por pedido
     * (60s, baseado no log) pode recusar a chamada — e isso é sucesso, não erro.
     *
     * A flag é reconferida no consumo: um job enfileirado antes de desligarem a
     * flag não pode executar depois.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function refreshQuoteOrder(array $payload): array
    {
        if (!(bool) $this->config->get('features.quote_agenda_webhook_refresh_enabled', false)) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'flag desligada', 'type' => 'quote_agenda.order.refresh'];
        }

        $companyId = (int) ($payload['company_id'] ?? 0);
        $orderId   = trim((string) ($payload['omie_order_id'] ?? ''));
        if ($companyId <= 0 || $orderId === '') {
            throw new RuntimeException('quote_agenda.order.refresh sem company_id/omie_order_id.');
        }

        $pdo = $this->connectAnalytic();
        $client = (new \BIomie\Omie\OmieClientFactory(
            $pdo, $this->logger, $this->config, $this->cache, static fn () => $pdo
        ))->forCompany($companyId);
        $endpoint = (string) $this->config->get('omie.endpoints.orders', 'https://app.omie.com.br/api/v1/produtos/pedido/');

        // SOMENTE LEITURA no Omie. O BIomie nunca escreve no ERP nesta fase.
        $consultar = static function (string $id) use ($client, $endpoint): array {
            try {
                $resp = $client->post($endpoint, 'ConsultarPedido', ['codigo_pedido' => (int) $id]);
                $etapa = $resp['pedido_venda_produto']['cabecalho']['etapa'] ?? $resp['cabecalho']['etapa'] ?? null;

                return ['ok' => true, 'etapa' => $etapa];
            } catch (\Throwable $e) {
                $http = null;
                if (preg_match('/\b(4\d{2}|5\d{2})\b/', $e->getMessage(), $m)) { $http = (int) $m[1]; }

                return ['ok' => false, 'error' => $e->getMessage(), 'http' => $http];
            }
        };

        // ── PORTÃO DA DESATIVAÇÃO ───────────────────────────────────────────
        // O portão vive no QuoteOrderRefresher (uma regra, um lugar): keep/retry
        // seguem automáticos; resolve/vanished de origem AUTOMÁTICA (webhook OU
        // scanner OU manual) ficam RETIDOS enquanto a auto-desativação estiver
        // desligada. Aqui só passamos a origem e o valor da flag — a decisão de
        // reter é do núcleo, então nenhum caller consegue burlar por acidente.
        $source = (string) ($payload['source'] ?? 'webhook');
        $autoDeactivationEnabled = (bool) $this->config->get('features.quote_agenda_auto_deactivation_enabled', false);

        $result = (new \BIomie\QuoteAgenda\QuoteOrderRefresher($pdo, $companyId, $consultar))
            ->refresh($orderId, $source, true, $autoDeactivationEnabled);

        if (($result['held'] ?? false) === true) {
            $this->logger->warning('Agenda: desativação automática RETIDA para validação manual.', [
                'company_id' => $companyId, 'order_id' => $orderId, 'source' => $source,
                'action' => $result['action'] ?? null, 'resolution' => $result['resolution'] ?? null,
                'etapa_seen' => $result['etapa_seen'] ?? null,
            ]);
            $this->notifyDeactivationHold($pdo, $companyId, $orderId, $source, $result);
        }

        return $result + ['ok' => true, 'type' => 'quote_agenda.order.refresh'];
    }

    /**
     * Alerta o gestor de que uma desativação AUTOMÁTICA (webhook/scanner/manual)
     * foi RETIDA e precisa de validação manual (pedido, etapa, empresa, followup)
     * antes de aplicar. A origem real entra na mensagem para a trilha ser fiel.
     *
     * @param array<string,mixed> $result
     */
    private function notifyDeactivationHold(\PDO $pdo, int $companyId, string $orderId, string $source, array $result): void
    {
        try {
            $acao = (string) ($result['action'] ?? '?');
            $res  = (string) ($result['resolution'] ?? '?');
            $etapa = (string) ($result['etapa_seen'] ?? '?');
            (new \BIomie\Notifications\NotificationService($pdo))->push([
                'category' => 'business',
                'severity' => 'warning',
                'priority' => 'high',
                'icon' => 'gpp_maybe',
                'company_id' => $companyId,
                'title' => 'Agenda: validar desativação automática (retida)',
                'body' => sprintf(
                    'Pedido %s decidido como %s/%s (etapa %s) via %s e RETIDO. '
                    . 'Valide pedido, etapa, empresa e followup; depois aplique só este ID: '
                    . 'php bin/quote_agenda_refresh.php --company=<SLUG> --order=%s --apply',
                    $orderId, $acao, $res, $etapa, $source, $orderId
                ),
                'source' => 'quote_agenda.deactivation.hold',
                'dedupe_key' => sprintf('quote_agenda.hold|company:%d|order:%s', $companyId, $orderId),
                'dedupe_window_seconds' => 86400,
            ]);
        } catch (\Throwable $e) {
            // Notificar é acessório; falhar aqui não pode derrubar o job.
            $this->logger->warning('Falha ao notificar hold da Agenda.', ['error' => $e->getMessage()]);
        }
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
        // Fila + PDO habilitam o hook da Agenda (Fase 2). Sem eles o hook
        // simplesmente não enfileira — a reconciliação periódica cobre.
        $processor = new WebhookInboxProcessor(
            $this->config, $pipeline, $this->logger, $queue,
            new FileJobQueue($this->rootDir . '/storage/cache', $this->logger),
            $this->connectAnalytic()
        );

        return $processor->process(
            max(1, (int) ($payload['limit'] ?? 20)),
            array_values(array_filter(array_map('strval', (array) ($payload['domains'] ?? []))))
        ) + ['ok' => true, 'type' => 'webhook.inbox.process'];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function syncDelta(array $payload): array
    {
        $this->requireOmieCredentials();
        $pdo = $this->connectAnalytic();
        $lock = $this->acquireFiscalWriteLock($pdo);
        try {
        // O lock fiscal pertence a esta sessao. Em worker, uma perda de PDO
        // aborta o job para retry idempotente; continuar em outra sessao faria
        // a escrita prosseguir sem a exclusividade garantida acima.
        $reconnect = static function (): \PDO {
            throw new RuntimeException('Conexao fiscal perdida; o job deve ser reexecutado com novo lock.');
        };
        $pipeline = new SyncPipelineRepository($pdo, $this->logger);
        $pipeline->ensureSchema();
        $canonical = new CanonicalRepository($pdo, $this->logger, $reconnect);
        $bronze = new BronzeRepository($pdo, $this->logger);
        $bronze->ensureSchema();
        $canonical->ensureFiscalSchema();

        $client = $this->omieClient();
        $omie = new OmieRepository($client, $this->config, $bronze, 'job-delta-sync');
        $fiscalSync = new OmieFiscalSyncService($this->config, $omie, $canonical, $this->logger);
        $eventSync = new OmieOrderEventSyncService($omie, $canonical, $this->logger);
        $financeRepository = new FinanceRepository($client, $this->config, $this->logger, $this->cache, $bronze, 'job-delta-sync');
        $financeSync = new OmieFinanceCanonicalSyncService($financeRepository, $canonical, $this->logger);
        $queue = new RefreshQueue($this->rootDir . '/storage/cache', $this->logger);
        $finance = new FinanceAnalyticsService($this->config, $this->cache, $this->logger, $financeRepository, $omie, $queue);
        $service = new OmieRealtimeSyncService(
            $this->config,
            $this->logger,
            $pipeline,
            $fiscalSync,
            $eventSync,
            $financeSync,
            $finance,
            $queue
        );

        $domains = array_values(array_filter(array_map('strval', (array) ($payload['domains'] ?? []))));
        $runId = trim((string) ($payload['run_id'] ?? ''));
        $result = $service->runDelta($domains, null, $runId !== '' ? $runId : null);
        if (empty($result['ok'])) {
            throw new RuntimeException('Sync delta Omie falhou.');
        }

        return $result + [
            'ok' => true,
            'type' => 'omie.delta.sync',
            'domains' => $domains,
            'run_id' => $runId !== '' ? $runId : null,
            'generated_at' => date(DATE_ATOM),
        ];
        } finally {
            $lock->release();
        }
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
        $lock = $this->acquireFiscalWriteLock($pdo);
        try {
        $reconnect = static function (): \PDO {
            throw new RuntimeException('Conexao fiscal perdida; o job deve ser reexecutado com novo lock.');
        };
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
        } finally {
            $lock->release();
        }
    }

    private function acquireFiscalWriteLock(\PDO $pdo): CronLock
    {
        $lock = CronLock::acquire(
            $pdo,
            'biomie:fiscal_write:company:' . TenantContext::companyId(),
            $this->logger,
        );
        if ($lock === null) {
            throw new RuntimeException('Outro sync fiscal da empresa ja esta em execucao.');
        }

        return $lock;
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
        $service = new GeoPeriodRebuildService($this->connectAnalytic(), $this->logger);
        $result = $service->rebuild($range['start'], $range['end'], [
            'assert' => (bool) ($payload['assert'] ?? true),
            'allow_open_month' => (bool) ($payload['allow_open_month'] ?? true),
            'force_full_month' => (bool) ($payload['force_full_month'] ?? false),
            'actor_user_id' => isset($payload['actor_user_id']) ? (int) $payload['actor_user_id'] : null,
            'source' => 'job_runner_geo.materialize',
        ]);
        if (empty($result['ok'])) {
            throw new RuntimeException('Materializacao geo falhou: ' . implode('; ', array_map('strval', (array) ($result['errors'] ?? []))));
        }

        return $result + [
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
        $this->omieClient();
    }

    private function omieClient(): OmieClient
    {
        $this->omieFactory ??= new OmieClientFactory(
            $this->connectAnalytic(),
            $this->logger,
            $this->config,
            $this->cache,
        );
        return $this->omieFactory->forCompany(TenantContext::companyId());
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

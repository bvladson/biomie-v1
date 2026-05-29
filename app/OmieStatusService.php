<?php

declare(strict_types=1);

namespace BIomie;

use BIomie\Queue\FileJobQueue;
use BIomie\Queue\DeadLetterRepository;
use BIomie\Queue\WorkerHeartbeatRepository;
use BIomie\Support\SecretMask;
use PDO;
use Throwable;

/**
 * Orquestra todos os blocos de dados da página Conexão Omie.
 *
 * getOverview() é chamado no carregamento (dados locais rápidos).
 * testConnection() é chamado por POST explícito.
 */
final class OmieStatusService
{
    private const FRESH_OK_HOURS = 24;
    private const FRESH_WARNING_HOURS = 72;

    public function __construct(
        private readonly Config $config,
        private readonly PDO $pdo,
        private readonly Logger $logger,
        private readonly OmieHealthCheckService $healthChecks,
        private readonly TableHealthService $tableHealth,
        private readonly ?PipelineStatusRepository $pipelineStatus = null,
        private readonly ?SyncPipelineRepository $syncPipeline = null,
    ) {}

    /**
     * Retorna visão completa do status Omie para a página.
     * Chamado no GET da página — usa apenas dados locais.
     *
     * @return array<string,mixed>
     */
    public function getOverview(): array
    {
        return [
            'ok' => true,
            'generated_at' => date('Y-m-d H:i:s'),
            'credentials' => $this->getCredentialStatus(),
            'api' => $this->getApiStatus(),
            'source_sync_status' => $this->getSourceSyncStatus(),
            'canonical_freshness_status' => $this->getCanonicalFreshnessStatus(),
            'gold_materialization_status' => $this->getGoldStatus(),
            'worker_queue_status' => $this->getWorkerQueueStatus(),
            'webhook_status' => $this->getWebhookStatus(),
            'data' => $this->getDataStatus(),
            'tables' => $this->getTableStatus(),
            'gold' => $this->getGoldStatus(),
            'jobs' => $this->getJobStatus(),
            'operational' => $this->getOperationalStatus(),
            'automation_commands' => $this->getAutomationCommands(),
            'rate_limits' => $this->getRateLimitStatus(),
            'sync_domains' => $this->getSyncDomainOverview(),
            'last_error' => $this->getLastError(),
            'checks' => $this->healthChecks->runAll(),
            'recommended_actions' => $this->getRecommendedActions(),
            'recent_syncs' => $this->getRecentSyncs(),
            'diagnostics' => $this->getDiagnostics(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getCredentialStatus(): array
    {
        $appKey = (string) $this->config->get('omie.app_key', '');
        $appSecret = (string) $this->config->get('omie.app_secret', '');
        $keyConfigured = SecretMask::isConfigured($appKey);
        $secretConfigured = SecretMask::isConfigured($appSecret);

        return [
            'app_key_configured' => $keyConfigured,
            'app_key_masked' => $keyConfigured ? SecretMask::maskSecret($appKey) : null,
            'app_secret_configured' => $secretConfigured,
            'credentials_valid' => ($keyConfigured && $secretConfigured) ? $this->lastTestResult() : 'not_configured',
            'environment' => (string) $this->config->get('app.env', getenv('APP_ENV') ?: 'production'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getApiStatus(): array
    {
        $lastTest = $this->getLastTestRow();

        return [
            'last_test_at' => $lastTest['created_at'] ?? null,
            'last_success_at' => ($lastTest !== null && ($lastTest['status'] ?? '') === 'ok') ? ($lastTest['created_at'] ?? null) : null,
            'last_failure_at' => ($lastTest !== null && ($lastTest['status'] ?? '') !== 'ok') ? ($lastTest['created_at'] ?? null) : null,
            'last_latency_ms' => isset($lastTest['latency_ms']) ? (int) $lastTest['latency_ms'] : null,
            'last_endpoint' => $lastTest['endpoint'] ?? null,
            'last_method' => $lastTest['method'] ?? null,
            'status' => $lastTest !== null ? (string) ($lastTest['status'] ?? 'unknown') : 'pending',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getDataStatus(): array
    {
        return [
            'commercial'  => $this->domainDataStatus('biomie_pedidos_canonicos', 'Pedidos'),
            'invoices'    => $this->domainDataStatus('biomie_notas_fiscais_canonicas', 'NF'),
            'clients'     => $this->clientsDimStatus(),
            'clients_geo' => $this->domainDataStatus('biomie_clientes_geo_dim', 'Clientes geo'),
            'bairros_dim' => $this->bairrosDimStatus(),
            'finance'     => $this->domainDataStatus('biomie_titulos_financeiros_canonicos', 'Titulos'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function getSourceSyncStatus(): array
    {
        $cursorsCount = $this->countRows('biomie_sync_cursors');
        $runsCount = $this->countRows('biomie_sync_runs');
        $lastRun = $this->latestSyncRun();
        $lastSuccessAt = $this->latestSyncSuccessAt();
        $lastCommercialSyncAt = $this->latestSyncSuccessAtForDomain('commercial');
        $lastFinanceSyncAt = $this->latestSyncSuccessAtForDomain('finance');
        $lastFiscalSyncAt = $this->latestSyncSuccessAtForDomain('fiscal') ?? $lastCommercialSyncAt;
        $recordsProcessed24h = $this->recordsProcessedLast24h();
        $domains = $this->getSyncDomainOverview();

        if (($cursorsCount ?? 0) === 0 && ($runsCount ?? 0) === 0) {
            return [
                'label' => 'Sync Omie',
                'status' => 'warning',
                'state' => 'never_ran',
                'message' => 'Pipeline delta nunca executado. Configure o cron/worker ou execute sincronizacao manual.',
                'recommended_action' => 'Rode php cron/sync_realtime_pipeline.php --domains=commercial e php cron/sync_realtime_pipeline.php --domains=finance, ou agende pelo worker.',
                'cursors_count' => (int) ($cursorsCount ?? 0),
                'runs_count' => (int) ($runsCount ?? 0),
                'last_success_at' => null,
                'last_commercial_sync_at' => null,
                'last_fiscal_sync_at' => null,
                'last_finance_sync_at' => null,
                'records_processed_24h' => 0,
                'domains' => $domains,
                'last_run' => null,
            ];
        }

        $status = 'pending';
        $message = 'Pipeline delta registrado, aguardando primeira execucao com sucesso.';
        $recommended = 'Verifique cron e worker.';
        if (is_array($lastRun) && in_array((string) ($lastRun['status'] ?? ''), ['failed_retryable', 'failed_terminal'], true)) {
            $status = 'danger';
            $message = 'Ultima execucao do sync delta falhou.';
            $recommended = 'Verifique o erro sanitizado em Historico sync runs e rode novamente o sync.';
        } elseif ($lastSuccessAt !== null) {
            $status = $this->freshnessStatus($lastSuccessAt);
            $message = match ($status) {
                'ok' => 'Sync Omie atualizado.',
                'warning' => 'Sync Omie pode estar atrasado.',
                default => 'Sync Omie atrasado.',
            };
            $recommended = $status === 'ok' ? null : 'Execute php cron/sync_realtime_pipeline.php --domains=commercial,finance.';
        }

        return [
            'label' => 'Sync Omie',
            'status' => $status,
            'state' => $lastSuccessAt !== null ? 'has_success' : 'no_success_yet',
            'message' => $message,
            'recommended_action' => $recommended,
            'cursors_count' => (int) ($cursorsCount ?? 0),
            'runs_count' => (int) ($runsCount ?? 0),
            'last_success_at' => $lastSuccessAt,
            'last_commercial_sync_at' => $lastCommercialSyncAt,
            'last_fiscal_sync_at' => $lastFiscalSyncAt,
            'last_fiscal_sync_note' => $lastFiscalSyncAt !== null && $lastFiscalSyncAt === $lastCommercialSyncAt
                ? 'Fiscal/NF e executado pelo dominio commercial neste pipeline.'
                : null,
            'last_finance_sync_at' => $lastFinanceSyncAt,
            'records_processed_24h' => $recordsProcessed24h,
            'domains' => $domains,
            'last_error' => is_array($lastRun) && !empty($lastRun['error_summary'])
                ? SecretMask::sanitizeErrorMessage((string) $lastRun['error_summary'], 160)
                : null,
            'last_run' => $lastRun,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function getCanonicalFreshnessStatus(): array
    {
        $datasets = [
            'commercial_orders' => $this->canonicalTableFreshness('biomie_pedidos_canonicos', 'Pedidos canonicos'),
            'invoices' => $this->canonicalTableFreshness('biomie_notas_fiscais_canonicas', 'NF canonicas'),
            'finance_titles' => $this->canonicalTableFreshness('biomie_titulos_financeiros_canonicos', 'Titulos financeiros'),
        ];

        $status = $this->worstStatus(array_map(
            static fn (array $row): string => (string) ($row['status'] ?? 'unknown'),
            $datasets
        ));

        $message = match ($status) {
            'ok' => 'Camada canonica atualizada.',
            'warning' => 'Camada canonica incompleta ou com atraso moderado.',
            'danger' => 'Camada canonica desatualizada ou indisponivel.',
            default => 'Camada canonica sem leitura conclusiva.',
        };

        return [
            'label' => 'Origem canonica',
            'status' => $status,
            'message' => $message,
            'datasets' => $datasets,
            'freshness_rule' => [
                'ok_hours' => self::FRESH_OK_HOURS,
                'warning_hours' => self::FRESH_WARNING_HOURS,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function canonicalTableFreshness(string $table, string $label): array
    {
        if (!$this->tableHealth->tableExists($table)) {
            return [
                'table' => $table,
                'label' => $label,
                'rows' => null,
                'last_sync' => null,
                'age_hours' => null,
                'status' => 'danger',
                'empty_class' => 'empty_error',
                'message' => 'Tabela canonica ausente.',
                'recommended_action' => 'Aplique migrations e execute o sync Omie.',
            ];
        }

        $rows = $this->tableHealth->safeCount($table);
        $lastSync = $this->tableHealth->lastUpdatedAt($table, [
            'sincronizado_em',
            'synced_at',
            'updated_at',
            'fetched_at',
            'ingested_at',
            'created_at',
        ]);

        if (($rows ?? 0) === 0) {
            $message = $table === 'biomie_titulos_financeiros_canonicos'
                ? 'Financeiro ainda nao sincronizado.'
                : 'Dados canonicos ainda nao sincronizados.';

            return [
                'table' => $table,
                'label' => $label,
                'rows' => (int) ($rows ?? 0),
                'last_sync' => $lastSync,
                'age_hours' => null,
                'status' => 'warning',
                'empty_class' => 'empty_not_synced',
                'message' => $message,
                'recommended_action' => $table === 'biomie_titulos_financeiros_canonicos'
                    ? 'Execute php cron/sync_realtime_pipeline.php --domains=finance.'
                    : 'Execute php cron/sync_realtime_pipeline.php --domains=commercial.',
            ];
        }

        if ($lastSync === null) {
            return [
                'table' => $table,
                'label' => $label,
                'rows' => (int) $rows,
                'last_sync' => null,
                'age_hours' => null,
                'status' => 'warning',
                'empty_class' => null,
                'message' => 'Sem timestamp local confiavel para medir freshness.',
                'recommended_action' => 'Verifique colunas de sincronizacao da tabela.',
            ];
        }

        $ageHours = $this->ageHours($lastSync);
        $status = $this->freshnessStatus($lastSync);

        return [
            'table' => $table,
            'label' => $label,
            'rows' => (int) $rows,
            'last_sync' => $lastSync,
            'age_hours' => $ageHours,
            'status' => $status,
            'empty_class' => null,
            'message' => match ($status) {
                'ok' => 'Atualizado nas ultimas 24h.',
                'warning' => 'Atualizado entre 24h e 72h.',
                default => 'Atualizacao acima de 72h.',
            },
            'recommended_action' => $status === 'ok' ? null : 'Execute o sync delta Omie do dominio correspondente.',
        ];
    }

    /**
     * Returns enriched status for biomie_clientes_dim including document/vendor/geo/CNAE breakdown.
     *
     * @return array<string,mixed>
     */
    private function clientsDimStatus(): array
    {
        $base = $this->domainDataStatus('biomie_clientes_dim', 'Clientes dimensao');

        if (!$this->tableHealth->tableExists('biomie_clientes_dim')) {
            return $base;
        }

        $total = (int) ($base['count'] ?? 0);
        if ($total === 0) {
            $base['detail'] = null;
            $base['recommended_action'] = $this->clientsDimRecommendedAction($total);
            return $base;
        }

        try {
            $stmt = $this->pdo->query(
                "SELECT
                    SUM(CASE WHEN documento_normalizado IS NOT NULL AND documento_normalizado != '' THEN 1 ELSE 0 END) AS com_doc,
                    SUM(CASE WHEN documento_normalizado IS NULL OR documento_normalizado = ''     THEN 1 ELSE 0 END) AS sem_doc,
                    SUM(CASE WHEN vendedor_responsavel_confidence > 0                             THEN 1 ELSE 0 END) AS com_vend,
                    SUM(CASE WHEN vendedor_responsavel_confidence = 0                             THEN 1 ELSE 0 END) AS sem_vend,
                    SUM(CASE WHEN cidade IS NOT NULL AND cidade != '' AND bairro IS NOT NULL AND bairro != '' THEN 1 ELSE 0 END) AS com_geo,
                    SUM(CASE WHEN bairro IS NULL OR bairro = ''                                   THEN 1 ELSE 0 END) AS sem_bairro,
                    SUM(CASE WHEN cnae_codigo IS NOT NULL AND cnae_codigo != ''                   THEN 1 ELSE 0 END) AS com_cnae,
                    SUM(CASE WHEN cnae_codigo IS NULL OR cnae_codigo = ''                         THEN 1 ELSE 0 END) AS sem_cnae
                 FROM biomie_clientes_dim"
            );
            $row = $stmt !== false ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        } catch (Throwable) {
            $row = null;
        }

        if (is_array($row)) {
            $base['detail'] = [
                'com_documento'  => (int) ($row['com_doc']   ?? 0),
                'sem_documento'  => (int) ($row['sem_doc']   ?? 0),
                'com_vendedor'   => (int) ($row['com_vend']  ?? 0),
                'sem_vendedor'   => (int) ($row['sem_vend']  ?? 0),
                'com_geo'        => (int) ($row['com_geo']   ?? 0),
                'sem_bairro'     => (int) ($row['sem_bairro']?? 0),
                'com_cnae'       => (int) ($row['com_cnae']  ?? 0),
                'sem_cnae'       => (int) ($row['sem_cnae']  ?? 0),
            ];
        }

        $base['recommended_action'] = $this->clientsDimRecommendedAction($total);
        return $base;
    }

    private function clientsDimRecommendedAction(int $total): ?string
    {
        if ($total === 0) {
            $martCount = $this->tableHealth->tableExists('mart_cliente_periodo')
                ? ($this->tableHealth->safeCount('mart_cliente_periodo') ?? 0)
                : 0;
            if ($martCount > 0) {
                return 'Execute clientes:materialize-dim no console para consolidar clientes.';
            }
            return null;
        }

        if (!$this->tableHealth->tableExists('biomie_clientes_geo_dim')) {
            return null;
        }
        $geoCount = $this->tableHealth->safeCount('biomie_clientes_geo_dim') ?? 0;
        if ($geoCount === 0) {
            return 'Clientes cadastrados, mas geo vazio. Execute geo:materialize para preparar o mapa.';
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function bairrosDimStatus(): array
    {
        $base = $this->domainDataStatus('biomie_bairros_fortaleza_dim', 'Bairros Fortaleza');

        if (!$this->tableHealth->tableExists('biomie_bairros_fortaleza_dim')) {
            $base['recommended_action'] = 'Aplique a migration BairrosFortalezaDim para disponibilizar os 121 bairros oficiais.';
            return $base;
        }

        $count = (int) ($base['count'] ?? 0);

        if ($count === 0) {
            $base['recommended_action'] = 'Re-execute a migration para semear os 121 bairros de Fortaleza.';
        } elseif ($count < 121) {
            $base['recommended_action'] = sprintf('Apenas %d bairros encontrados. Re-execute a migration.', $count);
        } else {
            $base['recommended_action'] = null;
        }

        try {
            $stmt = $this->pdo->query(
                "SELECT
                    SUM(CASE WHEN regional_antiga = 'SER I'   THEN 1 ELSE 0 END) AS ser_i,
                    SUM(CASE WHEN regional_antiga = 'SER II'  THEN 1 ELSE 0 END) AS ser_ii,
                    SUM(CASE WHEN regional_antiga = 'SER III' THEN 1 ELSE 0 END) AS ser_iii,
                    SUM(CASE WHEN regional_antiga = 'SER IV'  THEN 1 ELSE 0 END) AS ser_iv,
                    SUM(CASE WHEN regional_antiga = 'SER V'   THEN 1 ELSE 0 END) AS ser_v,
                    SUM(CASE WHEN regional_antiga = 'SER VI'  THEN 1 ELSE 0 END) AS ser_vi,
                    SUM(CASE WHEN ativo = 1 THEN 1 ELSE 0 END)                   AS ativos
                 FROM biomie_bairros_fortaleza_dim"
            );
            $row = $stmt !== false ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        } catch (Throwable) {
            $row = null;
        }

        if (is_array($row)) {
            $base['detail'] = [
                'ser_i'   => (int) ($row['ser_i']   ?? 0),
                'ser_ii'  => (int) ($row['ser_ii']  ?? 0),
                'ser_iii' => (int) ($row['ser_iii'] ?? 0),
                'ser_iv'  => (int) ($row['ser_iv']  ?? 0),
                'ser_v'   => (int) ($row['ser_v']   ?? 0),
                'ser_vi'  => (int) ($row['ser_vi']  ?? 0),
                'ativos'  => (int) ($row['ativos']  ?? 0),
                'total'   => $count,
                'expected'=> 121,
                'complete'=> $count >= 121,
            ];
        }

        return $base;
    }

    /**
     * @return array<string,list<array<string,mixed>>>
     */
    public function getTableStatus(): array
    {
        return $this->healthChecks->tableCatalog();
    }

    /**
     * @return array<string,mixed>
     */
    public function getGoldStatus(): array
    {
        $lastJob = $this->latestGoldMartJob();
        $lastCommercial = is_array($lastJob)
            ? (string) ($lastJob['completed_at'] ?? '')
            : (string) ($this->pipelineStatus?->lastSuccessfulRun('gold_mart_refresh') ?? '');
        $lastCommercial = $lastCommercial !== '' ? $lastCommercial : null;

        $geoReady = $this->tableHealth->tableExists('biomie_clientes_geo_dim')
            && $this->tableHealth->tableExists('biomie_vendas_geo_cliente_fact');

        $cnaeReady = $this->tableHealth->tableExists('mart_vendas_por_cnae_cnpj');

        $status = 'pending';
        $lastRawStatus = is_array($lastJob) ? (string) ($lastJob['status'] ?? '') : '';
        $canonicalFreshness = $this->getCanonicalFreshnessStatus();
        $canonicalStatus = (string) ($canonicalFreshness['status'] ?? 'unknown');
        $canonicalIsFresh = $canonicalStatus === 'ok';
        $sourceWarning = null;

        if ($lastRawStatus === 'failed') {
            $status = 'danger';
        } elseif ($lastRawStatus === 'running') {
            $status = 'pending';
        } elseif ($lastCommercial !== null && is_array($lastJob) && (int) ($lastJob['rows_processed'] ?? 0) === 0) {
            $status = 'warning';
        } elseif ($lastCommercial !== null) {
            $ageHours = (time() - strtotime($lastCommercial)) / 3600;
            $status = $ageHours <= 24 ? 'ok' : ($ageHours <= 72 ? 'warning' : 'danger');
            if ($status === 'ok' && !$canonicalIsFresh) {
                $status = $canonicalStatus === 'danger' ? 'danger' : 'warning';
                $sourceWarning = 'Gold foi materializado, mas a origem Omie/canonica esta atrasada.';
            } elseif ($status === 'warning' && $canonicalStatus === 'danger') {
                $status = 'danger';
                $sourceWarning = 'Gold e origem Omie/canonica estao atrasados.';
            }
        }

        return [
            'last_materialization_at' => $lastCommercial,
            'last_materialization_rows' => is_array($lastJob) ? (int) ($lastJob['rows_processed'] ?? 0) : null,
            'geo_ready' => $geoReady,
            'cnae_ready' => $cnaeReady,
            'status' => $status,
            'message' => $sourceWarning ?? match ($status) {
                'ok' => 'Gold atualizado e fonte canonica recente.',
                'warning' => 'Materializacao Gold requer atencao.',
                'danger' => 'Gold ou origem canonica estao atrasados.',
                default => 'Materializacao Gold pendente.',
            },
            'source_canonical_status' => $canonicalStatus,
            'source_warning' => $sourceWarning,
            'canonical_freshness' => $canonicalFreshness,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getJobStatus(): array
    {
        if (!$this->tableHealth->tableExists('biomie_pipeline_jobs')) {
            return ['available' => false, 'running' => 0, 'completed_recent' => 0, 'failed' => 0, 'last_job' => null];
        }

        try {
            $running = (int) $this->pdo->query("SELECT COUNT(*) FROM biomie_pipeline_jobs WHERE status='running'")->fetchColumn();
            $failed = (int) $this->pdo->query("SELECT COUNT(*) FROM biomie_pipeline_jobs WHERE status='failed'")->fetchColumn();
            $recent = (int) $this->pdo->query("SELECT COUNT(*) FROM biomie_pipeline_jobs WHERE status='completed' AND completed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();

            $lastStmt = $this->pdo->query("SELECT job_name, status, completed_at, duration_ms, error_message FROM biomie_pipeline_jobs ORDER BY id DESC LIMIT 1");
            $lastJob = $lastStmt !== false ? $lastStmt->fetch(PDO::FETCH_ASSOC) : null;
            if (is_array($lastJob) && isset($lastJob['error_message'])) {
                $lastJob['error_message'] = SecretMask::sanitizeErrorMessage((string) $lastJob['error_message'], 200);
            }
        } catch (Throwable) {
            return ['available' => true, 'running' => 0, 'completed_recent' => 0, 'failed' => 0, 'last_job' => null];
        }

        return [
            'available' => true,
            'running' => $running,
            'completed_recent' => $recent,
            'failed' => $failed,
            'last_job' => is_array($lastJob) ? $lastJob : null,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getLastError(): ?array
    {
        // From sync_runs
        if ($this->tableHealth->tableExists('biomie_sync_runs')) {
            try {
                $stmt = $this->pdo->query(
                    "SELECT error_summary, finished_at, domain_name, run_type FROM biomie_sync_runs
                     WHERE status IN ('failed_retryable','failed_terminal') AND error_summary IS NOT NULL
                     ORDER BY id DESC LIMIT 1"
                );
                $row = $stmt !== false ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
                if (is_array($row) && !empty($row['error_summary'])) {
                    return [
                        'message' => SecretMask::sanitizeErrorMessage((string) $row['error_summary'], 200),
                        'date' => (string) ($row['finished_at'] ?? ''),
                        'domain' => (string) ($row['domain_name'] ?? ''),
                        'type' => (string) ($row['run_type'] ?? ''),
                        'severity' => 'danger',
                        'recommended_action' => 'Verifique o diagnostico completo.',
                        'link' => 'admin.php?tab=diagnostico',
                    ];
                }
            } catch (Throwable) {}
        }

        // From pipeline_jobs
        if ($this->tableHealth->tableExists('biomie_pipeline_jobs')) {
            try {
                $stmt = $this->pdo->query(
                    "SELECT error_message, completed_at, job_name FROM biomie_pipeline_jobs
                     WHERE status='failed' AND error_message IS NOT NULL ORDER BY id DESC LIMIT 1"
                );
                $row = $stmt !== false ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
                if (is_array($row) && !empty($row['error_message'])) {
                    return [
                        'message' => SecretMask::sanitizeErrorMessage((string) $row['error_message'], 200),
                        'date' => (string) ($row['completed_at'] ?? ''),
                        'domain' => (string) ($row['job_name'] ?? ''),
                        'type' => 'gold',
                        'severity' => 'danger',
                        'recommended_action' => 'Verifique logs dos jobs.',
                        'link' => 'admin.php?tab=diagnostico',
                    ];
                }
            } catch (Throwable) {}
        }

        return null;
    }

    /**
     * Gera recomendações automáticas com base nos checks (max 5).
     *
     * @return list<array{message:string,link:?string,severity:string}>
     */
    public function getRecommendedActions(): array
    {
        $actions = [];
        $checks = $this->healthChecks->runAll();

        foreach ($checks as $check) {
            if (($check['recommended_action'] ?? null) !== null) {
                $actions[] = [
                    'message' => (string) $check['recommended_action'],
                    'link' => $check['link'] ?? null,
                    'severity' => (string) ($check['severity'] ?? 'pending'),
                    'key' => (string) ($check['key'] ?? ''),
                ];
            }

            if (count($actions) >= 5) {
                break;
            }
        }

        return $actions;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function getRecentSyncs(): array
    {
        if ($this->syncPipeline === null) {
            return [];
        }

        try {
            $runs = $this->syncPipeline->listRecentRuns(5);
            $result = [];
            foreach ($runs as $run) {
                $result[] = [
                    'domain' => (string) ($run['domain_name'] ?? ''),
                    'type' => (string) ($run['run_type'] ?? ''),
                    'status' => (string) ($run['status'] ?? ''),
                    'started_at' => (string) ($run['started_at'] ?? ''),
                    'finished_at' => $run['finished_at'] ?? null,
                    'items_processed' => (int) ($run['items_processed'] ?? 0),
                    'error' => isset($run['error_summary']) ? SecretMask::sanitizeErrorMessage((string) $run['error_summary'], 100) : null,
                ];
            }
            return $result;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function getDiagnostics(): array
    {
        $appKey = (string) $this->config->get('omie.app_key', '');

        return [
            'generated_at' => date('Y-m-d H:i:s'),
            'environment' => (string) $this->config->get('app.env', getenv('APP_ENV') ?: 'production'),
            'app_name' => (string) $this->config->get('app.name', 'BIomie'),
            'timezone' => (string) $this->config->get('app.timezone', 'America/Fortaleza'),
            'app_key_configured' => SecretMask::isConfigured($appKey),
            'app_key_masked' => SecretMask::isConfigured($appKey) ? SecretMask::maskSecret($appKey) : null,
            'app_secret_configured' => SecretMask::isConfigured((string) $this->config->get('omie.app_secret', '')),
            'rate_limits' => $this->getRateLimitStatus(),
            'operational' => $this->getOperationalStatus(),
            'latest_materialization' => $this->latestMaterializationEvent(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function getOperationalStatus(): array
    {
        $queue = new FileJobQueue(Bootstrap::rootPath('storage/cache'), $this->logger);
        $fileQueue = $queue->summary();
        $syncState = [
            'webhook_inbox' => null,
            'cursors' => [],
            'recent_runs' => [],
        ];

        if ($this->syncPipeline !== null) {
            try {
                $summary = $this->syncPipeline->summarizeOperationalState(20);
                $syncState['webhook_inbox'] = $summary['webhook_inbox'] ?? null;
                $syncState['cursors'] = $this->publicCursors((array) ($summary['cursors'] ?? []));
                $syncState['recent_runs'] = $this->publicRecentRuns((array) ($summary['recent_runs'] ?? []));
            } catch (Throwable $exception) {
                $syncState['error'] = SecretMask::sanitizeErrorMessage($exception->getMessage(), 180);
            }
        }

        return [
            'file_queue' => $fileQueue,
            'sync_pipeline' => $syncState,
            'recent_24h' => $this->syncRunsLast24h(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function getWorkerQueueStatus(): array
    {
        $queue = new FileJobQueue(Bootstrap::rootPath('storage/cache'), $this->logger);
        $summary = $queue->summary();
        $counts = is_array($summary['counts'] ?? null) ? $summary['counts'] : [];
        $queued = (int) ($counts['queued'] ?? 0) + (int) ($counts['failed_retryable'] ?? 0);
        $running = (int) ($counts['running'] ?? 0);
        $lastExecuted = $this->latestFileQueueJob(['completed', 'failed_terminal', 'failed_retryable', 'running']);
        $deadLetterCounts = $this->deadLetterCounts();
        $heartbeat = $this->workerHeartbeatSummary();

        if ($queued > 0 && $running === 0) {
            return [
                'label' => 'Worker/Fila',
                'status' => (($heartbeat['status'] ?? '') === 'critical') ? 'danger' : 'warning',
                'state' => 'queued_without_worker',
                'message' => 'Jobs agendados, mas worker nao processou.',
                'recommended_action' => 'Execute php cron/worker.php --queue=high,default,low --limit=50 --max-runtime=55.',
                'queued' => $queued,
                'running' => $running,
                'dead_letters' => $deadLetterCounts,
                'heartbeat' => $heartbeat,
                'counts' => $counts,
                'last_executed_job' => $lastExecuted,
            ];
        }

        if ($running > 0) {
            return [
                'label' => 'Worker/Fila',
                'status' => 'ok',
                'state' => 'running',
                'message' => "{$running} job(s) em processamento.",
                'recommended_action' => null,
                'queued' => $queued,
                'running' => $running,
                'dead_letters' => $deadLetterCounts,
                'heartbeat' => $heartbeat,
                'counts' => $counts,
                'last_executed_job' => $lastExecuted,
            ];
        }

        $lastAt = is_array($lastExecuted) ? (string) ($lastExecuted['finished_at'] ?? $lastExecuted['updated_at'] ?? '') : '';
        $status = $lastAt !== '' && $this->ageHours($lastAt) <= self::FRESH_OK_HOURS ? 'ok' : 'pending';
        if ((int) ($deadLetterCounts['unresolved'] ?? 0) > 0) {
            $status = 'warning';
        }

        return [
            'label' => 'Worker/Fila',
            'status' => $status,
            'state' => $lastAt !== '' ? 'idle_with_history' : 'idle_no_history',
            'message' => $lastAt !== ''
                ? 'Fila sem pendencias. Ultimo job registrado em ' . $lastAt . '.'
                : 'Fila sem pendencias, mas nenhum worker recente foi observado.',
            'recommended_action' => $lastAt !== '' ? null : 'Agende o worker via cron para processar jobs em background.',
            'queued' => $queued,
            'running' => $running,
            'dead_letters' => $deadLetterCounts,
            'heartbeat' => $heartbeat,
            'counts' => $counts,
            'last_executed_job' => $lastExecuted,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function getWebhookStatus(): array
    {
        if ($this->syncPipeline === null) {
            return [
                'label' => 'Webhook inbox',
                'status' => 'unknown',
                'message' => 'Pipeline de webhook indisponivel.',
                'total_events' => null,
            ];
        }

        try {
            $summary = $this->syncPipeline->summarizeOperationalState(1);
            $webhook = is_array($summary['webhook_inbox'] ?? null) ? $summary['webhook_inbox'] : [];
            $counts = is_array($webhook['counts'] ?? null) ? $webhook['counts'] : [];
            $total = array_sum(array_map('intval', $counts));
            $failed = (int) ($counts['falhou_temporariamente'] ?? 0) + (int) ($counts['falhou_definitivamente'] ?? 0);
            $pending = (int) ($webhook['pending_total'] ?? 0);

            return [
                'label' => 'Webhook inbox',
                'status' => $failed > 0 ? 'warning' : 'ok',
                'message' => $total === 0
                    ? 'Nenhum webhook recebido. Use cron/polling ou configure webhook Omie.'
                    : sprintf('%d evento(s), %d pendente(s), %d falha(s).', $total, $pending, $failed),
                'total_events' => $total,
                'last_event_received_at' => $this->lastWebhookReceivedAt(),
                'processed_events' => (int) ($counts['processado'] ?? 0),
                'duplicate_events' => (int) ($counts['ignorado_por_deduplicacao'] ?? 0),
                'failed_events' => $failed,
                'pending_events' => $pending,
                'counts' => $counts,
            ];
        } catch (Throwable $exception) {
            return [
                'label' => 'Webhook inbox',
                'status' => 'unknown',
                'message' => SecretMask::sanitizeErrorMessage($exception->getMessage(), 160),
                'total_events' => null,
            ];
        }
    }

    /**
     * @return list<array{label:string,command:string,description:string}>
     */
    private function getAutomationCommands(): array
    {
        return [
            [
                'label' => 'Sync comercial',
                'command' => 'php cron/sync_realtime_pipeline.php --domains=commercial',
                'description' => 'Atualiza pedidos, NFs e eventos comerciais a partir da Omie.',
            ],
            [
                'label' => 'Sync financeiro',
                'command' => 'php cron/sync_realtime_pipeline.php --domains=finance',
                'description' => 'Atualiza titulos financeiros canonicos.',
            ],
            [
                'label' => 'Worker',
                'command' => 'php cron/worker.php --queue=high,default,low --limit=50 --max-runtime=55',
                'description' => 'Processa jobs agendados pelos botoes da interface.',
            ],
            [
                'label' => 'Materializar Gold',
                'command' => 'php bin/materialize_marts.php',
                'description' => 'Recalcula marts com os dados locais disponiveis.',
            ],
            [
                'label' => 'Rebuild Geo periodo',
                'command' => 'php cron/geo_rebuild_period.php --start=YYYY-MM-01 --end=YYYY-MM-DD --assert --allow-open-month --json',
                'description' => 'Reconstroi marts geograficos do periodo.',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function getRateLimitStatus(): array
    {
        $registry = new OmieMethodPolicyRegistry($this->config);
        $policies = $registry->all();
        $summary = OmieRateLimiter::summarizePolicies($policies);
        $domains = [];
        foreach ($summary as $row) {
            $domain = (string) ($row['domain'] ?? 'generic');
            $domains[$domain] ??= [
                'methods' => 0,
                'min_page_size' => 100,
                'max_page_size' => 1,
                'cache_enabled_methods' => 0,
                'max_concurrency' => 0,
            ];
            $domains[$domain]['methods']++;
            $domains[$domain]['min_page_size'] = min((int) $domains[$domain]['min_page_size'], (int) ($row['page_size_target'] ?? 100));
            $domains[$domain]['max_page_size'] = max((int) $domains[$domain]['max_page_size'], (int) ($row['page_size_target'] ?? 1));
            $domains[$domain]['max_concurrency'] = max((int) $domains[$domain]['max_concurrency'], (int) ($row['max_concurrency'] ?? 1));
            if ((int) ($row['cache_ttl_seconds'] ?? 0) > 0) {
                $domains[$domain]['cache_enabled_methods']++;
            }
        }

        ksort($domains);

        return [
            'limits' => [
                'global_per_ip_per_minute' => 960,
                'per_app_method_per_minute' => 240,
                'max_concurrent_per_app_method' => 4,
                'redundant_lookup_cache_seconds' => 60,
            ],
            'domains' => $domains,
            'methods' => array_slice($summary, 0, 24),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function getSyncDomainOverview(): array
    {
        $definitions = [
            'commercial' => ['label' => 'Comercial', 'cursor' => 'commercial', 'cron' => 'cron/sync_realtime_pipeline.php --domains=commercial'],
            'fiscal' => ['label' => 'Fiscal/NF', 'cursor' => 'commercial', 'cron' => 'cron/sync_canonical.php'],
            'finance' => ['label' => 'Financeiro', 'cursor' => 'finance', 'cron' => 'cron/sync_realtime_pipeline.php --domains=finance'],
            'clients' => ['label' => 'Clientes', 'cursor' => 'commercial', 'cron' => 'cron/sync_realtime_pipeline.php --domains=commercial'],
            'geo' => ['label' => 'Geo', 'cursor' => 'geo', 'cron' => 'cron/geo_rebuild_period.php'],
        ];

        $rows = [];
        foreach ($definitions as $domain => $definition) {
            $cursor = null;
            if ($this->syncPipeline !== null) {
                try {
                    $cursor = $this->syncPipeline->getCursor((string) $definition['cursor'], (string) $definition['cursor']);
                } catch (Throwable) {
                    $cursor = null;
                }
            }

            $lastSuccess = is_array($cursor) ? ($cursor['last_success_at'] ?? null) : null;
            $ageMinutes = $lastSuccess ? max(0, (int) floor((time() - strtotime((string) $lastSuccess)) / 60)) : null;
            $status = 'pending';
            if (is_array($cursor)) {
                $rawStatus = (string) ($cursor['status'] ?? 'idle');
                $status = match (true) {
                    in_array($rawStatus, ['failed_retryable', 'failed_terminal'], true) => 'danger',
                    $rawStatus === 'running' => 'pending',
                    $ageMinutes !== null && $ageMinutes <= self::FRESH_OK_HOURS * 60 => 'ok',
                    $ageMinutes !== null && $ageMinutes <= self::FRESH_WARNING_HOURS * 60 => 'warning',
                    default => 'danger',
                };
            }

            if ($domain === 'geo') {
                $lastGeo = $this->tableHealth->tableExists('mart_compradores_por_geo')
                    ? $this->tableHealth->lastUpdatedAt('mart_compradores_por_geo', ['refreshed_at', 'updated_at'])
                    : null;
                $lastSuccess = $lastGeo ?: $lastSuccess;
                $ageMinutes = $lastSuccess ? max(0, (int) floor((time() - strtotime((string) $lastSuccess)) / 60)) : null;
                $status = $lastGeo !== null ? ($ageMinutes !== null && $ageMinutes <= self::FRESH_WARNING_HOURS * 60 ? 'ok' : 'warning') : 'pending';
            }

            $rows[] = [
                'domain' => $domain,
                'label' => $definition['label'],
                'status' => $status,
                'last_success_at' => $lastSuccess,
                'age_minutes' => $ageMinutes,
                'next_delta_at' => is_array($cursor) ? ($cursor['next_delta_at'] ?? null) : null,
                'recommended_command' => $definition['cron'],
                'message' => is_array($cursor)
                    ? ($status === 'ok' ? 'Atualizado nas ultimas 24h.' : ($status === 'warning' ? 'Atualizado entre 24h e 72h.' : 'Atualizacao acima de 72h ou falha registrada.'))
                    : 'Pipeline delta nunca executado para este dominio.',
                'last_error' => is_array($cursor) && !empty($cursor['last_error'])
                    ? SecretMask::sanitizeErrorMessage((string) $cursor['last_error'], 120)
                    : null,
            ];
        }

        return $rows;
    }

    /**
     * @param list<array<string,mixed>> $cursors
     * @return list<array<string,mixed>>
     */
    private function publicCursors(array $cursors): array
    {
        $result = [];
        foreach ($cursors as $cursor) {
            if (!is_array($cursor)) {
                continue;
            }

            $lastSuccess = (string) ($cursor['last_success_at'] ?? '');
            $result[] = [
                'domain' => (string) ($cursor['domain_name'] ?? ''),
                'endpoint_group' => (string) ($cursor['endpoint_group'] ?? ''),
                'status' => (string) ($cursor['status'] ?? 'unknown'),
                'last_success_at' => $lastSuccess !== '' ? $lastSuccess : null,
                'next_delta_at' => ($cursor['next_delta_at'] ?? null) ?: null,
                'age_minutes' => $lastSuccess !== '' ? max(0, (int) floor((time() - strtotime($lastSuccess)) / 60)) : null,
                'last_error' => !empty($cursor['last_error'])
                    ? SecretMask::sanitizeErrorMessage((string) $cursor['last_error'], 120)
                    : null,
            ];
        }

        return $result;
    }

    /**
     * @param list<array<string,mixed>> $runs
     * @return list<array<string,mixed>>
     */
    private function publicRecentRuns(array $runs): array
    {
        $result = [];
        foreach (array_slice($runs, 0, 10) as $run) {
            if (!is_array($run)) {
                continue;
            }

            $result[] = [
                'domain' => (string) ($run['domain_name'] ?? ''),
                'type' => (string) ($run['run_type'] ?? ''),
                'status' => (string) ($run['status'] ?? ''),
                'started_at' => (string) ($run['started_at'] ?? ''),
                'finished_at' => ($run['finished_at'] ?? null) ?: null,
                'items_processed' => (int) ($run['items_processed'] ?? 0),
                'items_failed' => (int) ($run['items_failed'] ?? 0),
                'error' => !empty($run['error_summary'])
                    ? SecretMask::sanitizeErrorMessage((string) $run['error_summary'], 120)
                    : null,
            ];
        }

        return $result;
    }

    /**
     * @return array<string,mixed>
     */
    private function syncRunsLast24h(): array
    {
        if (!$this->tableHealth->tableExists('biomie_sync_runs')) {
            return [
                'available' => false,
                'runs' => 0,
                'failures' => 0,
                'avg_duration_ms' => null,
                'last_success_at' => null,
                'last_failure_at' => null,
            ];
        }

        try {
            $stmt = $this->pdo->query(<<<'SQL'
                SELECT
                    COUNT(*) AS runs_total,
                    SUM(CASE WHEN status IN ('failed_retryable','failed_terminal') THEN 1 ELSE 0 END) AS failures_total,
                    AVG(CASE
                        WHEN finished_at IS NOT NULL THEN TIMESTAMPDIFF(MICROSECOND, started_at, finished_at) / 1000
                        ELSE NULL
                    END) AS avg_duration_ms,
                    MAX(CASE WHEN status IN ('completed','completed_with_warnings') THEN finished_at ELSE NULL END) AS last_success_at,
                    MAX(CASE WHEN status IN ('failed_retryable','failed_terminal') THEN finished_at ELSE NULL END) AS last_failure_at
                FROM biomie_sync_runs
                WHERE started_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            SQL);
            $row = $stmt !== false ? $stmt->fetch(PDO::FETCH_ASSOC) : [];
        } catch (Throwable) {
            $row = [];
        }

        return [
            'available' => true,
            'runs' => (int) ($row['runs_total'] ?? 0),
            'failures' => (int) ($row['failures_total'] ?? 0),
            'avg_duration_ms' => isset($row['avg_duration_ms']) ? (int) round((float) $row['avg_duration_ms']) : null,
            'last_success_at' => ($row['last_success_at'] ?? null) ?: null,
            'last_failure_at' => ($row['last_failure_at'] ?? null) ?: null,
        ];
    }

    /**
     * Testa conexão Omie com chamada leve.
     * Deve ser chamado apenas via POST explícito.
     *
     * @return array<string,mixed>
     */
    public function testConnection(?int $actorUserId = null): array
    {
        if (!$this->config->hasOmieCredentials()) {
            return [
                'ok' => false,
                'status' => 'danger',
                'error' => 'credentials_missing',
                'message' => 'Credenciais Omie nao configuradas.',
                'recommended_action' => 'Configure APP_KEY e APP_SECRET no ambiente.',
            ];
        }

        $startMs = microtime(true);
        $endpoint = (string) $this->config->get('omie.endpoints.clients', 'https://app.omie.com.br/api/v1/geral/clientes/');
        $method = 'ListarClientesResumido';

        try {
            $cache = new Cache(Bootstrap::rootPath('storage/cache'));
            $client = new OmieClient(
                (string) $this->config->get('omie.app_key'),
                (string) $this->config->get('omie.app_secret'),
                10, // timeout curto
                $this->logger,
                new OmieMethodPolicyRegistry($this->config),
                $cache,
            );

            $client->post($endpoint, $method, [
                'pagina' => 1,
                'registros_por_pagina' => 1,
            ]);

            $latencyMs = (int) round((microtime(true) - $startMs) * 1000);

            $this->recordTestResult('ok', $endpoint, $method, $latencyMs, 'Conexao validada com sucesso.', null, $actorUserId);

            return [
                'ok' => true,
                'status' => 'ok',
                'message' => 'Conexao Omie validada com sucesso.',
                'latency_ms' => $latencyMs,
                'tested_endpoint' => $endpoint,
                'tested_method' => $method,
                'tested_at' => date('Y-m-d H:i:s'),
            ];
        } catch (Throwable $e) {
            $latencyMs = (int) round((microtime(true) - $startMs) * 1000);
            $sanitizedError = SecretMask::sanitizeErrorMessage($e->getMessage(), 300);

            $this->recordTestResult('danger', $endpoint, $method, $latencyMs, $sanitizedError, $sanitizedError, $actorUserId);

            return [
                'ok' => false,
                'status' => 'danger',
                'error' => 'omie_connection_failed',
                'message' => 'Nao foi possivel validar a conexao com a Omie.',
                'detail' => $sanitizedError,
                'latency_ms' => $latencyMs,
                'recommended_action' => 'Verifique credenciais, internet do servidor e permissoes do aplicativo.',
            ];
        }
    }

    // ── Private ──────────────────────────────────────────────────────────────

    private function countRows(string $table): ?int
    {
        if (!$this->tableHealth->tableExists($table)) {
            return null;
        }

        return $this->tableHealth->safeCount($table, true);
    }

    private function latestSyncSuccessAt(): ?string
    {
        if (!$this->tableHealth->tableExists('biomie_sync_runs')) {
            return null;
        }

        try {
            $stmt = $this->pdo->query(
                "SELECT MAX(finished_at) FROM biomie_sync_runs
                 WHERE status IN ('completed','completed_with_warnings')"
            );
            $value = $stmt !== false ? $stmt->fetchColumn() : false;
            return $value !== false && $value !== null && (string) $value !== '' ? (string) $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function latestSyncSuccessAtForDomain(string $domain): ?string
    {
        if (!$this->tableHealth->tableExists('biomie_sync_runs')) {
            return null;
        }

        try {
            $stmt = $this->pdo->prepare(
                "SELECT MAX(finished_at) FROM biomie_sync_runs
                 WHERE status IN ('completed','completed_with_warnings')
                   AND domain_name = :domain"
            );
            $stmt->execute(['domain' => $domain]);
            $value = $stmt->fetchColumn();
            return $value !== false && $value !== null && (string) $value !== '' ? (string) $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function recordsProcessedLast24h(): int
    {
        if (!$this->tableHealth->tableExists('biomie_sync_runs')) {
            return 0;
        }

        try {
            $stmt = $this->pdo->query(
                "SELECT COALESCE(SUM(items_processed), 0) FROM biomie_sync_runs
                 WHERE started_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
            );
            $value = $stmt !== false ? $stmt->fetchColumn() : false;
            return $value !== false && $value !== null ? (int) $value : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    private function lastWebhookReceivedAt(): ?string
    {
        if (!$this->tableHealth->tableExists('biomie_webhook_inbox')) {
            return null;
        }
        try {
            $stmt = $this->pdo->query('SELECT MAX(received_at) FROM biomie_webhook_inbox');
            $value = $stmt !== false ? $stmt->fetchColumn() : false;
            return $value !== false && $value !== null && (string) $value !== '' ? (string) $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function latestSyncRun(): ?array
    {
        if (!$this->tableHealth->tableExists('biomie_sync_runs')) {
            return null;
        }

        try {
            $stmt = $this->pdo->query(
                "SELECT id, run_type, domain_name, endpoint_group, status, items_processed, items_failed, started_at, finished_at, error_summary
                 FROM biomie_sync_runs
                 ORDER BY started_at DESC, id DESC
                 LIMIT 1"
            );
            $row = $stmt !== false ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
            if (!is_array($row)) {
                return null;
            }
            if (isset($row['error_summary'])) {
                $row['error_summary'] = SecretMask::sanitizeErrorMessage((string) $row['error_summary'], 160);
            }
            return $row;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param list<string> $statuses
     * @return array<string,mixed>|null
     */
    private function latestFileQueueJob(array $statuses): ?array
    {
        $jobsDir = Bootstrap::rootPath('storage/cache/jobs');
        if (!is_dir($jobsDir)) {
            return null;
        }

        $latest = null;
        $latestTs = 0;
        foreach ($statuses as $status) {
            foreach (glob($jobsDir . '/' . $status . '_job_*.json') ?: [] as $path) {
                $raw = @file_get_contents($path);
                $row = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
                if (!is_array($row)) {
                    continue;
                }
                $date = (string) ($row['finished_at'] ?? $row['failed_at'] ?? $row['started_at'] ?? $row['updated_at'] ?? $row['created_at'] ?? '');
                $ts = $date !== '' ? (strtotime($date) ?: 0) : 0;
                if ($ts < $latestTs) {
                    continue;
                }
                $latestTs = $ts;
                $latest = [
                    'job_id' => (string) ($row['job_id'] ?? ''),
                    'type' => (string) ($row['type'] ?? $row['job_type'] ?? ''),
                    'queue' => (string) ($row['queue'] ?? ''),
                    'status' => (string) ($row['status'] ?? $status),
                    'created_at' => $row['created_at'] ?? null,
                    'updated_at' => $row['updated_at'] ?? null,
                    'started_at' => $row['started_at'] ?? null,
                    'finished_at' => $row['finished_at'] ?? ($row['failed_at'] ?? null),
                    'error_summary' => !empty($row['error_summary'])
                        ? SecretMask::sanitizeErrorMessage((string) $row['error_summary'], 160)
                        : null,
                ];
            }
        }

        return $latest;
    }

    private function ageHours(string $dateTime): ?float
    {
        $ts = strtotime($dateTime);
        if ($ts === false) {
            return null;
        }

        return max(0.0, (time() - $ts) / 3600);
    }

    /**
     * @return array<string,int>
     */
    private function deadLetterCounts(): array
    {
        try {
            return (new DeadLetterRepository($this->pdo))->counts();
        } catch (Throwable) {
            return ['total' => 0, 'unresolved' => 0, 'retryable' => 0, 'last_24h' => 0];
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function workerHeartbeatSummary(): array
    {
        try {
            return (new WorkerHeartbeatRepository($this->pdo))->summary();
        } catch (Throwable) {
            return [
                'status' => 'unknown',
                'message' => 'Heartbeat do worker indisponivel.',
                'latest' => null,
                'age_seconds' => null,
            ];
        }
    }

    private function freshnessStatus(?string $dateTime): string
    {
        if ($dateTime === null || $dateTime === '') {
            return 'pending';
        }

        $ageHours = $this->ageHours($dateTime);
        if ($ageHours === null) {
            return 'unknown';
        }

        if ($ageHours <= self::FRESH_OK_HOURS) {
            return 'ok';
        }
        if ($ageHours <= self::FRESH_WARNING_HOURS) {
            return 'warning';
        }

        return 'danger';
    }

    /**
     * @param list<string> $statuses
     */
    private function worstStatus(array $statuses): string
    {
        $rank = ['unknown' => 0, 'ok' => 1, 'pending' => 2, 'warning' => 3, 'danger' => 4];
        $worst = 'unknown';
        foreach ($statuses as $status) {
            if (($rank[$status] ?? 0) > ($rank[$worst] ?? 0)) {
                $worst = $status;
            }
        }

        return $worst;
    }

    private function lastTestResult(): string
    {
        $row = $this->getLastTestRow();
        if ($row === null) {
            return 'not_tested';
        }

        return (string) ($row['status'] ?? 'unknown') === 'ok' ? 'valid' : 'failed';
    }

    /**
     * @return array<string,mixed>|null
     */
    private function getLastTestRow(): ?array
    {
        if (!$this->tableHealth->tableExists('biomie_omie_status_checks')) {
            return null;
        }

        try {
            $stmt = $this->pdo->prepare(
                "SELECT * FROM biomie_omie_status_checks WHERE check_type='connection_test' ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function recordTestResult(
        string $status,
        string $endpoint,
        string $method,
        int $latencyMs,
        string $message,
        ?string $errorMessage,
        ?int $actorUserId,
    ): void {
        if (!$this->tableHealth->tableExists('biomie_omie_status_checks')) {
            return;
        }

        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO biomie_omie_status_checks
                 (company_id, check_type, status, endpoint, method, latency_ms, message, error_code, error_message_sanitized, actor_user_id, created_at)
                 VALUES (NULL, 'connection_test', :status, :endpoint, :method, :latency_ms, :message, :error_code, :error_msg, :actor, NOW())"
            );
            $stmt->execute([
                'status' => $status,
                'endpoint' => $endpoint,
                'method' => $method,
                'latency_ms' => $latencyMs,
                'message' => $message,
                'error_code' => $errorMessage !== null ? 'omie_connection_failed' : null,
                'error_msg' => $errorMessage,
                'actor' => $actorUserId,
            ]);
        } catch (Throwable $e) {
            $this->logger->warning('OmieStatusService: falha ao registrar teste.', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function domainDataStatus(string $table, string $label): array
    {
        if (!$this->tableHealth->tableExists($table)) {
            return ['label' => $label, 'count' => null, 'last_sync' => null, 'status' => 'danger', 'empty_class' => 'empty_error', 'message' => 'Tabela ausente.'];
        }

        $count = $this->tableHealth->safeCount($table);
        $lastSync = $this->tableHealth->lastUpdatedAt($table);

        if ($count === 0 || $count === null) {
            $message = $table === 'biomie_titulos_financeiros_canonicos'
                ? 'Financeiro ainda nao sincronizado.'
                : 'Aguardando sincronizacao.';
            return ['label' => $label, 'count' => $count, 'last_sync' => $lastSync, 'status' => 'warning', 'empty_class' => 'empty_not_synced', 'message' => $message];
        }

        return ['label' => $label, 'count' => $count, 'last_sync' => $lastSync, 'status' => 'ok', 'empty_class' => null, 'message' => number_format($count, 0, ',', '.') . ' registros.'];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function latestGoldMartJob(): ?array
    {
        if (!$this->tableHealth->tableExists('biomie_pipeline_jobs')) {
            return null;
        }

        try {
            $stmt = $this->pdo->prepare(
                "SELECT job_name, status, rows_processed, counts_json, completed_at, duration_ms, error_message
                 FROM biomie_pipeline_jobs
                 WHERE job_name = 'gold_mart_refresh'
                 ORDER BY id DESC
                 LIMIT 1"
            );
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                return null;
            }
            if (isset($row['error_message'])) {
                $row['error_message'] = SecretMask::sanitizeErrorMessage((string) $row['error_message'], 200);
            }
            return $row;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function latestMaterializationEvent(): ?array
    {
        if (!$this->tableHealth->tableExists('biomie_operational_events')) {
            return null;
        }

        try {
            $stmt = $this->pdo->prepare(
                "SELECT event_name, summary, context_json, occurred_at
                 FROM biomie_operational_events
                 WHERE event_name IN ('gold.materialize.finished','gold.materialize.finished_zero','gold.materialize.failed')
                 ORDER BY id DESC
                 LIMIT 1"
            );
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                return null;
            }

            $context = [];
            if (is_string($row['context_json'] ?? null) && (string) $row['context_json'] !== '') {
                $decoded = json_decode((string) $row['context_json'], true);
                $context = is_array($decoded) ? SecretMask::redactArray($decoded) : [];
            }

            return [
                'event_name' => (string) ($row['event_name'] ?? ''),
                'summary' => (string) ($row['summary'] ?? ''),
                'occurred_at' => (string) ($row['occurred_at'] ?? ''),
                'context' => $context,
            ];
        } catch (Throwable) {
            return null;
        }
    }
}

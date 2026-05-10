<?php

declare(strict_types=1);

namespace BIomie;

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
            'data' => $this->getDataStatus(),
            'tables' => $this->getTableStatus(),
            'gold' => $this->getGoldStatus(),
            'jobs' => $this->getJobStatus(),
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
        if ($lastRawStatus === 'failed') {
            $status = 'danger';
        } elseif ($lastRawStatus === 'running') {
            $status = 'pending';
        } elseif ($lastCommercial !== null && is_array($lastJob) && (int) ($lastJob['rows_processed'] ?? 0) === 0) {
            $status = 'warning';
        } elseif ($lastCommercial !== null) {
            $ageHours = (time() - strtotime($lastCommercial)) / 3600;
            $status = $ageHours <= 24 ? 'ok' : ($ageHours <= 72 ? 'warning' : 'danger');
        }

        return [
            'last_materialization_at' => $lastCommercial,
            'last_materialization_rows' => is_array($lastJob) ? (int) ($lastJob['rows_processed'] ?? 0) : null,
            'geo_ready' => $geoReady,
            'cnae_ready' => $cnaeReady,
            'status' => $status,
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
            'latest_materialization' => $this->latestMaterializationEvent(),
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
            return ['label' => $label, 'count' => null, 'last_sync' => null, 'status' => 'danger', 'message' => 'Tabela ausente.'];
        }

        $count = $this->tableHealth->safeCount($table);
        $lastSync = $this->tableHealth->lastUpdatedAt($table);

        if ($count === 0 || $count === null) {
            return ['label' => $label, 'count' => $count, 'last_sync' => $lastSync, 'status' => 'warning', 'message' => 'Aguardando sincronizacao.'];
        }

        return ['label' => $label, 'count' => $count, 'last_sync' => $lastSync, 'status' => 'ok', 'message' => number_format($count, 0, ',', '.') . ' registros.'];
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

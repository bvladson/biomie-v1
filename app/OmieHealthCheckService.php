<?php

declare(strict_types=1);

namespace BIomie;

use BIomie\Migrations\MigrationRunner;
use BIomie\Support\SecretMask;
use PDO;
use Throwable;

/**
 * Executa health checks de cada componente da integração Omie.
 *
 * Cada check retorna: key, label, status (ok|warning|danger|pending|unknown),
 * message, severity, updated_at, recommended_action, link.
 *
 * Checks são rápidos e usam apenas dados locais.
 * A chamada real à API Omie só ocorre via testConnection() explícito.
 */
final class OmieHealthCheckService
{
    /** Threshold: sync OK se < 24h */
    private const SYNC_OK_HOURS = 24;
    /** Threshold: sync warning se < 72h */
    private const SYNC_WARNING_HOURS = 72;

    /** @var array<string,array{label:string,table:string}> */
    private const TABLE_CATALOG = [
        // Geo
        'bairros_dim' => ['label' => 'Bairros Fortaleza dim', 'table' => 'biomie_bairros_fortaleza_dim', 'group' => 'canonical'],
        // Bronze
        'bronze_payloads' => ['label' => 'Bronze API payloads', 'table' => 'biomie_omie_bronze', 'group' => 'bronze'],
        // Canonical/Silver
        'canonical_pedidos' => ['label' => 'Pedidos canônicos', 'table' => 'biomie_pedidos_canonicos', 'group' => 'canonical'],
        'canonical_nf' => ['label' => 'NF canônicas', 'table' => 'biomie_notas_fiscais_canonicas', 'group' => 'canonical'],
        'canonical_itens' => ['label' => 'Itens NF canônicos', 'table' => 'biomie_notas_fiscais_itens_canonicos', 'group' => 'canonical'],
        'canonical_titulos' => ['label' => 'Títulos financeiros', 'table' => 'biomie_titulos_financeiros_canonicos', 'group' => 'canonical'],
        'canonical_clientes' => ['label' => 'Clientes dimensão', 'table' => 'biomie_clientes_dim', 'group' => 'canonical'],
        'canonical_clientes_geo' => ['label' => 'Clientes geo', 'table' => 'biomie_clientes_geo_dim', 'group' => 'canonical'],
        // Gold
        'gold_bridge' => ['label' => 'Bridge pedido-nfe-título', 'table' => 'mart_bridge_pedido_nfe_titulo', 'group' => 'gold'],
        'gold_comercial' => ['label' => 'Mart comercial', 'table' => 'mart_comercial_pedido', 'group' => 'gold'],
        'gold_fiscal' => ['label' => 'Mart fiscal', 'table' => 'mart_fiscal_nfe', 'group' => 'gold'],
        'gold_financeiro' => ['label' => 'Mart financeiro', 'table' => 'mart_financeiro_titulo', 'group' => 'gold'],
        'gold_vendedor_dia' => ['label' => 'Mart vendedor/dia', 'table' => 'mart_vendedor_dia', 'group' => 'gold'],
        'gold_cliente_periodo' => ['label' => 'Mart cliente/período', 'table' => 'mart_cliente_periodo', 'group' => 'gold'],
        'gold_produto_periodo' => ['label' => 'Mart produto/período', 'table' => 'mart_produto_periodo', 'group' => 'gold'],
        'gold_compradores_geo' => ['label' => 'Compradores por geo', 'table' => 'mart_compradores_por_geo', 'group' => 'gold'],
        'gold_vendas_cnae' => ['label' => 'Vendas por CNAE', 'table' => 'mart_vendas_por_cnae_cnpj', 'group' => 'gold'],
        // Jobs/Sync
        'sync_cursors' => ['label' => 'Cursores sync', 'table' => 'biomie_sync_cursors', 'group' => 'jobs'],
        'sync_runs' => ['label' => 'Histórico sync runs', 'table' => 'biomie_sync_runs', 'group' => 'jobs'],
        'webhook_inbox' => ['label' => 'Webhook inbox', 'table' => 'biomie_webhook_inbox', 'group' => 'jobs'],
        'pipeline_jobs' => ['label' => 'Pipeline jobs', 'table' => 'biomie_pipeline_jobs', 'group' => 'jobs'],
        'status_checks' => ['label' => 'Status checks', 'table' => 'biomie_omie_status_checks', 'group' => 'jobs'],
        'migrations' => ['label' => 'Schema migrations', 'table' => 'biomie_schema_migrations', 'group' => 'jobs'],
    ];

    public function __construct(
        private readonly Config $config,
        private readonly PDO $pdo,
        private readonly Logger $logger,
        private readonly TableHealthService $tableHealth,
        private readonly ?PipelineStatusRepository $pipelineStatus = null,
        private readonly ?SyncPipelineRepository $syncPipeline = null,
    ) {}

    /**
     * Executa todos os checks e retorna array de resultados.
     *
     * @return list<array{key:string,label:string,status:string,message:string,severity:string,updated_at:?string,recommended_action:?string,link:?string}>
     */
    public function runAll(): array
    {
        $checks = [];

        try { $checks[] = $this->checkCredentials(); } catch (Throwable $e) { $checks[] = $this->errorCheck('credentials_configured', 'Credenciais Omie', $e); }
        try { $checks[] = $this->checkApiLastTest(); } catch (Throwable $e) { $checks[] = $this->errorCheck('api_last_test', 'API Omie', $e); }
        try { $checks[] = $this->checkCommercialSync(); } catch (Throwable $e) { $checks[] = $this->errorCheck('commercial_sync', 'Sync comercial', $e); }
        try { $checks[] = $this->checkFinanceSync(); } catch (Throwable $e) { $checks[] = $this->errorCheck('finance_sync', 'Sync financeiro', $e); }
        try { $checks[] = $this->checkClientsSync(); } catch (Throwable $e) { $checks[] = $this->errorCheck('clients_sync', 'Clientes', $e); }
        try { $checks[] = $this->checkClientsDimReadiness(); } catch (Throwable $e) { $checks[] = $this->errorCheck('clients_dim_readiness', 'Prontidao clientes dim', $e); }
        try { $checks[] = $this->checkBairrosDimReadiness(); } catch (Throwable $e) { $checks[] = $this->errorCheck('bairros_dim_readiness', 'Bairros Fortaleza dim', $e); }
        try { $checks[] = $this->checkInvoicesSync(); } catch (Throwable $e) { $checks[] = $this->errorCheck('invoices_sync', 'NF sincronizadas', $e); }
        try { $checks[] = $this->checkReceivablesSync(); } catch (Throwable $e) { $checks[] = $this->errorCheck('receivables_sync', 'Títulos financeiros', $e); }
        try { $checks[] = $this->checkBronzeTables(); } catch (Throwable $e) { $checks[] = $this->errorCheck('bronze_tables', 'Tabelas bronze', $e); }
        try { $checks[] = $this->checkCanonicalTables(); } catch (Throwable $e) { $checks[] = $this->errorCheck('canonical_tables', 'Tabelas canônicas', $e); }
        try { $checks[] = $this->checkGoldTables(); } catch (Throwable $e) { $checks[] = $this->errorCheck('gold_tables', 'Tabelas gold', $e); }
        try { $checks[] = $this->checkGeoGold(); } catch (Throwable $e) { $checks[] = $this->errorCheck('geo_gold', 'Gold geográfico', $e); }
        try { $checks[] = $this->checkCnaeGold(); } catch (Throwable $e) { $checks[] = $this->errorCheck('cnae_gold', 'Gold CNAE', $e); }
        try { $checks[] = $this->checkJobsQueue(); } catch (Throwable $e) { $checks[] = $this->errorCheck('jobs_queue', 'Fila de jobs', $e); }
        try { $checks[] = $this->checkFailedJobs(); } catch (Throwable $e) { $checks[] = $this->errorCheck('failed_jobs', 'Jobs em falha', $e); }
        try { $checks[] = $this->checkMigrations(); } catch (Throwable $e) { $checks[] = $this->errorCheck('migrations', 'Migrations', $e); }
        try { $checks[] = $this->checkMaterialization(); } catch (Throwable $e) { $checks[] = $this->errorCheck('materialization', 'Materialização', $e); }
        try { $checks[] = $this->checkLastError(); } catch (Throwable $e) { $checks[] = $this->errorCheck('last_error', 'Último erro', $e); }

        return $checks;
    }

    /**
     * Retorna catálogo de tabelas com status.
     *
     * @return array<string,list<array{name:string,label:string,exists:bool,rows:?int,last_updated:?string,status:string,message:string}>>
     */
    public function tableCatalog(): array
    {
        $groups = ['bronze' => [], 'canonical' => [], 'gold' => [], 'jobs' => []];

        foreach (self::TABLE_CATALOG as $def) {
            $status = $this->tableHealth->tableStatus($def['table'], $def['label']);
            $groups[$def['group']][] = $status;
        }

        return $groups;
    }

    // ── Individual Checks ────────────────────────────────────────────────────

    private function checkCredentials(): array
    {
        $keyConfigured = SecretMask::isConfigured((string) $this->config->get('omie.app_key', ''));
        $secretConfigured = SecretMask::isConfigured((string) $this->config->get('omie.app_secret', ''));
        $both = $keyConfigured && $secretConfigured;

        if (!$both) {
            return $this->check('credentials_configured', 'Credenciais Omie', 'danger',
                'Credenciais Omie nao configuradas.',
                'Configure APP_KEY e APP_SECRET no ambiente.',
                'admin.php?tab=diagnostico');
        }

        return $this->check('credentials_configured', 'Credenciais Omie', 'ok',
            'Credenciais configuradas.');
    }

    private function checkApiLastTest(): array
    {
        if (!$this->tableHealth->tableExists('biomie_omie_status_checks')) {
            return $this->check('api_last_test', 'API Omie', 'pending',
                'Aguardando primeiro teste de conexao.',
                'Clique em Testar conexao Omie.');
        }

        try {
            $stmt = $this->pdo->prepare(
                "SELECT status, message, latency_ms, created_at FROM biomie_omie_status_checks
                 WHERE check_type = 'connection_test' ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            $row = null;
        }

        if (!is_array($row)) {
            return $this->check('api_last_test', 'API Omie', 'pending',
                'Aguardando primeiro teste de conexao.',
                'Clique em Testar conexao Omie.');
        }

        $status = (string) ($row['status'] ?? 'unknown');
        $latency = isset($row['latency_ms']) ? ((int) $row['latency_ms']) . 'ms' : '';
        $testedAt = (string) ($row['created_at'] ?? '');

        if ($status === 'ok') {
            return $this->check('api_last_test', 'API Omie', 'ok',
                'Conexao validada com sucesso. ' . ($latency ? "Latencia: {$latency}." : ''),
                null, null, $testedAt);
        }

        return $this->check('api_last_test', 'API Omie', 'danger',
            'Ultima tentativa de conexao falhou.',
            'Verifique credenciais e conectividade.',
            null, $testedAt);
    }

    private function checkCommercialSync(): array
    {
        return $this->checkSyncDomain('commercial', 'commercial_sync', 'Sync comercial');
    }

    private function checkFinanceSync(): array
    {
        return $this->checkSyncDomain('finance', 'finance_sync', 'Sync financeiro');
    }

    private function checkClientsSync(): array
    {
        return $this->checkTableCount('biomie_clientes_dim', 'clients_sync', 'Clientes');
    }

    private function checkClientsDimReadiness(): array
    {
        $dimExists = $this->tableHealth->tableExists('biomie_clientes_dim');

        if (!$dimExists) {
            return $this->check('clients_dim_readiness', 'Prontidao clientes dim', 'danger',
                'biomie_clientes_dim ausente.', 'Aplique migrations.', 'admin.php?tab=migrations');
        }

        $dimCount = $this->tableHealth->safeCount('biomie_clientes_dim') ?? 0;

        if ($dimCount === 0) {
            $martCount = $this->tableHealth->tableExists('mart_cliente_periodo')
                ? ($this->tableHealth->safeCount('mart_cliente_periodo') ?? 0)
                : 0;

            if ($martCount > 0) {
                return $this->check('clients_dim_readiness', 'Prontidao clientes dim', 'warning',
                    'Clientes dim vazio, mas mart_cliente_periodo tem dados.',
                    'Execute clientes:materialize-dim no console administrativo.');
            }

            return $this->check('clients_dim_readiness', 'Prontidao clientes dim', 'pending',
                'Clientes dim aguardando primeira materializacao.',
                'Execute clientes:materialize-dim apos sincronizar pedidos/NF.');
        }

        // Check if geo_dim is populated
        $geoCount = 0;
        if ($this->tableHealth->tableExists('biomie_clientes_geo_dim')) {
            $geoCount = $this->tableHealth->safeCount('biomie_clientes_geo_dim') ?? 0;
        }

        if ($geoCount === 0) {
            return $this->check('clients_dim_readiness', 'Prontidao clientes dim', 'warning',
                number_format($dimCount, 0, ',', '.') . ' clientes dim, geo ainda vazio.',
                'Execute geo:materialize para preparar o mapa geografico.');
        }

        $updatedAt = $this->tableHealth->lastUpdatedAt('biomie_clientes_dim');
        return $this->check('clients_dim_readiness', 'Prontidao clientes dim', 'ok',
            number_format($dimCount, 0, ',', '.') . ' clientes. Geo disponivel.',
            null, null, $updatedAt);
    }

    private function checkBairrosDimReadiness(): array
    {
        if (!$this->tableHealth->tableExists('biomie_bairros_fortaleza_dim')) {
            return $this->check('bairros_dim_readiness', 'Bairros Fortaleza dim', 'warning',
                'biomie_bairros_fortaleza_dim ausente.',
                'Aplique a migration BairrosFortalezaDim.', 'admin.php?tab=migrations');
        }

        $count = $this->tableHealth->safeCount('biomie_bairros_fortaleza_dim') ?? 0;

        if ($count === 0) {
            return $this->check('bairros_dim_readiness', 'Bairros Fortaleza dim', 'warning',
                'biomie_bairros_fortaleza_dim existe, mas esta vazia.',
                'Re-execute a migration para semear os 121 bairros.');
        }

        if ($count < 121) {
            return $this->check('bairros_dim_readiness', 'Bairros Fortaleza dim', 'warning',
                sprintf('%d bairros encontrados, esperado 121.', $count),
                'Re-execute a migration para completar o seed.');
        }

        return $this->check('bairros_dim_readiness', 'Bairros Fortaleza dim', 'ok',
            '121 bairros oficiais de Fortaleza disponíveis.');
    }

    private function checkInvoicesSync(): array
    {
        return $this->checkTableCount('biomie_notas_fiscais_canonicas', 'invoices_sync', 'NF sincronizadas');
    }

    private function checkReceivablesSync(): array
    {
        return $this->checkTableCount('biomie_titulos_financeiros_canonicos', 'receivables_sync', 'Titulos financeiros');
    }

    private function checkBronzeTables(): array
    {
        if (!$this->tableHealth->tableExists('biomie_omie_bronze')) {
            return $this->check('bronze_tables', 'Tabelas bronze', 'danger',
                'Tabela biomie_omie_bronze ausente.',
                'Aplique migrations.', 'admin.php?tab=migrations');
        }

        $count = $this->tableHealth->safeCount('biomie_omie_bronze', true);
        if ($count === null || $count === 0) {
            return $this->check('bronze_tables', 'Tabelas bronze', 'warning',
                'biomie_omie_bronze existe, mas esta vazia.',
                'Execute a sincronizacao Omie.');
        }

        return $this->check('bronze_tables', 'Tabelas bronze', 'ok',
            'biomie_omie_bronze disponivel com ' . number_format($count, 0, ',', '.') . ' registros.',
            null, null, $this->tableHealth->lastUpdatedAt('biomie_omie_bronze', ['ingested_at']));
    }

    private function checkCanonicalTables(): array
    {
        $tables = [
            'biomie_pedidos_canonicos',
            'biomie_notas_fiscais_canonicas',
            'biomie_notas_fiscais_itens_canonicos',
            'biomie_titulos_financeiros_canonicos',
            'biomie_clientes_dim',
        ];
        return $this->checkTableGroupExists($tables, 'canonical_tables', 'Tabelas canonicas');
    }

    private function checkGoldTables(): array
    {
        $tables = [
            'mart_bridge_pedido_nfe_titulo',
            'mart_comercial_pedido',
            'mart_fiscal_nfe',
            'mart_financeiro_titulo',
            'mart_vendedor_dia',
        ];
        return $this->checkTableGroupExists($tables, 'gold_tables', 'Tabelas gold');
    }

    private function checkGeoGold(): array
    {
        $tables = ['biomie_clientes_geo_dim', 'biomie_vendas_geo_cliente_fact'];
        return $this->checkTableGroupExists($tables, 'geo_gold', 'Gold geografico');
    }

    private function checkCnaeGold(): array
    {
        $tables = ['mart_vendas_por_cnae_cnpj', 'mart_compradores_por_geo'];
        return $this->checkTableGroupExists($tables, 'cnae_gold', 'Gold CNAE/Compradores');
    }

    private function checkJobsQueue(): array
    {
        if (!$this->tableHealth->tableExists('biomie_pipeline_jobs')) {
            return $this->check('jobs_queue', 'Fila de jobs', 'warning',
                'Tabela de jobs ausente.', 'Aplique migrations.', 'admin.php?tab=migrations');
        }

        try {
            $stmt = $this->pdo->query(
                "SELECT COUNT(*) FROM biomie_pipeline_jobs WHERE status = 'running'"
            );
            $running = $stmt !== false ? (int) $stmt->fetchColumn() : 0;
        } catch (Throwable) {
            $running = 0;
        }

        $lastRun = $this->pipelineStatus?->lastSuccessfulRun('gold_mart_refresh');
        $message = $running > 0
            ? "{$running} job(s) em execucao."
            : ($lastRun !== null ? "Ultimo job concluido em {$lastRun}." : 'Nenhum job registrado.');

        return $this->check('jobs_queue', 'Fila de jobs',
            $running > 0 ? 'ok' : ($lastRun !== null ? 'ok' : 'pending'),
            $message,
            $lastRun === null ? 'Execute a primeira materializacao.' : null,
            null, $lastRun);
    }

    private function checkFailedJobs(): array
    {
        if (!$this->tableHealth->tableExists('biomie_pipeline_jobs')) {
            return $this->check('failed_jobs', 'Jobs em falha', 'unknown', 'Tabela de jobs ausente.');
        }

        try {
            $stmt = $this->pdo->query(
                "SELECT COUNT(*) FROM biomie_pipeline_jobs WHERE status = 'failed'"
            );
            $failed = $stmt !== false ? (int) $stmt->fetchColumn() : 0;
        } catch (Throwable) {
            $failed = 0;
        }

        if ($failed === 0) {
            return $this->check('failed_jobs', 'Jobs em falha', 'ok', 'Nenhum job em falha.');
        }

        return $this->check('failed_jobs', 'Jobs em falha', 'danger',
            "{$failed} job(s) em falha.",
            'Verifique logs dos jobs.', 'admin.php?tab=diagnostico');
    }

    private function checkMigrations(): array
    {
        try {
            $runner = new MigrationRunner($this->config, $this->logger);
            $status = $runner->status();
            $pending = (int) ($status['summary']['pending'] ?? 0);

            if ($pending > 0) {
                return $this->check('migrations', 'Migrations', 'warning',
                    "{$pending} migration(s) pendente(s).",
                    'Abra a aba Migrations.', 'admin.php?tab=migrations');
            }

            return $this->check('migrations', 'Migrations', 'ok',
                'Todas as migrations aplicadas.');
        } catch (Throwable) {
            return $this->check('migrations', 'Migrations', 'unknown',
                'Nao foi possivel verificar migrations.',
                'Verifique a aba Migrations.', 'admin.php?tab=migrations');
        }
    }

    private function checkMaterialization(): array
    {
        $lastJob = $this->latestGoldMartJob();
        $lastRun = is_array($lastJob)
            ? (string) ($lastJob['completed_at'] ?? '')
            : (string) ($this->pipelineStatus?->lastSuccessfulRun('gold_mart_refresh') ?? '');
        $lastRun = $lastRun !== '' ? $lastRun : null;

        if ($lastRun === null) {
            return $this->check('materialization', 'Materializacao', 'pending',
                'Visao gold ainda nao preparada.',
                'Atualize a visao comercial.');
        }

        if (is_array($lastJob) && (string) ($lastJob['status'] ?? '') === 'failed') {
            return $this->check('materialization', 'Materializacao', 'danger',
                'Ultima materializacao falhou.',
                'Verifique o diagnostico completo.', 'admin.php?tab=diagnostico', $lastRun);
        }

        if (is_array($lastJob) && (string) ($lastJob['status'] ?? '') === 'running') {
            return $this->check('materialization', 'Materializacao', 'pending',
                'Materializacao em execucao.',
                null, null, $lastRun);
        }

        if (is_array($lastJob) && (int) ($lastJob['rows_processed'] ?? 0) === 0) {
            return $this->check('materialization', 'Materializacao', 'warning',
                'Ultima materializacao nao gerou registros.',
                'Verifique o diagnostico tecnico.', 'admin.php?tab=diagnostico', $lastRun);
        }

        $ageHours = (time() - strtotime($lastRun)) / 3600;
        if ($ageHours <= self::SYNC_OK_HOURS) {
            return $this->check('materialization', 'Materializacao', 'ok',
                'Visao gold atualizada.', null, null, $lastRun);
        }

        if ($ageHours <= self::SYNC_WARNING_HOURS) {
            return $this->check('materialization', 'Materializacao', 'warning',
                'Visao gold pode estar desatualizada.',
                'Atualize a visao comercial.', null, $lastRun);
        }

        return $this->check('materialization', 'Materializacao', 'danger',
            'Visao gold desatualizada.',
            'Atualize a visao comercial.', null, $lastRun);
    }

    private function checkLastError(): array
    {
        $error = $this->findLastError();

        if ($error === null) {
            return $this->check('last_error', 'Ultimo erro', 'ok',
                'Nenhum erro recente detectado.');
        }

        return $this->check('last_error', 'Ultimo erro', 'danger',
            SecretMask::sanitizeErrorMessage((string) ($error['message'] ?? 'Erro desconhecido.'), 200),
            'Verifique o diagnostico completo.', 'admin.php?tab=diagnostico',
            (string) ($error['date'] ?? ''));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function checkSyncDomain(string $domain, string $key, string $label): array
    {
        if ($this->syncPipeline === null) {
            return $this->check($key, $label, 'unknown', 'Pipeline de sync indisponivel.');
        }

        $cursor = $this->syncPipeline->getCursor($domain, $domain);

        if ($cursor === null) {
            return $this->check($key, $label, 'pending',
                'Aguardando primeira sincronizacao.',
                "Execute a sincronizacao {$label}.");
        }

        $lastSuccess = $cursor['last_success_at'] ?? null;
        $status = (string) ($cursor['status'] ?? 'idle');
        $lastError = $cursor['last_error'] ?? null;

        if ($status === 'running') {
            return $this->check($key, $label, 'ok',
                'Sincronizacao em andamento.', null, null, $lastSuccess);
        }

        if (in_array($status, ['failed_retryable', 'failed_terminal'], true)) {
            $msg = $lastError !== null
                ? SecretMask::sanitizeErrorMessage((string) $lastError, 150)
                : 'Falha na ultima sincronizacao.';
            return $this->check($key, $label, 'danger', $msg,
                "Tente novamente a sincronizacao {$label}.", null, $lastSuccess);
        }

        if ($lastSuccess === null) {
            return $this->check($key, $label, 'pending',
                'Aguardando primeira sincronizacao com sucesso.',
                "Execute a sincronizacao {$label}.");
        }

        $ageHours = (time() - strtotime($lastSuccess)) / 3600;
        if ($ageHours <= self::SYNC_OK_HOURS) {
            return $this->check($key, $label, 'ok',
                'Sincronizacao atualizada.', null, null, $lastSuccess);
        }

        if ($ageHours <= self::SYNC_WARNING_HOURS) {
            return $this->check($key, $label, 'warning',
                'Sincronizacao pode estar atrasada.',
                "Execute a sincronizacao {$label}.", null, $lastSuccess);
        }

        return $this->check($key, $label, 'danger',
            'Sincronizacao atrasada.',
            "Execute a sincronizacao {$label}.", null, $lastSuccess);
    }

    private function checkTableCount(string $table, string $key, string $label): array
    {
        if (!$this->tableHealth->tableExists($table)) {
            return $this->check($key, $label, 'danger',
                'Tabela ausente.', 'Aplique migrations.', 'admin.php?tab=migrations');
        }

        $count = $this->tableHealth->safeCount($table);
        if ($count === null || $count === 0) {
            return $this->check($key, $label, 'warning',
                'Aguardando primeira sincronizacao.',
                "Sincronize dados para popular {$label}.");
        }

        $lastUpdated = $this->tableHealth->lastUpdatedAt($table);
        return $this->check($key, $label, 'ok',
            number_format($count, 0, ',', '.') . ' registros.',
            null, null, $lastUpdated);
    }

    /**
     * @param list<string> $tables
     */
    private function checkTableGroupExists(array $tables, string $key, string $label): array
    {
        $missing = [];
        $total = count($tables);

        foreach ($tables as $table) {
            if (!$this->tableHealth->tableExists($table)) {
                $missing[] = $table;
            }
        }

        if ($missing === []) {
            return $this->check($key, $label, 'ok',
                "Todas as {$total} tabelas disponiveis.");
        }

        $missingCount = count($missing);
        if ($missingCount === $total) {
            return $this->check($key, $label, 'danger',
                'Nenhuma tabela encontrada.',
                'Aplique migrations.', 'admin.php?tab=migrations');
        }

        return $this->check($key, $label, 'warning',
            "{$missingCount} de {$total} tabela(s) ausente(s).",
            'Aplique migrations.', 'admin.php?tab=migrations');
    }

    /**
     * @return array{message:string,date:string,domain:string}|null
     */
    private function findLastError(): ?array
    {
        // Try sync_runs first
        if ($this->tableHealth->tableExists('biomie_sync_runs')) {
            try {
                $stmt = $this->pdo->query(
                    "SELECT error_summary, finished_at, domain_name FROM biomie_sync_runs
                     WHERE status IN ('failed_retryable','failed_terminal') AND error_summary IS NOT NULL
                     ORDER BY id DESC LIMIT 1"
                );
                $row = $stmt !== false ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
                if (is_array($row) && !empty($row['error_summary'])) {
                    return [
                        'message' => (string) $row['error_summary'],
                        'date' => (string) ($row['finished_at'] ?? ''),
                        'domain' => (string) ($row['domain_name'] ?? ''),
                    ];
                }
            } catch (Throwable) {}
        }

        // Try pipeline_jobs
        if ($this->tableHealth->tableExists('biomie_pipeline_jobs')) {
            try {
                $stmt = $this->pdo->query(
                    "SELECT error_message, completed_at, job_name FROM biomie_pipeline_jobs
                     WHERE status = 'failed' AND error_message IS NOT NULL
                     ORDER BY id DESC LIMIT 1"
                );
                $row = $stmt !== false ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
                if (is_array($row) && !empty($row['error_message'])) {
                    return [
                        'message' => (string) $row['error_message'],
                        'date' => (string) ($row['completed_at'] ?? ''),
                        'domain' => (string) ($row['job_name'] ?? ''),
                    ];
                }
            } catch (Throwable) {}
        }

        return null;
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
            return is_array($row) ? $row : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{key:string,label:string,status:string,message:string,severity:string,updated_at:?string,recommended_action:?string,link:?string}
     */
    private function check(
        string $key,
        string $label,
        string $status,
        string $message,
        ?string $recommendedAction = null,
        ?string $link = null,
        ?string $updatedAt = null,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'message' => $message,
            'severity' => $status,
            'updated_at' => $updatedAt,
            'recommended_action' => $recommendedAction,
            'link' => $link,
        ];
    }

    private function errorCheck(string $key, string $label, Throwable $e): array
    {
        $this->logger->warning("OmieHealthCheck: falha no check {$key}.", [
            'message' => $e->getMessage(),
        ]);

        return $this->check($key, $label, 'unknown',
            'Nao foi possivel verificar.',
            'Verifique o diagnostico completo.', 'admin.php?tab=diagnostico');
    }
}

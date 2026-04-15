<?php

declare(strict_types=1);

namespace BIomie;

use BIomie\Canonical\CanonicalCoverageRepository;
use BIomie\Canonical\CanonicalDivergenceRepository;
use BIomie\Canonical\CanonicalReadRepository;
use BIomie\Canonical\CanonicalReconciliationRepository;
use BIomie\Canonical\CanonicalSyncStateRepository;
use BIomie\Canonical\CanonicalWriteRepository;
use BIomie\Canonical\Support\CanonicalHashing;
use BIomie\Canonical\Support\CanonicalJsonCodec;
use Closure;
use PDO;

/**
 * Repositório da camada canônica de vendas.
 *
 * Toda leitura e escrita dos pedidos normalizados passa por aqui.
 * O objetivo é que nenhuma tela consulte o Omie diretamente para exibir dados —
 * tudo vem desta tabela, que é alimentada pelo sync (cron ou manual).
 */
final class CanonicalRepository
{
    private const TABLE_VENDORS = 'biomie_vendedores_omie';
    private const TABLE_ORDERS = 'biomie_pedidos_canonicos';
    private const TABLE_INVOICES = 'biomie_notas_fiscais_canonicas';
    private const TABLE_INVOICE_ITEMS = 'biomie_notas_fiscais_itens_canonicos';
    private const TABLE_XML_DOCUMENTS = 'biomie_documentos_xml_canonicos';
    private const TABLE_FINANCIAL_TITLES = 'biomie_titulos_financeiros_canonicos';
    private const TABLE_EVENTS = 'biomie_eventos_canonicos';
    private const TABLE_DIVERGENCES = 'biomie_divergencias';
    private const TABLE_SYNC_STATE = 'biomie_sync_state';
    private const TABLE_RECONCILIATION_RUNS = 'biomie_reconciliacoes_runs';
    private const TABLE_RECONCILIATION_VENDOR_ROWS = 'biomie_reconciliacoes_vendedores';
    private const TABLE_RECONCILIATION_DIFF_ROWS = 'biomie_reconciliacoes_diferencas';

    public function __construct(
        private PDO $pdo,
        private readonly Logger $logger,
        private readonly ?Closure $reconnect = null,
    ) {
    }

    // ── Verificação de schema ────────────────────────────────────────────────

    public function isAvailable(): bool
    {
        try {
            return $this->hasTable(self::TABLE_VENDORS)
                && $this->hasTable(self::TABLE_ORDERS)
                && $this->hasTable(self::TABLE_INVOICES)
                && $this->hasTable(self::TABLE_DIVERGENCES);
        } catch (\Throwable) {
            return false;
        }
    }

    public function ensureFiscalSchema(): void
    {
        $this->ensureBaseTablesExist();
        $this->ensureInvoiceTablesExist();
        $this->ensureSeparatedDomainTablesExist();
        $this->ensureEventStoreTableExists();
        $this->ensureStateTableExists();
        $this->ensureReconciliationTablesExist();
    }

    private function hasTable(string $table): bool
    {
        $sql = <<<'SQL'
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = :table
            LIMIT 1
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['table' => $table]);

        return (bool) $stmt->fetchColumn();
    }

    private function hasColumn(string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = :table
              AND column_name = :column
            LIMIT 1
        SQL);
        $stmt->execute([
            'table' => $table,
            'column' => $column,
        ]);

        return (bool) $stmt->fetchColumn();
    }

    private function hasIndex(string $table, string $index): bool
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = :table
              AND index_name = :index_name
            LIMIT 1
        SQL);
        $stmt->execute([
            'table' => $table,
            'index_name' => $index,
        ]);

        return (bool) $stmt->fetchColumn();
    }

    private function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        if ($this->hasColumn($table, $column)) {
            return;
        }

        $this->pdo->exec(sprintf(
            'ALTER TABLE `%s` ADD COLUMN `%s` %s',
            $table,
            $column,
            $definition
        ));
    }

    private function addIndexIfMissing(string $table, string $index, string $definition): void
    {
        if ($this->hasIndex($table, $index)) {
            return;
        }

        $this->pdo->exec(sprintf(
            'ALTER TABLE `%s` ADD INDEX `%s` %s',
            $table,
            $index,
            $definition
        ));
    }

    /**
     * @template T
     * @param callable(PDO):T $callback
     * @return T
     */
    private function withReconnect(callable $callback): mixed
    {
        try {
            return $callback($this->pdo);
        } catch (\Throwable $exception) {
            if (!$this->shouldReconnect($exception) || !$this->reconnectPdo()) {
                throw $exception;
            }

            return $callback($this->pdo);
        }
    }

    private function shouldReconnect(\Throwable $exception): bool
    {
        $message = mb_strtolower((string) $exception->getMessage());

        return str_contains($message, 'server has gone away')
            || str_contains($message, 'lost connection')
            || str_contains($message, 'error while sending query')
            || str_contains($message, 'sqlstate[hy000]')
            || str_contains($message, 'general error: 2006')
            || str_contains($message, 'general error: 2013');
    }

    private function reconnectPdo(): bool
    {
        if (!$this->reconnect instanceof Closure) {
            return false;
        }

        $pdo = ($this->reconnect)();
        if (!$pdo instanceof PDO) {
            return false;
        }

        $this->pdo = $pdo;
        $this->logger->warning('Camada canonica reconectada apos perda de conexao MariaDB.');

        return true;
    }

    private function beginTransactionSafely(): void
    {
        try {
            $this->pdo->beginTransaction();
        } catch (\Throwable $exception) {
            if (!$this->shouldReconnect($exception) || !$this->reconnectPdo()) {
                throw $exception;
            }

            $this->pdo->beginTransaction();
        }
    }

    // ── Vendedores ───────────────────────────────────────────────────────────

    private function jsonCodec(): CanonicalJsonCodec
    {
        return new CanonicalJsonCodec();
    }

    private function hashing(): CanonicalHashing
    {
        return new CanonicalHashing($this->jsonCodec());
    }

    private function syncStateRepository(): CanonicalSyncStateRepository
    {
        return new CanonicalSyncStateRepository(
            fn (): PDO => $this->pdo,
            fn (callable $callback): mixed => $this->withReconnect($callback),
        );
    }

    private function divergenceRepository(): CanonicalDivergenceRepository
    {
        return new CanonicalDivergenceRepository(
            fn (): PDO => $this->pdo,
            function (): void {
                $this->ensureBaseTablesExist();
            },
            $this->jsonCodec(),
        );
    }

    private function reconciliationRepository(): CanonicalReconciliationRepository
    {
        return new CanonicalReconciliationRepository(
            fn (): PDO => $this->pdo,
            function (): void {
                $this->ensureReconciliationTablesExist();
            },
            function (): void {
                $this->beginTransactionSafely();
            },
            $this->jsonCodec(),
        );
    }

    private function writeRepository(): CanonicalWriteRepository
    {
        return new CanonicalWriteRepository(
            fn (): PDO => $this->pdo,
            function (): void { $this->beginTransactionSafely(); },
            $this->hashing(),
            $this->jsonCodec(),
        );
    }

    private function readRepository(): CanonicalReadRepository
    {
        return new CanonicalReadRepository(fn (): PDO => $this->pdo);
    }

    private function coverageRepository(): CanonicalCoverageRepository
    {
        return new CanonicalCoverageRepository(fn (): PDO => $this->pdo);
    }

    public function upsertVendor(string $codigoOmie, string $nome, string $email, float $comissao): void
    {
        $this->writeRepository()->upsertVendor($codigoOmie, $nome, $email, $comissao);
    }

    /**
     * @return array<string, array{codigo_omie: string, nome: string}>
     */
    public function getVendorMap(): array
    {
        return $this->readRepository()->getVendorMap();
    }

    /**
     * @return list<array{vendor_id:string,vendor_name:string,email:?string}>
     */
    public function listCommercialVendorRoster(): array
    {
        return $this->readRepository()->listCommercialVendorRoster();
    }

    /**
     * @return array{notes_count:int,merchandise_total:float,invoice_total:float,discount_total:float}
     */
    public function summarizeInvoicedCommercial(string $start, string $end): array
    {
        return $this->readRepository()->summarizeInvoicedCommercial($start, $end);
    }

    /**
     * @return array{notes_count:int,merchandise_total:float,invoice_total:float}
     */
    public function summarizeCancelledCommercial(string $start, string $end): array
    {
        return $this->readRepository()->summarizeCancelledCommercial($start, $end);
    }

    /**
     * @return array{orders_count:int,merchandise_total:float,order_total:float}
     */
    public function summarizeOrderPipelineCommercial(string $start, string $end): array
    {
        return $this->readRepository()->summarizeOrderPipelineCommercial($start, $end);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function aggregateInvoicedByVendorCommercial(string $start, string $end): array
    {
        return $this->readRepository()->aggregateInvoicedByVendorCommercial($start, $end);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function aggregateCancelledByVendorCommercial(string $start, string $end): array
    {
        return $this->readRepository()->aggregateCancelledByVendorCommercial($start, $end);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function aggregateOrderPipelineByVendorCommercial(string $start, string $end): array
    {
        return $this->readRepository()->aggregateOrderPipelineByVendorCommercial($start, $end);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function dailyInvoicedCommercial(string $start, string $end, ?string $vendorId = null): array
    {
        return $this->readRepository()->dailyInvoicedCommercial($start, $end, $vendorId);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function dailyCancelledCommercial(string $start, string $end, ?string $vendorId = null): array
    {
        return $this->readRepository()->dailyCancelledCommercial($start, $end, $vendorId);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listInvoicedOrdersForDisplayCommercial(string $start, string $end, ?string $vendorId = null): array
    {
        return $this->readRepository()->listInvoicedOrdersForDisplayCommercial($start, $end, $vendorId);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listPipelineOrdersForDisplayCommercial(string $start, string $end, ?string $vendorId = null): array
    {
        return $this->readRepository()->listPipelineOrdersForDisplayCommercial($start, $end, $vendorId);
    }

    // ── Pedidos ──────────────────────────────────────────────────────────────

    /**
     * Upsert de pedido canônico. Se o hash não mudou, pula a escrita.
     * Retorna 'inserted', 'updated', 'unchanged'.
     */
    public function upsertOrder(array $order): string
    {
        return $this->writeRepository()->upsertOrder($order);
    }

    /**
     * Substitui a carteira aberta da janela pelo retrato atual retornado pelo Omie.
     *
     * @param list<array<string,mixed>> $orders
     * @return array{inserted:int,updated:int,unchanged:int,skipped:int}
     */
    public function replacePendingOrdersWindow(string $start, string $end, array $orders): array
    {
        return $this->writeRepository()->replacePendingOrdersWindow($start, $end, $orders);
    }

    /**
     * Substitui a janela fiscal pelo retrato atual do Omie.
     *
     * @param list<array<string,mixed>> $invoices
     * @return array{inserted:int,updated:int,unchanged:int,skipped:int}
     */
    public function replaceInvoicesWindow(string $start, string $end, array $invoices): array
    {
        return $this->writeRepository()->replaceInvoicesWindow($start, $end, $invoices);
    }

    public function upsertInvoice(array $invoice): string
    {
        return $this->writeRepository()->upsertInvoice($invoice);
    }

    // ── Resiliência e Estado (Chunking e Retry) ──────────────────────────────

    // Separacao explicita de dominios: financeiro e fiscal documental.
    /**
     * @param list<array<string,mixed>> $titles
     * @return array{inserted:int,updated:int,unchanged:int,skipped:int}
     */
    public function replaceFinancialTitlesWindow(string $start, string $end, array $titles): array
    {
        return $this->writeRepository()->replaceFinancialTitlesWindow($start, $end, $titles);
    }

    public function upsertFinancialTitle(array $title): string
    {
        return $this->writeRepository()->upsertFinancialTitle($title);
    }

    public function upsertXmlDocument(array $document): string
    {
        return $this->writeRepository()->upsertXmlDocument($document);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listFinancialTitlesByPeriod(string $start, string $end): array
    {
        return $this->readRepository()->listFinancialTitlesByPeriod($start, $end);
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function getDomainEntityMap(): array
    {
        return $this->readRepository()->getDomainEntityMap();
    }

    /**
     * Event store canonico append-only.
     * Retorna 'inserted', 'unchanged' ou 'skipped'.
     */
    public function appendCanonicalEvent(array $event): string
    {
        return $this->writeRepository()->appendCanonicalEvent($event);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listCanonicalEventsByPeriod(string $start, string $end, ?string $entityName = null): array
    {
        return $this->readRepository()->listCanonicalEventsByPeriod($start, $end, $entityName);
    }

    public function reconnect(): void
    {
        // Força a desconexão interna, reabrindo chamando config
        // Como não temos acesso imediato a $config puro aqui, fechamos a conexão atual e requeremos nova se possível
        // Mas a forma correta seria fechar e abrir uma nova. O PDO fecha quando o ref count é 0.
        // Se a classe Database mantiver a instância estática, não conseguiremos forçar. 
        // O ideal é a aplicação cuidar disso. Vamos lançar PDOException no Catch do OmieSyncService
        // e lá o processo cli mata a execução se for irrecuperável ou refaz o boot.
        // No contexto da Hostinger (CLI), se a reconexão falhar, abortamos o lote com exit 1 e o Cron do sistema sobe de novo na próxima janela de tempo.
    }

    public function getSyncState(
        string $start,
        string $end,
        string $syncName = 'orders_sync'
    ): ?array
    {
        return $this->syncStateRepository()->getSyncState($start, $end, $syncName);
    }

    public function prepareSyncState(
        string $start,
        string $end,
        string $source,
        string $syncName = 'orders_sync',
        string $phase = 'chunked',
    ): array {
        return $this->syncStateRepository()->prepareSyncState($start, $end, $source, $syncName, $phase);
    }

    public function markVendorsSynced(
        string $start,
        string $end,
        int $vendorsSynced,
        string $source,
        string $syncName = 'orders_sync',
        string $phase = 'chunked',
    ): void {
        $this->syncStateRepository()->markVendorsSynced($start, $end, $vendorsSynced, $source, $syncName, $phase);
    }

    public function advanceOrdersCheckpoint(
        string $start,
        string $end,
        string $checkpointDate,
        int $checkpointPage,
        int $checkpointBatch,
        string $source,
        int $vendorsSynced,
        array $orderStats,
        string $syncName = 'orders_sync',
        string $phase = 'chunked',
    ): void {
        $this->syncStateRepository()->advanceOrdersCheckpoint(
            $start,
            $end,
            $checkpointDate,
            $checkpointPage,
            $checkpointBatch,
            $source,
            $vendorsSynced,
            $orderStats,
            $syncName,
            $phase,
        );
    }

    public function markSyncCompleted(
        string $start,
        string $end,
        string $source,
        int $vendorsSynced,
        array $orderStats,
        ?string $checkpointDate = null,
        int $checkpointPage = 1,
        int $checkpointBatch = 0,
        string $syncName = 'orders_sync',
        string $phase = 'chunked',
    ): void {
        $this->syncStateRepository()->markSyncCompleted(
            $start,
            $end,
            $source,
            $vendorsSynced,
            $orderStats,
            $checkpointDate,
            $checkpointPage,
            $checkpointBatch,
            $syncName,
            $phase,
        );
    }

    public function markSyncFailed(
        string $start,
        string $end,
        string $message,
        string $source,
        int $vendorsSynced,
        array $orderStats,
        ?string $checkpointDate = null,
        ?int $checkpointPage = null,
        ?int $checkpointBatch = null,
        ?string $nextAllowedAt = null,
        string $syncName = 'orders_sync',
        string $phase = 'chunked',
    ): void {
        $this->syncStateRepository()->markSyncFailed(
            $start,
            $end,
            $message,
            $source,
            $vendorsSynced,
            $orderStats,
            $checkpointDate,
            $checkpointPage,
            $checkpointBatch,
            $nextAllowedAt,
            $syncName,
            $phase,
        );
    }

    public function updateSyncState(array $state): void
    {
        $this->syncStateRepository()->updateSyncState($state);
    }

    private function ensureStateTableExists(): void
    {
        $sql = <<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_sync_state (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                sync_name VARCHAR(64) NOT NULL DEFAULT 'canonical_orders',
                period_start DATE NOT NULL,
                period_end DATE NOT NULL,
                phase ENUM('idle','vendors_synced','orders_running','completed','failed','chunked') NOT NULL DEFAULT 'idle',
                source VARCHAR(32) NOT NULL DEFAULT 'cron',
                checkpoint_date DATE DEFAULT NULL,
                checkpoint_page INT UNSIGNED DEFAULT NULL,
                checkpoint_batch INT UNSIGNED DEFAULT NULL,
                vendors_synced INT UNSIGNED NOT NULL DEFAULT 0,
                orders_inserted INT UNSIGNED NOT NULL DEFAULT 0,
                orders_updated INT UNSIGNED NOT NULL DEFAULT 0,
                orders_unchanged INT UNSIGNED NOT NULL DEFAULT 0,
                orders_skipped INT UNSIGNED NOT NULL DEFAULT 0,
                last_error TEXT DEFAULT NULL,
                started_at DATETIME DEFAULT NULL,
                completed_at DATETIME DEFAULT NULL,
                next_allowed_at DATETIME DEFAULT NULL,
                last_sync DATETIME DEFAULT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_biomie_sync_state_period (sync_name, period_start, period_end),
                KEY idx_biomie_sync_state_phase (phase),
                KEY idx_biomie_sync_state_checkpoint (checkpoint_date, checkpoint_page, checkpoint_batch),
                KEY idx_biomie_sync_state_last_sync (last_sync)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        SQL;
        $this->withReconnect(static function (PDO $pdo) use ($sql): void {
            $pdo->exec($sql);
        });
    }

    private function fetchSyncStateByPeriod(string $syncName, string $start, string $end): ?array
    {
        $sql = <<<'SQL'
            SELECT
                id,
                sync_name,
                phase,
                source,
                period_start,
                period_end,
                checkpoint_date,
                checkpoint_page,
                checkpoint_batch,
                vendors_synced,
                orders_inserted,
                orders_updated,
                orders_unchanged,
                orders_skipped,
                last_error,
                started_at,
                completed_at,
                next_allowed_at,
                last_sync,
                updated_at
            FROM biomie_sync_state
            WHERE sync_name = :name
              AND period_start = :start
              AND period_end = :end
            LIMIT 1
        SQL;

        return $this->withReconnect(function (PDO $pdo) use ($sql, $syncName, $start, $end): ?array {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'name' => $syncName,
                'start' => $start,
                'end' => $end,
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return $row ?: null;
        });
    }

    private function buildSyncStateRecord(array $state, ?array $currentState): array
    {
        $now = date('Y-m-d H:i:s');

        return [
            'sync_name' => (string) $state['sync_name'],
            'phase' => (string) $state['phase'],
            'source' => $this->pickSyncStateValue($state, $currentState, 'source'),
            'period_start' => $this->pickSyncStateValue($state, $currentState, 'period_start'),
            'period_end' => $this->pickSyncStateValue($state, $currentState, 'period_end'),
            'checkpoint_date' => $this->pickSyncStateValue($state, $currentState, 'checkpoint_date'),
            'checkpoint_page' => (int) $this->pickSyncStateValue($state, $currentState, 'checkpoint_page', 1),
            'checkpoint_batch' => (int) $this->pickSyncStateValue($state, $currentState, 'checkpoint_batch', 0),
            'vendors_synced' => (int) $this->pickSyncStateValue($state, $currentState, 'vendors_synced', 0),
            'orders_inserted' => (int) $this->pickSyncStateValue($state, $currentState, 'orders_inserted', 0),
            'orders_updated' => (int) $this->pickSyncStateValue($state, $currentState, 'orders_updated', 0),
            'orders_unchanged' => (int) $this->pickSyncStateValue($state, $currentState, 'orders_unchanged', 0),
            'orders_skipped' => (int) $this->pickSyncStateValue($state, $currentState, 'orders_skipped', 0),
            'last_error' => $this->pickSyncStateValue($state, $currentState, 'last_error'),
            'started_at' => $this->pickSyncStateValue($state, $currentState, 'started_at', $now),
            'completed_at' => $this->pickSyncStateValue($state, $currentState, 'completed_at'),
            'next_allowed_at' => $this->pickSyncStateValue($state, $currentState, 'next_allowed_at'),
            'last_sync' => $this->pickSyncStateValue($state, $currentState, 'last_sync'),
        ];
    }

    private function pickSyncStateValue(array $state, ?array $currentState, string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $state)) {
            return $state[$key];
        }

        if ($currentState !== null && array_key_exists($key, $currentState)) {
            return $currentState[$key];
        }

        return $default;
    }

    // ── Consultas canônicas ──────────────────────────────────────────────────

    /**
     * Lista pedidos canônicos por período e, opcionalmente, por status.
     * Esta é a query que alimenta a tela de vendedores e o cockpit.
     */
    public function listOrders(string $start, string $end, ?string $status = null): array
    {
        return $this->readRepository()->listOrders($start, $end, $status);
    }

    /**
     * Lista pedidos comerciais do periodo com competencia por emissao.
     */
    public function listOrdersCommercial(string $start, string $end, ?string $status = null): array
    {
        return $this->readRepository()->listOrdersCommercial($start, $end, $status);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listOrderRowsForReconciliation(string $start, string $end): array
    {
        return $this->readRepository()->listOrderRowsForReconciliation($start, $end);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listInvoiceRowsForReconciliation(string $start, string $end): array
    {
        return $this->readRepository()->listInvoiceRowsForReconciliation($start, $end);
    }

    /**
     * Agregação por vendedor — consulta otimizada direto no banco.
     * Substitui toda a lógica de buildStatusBlocks + vendorMatrix do SalesAnalyticsService.
     */
    public function aggregateByVendor(string $start, string $end): array
    {
        return $this->readRepository()->aggregateByVendor($start, $end);
    }

    /**
     * Agrega totais comerciais por vendedor com competencia por emissao.
     */
    public function aggregateByVendorCommercial(string $start, string $end): array
    {
        return $this->readRepository()->aggregateByVendorCommercial($start, $end);
    }

    /**
     * Resumo geral do período: totais por status.
     */
    public function summarize(string $start, string $end): array
    {
        return $this->readRepository()->summarize($start, $end);
    }

    /**
     * Resume o periodo comercial usando data de emissao como base.
     */
    public function summarizeCommercial(string $start, string $end): array
    {
        return $this->readRepository()->summarizeCommercial($start, $end);
    }

    /**
     * Série diária por status — alimenta o gráfico de evolução.
     */
    public function dailyByStatus(string $start, string $end): array
    {
        return $this->readRepository()->dailyByStatus($start, $end);
    }

    /**
     * Serie diaria comercial do periodo com base em data_emissao.
     */
    public function dailyByStatusCommercial(string $start, string $end): array
    {
        return $this->readRepository()->dailyByStatusCommercial($start, $end);
    }

    /**
     * Última sincronização do período.
     */
    public function lastSyncAt(string $start, string $end): ?string
    {
        return $this->coverageRepository()->lastSyncAt($start, $end);
    }

    /**
     * @return array<string,mixed>
     */
    public function invoiceCoverageDiagnostics(string $start, string $end): array
    {
        return $this->coverageRepository()->invoiceCoverageDiagnostics($start, $end);
    }

    /**
     * @return array<string,mixed>
     */
    public function pendingCoverageDiagnostics(string $start, string $end): array
    {
        return $this->coverageRepository()->pendingCoverageDiagnostics($start, $end);
    }

    /**
     * @return array<string,mixed>
     */
    public function commercialCoverageDiagnostics(string $start, string $end): array
    {
        return $this->coverageRepository()->commercialCoverageDiagnostics($start, $end);
    }

    /**
     * Pedidos para exibição na tela — formato compatível com o front-end.
     * Mapeia os campos canônicos para o contrato que app.js espera.
     */
    public function listOrdersForDisplay(string $start, string $end, ?string $status = null): array
    {
        return $this->readRepository()->listOrdersForDisplay($start, $end, $status);
    }

    /**
     * Formata pedidos comerciais para o contrato esperado pelo front-end.
     */
    public function listOrdersForDisplayCommercial(string $start, string $end, ?string $status = null): array
    {
        return $this->readRepository()->listOrdersForDisplayCommercial($start, $end, $status);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listInvoicesCommercial(string $start, string $end): array
    {
        return $this->readRepository()->listInvoicesCommercial($start, $end);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listCancelledInvoicesCommercial(string $start, string $end): array
    {
        return $this->readRepository()->listCancelledInvoicesCommercial($start, $end);
    }

    /**
     * Base fiscal bruta para reconciliacao com o consolidado do Omie.
     * Usa data de emissao para espelhar a exportacao padrao da pagina de downloads.
     *
     * @return list<array<string,mixed>>
     */
    public function listFiscalDocumentsByIssueDate(string $start, string $end): array
    {
        return $this->readRepository()->listFiscalDocumentsByIssueDate($start, $end);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listPendingOrdersCommercial(string $start, string $end): array
    {
        return $this->readRepository()->listPendingOrdersCommercial($start, $end);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listInvoicesForDisplayCommercial(string $start, string $end): array
    {
        return $this->readRepository()->listInvoicesForDisplayCommercial($start, $end);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listCancelledInvoicesForDisplayCommercial(string $start, string $end): array
    {
        return $this->readRepository()->listCancelledInvoicesForDisplayCommercial($start, $end);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listPendingOrdersForDisplayCommercial(string $start, string $end): array
    {
        return $this->readRepository()->listPendingOrdersForDisplayCommercial($start, $end);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function productDocumentBreakdownCommercial(
        string $start,
        string $end,
        ?string $productCode = null,
        int $limit = 200
    ): array {
        return $this->readRepository()->productDocumentBreakdownCommercial($start, $end, $productCode, $limit);
    }

    /**
     * @return array<string,int|float>
     */
    public function summarizeInvoicesCommercial(string $start, string $end): array
    {
        return $this->readRepository()->summarizeInvoicesCommercial($start, $end);
    }

    /**
     * @return array<string,int|float>
     */
    public function summarizePendingCommercial(string $start, string $end): array
    {
        return $this->readRepository()->summarizePendingCommercial($start, $end);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function aggregateInvoicesByVendorCommercial(string $start, string $end): array
    {
        return $this->readRepository()->aggregateInvoicesByVendorCommercial($start, $end);
    }

    /**
     * Relatório de reconciliação — compara totais canônicos por vendedor.
     * Útil para conferir BIomie x Omie x planilha.
     */
    public function reconciliationReport(string $start, string $end): array
    {
        return $this->reconciliationRepository()->report($start, $end);
    }

    // ── Divergências ─────────────────────────────────────────────────────────

    /**
     * @return list<array<string,mixed>>
     */
    public function topProductsCommercial(string $start, string $end, int $limit = 12): array
    {
        return $this->readRepository()->topProductsCommercial($start, $end, $limit);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function topClientsCommercial(string $start, string $end, int $limit = 12): array
    {
        return $this->readRepository()->topClientsCommercial($start, $end, $limit);
    }

    /**
     * @param array<string,mixed>|null $contexto
     * @param array<string,mixed> $meta
     */
    public function logDivergence(
        string $tipo,
        string $referencia,
        string $descricao,
        ?array $contexto = null,
        array $meta = [],
    ): void {
        $this->divergenceRepository()->log($tipo, $referencia, $descricao, $contexto, $meta);
    }

    /**
     * @param list<string> $statuses
     * @return list<array<string,mixed>>
     */
    public function listDivergenceQueue(array $statuses = [], ?string $severity = null, int $limit = 100): array
    {
        return $this->divergenceRepository()->listQueue($statuses, $severity, $limit);
    }

    /**
     * @return array<string,mixed>
     */
    public function summarizeDivergenceQueue(): array
    {
        return $this->divergenceRepository()->summarizeQueue();
    }

    public function updateDivergenceOperationalStatus(int $id, string $status, ?string $actor = null, ?string $note = null): void
    {
        $this->divergenceRepository()->updateOperationalStatus($id, $status, $actor, $note);
    }

    public function openDivergences(int $limit = 50): array
    {
        return $this->divergenceRepository()->open($limit);
    }

    /**
     * @param array<string,mixed> $report
     * @param array<string,mixed>|null $actor
     */
    public function saveReconciliationSnapshot(array $report, ?array $actor = null): int
    {
        return $this->reconciliationRepository()->saveSnapshot($report, $actor);

        $this->ensureReconciliationTablesExist();

        $source = is_array($report['source'] ?? null) ? $report['source'] : [];
        $period = is_array($source['period'] ?? null) ? $source['period'] : [];
        $periodStart = (string) ($period['start'] ?? '');
        $periodEnd = (string) ($period['end'] ?? '');
        if ($periodStart === '' || $periodEnd === '') {
            throw new \InvalidArgumentException('Período da conciliação não informado.');
        }

        $metrics = is_array($report['metrics'] ?? null) ? $report['metrics'] : [];
        $vendorRows = is_array($report['vendor_comparison'] ?? null) ? $report['vendor_comparison'] : [];
        $differences = is_array($report['differences'] ?? null) ? $report['differences'] : [];
        $sourceType = trim((string) ($source['source_type'] ?? 'omie_csv')) ?: 'omie_csv';
        $sourceFile = trim((string) ($source['source_file'] ?? 'consolidado.csv'));
        $createdUserId = isset($actor['id']) ? (int) $actor['id'] : null;
        $createdUserName = trim((string) ($actor['username'] ?? $actor['full_name'] ?? ''));

        $runId = 0;
        try {
            $this->beginTransactionSafely();

            $stmt = $this->pdo->prepare(
                'UPDATE ' . self::TABLE_RECONCILIATION_RUNS . '
                 SET latest_for_period = 0
                 WHERE source_type = :source_type
                   AND period_start = :period_start
                   AND period_end = :period_end'
            );
            $stmt->execute([
                'source_type' => $sourceType,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ]);

            $stmt = $this->pdo->prepare(
                'INSERT INTO ' . self::TABLE_RECONCILIATION_RUNS . ' (
                    source_type, source_file, period_start, period_end,
                    notes_csv, notes_system, differences_count, matches_count,
                    created_user_id, created_user_name, latest_for_period, report_json, created_at
                 ) VALUES (
                    :source_type, :source_file, :period_start, :period_end,
                    :notes_csv, :notes_system, :differences_count, :matches_count,
                    :created_user_id, :created_user_name, 1, :report_json, NOW()
                 )'
            );
            $stmt->execute([
                'source_type' => $sourceType,
                'source_file' => $sourceFile,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'notes_csv' => (int) ($metrics['notes_csv'] ?? 0),
                'notes_system' => (int) ($metrics['notes_system'] ?? 0),
                'differences_count' => (int) ($metrics['differences'] ?? 0),
                'matches_count' => (int) ($metrics['matches'] ?? 0),
                'created_user_id' => $createdUserId,
                'created_user_name' => $createdUserName !== '' ? $createdUserName : null,
                'report_json' => $this->encodeJson($report),
            ]);

            $runId = (int) $this->pdo->lastInsertId();

            if ($vendorRows !== []) {
                $stmtVendor = $this->pdo->prepare(
                    'INSERT INTO ' . self::TABLE_RECONCILIATION_VENDOR_ROWS . ' (
                        run_id, vendor_key, vendor_name, has_difference, payload_json
                     ) VALUES (
                        :run_id, :vendor_key, :vendor_name, :has_difference, :payload_json
                     )'
                );

                foreach ($vendorRows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    $stmtVendor->execute([
                        'run_id' => $runId,
                        'vendor_key' => (string) ($row['vendor_key'] ?? ''),
                        'vendor_name' => (string) ($row['vendor_name'] ?? 'Sem vendedor'),
                        'has_difference' => !empty($row['has_difference']) ? 1 : 0,
                        'payload_json' => $this->encodeJson($row),
                    ]);
                }
            }

            if ($differences !== []) {
                $stmtDiff = $this->pdo->prepare(
                    'INSERT INTO ' . self::TABLE_RECONCILIATION_DIFF_ROWS . ' (
                        run_id, note_key, note_ref, difference_type, payload_json
                     ) VALUES (
                        :run_id, :note_key, :note_ref, :difference_type, :payload_json
                     )'
                );

                foreach ($differences as $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    $stmtDiff->execute([
                        'run_id' => $runId,
                        'note_key' => (string) ($row['note_key'] ?? ''),
                        'note_ref' => (string) ($row['note_ref'] ?? ''),
                        'difference_type' => (string) ($row['type'] ?? 'mismatch'),
                        'payload_json' => $this->encodeJson($row),
                    ]);
                }
            }

            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return $runId;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function loadReconciliationSnapshot(?int $runId = null): ?array
    {
        return $this->reconciliationRepository()->loadSnapshot($runId);

        $this->ensureReconciliationTablesExist();

        if ($runId !== null && $runId > 0) {
            $stmt = $this->pdo->prepare('SELECT * FROM ' . self::TABLE_RECONCILIATION_RUNS . ' WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $runId]);
        } else {
            $stmt = $this->pdo->query(
                'SELECT * FROM ' . self::TABLE_RECONCILIATION_RUNS . '
                 WHERE latest_for_period = 1
                 ORDER BY created_at DESC, id DESC
                 LIMIT 1'
            );
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $report = $this->decodeJsonObject((string) ($row['report_json'] ?? ''));
        if ($report === null) {
            return null;
        }

        $report['saved'] = [
            'run_id' => (int) ($row['id'] ?? 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'created_user_name' => (string) ($row['created_user_name'] ?? ''),
            'source_file' => (string) ($row['source_file'] ?? ''),
        ];

        return $report;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listRecentReconciliationSnapshots(int $limit = 12): array
    {
        return $this->reconciliationRepository()->listRecentSnapshots($limit);

        $this->ensureReconciliationTablesExist();

        $stmt = $this->pdo->prepare(
            'SELECT id, source_type, source_file, period_start, period_end, notes_csv, notes_system,
                    differences_count, matches_count, created_user_name, latest_for_period, created_at
             FROM ' . self::TABLE_RECONCILIATION_RUNS . '
             ORDER BY created_at DESC, id DESC
             LIMIT :lim'
        );
        $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function ensureBaseTablesExist(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_vendedores_omie (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                codigo_omie VARCHAR(32) NOT NULL,
                nome VARCHAR(120) NOT NULL,
                email VARCHAR(200) NULL,
                comissao DECIMAL(5,2) NOT NULL DEFAULT 0,
                ativo TINYINT(1) NOT NULL DEFAULT 1,
                sincronizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_codigo_omie (codigo_omie)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_pedidos_canonicos (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                pedido_id_omie BIGINT NOT NULL,
                codigo_pedido VARCHAR(32) NOT NULL,
                codigo_integracao VARCHAR(64) NULL,
                codigo_vendedor_omie VARCHAR(32) NOT NULL DEFAULT '',
                nome_vendedor VARCHAR(120) NOT NULL DEFAULT 'Sem vendedor',
                codigo_cliente_omie BIGINT NULL,
                nome_cliente VARCHAR(200) NOT NULL DEFAULT 'Cliente nao informado',
                documento_cliente VARCHAR(20) NULL,
                status_comercial ENUM('faturado','autorizado','cancelado','outro') NOT NULL DEFAULT 'outro',
                etapa_omie VARCHAR(32) NULL,
                status_pedido_omie VARCHAR(64) NULL,
                data_emissao DATE NULL,
                data_faturamento DATE NULL,
                data_cancelamento DATE NULL,
                valor_total DECIMAL(15,2) NOT NULL DEFAULT 0,
                valor_mercadorias DECIMAL(15,2) NOT NULL DEFAULT 0,
                valor_descontos DECIMAL(15,2) NOT NULL DEFAULT 0,
                quantidade_itens INT UNSIGNED NOT NULL DEFAULT 0,
                hash_payload VARCHAR(64) NULL,
                sincronizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                fonte_sync ENUM('cron','manual','webhook') NOT NULL DEFAULT 'cron',
                PRIMARY KEY (id),
                UNIQUE KEY uq_pedido_omie (pedido_id_omie),
                KEY idx_vendedor_status (codigo_vendedor_omie, status_comercial),
                KEY idx_status_faturamento (status_comercial, data_faturamento),
                KEY idx_data_emissao (data_emissao),
                KEY idx_data_faturamento (data_faturamento),
                KEY idx_sincronizado (sincronizado_em)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_divergencias (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                tipo VARCHAR(64) NOT NULL,
                rule_code VARCHAR(80) NOT NULL DEFAULT '',
                severity ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
                entity_type VARCHAR(32) NOT NULL DEFAULT 'generic',
                source_domain VARCHAR(32) NOT NULL DEFAULT 'reconciliation',
                source_origin VARCHAR(64) NULL,
                referencia VARCHAR(128) NULL,
                descricao TEXT NULL,
                contexto_json TEXT NULL,
                suggested_action VARCHAR(255) NULL,
                operational_status ENUM('aberta','reincidente','resolvida','ignorada_regra') NOT NULL DEFAULT 'aberta',
                first_detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_seen_period_start DATE NULL,
                last_seen_period_end DATE NULL,
                occurrence_count INT UNSIGNED NOT NULL DEFAULT 1,
                detectado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                resolvido_em TIMESTAMP NULL,
                resolvido_por VARCHAR(64) NULL,
                resolution_note TEXT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_divergencia_regra_ref (rule_code, referencia),
                KEY idx_divergencia_status (operational_status, severity, last_detected_at),
                KEY idx_divergencia_rule (rule_code),
                KEY idx_divergencia_entity (entity_type, referencia),
                KEY idx_tipo (tipo),
                KEY idx_detectado (detectado_em),
                KEY idx_aberto (resolvido_em)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->ensureDivergenceTableShape();
    }

    private function ensureDivergenceTableShape(): void
    {
        if (!$this->hasTable(self::TABLE_DIVERGENCES)) {
            return;
        }

        $this->addColumnIfMissing(self::TABLE_DIVERGENCES, 'rule_code', "VARCHAR(80) NOT NULL DEFAULT '' AFTER `tipo`");
        $this->addColumnIfMissing(
            self::TABLE_DIVERGENCES,
            'severity',
            "ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium' AFTER `rule_code`"
        );
        $this->addColumnIfMissing(
            self::TABLE_DIVERGENCES,
            'entity_type',
            "VARCHAR(32) NOT NULL DEFAULT 'generic' AFTER `severity`"
        );
        $this->addColumnIfMissing(
            self::TABLE_DIVERGENCES,
            'source_domain',
            "VARCHAR(32) NOT NULL DEFAULT 'reconciliation' AFTER `entity_type`"
        );
        $this->addColumnIfMissing(
            self::TABLE_DIVERGENCES,
            'source_origin',
            "VARCHAR(64) NULL AFTER `source_domain`"
        );
        $this->addColumnIfMissing(
            self::TABLE_DIVERGENCES,
            'suggested_action',
            "VARCHAR(255) NULL AFTER `contexto_json`"
        );
        $this->addColumnIfMissing(
            self::TABLE_DIVERGENCES,
            'operational_status',
            "ENUM('aberta','reincidente','resolvida','ignorada_regra') NOT NULL DEFAULT 'aberta' AFTER `suggested_action`"
        );
        $this->addColumnIfMissing(
            self::TABLE_DIVERGENCES,
            'first_detected_at',
            "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `operational_status`"
        );
        $this->addColumnIfMissing(
            self::TABLE_DIVERGENCES,
            'last_detected_at',
            "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `first_detected_at`"
        );
        $this->addColumnIfMissing(
            self::TABLE_DIVERGENCES,
            'last_seen_period_start',
            "DATE NULL AFTER `last_detected_at`"
        );
        $this->addColumnIfMissing(
            self::TABLE_DIVERGENCES,
            'last_seen_period_end',
            "DATE NULL AFTER `last_seen_period_start`"
        );
        $this->addColumnIfMissing(
            self::TABLE_DIVERGENCES,
            'occurrence_count',
            "INT UNSIGNED NOT NULL DEFAULT 1 AFTER `last_seen_period_end`"
        );
        $this->addColumnIfMissing(
            self::TABLE_DIVERGENCES,
            'resolution_note',
            "TEXT NULL AFTER `resolvido_por`"
        );

        $this->pdo->exec(<<<'SQL'
            UPDATE biomie_divergencias
            SET rule_code = CASE
                    WHEN rule_code IS NULL OR rule_code = '' THEN COALESCE(NULLIF(tipo, ''), 'divergencia')
                    ELSE rule_code
                END,
                first_detected_at = COALESCE(detectado_em, first_detected_at, NOW()),
                last_detected_at = COALESCE(detectado_em, last_detected_at, NOW()),
                occurrence_count = CASE
                    WHEN occurrence_count IS NULL OR occurrence_count < 1 THEN 1
                    ELSE occurrence_count
                END,
                operational_status = CASE
                    WHEN resolvido_em IS NOT NULL AND operational_status IN ('aberta', 'reincidente') THEN 'resolvida'
                    ELSE operational_status
                END
        SQL);

        $this->addIndexIfMissing(
            self::TABLE_DIVERGENCES,
            'idx_divergencia_status',
            "(`operational_status`, `severity`, `last_detected_at`)"
        );
        $this->addIndexIfMissing(self::TABLE_DIVERGENCES, 'idx_divergencia_rule', "(`rule_code`)");
        $this->addIndexIfMissing(self::TABLE_DIVERGENCES, 'idx_divergencia_entity', "(`entity_type`, `referencia`)");
    }

    private function ensureInvoiceTablesExist(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_notas_fiscais_canonicas (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                nota_key VARCHAR(80) NOT NULL,
                nota_id_omie BIGINT NULL,
                chave_nfe VARCHAR(64) NULL,
                numero_nf VARCHAR(32) NOT NULL DEFAULT '',
                serie_nf VARCHAR(16) NULL,
                pedido_id_omie BIGINT NULL,
                codigo_pedido_origem VARCHAR(32) NULL,
                codigo_vendedor_omie VARCHAR(32) NOT NULL DEFAULT '',
                nome_vendedor VARCHAR(120) NOT NULL DEFAULT 'Sem vendedor',
                codigo_cliente_omie BIGINT NULL,
                nome_cliente VARCHAR(200) NOT NULL DEFAULT 'Cliente nao informado',
                documento_cliente VARCHAR(20) NULL,
                status_fiscal ENUM('autorizada','cancelada') NOT NULL DEFAULT 'autorizada',
                data_emissao DATE NULL,
                data_cancelamento DATE NULL,
                data_competencia DATE NULL,
                valor_total DECIMAL(15,2) NOT NULL DEFAULT 0,
                valor_mercadorias DECIMAL(15,2) NOT NULL DEFAULT 0,
                valor_descontos DECIMAL(15,2) NOT NULL DEFAULT 0,
                quantidade_itens INT UNSIGNED NOT NULL DEFAULT 0,
                hash_payload VARCHAR(64) NULL,
                sincronizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                fonte_sync ENUM('cron','manual','webhook') NOT NULL DEFAULT 'cron',
                PRIMARY KEY (id),
                UNIQUE KEY uq_nota_key (nota_key),
                KEY idx_invoice_status_competencia (status_fiscal, data_competencia),
                KEY idx_invoice_vendor_status (codigo_vendedor_omie, status_fiscal, data_competencia),
                KEY idx_invoice_emissao (data_emissao),
                KEY idx_invoice_cancelamento (data_cancelamento),
                KEY idx_invoice_chave (chave_nfe)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_notas_fiscais_itens_canonicos (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                nota_key VARCHAR(80) NOT NULL,
                sequencia_item INT UNSIGNED NOT NULL,
                codigo_produto VARCHAR(60) NULL,
                descricao_produto VARCHAR(255) NOT NULL DEFAULT 'Produto sem descricao',
                quantidade DECIMAL(15,4) NOT NULL DEFAULT 0,
                valor_total DECIMAL(15,2) NOT NULL DEFAULT 0,
                valor_desconto DECIMAL(15,2) NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                UNIQUE KEY uq_invoice_item (nota_key, sequencia_item),
                KEY idx_invoice_item_product (codigo_produto, descricao_produto),
                KEY idx_invoice_item_note (nota_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    private function ensureSeparatedDomainTablesExist(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_documentos_xml_canonicos (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                xml_key VARCHAR(96) NOT NULL,
                nota_key VARCHAR(80) NULL,
                nota_id_omie BIGINT NULL,
                chave_nfe VARCHAR(64) NULL,
                numero_nf VARCHAR(32) NOT NULL DEFAULT '',
                serie_nf VARCHAR(16) NULL,
                pedido_id_omie BIGINT NULL,
                codigo_pedido_origem VARCHAR(32) NULL,
                documento_tipo VARCHAR(32) NOT NULL DEFAULT 'nfe_xml',
                status_documento ENUM('pendente','disponivel','indisponivel','cancelado') NOT NULL DEFAULT 'pendente',
                data_emissao DATE NULL,
                data_cancelamento DATE NULL,
                xml_payload LONGTEXT NULL,
                metadata_json LONGTEXT NULL,
                hash_payload VARCHAR(64) NULL,
                sincronizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                fonte_sync ENUM('cron','manual','webhook') NOT NULL DEFAULT 'cron',
                PRIMARY KEY (id),
                UNIQUE KEY uq_xml_key (xml_key),
                KEY idx_xml_chave (chave_nfe),
                KEY idx_xml_nota (nota_key),
                KEY idx_xml_status_emissao (status_documento, data_emissao)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_titulos_financeiros_canonicos (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                titulo_key VARCHAR(96) NOT NULL,
                titulo_id_omie BIGINT NULL,
                codigo_integracao VARCHAR(80) NULL,
                numero_documento VARCHAR(64) NOT NULL DEFAULT '',
                status_titulo VARCHAR(32) NOT NULL DEFAULT 'outro',
                especie_documento VARCHAR(64) NULL,
                forma_pagamento VARCHAR(64) NULL,
                documento_origem_tipo VARCHAR(32) NULL,
                documento_origem_ref VARCHAR(120) NULL,
                nota_key VARCHAR(80) NULL,
                chave_nfe VARCHAR(64) NULL,
                pedido_id_omie BIGINT NULL,
                codigo_pedido_origem VARCHAR(32) NULL,
                codigo_cliente_omie BIGINT NULL,
                nome_cliente VARCHAR(200) NOT NULL DEFAULT 'Cliente nao informado',
                documento_cliente VARCHAR(20) NULL,
                codigo_vendedor_omie VARCHAR(32) NOT NULL DEFAULT '',
                nome_vendedor VARCHAR(120) NOT NULL DEFAULT 'Sem vendedor',
                codigo_conta_corrente_omie VARCHAR(32) NULL,
                data_emissao DATE NULL,
                data_vencimento DATE NULL,
                data_liquidacao DATE NULL,
                data_competencia DATE NULL,
                valor_pago DECIMAL(15,2) NOT NULL DEFAULT 0,
                valor_liquido DECIMAL(15,2) NOT NULL DEFAULT 0,
                valor_aberto DECIMAL(15,2) NOT NULL DEFAULT 0,
                hash_payload VARCHAR(64) NULL,
                sincronizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                fonte_sync ENUM('cron','manual','webhook') NOT NULL DEFAULT 'cron',
                PRIMARY KEY (id),
                UNIQUE KEY uq_titulo_key (titulo_key),
                KEY idx_titulo_competencia (data_competencia, status_titulo),
                KEY idx_titulo_cliente (codigo_cliente_omie, data_competencia),
                KEY idx_titulo_vendedor (codigo_vendedor_omie, data_competencia),
                KEY idx_titulo_origem_nota (nota_key),
                KEY idx_titulo_origem_pedido (pedido_id_omie),
                KEY idx_titulo_chave_nfe (chave_nfe)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    private function ensureEventStoreTableExists(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_eventos_canonicos (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                entity_name VARCHAR(32) NOT NULL,
                omie_entity_id BIGINT NULL,
                entity_code VARCHAR(60) NULL,
                integration_code VARCHAR(80) NULL,
                event_type VARCHAR(64) NOT NULL,
                event_at_omie DATETIME NULL,
                event_order INT UNSIGNED NOT NULL DEFAULT 0,
                stage_code VARCHAR(16) NULL,
                event_user VARCHAR(32) NULL,
                document_key VARCHAR(64) NULL,
                document_number VARCHAR(32) NULL,
                document_series VARCHAR(16) NULL,
                source_payload_hash CHAR(64) NOT NULL,
                idempotency_hash CHAR(64) NOT NULL,
                source_payload_json LONGTEXT NOT NULL,
                source VARCHAR(32) NOT NULL DEFAULT 'cron',
                ingested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_canonical_event_idempotency (idempotency_hash),
                KEY idx_event_entity_time (entity_name, omie_entity_id, event_at_omie),
                KEY idx_event_type_time (event_type, event_at_omie),
                KEY idx_event_code_time (entity_code, event_at_omie)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    private function ensureReconciliationTablesExist(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_reconciliacoes_runs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                source_type VARCHAR(32) NOT NULL DEFAULT 'omie_csv',
                source_file VARCHAR(255) NOT NULL DEFAULT '',
                period_start DATE NOT NULL,
                period_end DATE NOT NULL,
                notes_csv INT UNSIGNED NOT NULL DEFAULT 0,
                notes_system INT UNSIGNED NOT NULL DEFAULT 0,
                differences_count INT UNSIGNED NOT NULL DEFAULT 0,
                matches_count INT UNSIGNED NOT NULL DEFAULT 0,
                created_user_id BIGINT NULL,
                created_user_name VARCHAR(120) NULL,
                latest_for_period TINYINT(1) NOT NULL DEFAULT 1,
                report_json LONGTEXT NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_reconciliation_period (period_start, period_end, latest_for_period),
                KEY idx_reconciliation_created (created_at),
                KEY idx_reconciliation_source (source_type, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_reconciliacoes_vendedores (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                run_id BIGINT UNSIGNED NOT NULL,
                vendor_key VARCHAR(180) NOT NULL DEFAULT '',
                vendor_name VARCHAR(160) NOT NULL DEFAULT 'Sem vendedor',
                has_difference TINYINT(1) NOT NULL DEFAULT 0,
                payload_json LONGTEXT NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_reconciliation_vendor_run (run_id),
                KEY idx_reconciliation_vendor_name (vendor_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_reconciliacoes_diferencas (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                run_id BIGINT UNSIGNED NOT NULL,
                note_key VARCHAR(120) NOT NULL DEFAULT '',
                note_ref VARCHAR(80) NOT NULL DEFAULT '',
                difference_type VARCHAR(40) NOT NULL DEFAULT 'mismatch',
                payload_json LONGTEXT NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_reconciliation_diff_run (run_id),
                KEY idx_reconciliation_diff_note (note_ref),
                KEY idx_reconciliation_diff_type (difference_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

}


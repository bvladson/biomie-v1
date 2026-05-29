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
use BIomie\DataPlatform\Silver\Schema\CanonicalSchemaManager;
use BIomie\Modules\Geo\Infrastructure\ClientDimensionWriter;
use BIomie\Modules\Geo\Infrastructure\GeoFactReconstructor;
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

    // ── Schema — delegado para CanonicalSchemaManager (Fase 5) ─────────────

    private function schemaManager(): CanonicalSchemaManager
    {
        return new CanonicalSchemaManager($this->pdo);
    }

    public function isAvailable(): bool
    {
        return $this->schemaManager()->isAvailable();
    }

    public function ensureFiscalSchema(): void
    {
        $this->schemaManager()->ensureFiscalSchema();
    }

    /**
     * @return list<string>
     */
    public function missingGeoAnalyticsTables(): array
    {
        return $this->schemaManager()->missingGeoAnalyticsTables();
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
                $this->schemaManager()->ensureBaseTablesExist();
            },
            $this->jsonCodec(),
        );
    }

    private function reconciliationRepository(): CanonicalReconciliationRepository
    {
        return new CanonicalReconciliationRepository(
            fn (): PDO => $this->pdo,
            function (): void {
                $this->schemaManager()->ensureReconciliationTablesExist();
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

    public function upsertVendor(string $codigoOmie, string $nome, string $email, float $comissao, bool $ativo = true): void
    {
        $this->writeRepository()->upsertVendor($codigoOmie, $nome, $email, $comissao, $ativo);
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
    public function listTopInvoicesForDisplayCommercial(string $start, string $end, int $limit = 8): array
    {
        return $this->readRepository()->listTopInvoicesForDisplayCommercial($start, $end, $limit);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listCancelledInvoicesForDisplayCommercial(string $start, string $end): array
    {
        return $this->readRepository()->listCancelledInvoicesForDisplayCommercial($start, $end);
    }

    /**
     * Fase H — NFs de remessa. Retorna [] pré-migration H3.
     *
     * @return list<array<string,mixed>>
     */
    public function listInvoicesForDisplayRemittance(string $start, string $end): array
    {
        return $this->readRepository()->listInvoicesForDisplayRemittance($start, $end);
    }

    public function operationScopeAvailable(): bool
    {
        return $this->readRepository()->operationScopeAvailable();
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
    }

    /**
     * @return array<string,mixed>|null
     */
    public function loadReconciliationSnapshot(?int $runId = null): ?array
    {
        return $this->reconciliationRepository()->loadSnapshot($runId);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listRecentReconciliationSnapshots(int $limit = 12): array
    {
        return $this->reconciliationRepository()->listRecentSnapshots($limit);
    }

    // ── PDO accessor ─────────────────────────────────────────────────────────

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    // ── Geo — delegado para GeoFactReconstructor e ClientDimensionWriter ─────

    private function geoReconstructor(): GeoFactReconstructor
    {
        return new GeoFactReconstructor($this->pdo);
    }

    private function clientDimensionWriter(): ClientDimensionWriter
    {
        return new ClientDimensionWriter($this->pdo);
    }

    public function reconstructGeoFactTables(string $start, string $end): void
    {
        $this->geoReconstructor()->reconstructGeoFactTables($start, $end);
    }

    /**
     * @return list<int>
     */
    public function getPendingEnrichmentClients(int $limit): array
    {
        return $this->clientDimensionWriter()->getPendingEnrichmentClients($limit);
    }

    /**
     * @param list<int> $codes
     */
    public function markEnrichmentStatus(array $codes, string $status, string $reason = ''): void
    {
        $this->clientDimensionWriter()->markEnrichmentStatus($codes, $status, $reason);
    }

    /**
     * @param array<string,mixed> $dim
     * @param array<string,mixed> $geo
     */
    public function upsertClientDimensions(array $dim, array $geo): void
    {
        $this->clientDimensionWriter()->upsertClientDimensions($dim, $geo);
    }
}

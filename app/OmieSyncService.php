<?php

declare(strict_types=1);

namespace BIomie;

/**
 * Servico de sincronizacao Omie -> Camada Canonica.
 *
 * Responsabilidades:
 * 1. Puxar vendedores e pedidos do Omie
 * 2. Normalizar cada pedido em estrutura canonica
 * 3. Persistir na tabela biomie_pedidos_canonicos
 * 4. Detectar e registrar divergencias
 *
 * Este servico nao decide como exibir os dados na tela.
 */
final class OmieSyncService
{
    private const STATE_SYNC_NAME = 'orders_sync';
    private const STATE_PHASE = 'chunked';

    private array $vendorMap = [];

    public function __construct(
        private readonly Config $config,
        private readonly OmieRepository $omie,
        private readonly CanonicalRepository $canonical,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Sync completo para o periodo: vendedores + pipeline de pedidos em blocos.
     *
     * @return array{
     *     vendors_synced: int,
     *     orders: array{inserted: int, updated: int, unchanged: int, skipped: int},
     *     orders_checked: int,
     *     divergences: int,
     *     duration_seconds: float
     * }
     */
    public function syncPeriod(string $start, string $end, string $fonte = 'cron'): array
    {
        $t0 = microtime(true);

        $this->logger->info('Sync canonico iniciado (modo STATEFUL)', [
            'start' => $start,
            'end' => $end,
            'fonte' => $fonte,
        ]);

        $state = $this->canonical->prepareSyncState(
            $start,
            $end,
            $fonte,
            self::STATE_SYNC_NAME,
            self::STATE_PHASE
        );

        try {
            $vendorCount = $this->syncVendors();
            $this->canonical->markVendorsSynced(
                $start,
                $end,
                $vendorCount,
                $fonte,
                self::STATE_SYNC_NAME,
                self::STATE_PHASE
            );
        } catch (\Throwable $e) {
            $this->canonical->markSyncFailed(
                $start,
                $end,
                $e->getMessage(),
                $fonte,
                0,
                $this->emptyOrderStats(),
                $state['checkpoint_date'] ?? $start,
                (int) ($state['checkpoint_page'] ?? 1),
                (int) ($state['checkpoint_batch'] ?? 0),
                null,
                self::STATE_SYNC_NAME,
                self::STATE_PHASE
            );
            throw $e;
        }

        $ordersResult = $this->syncOrdersChunked($start, $end, $fonte, $vendorCount);
        $ordersChecked = $this->sumOrderStats($ordersResult['orders']);
        $duration = round(microtime(true) - $t0, 2);

        $this->logger->info('Sync canonico finalizado na pipeline inteira.', [
            'duration_seconds' => $duration,
            'vendors' => $vendorCount,
            'orders' => $ordersResult['orders'],
            'orders_checked' => $ordersChecked,
            'divergences' => $ordersResult['divergences'],
        ]);

        return [
            'vendors_synced' => $vendorCount,
            'orders' => $ordersResult['orders'],
            'orders_checked' => $ordersChecked,
            'divergences' => $ordersResult['divergences'],
            'duration_seconds' => $duration,
        ];
    }

    /**
     * Quebra o intervalo em janelas diarias, lendo e gravando checkpoint.
     *
     * @return array{
     *     orders: array{inserted: int, updated: int, unchanged: int, skipped: int},
     *     divergences: int
     * }
     */
    private function syncOrdersChunked(string $start, string $end, string $fonte, int $vendorCount): array
    {
        $current = strtotime($start);
        $lastTimestamp = strtotime($end);
        $orderStats = $this->emptyOrderStats();
        $divergenceCount = 0;

        $state = $this->canonical->getSyncState($start, $end, self::STATE_SYNC_NAME);
        if ($state && !empty($state['checkpoint_date'])) {
            $checkpointTs = strtotime((string) $state['checkpoint_date']);
            if ($checkpointTs >= $current) {
                $current = $checkpointTs;
            }
        }

        while ($current <= $lastTimestamp) {
            $dayStart = date('Y-m-d', $current);
            $dayEnd = $dayStart;
            $checkpointPage = ($state && ($state['checkpoint_date'] ?? null) === $dayStart)
                ? (int) ($state['checkpoint_page'] ?? 1)
                : 1;
            $checkpointBatch = ($state && ($state['checkpoint_date'] ?? null) === $dayStart)
                ? (int) ($state['checkpoint_batch'] ?? 0)
                : 0;

            $this->logger->info("Iniciando chunk de pedidos Omie: {$dayStart} - {$dayEnd}");
            $this->canonical->advanceOrdersCheckpoint(
                $start,
                $end,
                $dayStart,
                $checkpointPage,
                $checkpointBatch,
                $fonte,
                $vendorCount,
                $orderStats,
                self::STATE_SYNC_NAME,
                self::STATE_PHASE
            );

            $success = false;
            $attempts = 0;

            while (!$success && $attempts < 3) {
                try {
                    $dayResult = $this->processDayBatch($dayStart, $dayEnd, $checkpointPage, $fonte);
                    $orderStats = $this->mergeOrderStats($orderStats, $dayResult['orders']);
                    $divergenceCount += $dayResult['divergences'];
                    $checkpointPage = (int) $dayResult['checkpoint_page'];
                    $checkpointBatch = (int) $dayResult['checkpoint_batch'];

                    $nextCheckpointDate = date('Y-m-d', strtotime('+1 day', $current));
                    if (strtotime($nextCheckpointDate) <= $lastTimestamp) {
                        $this->canonical->advanceOrdersCheckpoint(
                            $start,
                            $end,
                            $nextCheckpointDate,
                            1,
                            0,
                            $fonte,
                            $vendorCount,
                            $orderStats,
                            self::STATE_SYNC_NAME,
                            self::STATE_PHASE
                        );
                    }

                    $success = true;
                } catch (\PDOException $e) {
                    $this->logger->error('Falha de banco (PDOException) durante lote.', [
                        'msg' => $e->getMessage(),
                        'code' => $e->getCode(),
                    ]);
                    $this->canonical->markSyncFailed(
                        $start,
                        $end,
                        $e->getMessage(),
                        $fonte,
                        $vendorCount,
                        $orderStats,
                        $dayStart,
                        $checkpointPage,
                        $checkpointBatch,
                        null,
                        self::STATE_SYNC_NAME,
                        self::STATE_PHASE
                    );
                    throw $e;
                } catch (\RuntimeException $e) {
                    if (str_contains($e->getMessage(), 'REDUNDANT')) {
                        $this->logger->warning('API Rate limit (REDUNDANT) - Backoff de seguranca (10s)', [
                            'attempt' => $attempts,
                        ]);
                        $this->canonical->updateSyncState([
                            'sync_name' => self::STATE_SYNC_NAME,
                            'phase' => self::STATE_PHASE,
                            'period_start' => $start,
                            'period_end' => $end,
                            'last_error' => $e->getMessage(),
                            'next_allowed_at' => date('Y-m-d H:i:s', time() + 10),
                        ]);
                        sleep(10);
                        $attempts++;
                        continue;
                    }

                    $this->canonical->markSyncFailed(
                        $start,
                        $end,
                        $e->getMessage(),
                        $fonte,
                        $vendorCount,
                        $orderStats,
                        $dayStart,
                        $checkpointPage,
                        $checkpointBatch,
                        null,
                        self::STATE_SYNC_NAME,
                        self::STATE_PHASE
                    );
                    throw $e;
                } catch (\Throwable $e) {
                    $this->canonical->markSyncFailed(
                        $start,
                        $end,
                        $e->getMessage(),
                        $fonte,
                        $vendorCount,
                        $orderStats,
                        $dayStart,
                        $checkpointPage,
                        $checkpointBatch,
                        null,
                        self::STATE_SYNC_NAME,
                        self::STATE_PHASE
                    );
                    throw $e;
                }
            }

            if (!$success) {
                $this->canonical->markSyncFailed(
                    $start,
                    $end,
                    'Exceeded max retries due to REDUNDANT or failures.',
                    $fonte,
                    $vendorCount,
                    $orderStats,
                    $dayStart,
                    $checkpointPage,
                    $checkpointBatch,
                    date('Y-m-d H:i:s', time() + 10),
                    self::STATE_SYNC_NAME,
                    self::STATE_PHASE
                );
                throw new \RuntimeException("Abortando chunking por excesso de falhas em $dayStart");
            }

            $current = strtotime('+1 day', $current);
            $state = null;
        }

        $this->canonical->markSyncCompleted(
            $start,
            $end,
            $fonte,
            $vendorCount,
            $orderStats,
            $end,
            1,
            0,
            self::STATE_SYNC_NAME,
            self::STATE_PHASE
        );

        return [
            'orders' => $orderStats,
            'divergences' => $divergenceCount,
        ];
    }

    /**
     * Processa a paginacao de 1 unico dia.
     *
     * @return array{
     *     orders: array{inserted: int, updated: int, unchanged: int, skipped: int},
     *     divergences: int,
     *     checkpoint_page: int,
     *     checkpoint_batch: int
     * }
     */
    private function processDayBatch(string $dayStart, string $dayEnd, int $startPage, string $fonte): array
    {
        $checkpointPage = $startPage;
        $checkpointBatch = 0;
        $orderStats = $this->emptyOrderStats();
        $divergenceCount = 0;

        while (true) {
            $rawOrders = $this->omie->listOrdersPipeline($dayStart, $dayEnd);

            if (empty($rawOrders)) {
                break;
            }

            $clientMap = $this->buildClientMap($rawOrders);
            $chunks = array_chunk($rawOrders, 50);

            foreach ($chunks as $chunk) {
                foreach ($chunk as $raw) {
                    if (!is_array($raw)) {
                        $orderStats['skipped']++;
                        continue;
                    }

                    $canonical = $this->normalizeToCanonical($raw, $clientMap, $fonte);
                    if ($canonical === null) {
                        $orderStats['skipped']++;
                        continue;
                    }

                    $divergenceCount += $this->detectDivergences($canonical);

                    $result = $this->canonical->upsertOrder($canonical);
                    if (isset($orderStats[$result])) {
                        $orderStats[$result]++;
                    }
                }

                $checkpointBatch++;
            }

            break;
        }

        return [
            'orders' => $orderStats,
            'divergences' => $divergenceCount,
            'checkpoint_page' => $checkpointPage,
            'checkpoint_batch' => $checkpointBatch,
        ];
    }

    /**
     * Sincroniza apenas o cadastro de vendedores.
     */
    public function syncVendors(): int
    {
        $vendors = $this->omie->listVendors();
        $count = 0;

        $this->vendorMap = [];
        foreach ($vendors as $vendor) {
            if (!is_array($vendor)) {
                continue;
            }

            $codigo = trim((string) ($vendor['codigo'] ?? ''));
            $nome = trim((string) ($vendor['nome'] ?? ''));
            if ($codigo === '' || $nome === '') {
                continue;
            }

            $email = trim((string) ($vendor['email'] ?? ''));
            $comissao = Support::toFloat($vendor['comissao'] ?? 0);

            $this->canonical->upsertVendor($codigo, $nome, $email, $comissao);
            $this->vendorMap[$codigo] = $nome;
            $count++;
        }

        $this->logger->info('Vendedores sincronizados.', ['total' => $count]);
        return $count;
    }

    /**
     * Normaliza um pedido bruto do Omie para a estrutura canonica.
     */
    private function normalizeToCanonical(array $raw, array $clientMap, string $fonte): ?array
    {
        $pedidoIdOmie = (int) Support::getByPaths($raw, [
            'cabecalho.codigo_pedido',
            'codigo_pedido',
        ], 0);

        if ($pedidoIdOmie <= 0) {
            return null;
        }

        $codigoPedido = (string) Support::getByPaths($raw, [
            'cabecalho.numero_pedido',
            'codigo_pedido',
            'cabecalho.codigo_pedido_integracao',
        ], '');

        $codigoIntegracao = (string) Support::getByPaths($raw, [
            'cabecalho.codigo_pedido_integracao',
            'codigo_pedido_integracao',
        ], '');

        $vendorId = (string) $this->extractVendorId($raw);
        $vendorName = $this->resolveVendorName($vendorId, $raw);

        $clientCode = $this->extractClientCode($raw);
        $clientData = $clientMap[(string) $clientCode] ?? [];
        $clientName = $this->resolveClientName($raw, $clientData);
        $clientDoc = $this->resolveClientDocument($raw, $clientData);

        $etapa = (string) Support::getByPaths($raw, ['cabecalho.etapa'], '');
        $statusPedido = (string) Support::getByPaths($raw, ['status_pedido', 'situacao'], '');
        $statusComercial = $this->classifyStatus($etapa, $statusPedido);

        $dataEmissao = Support::formatDate((string) Support::getByPaths($raw, [
            'cabecalho.data_previsao',
            'infoCadastro.dInc',
            'data_previsao',
        ], ''));

        $dataFaturamento = Support::formatDate((string) Support::getByPaths($raw, [
            'infoCadastro.dFat',
            'cabecalho.data_faturamento',
            'faturamento.data_faturamento',
            'data_faturamento',
        ], ''));
        if ($dataFaturamento === null) {
            $dataFaturamento = Support::formatDate((string) Support::findFirstByKeys($raw, ['dFat', 'data_faturamento'], ''));
        }

        $dataCancelamento = null;
        if ($statusComercial === 'cancelado') {
            $dataCancelamento = Support::formatDate((string) Support::getByPaths($raw, [
                'infoCadastro.dCan',
                'data_cancelamento',
            ], ''));
        }

        $valorTotal = Support::toFloat(Support::getByPaths($raw, ['total_pedido.valor_total_pedido', 'valor_total_pedido'], 0));
        $valorMercadorias = Support::toFloat(Support::getByPaths($raw, ['total_pedido.valor_mercadorias', 'valor_mercadorias'], 0));
        $valorDescontos = Support::toFloat(Support::getByPaths($raw, ['total_pedido.valor_descontos', 'valor_descontos', 'valor_desconto_pedido'], 0));

        if ($valorMercadorias <= 0 || $valorTotal <= 0) {
            $itemMerc = 0.0;
            $itemDesc = 0.0;
            foreach (($raw['det'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $itemMerc += Support::toFloat(Support::getByPaths($item, ['produto.valor_mercadoria', 'valor_mercadorias', 'valor_total'], 0));
                $itemDesc += Support::toFloat(Support::getByPaths($item, ['produto.valor_desconto', 'valor_desconto'], 0));
            }
            if ($valorMercadorias <= 0) {
                $valorMercadorias = $itemMerc;
            }
            if ($valorDescontos <= 0) {
                $valorDescontos = $itemDesc;
            }
            if ($valorTotal <= 0) {
                $valorTotal = max($valorMercadorias - $valorDescontos, 0.0);
            }
        }

        $qtdItens = (int) Support::getByPaths($raw, ['cabecalho.quantidade_itens', 'quantidade_itens'], 0);
        if ($qtdItens <= 0) {
            $qtdItens = count($raw['det'] ?? []);
        }

        return [
            'pedido_id_omie' => $pedidoIdOmie,
            'codigo_pedido' => $codigoPedido,
            'codigo_integracao' => $codigoIntegracao ?: null,
            'codigo_vendedor_omie' => $vendorId,
            'nome_vendedor' => $vendorName,
            'codigo_cliente_omie' => $clientCode > 0 ? $clientCode : null,
            'nome_cliente' => $clientName,
            'documento_cliente' => $clientDoc ?: null,
            'status_comercial' => $statusComercial,
            'etapa_omie' => $etapa ?: null,
            'status_pedido_omie' => $statusPedido ?: null,
            'data_emissao' => $dataEmissao,
            'data_faturamento' => $dataFaturamento,
            'data_cancelamento' => $dataCancelamento,
            'valor_total' => $valorTotal,
            'valor_mercadorias' => $valorMercadorias,
            'valor_descontos' => $valorDescontos,
            'quantidade_itens' => $qtdItens,
            'fonte_sync' => $fonte,
        ];
    }

    /**
     * Um pedido recebe UM status canonico, sem ambiguidade.
     */
    private function classifyStatus(string $etapa, string $statusPedido): string
    {
        if ($etapa !== '' && ctype_digit($etapa)) {
            $code = (int) $etapa;
            if ($code >= 50 && $code <= 69) {
                return 'faturado';
            }
            if ($code >= 20 && $code <= 49) {
                return 'autorizado';
            }
            if ($code >= 70) {
                return 'cancelado';
            }
            if ($code <= 19) {
                return 'outro';
            }
        }

        $n = mb_strtolower(trim($etapa !== '' ? $etapa : $statusPedido));
        if ($n === '') {
            return 'outro';
        }

        if (str_contains($n, 'faturad') || str_contains($n, 'entregue') || str_contains($n, 'conclu')) {
            return 'faturado';
        }
        if (str_contains($n, 'autoriz') || str_contains($n, 'aprovad') || str_contains($n, 'separ') || str_contains($n, 'pedido')) {
            return 'autorizado';
        }
        if (str_contains($n, 'cancel') || str_contains($n, 'devolv') || str_contains($n, 'recusad') || str_contains($n, 'denegad') || str_contains($n, 'rejeit')) {
            return 'cancelado';
        }

        if ($etapa !== '' && $statusPedido !== '') {
            return $this->classifyStatus('', $statusPedido);
        }

        return 'outro';
    }

    private function extractVendorId(array $raw): string
    {
        $id = Support::getByPaths($raw, [
            'informacoes_adicionais.codVend',
            'cabecalho.codVend',
            'vendedor.codigo',
            'vendedor.codVend',
            'codVend',
            'nVendedor',
        ], null);

        if ($id === null || $id === '') {
            $id = Support::findFirstByKeys($raw, ['codVend', 'nVendedor', 'codigo_vendedor', 'codigo_vendedor_omie'], '');
        }

        return trim((string) $id);
    }

    private function resolveVendorName(string $vendorId, array $raw): string
    {
        if ($vendorId !== '' && isset($this->vendorMap[$vendorId])) {
            return $this->vendorMap[$vendorId];
        }

        $name = trim((string) Support::getByPaths($raw, [
            'informacoes_adicionais.nome_vendedor',
            'vendedor.nome',
            'nome_vendedor',
            'Vendedor',
        ], ''));

        if ($name === '') {
            $name = trim((string) Support::findFirstByKeys($raw, ['Vendedor', 'nome_vendedor'], ''));
        }

        return $name !== '' ? $name : 'Sem vendedor';
    }

    private function extractClientCode(array $raw): int
    {
        $code = Support::getByPaths($raw, [
            'cabecalho.codigo_cliente',
            'cliente.codigo_cliente_omie',
            'cliente.codigo_cliente',
            'codigo_cliente',
            'codigo_cliente_omie',
        ], null);

        if ($code === null || $code === '') {
            $code = Support::findFirstByKeys($raw, ['codigo_cliente', 'codigo_cliente_omie'], 0);
        }

        return (int) $code;
    }

    private function resolveClientName(array $raw, array $clientData): string
    {
        $name = trim((string) Support::getByPaths($clientData, ['razao_social', 'nome_fantasia'], ''));
        if ($name !== '') {
            return $name;
        }

        $name = trim((string) Support::getByPaths($raw, [
            'cliente.razao_social',
            'cliente.nome_fantasia',
            'cabecalho.nome_cliente',
            'nome_fantasia',
            'razao_social',
        ], ''));

        return $name !== '' ? $name : 'Cliente nao informado';
    }

    private function resolveClientDocument(array $raw, array $clientData): string
    {
        $doc = trim((string) Support::getByPaths($clientData, ['cnpj_cpf'], ''));
        if ($doc !== '') {
            return $doc;
        }

        return trim((string) Support::getByPaths($raw, [
            'cliente.cnpj_cpf',
            'cliente.cpf_cnpj',
            'cnpj_cpf',
        ], ''));
    }

    private function buildClientMap(array $orders): array
    {
        $codes = [];
        foreach ($orders as $order) {
            if (!is_array($order)) {
                continue;
            }
            $code = $this->extractClientCode($order);
            if ($code > 0) {
                $codes[] = $code;
            }
        }

        return $codes !== [] ? $this->omie->listClientsSummaryByCodes($codes) : [];
    }

    private function detectDivergences(array $canonical): int
    {
        $count = 0;

        $vendorId = $canonical['codigo_vendedor_omie'] ?? '';
        if ($vendorId !== '' && !isset($this->vendorMap[$vendorId])) {
            $this->canonical->logDivergence(
                'vendedor_nao_cadastrado',
                'vendor:' . $vendorId,
                sprintf(
                    'Pedido %s referencia vendedor %s que nao existe no cadastro Omie.',
                    $canonical['codigo_pedido'] ?? '?',
                    $vendorId
                ),
                ['pedido_id' => $canonical['pedido_id_omie'], 'nome_no_pedido' => $canonical['nome_vendedor']]
            );
            $count++;
        }

        if ($vendorId === '') {
            $this->canonical->logDivergence(
                'pedido_sem_vendedor',
                'pedido:' . ($canonical['pedido_id_omie'] ?? '?'),
                sprintf(
                    'Pedido %s nao tem codigo de vendedor.',
                    $canonical['codigo_pedido'] ?? '?'
                ),
                ['pedido_id' => $canonical['pedido_id_omie']]
            );
            $count++;
        }

        if (($canonical['status_comercial'] ?? '') === 'faturado' && empty($canonical['data_faturamento'])) {
            $this->canonical->logDivergence(
                'faturado_sem_data',
                'pedido:' . ($canonical['pedido_id_omie'] ?? '?'),
                sprintf(
                    'Pedido %s classificado como faturado mas sem data de faturamento.',
                    $canonical['codigo_pedido'] ?? '?'
                ),
                ['pedido_id' => $canonical['pedido_id_omie']]
            );
            $count++;
        }

        return $count;
    }

    /**
     * @return array{inserted: int, updated: int, unchanged: int, skipped: int}
     */
    private function emptyOrderStats(): array
    {
        return [
            'inserted' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'skipped' => 0,
        ];
    }

    /**
     * @param array{inserted?: int, updated?: int, unchanged?: int, skipped?: int} $current
     * @param array{inserted?: int, updated?: int, unchanged?: int, skipped?: int} $delta
     * @return array{inserted: int, updated: int, unchanged: int, skipped: int}
     */
    private function mergeOrderStats(array $current, array $delta): array
    {
        return [
            'inserted' => (int) ($current['inserted'] ?? 0) + (int) ($delta['inserted'] ?? 0),
            'updated' => (int) ($current['updated'] ?? 0) + (int) ($delta['updated'] ?? 0),
            'unchanged' => (int) ($current['unchanged'] ?? 0) + (int) ($delta['unchanged'] ?? 0),
            'skipped' => (int) ($current['skipped'] ?? 0) + (int) ($delta['skipped'] ?? 0),
        ];
    }

    /**
     * @param array{inserted?: int, updated?: int, unchanged?: int, skipped?: int} $stats
     */
    private function sumOrderStats(array $stats): int
    {
        return (int) ($stats['inserted'] ?? 0)
            + (int) ($stats['updated'] ?? 0)
            + (int) ($stats['unchanged'] ?? 0)
            + (int) ($stats['skipped'] ?? 0);
    }
}

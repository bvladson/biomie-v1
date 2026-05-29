<?php

declare(strict_types=1);

namespace BIomie\Sync;

use BIomie\CanonicalRepository;
use BIomie\GoldMartBuilder;
use BIomie\Logger;
use BIomie\OmieRepository;
use BIomie\Support;
use BIomie\Support\OmieVendorStatusMapper;
use Throwable;

/**
 * Coordenador de sincronização do domínio comercial (vendedores + pedidos).
 *
 * Substitui OmieSyncService como implementação canônica.
 * Normaliza pedidos Omie -> canonical, persiste via CanonicalRepository,
 * detecta divergências e usa ChunkedWindowRunner para o loop diário.
 */
final class CommercialSyncCoordinator
{
    /** @var array<string,string> */
    private array $vendorMap = [];

    public function __construct(
        private readonly OmieRepository $omie,
        private readonly CanonicalRepository $canonical,
        private readonly Logger $logger,
        private readonly ChunkedWindowRunner $chunker,
        private readonly ?GoldMartBuilder $goldMart = null,
    ) {
    }

    /**
     * @return array{
     *   vendors_synced: int,
     *   orders: array{inserted:int, updated:int, unchanged:int, skipped:int},
     *   orders_checked: int,
     *   divergences: int,
     *   duration_seconds: float
     * }
     */
    public function syncPeriod(string $start, string $end, string $fonte = 'cron'): array
    {
        $t0 = microtime(true);

        $this->logger->info('CommercialSyncCoordinator: sincronização iniciada.', [
            'start' => $start,
            'end'   => $end,
            'fonte' => $fonte,
        ]);

        $vendorCount = $this->syncVendors();
        $orderStats  = $this->emptyStats();
        $divergences = 0;

        $run = $this->chunker->walk($start, $end, function (string $day) use ($fonte): array {
            return $this->processDayOrders($day, $fonte);
        });

        foreach ($run['results'] as $dayResult) {
            $orderStats  = $this->mergeStats($orderStats, (array) ($dayResult['orders'] ?? []));
            $divergences += (int) ($dayResult['divergences'] ?? 0);
        }

        $duration = round(microtime(true) - $t0, 2);

        $this->logger->info('CommercialSyncCoordinator: sincronização finalizada.', [
            'duration_seconds' => $duration,
            'vendors'          => $vendorCount,
            'orders'           => $orderStats,
            'divergences'      => $divergences,
        ]);

        if ($this->goldMart !== null) {
            try {
                $this->goldMart->refreshPeriod($start, $end);
            } catch (Throwable $e) {
                $this->logger->warning('CommercialSyncCoordinator: GoldMart refresh falhou; mart pode estar desatualizado.', [
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return [
            'vendors_synced'   => $vendorCount,
            'orders'           => $orderStats,
            'orders_checked'   => $this->sumStats($orderStats),
            'divergences'      => $divergences,
            'duration_seconds' => $duration,
        ];
    }

    public function syncVendors(): int
    {
        $vendors = $this->omie->listVendors();
        $count   = 0;
        $statusMapper = new OmieVendorStatusMapper();

        $this->vendorMap = [];
        foreach ($vendors as $vendor) {
            if (!is_array($vendor)) {
                continue;
            }

            $codigo = trim((string) ($vendor['codigo'] ?? ''));
            $nome   = trim((string) ($vendor['nome'] ?? ''));
            if ($codigo === '' || $nome === '') {
                continue;
            }

            $email    = trim((string) ($vendor['email'] ?? ''));
            $comissao = Support::toFloat($vendor['comissao'] ?? 0);
            $ativo    = $statusMapper->isActive($vendor, true);

            $this->canonical->upsertVendor($codigo, $nome, $email, $comissao, $ativo);
            $this->vendorMap[$codigo] = $nome;
            $count++;
        }

        $this->logger->info('Vendedores sincronizados (commercial).', ['total' => $count]);

        return $count;
    }

    /**
     * @return array{orders:array{inserted:int,updated:int,unchanged:int,skipped:int},divergences:int}
     */
    private function processDayOrders(string $day, string $fonte): array
    {
        $rawOrders   = $this->omie->listOrdersPipeline($day, $day);
        $clientMap   = $this->buildClientMap($rawOrders);
        $orderStats  = $this->emptyStats();
        $divergences = 0;

        foreach ($rawOrders as $raw) {
            if (!is_array($raw)) {
                $orderStats['skipped']++;
                continue;
            }

            $canonical = $this->normalizeOrder($raw, $clientMap, $fonte);
            if ($canonical === null) {
                $orderStats['skipped']++;
                continue;
            }

            $divergences += $this->detectDivergences($canonical);

            $result = $this->canonical->upsertOrder($canonical);
            if (isset($orderStats[$result])) {
                $orderStats[$result]++;
            }
        }

        return ['orders' => $orderStats, 'divergences' => $divergences];
    }

    /**
     * @param array<string,mixed> $raw
     * @param array<string,array<string,mixed>> $clientMap
     * @return array<string,mixed>|null
     */
    private function normalizeOrder(array $raw, array $clientMap, string $fonte): ?array
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

        $vendorId   = (string) $this->extractVendorId($raw);
        $vendorName = $this->resolveVendorName($vendorId, $raw);

        $clientCode = $this->extractClientCode($raw);
        $clientData = $clientMap[(string) $clientCode] ?? [];
        $clientName = $this->resolveClientName($raw, $clientData);
        $clientDoc  = $this->resolveClientDocument($raw, $clientData);

        $etapa           = (string) Support::getByPaths($raw, ['cabecalho.etapa'], '');
        $statusPedido    = (string) Support::getByPaths($raw, ['status_pedido', 'situacao'], '');
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
            $dataFaturamento = Support::formatDate(
                (string) Support::findFirstByKeys($raw, ['dFat', 'data_faturamento'], '')
            );
        }

        $dataCancelamento = null;
        if ($statusComercial === 'cancelado') {
            $dataCancelamento = Support::formatDate((string) Support::getByPaths($raw, [
                'infoCadastro.dCan',
                'data_cancelamento',
            ], ''));
        }

        $valorTotal       = Support::toFloat(Support::getByPaths($raw, ['total_pedido.valor_total_pedido', 'valor_total_pedido'], 0));
        $valorMercadorias = Support::toFloat(Support::getByPaths($raw, ['total_pedido.valor_mercadorias', 'valor_mercadorias'], 0));
        $valorDescontos   = Support::toFloat(Support::getByPaths($raw, ['total_pedido.valor_descontos', 'valor_descontos', 'valor_desconto_pedido'], 0));

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
            'pedido_id_omie'       => $pedidoIdOmie,
            'codigo_pedido'        => $codigoPedido,
            'codigo_integracao'    => $codigoIntegracao ?: null,
            'codigo_vendedor_omie' => $vendorId,
            'nome_vendedor'        => $vendorName,
            'codigo_cliente_omie'  => $clientCode > 0 ? $clientCode : null,
            'nome_cliente'         => $clientName,
            'documento_cliente'    => $clientDoc ?: null,
            'status_comercial'     => $statusComercial,
            'etapa_omie'           => $etapa ?: null,
            'status_pedido_omie'   => $statusPedido ?: null,
            'data_emissao'         => $dataEmissao,
            'data_faturamento'     => $dataFaturamento,
            'data_cancelamento'    => $dataCancelamento,
            'valor_total'          => $valorTotal,
            'valor_mercadorias'    => $valorMercadorias,
            'valor_descontos'      => $valorDescontos,
            'quantidade_itens'     => $qtdItens,
            'fonte_sync'           => $fonte,
        ];
    }

    /**
     * Classifica etapa/status em valor ENUM ('faturado','autorizado','cancelado','outro').
     *
     * COM.R1.2: etapa 60 (Entrega Finalizada) retorna 'outro', NÃO 'faturado'.
     * Para classificação semântica estendida use CommercialPipelineClassifier.
     */
    private function classifyStatus(string $etapa, string $statusPedido): string
    {
        if ($etapa !== '' && ctype_digit($etapa)) {
            $code = (int) $etapa;
            // etapa 60 = Entrega Finalizada — pós-venda, NÃO novo faturamento
            if ($code === 60) {
                return 'outro';
            }
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

        // entregue/entrega finalizada → 'outro' (pós-venda, não faturamento novo)
        if (str_contains($n, 'entregue') || str_contains($n, 'entrega finalizada') || str_contains($n, 'conclu')) {
            return 'outro';
        }
        if (str_contains($n, 'faturad')) {
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

    /**
     * @param list<array<string,mixed>> $orders
     * @return array<string,array<string,mixed>>
     */
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
        $count    = 0;
        $vendorId = (string) ($canonical['codigo_vendedor_omie'] ?? '');

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
                sprintf('Pedido %s nao tem codigo de vendedor.', $canonical['codigo_pedido'] ?? '?'),
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

    /** @return array{inserted:int,updated:int,unchanged:int,skipped:int} */
    private function emptyStats(): array
    {
        return ['inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];
    }

    /**
     * @param array{inserted?:int,updated?:int,unchanged?:int,skipped?:int} $current
     * @param array{inserted?:int,updated?:int,unchanged?:int,skipped?:int} $delta
     * @return array{inserted:int,updated:int,unchanged:int,skipped:int}
     */
    private function mergeStats(array $current, array $delta): array
    {
        return [
            'inserted'  => (int) ($current['inserted']  ?? 0) + (int) ($delta['inserted']  ?? 0),
            'updated'   => (int) ($current['updated']   ?? 0) + (int) ($delta['updated']   ?? 0),
            'unchanged' => (int) ($current['unchanged'] ?? 0) + (int) ($delta['unchanged'] ?? 0),
            'skipped'   => (int) ($current['skipped']   ?? 0) + (int) ($delta['skipped']   ?? 0),
        ];
    }

    /** @param array{inserted?:int,updated?:int,unchanged?:int,skipped?:int} $stats */
    private function sumStats(array $stats): int
    {
        return (int) ($stats['inserted']  ?? 0)
            + (int) ($stats['updated']   ?? 0)
            + (int) ($stats['unchanged'] ?? 0)
            + (int) ($stats['skipped']   ?? 0);
    }
}

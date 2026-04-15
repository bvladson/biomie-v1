<?php

declare(strict_types=1);

namespace BIomie;

final class OmieRepository
{
    public function __construct(
        private readonly OmieClient $client,
        private readonly Config $config,
        private readonly ?BronzeRepository $bronze = null,
        private readonly string $bronzeSource = 'runtime',
    ) {
    }

    public function listVendors(): array
    {
        $endpoint = (string) $this->config->get('omie.endpoints.vendors');
        $pageSize = $this->pageSizeFor('ListarVendedores');

        return $this->paginateVendors($endpoint, $pageSize);
    }

    public function listOrders(string $start, string $end): array
    {
        $endpoint = (string) $this->config->get('omie.endpoints.orders');
        $pageSize = $this->pageSizeFor('ListarPedidos');

        return $this->paginateOrders($endpoint, $pageSize, [
            'status_pedido' => 'FATURADO',
            'data_faturamento_de' => date('d/m/Y', strtotime($start)),
            'data_faturamento_ate' => date('d/m/Y', strtotime($end)),
        ]);
    }

    /**
     * Lista NF-e do período.
     *
     * Status Omie:
     * N = não cancelada
     * C = cancelada
     *
     * Base documental oficial:
     * https://app.omie.com.br/api/v1/produtos/nfconsultar/
     */
    public function listInvoices(string $start, string $end, string $status = 'N'): array
    {
        $endpoint = (string) $this->config->get('omie.endpoints.invoices');
        $pageSize = $this->pageSizeFor('ListarNF');

        if ($endpoint === '') {
            throw new \RuntimeException('Endpoint de NF-e nao configurado.');
        }

        $filters = $this->baseInvoiceFilters($status);

        if ($filters['filtrar_por_status'] === 'C') {
            $filters['dCanInicial'] = date('d/m/Y', strtotime($start));
            $filters['dCanFinal'] = date('d/m/Y', strtotime($end));
        } else {
            $filters['dEmiInicial'] = date('d/m/Y', strtotime($start));
            $filters['dEmiFinal'] = date('d/m/Y', strtotime($end));
        }

        return $this->paginateInvoices($endpoint, $pageSize, $filters);
    }

    /**
     * Lista NF-e do periodo usando data de emissao para todos os status.
     * Esta leitura existe para conciliacao fiscal Omie x BIomie e nao deve
     * substituir o sync operacional, que continua tratando canceladas por data
     * de cancelamento.
     */
    public function listInvoicesByIssueDate(string $start, string $end, string $status = 'N'): array
    {
        $endpoint = (string) $this->config->get('omie.endpoints.invoices');
        $pageSize = $this->pageSizeFor('ListarNF');

        if ($endpoint === '') {
            throw new \RuntimeException('Endpoint de NF-e nao configurado.');
        }

        $filters = $this->baseInvoiceFilters($status);
        $filters['dEmiInicial'] = date('d/m/Y', strtotime($start));
        $filters['dEmiFinal'] = date('d/m/Y', strtotime($end));

        return $this->paginateInvoices($endpoint, $pageSize, $filters);
    }

    /**
     * Lista apenas pedidos ainda abertos para compor backlog/carteira.
     */
    public function listPendingOrders(string $start, string $end): array
    {
        $endpoint = (string) $this->config->get('omie.endpoints.orders');
        $pageSize = $this->pageSizeFor('ListarPedidos');

        return $this->paginateOrders($endpoint, $pageSize, [
            'data_previsao_de' => date('d/m/Y', strtotime($start)),
            'data_previsao_ate' => date('d/m/Y', strtotime($end)),
        ]);
    }

    /**
     * Pipeline completo: faturado + autorizado + cancelado.
     * Faz 2 queries: faturado (por data_faturamento) + restante (por data_previsao).
     * Deduplicada por codigo_pedido.
     */
    public function listOrdersPipeline(string $start, string $end): array
    {
        $endpoint = (string) $this->config->get('omie.endpoints.orders');
        $pageSize = $this->pageSizeFor('ListarPedidos');
        $seen = [];
        $all = [];

        // 1) Faturados — busca por data de faturamento
        foreach ($this->paginateOrders($endpoint, $pageSize, [
            'status_pedido' => 'FATURADO',
            'data_faturamento_de' => date('d/m/Y', strtotime($start)),
            'data_faturamento_ate' => date('d/m/Y', strtotime($end)),
        ]) as $order) {
            $key = $this->orderKey($order);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $all[] = $order;
            }
        }

        // 2) Não-faturados — busca por data de previsão (inclui autorizado, cancelado, etc.)
        foreach ($this->paginateOrders($endpoint, $pageSize, [
            'data_previsao_de' => date('d/m/Y', strtotime($start)),
            'data_previsao_ate' => date('d/m/Y', strtotime($end)),
        ]) as $order) {
            $key = $this->orderKey($order);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $all[] = $order;
            }
        }

        return $all;
    }

    /**
     * Lista a linha do tempo de etapas do pedido de venda.
     *
     * Base documental oficial:
     * https://app.omie.com.br/api/v1/produtos/pedidoetapas/
     *
     * @return list<array<string,mixed>>
     */
    public function listOrderStageEvents(
        string $start,
        string $end,
        string $startTime = '00:00:00',
        string $endTime = '23:59:59',
    ): array {
        $endpoint = trim((string) $this->config->get('omie.endpoints.order_stages', 'https://app.omie.com.br/api/v1/produtos/pedidoetapas/'));
        if ($endpoint === '') {
            throw new \RuntimeException('Endpoint de etapas de pedido nao configurado.');
        }

        $pageSize = $this->pageSizeFor('ListarEtapasPedido');
        $page = 1;
        $events = [];

        do {
            $request = [
                'nPagina' => $page,
                'nRegPorPagina' => $pageSize,
                'cOrdenarPor' => 'DATAHORA',
                'cOrdemDecrescente' => 'N',
                'dDtInicial' => date('d/m/Y', strtotime($start)),
                'dDtFinal' => date('d/m/Y', strtotime($end)),
                'cHrInicial' => $startTime,
                'cHrFinal' => $endTime,
            ];

            $response = $this->client->post($endpoint, 'ListarEtapasPedido', $request);
            $batch = $response['etapasPedido'] ?? [];
            if (is_array($batch)) {
                foreach ($batch as $item) {
                    if (is_array($item)) {
                        $events[] = $item;
                    }
                }

                $this->captureBronzeBatch(
                    'order_events',
                    'order_stage',
                    $endpoint,
                    'ListarEtapasPedido',
                    $request,
                    $response,
                    $batch,
                    fn (array $item): ?string => $this->extractOrderStageNaturalKey($item),
                    fn (array $item): ?string => $this->extractOrderStageProviderKey($item),
                );
            }

            $totalPages = (int) ($response['nTotPaginas'] ?? 1);
            $page++;
        } while ($page <= max($totalPages, 1));

        return $events;
    }

    private function paginateOrders(string $endpoint, int $pageSize, array $filters): array
    {
        $page = 1;
        $orders = [];

        do {
            $response = $this->client->post($endpoint, 'ListarPedidos', array_merge([
                'pagina' => $page,
                'registros_por_pagina' => $pageSize,
                'apenas_importado_api' => 'N',
            ], $filters));

            $batch = $response['pedido_venda_produto'] ?? [];
            if (is_array($batch)) {
                foreach ($batch as $item) {
                    if (is_array($item)) {
                        $orders[] = $item;
                    }
                }

                $this->captureBronzeBatch(
                    'orders',
                    'order',
                    $endpoint,
                    'ListarPedidos',
                    array_merge([
                        'pagina' => $page,
                        'registros_por_pagina' => $pageSize,
                        'apenas_importado_api' => 'N',
                    ], $filters),
                    $response,
                    $batch,
                    fn (array $item): ?string => $this->extractOrderNaturalKey($item),
                    fn (array $item): ?string => $this->extractOrderProviderKey($item),
                );
            }

            $totalPages = (int) ($response['total_de_paginas'] ?? 1);
            $page++;
        } while ($page <= max($totalPages, 1));

        return $orders;
    }

    private function paginateInvoices(string $endpoint, int $pageSize, array $filters): array
    {
        $page = 1;
        $invoices = [];

        do {
            $response = $this->client->post($endpoint, 'ListarNF', array_merge([
                'pagina' => $page,
                'registros_por_pagina' => $pageSize,
            ], $filters));

            $batch = $response['nfCadastro'] ?? [];
            if (is_array($batch)) {
                foreach ($batch as $item) {
                    if (is_array($item)) {
                        $invoices[] = $item;
                    }
                }

                $this->captureBronzeBatch(
                    'invoices',
                    'invoice',
                    $endpoint,
                    'ListarNF',
                    array_merge([
                        'pagina' => $page,
                        'registros_por_pagina' => $pageSize,
                    ], $filters),
                    $response,
                    $batch,
                    fn (array $item): ?string => $this->extractInvoiceNaturalKey($item),
                    fn (array $item): ?string => $this->extractInvoiceProviderKey($item),
                );
            }

            $totalPages = (int) ($response['total_de_paginas'] ?? 1);
            $page++;
        } while ($page <= max($totalPages, 1));

        return $invoices;
    }

    private function baseInvoiceFilters(string $status): array
    {
        return [
            'filtrar_por_status' => strtoupper($status) === 'C' ? 'C' : 'N',
            'tpNF' => '1',
            'cDetalhesPedido' => 'S',
            'cApenasResumo' => 'N',
            'ordenar_por' => 'DATA_LANCAMENTO',
            'ordem_decrescente' => 'N',
        ];
    }

    private function orderKey(array $order): string
    {
        $code = Support::getByPaths($order, [
            'cabecalho.numero_pedido',
            'cabecalho.codigo_pedido',
            'codigo_pedido',
        ], '');
        return (string) ($code !== '' ? $code : spl_object_id((object) $order));
    }

    public function listClientsSummaryByCodes(array $clientCodes): array
    {
        $codes = array_values(array_unique(array_filter(array_map(static fn (mixed $code): int => (int) $code, $clientCodes))));
        if ($codes === []) {
            return [];
        }

        $endpoint = (string) $this->config->get('omie.endpoints.clients');
        $pageSize = max($this->pageSizeFor('ListarClientesResumido'), 100);
        $clients = [];

        foreach (array_chunk($codes, 100) as $chunk) {
            $request = [
                'pagina' => 1,
                'registros_por_pagina' => $pageSize,
                'apenas_importado_api' => 'N',
                'clientesPorCodigo' => array_map(static fn (int $code): array => [
                    'codigo_cliente_omie' => $code,
                ], $chunk),
            ];

            $response = $this->client->post($endpoint, 'ListarClientesResumido', $request);
            $batch = $response['clientes_cadastro_resumido'] ?? [];
            if (!is_array($batch) || $batch === []) {
                $response = $this->client->post($endpoint, 'ListarClientes', $request);
                $batch = $response['clientes_cadastro'] ?? [];
                $call = 'ListarClientes';
            } else {
                $call = 'ListarClientesResumido';
            }

            if (is_array($batch)) {
                foreach ($batch as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $key = (string) ($item['codigo_cliente'] ?? $item['codigo_cliente_omie'] ?? '');
                    if ($key !== '') {
                        $clients[$key] = $item;
                    }
                }

                $this->captureBronzeBatch(
                    'clients',
                    'client',
                    $endpoint,
                    $call,
                    $request,
                    $response,
                    $batch,
                    fn (array $item): ?string => $this->extractClientNaturalKey($item),
                    fn (array $item): ?string => $this->extractClientProviderKey($item),
                );
            }

            // Clientes não encontrados no batch são ignorados.
            // Antes havia consultas individuais (consultClientByCode) que causavam
            // N+1 requests HTTP ao Omie, travando o carregamento por minutos.
        }

        return $clients;
    }

    private function consultClientByCode(int $clientCode): ?array
    {
        if ($clientCode <= 0) {
            return null;
        }

        $endpoint = (string) $this->config->get('omie.endpoints.clients');
        $response = $this->client->post($endpoint, 'ConsultarCliente', [
            'codigo_cliente_omie' => $clientCode,
        ]);

        if (is_array($response)) {
            $this->captureBronzeBatch(
                'clients',
                'client',
                $endpoint,
                'ConsultarCliente',
                ['codigo_cliente_omie' => $clientCode],
                $response,
                [$response],
                fn (array $item): ?string => $this->extractClientNaturalKey($item),
                fn (array $item): ?string => $this->extractClientProviderKey($item),
            );
        }

        return is_array($response) ? $response : null;
    }

    private function paginateVendors(string $endpoint, int $pageSize): array
    {
        $page = 1;
        $vendors = [];

        do {
            $request = [
                'pagina' => $page,
                'registros_por_pagina' => $pageSize,
                'apenas_importado_api' => 'N',
            ];
            $response = $this->client->post($endpoint, 'ListarVendedores', $request);

            $batch = $response['cadastro'] ?? [];
            if (is_array($batch)) {
                foreach ($batch as $item) {
                    if (is_array($item)) {
                        $vendors[] = $item;
                    }
                }

                $this->captureBronzeBatch(
                    'vendors',
                    'vendor',
                    $endpoint,
                    'ListarVendedores',
                    $request,
                    $response,
                    $batch,
                    fn (array $item): ?string => $this->extractVendorNaturalKey($item),
                    fn (array $item): ?string => $this->extractVendorProviderKey($item),
                );
            }

            $totalPages = (int) ($response['total_de_paginas'] ?? 1);
            $page++;
        } while ($page <= max($totalPages, 1));

        return $vendors;
    }

    private function methodPolicies(): OmieMethodPolicyRegistry
    {
        return new OmieMethodPolicyRegistry($this->config);
    }

    private function pageSizeFor(string $call): int
    {
        $configured = (int) $this->config->get('omie.default_page_size', 100);
        $resolved = $this->methodPolicies()->pageSize($call, $configured);

        return max(1, min(100, $resolved));
    }

    /**
     * @param list<mixed> $batch
     * @param callable(array<string,mixed>):?string $naturalKeyResolver
     * @param callable(array<string,mixed>):?string $providerKeyResolver
     * @return void
     */
    private function captureBronzeBatch(
        string $domain,
        string $entityName,
        string $endpoint,
        string $omieMethod,
        array $requestParams,
        array $response,
        array $batch,
        callable $naturalKeyResolver,
        callable $providerKeyResolver,
    ): void {
        if ($this->bronze === null) {
            return;
        }

        $metadata = [
            'page' => (int) ($requestParams['pagina'] ?? $requestParams['nPagina'] ?? 1),
            'total_pages' => (int) ($response['total_de_paginas'] ?? $response['nTotPaginas'] ?? 1),
            'route' => RequestContext::route(),
        ];

        foreach ($batch as $item) {
            if (!is_array($item)) {
                continue;
            }

            $this->bronze->captureRecord(
                $domain,
                $entityName,
                $endpoint,
                $omieMethod,
                $requestParams,
                $item,
                $naturalKeyResolver($item),
                $providerKeyResolver($item),
                $metadata,
                $this->bronzeSource,
            );
        }
    }

    private function extractVendorNaturalKey(array $vendor): ?string
    {
        $value = trim((string) Support::getByPaths($vendor, ['codigo', 'codigo_omie'], ''));
        return $value !== '' ? $value : null;
    }

    private function extractVendorProviderKey(array $vendor): ?string
    {
        return $this->extractVendorNaturalKey($vendor);
    }

    private function extractOrderNaturalKey(array $order): ?string
    {
        $value = trim((string) Support::getByPaths($order, [
            'cabecalho.codigo_pedido',
            'codigo_pedido',
            'cabecalho.numero_pedido',
        ], ''));

        return $value !== '' ? $value : null;
    }

    private function extractOrderProviderKey(array $order): ?string
    {
        $value = trim((string) Support::getByPaths($order, [
            'cabecalho.codigo_pedido',
            'codigo_pedido',
            'cabecalho.numero_pedido',
            'pedido_venda_produto.codigo_pedido',
        ], ''));

        return $value !== '' ? $value : null;
    }

    private function extractInvoiceNaturalKey(array $invoice): ?string
    {
        $accessKey = trim((string) Support::getByPaths($invoice, [
            'compl.chave_acesso',
            'chave_nfe',
            'cabecalho.chave_acesso',
        ], ''));
        if ($accessKey !== '') {
            return $accessKey;
        }

        $number = trim((string) Support::getByPaths($invoice, [
            'cabecalho.nNumero',
            'numero_nf',
            'nNumero',
        ], ''));
        $serie = trim((string) Support::getByPaths($invoice, [
            'cabecalho.serie',
            'serie_nf',
            'serie',
        ], ''));
        $issueDate = Support::formatDate((string) Support::getByPaths($invoice, [
            'cabecalho.dEmi',
            'dEmi',
            'data_emissao',
        ], ''));

        $parts = array_values(array_filter([$number, $serie, $issueDate]));

        return $parts !== [] ? implode(':', $parts) : null;
    }

    private function extractInvoiceProviderKey(array $invoice): ?string
    {
        $value = trim((string) Support::getByPaths($invoice, [
            'cabecalho.nCodNF',
            'nCodNF',
            'codigo_lancamento_omie',
        ], ''));

        return $value !== '' ? $value : null;
    }

    private function extractClientNaturalKey(array $client): ?string
    {
        $value = trim((string) Support::getByPaths($client, [
            'codigo_cliente_omie',
            'codigo_cliente',
        ], ''));

        return $value !== '' ? $value : null;
    }

    private function extractClientProviderKey(array $client): ?string
    {
        return $this->extractClientNaturalKey($client);
    }

    private function extractOrderStageNaturalKey(array $event): ?string
    {
        $orderCode = trim((string) Support::getByPaths($event, ['cNumero', 'cCodIntPed'], ''));
        $stageCode = trim((string) Support::getByPaths($event, ['cEtapa'], ''));
        $eventAt = Support::formatDateTime(
            (string) Support::getByPaths($event, ['dDtEtapa', 'faturamento.dDtFat', 'cancelamento.dDtCanc', 'devolucao.dDtDev'], ''),
            (string) Support::getByPaths($event, ['cHrEtapa', 'faturamento.cHrFat', 'cancelamento.cHrCanc', 'devolucao.cHrDev'], '')
        );

        $parts = array_values(array_filter([$orderCode, $stageCode, $eventAt]));

        return $parts !== [] ? implode(':', $parts) : null;
    }

    private function extractOrderStageProviderKey(array $event): ?string
    {
        $value = trim((string) Support::getByPaths($event, ['nCodPed'], ''));
        return $value !== '' ? $value : null;
    }
}

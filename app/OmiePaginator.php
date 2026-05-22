<?php

declare(strict_types=1);

namespace BIomie;

final class OmiePaginator
{
    public function __construct(
        private readonly OmieClient $client,
        private readonly ?OmieMethodPolicyRegistry $policyRegistry = null,
    )
    {
    }

    /**
     * @param array<string,mixed> $baseParams
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function paginate(string $endpoint, string $call, array $baseParams = [], array $options = []): array
    {
        $startedAt = microtime(true);
        $policy = $this->policyRegistry?->forCall($call) ?? [];
        $pageParam = (string) ($options['page_param'] ?? 'pagina');
        $pageSizeParam = (string) ($options['page_size_param'] ?? 'registros_por_pagina');
        $policyPageSize = max(1, min(100, (int) ($policy['page_size_target'] ?? 100)));
        $pageSize = max(1, min(100, (int) ($options['page_size'] ?? $policyPageSize)));
        $maxPages = max(1, (int) ($options['max_pages'] ?? ($policy['pagination']['max_pages'] ?? 500)));
        $recordsKey = isset($options['records_key']) ? (string) $options['records_key'] : null;
        $dryRun = !empty($options['dry_run']) || !empty($options['no_network']);

        if ($dryRun) {
            return [
                'status' => 'dry_run',
                'call' => $call,
                'pages_expected' => 0,
                'pages_fetched' => 0,
                'records_expected' => 0,
                'records_received' => 0,
                'first_page_ms' => 0,
                'total_duration_ms' => 0,
                'records' => [],
                'errors' => [],
                'policy' => $this->policySummary($policy, $pageSize),
            ];
        }

        $records = [];
        $errors = [];
        $pagesFetched = 0;
        $pagesExpected = 1;
        $recordsExpected = null;
        $firstPageMs = null;
        $seenPages = [];

        for ($page = 1; $page <= min($pagesExpected, $maxPages); $page++) {
            if (isset($seenPages[$page])) {
                continue;
            }
            $seenPages[$page] = true;

            $params = $baseParams + [
                $pageParam => $page,
                $pageSizeParam => $pageSize,
            ];

            $pageStartedAt = microtime(true);
            $response = $this->client->postStructured($endpoint, $call, $params);
            $elapsedMs = max(0, (int) round((microtime(true) - $pageStartedAt) * 1000));
            if ($firstPageMs === null) {
                $firstPageMs = $elapsedMs;
            }

            if (empty($response['ok'])) {
                $errors[] = [
                    'page' => $page,
                    'error' => $response['error'] ?? ['type' => 'unknown', 'message' => 'Falha desconhecida'],
                ];
                break;
            }

            $data = is_array($response['data'] ?? null) ? $response['data'] : [];
            $batch = $this->extractRecords($data, $recordsKey);
            foreach ($batch as $item) {
                if (is_array($item)) {
                    $records[] = $item;
                }
            }

            $pagesFetched++;
            $pagesExpected = max(1, min($maxPages, $this->extractTotalPages($data)));
            $recordsExpected = $this->extractTotalRecords($data) ?? $recordsExpected;
        }

        return [
            'status' => $errors === [] ? 'ok' : 'error',
            'call' => $call,
            'pages_expected' => $pagesExpected,
            'pages_fetched' => $pagesFetched,
            'records_expected' => $recordsExpected ?? count($records),
            'records_received' => count($records),
            'first_page_ms' => $firstPageMs ?? 0,
            'total_duration_ms' => max(0, (int) round((microtime(true) - $startedAt) * 1000)),
            'records' => $records,
            'errors' => $errors,
            'policy' => $this->policySummary($policy, $pageSize),
        ];
    }

    /**
     * @param array<string,mixed> $policy
     * @return array<string,mixed>
     */
    private function policySummary(array $policy, int $pageSize): array
    {
        return [
            'domain' => (string) ($policy['domain'] ?? 'generic'),
            'page_size' => $pageSize,
            'page_size_target' => max(1, min(100, (int) ($policy['page_size_target'] ?? $pageSize))),
            'rate_limit_per_minute' => max(1, (int) ($policy['rate_limit_per_minute'] ?? 240)),
            'max_concurrency' => max(1, min(4, (int) ($policy['max_concurrency'] ?? 1))),
            'window_strategy' => (string) (($policy['window']['strategy'] ?? '') ?: 'on_demand'),
        ];
    }

    /** @param array<string,mixed> $data @return list<array<string,mixed>|mixed> */
    private function extractRecords(array $data, ?string $preferredKey): array
    {
        if ($preferredKey !== null && is_array($data[$preferredKey] ?? null)) {
            return array_values($data[$preferredKey]);
        }

        foreach ([
            'nfCadastro',
            'pedido_venda_produto',
            'clientes_cadastro',
            'clientes_cadastro_resumido',
            'cadastro',
            'etapasPedido',
            'lista_cidades',
            'cidades',
            'movimentos',
            'titulosEncontrados',
            'lista_contas_correntes',
        ] as $key) {
            if (is_array($data[$key] ?? null)) {
                return array_values($data[$key]);
            }
        }

        foreach ($data as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'lista_') && is_array($value)) {
                return array_values($value);
            }
        }

        return [];
    }

    /** @param array<string,mixed> $data */
    private function extractTotalPages(array $data): int
    {
        foreach (['total_de_paginas', 'nTotPaginas', 'total_paginas'] as $key) {
            if (isset($data[$key])) {
                return max(1, (int) $data[$key]);
            }
        }
        return 1;
    }

    /** @param array<string,mixed> $data */
    private function extractTotalRecords(array $data): ?int
    {
        foreach (['total_de_registros', 'registros', 'nTotRegistros', 'total_registros'] as $key) {
            if (isset($data[$key])) {
                return max(0, (int) $data[$key]);
            }
        }
        return null;
    }
}

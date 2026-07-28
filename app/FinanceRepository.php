<?php

declare(strict_types=1);

namespace BIomie;

use BIomie\Support\TenantContext;

final class FinanceRepository
{
    private readonly int $companyId;

    public function __construct(
        private readonly OmieClient $client,
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly ?Cache $cache = null,
        private readonly ?BronzeRepository $bronze = null,
        private readonly string $bronzeSource = 'runtime',
    ) {
        $this->companyId = TenantContext::companyId();
    }

    private function fetchWithMonthlyCache(string $service, string $start, string $end, callable $fetcher): array
    {
        $rows = [];
        $currentMonth = new \DateTimeImmutable(date('Y-m-01', strtotime($start)));
        $lastMonth = new \DateTimeImmutable(date('Y-m-01', strtotime($end)));
        $now = new \DateTimeImmutable();

        while ($currentMonth <= $lastMonth) {
            $monthStart = $currentMonth->format('Y-m-01');
            $monthEnd = $currentMonth->format('Y-m-t');

            if ($currentMonth->format('Y-m') === $lastMonth->format('Y-m')) {
                $monthEnd = $end;
            }

            $isHistorical = (new \DateTimeImmutable($monthEnd)) < new \DateTimeImmutable($now->format('Y-m-d'));
            $cacheKey = sprintf('finance_chunk_c%d_%s_%s_%s', $this->companyId, $service, $monthStart, $monthEnd);

            if ($isHistorical && $this->cache !== null) {
                $cached = $this->cache->get($cacheKey, 31536000);
                if ($cached !== null) {
                    foreach ((array) $cached as $row) {
                        $rows[] = $row;
                    }
                    $currentMonth = $currentMonth->modify('+1 month');
                    continue;
                }
            }

            $chunkRows = $fetcher($monthStart, $monthEnd);

            if ($isHistorical && $this->cache !== null) {
                $this->cache->put($cacheKey, $chunkRows);
            }

            foreach ($chunkRows as $row) {
                $rows[] = $row;
            }
            $currentMonth = $currentMonth->modify('+1 month');
        }

        return $rows;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listAccounts(): array
    {
        return $this->postAllPages(
            $this->endpoint('accounts', 'https://app.omie.com.br/api/v1/geral/contacorrente/'),
            ['ListarContasCorrentes'],
            [
                'pagina' => 1,
                'registros_por_pagina' => $this->pageSizeFor('ListarContasCorrentes'),
                'apenas_importado_api' => 'N',
            ],
            ['lista_contas_correntes', 'conta_corrente_cadastro', 'contas_correntes_cadastro', 'cadastros'],
            'finance',
            'account',
            fn (array $row): ?string => $this->extractAccountNaturalKey($row),
            fn (array $row): ?string => $this->extractAccountProviderKey($row)
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listFinancialMovements(string $start, string $end): array
    {
        return $this->fetchWithMonthlyCache('movements', $start, $end, function ($mStart, $mEnd) {
            return $this->postAllPages(
                $this->endpoint('fin_movements', 'https://app.omie.com.br/api/v1/financas/mf/'),
                ['ListarMovimentos'],
                [
                    'nPagina' => 1,
                    'nRegPorPagina' => $this->pageSizeFor('ListarMovimentos'),
                    'cNatureza' => 'R',
                    'dDtPagtoDe' => date('d/m/Y', strtotime($mStart)),
                    'dDtPagtoAte' => date('d/m/Y', strtotime($mEnd)),
                ],
                ['movimentos', 'movimentosEncontrados', 'lista_movimentos'],
                'finance',
                'movement',
                fn (array $row): ?string => $this->extractMovementNaturalKey($row),
                fn (array $row): ?string => $this->extractMovementProviderKey($row)
            );
        });
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function searchTitlesByPayment(string $start, string $end): array
    {
        return $this->fetchWithMonthlyCache('titles_payment', $start, $end, function ($mStart, $mEnd) {
            return $this->postAllPages(
                $this->endpoint('fin_titles_search', 'https://app.omie.com.br/api/v1/financas/pesquisartitulos/'),
                ['PesquisarLancamentos', 'ListarLancamentos'],
                [
                    'nPagina' => 1,
                    'nRegPorPagina' => $this->pageSizeFor('PesquisarLancamentos'),
                    'cNatureza' => 'R',
                    'dDtPagtoDe' => date('d/m/Y', strtotime($mStart)),
                    'dDtPagtoAte' => date('d/m/Y', strtotime($mEnd)),
                ],
                ['titulosEncontrados', 'titulos_encontrados', 'titulos', 'lancamentos', 'lista_lancamentos'],
                'finance',
                'title',
                fn (array $row): ?string => $this->extractTitleNaturalKey($row),
                fn (array $row): ?string => $this->extractTitleProviderKey($row)
            );
        });
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function searchTitlesByDueDate(string $start, string $end): array
    {
        return $this->fetchWithMonthlyCache('titles_duedate', $start, $end, function ($mStart, $mEnd) {
            return $this->postAllPages(
                $this->endpoint('fin_titles_search', 'https://app.omie.com.br/api/v1/financas/pesquisartitulos/'),
                ['PesquisarLancamentos', 'ListarLancamentos'],
                [
                    'nPagina' => 1,
                    'nRegPorPagina' => $this->pageSizeFor('PesquisarLancamentos'),
                    'cNatureza' => 'R',
                    'dDtVencDe' => date('d/m/Y', strtotime($mStart)),
                    'dDtVencAte' => date('d/m/Y', strtotime($mEnd)),
                ],
                ['titulosEncontrados', 'titulos_encontrados', 'titulos', 'lancamentos', 'lista_lancamentos'],
                'finance',
                'title',
                fn (array $row): ?string => $this->extractTitleNaturalKey($row),
                fn (array $row): ?string => $this->extractTitleProviderKey($row)
            );
        });
    }

    /**
     * Busca contas a receber completas via ListarContasReceber.
     * O contrato oficial expoe tipo de documento, conta corrente, boleto
     * e referencias fiscais ausentes em PesquisarLancamentos.
     *
     * @return list<array<string,mixed>>
     */
    public function listReceivablesByDueDate(string $start, string $end): array
    {
        return $this->fetchWithMonthlyCache('receivables_full', $start, $end, function ($mStart, $mEnd) {
            return $this->postAllPages(
                $this->endpoint('fin_receivables', 'https://app.omie.com.br/api/v1/financas/contareceber/'),
                ['ListarContasReceber'],
                [
                    'pagina'                  => 1,
                    'registros_por_pagina'    => $this->pageSizeFor('ListarContasReceber'),
                    'apenas_importado_api'    => 'N',
                    'filtrar_por_emissao_de'  => date('d/m/Y', strtotime($mStart)),
                    'filtrar_por_emissao_ate' => date('d/m/Y', strtotime($mEnd)),
                    'exibir_obs'              => 'N',
                ],
                ['conta_receber_cadastro', 'clientes_cadastro', 'lista_conta_receber', 'titulos'],
                'finance',
                'receivable',
                fn (array $row): ?string => $this->extractTitleNaturalKey($row),
                fn (array $row): ?string => $this->extractTitleProviderKey($row)
            );
        });
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listBudgetMonths(string $start, string $end): array
    {
        $endpoint = $this->endpoint('cash_budget', 'https://app.omie.com.br/api/v1/financas/caixa/');
        $rows = [];
        $currentMonth = new \DateTimeImmutable(date('Y-m-01', strtotime($start)));
        $lastMonth = new \DateTimeImmutable(date('Y-m-01', strtotime($end)));
        $now = new \DateTimeImmutable();

        while ($currentMonth <= $lastMonth) {
            $ano = (int) $currentMonth->format('Y');
            $mes = (int) $currentMonth->format('n');

            $cacheKey = sprintf('finance_budget_c%d_%s_%s', $this->companyId, $ano, str_pad((string) $mes, 2, '0', STR_PAD_LEFT));
            $isHistorical = $currentMonth < new \DateTimeImmutable($now->format('Y-m-01'));
            if ($isHistorical && $this->cache !== null) {
                $cached = $this->cache->get($cacheKey, 31536000);
                if ($cached !== null) {
                    foreach ((array) $cached as $row) {
                        $rows[] = $row;
                    }
                    $currentMonth = $currentMonth->modify('+1 month');
                    continue;
                }
            }

            ['response' => $response, 'call' => $call] = $this->postWithFallbackCallsMeta(
                $endpoint,
                ['ListarOrcamentos', 'ListarOrcamento', 'ListarOrcamentosCaixa'],
                ['nAno' => $ano, 'nMes' => $mes]
            );

            $batch = $this->extractRows($response, ['listaOrcamentos', 'orcamentos', 'lista_orcamentos']);
            if ($batch === [] && $this->looksLikeRowCollection($response)) {
                $batch = array_values(array_filter($response, 'is_array'));
            }

            $enrichedBatch = [];
            foreach ($batch as $row) {
                $row['__year'] = $ano;
                $row['__month'] = $mes;
                $enrichedBatch[] = $row;
            }

            if ($isHistorical && $this->cache !== null) {
                $this->cache->put($cacheKey, $enrichedBatch);
            }

            foreach ($enrichedBatch as $row) {
                $rows[] = $row;
            }

            $this->captureBronzeRows(
                'finance',
                'budget',
                $endpoint,
                $call,
                ['nAno' => $ano, 'nMes' => $mes],
                $response,
                $enrichedBatch,
                fn (array $row): ?string => $this->extractBudgetNaturalKey($row),
                fn (array $row): ?string => $this->extractBudgetProviderKey($row)
            );

            $currentMonth = $currentMonth->modify('+1 month');
        }

        return $rows;
    }

    /**
     * @return array<string,mixed>
     */
    public function getFinanceSummary(string $start, string $end): array
    {
        $endpoint = $this->endpoint('fin_summary', 'https://app.omie.com.br/api/v1/financas/resumo/');

        // Circuit breaker dedicado ao endpoint financas/resumo/ da Omie.
        // Esse endpoint tem historico de indisponibilidade (HTTP 500) e fornece
        // apenas um RESUMO suplementar — nunca deve travar a sincronizacao nem
        // ser martelado em loop. Se falhou recentemente, pulamos a chamada e
        // degradamos para um resumo vazio (reconstruido a partir dos titulos).
        $circuitKey = sprintf('finance_summary_cb_c%d', $this->companyId);
        $cooldownSeconds = max(0, (int) $this->config->get('omie.finance_summary_circuit_cooldown_seconds', 1800));
        if ($this->cache !== null && $cooldownSeconds > 0 && $this->cache->get($circuitKey, $cooldownSeconds) !== null) {
            $this->logger->info('Resumo financeiro Omie em cooldown (circuit aberto); chamada pulada.', [
                'endpoint' => $endpoint,
                'cooldown_seconds' => $cooldownSeconds,
            ]);

            return [];
        }

        $params = [
            'dDtInicial' => date('d/m/Y', strtotime($start)),
            'dDtFinal' => date('d/m/Y', strtotime($end)),
            'cNatureza' => 'R',
        ];

        try {
            ['response' => $response, 'call' => $call] = $this->postWithFallbackCallsMeta(
                $endpoint,
                ['ListarResumo', 'ConsultarResumo', 'ObterResumo'],
                $params
            );
        } catch (\Throwable $exception) {
            // Abre o circuit para evitar reincidencia durante o outage da Omie.
            if ($this->cache !== null && $cooldownSeconds > 0) {
                $this->cache->put($circuitKey, ['opened_at' => time(), 'reason' => 'omie_resumo_unavailable']);
            }
            $this->logger->warning('Resumo financeiro Omie indisponivel; degradando sem snapshot de resumo.', [
                'endpoint' => $endpoint,
                'message' => $exception->getMessage(),
            ]);

            return [];
        }

        // Sucesso: fecha o circuit (se estava aberto) e segue o fluxo normal.
        if ($this->cache !== null && $cooldownSeconds > 0) {
            $this->cache->delete($circuitKey);
        }

        if ($this->bronze !== null && $response !== []) {
            $this->bronze->captureRecord(
                'finance',
                'summary',
                $endpoint,
                $call,
                $params,
                $response,
                $start . ':' . $end,
                null,
                ['route' => RequestContext::route()],
                $this->bronzeSource,
            );
        }

        return $response;
    }

    private function endpoint(string $key, string $default): string
    {
        return trim((string) $this->config->get('omie.endpoints.' . $key, $default));
    }

    private function pageSizeFor(string $call): int
    {
        return max(
            1,
            min(
                100,
                (new OmieMethodPolicyRegistry($this->config))->pageSize(
                    $call,
                    (int) $this->config->get('omie.default_page_size', 100)
                )
            )
        );
    }

    /**
     * @param list<string> $calls
     * @param array<string,mixed> $baseParams
     * @param list<string> $rowKeys
     * @param callable(array<string,mixed>):?string|null $naturalKeyResolver
     * @param callable(array<string,mixed>):?string|null $providerKeyResolver
     * @return list<array<string,mixed>>
     */
    private function postAllPages(
        string $endpoint,
        array $calls,
        array $baseParams,
        array $rowKeys,
        ?string $domain = null,
        ?string $entityName = null,
        ?callable $naturalKeyResolver = null,
        ?callable $providerKeyResolver = null,
    ): array {
        $rows = [];
        $page = 1;
        $totalPages = 1;

        do {
            $params = $baseParams;
            foreach (['pagina', 'nPagina'] as $pageKey) {
                if (array_key_exists($pageKey, $params)) {
                    $params[$pageKey] = $page;
                }
            }

            ['response' => $response, 'call' => $call] = $this->postWithFallbackCallsMeta($endpoint, $calls, $params);
            $batch = $this->extractRows($response, $rowKeys);

            foreach ($batch as $row) {
                $rows[] = $row;
            }

            if (
                $domain !== null
                && $entityName !== null
                && $naturalKeyResolver !== null
                && $providerKeyResolver !== null
            ) {
                $this->captureBronzeRows(
                    $domain,
                    $entityName,
                    $endpoint,
                    $call,
                    $params,
                    $response,
                    $batch,
                    $naturalKeyResolver,
                    $providerKeyResolver,
                );
            }

            $totalPages = $this->extractTotalPages($response);
            $page++;
        } while ($page <= max($totalPages, 1));

        return $rows;
    }

    /**
     * @param list<string> $calls
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function postWithFallbackCalls(string $endpoint, array $calls, array $params): array
    {
        return $this->postWithFallbackCallsMeta($endpoint, $calls, $params)['response'];
    }

    /**
     * @param list<string> $calls
     * @param array<string,mixed> $params
     * @return array{response:array<string,mixed>,call:string}
     */
    private function postWithFallbackCallsMeta(string $endpoint, array $calls, array $params): array
    {
        $lastException = null;
        foreach ($calls as $call) {
            try {
                return [
                    'response' => $this->client->post($endpoint, $call, $params),
                    'call' => $call,
                ];
            } catch (\Throwable $exception) {
                $lastException = $exception;
                $this->logger->warning('Falha em chamada financeira Omie; tentando proxima assinatura.', [
                    'endpoint' => $endpoint,
                    'call' => $call,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        if ($lastException instanceof \Throwable) {
            throw $lastException;
        }

        return [
            'response' => [],
            'call' => $calls[0] ?? 'unknown',
        ];
    }

    private function extractTotalPages(array $response): int
    {
        return (int) Support::getByPaths($response, [
            'total_de_paginas',
            'nTotPaginas',
            'nTotPagina',
            'total_paginas',
        ], 1);
    }

    /**
     * @param array<string,mixed> $response
     * @param list<string> $keys
     * @return list<array<string,mixed>>
     */
    private function extractRows(array $response, array $keys): array
    {
        foreach ($keys as $key) {
            $value = $response[$key] ?? null;
            if (!is_array($value)) {
                continue;
            }
            if ($this->looksLikeRowCollection($value)) {
                return array_values(array_filter($value, 'is_array'));
            }
        }

        foreach ($response as $value) {
            if (!is_array($value)) {
                continue;
            }
            if ($this->looksLikeRowCollection($value)) {
                return array_values(array_filter($value, 'is_array'));
            }
        }

        return [];
    }

    private function looksLikeRowCollection(array $value): bool
    {
        if ($value === []) {
            return false;
        }
        foreach ($value as $item) {
            return is_array($item);
        }
        return false;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param callable(array<string,mixed>):?string $naturalKeyResolver
     * @param callable(array<string,mixed>):?string $providerKeyResolver
     */
    private function captureBronzeRows(
        string $domain,
        string $entityName,
        string $endpoint,
        string $omieMethod,
        array $params,
        array $response,
        array $rows,
        callable $naturalKeyResolver,
        callable $providerKeyResolver,
    ): void {
        if ($this->bronze === null || $rows === []) {
            return;
        }

        $metadata = [
            'page' => (int) ($params['pagina'] ?? $params['nPagina'] ?? 1),
            'total_pages' => $this->extractTotalPages($response),
            'route' => RequestContext::route(),
        ];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $this->bronze->captureRecord(
                $domain,
                $entityName,
                $endpoint,
                $omieMethod,
                $params,
                $row,
                $naturalKeyResolver($row),
                $providerKeyResolver($row),
                $metadata,
                $this->bronzeSource,
            );
        }
    }

    private function extractAccountNaturalKey(array $row): ?string
    {
        $value = trim((string) Support::getByPaths($row, ['nCodCC', 'codigo', 'codigo_conta_corrente'], ''));
        return $value !== '' ? $value : null;
    }

    private function extractAccountProviderKey(array $row): ?string
    {
        return $this->extractAccountNaturalKey($row);
    }

    private function extractMovementNaturalKey(array $row): ?string
    {
        $titleId = trim((string) Support::getByPaths($row, ['detalhes.nCodTitulo', 'nCodTitulo', 'codigo_lancamento_omie'], ''));
        $document = trim((string) Support::getByPaths($row, ['detalhes.cNumTitulo', 'cNumTitulo', 'documento'], ''));
        $parts = array_values(array_filter([$titleId, $document]));
        return $parts !== [] ? implode(':', $parts) : null;
    }

    private function extractMovementProviderKey(array $row): ?string
    {
        $value = trim((string) Support::getByPaths($row, ['detalhes.nCodTitulo', 'nCodTitulo', 'codigo_lancamento_omie'], ''));
        return $value !== '' ? $value : null;
    }

    private function extractTitleNaturalKey(array $row): ?string
    {
        $titleId = trim((string) Support::getByPaths($row, ['cabecTitulo.nCodTitulo', 'nCodTitulo', 'codigo_lancamento_omie'], ''));
        $document = trim((string) Support::getByPaths($row, ['cabecTitulo.cNumTitulo', 'cNumTitulo', 'numero_documento', 'documento'], ''));
        $parts = array_values(array_filter([$titleId, $document]));
        return $parts !== [] ? implode(':', $parts) : null;
    }

    private function extractTitleProviderKey(array $row): ?string
    {
        $value = trim((string) Support::getByPaths($row, ['cabecTitulo.nCodTitulo', 'nCodTitulo', 'codigo_lancamento_omie'], ''));
        return $value !== '' ? $value : null;
    }

    private function extractBudgetNaturalKey(array $row): ?string
    {
        $year = (string) ($row['__year'] ?? '');
        $month = str_pad((string) ($row['__month'] ?? ''), 2, '0', STR_PAD_LEFT);
        $category = trim((string) Support::getByPaths($row, ['cCodCateg', 'codigo_categoria'], ''));
        $parts = array_values(array_filter([$year, $month, $category]));
        return $parts !== [] ? implode(':', $parts) : null;
    }

    private function extractBudgetProviderKey(array $row): ?string
    {
        $value = trim((string) Support::getByPaths($row, ['cCodCateg', 'codigo_categoria'], ''));
        return $value !== '' ? $value : null;
    }
}

<?php

declare(strict_types=1);

namespace BIomie;

use BIomie\FinanceAnalytics\FinanceLabelResolver;
use BIomie\FinanceAnalytics\FinanceNormalizer;
use BIomie\FinanceAnalytics\FinanceRowFormatter;
use BIomie\FinanceAnalytics\FinanceTrendBuilder;
use DateTimeImmutable;
use RuntimeException;

final class FinanceAnalyticsService
{
    private ?FinanceLabelResolver $financeLabelResolver = null;
    private ?FinanceNormalizer $financeNormalizer = null;
    private ?FinanceTrendBuilder $financeTrendBuilder = null;
    private ?FinanceRowFormatter $financeRowFormatter = null;
    private array $extraVendorMap = [];

    public function __construct(
        private readonly Config $config,
        private readonly Cache $cache,
        private readonly Logger $logger,
        private readonly ?FinanceRepository $repository = null,
        private readonly ?OmieRepository $salesRepository = null,
        private readonly ?RefreshQueue $queue = null,
    ) {
    }

    public function getSnapshot(string $start, string $end, bool $forceRefresh = false): array
    {
        $key = sprintf('finance_snapshot_%s_%s', $start, $end);
        $ttlMinutes = (int) $this->config->get('finance.cache_ttl_minutes', $this->config->get('analytics.cache_ttl_minutes', 60));
        $ttlSeconds = max($ttlMinutes, 1) * 60;

        if (!$forceRefresh) {
            $cached = $this->cache->get($key, $ttlSeconds);
            if (is_array($cached)) {
                $cached['meta']['cache'] = $this->cache->getMeta($key);
                $cached['meta']['served_from'] = 'cache-fresh';
                return $cached;
            }
        }

        $fallback = $this->cache->read($key);
        try {
            $snapshot = $this->buildSnapshot($start, $end);
            $this->cache->put($key, $snapshot);
            $this->rememberSnapshotHistory($key, $snapshot);
            $snapshot['meta']['cache'] = $this->cache->getMeta($key);
            $snapshot['meta']['served_from'] = $forceRefresh ? 'manual-refresh' : 'live-build';
            $snapshot['meta']['refresh_strategy'] = 'auto-live-refresh';
            return $snapshot;
        } catch (\Throwable $exception) {
            $this->logger->error('Finance build falhou, stale-if-error', ['message' => $exception->getMessage()]);
            if (is_array($fallback)) {
                $fallback['meta']['cache'] = $this->cache->getMeta($key);
                $fallback['meta']['served_from'] = 'stale-if-error';
                $fallback['meta']['fallback_reason'] = $exception->getMessage();
                $fallback['meta']['refresh_strategy'] = 'auto-live-refresh';
                return $fallback;
            }
            throw $exception;
        }
    }

    private function buildSnapshot(string $start, string $end): array
    {
        if (!$this->config->hasOmieCredentials() || $this->repository === null) {
            throw new RuntimeException('Módulo Financeiro Realizado requer credenciais válidas da Omie.');
        }

        $warnings = [];
        $accounts = $this->attempt('Contas correntes', fn (): array => $this->normalizeAccounts($this->repository->listAccounts()), $warnings);
        $movements = $this->attempt('Movimentos financeiros', fn (): array => $this->normalizeMovements($this->repository->listFinancialMovements($start, $end)), $warnings);
        $paidTitles = $this->attempt('Pesquisar títulos por pagamento', fn (): array => $this->normalizeTitles($this->repository->searchTitlesByPayment($start, $end)), $warnings);
        $dueTitles = $this->attempt('Pesquisar títulos por vencimento', fn (): array => $this->normalizeTitles($this->repository->searchTitlesByDueDate($start, $end)), $warnings);
        $budgetRows = $this->attempt('Orçamento de caixa', fn (): array => $this->normalizeBudget($this->repository->listBudgetMonths($start, $end)), $warnings);
        $summaryRows = $this->attempt('Resumo de finanças', fn (): array => (array) $this->repository->getFinanceSummary($start, $end), $warnings, false);

        $technicalWarnings = array_values(array_filter($warnings, static fn (string $warning): bool => !str_contains($warning, 'sem dados retornados')));
        if ($movements === [] && $paidTitles === [] && $dueTitles === [] && $technicalWarnings !== []) {
            throw new RuntimeException('Os endpoints do módulo financeiro não retornaram dados utilizáveis. Verifique os serviços financeiros da Omie no config/env.php.');
        }

        $vendorMap = $this->buildVendorMap();
        $accountMap = [];
        foreach ($accounts as $account) {
            foreach ($this->buildLookupKeys($account['account_id'] ?? '', $account['integration_code'] ?? '') as $key) {
                $accountMap[$key] = $account;
            }
        }

        foreach ($movements as $index => $movement) {
            $movements[$index]['account_name'] = $this->resolveAccountLabel($movement['account_id'] ?? '', $accountMap);
            $movements[$index]['payment_method'] = $this->inferPaymentMethod($movement, $movements[$index]['account_name']);
            $movements[$index]['document_type'] = $this->inferDocumentType($movement);
            $movements[$index]['document_label'] = $this->resolveDocumentLabel($movement);
            if (trim((string) ($movements[$index]['client_name'] ?? '')) === '') {
                $movements[$index]['client_name'] = 'Cliente não informado';
            }
        }

        foreach ($paidTitles as $index => $title) {
            $paidTitles[$index]['vendor_name'] = $this->resolveVendorName($title, $vendorMap);
            $paidTitles[$index]['account_name'] = $this->resolveAccountLabel($title['account_id'] ?? '', $accountMap);
            $paidTitles[$index]['payment_method'] = $this->inferPaymentMethod($title, $paidTitles[$index]['account_name']);
            $paidTitles[$index]['document_type'] = $this->inferDocumentType($title);
            $paidTitles[$index]['document_label'] = $this->resolveDocumentLabel($title);
        }
        foreach ($dueTitles as $index => $title) {
            $dueTitles[$index]['vendor_name'] = $this->resolveVendorName($title, $vendorMap);
            $dueTitles[$index]['account_name'] = $this->resolveAccountLabel($title['account_id'] ?? '', $accountMap);
            $dueTitles[$index]['payment_method'] = $this->inferPaymentMethod($title, $dueTitles[$index]['account_name']);
            $dueTitles[$index]['document_type'] = $this->inferDocumentType($title);
            $dueTitles[$index]['document_label'] = $this->resolveDocumentLabel($title);
        }

        $receivedRows = $paidTitles !== [] ? $paidTitles : $this->titlesFromMovements($movements);
        $receivedRows = array_values(array_filter($receivedRows, static function (array $row): bool {
            return Support::toFloat($row['amount_net'] ?? $row['amount_paid'] ?? 0) > 0.0001;
        }));
        usort($receivedRows, static fn (array $a, array $b): int => strcmp((string) ($b['paid_date'] ?? ''), (string) ($a['paid_date'] ?? '')));

        $openTitles = array_values(array_filter($dueTitles, static fn (array $row): bool => Support::toFloat($row['amount_open'] ?? 0) > 0.0001));
        usort($openTitles, static fn (array $a, array $b): int => strcmp((string) ($a['due_date'] ?? ''), (string) ($b['due_date'] ?? '')));

        $today = new DateTimeImmutable('today');
        $overdueTitles = array_values(array_filter($openTitles, static function (array $row) use ($today): bool {
            $dueDate = (string) ($row['due_date'] ?? '');
            return $dueDate !== '' && new DateTimeImmutable($dueDate) < $today;
        }));
        usort($overdueTitles, fn (array $a, array $b): int => $this->daysLate($b['due_date'] ?? '') <=> $this->daysLate($a['due_date'] ?? ''));

        $receivedTotal = array_reduce($receivedRows, static fn (float $sum, array $row): float => $sum + Support::toFloat($row['amount_net'] ?? $row['amount_paid'] ?? 0), 0.0);
        $openTotal = array_reduce($openTitles, static fn (float $sum, array $row): float => $sum + Support::toFloat($row['amount_open'] ?? 0), 0.0);
        $overdueTotal = array_reduce($overdueTitles, static fn (float $sum, array $row): float => $sum + Support::toFloat($row['amount_open'] ?? 0), 0.0);
        $portfolioExpected = $receivedTotal + $openTotal;
        $delinquencyRate = $openTotal > 0 ? ($overdueTotal / $openTotal) * 100 : 0.0;
        $realizedVsPlanned = $portfolioExpected > 0 ? ($receivedTotal / $portfolioExpected) * 100 : 0.0;

        $byVendor = [];
        foreach ($receivedRows as $row) {
            $name = trim((string) ($row['vendor_name'] ?? '')) ?: 'Sem vendedor';
            if (!isset($byVendor[$name])) {
                $byVendor[$name] = [
                    'vendor_id' => (string) ($row['vendor_id'] ?? ''),
                    'vendor_name' => $name,
                    'received' => 0.0,
                    'titles' => 0,
                    'avg_ticket' => 0.0,
                ];
            }
            $byVendor[$name]['received'] += Support::toFloat($row['amount_net'] ?? $row['amount_paid'] ?? 0);
            $byVendor[$name]['titles']++;
        }
        foreach ($byVendor as &$row) {
            $row['avg_ticket'] = $row['titles'] > 0 ? $row['received'] / $row['titles'] : 0.0;
        }
        unset($row);
        $byVendor = array_values($byVendor);
        usort($byVendor, static fn (array $a, array $b): int => Support::toFloat($b['received']) <=> Support::toFloat($a['received']));

        $byAccount = [];
        $accountRows = $movements !== [] ? $movements : $receivedRows;
        foreach ($accountRows as $row) {
            $name = trim((string) ($row['account_name'] ?? '')) ?: 'Conta não identificada';
            if (!isset($byAccount[$name])) {
                $byAccount[$name] = [
                    'account_id' => (string) ($row['account_id'] ?? ''),
                    'account_name' => $name,
                    'received' => 0.0,
                    'movements' => 0,
                ];
            }
            $byAccount[$name]['received'] += Support::toFloat($row['amount_net'] ?? $row['amount_paid'] ?? 0);
            $byAccount[$name]['movements']++;
        }
        $byAccount = array_values($byAccount);
        usort($byAccount, static fn (array $a, array $b): int => Support::toFloat($b['received']) <=> Support::toFloat($a['received']));

        $byPaymentMethod = [];
        foreach ($receivedRows as $row) {
            $name = trim((string) ($row['payment_method'] ?? '')) ?: 'Não identificado';
            if (!isset($byPaymentMethod[$name])) {
                $byPaymentMethod[$name] = [
                    'payment_method' => $name,
                    'received' => 0.0,
                    'titles' => 0,
                ];
            }
            $byPaymentMethod[$name]['received'] += Support::toFloat($row['amount_net'] ?? $row['amount_paid'] ?? 0);
            $byPaymentMethod[$name]['titles']++;
        }
        $byPaymentMethod = array_values($byPaymentMethod);
        usort($byPaymentMethod, static fn (array $a, array $b): int => Support::toFloat($b['received']) <=> Support::toFloat($a['received']));

        $documentTypes = [];
        foreach (array_merge($receivedRows, $openTitles) as $row) {
            $name = trim((string) ($row['document_type'] ?? '')) ?: 'Título financeiro';
            if (!isset($documentTypes[$name])) {
                $documentTypes[$name] = [
                    'document_type' => $name,
                    'titles' => 0,
                    'amount' => 0.0,
                ];
            }
            $documentTypes[$name]['titles']++;
            $documentTypes[$name]['amount'] += Support::toFloat($row['amount_open'] ?? $row['amount_net'] ?? $row['amount_paid'] ?? 0);
        }
        $documentTypes = array_values($documentTypes);
        usort($documentTypes, static fn (array $a, array $b): int => Support::toFloat($b['amount']) <=> Support::toFloat($a['amount']));

        $byClient = [];
        foreach ($overdueTitles as $row) {
            $name = trim((string) ($row['client_name'] ?? '')) ?: 'Cliente não informado';
            $daysLate = $this->daysLate((string) ($row['due_date'] ?? ''));
            if (!isset($byClient[$name])) {
                $byClient[$name] = [
                    'client_id' => (string) ($row['client_id'] ?? ''),
                    'client_name' => $name,
                    'client_document' => (string) ($row['client_document'] ?? ''),
                    'overdue' => 0.0,
                    'titles' => 0,
                    'max_days_late' => 0,
                    'oldest_due_date' => (string) ($row['due_date'] ?? ''),
                    'documents' => [],
                    'document_type_counts' => [],
                    'payment_method_counts' => [],
                    'status_counts' => [],
                ];
            }
            $byClient[$name]['overdue'] += Support::toFloat($row['amount_open'] ?? 0);
            $byClient[$name]['titles']++;
            $byClient[$name]['max_days_late'] = max((int) $byClient[$name]['max_days_late'], $daysLate);
            $currentOldest = (string) ($byClient[$name]['oldest_due_date'] ?? '');
            if ($currentOldest === '' || ((string) ($row['due_date'] ?? '')) !== '' && strcmp((string) ($row['due_date'] ?? ''), $currentOldest) < 0) {
                $byClient[$name]['oldest_due_date'] = (string) ($row['due_date'] ?? '');
            }
            $doc = trim((string) ($row['document_label'] ?? $row['document'] ?? ''));
            if ($doc !== '' && count($byClient[$name]['documents']) < 6) {
                $byClient[$name]['documents'][] = $doc;
            }

            $docType = trim((string) ($row['document_type'] ?? '')) ?: 'Título financeiro';
            $paymentMethod = trim((string) ($row['payment_method'] ?? '')) ?: 'Não identificado';
            $status = trim((string) ($row['status'] ?? '')) ?: 'Vencido';
            $byClient[$name]['document_type_counts'][$docType] = (int) ($byClient[$name]['document_type_counts'][$docType] ?? 0) + 1;
            $byClient[$name]['payment_method_counts'][$paymentMethod] = (int) ($byClient[$name]['payment_method_counts'][$paymentMethod] ?? 0) + 1;
            $byClient[$name]['status_counts'][$status] = (int) ($byClient[$name]['status_counts'][$status] ?? 0) + 1;
        }
        foreach ($byClient as &$clientRow) {
            arsort($clientRow['document_type_counts']);
            arsort($clientRow['payment_method_counts']);
            arsort($clientRow['status_counts']);
            $clientRow['dominant_document_type'] = (string) array_key_first($clientRow['document_type_counts']);
            $clientRow['dominant_payment_method'] = (string) array_key_first($clientRow['payment_method_counts']);
            $clientRow['dominant_status'] = (string) array_key_first($clientRow['status_counts']);
            $clientRow['cause_label'] = $clientRow['max_days_late'] >= 90
                ? 'Atraso crítico 90+'
                : ($clientRow['max_days_late'] >= 30 ? 'Atraso recorrente' : 'Atraso recente');
            unset($clientRow['document_type_counts'], $clientRow['payment_method_counts'], $clientRow['status_counts']);
        }
        unset($clientRow);
        $byClient = array_values($byClient);
        usort($byClient, static fn (array $a, array $b): int => Support::toFloat($b['overdue']) <=> Support::toFloat($a['overdue']));

        $agingBuckets = [
            '1-30 dias' => ['amount' => 0.0, 'titles' => 0, 'min_days' => 1, 'max_days' => 30],
            '31-60 dias' => ['amount' => 0.0, 'titles' => 0, 'min_days' => 31, 'max_days' => 60],
            '61-90 dias' => ['amount' => 0.0, 'titles' => 0, 'min_days' => 61, 'max_days' => 90],
            '91+ dias' => ['amount' => 0.0, 'titles' => 0, 'min_days' => 91, 'max_days' => null],
        ];
        foreach ($overdueTitles as $row) {
            $daysLate = $this->daysLate((string) ($row['due_date'] ?? ''));
            $bucket = match (true) {
                $daysLate <= 30 => '1-30 dias',
                $daysLate <= 60 => '31-60 dias',
                $daysLate <= 90 => '61-90 dias',
                default => '91+ dias',
            };
            $agingBuckets[$bucket]['amount'] += Support::toFloat($row['amount_open'] ?? 0);
            $agingBuckets[$bucket]['titles']++;
        }
        $agingRows = [];
        foreach ($agingBuckets as $bucket => $bucketData) {
            $amount = Support::toFloat($bucketData['amount'] ?? 0);
            $agingRows[] = [
                'bucket' => $bucket,
                'amount' => Support::money($amount),
                'titles' => (int) ($bucketData['titles'] ?? 0),
                'share' => $overdueTotal > 0 ? round(($amount / $overdueTotal) * 100, 2) : 0.0,
                'min_days' => (int) ($bucketData['min_days'] ?? 0),
                'max_days' => isset($bucketData['max_days']) && $bucketData['max_days'] !== null ? (int) $bucketData['max_days'] : null,
            ];
        }

        $statusBreakdown = [
            [
                'label' => 'Recebido',
                'amount' => Support::money($receivedTotal),
                'titles' => count($receivedRows),
            ],
            [
                'label' => 'Em aberto',
                'amount' => Support::money($openTotal),
                'titles' => count($openTitles),
            ],
            [
                'label' => 'Vencido',
                'amount' => Support::money($overdueTotal),
                'titles' => count($overdueTitles),
            ],
        ];

        $trendSeries = $this->buildTrendSeries($start, $end, $receivedRows, $openTitles, $overdueTitles);
        $forecastRows = $this->buildForecastRows($start, $end, $receivedRows, $openTitles);
        $budgetRows = $this->mergeBudgetRows($start, $end, $budgetRows, $forecastRows);

        $planned = $portfolioExpected;
        $budgetRealized = $receivedTotal;
        $rangeContext = $this->describePeriodContext($start, $end);

        return [
            'mode' => 'live-finance',
            'period' => [
                'start' => $start,
                'end' => $end,
                'label' => date('d/m/Y', strtotime($start)) . ' até ' . date('d/m/Y', strtotime($end)),
            ],
            'summary' => [
                'received' => Support::money($receivedTotal),
                'open' => Support::money($openTotal),
                'overdue' => Support::money($overdueTotal),
                'delinquency_rate' => round($delinquencyRate, 2),
                'planned' => Support::money($planned),
                'budget_realized' => Support::money($budgetRealized),
                'realized_vs_planned' => round($realizedVsPlanned, 2),
                'accounts' => count($byAccount),
                'vendors' => count($byVendor),
                'received_titles' => count($receivedRows),
                'open_titles' => count($openTitles),
                'overdue_titles' => count($overdueTitles),
                'avg_received_ticket' => count($receivedRows) > 0 ? Support::money($receivedTotal / count($receivedRows)) : 0.0,
                'portfolio_expected' => Support::money($portfolioExpected),
                'planned_label' => 'Carteira do período (recebido + em aberto)',
                'range_days' => $rangeContext['days'],
                'range_granularity' => $rangeContext['granularity'],
                'range_points' => $rangeContext['points'],
            ],
            'received_rows' => array_map(fn (array $row): array => $this->formatReceivedRow($row), array_slice($receivedRows, 0, 80)),
            'open_titles' => array_map(fn (array $row): array => $this->formatTitleRow($row), array_slice($openTitles, 0, 80)),
            'overdue_titles' => array_map(fn (array $row): array => $this->formatTitleRow($row), array_slice($overdueTitles, 0, 80)),
            'by_account' => array_map(static fn (array $row): array => [
                'account_id' => (string) $row['account_id'],
                'account_name' => (string) $row['account_name'],
                'received' => Support::money($row['received']),
                'movements' => (int) $row['movements'],
            ], array_slice($byAccount, 0, 20)),
            'by_vendor' => array_map(static fn (array $row): array => [
                'vendor_id' => (string) $row['vendor_id'],
                'vendor_name' => (string) $row['vendor_name'],
                'received' => Support::money($row['received']),
                'titles' => (int) $row['titles'],
                'avg_ticket' => Support::money($row['avg_ticket']),
            ], array_slice($byVendor, 0, 20)),
            'by_client' => array_map(static fn (array $row): array => [
                'client_id' => (string) $row['client_id'],
                'client_name' => (string) $row['client_name'],
                'client_document' => (string) ($row['client_document'] ?? ''),
                'overdue' => Support::money($row['overdue']),
                'titles' => (int) $row['titles'],
                'max_days_late' => (int) $row['max_days_late'],
                'oldest_due_date' => (string) $row['oldest_due_date'],
                'dominant_document_type' => (string) ($row['dominant_document_type'] ?? ''),
                'dominant_payment_method' => (string) ($row['dominant_payment_method'] ?? ''),
                'dominant_status' => (string) ($row['dominant_status'] ?? ''),
                'cause_label' => (string) ($row['cause_label'] ?? ''),
                'documents' => array_values($row['documents']),
            ], array_slice($byClient, 0, 25)),
            'budget' => array_map(static fn (array $row): array => [
                'year_month' => (string) $row['year_month'],
                'category_code' => (string) ($row['category_code'] ?? ''),
                'category_name' => (string) ($row['category_name'] ?? 'Carteira de recebíveis'),
                'planned' => Support::money($row['planned']),
                'realized' => Support::money($row['realized']),
                'open' => Support::money($row['open'] ?? 0),
                'overdue' => Support::money($row['overdue'] ?? 0),
                'coverage_rate' => round((float) ($row['coverage_rate'] ?? 0), 2),
            ], array_slice($budgetRows, 0, 24)),
            'trend' => $trendSeries,
            'aging' => $agingRows,
            'status_breakdown' => $statusBreakdown,
            'document_types' => array_map(static fn (array $row): array => [
                'document_type' => (string) $row['document_type'],
                'titles' => (int) $row['titles'],
                'amount' => Support::money($row['amount']),
            ], array_slice($documentTypes, 0, 12)),
            'payment_methods' => array_map(static fn (array $row): array => [
                'payment_method' => (string) $row['payment_method'],
                'titles' => (int) $row['titles'],
                'received' => Support::money($row['received']),
            ], array_slice($byPaymentMethod, 0, 12)),
            'meta' => [
                'generated_at' => date(DATE_ATOM),
                'source' => 'finance-module',
                'warnings' => $warnings,
                'notes' => [
                    'A tela agora faz atualização automática em leitura: não depende do botão de sincronização para refletir novos períodos.',
                    'Previsto x realizado foi recalculado pela carteira de títulos do período e mesclado ao orçamento mensal quando disponível.',
                    'Recebido usa títulos pagos ou baixados; aberto e vencido usam títulos ainda não liquidados.',
                ],
                'range' => $rangeContext,
                'counts' => [
                    'movements' => count($movements),
                    'paid_titles' => count($paidTitles),
                    'due_titles' => count($dueTitles),
                    'accounts' => count($accounts),
                    'budget_rows' => count($budgetRows),
                    'summary_rows' => is_array($summaryRows) ? count($summaryRows) : 0,
                ],
            ],
        ];
    }

    /**
     * @template T
     * @param callable():T $callback
     * @param list<string> $warnings
     * @return T
     */
    private function attempt(string $label, callable $callback, array &$warnings, bool $warnOnEmpty = true): mixed
    {
        try {
            $value = $callback();
            if ($warnOnEmpty && is_array($value) && $value === []) {
                $warnings[] = $label . ': sem dados retornados para o período.';
            }
            return $value;
        } catch (\Throwable $exception) {
            $warnings[] = $label . ': ' . $exception->getMessage();
            $this->logger->warning('Falha ao compor módulo financeiro', [
                'label' => $label,
                'message' => $exception->getMessage(),
            ]);
            return [];
        }
    }

    public function withExtraVendorMap(array $map): void
    {
        $this->extraVendorMap = $map;
    }

    private function buildVendorMap(): array
    {
        $map = [];

        if ($this->salesRepository !== null) {
            try {
                foreach ($this->salesRepository->listVendors() as $vendor) {
                    $name = trim((string) ($vendor['nome'] ?? ''));
                    if ($name === '') {
                        continue;
                    }
                    foreach ($this->buildLookupKeys($vendor['codigo'] ?? '', $vendor['codigo_integracao'] ?? '') as $key) {
                        $map[$key] = $name;
                    }
                }
            } catch (\Throwable $exception) {
                $this->logger->warning('Falha ao montar mapa de vendedores para o financeiro', ['message' => $exception->getMessage()]);
            }
        }

        foreach ($this->extraVendorMap as $key => $name) {
            $normalizedKey = (string) $key;
            if (!isset($map[$normalizedKey]) || trim((string) $map[$normalizedKey]) === '') {
                $map[$normalizedKey] = (string) $name;
            }
        }

        return $map;
    }

    private function resolveVendorName(array $title, array $vendorMap): string
    {
        return $this->financeLabelResolver()->resolveVendorName($title, $vendorMap);
    }

    private function resolveAccountLabel(mixed $accountId, array $accountMap): string
    {
        return $this->financeLabelResolver()->resolveAccountLabel($accountId, $accountMap);
    }

    /** @return list<string> */
    private function buildLookupKeys(mixed ...$values): array
    {
        return $this->financeLabelResolver()->buildLookupKeys(...$values);
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function normalizeAccounts(array $rows): array
    {
        return $this->financeNormalizer()->normalizeAccounts($rows);
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function normalizeMovements(array $rows): array
    {
        return $this->financeNormalizer()->normalizeMovements($rows);
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function normalizeTitles(array $rows): array
    {
        return $this->financeNormalizer()->normalizeTitles($rows);
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function normalizeBudget(array $rows): array
    {
        return $this->financeNormalizer()->normalizeBudget($rows);
    }

    /** @param list<array<string,mixed>> $movements @return list<array<string,mixed>> */
    private function titlesFromMovements(array $movements): array
    {
        return $this->financeNormalizer()->titlesFromMovements($movements);
    }

    private function inferDocumentType(array $row): string
    {
        return $this->financeLabelResolver()->inferDocumentType($row);
    }

    private function inferPaymentMethod(array $row, string $accountName = ''): string
    {
        return $this->financeLabelResolver()->inferPaymentMethod($row, $accountName);
    }

    private function resolveDocumentLabel(array $row): string
    {
        return $this->financeLabelResolver()->resolveDocumentLabel($row);
    }

    private function titleize(string $value): string
    {
        return $this->financeLabelResolver()->titleize($value);
    }

    private function daysLate(string $dueDate): int
    {
        return $this->financeLabelResolver()->daysLate($dueDate);
    }

    /**
     * @param list<array<string,mixed>> $receivedRows
     * @param list<array<string,mixed>> $openTitles
     * @param list<array<string,mixed>> $overdueTitles
     * @return list<array<string,mixed>>
     */
    private function buildTrendSeries(string $start, string $end, array $receivedRows, array $openTitles, array $overdueTitles): array
    {
        return $this->financeTrendBuilder()->buildTrendSeries($start, $end, $receivedRows, $openTitles, $overdueTitles);
    }

    /**
     * @param list<array<string,mixed>> $receivedRows
     * @param list<array<string,mixed>> $openTitles
     * @return list<array<string,mixed>>
     */
    private function buildForecastRows(string $start, string $end, array $receivedRows, array $openTitles): array
    {
        return $this->financeTrendBuilder()->buildForecastRows($start, $end, $receivedRows, $openTitles);
    }

    /**
     * @param list<array<string,mixed>> $budgetRows
     * @param list<array<string,mixed>> $forecastRows
     * @return list<array<string,mixed>>
     */
    private function mergeBudgetRows(string $start, string $end, array $budgetRows, array $forecastRows): array
    {
        return $this->financeTrendBuilder()->mergeBudgetRows($start, $end, $budgetRows, $forecastRows);
    }

    /**
     * @return array{days:int,granularity:string,points:int}
     */
    private function describePeriodContext(string $start, string $end): array
    {
        return $this->financeTrendBuilder()->describePeriodContext($start, $end);
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function buildEmptyBuckets(string $start, string $end, string $granularity): array
    {
        return $this->financeTrendBuilder()->buildEmptyBuckets($start, $end, $granularity);
    }

    private function bucketKeyForDate(string $date, string $granularity): string
    {
        return $this->financeTrendBuilder()->bucketKeyForDate($date, $granularity);
    }

    private function rememberSnapshotHistory(string $cacheKey, array $snapshot): void
    {
        $historyKey = 'finance_snapshot_history';
        $history = $this->cache->read($historyKey) ?: [];
        if (!is_array($history)) {
            $history = [];
        }

        $entry = [
            'cache_key' => $cacheKey,
            'generated_at' => (string) ($snapshot['meta']['generated_at'] ?? date(DATE_ATOM)),
            'start' => (string) ($snapshot['period']['start'] ?? ''),
            'end' => (string) ($snapshot['period']['end'] ?? ''),
            'label' => (string) ($snapshot['period']['label'] ?? ''),
            'summary' => [
                'received' => Support::money($snapshot['summary']['received'] ?? 0),
                'open' => Support::money($snapshot['summary']['open'] ?? 0),
                'overdue' => Support::money($snapshot['summary']['overdue'] ?? 0),
                'realized_vs_planned' => round((float) ($snapshot['summary']['realized_vs_planned'] ?? 0), 2),
            ],
        ];

        $history = array_values(array_filter($history, static fn (array $row): bool => ($row['cache_key'] ?? '') !== $cacheKey));
        array_unshift($history, $entry);
        $this->cache->put($historyKey, array_slice($history, 0, 24));
    }

    private function formatReceivedRow(array $row): array
    {
        return $this->financeRowFormatter()->formatReceivedRow($row);
    }

    private function formatTitleRow(array $row): array
    {
        return $this->financeRowFormatter()->formatTitleRow($row);
    }

    private function financeLabelResolver(): FinanceLabelResolver
    {
        return $this->financeLabelResolver ??= new FinanceLabelResolver();
    }

    private function financeNormalizer(): FinanceNormalizer
    {
        return $this->financeNormalizer ??= new FinanceNormalizer($this->financeLabelResolver());
    }

    private function financeTrendBuilder(): FinanceTrendBuilder
    {
        return $this->financeTrendBuilder ??= new FinanceTrendBuilder($this->financeLabelResolver());
    }

    private function financeRowFormatter(): FinanceRowFormatter
    {
        return $this->financeRowFormatter ??= new FinanceRowFormatter($this->financeLabelResolver());
    }
}

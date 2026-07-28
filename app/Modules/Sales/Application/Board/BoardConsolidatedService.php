<?php

declare(strict_types=1);

namespace BIomie\Modules\Sales\Application\Board;

use BIomie\Cache;
use BIomie\Company\CompanyPackageLoader;
use BIomie\Config;
use BIomie\Database;
use BIomie\Logger;
use BIomie\Modules\Fiscal\Infrastructure\FiscalReadRepository;
use BIomie\SchemaCapabilities;
use BIomie\Support;
use BIomie\Support\TenantContext;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Painel da Diretoria (modo TV) — visão executiva das empresas acessíveis.
 *
 * Receita e volume respeitam can_varejo/can_licitacao empresa por empresa.
 * Atingimento consolidado usa somente a receita das empresas que possuem meta,
 * evitando comparar toda a receita do grupo com uma meta de cobertura parcial.
 */
final class BoardConsolidatedService
{
    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly Cache $cache,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function build(int $userId, string $start, string $end, ?string $annualStart = null): array
    {
        $annualStart = $annualStart ?? substr($start, 0, 4) . '-01-01';
        $key = sprintf('board_consolidated_v8_u%d_%s_%s_%s', $userId, $start, $end, $annualStart);

        return $this->cache->getOrCompute(
            $key,
            300,
            fn (): array => $this->compute($userId, $start, $end, $annualStart),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function compute(int $userId, string $start, string $end, string $annualStart): array
    {
        $companies = TenantContext::isBooted() && TenantContext::userId() === $userId
            ? TenantContext::cachedAccessible()
            : TenantContext::listAccessible(Database::connect($this->config, 'db'), $userId);

        $companyMap = [];
        $channelMap = [];
        $ids = [];
        foreach ($companies as $company) {
            $companyId = (int) ($company['id'] ?? 0);
            if ($companyId <= 0) {
                continue;
            }

            $channels = [];
            if ((int) ($company['can_varejo'] ?? 0) === 1) {
                $channels[] = 'varejo';
            }
            if ((int) ($company['can_licitacao'] ?? 0) === 1) {
                $channels[] = 'licitacao';
            }
            if ($channels === []) {
                continue;
            }

            $ids[] = $companyId;
            $companyMap[$companyId] = $company;
            $channelMap[$companyId] = $channels;
        }

        $comparison = $this->comparisonPeriod($start, $end);
        $periodProgress = $this->periodProgress($start, $end);
        $invoices = [];
        $annualInvoices = [];
        $comparisonInvoices = [];
        $targetedInvoices = [];
        $annualTargetedInvoices = [];
        $targetPopulationAvailable = true;
        $sourceAvailable = false;
        $warnings = [];
        $monthTargets = $this->emptyTargetResult();
        $annualTargets = $this->emptyTargetResult();

        if ($ids !== []) {
            $canonicalPdo = $this->canonicalPdo();
            if ($canonicalPdo === null) {
                $warnings[] = [
                    'code' => 'invoice_source_unavailable',
                    'message' => 'A fonte fiscal consolidada está indisponível.',
                ];
            } else {
                SchemaCapabilities::warm($canonicalPdo, [
                    'biomie_notas_fiscais_canonicas',
                    'biomie_sales_targets_monthly',
                    'biomie_company_targets',
                ]);
                $monthKey = substr($start, 0, 7);
                $targetPeriods = $this->loadTargetPeriodsByCompany(
                    $canonicalPdo,
                    $ids,
                    substr($annualStart, 0, 7),
                    $monthKey,
                    $channelMap,
                );
                $monthTargets = $targetPeriods['month'];
                $annualTargets = $targetPeriods['annual'];
                if (!$monthTargets['available'] || !$annualTargets['available']) {
                    $warnings[] = [
                        'code' => 'target_source_unavailable',
                        'message' => 'As metas não puderam ser consultadas.',
                    ];
                }

                try {
                    $fiscal = new FiscalReadRepository(fn (): PDO => $canonicalPdo);
                    $periodSummaries = $fiscal->summarizeBoardPeriodsByCompanies(
                        $ids,
                        $start,
                        $end,
                        $annualStart,
                        $comparison['start'],
                        $comparison['end'],
                        $channelMap,
                        $monthTargets['seller_ids'],
                        $annualTargets['seller_ids'],
                    );
                    $invoices = $periodSummaries['current'];
                    $annualInvoices = $periodSummaries['annual'];
                    $comparisonInvoices = $periodSummaries['comparison'];
                    $targetedInvoices = $periodSummaries['targeted_current'];
                    $annualTargetedInvoices = $periodSummaries['targeted_annual'];
                    $sourceAvailable = true;
                } catch (Throwable $exception) {
                    $targetPopulationAvailable = false;
                    $this->logger->warning('Board: faturamento por empresa indisponivel.', [
                        'message' => $exception->getMessage(),
                    ]);
                    $warnings[] = [
                        'code' => 'invoice_source_unavailable',
                        'message' => 'Não foi possível consultar a fonte fiscal consolidada.',
                    ];
                }
            }
        }

        $rows = [];
        $groupRevenue = 0.0;
        $groupRetailRevenue = 0.0;
        $groupBiddingRevenue = 0.0;
        $groupTarget = 0.0;
        $groupComparableRevenue = 0.0;
        $groupOrders = 0;
        $groupAnnualRevenue = 0.0;
        $groupAnnualRetailRevenue = 0.0;
        $groupAnnualBiddingRevenue = 0.0;
        $groupAnnualTarget = 0.0;
        $groupAnnualComparableRevenue = 0.0;
        $groupExpectedAnnualTarget = 0.0;
        $companiesWithSales = 0;
        $companiesWithTargets = 0;
        $companiesWithoutTargets = 0;
        $targetDefinitionsComplete = true;
        $annualTargetDefinitionsComplete = true;
        $sourceRefreshedAt = null;

        foreach ($ids as $companyId) {
            $company = $companyMap[$companyId];
            $identity = $this->companyIdentity($company);
            $invoice = $invoices[$companyId] ?? null;
            $annualInvoice = $annualInvoices[$companyId] ?? null;
            $comparisonInvoice = $comparisonInvoices[$companyId] ?? null;

            $revenue = (float) ($invoice['billed_value'] ?? 0);
            $retailRevenue = (float) ($invoice['retail_value'] ?? 0);
            $biddingRevenue = (float) ($invoice['bidding_value'] ?? 0);
            $orders = (int) ($invoice['billed_orders'] ?? 0);
            $customers = (int) ($invoice['active_clients'] ?? 0);
            $vendors = (int) ($invoice['active_vendors'] ?? 0);
            $ticket = $orders > 0 ? $revenue / $orders : 0.0;

            $annualRevenue = (float) ($annualInvoice['billed_value'] ?? 0);
            $annualRetailRevenue = (float) ($annualInvoice['retail_value'] ?? 0);
            $annualBiddingRevenue = (float) ($annualInvoice['bidding_value'] ?? 0);
            $comparisonRevenue = (float) ($comparisonInvoice['billed_value'] ?? 0);

            $target = (float) ($monthTargets['values'][$companyId] ?? 0);
            $annualTarget = (float) ($annualTargets['values'][$companyId] ?? 0);
            $hasTarget = $target > 0.0;
            $hasAnnualTarget = $annualTarget > 0.0;
            $targetGrain = (string) ($monthTargets['grains'][$companyId] ?? '');
            $annualTargetGrain = (string) ($annualTargets['grains'][$companyId] ?? '');
            $targetPopulationKnown = $targetGrain !== 'seller' || $targetPopulationAvailable;
            $annualTargetPopulationKnown = $annualTargetGrain !== 'seller' || $targetPopulationAvailable;
            $targetComparableRevenue = $targetGrain === 'seller'
                ? (float) ($targetedInvoices[$companyId]['billed_value'] ?? 0)
                : $revenue;
            $annualTargetComparableRevenue = $annualTargetGrain === 'seller'
                ? (float) ($annualTargetedInvoices[$companyId]['billed_value'] ?? 0)
                : $annualRevenue;
            $targetCoveragePct = $revenue > 0.0
                ? round(($targetComparableRevenue / $revenue) * 100, 1)
                : 100.0;
            $annualTargetCoveragePct = $annualRevenue > 0.0
                ? round(($annualTargetComparableRevenue / $annualRevenue) * 100, 1)
                : 100.0;
            $targetCoverageComplete = $targetPopulationKnown
                && (
                    $targetGrain !== 'seller'
                    || abs($targetComparableRevenue - $revenue) < 0.01
                );
            $annualMonthsComplete = (int) ($annualTargets['months_configured'][$companyId] ?? 0)
                >= (int) ($annualTargets['months_expected'] ?? 0);
            $annualTargetCoverageComplete = $annualTargetPopulationKnown
                && $annualMonthsComplete
                && (
                    $annualTargetGrain !== 'seller'
                    || abs($annualTargetComparableRevenue - $annualRevenue) < 0.01
                );
            $pairedAttainment = $hasTarget && $targetPopulationKnown
                ? round(($targetComparableRevenue / $target) * 100, 1)
                : null;
            $pairedAnnualAttainment = $hasAnnualTarget && $annualTargetPopulationKnown
                ? round(($annualTargetComparableRevenue / $annualTarget) * 100, 1)
                : null;
            $attainment = $targetCoverageComplete ? $pairedAttainment : null;
            $annualAttainment = $annualTargetCoverageComplete ? $pairedAnnualAttainment : null;
            $pace = $hasTarget && $targetPopulationKnown && $periodProgress['expected_pct'] > 0.0
                ? round(
                    ($targetComparableRevenue / ($target * $periodProgress['expected_pct'] / 100)) * 100,
                    1,
                )
                : null;
            $projection = $periodProgress['expected_pct'] > 0.0
                ? $targetComparableRevenue / ($periodProgress['expected_pct'] / 100)
                : 0.0;
            $expectedAnnualTarget = $hasAnnualTarget
                ? max(
                    0.0,
                    $annualTarget - $target + ($target * $periodProgress['expected_pct'] / 100),
                )
                : 0.0;
            $annualPace = $expectedAnnualTarget > 0.0
                ? round(($annualTargetComparableRevenue / $expectedAnnualTarget) * 100, 1)
                : null;

            $delta = $comparisonRevenue > 0.0
                ? round((($revenue - $comparisonRevenue) / $comparisonRevenue) * 100, 1)
                : null;
            $comparisonStatus = $comparisonRevenue > 0.0
                ? 'comparable'
                : ($revenue > 0.0 ? 'no_previous_base' : 'no_movement');

            $lastSourceAt = $this->latestTimestamp([
                $invoice['last_source_at'] ?? null,
                $annualInvoice['last_source_at'] ?? null,
                $company['integration_last_success_at'] ?? null,
            ]);
            $sourceRefreshedAt = $this->latestTimestamp([$sourceRefreshedAt, $lastSourceAt]);
            $integrationStatus = $this->integrationStatus($company, $lastSourceAt);
            $salesStatus = !$sourceAvailable
                ? 'unavailable'
                : ($revenue > 0.009 ? 'ok' : 'no_movement');

            $groupRevenue += $revenue;
            $groupRetailRevenue += $retailRevenue;
            $groupBiddingRevenue += $biddingRevenue;
            $groupOrders += $orders;
            $groupAnnualRevenue += $annualRevenue;
            $groupAnnualRetailRevenue += $annualRetailRevenue;
            $groupAnnualBiddingRevenue += $annualBiddingRevenue;

            if ($revenue > 0.009) {
                $companiesWithSales++;
            }
            if ($hasTarget) {
                $companiesWithTargets++;
                $groupTarget += $target;
                $groupComparableRevenue += $targetComparableRevenue;
                if (!$targetCoverageComplete) {
                    $targetDefinitionsComplete = false;
                }
            } else {
                $companiesWithoutTargets++;
            }
            if ($hasAnnualTarget) {
                $groupAnnualTarget += $annualTarget;
                $groupAnnualComparableRevenue += $annualTargetComparableRevenue;
                $groupExpectedAnnualTarget += $expectedAnnualTarget;
                if (!$annualTargetCoverageComplete) {
                    $annualTargetDefinitionsComplete = false;
                }
            }

            $rows[] = [
                'company_id' => $companyId,
                'slug' => $identity['slug'],
                'name' => $identity['name'],
                'initials' => $identity['initials'],
                'logo' => $identity['logo'],
                'color' => $identity['color'],
                'channel_scope' => $channelMap[$companyId],
                'channel_label' => $this->channelLabel($channelMap[$companyId]),
                'primary_channel' => $this->primaryChannel(
                    $channelMap[$companyId],
                    $retailRevenue,
                    $biddingRevenue,
                    $annualRetailRevenue,
                    $annualBiddingRevenue,
                ),
                'revenue' => Support::money($revenue),
                'retail_revenue' => Support::money($retailRevenue),
                'bidding_revenue' => Support::money($biddingRevenue),
                'target' => Support::money($target),
                'target_source' => $monthTargets['sources'][$companyId] ?? null,
                'target_grain' => $targetGrain !== '' ? $targetGrain : null,
                'target_status' => !$hasTarget
                    ? 'missing'
                    : ($targetCoverageComplete ? 'ok' : 'partial'),
                'attainment_pct' => $attainment,
                'paired_attainment_pct' => $pairedAttainment,
                'target_comparable_revenue' => Support::money($targetComparableRevenue),
                'revenue_without_target' => Support::money(max(0.0, $revenue - $targetComparableRevenue)),
                'target_coverage_pct' => $targetCoveragePct,
                'target_coverage_complete' => $targetCoverageComplete,
                'target_sellers_count' => count($monthTargets['seller_ids'][$companyId] ?? []),
                'expected_pct' => $periodProgress['expected_pct'],
                'pace_pct' => $pace,
                'goal_status' => $this->goalStatus($hasTarget, $pairedAttainment, $pace),
                'gap' => Support::money($hasTarget ? $target - $targetComparableRevenue : 0.0),
                'projection' => Support::money($projection),
                'projected_gap' => Support::money($hasTarget ? $target - $projection : 0.0),
                'annual_revenue' => Support::money($annualRevenue),
                'annual_retail_revenue' => Support::money($annualRetailRevenue),
                'annual_bidding_revenue' => Support::money($annualBiddingRevenue),
                'annual_target' => Support::money($annualTarget),
                'annual_target_source' => $annualTargets['sources'][$companyId] ?? null,
                'annual_target_grain' => $annualTargetGrain !== '' ? $annualTargetGrain : null,
                'annual_target_status' => !$hasAnnualTarget
                    ? 'missing'
                    : ($annualTargetCoverageComplete ? 'ok' : 'partial'),
                'annual_attainment_pct' => $annualAttainment,
                'annual_paired_attainment_pct' => $pairedAnnualAttainment,
                'annual_pace_pct' => $annualPace,
                'annual_target_coverage_pct' => $annualTargetCoveragePct,
                'annual_target_coverage_complete' => $annualTargetCoverageComplete,
                'annual_target_months_configured' => (int) (
                    $annualTargets['months_configured'][$companyId] ?? 0
                ),
                'annual_target_months_expected' => (int) ($annualTargets['months_expected'] ?? 0),
                'comparison_revenue' => Support::money($comparisonRevenue),
                'revenue_delta_pct' => $delta,
                'comparison_status' => $comparisonStatus,
                'orders' => $orders,
                'customers' => $customers,
                'ticket_avg' => Support::money($ticket),
                'vendors' => $vendors,
                'sales_status' => $salesStatus,
                'integration_status' => $integrationStatus,
                'last_source_at' => $lastSourceAt,
                'has_month_data' => $invoice !== null,
                'has_annual_data' => $annualInvoice !== null,
                'has_data' => $sourceAvailable,
            ];
        }

        usort(
            $rows,
            static fn (array $left, array $right): int =>
                ($right['revenue'] <=> $left['revenue'])
                ?: strcmp((string) $left['name'], (string) $right['name']),
        );
        foreach ($rows as $index => &$row) {
            $row['rank'] = $index + 1;
            $row['share_pct'] = $groupRevenue > 0.0
                ? round(((float) $row['revenue'] / $groupRevenue) * 100, 1)
                : 0.0;
        }
        unset($row);

        $leader = null;
        foreach ($rows as $candidate) {
            if ((float) $candidate['revenue'] > 0.0) {
                $leader = $candidate;
                break;
            }
        }

        $groupAttainment = $groupTarget > 0.0
            ? round(($groupComparableRevenue / $groupTarget) * 100, 1)
            : null;
        $groupPace = $groupTarget > 0.0 && $periodProgress['expected_pct'] > 0.0
            ? round(
                ($groupComparableRevenue / ($groupTarget * $periodProgress['expected_pct'] / 100)) * 100,
                1,
            )
            : null;
        $groupAnnualAttainment = $groupAnnualTarget > 0.0
            ? round(($groupAnnualComparableRevenue / $groupAnnualTarget) * 100, 1)
            : null;
        $groupAnnualPace = $groupExpectedAnnualTarget > 0.0
            ? round(($groupAnnualComparableRevenue / $groupExpectedAnnualTarget) * 100, 1)
            : null;
        $targetCoverageRevenuePct = $groupRevenue > 0.0
            ? round(($groupComparableRevenue / $groupRevenue) * 100, 1)
            : 100.0;
        $targetCoverageComplete = $targetDefinitionsComplete
            && (
                $groupRevenue <= 0.009
                || abs($groupComparableRevenue - $groupRevenue) < 0.01
            );
        $annualTargetCoverageRevenuePct = $groupAnnualRevenue > 0.0
            ? round(($groupAnnualComparableRevenue / $groupAnnualRevenue) * 100, 1)
            : 100.0;
        $annualTargetCoverageComplete = $annualTargetDefinitionsComplete
            && (
                $groupAnnualRevenue <= 0.009
                || abs($groupAnnualComparableRevenue - $groupAnnualRevenue) < 0.01
            );

        return [
            'ok' => true,
            'generated_at' => date(DATE_ATOM),
            'source_refreshed_at' => $sourceRefreshedAt,
            'data_status' => $sourceAvailable ? 'available' : 'unavailable',
            'warnings' => $warnings,
            'period' => [
                'start' => $start,
                'end' => $end,
                'annual_start' => $annualStart,
                'label' => $this->periodLabel($start),
                'exact_label' => $this->exactPeriodLabel($start, $end),
                'year_month' => substr($start, 0, 7),
                'expected_pct' => $periodProgress['expected_pct'],
                'elapsed_days' => $periodProgress['elapsed_days'],
                'days_in_month' => $periodProgress['days_in_month'],
                'comparison_start' => $comparison['start'],
                'comparison_end' => $comparison['end'],
                'comparison_label' => $comparison['label'],
            ],
            'group' => [
                'revenue' => Support::money($groupRevenue),
                'retail_revenue' => Support::money($groupRetailRevenue),
                'bidding_revenue' => Support::money($groupBiddingRevenue),
                'target' => Support::money($groupTarget),
                'comparable_revenue' => Support::money($groupComparableRevenue),
                'attainment_pct' => $targetCoverageComplete ? $groupAttainment : null,
                'paired_attainment_pct' => $groupAttainment,
                'expected_pct' => $periodProgress['expected_pct'],
                'pace_pct' => $targetCoverageComplete ? $groupPace : null,
                'paired_pace_pct' => $groupPace,
                'projection' => Support::money(
                    $periodProgress['expected_pct'] > 0.0
                        ? $groupRevenue / ($periodProgress['expected_pct'] / 100)
                        : 0.0,
                ),
                'gap' => Support::money($groupTarget - $groupComparableRevenue),
                'annual_revenue' => Support::money($groupAnnualRevenue),
                'annual_retail_revenue' => Support::money($groupAnnualRetailRevenue),
                'annual_bidding_revenue' => Support::money($groupAnnualBiddingRevenue),
                'annual_target' => Support::money($groupAnnualTarget),
                'annual_comparable_revenue' => Support::money($groupAnnualComparableRevenue),
                'annual_attainment_pct' => $annualTargetCoverageComplete ? $groupAnnualAttainment : null,
                'annual_paired_attainment_pct' => $groupAnnualAttainment,
                'annual_pace_pct' => $annualTargetCoverageComplete ? $groupAnnualPace : null,
                'annual_paired_pace_pct' => $groupAnnualPace,
                'orders' => $groupOrders,
                'companies_count' => count($rows),
                'companies_with_sales' => $companiesWithSales,
                'companies_with_target' => $companiesWithTargets,
                'companies_without_target' => $companiesWithoutTargets,
                'target_coverage' => [
                    'complete' => $targetCoverageComplete,
                    'companies_with_target' => $companiesWithTargets,
                    'companies_total' => count($rows),
                    'revenue_pct' => $targetCoverageRevenuePct,
                ],
                'annual_target_coverage' => [
                    'complete' => $annualTargetCoverageComplete,
                    'revenue_pct' => $annualTargetCoverageRevenuePct,
                ],
                'leader' => $leader === null ? null : [
                    'name' => $leader['name'],
                    'slug' => $leader['slug'],
                    'revenue' => $leader['revenue'],
                    'share_pct' => $groupRevenue > 0.0
                        ? round(((float) $leader['revenue'] / $groupRevenue) * 100, 1)
                        : 0.0,
                    'pace_pct' => $leader['pace_pct'],
                ],
            ],
            'companies' => $rows,
        ];
    }

    private function canonicalPdo(): ?PDO
    {
        try {
            $connection = Database::isConfigured($this->config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
            if (!Database::isConfigured($this->config, $connection)) {
                return null;
            }
            if (TenantContext::isBooted()
                && ($connection === 'db' || $this->sameDatabaseConfig('db', $connection))
            ) {
                return TenantContext::storageConnection();
            }

            return Database::connect($this->config, $connection);
        } catch (Throwable $exception) {
            $this->logger->warning('Board: conexao canonica indisponivel.', [
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function sameDatabaseConfig(string $left, string $right): bool
    {
        foreach (['driver', 'host', 'port', 'name', 'user'] as $field) {
            $default = $field === 'port' ? 3306 : '';
            if ((string) $this->config->get($left . '.' . $field, $default)
                !== (string) $this->config->get($right . '.' . $field, $default)
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Carrega metas mensal e YTD com uma unica leitura de cada fonte. A versao
     * anterior repetia as mesmas consultas para o mes e para o ano.
     *
     * @param list<int> $ids
     * @param array<int,list<string>> $channelsByCompany
     * @return array{month:array<string,mixed>,annual:array<string,mixed>}
     */
    private function loadTargetPeriodsByCompany(
        PDO $pdo,
        array $ids,
        string $annualFromYearMonth,
        string $currentYearMonth,
        array $channelsByCompany,
    ): array {
        $monthUnavailable = $this->emptyTargetResult();
        $monthUnavailable['months_expected'] = 1;
        $annualUnavailable = $this->emptyTargetResult();
        $annualUnavailable['months_expected'] = $this->monthSpanCount(
            $annualFromYearMonth,
            $currentYearMonth,
        );
        if ($ids === []) {
            return ['month' => $monthUnavailable, 'annual' => $annualUnavailable];
        }

        /** @var array<int,array<string,float>> $sellerTargets */
        $sellerTargets = [];
        /** @var array<int,array<string,array<string,true>>> $sellerIds */
        $sellerIds = [];
        /** @var array<int,array<string,float>> $companyTargets */
        $companyTargets = [];

        try {
            $queries = [];
            $params = [];
            if (SchemaCapabilities::tableExists($pdo, 'biomie_sales_targets_monthly')) {
                [$inClause, $sellerParams] = $this->idPlaceholders($ids, 'seller_period_company_');
                $sellerParams['seller_period_from'] = $annualFromYearMonth;
                $sellerParams['seller_period_to'] = $currentYearMonth;
                $params += $sellerParams;
                $queries[] = "
                    SELECT 'seller' AS source_type,
                           company_id,
                           vendor_id AS seller_id,
                           `year_month` AS target_month,
                           COALESCE(SUM(target_amount), 0) AS total
                      FROM biomie_sales_targets_monthly
                     WHERE `year_month` BETWEEN :seller_period_from AND :seller_period_to
                       AND company_id IN ({$inClause})
                     GROUP BY company_id, vendor_id, `year_month`
                ";
            }

            if (SchemaCapabilities::tableExists($pdo, 'biomie_company_targets')) {
                [$inClause, $companyParams] = $this->idPlaceholders($ids, 'target_period_company_');
                $companyParams['target_period_from'] = $annualFromYearMonth;
                $companyParams['target_period_to'] = $currentYearMonth;
                $channelClauses = [];
                foreach ($ids as $index => $companyId) {
                    $allowed = array_values(array_intersect(
                        ['varejo', 'licitacao'],
                        $channelsByCompany[$companyId] ?? [],
                    ));
                    if ($allowed === []) {
                        continue;
                    }
                    $companyKey = 'target_period_scope_company_' . $index;
                    $companyParams[$companyKey] = $companyId;
                    $channelPlaceholders = [];
                    foreach ($allowed as $channelIndex => $channel) {
                        $channelKey = 'target_period_scope_channel_' . $index . '_' . $channelIndex;
                        $companyParams[$channelKey] = $channel;
                        $channelPlaceholders[] = ':' . $channelKey;
                    }
                    $channelClauses[] = sprintf(
                        '(company_id = :%s AND canal IN (%s))',
                        $companyKey,
                        implode(', ', $channelPlaceholders),
                    );
                }
                $params += $companyParams;
                $scope = $channelClauses === [] ? '1 = 0' : implode(' OR ', $channelClauses);
                $queries[] = "
                    SELECT 'company' AS source_type,
                           company_id,
                           '' AS seller_id,
                           competencia AS target_month,
                           COALESCE(SUM(meta_valor), 0) AS total
                      FROM biomie_company_targets
                     WHERE competencia BETWEEN :target_period_from AND :target_period_to
                       AND ativo = 1
                       AND company_id IN ({$inClause})
                       AND ({$scope})
                     GROUP BY company_id, competencia
                ";
            }

            if ($queries !== []) {
                $stmt = $pdo->prepare(implode(' UNION ALL ', $queries));
                $stmt->execute($params);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $companyId = (int) ($row['company_id'] ?? 0);
                    $yearMonth = trim((string) ($row['target_month'] ?? ''));
                    if ($companyId <= 0 || $yearMonth === '') {
                        continue;
                    }
                    if (($row['source_type'] ?? '') === 'seller') {
                        $sellerTargets[$companyId][$yearMonth] =
                            ($sellerTargets[$companyId][$yearMonth] ?? 0.0)
                            + (float) ($row['total'] ?? 0);
                        $sellerId = trim((string) ($row['seller_id'] ?? ''));
                        if ($sellerId !== '') {
                            $sellerIds[$companyId][$yearMonth][$sellerId] = true;
                        }
                    } else {
                        $companyTargets[$companyId][$yearMonth] =
                            ($companyTargets[$companyId][$yearMonth] ?? 0.0)
                            + (float) ($row['total'] ?? 0);
                    }
                }
            }
        } catch (Throwable $exception) {
            $this->logger->warning('Board: metas por empresa indisponiveis.', [
                'message' => $exception->getMessage(),
                'from_year_month' => $annualFromYearMonth,
                'to_year_month' => $currentYearMonth,
            ]);
            $monthUnavailable['available'] = false;
            $annualUnavailable['available'] = false;

            return ['month' => $monthUnavailable, 'annual' => $annualUnavailable];
        }

        return [
            'month' => $this->selectTargetPeriod(
                $ids,
                $currentYearMonth,
                $currentYearMonth,
                $sellerTargets,
                $sellerIds,
                $companyTargets,
            ),
            'annual' => $this->selectTargetPeriod(
                $ids,
                $annualFromYearMonth,
                $currentYearMonth,
                $sellerTargets,
                $sellerIds,
                $companyTargets,
            ),
        ];
    }

    /**
     * @param list<int> $ids
     * @param array<int,array<string,float>> $sellerTargets
     * @param array<int,array<string,array<string,true>>> $sellerIds
     * @param array<int,array<string,float>> $companyTargets
     * @return array<string,mixed>
     */
    private function selectTargetPeriod(
        array $ids,
        string $fromYearMonth,
        string $toYearMonth,
        array $sellerTargets,
        array $sellerIds,
        array $companyTargets,
    ): array {
        $values = [];
        $sources = [];
        $grains = [];
        $selectedSellerIds = [];
        $monthsConfigured = [];

        foreach ($ids as $companyId) {
            $sellerTotal = 0.0;
            $sellerMonths = [];
            $sellerSet = [];
            foreach ($sellerTargets[$companyId] ?? [] as $yearMonth => $amount) {
                if ($yearMonth < $fromYearMonth || $yearMonth > $toYearMonth) {
                    continue;
                }
                $sellerTotal += $amount;
                $sellerMonths[$yearMonth] = true;
                foreach ($sellerIds[$companyId][$yearMonth] ?? [] as $sellerId => $_present) {
                    $sellerSet[$sellerId] = true;
                }
            }

            $companyTotal = 0.0;
            $companyMonths = [];
            foreach ($companyTargets[$companyId] ?? [] as $yearMonth => $amount) {
                if ($yearMonth < $fromYearMonth || $yearMonth > $toYearMonth) {
                    continue;
                }
                $companyTotal += $amount;
                $companyMonths[$yearMonth] = true;
            }

            if ($sellerTotal > 0.0) {
                $values[$companyId] = $sellerTotal;
                $sources[$companyId] = 'sales_targets_monthly';
                $grains[$companyId] = 'seller';
                $selectedSellerIds[$companyId] = array_values(array_keys($sellerSet));
                $monthsConfigured[$companyId] = count($sellerMonths);
            } elseif ($companyTotal > 0.0) {
                $values[$companyId] = $companyTotal;
                $sources[$companyId] = 'company_targets';
                $grains[$companyId] = 'company_channel';
                $monthsConfigured[$companyId] = count($companyMonths);
            }
        }

        return [
            'values' => $values,
            'sources' => $sources,
            'grains' => $grains,
            'seller_ids' => $selectedSellerIds,
            'months_configured' => $monthsConfigured,
            'months_expected' => $this->monthSpanCount($fromYearMonth, $toYearMonth),
            'available' => true,
        ];
    }

    /**
     * @param list<int> $ids
     * @return array{0:string,1:array<string,int>}
     */
    private function idPlaceholders(array $ids, string $prefix): array
    {
        $placeholders = [];
        $params = [];
        foreach (array_values($ids) as $index => $id) {
            $key = $prefix . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = (int) $id;
        }

        return [implode(', ', $placeholders), $params];
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyTargetResult(): array
    {
        return [
            'values' => [],
            'sources' => [],
            'grains' => [],
            'seller_ids' => [],
            'months_configured' => [],
            'months_expected' => 0,
            'available' => true,
        ];
    }

    private function monthSpanCount(string $fromYearMonth, string $toYearMonth): int
    {
        $from = DateTimeImmutable::createFromFormat('!Y-m', $fromYearMonth);
        $to = DateTimeImmutable::createFromFormat('!Y-m', $toYearMonth);
        if (!$from instanceof DateTimeImmutable || !$to instanceof DateTimeImmutable || $to < $from) {
            return 0;
        }

        return ((int) $to->format('Y') - (int) $from->format('Y')) * 12
            + ((int) $to->format('n') - (int) $from->format('n'))
            + 1;
    }

    /**
     * @param array<string,mixed> $company
     * @return array{slug:string,name:string,initials:string,logo:string,color:string}
     */
    private function companyIdentity(array $company): array
    {
        $slug = (string) ($company['slug'] ?? '');
        $name = (string) ($company['nome_fantasia'] ?? $slug);
        $initials = '';
        $color = '#3b82f6';
        $logo = '';

        try {
            $manifest = CompanyPackageLoader::load($slug);
            $name = $manifest->name() ?: $name;
            $initials = $manifest->initials();
            $color = $manifest->brandColor() ?: $color;
            $logoPath = $manifest->logoIconPath();
            if ($logoPath !== '' && is_file(\BIomie\Bootstrap::rootPath($logoPath))) {
                $tvLogoPath = 'images/logos/tv/' . basename($logoPath);
                $logo = is_file(\BIomie\Bootstrap::rootPath($tvLogoPath))
                    ? $tvLogoPath
                    : $logoPath;
            }
        } catch (Throwable) {
            // A identidade cadastrada na empresa continua sendo um fallback válido.
        }

        return [
            'slug' => $slug,
            'name' => $name,
            'initials' => $initials !== '' ? $initials : mb_strtoupper(mb_substr($name, 0, 2)),
            'logo' => $logo,
            'color' => $color,
        ];
    }

    /**
     * @param list<string> $channels
     */
    private function channelLabel(array $channels): string
    {
        if (count($channels) > 1) {
            return 'Varejo + Licitação';
        }

        return ($channels[0] ?? '') === 'licitacao' ? 'Licitação' : 'Varejo';
    }

    /**
     * @param list<string> $allowedChannels
     */
    private function primaryChannel(
        array $allowedChannels,
        float $retailRevenue,
        float $biddingRevenue,
        float $annualRetailRevenue,
        float $annualBiddingRevenue,
    ): string {
        if ($retailRevenue !== $biddingRevenue && ($retailRevenue > 0.0 || $biddingRevenue > 0.0)) {
            return $retailRevenue > $biddingRevenue ? 'varejo' : 'licitacao';
        }
        if ($annualRetailRevenue !== $annualBiddingRevenue) {
            return $annualRetailRevenue > $annualBiddingRevenue ? 'varejo' : 'licitacao';
        }

        return $allowedChannels[0] ?? 'varejo';
    }

    private function goalStatus(bool $hasTarget, ?float $attainment, ?float $pace): string
    {
        if (!$hasTarget) {
            return 'no_target';
        }
        if (($attainment ?? 0.0) >= 100.0) {
            return 'achieved';
        }
        if (($pace ?? 0.0) >= 95.0) {
            return 'on_track';
        }
        if (($pace ?? 0.0) >= 75.0) {
            return 'attention';
        }

        return 'risk';
    }

    /**
     * @param array<string,mixed> $company
     */
    private function integrationStatus(array $company, ?string $lastSourceAt): string
    {
        $sourceTime = $lastSourceAt !== null ? strtotime($lastSourceAt) : false;
        if ($sourceTime !== false && $sourceTime >= time() - 86400) {
            return 'fresh';
        }
        if ((int) ($company['integration_configured'] ?? 0) !== 1) {
            return 'unconfigured';
        }
        if ((int) ($company['integration_active'] ?? 0) !== 1) {
            return 'inactive';
        }

        $success = trim((string) ($company['integration_last_success_at'] ?? ''));
        $error = trim((string) ($company['integration_last_error_at'] ?? ''));
        if ($error !== '' && ($success === '' || strtotime($error) > strtotime($success))) {
            return 'error';
        }
        if ($success === '') {
            return 'pending';
        }
        $successTime = strtotime($success);
        if ($successTime !== false && $successTime < time() - 86400) {
            return 'stale';
        }

        return 'fresh';
    }

    /**
     * @param list<mixed> $timestamps
     */
    private function latestTimestamp(array $timestamps): ?string
    {
        $latestValue = null;
        $latestTime = null;
        foreach ($timestamps as $timestamp) {
            $value = trim((string) ($timestamp ?? ''));
            if ($value === '') {
                continue;
            }
            $time = strtotime($value);
            if ($time === false) {
                continue;
            }
            if ($latestTime === null || $time > $latestTime) {
                $latestTime = $time;
                $latestValue = $value;
            }
        }

        return $latestValue;
    }

    /**
     * @return array{start:string,end:string,label:string}
     */
    private function comparisonPeriod(string $start, string $end): array
    {
        $startDate = DateTimeImmutable::createFromFormat('!Y-m-d', $start)
            ?: new DateTimeImmutable('first day of this month');
        $endDate = DateTimeImmutable::createFromFormat('!Y-m-d', $end) ?: new DateTimeImmutable('today');
        $previousStart = $startDate->modify('first day of previous month');
        $day = min((int) $endDate->format('j'), (int) $previousStart->format('t'));
        $previousEnd = $previousStart->setDate(
            (int) $previousStart->format('Y'),
            (int) $previousStart->format('m'),
            $day,
        );

        return [
            'start' => $previousStart->format('Y-m-d'),
            'end' => $previousEnd->format('Y-m-d'),
            'label' => sprintf(
                '01–%02d %s',
                $day,
                $this->monthShort((int) $previousStart->format('n')),
            ),
        ];
    }

    /**
     * @return array{expected_pct:float,elapsed_days:int,days_in_month:int}
     */
    private function periodProgress(string $start, string $end): array
    {
        $startDate = DateTimeImmutable::createFromFormat('!Y-m-d', $start)
            ?: new DateTimeImmutable('first day of this month');
        $endDate = DateTimeImmutable::createFromFormat('!Y-m-d', $end) ?: new DateTimeImmutable('today');
        $daysInMonth = (int) $startDate->format('t');
        $elapsedDays = max(0, min(
            $daysInMonth,
            (int) $startDate->diff($endDate)->format('%r%a') + 1,
        ));

        return [
            'expected_pct' => round(($elapsedDays / max(1, $daysInMonth)) * 100, 1),
            'elapsed_days' => $elapsedDays,
            'days_in_month' => $daysInMonth,
        ];
    }

    private function periodLabel(string $start): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $start)
            ?: new DateTimeImmutable('first day of this month');

        return $this->monthLong((int) $date->format('n')) . ' / ' . $date->format('Y');
    }

    private function exactPeriodLabel(string $start, string $end): string
    {
        $startDate = DateTimeImmutable::createFromFormat('!Y-m-d', $start)
            ?: new DateTimeImmutable('first day of this month');
        $endDate = DateTimeImmutable::createFromFormat('!Y-m-d', $end) ?: new DateTimeImmutable('today');

        return sprintf(
            '%02d–%02d %s %s',
            (int) $startDate->format('j'),
            (int) $endDate->format('j'),
            $this->monthShort((int) $startDate->format('n')),
            $endDate->format('Y'),
        );
    }

    private function monthLong(int $month): string
    {
        $months = [
            1 => 'Janeiro',
            2 => 'Fevereiro',
            3 => 'Março',
            4 => 'Abril',
            5 => 'Maio',
            6 => 'Junho',
            7 => 'Julho',
            8 => 'Agosto',
            9 => 'Setembro',
            10 => 'Outubro',
            11 => 'Novembro',
            12 => 'Dezembro',
        ];

        return $months[$month] ?? '';
    }

    private function monthShort(int $month): string
    {
        $months = [
            1 => 'jan',
            2 => 'fev',
            3 => 'mar',
            4 => 'abr',
            5 => 'mai',
            6 => 'jun',
            7 => 'jul',
            8 => 'ago',
            9 => 'set',
            10 => 'out',
            11 => 'nov',
            12 => 'dez',
        ];

        return $months[$month] ?? '';
    }
}

<?php

declare(strict_types=1);

namespace BIomie\Modules\Sales\Application\Geo;

use BIomie\Modules\Sales\Infrastructure\Geo360ReadRepository;

final class Geo360ReadService
{
    public function __construct(private readonly Geo360ReadRepository $repo) {}

    /**
     * @param string|null $tab  null = tudo; 'resumo'|'compradores'|'cnaes'|'nfs'|'vendedores'|'evolucao'|'diagnostico'
     */
    public function build(
        string $neighborhoodKey,
        string $neighborhood,
        string $city,
        string $start,
        string $end,
        int    $limit,
        ?string $tab
    ): array {
        $loadAll     = $tab === null;
        $loadResumo  = $loadAll || $tab === 'resumo';
        $loadBuyers  = $loadAll || $tab === 'compradores';
        $loadCnaes   = $loadAll || $tab === 'cnaes';
        $loadNfs     = $loadAll || $tab === 'nfs';
        $loadVendors = $loadAll || $tab === 'vendedores';
        $loadTimeline = $loadAll || $tab === 'evolucao';

        $summary   = $loadResumo  ? $this->repo->summary($neighborhoodKey, $start, $end)          : null;
        $buyers    = $loadBuyers  ? $this->repo->buyers($neighborhoodKey, $start, $end, $limit)   : null;
        $cnaes     = $loadCnaes   ? $this->repo->cnaes($neighborhoodKey, $start, $end, $limit)    : null;
        $invoices  = $loadNfs     ? $this->repo->invoices($neighborhoodKey, $start, $end, $limit) : null;
        $vendors   = $loadVendors ? $this->repo->vendors($neighborhoodKey, $start, $end, $limit)  : null;
        $timeline  = $loadTimeline ? $this->repo->timeline($neighborhoodKey, $start, $end)        : null;

        $goldAt      = $this->repo->lastGoldMaterializedAt($neighborhoodKey);
        $canonicalAt = $this->repo->lastCanonicalNfAt();
        $officialName = $this->repo->officialNeighborhoodName($neighborhoodKey);

        $freshnessStatus = $this->freshnessStatus();
        $goldFreshness   = $freshnessStatus($goldAt);
        $canonFreshness  = $freshnessStatus($canonicalAt);
        $overallFreshness = $this->worstFreshness($goldFreshness, $canonFreshness);

        $revenue    = (float) ($summary['revenue'] ?? 0);
        $sourceFlag = $revenue > 0 ? 'gold_mart' : 'empty';

        $totalRevenue = array_sum(array_column($buyers ?? [], 'revenue'));
        if ($buyers !== null && $totalRevenue > 0) {
            foreach ($buyers as &$b) {
                $b['participation_pct'] = round(((float) $b['revenue'] / $totalRevenue) * 100, 1);
            }
            unset($b);
        }

        $totalCnaeRev = array_sum(array_column($cnaes ?? [], 'revenue'));
        if ($cnaes !== null && $totalCnaeRev > 0) {
            foreach ($cnaes as &$c) {
                $c['participation_pct'] = round(((float) $c['revenue'] / $totalCnaeRev) * 100, 1);
            }
            unset($c);
        }

        $totalVendorRev = array_sum(array_column($vendors ?? [], 'revenue'));
        if ($vendors !== null && $totalVendorRev > 0) {
            foreach ($vendors as &$v) {
                $v['participation_pct'] = round(((float) $v['revenue'] / $totalVendorRev) * 100, 1);
            }
            unset($v);
        }

        $timelineFormatted = null;
        if ($timeline !== null) {
            $timelineFormatted = array_map(static fn (array $row): array => [
                'bucket'          => $row['bucket'],
                'revenue'         => $row['revenue'],
                'revenue_raw'     => (float) $row['revenue'],
                'invoices_count'  => (int) $row['invoices_count'],
                'customers_count' => (int) $row['customers_count'],
            ], $timeline);
        }

        $payload = [
            'ok'     => true,
            'source' => $sourceFlag,
            'neighborhood' => [
                'key'           => $neighborhoodKey,
                'name'          => $officialName ?? $neighborhood,
                'display_name'  => $officialName ?? $neighborhood,
                'city'          => $city,
            ],
            'period' => ['start' => $start, 'end' => $end],
            'period_basis' => [
                'realized'  => 'data_competencia',
                'source'    => 'mart_compradores_por_geo',
            ],
        ];

        if ($summary !== null) {
            $payload['summary'] = [
                'neighborhood_key'   => $neighborhoodKey,
                'neighborhood_name'  => $officialName ?? $neighborhood,
                'city'               => $city,
                'revenue'            => $summary['revenue'],
                'invoices_count'     => (int) ($summary['invoices_count'] ?? 0),
                'customers_count'    => (int) ($summary['customers_count'] ?? 0),
                'cnaes_count'        => (int) ($summary['cnaes_count'] ?? 0),
                'avg_ticket'         => $summary['avg_ticket'],
                'first_sale_at'      => $summary['first_sale_at'] ?? null,
                'last_sale_at'       => $summary['last_sale_at'] ?? null,
                'source'             => $sourceFlag,
                'realized_period_basis' => 'data_competencia',
            ];
        }

        if ($buyers !== null)  $payload['buyers']   = $buyers;
        if ($cnaes !== null)   $payload['cnaes']    = $cnaes;
        if ($invoices !== null) $payload['invoices'] = $invoices;
        if ($vendors !== null) $payload['vendors']  = $vendors;
        if ($timelineFormatted !== null) $payload['timeline'] = $timelineFormatted;

        $payload['data_quality'] = [
            'mart_rows_count'          => $this->repo->martRowCount($neighborhoodKey, $start, $end),
            'source'                   => $sourceFlag,
            'freshness_status'         => $overallFreshness,
            'gold_freshness_status'    => $goldFreshness,
            'canonical_freshness_status' => $canonFreshness,
            'last_gold_materialized_at'  => $goldAt,
            'last_canonical_nf_at'       => $canonicalAt,
            'warnings'                 => $this->buildWarnings($revenue, $goldAt),
        ];

        $payload['empty_states'] = $this->buildEmptyStates($revenue, $goldAt, $overallFreshness);

        return $payload;
    }

    private function freshnessStatus(): \Closure
    {
        return static function (?string $at): string {
            if ($at === null || $at === '') return 'unknown';
            $age = time() - strtotime($at);
            if ($age <= 86400)  return 'ok';
            if ($age <= 259200) return 'warning';
            return 'critical';
        };
    }

    private function worstFreshness(string $a, string $b): string
    {
        $order = ['critical' => 3, 'warning' => 2, 'unknown' => 1, 'ok' => 0];
        return ($order[$a] ?? 0) >= ($order[$b] ?? 0) ? $a : $b;
    }

    private function buildWarnings(float $revenue, ?string $goldAt): array
    {
        $warnings = [];
        if ($revenue <= 0.0 && $goldAt !== null) {
            $warnings[] = 'Bairro sem receita no período — mart materializado mas sem vendas para este bairro.';
        }
        if ($goldAt === null) {
            $warnings[] = 'mart_compradores_por_geo nunca foi materializado para este bairro.';
        }
        return $warnings;
    }

    private function buildEmptyStates(float $revenue, ?string $goldAt, string $freshness): array
    {
        $salesEmpty = $revenue <= 0.0;
        $salesReason = null;
        if ($salesEmpty) {
            $salesReason = ($freshness === 'critical' || $goldAt === null) ? 'sync_stale' : 'no_sales_in_period';
        }

        return [
            'sales' => [
                'is_empty'    => $salesEmpty,
                'reason_code' => $salesReason,
            ],
            'buyers' => [
                'is_empty'    => $salesEmpty,
                'reason_code' => $salesEmpty ? 'no_sales_in_period' : null,
            ],
        ];
    }
}

<?php

declare(strict_types=1);

namespace BIomie\Modules\Finance\Application;

use BIomie\Logger;
use BIomie\Modules\Finance\Infrastructure\FinanceReadRepository;
use BIomie\Support;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Generates finance_summary for the "Recebimentos e Risco" card.
 * Uses SQL aggregations — no SELECT *, no N+1.
 */
final class FinanceOverviewBuilder
{
    private const RISK_OVERDUE_CRITICAL_AMOUNT = 50000.0;
    private const RISK_OVERDUE_CRITICAL_RATIO  = 0.20;
    private const RISK_CASH_CONV_LOW           = 40.0;

    public function __construct(
        private readonly FinanceReadRepository $repo,
    ) {
    }

    /**
     * @return array<string,mixed>  finance_summary contract
     */
    public function build(
        string $start,
        string $end,
        ?string $referenceDate = null,
        float $revenueReference = 0.0,
        string $requestId = '',
        ?Logger $logger = null
    ): array {
        $t0  = microtime(true);
        $today = date('Y-m-d');
        $ref = $referenceDate ?? (strtotime($end) > strtotime($today) ? $today : $end);

        $diagnostics = [
            'runtime_ms'      => 0,
            'query_count'     => 0,
            'fields_detected' => $this->repo->detectFinanceFields(),
            'errors'          => [],
        ];

        // ── Table check ──────────────────────────────────────────────────────
        $tableInfo = $this->repo->tableInfo();
        if (!$tableInfo['exists']) {
            return $this->unavailable('finance_table_missing', $start, $end, $ref, $diagnostics, $t0);
        }
        if ($tableInfo['total_rows'] === 0) {
            return $this->unavailable('finance_table_empty', $start, $end, $ref, $diagnostics, $t0);
        }

        // ── Aggregated totals ────────────────────────────────────────────────
        $totalsRaw = null;
        try {
            $totalsRaw = $this->repo->summarizeReceivables($start, $end, $ref);
            $diagnostics['query_count']++;
        } catch (Throwable $e) {
            $diagnostics['errors'][] = ['step' => 'summarize', 'message' => $e->getMessage()];
            $logger?->warning('FinanceOverviewBuilder: summarizeReceivables failed.', [
                'message'    => $e->getMessage(),
                'request_id' => $requestId,
            ]);
            return $this->unavailable('query_error', $start, $end, $ref, $diagnostics, $t0);
        }

        if ($totalsRaw['received'] <= 0 && $totalsRaw['open'] <= 0 && $totalsRaw['overdue'] <= 0) {
            // Table exists but nothing in this period
            $diagnostics['runtime_ms'] = (int) round((microtime(true) - $t0) * 1000);
            return array_merge(
                $this->buildEmptyPeriod($start, $end, $ref),
                ['diagnostics' => $diagnostics]
            );
        }

        // ── Overdue customers ────────────────────────────────────────────────
        $overdueCustomers = [];
        try {
            $overdueCustomers = $this->repo->listOverdueCustomers($start, $end, $ref, 10);
            $diagnostics['query_count']++;
        } catch (Throwable $e) {
            $diagnostics['errors'][] = ['step' => 'overdue_customers', 'message' => $e->getMessage()];
        }

        // ── Top open titles ──────────────────────────────────────────────────
        $topOpenTitles = [];
        try {
            $topOpenTitles = $this->repo->listTopOpenTitles($start, $end, $ref, 10);
            $diagnostics['query_count']++;
        } catch (Throwable $e) {
            $diagnostics['errors'][] = ['step' => 'top_open_titles', 'message' => $e->getMessage()];
        }

        // ── Cash conversion ──────────────────────────────────────────────────
        $received         = $totalsRaw['received'];
        $open             = $totalsRaw['open'];
        $overdue          = $totalsRaw['overdue'];
        $cashConversionPct = $revenueReference > 0
            ? round(($received / $revenueReference) * 100, 2)
            : null;

        // ── Risk level ───────────────────────────────────────────────────────
        [$riskLevel, $riskScore, $riskReasons] = $this->computeRisk(
            $overdue,
            $revenueReference,
            $cashConversionPct
        );

        $diagnostics['runtime_ms'] = (int) round((microtime(true) - $t0) * 1000);
        if ($diagnostics['runtime_ms'] > 300) {
            $diagnostics['performance_warning'] = 'runtime_ms > 300ms — verificar índices em biomie_titulos_financeiros_canonicos';
        }

        return [
            'available' => true,
            'source'    => 'canonical',
            'period'    => [
                'start'          => $start,
                'end'            => $end,
                'reference_date' => $ref,
            ],
            'basis' => [
                'received_period_basis' => 'data_liquidacao (classif. por data_competencia)',
                'open_reference_basis'  => 'data_vencimento',
                'overdue_reference_basis' => 'data_vencimento < reference_date',
                'revenue_period_basis'  => 'data_competencia (NFs autorizadas)',
            ],
            'totals' => [
                'received'           => round($received, 2),
                'open'               => round($open, 2),
                'overdue'            => round($overdue, 2),
                'to_receive'         => round($open, 2),
                'revenue_reference'  => round($revenueReference, 2),
                'cash_conversion_pct' => $cashConversionPct,
            ],
            'counts' => [
                'received_titles'   => $totalsRaw['received_titles'],
                'open_titles'       => $totalsRaw['open_titles'],
                'overdue_titles'    => $totalsRaw['overdue_titles'],
                'overdue_customers' => $totalsRaw['overdue_customers'],
            ],
            'risk' => [
                'level'   => $riskLevel,
                'score'   => $riskScore,
                'reasons' => $riskReasons,
            ],
            'overdue_customers' => $overdueCustomers,
            'top_open_titles'   => $topOpenTitles,
            'reason_code'       => null,
            'diagnostics'       => $diagnostics,
        ];
    }

    /**
     * Maps the finance_summary to the format expected by B.renderCommercialHealth.
     * Allows commercial_health.php to use canonical finance as drop-in.
     *
     * @return array<string,mixed>
     */
    public static function toHealthFormat(array $financeSummary): array
    {
        $totals = $financeSummary['totals'] ?? [];
        $counts = $financeSummary['counts'] ?? [];

        return [
            'ok'        => true,
            'available' => (bool) ($financeSummary['available'] ?? false),
            'source'    => 'canonical-finance',
            'summary'   => [
                'received'           => (float) ($totals['received'] ?? 0),
                'open'               => (float) ($totals['open'] ?? 0),
                'overdue'            => (float) ($totals['overdue'] ?? 0),
                'cash_conversion_pct' => $totals['cash_conversion_pct'] ?? 0,
                'delinquency_rate'   => 0.0,
                'received_titles'    => (int) ($counts['received_titles'] ?? 0),
                'open_titles'        => (int) ($counts['open_titles'] ?? 0),
                'overdue_titles'     => (int) ($counts['overdue_titles'] ?? 0),
            ],
            'top_overdue_clients' => array_map(static fn (array $r): array => [
                'client_name'   => (string) ($r['customer_name'] ?? 'Cliente nao informado'),
                'client_document' => (string) ($r['document'] ?? ''),
                'overdue'       => (float) ($r['overdue_amount'] ?? 0),
                'titles'        => (int) ($r['titles_count'] ?? 0),
                'max_days_late' => (int) ($r['max_days_overdue'] ?? 0),
                'cause_label'   => '',
            ], (array) ($financeSummary['overdue_customers'] ?? [])),
        ];
    }

    /**
     * @return array{0:string,1:int,2:list<string>}
     */
    private function computeRisk(float $overdue, float $revenue, ?float $cashConvPct): array
    {
        if ($overdue <= 0 && $revenue <= 0) {
            return ['unknown', 0, []];
        }

        $reasons = [];
        $score   = 0;

        if ($overdue >= self::RISK_OVERDUE_CRITICAL_AMOUNT) {
            $reasons[] = 'overdue_above_threshold';
            $score += 2;
        }
        if ($revenue > 0 && $overdue / $revenue >= self::RISK_OVERDUE_CRITICAL_RATIO) {
            $reasons[] = 'overdue_ratio_critical';
            $score += 2;
        }
        if ($cashConvPct !== null && $cashConvPct < self::RISK_CASH_CONV_LOW) {
            $reasons[] = 'low_cash_conversion';
            $score++;
        }

        $level = match (true) {
            $score >= 2 => 'critical',
            $score === 1 || $overdue > 0 => 'attention',
            default => 'ok',
        };

        return [$level, $score, $reasons];
    }

    /**
     * @return array<string,mixed>
     */
    private function unavailable(
        string $reasonCode,
        string $start,
        string $end,
        string $ref,
        array $diagnostics,
        float $t0
    ): array {
        $diagnostics['runtime_ms'] = (int) round((microtime(true) - $t0) * 1000);
        return [
            'available'   => false,
            'source'      => 'empty',
            'period'      => ['start' => $start, 'end' => $end, 'reference_date' => $ref],
            'totals'      => ['received' => 0.0, 'open' => 0.0, 'overdue' => 0.0, 'to_receive' => 0.0, 'revenue_reference' => 0.0, 'cash_conversion_pct' => null],
            'counts'      => ['received_titles' => 0, 'open_titles' => 0, 'overdue_titles' => 0, 'overdue_customers' => 0],
            'risk'        => ['level' => 'unknown', 'score' => 0, 'reasons' => []],
            'overdue_customers' => [],
            'top_open_titles'   => [],
            'reason_code' => $reasonCode,
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildEmptyPeriod(string $start, string $end, string $ref): array
    {
        return [
            'available' => false,
            'source'    => 'canonical',
            'period'    => ['start' => $start, 'end' => $end, 'reference_date' => $ref],
            'totals'    => ['received' => 0.0, 'open' => 0.0, 'overdue' => 0.0, 'to_receive' => 0.0, 'revenue_reference' => 0.0, 'cash_conversion_pct' => null],
            'counts'    => ['received_titles' => 0, 'open_titles' => 0, 'overdue_titles' => 0, 'overdue_customers' => 0],
            'risk'      => ['level' => 'unknown', 'score' => 0, 'reasons' => []],
            'overdue_customers' => [],
            'top_open_titles'   => [],
            'reason_code' => 'no_titles_in_period',
        ];
    }
}

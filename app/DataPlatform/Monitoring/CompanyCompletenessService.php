<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Monitoring;

use PDO;

/**
 * Computes per-company freshness and completeness signals.
 *
 * Score 0-100 based on 9 weighted dimensions (sum = 100).
 * Tiers: full (≥80) | operational (≥50) | minimal (≥20) | incomplete (<20).
 *
 * Designed for multi-company contexts — all queries are company_id-scoped.
 */
final class CompanyCompletenessService
{
    /** @var array<string,int> Weights sum to 100. */
    private const WEIGHTS = [
        'credentials_configured' => 15,
        'has_canonical_nfs'      => 15,
        'has_canonical_orders'   => 10,
        'has_clients_dim'        => 15,
        'has_finance_titles'     => 10,
        'has_geo_mart'           => 10,
        'has_product_mart'       => 10,
        'sync_commercial_fresh'  => 10,
        'sync_finance_fresh'     => 5,
        // 'coverage_no_gaps' is informational only — not scored
    ];

    private const COMMERCIAL_THRESHOLDS = ['ok' => 6,  'warn' => 36];
    private const FINANCE_THRESHOLDS    = ['ok' => 8,  'warn' => 48];

    /** @var array<string,string> table → alias */
    private const MART_TABLES = [
        'mart_produto_periodo'      => 'products',
        'mart_compradores_por_geo'  => 'geo_buyers',
        'mart_forecasts'            => 'forecasts',
        'mart_oportunidades'        => 'opportunities',
        'mart_vendas_por_cnae_cnpj' => 'cnae_sales',
        'mart_fiscal_items'         => 'fiscal_items',
        'mart_cliente_periodo'      => 'client_periods',
        'mart_vendedor_dia'         => 'vendor_days',
        'biomie_clientes_dim'       => 'clients_dim',
    ];

    public function __construct(private readonly PDO $pdo) {}

    /**
     * Analyse completeness for a list of company IDs.
     *
     * Pass $preloadedCredentials (keyed by company_id → ['configured' => bool]) to avoid
     * a redundant DB round-trip when the caller already loaded integration state.
     *
     * @param  list<int>                     $companyIds
     * @param  array<int,array<string,bool>> $preloadedCredentials
     * @return array<int,array<string,mixed>>  keyed by company_id
     */
    public function analyse(array $companyIds, array $preloadedCredentials = []): array
    {
        if ($companyIds === []) {
            return [];
        }

        $idList = implode(',', array_map('intval', $companyIds));

        $domains     = $this->queryDomains($idList);
        $canonical   = $this->queryCanonical($idList);
        $coverage    = $this->queryCoverage($idList);
        $marts       = $this->queryMarts($idList);
        $credentials = $preloadedCredentials !== [] ? $preloadedCredentials : $this->queryCredentials($idList);

        $expectedMonths = [];
        for ($i = 5; $i >= 0; $i--) {
            $expectedMonths[] = date('Y-m', strtotime("-{$i} months"));
        }

        $result = [];
        foreach ($companyIds as $cid) {
            $dom  = $domains[$cid]     ?? [];
            $can  = $canonical[$cid]   ?? [];
            $cov  = $coverage[$cid]    ?? [];
            $mart = $marts[$cid]       ?? [];
            $cred = $credentials[$cid] ?? [];

            // Coverage months — last 6
            $coverageMonths = [];
            $gapCount       = 0;
            $nfTotal        = (int) ($can['nf']['count'] ?? 0);
            foreach ($expectedMonths as $m) {
                $cnt    = $cov[$m] ?? 0;
                $status = $cnt === 0 ? 'empty' : ($cnt < 5 ? 'sparse' : 'ok');
                if ($status === 'empty') {
                    $gapCount++;
                }
                $coverageMonths[] = ['month' => $m, 'nf_count' => $cnt, 'status' => $status];
            }

            $dims = [
                'credentials_configured' => (bool) ($cred['configured'] ?? false),
                'has_canonical_nfs'      => $nfTotal > 0,
                'has_canonical_orders'   => ((int) ($can['orders']['count']  ?? 0)) > 0,
                'has_clients_dim'        => ((int) ($can['clients']['count'] ?? 0)) > 0,
                'has_finance_titles'     => ((int) ($can['finance']['count'] ?? 0)) > 0,
                'has_geo_mart'           => ((int) ($mart['geo_buyers']      ?? 0)) > 0,
                'has_product_mart'       => ((int) ($mart['products']        ?? 0)) > 0,
                'sync_commercial_fresh'  => $this->isFresh($dom['commercial'] ?? [], 24),
                'sync_finance_fresh'     => $this->isFresh($dom['finance']    ?? [], 48),
                'coverage_no_gaps'       => $nfTotal > 0 && $gapCount === 0,
            ];

            $score = 0;
            foreach (self::WEIGHTS as $dim => $weight) {
                if ($dims[$dim] ?? false) {
                    $score += $weight;
                }
            }

            $result[$cid] = [
                'score'           => $score,
                'tier'            => $this->tier($score),
                'dims'            => $dims,
                'sync_domains'    => $dom,
                'canonical'       => $can,
                'coverage_months' => $coverageMonths,
                'marts'           => $mart,
            ];
        }

        return $result;
    }

    /** @return array<string,int> Weights map, for use in CLI/display. */
    public static function weights(): array
    {
        return self::WEIGHTS;
    }

    // ── Private query methods ─────────────────────────────────────────────────

    /** @return array<int,array<string,array<string,mixed>>> keyed by company_id → domain_name */
    private function queryDomains(string $idList): array
    {
        $rows = $this->safeFetch(
            "SELECT company_id, domain_name,
                    MAX(CASE WHEN status IN ('success','completed','completed_with_warnings')
                             THEN finished_at END)                                         AS last_success_at,
                    MAX(CASE WHEN status IN ('failed','failed_retryable','failed_terminal')
                             THEN finished_at END)                                         AS last_failure_at,
                    SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END)                   AS running_count,
                    SUM(COALESCE(items_processed, 0))                                      AS total_items
             FROM biomie_sync_runs
             WHERE company_id IN ({$idList}) AND domain_name IN ('commercial','finance')
             GROUP BY company_id, domain_name"
        );

        $out = [];
        foreach ($rows as $row) {
            $cid    = (int)    $row['company_id'];
            $domain = (string) $row['domain_name'];
            $lastOk = (($row['last_success_at'] ?? '') !== '') ? (string) $row['last_success_at'] : null;

            $lagHours  = null;
            $domStatus = 'never';
            if ($lastOk !== null) {
                $lagHours  = max(0, (int) round((time() - strtotime($lastOk)) / 3600));
                $t         = $domain === 'commercial' ? self::COMMERCIAL_THRESHOLDS : self::FINANCE_THRESHOLDS;
                $domStatus = $lagHours <= $t['ok'] ? 'ok' : ($lagHours <= $t['warn'] ? 'warning' : 'critical');
            }

            $out[$cid][$domain] = [
                'status'          => $domStatus,
                'last_success_at' => $lastOk,
                'last_failure_at' => (($row['last_failure_at'] ?? '') !== '') ? (string) $row['last_failure_at'] : null,
                'lag_hours'       => $lagHours,
                'total_items'     => (int) ($row['total_items'] ?? 0),
            ];
        }

        return $out;
    }

    /** @return array<int,array<string,array<string,mixed>>> keyed by company_id → table_key */
    private function queryCanonical(string $idList): array
    {
        $out = [];

        // NF — also fetch MAX(data_emissao) as last_nf_date for freshness age in days
        $nfRows = $this->safeFetch(
            "SELECT company_id, COUNT(*) AS cnt,
                    MAX(data_emissao) AS last_nf_date,
                    MAX(updated_at)   AS last_sync
             FROM biomie_notas_fiscais_canonicas
             WHERE company_id IN ({$idList}) GROUP BY company_id"
        );
        foreach ($nfRows as $row) {
            $out[(int) $row['company_id']]['nf'] = [
                'count'        => (int) $row['cnt'],
                'last_nf_date' => (($row['last_nf_date'] ?? '') !== '') ? (string) $row['last_nf_date'] : null,
                'last_sync'    => (($row['last_sync']    ?? '') !== '') ? (string) $row['last_sync']    : null,
            ];
        }

        // Remaining canonical tables
        foreach ([
            'orders'  => 'biomie_pedidos_canonicos',
            'clients' => 'biomie_clientes_dim',
            'finance' => 'biomie_titulos_financeiros_canonicos',
        ] as $key => $table) {
            $rows = $this->safeFetch(
                "SELECT company_id, COUNT(*) AS cnt, MAX(updated_at) AS last_sync
                 FROM `{$table}` WHERE company_id IN ({$idList}) GROUP BY company_id"
            );
            foreach ($rows as $row) {
                $out[(int) $row['company_id']][$key] = [
                    'count'     => (int) $row['cnt'],
                    'last_sync' => (($row['last_sync'] ?? '') !== '') ? (string) $row['last_sync'] : null,
                ];
            }
        }

        return $out;
    }

    /** @return array<int,array<string,int>> keyed by company_id → YYYY-MM → nf_count */
    private function queryCoverage(string $idList): array
    {
        $rows = $this->safeFetch(
            "SELECT company_id, DATE_FORMAT(data_emissao,'%Y-%m') AS month, COUNT(*) AS nf_count
             FROM biomie_notas_fiscais_canonicas
             WHERE company_id IN ({$idList})
               AND data_emissao >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
               AND status_fiscal = 'autorizada'
             GROUP BY company_id, month"
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['company_id']][(string) $row['month']] = (int) $row['nf_count'];
        }

        return $out;
    }

    /**
     * Returns mart row counts per company.
     *
     * Fast path: one UNION ALL query (1 round-trip).
     * Fallback: per-table queries when a table is missing (catches the error per table).
     *
     * @return array<int,array<string,int>> keyed by company_id → mart_alias → row_count
     */
    private function queryMarts(string $idList): array
    {
        // Build UNION ALL — one query instead of N
        $unions = [];
        foreach (self::MART_TABLES as $table => $alias) {
            $unions[] = "SELECT '{$alias}' AS mart_alias, company_id, COUNT(*) AS cnt"
                      . " FROM `{$table}` WHERE company_id IN ({$idList}) GROUP BY company_id";
        }

        $batchOk = false;
        $out = [];
        try {
            $stmt = $this->pdo->query(implode(' UNION ALL ', $unions));
            if ($stmt !== false) {
                $batchOk = true;
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $out[(int) $row['company_id']][(string) $row['mart_alias']] = (int) $row['cnt'];
                }
            }
        } catch (\Throwable) {
            // A mart table doesn't exist yet — fall through to per-table fallback
        }

        if (!$batchOk) {
            foreach (self::MART_TABLES as $table => $alias) {
                $rows = $this->safeFetch(
                    "SELECT company_id, COUNT(*) AS cnt FROM `{$table}`
                     WHERE company_id IN ({$idList}) GROUP BY company_id"
                );
                foreach ($rows as $row) {
                    $out[(int) $row['company_id']][$alias] = (int) $row['cnt'];
                }
            }
        }

        return $out;
    }

    /** @return array<int,array<string,bool>> keyed by company_id */
    private function queryCredentials(string $idList): array
    {
        $rows = $this->safeFetch(
            "SELECT c.id AS company_id,
                    COALESCE(i.active, 0) AS active,
                    CASE WHEN COALESCE(i.app_key,'') <> ''
                          AND COALESCE(i.app_secret_encrypted,'') <> ''
                         THEN 1 ELSE 0 END AS configured
             FROM biomie_companies c
             LEFT JOIN biomie_company_integrations i ON i.company_id = c.id AND i.provider = 'omie'
             WHERE c.id IN ({$idList})"
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['company_id']] = [
                'configured' => (bool) $row['configured'] && (bool) $row['active'],
            ];
        }

        return $out;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $domain */
    private function isFresh(array $domain, int $maxHours): bool
    {
        $lag = $domain['lag_hours'] ?? null;
        return $lag !== null && $lag < $maxHours;
    }

    private function tier(int $score): string
    {
        return match (true) {
            $score >= 80 => 'full',
            $score >= 50 => 'operational',
            $score >= 20 => 'minimal',
            default      => 'incomplete',
        };
    }

    /** @return list<array<string,mixed>> */
    private function safeFetch(string $sql): array
    {
        try {
            $stmt = $this->pdo->query($sql);
            return $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (\Throwable) {
            return [];
        }
    }
}

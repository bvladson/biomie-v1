<?php

declare(strict_types=1);

namespace BIomie\Commercial;

use BIomie\Support\BiVisibilityScope;
use BIomie\Support\SalesGoalPeriodCalculator;
use BIomie\TargetsRepository;
use PDO;
use Throwable;

/**
 * COM.R3 — Agregador unificado de métricas comerciais.
 *
 * Builder read-only que consolida summary, time_series, vendor_ranking,
 * product_top, client_top, pipeline_scope, portfolio_summary e goals_summary.
 *
 * Sempre usa:
 *   - CommercialPeriodScopeContext para normalização de período/escopo
 *   - CommercialScopeSqlFilter para filtros SQL
 *   - CommercialMetricDate para expressão de data canônica
 *   - CommercialPeriodBucket para granularidade temporal
 *   - BiVisibilityScope para exclusão de Licitação
 *
 * Não duplica queries de SalesReadRepository/CanonicalRepository —
 * orquestra e normaliza retornos através do escopo padronizado.
 */
final class CommercialMetricsAggregator
{
    private PDO $pdo;
    private ?TargetsRepository $targetsRepo;
    private string $visCond;

    public function __construct(PDO $pdo, ?TargetsRepository $targetsRepo = null)
    {
        $this->pdo         = $pdo;
        $this->targetsRepo = $targetsRepo;
        $this->visCond     = BiVisibilityScope::getCondition($pdo, 'biomie_notas_fiscais_canonicas', 'nf');
    }

    // ── Public API ─────────────────────────────────────────────────────────────

    /**
     * KPIs de summary para o período e escopo.
     *
     * @return array{base_comercial:float,nfs:int,clientes:int,vendedores:int,wallet_value:float,wallet_orders:int,quotes_value:float,quotes_orders:int}
     */
    public function summary(CommercialPeriodScopeContext $ctx): array
    {
        $dateWhere = CommercialMetricDate::wherePeriod('nf');
        $params    = [':start' => $ctx->dateStart(), ':end' => $ctx->dateEnd()];
        $scopeFilter = $this->buildScopeFilter($ctx);

        try {
            $stmt = $this->pdo->prepare(
                "SELECT
                    COALESCE(SUM(COALESCE(nf.valor_base_comercial_nf, nf.valor_mercadorias)), 0) AS base_comercial,
                    COUNT(*) AS nfs,
                    COUNT(DISTINCT nf.codigo_cliente_omie) AS clientes,
                    COUNT(DISTINCT nf.nome_vendedor) AS vendedores
                 FROM biomie_notas_fiscais_canonicas nf
                 WHERE nf.status_fiscal = 'autorizada'
                   AND {$dateWhere}{$scopeFilter}"
            );
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            $row = [];
        }

        // Wallet (carteira aceita) and Quotes (orçamentos)
        $wallet = $this->walletSummary($ctx);
        $quotes = $this->quotesSummary($ctx);

        return [
            'base_comercial' => (float)($row['base_comercial'] ?? 0),
            'nfs'            => (int)($row['nfs'] ?? 0),
            'clientes'       => (int)($row['clientes'] ?? 0),
            'vendedores'     => (int)($row['vendedores'] ?? 0),
            'wallet_value'   => $wallet['value'],
            'wallet_orders'  => $wallet['orders'],
            'quotes_value'   => $quotes['value'],
            'quotes_orders'  => $quotes['orders'],
        ];
    }

    /**
     * Série temporal agrupada por CommercialPeriodBucket.
     *
     * @return array{mode:string,days:int,buckets:list<array{key:string,label:string,base_comercial:float,nfs:int}>}
     */
    public function timeSeries(CommercialPeriodScopeContext $ctx): array
    {
        $bucket    = CommercialPeriodBucket::resolve($ctx->dateStart(), $ctx->dateEnd());
        $dateExpr  = CommercialMetricDate::metricDateSql('nf');
        $bucketExpr = $bucket->bucketSqlFromExpr($dateExpr);
        $scopeFilter = $this->buildScopeFilter($ctx);

        $allBuckets = $bucket->buildBuckets();
        $dataByKey  = [];

        try {
            $stmt = $this->pdo->prepare(
                "SELECT
                    {$bucketExpr} AS bucket_key,
                    COALESCE(SUM(COALESCE(nf.valor_base_comercial_nf, nf.valor_mercadorias)), 0) AS base_comercial,
                    COUNT(*) AS nfs
                 FROM biomie_notas_fiscais_canonicas nf
                 WHERE nf.status_fiscal = 'autorizada'
                   AND {$dateExpr} BETWEEN :start AND :end{$scopeFilter}
                 GROUP BY {$bucketExpr}
                 ORDER BY {$bucketExpr} ASC"
            );
            $stmt->execute([':start' => $ctx->dateStart(), ':end' => $ctx->dateEnd()]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $dataByKey[(string)($row['bucket_key'] ?? '')] = [
                    'base_comercial' => (float)($row['base_comercial'] ?? 0),
                    'nfs'            => (int)($row['nfs'] ?? 0),
                ];
            }
        } catch (Throwable) {}

        // Merge with all pre-built buckets (zero-fill missing buckets)
        $result = [];
        foreach ($allBuckets as $b) {
            $data = $dataByKey[$b['key']] ?? ['base_comercial' => 0.0, 'nfs' => 0];
            $result[] = [
                'key'            => $b['key'],
                'label'          => $b['label'],
                'base_comercial' => $data['base_comercial'],
                'nfs'            => $data['nfs'],
            ];
        }

        return [
            'mode'   => $bucket->mode(),
            'days'   => $bucket->days(),
            'buckets' => $result,
        ];
    }

    /**
     * Top N vendors by base_comercial.
     *
     * @return list<array{nome_vendedor:string,nfs:int,clientes:int,base_comercial:float,share_pct:float}>
     */
    public function vendorRanking(CommercialPeriodScopeContext $ctx, int $limit = 10): array
    {
        $dateWhere   = CommercialMetricDate::wherePeriod('nf');
        $scopeFilter = $this->buildScopeFilter($ctx);

        try {
            $stmt = $this->pdo->prepare(
                "SELECT
                    nf.nome_vendedor,
                    COUNT(*) AS nfs,
                    COUNT(DISTINCT nf.codigo_cliente_omie) AS clientes,
                    COALESCE(SUM(COALESCE(nf.valor_base_comercial_nf, nf.valor_mercadorias)), 0) AS base_comercial
                 FROM biomie_notas_fiscais_canonicas nf
                 WHERE nf.status_fiscal = 'autorizada'
                   AND {$dateWhere}{$scopeFilter}
                 GROUP BY nf.nome_vendedor
                 ORDER BY base_comercial DESC
                 LIMIT {$limit}"
            );
            $stmt->execute([':start' => $ctx->dateStart(), ':end' => $ctx->dateEnd()]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }

        $total = array_sum(array_column($rows, 'base_comercial'));
        return array_map(function (array $r) use ($total): array {
            return [
                'nome_vendedor'  => (string)($r['nome_vendedor'] ?? ''),
                'nfs'            => (int)($r['nfs'] ?? 0),
                'clientes'       => (int)($r['clientes'] ?? 0),
                'base_comercial' => (float)($r['base_comercial'] ?? 0),
                'share_pct'      => $total > 0 ? round((float)($r['base_comercial'] ?? 0) / $total * 100, 2) : 0.0,
            ];
        }, $rows);
    }

    /**
     * Top N products by revenue.
     *
     * @return list<array{codigo_produto:string,descricao_produto:string,nfs:int,quantidade:float,receita:float,share_pct:float}>
     */
    public function productTop(CommercialPeriodScopeContext $ctx, int $limit = 10): array
    {
        $dateExpr    = CommercialMetricDate::metricDateSql('nf');
        $scopeFilter = $this->buildScopeFilter($ctx);
        $hasItens    = $this->tableExists('biomie_notas_fiscais_itens_canonicos');
        if (!$hasItens) return [];

        try {
            $stmt = $this->pdo->prepare(
                "SELECT
                    COALESCE(NULLIF(TRIM(i.codigo_produto), ''), 'SEM_SKU') AS codigo_produto,
                    MAX(COALESCE(NULLIF(TRIM(i.descricao_produto), ''), 'Produto sem descrição')) AS descricao_produto,
                    COUNT(DISTINCT i.nota_key) AS nfs,
                    COALESCE(SUM(i.quantidade), 0) AS quantidade,
                    COALESCE(SUM(i.valor_total), 0) AS receita
                 FROM biomie_notas_fiscais_itens_canonicos i
                 JOIN biomie_notas_fiscais_canonicas nf
                   ON nf.company_id = i.company_id AND nf.nota_key = i.nota_key
                 WHERE nf.status_fiscal = 'autorizada'
                   AND {$dateExpr} BETWEEN :start AND :end{$scopeFilter}
                 GROUP BY COALESCE(NULLIF(TRIM(i.codigo_produto), ''), 'SEM_SKU')
                 ORDER BY receita DESC
                 LIMIT {$limit}"
            );
            $stmt->execute([':start' => $ctx->dateStart(), ':end' => $ctx->dateEnd()]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }

        $total = array_sum(array_column($rows, 'receita'));
        return array_map(function (array $r) use ($total): array {
            return [
                'codigo_produto'    => (string)($r['codigo_produto'] ?? ''),
                'descricao_produto' => (string)($r['descricao_produto'] ?? ''),
                'nfs'               => (int)($r['nfs'] ?? 0),
                'quantidade'        => (float)($r['quantidade'] ?? 0),
                'receita'           => (float)($r['receita'] ?? 0),
                'share_pct'         => $total > 0 ? round((float)($r['receita'] ?? 0) / $total * 100, 2) : 0.0,
            ];
        }, $rows);
    }

    /**
     * Top N clients by base_comercial.
     *
     * @return list<array{codigo_cliente_omie:int|null,nome_cliente:string,nfs:int,base_comercial:float,ultima_compra:string}>
     */
    public function clientTop(CommercialPeriodScopeContext $ctx, int $limit = 10): array
    {
        $dateExpr    = CommercialMetricDate::metricDateSql('nf');
        $scopeFilter = $this->buildScopeFilter($ctx);

        try {
            $stmt = $this->pdo->prepare(
                "SELECT
                    nf.codigo_cliente_omie,
                    MAX(nf.nome_cliente) AS nome_cliente,
                    COUNT(*) AS nfs,
                    COALESCE(SUM(COALESCE(nf.valor_base_comercial_nf, nf.valor_mercadorias)), 0) AS base_comercial,
                    MAX({$dateExpr}) AS ultima_compra
                 FROM biomie_notas_fiscais_canonicas nf
                 WHERE nf.status_fiscal = 'autorizada'
                   AND {$dateExpr} BETWEEN :start AND :end{$scopeFilter}
                 GROUP BY nf.codigo_cliente_omie
                 ORDER BY base_comercial DESC
                 LIMIT {$limit}"
            );
            $stmt->execute([':start' => $ctx->dateStart(), ':end' => $ctx->dateEnd()]);
            return array_map(fn(array $r) => [
                'codigo_cliente_omie' => $r['codigo_cliente_omie'] !== null ? (int)$r['codigo_cliente_omie'] : null,
                'nome_cliente'        => (string)($r['nome_cliente'] ?? ''),
                'nfs'                 => (int)($r['nfs'] ?? 0),
                'base_comercial'      => (float)($r['base_comercial'] ?? 0),
                'ultima_compra'       => (string)($r['ultima_compra'] ?? ''),
            ], $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Pipeline scope breakdown: orçamentos, carteira, faturado NF, canceladas, entrega finalizada.
     *
     * @return array{orcamentos:array,carteira_aceita:array,faturado_nf:array,canceladas:array,entrega_finalizada:array,remessa:array}
     */
    public function pipelineScope(CommercialPeriodScopeContext $ctx): array
    {
        $start = $ctx->dateStart();
        $end   = $ctx->dateEnd();

        $scopes = [
            'orcamentos' => [
                'table' => 'biomie_pedidos_canonicos',
                'where' => "status_comercial = 'autorizado' AND etapa_omie = '10' AND COALESCE(data_faturamento, data_emissao) BETWEEN :s AND :e",
            ],
            'carteira_aceita' => [
                'table' => 'biomie_pedidos_canonicos',
                'where' => "status_comercial = 'autorizado' AND (etapa_omie IS NULL OR etapa_omie <> '10') AND COALESCE(data_faturamento, data_emissao) BETWEEN :s AND :e",
            ],
        ];

        $result = [];

        foreach ($scopes as $key => $scope) {
            try {
                $stmt = $this->pdo->prepare(
                    "SELECT COUNT(*) AS total, COALESCE(SUM(valor_total), 0) AS valor
                     FROM {$scope['table']}
                     WHERE " . \BIomie\Support\TenantContext::sqlFilter() . "
                       AND {$scope['where']}"
                );
                $stmt->execute([':s' => $start, ':e' => $end]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $result[$key] = ['total' => (int)($row['total'] ?? 0), 'valor' => (float)($row['valor'] ?? 0)];
            } catch (Throwable) {
                $result[$key] = ['total' => 0, 'valor' => 0.0];
            }
        }

        // NF-based scopes
        $nfScopes = [
            'faturado_nf' => "nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial' AND {$this->visCond}",
            'canceladas'  => "nf.status_fiscal = 'cancelada' AND nf.operation_scope = 'commercial'",
            'remessa'     => "nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'remittance'",
            'entrega_finalizada' => "nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial' AND {$this->visCond} AND EXISTS (SELECT 1 FROM biomie_pedidos_canonicos p2 WHERE p2.company_id = nf.company_id AND p2.pedido_id_omie = nf.pedido_id_omie AND p2.etapa_omie = '60')",
        ];

        $dateExpr = CommercialMetricDate::metricDateSql('nf');
        foreach ($nfScopes as $key => $whereExtra) {
            try {
                $stmt = $this->pdo->prepare(
                    "SELECT COUNT(*) AS total,
                            COALESCE(SUM(COALESCE(nf.valor_base_comercial_nf, nf.valor_mercadorias)), 0) AS valor
                     FROM biomie_notas_fiscais_canonicas nf
                     WHERE " . \BIomie\Support\TenantContext::sqlFilter('nf') . "
                       AND {$whereExtra}
                       AND {$dateExpr} BETWEEN :s AND :e"
                );
                $stmt->execute([':s' => $start, ':e' => $end]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $result[$key] = ['total' => (int)($row['total'] ?? 0), 'valor' => (float)($row['valor'] ?? 0)];
            } catch (Throwable) {
                $result[$key] = ['total' => 0, 'valor' => 0.0];
            }
        }

        return $result;
    }

    /**
     * Goals summary using SalesGoalPeriodCalculator.
     *
     * @return array{meta_total:float,realizado:float,atingimento_pct:float|null,breakdown:list<array<string,mixed>>,target_vendors:int,has_targets:bool}
     */
    public function goalsSummary(CommercialPeriodScopeContext $ctx): array
    {
        if ($this->targetsRepo === null || !$this->targetsRepo->isAvailable()) {
            return ['meta_total' => 0.0, 'realizado' => 0.0, 'atingimento_pct' => null, 'breakdown' => [], 'target_vendors' => 0, 'has_targets' => false];
        }

        $months = $ctx->monthsCovered();
        $targetsRows = $this->targetsRepo->getTargetsForMonths($months);

        // Aggregate team monthly targets
        $monthlyTargets = [];
        foreach ($targetsRows as $row) {
            $ym = $row['year_month'];
            if ($ym !== '') $monthlyTargets[$ym] = ($monthlyTargets[$ym] ?? 0.0) + $row['target_amount'];
        }
        if ($monthlyTargets === []) {
            $monthlyTargets = $this->targetsRepo->getCompanyTargetsForMonths($months);
        }

        $calc    = new SalesGoalPeriodCalculator();
        $result  = $calc->calculate($ctx->dateStart(), $ctx->dateEnd(), $monthlyTargets);
        $summary = $this->summary($ctx);

        $meta     = $result['meta_total_periodo'];
        $real     = $summary['base_comercial'];
        $atingPct = $meta > 0 ? round($real / $meta * 100, 2) : null;

        $vendorIds = array_unique(array_column($targetsRows, 'vendor_name'));

        return [
            'meta_total'     => $meta,
            'realizado'      => $real,
            'atingimento_pct' => $atingPct,
            'breakdown'      => $result['breakdown'],
            'target_vendors' => count(array_filter($vendorIds)),
            'has_targets'    => $meta > 0,
        ];
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    private function buildScopeFilter(CommercialPeriodScopeContext $ctx): string
    {
        $scope = $ctx->scope();

        $tenant = \BIomie\Support\TenantContext::sqlFilter('nf');
        $baseFilter = "\n               AND {$tenant}\n               AND nf.operation_scope = 'commercial'\n               AND {$this->visCond}";
        $licitacaoScope = "\n               AND {$tenant}\n               AND nf.operation_scope = 'commercial'\n               AND NOT ({$this->visCond})";
        $remessaScope = "\n               AND {$tenant}\n               AND nf.operation_scope = 'remittance'";
        $allScope = "\n               AND {$tenant}";

        return match ($scope) {
            'licitacao'         => $licitacaoScope,
            'remessa'           => $remessaScope,
            'todos'             => $allScope,
            default             => $baseFilter, // orc_pd, faturado, orcamento, carteira_aceita, etc.
        };
    }

    /** @return array{value:float,orders:int} */
    private function walletSummary(CommercialPeriodScopeContext $ctx): array
    {
        if (!$this->tableExists('biomie_pedidos_canonicos')) {
            return ['value' => 0.0, 'orders' => 0];
        }
        try {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) AS orders, COALESCE(SUM(valor_total), 0) AS value
                 FROM biomie_pedidos_canonicos
                 WHERE status_comercial = 'autorizado'
                   AND " . \BIomie\Support\TenantContext::sqlFilter() . "
                   AND COALESCE(data_faturamento, data_emissao) BETWEEN :s AND :e"
            );
            $stmt->execute([':s' => $ctx->dateStart(), ':e' => $ctx->dateEnd()]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            return ['value' => (float)($row['value'] ?? 0), 'orders' => (int)($row['orders'] ?? 0)];
        } catch (Throwable) {
            return ['value' => 0.0, 'orders' => 0];
        }
    }

    /** @return array{value:float,orders:int} */
    private function quotesSummary(CommercialPeriodScopeContext $ctx): array
    {
        if (!$this->tableExists('biomie_pedidos_canonicos')) {
            return ['value' => 0.0, 'orders' => 0];
        }
        try {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) AS orders, COALESCE(SUM(valor_total), 0) AS value
                 FROM biomie_pedidos_canonicos
                 WHERE status_comercial = 'autorizado'
                   AND " . \BIomie\Support\TenantContext::sqlFilter() . "
                   AND etapa_omie = '10'
                   AND COALESCE(data_faturamento, data_emissao) BETWEEN :s AND :e"
            );
            $stmt->execute([':s' => $ctx->dateStart(), ':e' => $ctx->dateEnd()]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            return ['value' => (float)($row['value'] ?? 0), 'orders' => (int)($row['orders'] ?? 0)];
        } catch (Throwable) {
            return ['value' => 0.0, 'orders' => 0];
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            $s = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t LIMIT 1');
            $s->execute(['t' => $table]);
            return (int) $s->fetchColumn() > 0;
        } catch (Throwable) { return false; }
    }
}

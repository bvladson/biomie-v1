<?php

declare(strict_types=1);

namespace BIomie\Modules\Sales\Application\Overview;

use BIomie\CanonicalRepository;
use BIomie\Config;
use BIomie\Database;
use BIomie\Acl\DataMasker;
use BIomie\DataPlatform\Silver\Readers\CanonicalReadSupport;
use BIomie\Logger;
use BIomie\RequestContext;
use BIomie\Support\TenantContext;
use RuntimeException;
use Throwable;

/**
 * Builds detail payloads for the overview drilldown drawer.
 * Each chart_id delegates to a dedicated handler method — no giant switch.
 */
final class OverviewDetailBuilder
{
    private \PDO $pdo;
    private Logger $logger;
    private CanonicalRepository $canonical;
    private CanonicalReadSupport $canonicalReadSupport;

    private function __construct(\PDO $pdo, Logger $logger, CanonicalRepository $canonical)
    {
        $this->pdo = $pdo;
        $this->logger = $logger;
        $this->canonical = $canonical;
        $this->canonicalReadSupport = new CanonicalReadSupport(static fn (): \PDO => $pdo);
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
        );
        $stmt->execute([':table' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = :table
                AND COLUMN_NAME = :column'
        );
        $stmt->execute(['table' => $table, 'column' => $column]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /** Channel scope for marts that already contain only eligible commercial facts. */
    private function martChannelFilter(string $alias): string
    {
        // Consolidado aggregates the allowed companies using the Varejo
        // contract; it does not merge the Licitacao channel into the cards.
        // Mixed (Todas as operações) SIM: os dois canais numa mesma empresa.
        if (TenantContext::isMixed()) {
            return sprintf("%s.business_channel IN ('licitacao', 'varejo')", $alias);
        }
        $channel = TenantContext::isLicitacao() ? 'licitacao' : 'varejo';
        return sprintf("%s.business_channel = '%s'", $alias, $channel);
    }

    /** Same eligibility contract used by the Gold builders for canonical NF reads. */
    private function canonicalCommercialFilter(string $alias): string
    {
        $base = $alias . ".operation_scope = 'commercial'";
        // Mixed: os dois canais, com a regra do CommercialNfUniverse (licitação
        // preservada + varejo visível). Nunca só varejo, que esconderia licitação.
        if (TenantContext::isMixed()) {
            return $base . ' AND (' . $alias . ".business_channel = 'licitacao'"
                . ' OR (' . $alias . ".business_channel = 'varejo'"
                . ' AND COALESCE(' . $alias . '.is_biomie_commercial_visible, 1) = 1))';
        }
        if (TenantContext::isLicitacao()) {
            return $base . ' AND ' . $alias . ".business_channel = 'licitacao'";
        }

        return $base . ' AND ' . $alias . ".business_channel = 'varejo'"
            . ' AND COALESCE(' . $alias . '.is_biomie_commercial_visible, 1) = 1';
    }

    /**
     * @return array{0:string,1:string}
     */
    private function monthRange(string $start, string $end): array
    {
        return [substr($start, 0, 7), substr($end, 0, 7)];
    }

    private function isWholeMonthRange(string $start, string $end): bool
    {
        try {
            $startDate = new \DateTimeImmutable($start);
            $endDate = new \DateTimeImmutable($end);
        } catch (\Throwable) {
            return false;
        }

        return $startDate->format('d') === '01'
            && $endDate->format('Y-m-d') === $endDate->modify('last day of this month')->format('Y-m-d');
    }

    public static function boot(): self
    {
        $config = new Config();
        $logger = new Logger(\BIomie\Bootstrap::rootPath('storage/logs/overview_detail.log'));
        $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
        $pdo = Database::connect($config, $connection);
        $canonical = new CanonicalRepository($pdo, $logger);

        return new self($pdo, $logger, $canonical);
    }

    /**
     * @return list<string>
     */
    public static function supportedChartIds(): array
    {
        return [
            'revenue_evolution',
            'vendor_ranking',
            'top_products',
            'top_customers',
            'top_invoices',
            'geo_compact',
            'pipeline_period',
            'monthly_comparison',
            'finance_risk',
            'attention_points',
        ];
    }

    /**
     * @param array<string,string> $filters
     * @return array<string,mixed>
     */
    public function build(string $chartId, string $start, string $end, array $filters, int $limit, int $offset): array
    {
        $handlers = [
            'revenue_evolution'  => 'handleRevenueEvolution',
            'vendor_ranking'     => 'handleVendorRanking',
            'top_products'       => 'handleTopProducts',
            'top_customers'      => 'handleTopCustomers',
            'top_invoices'       => 'handleTopInvoices',
            'geo_compact'        => 'handleGeoCompact',
            'pipeline_period'    => 'handlePipelinePeriod',
            'monthly_comparison' => 'handleMonthlyComparison',
            'finance_risk'       => 'handleFinanceRisk',
            'attention_points'   => 'handleAttentionPoints',
        ];

        $handler = $handlers[$chartId] ?? null;
        if ($handler === null) {
            throw new RuntimeException("chart_id desconhecido: {$chartId}", 422);
        }

        try {
            return $this->{$handler}($start, $end, $filters, $limit, $offset);
        } catch (Throwable $e) {
            $this->logger->warning("OverviewDetail: falha em {$chartId}.", [
                'message'    => $e->getMessage(),
                'period'     => compact('start', 'end'),
                'filters'    => $filters,
                'request_id' => RequestContext::id(),
            ]);
            return [
                'ok' => false,
                'status' => 'warning',
                'chart_id' => $chartId,
                'message' => 'Nao foi possivel montar este detalhe com o cache local atual.',
                'rows' => [],
                'meta' => [
                    'warnings' => ['Falha controlada no handler do detalhe.'],
                    'diagnostics' => [
                        'handler' => $handler,
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                    ],
                ],
            ];
        }
    }

    // ── Revenue Evolution ─────────────────────────────────────────────────────

    /**
     * @param array<string,string> $filters
     * @return array<string,mixed>
     */
    private function handleRevenueEvolution(string $start, string $end, array $filters, int $limit, int $offset): array
    {
        $tenantFilter = \BIomie\Support\TenantContext::sqlFilter('n');
        $commercialBase = $this->canonicalReadSupport->commercialBaseSql('n');
        $dateFilter = $filters['date'] ?? '';
        $where = [
            $tenantFilter,
            $this->canonicalCommercialFilter('n'),
            "n.status_fiscal = 'autorizada'",
            'n.data_competencia >= :start',
            'n.data_competencia <= :end',
        ];
        $params = [':start' => $start, ':end' => $end];

        if ($dateFilter !== '') {
            $where[] = 'n.data_competencia = :date_filter';
            $params[':date_filter'] = $dateFilter;
        }

        $sql = 'SELECT n.nota_key,
                       n.chave_nfe,
                       n.numero_nf,
                       n.numero_nf AS code,
                       n.data_competencia AS date,
                       ' . $commercialBase . ' AS revenue,
                       n.valor_total AS gross_revenue,
                       COALESCE(n.nome_vendedor, \'Nao identificado\') AS vendor_name,
                       COALESCE(n.nome_cliente, \'Cliente nao informado\') AS client
                FROM biomie_notas_fiscais_canonicas n
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY revenue DESC
                LIMIT :lim OFFSET :off';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return ['ok' => true, 'chart_id' => 'revenue_evolution', 'rows' => DataMasker::applyToRows($rows)];
    }

    // ── Vendor Ranking ────────────────────────────────────────────────────────

    /**
     * @param array<string,string> $filters
     * @return array<string,mixed>
     */
    private function handleVendorRanking(string $start, string $end, array $filters, int $limit, int $offset): array
    {
        $tenantFilter = \BIomie\Support\TenantContext::sqlFilter('n');
        $commercialBase = $this->canonicalReadSupport->commercialBaseSql('n');
        $sql  = "SELECT COALESCE(NULLIF(n.codigo_vendedor_omie, ''), '__SEM_VENDEDOR__') AS vendor_id,
                       COALESCE(NULLIF(n.nome_vendedor, ''), 'Nao identificado') AS vendor_name,
                       COUNT(*) AS orders,
                       COUNT(*) AS invoices_count,
                       COUNT(DISTINCT COALESCE(NULLIF(CAST(n.codigo_cliente_omie AS CHAR), ''), NULLIF(n.documento_cliente, ''), NULLIF(n.nome_cliente, ''))) AS customers_count,
                       SUM({$commercialBase}) AS revenue,
                       AVG({$commercialBase}) AS ticket
                FROM biomie_notas_fiscais_canonicas n
                WHERE {$tenantFilter}
                  AND " . $this->canonicalCommercialFilter('n') . "
                  AND n.status_fiscal = 'autorizada'
                  AND n.data_competencia >= :start
                  AND n.data_competencia <= :end
                GROUP BY vendor_id, vendor_name
                ORDER BY revenue DESC
                LIMIT :lim OFFSET :off";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':start', $start);
        $stmt->bindValue(':end', $end);
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return ['ok' => true, 'chart_id' => 'vendor_ranking', 'rows' => $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []];
    }

    // ── Top Products ──────────────────────────────────────────────────────────

    /**
     * @param array<string,string> $filters
     * @return array<string,mixed>
     */
    private function handleTopProducts(string $start, string $end, array $filters, int $limit, int $offset): array
    {
        if (!$this->isWholeMonthRange($start, $end)) {
            $rows = $this->canonical->topProductsCommercial($start, $end, min($offset + $limit, 500));

            return [
                'ok' => true,
                'chart_id' => 'top_products',
                'rows' => array_slice($rows, $offset, $limit),
                'meta' => [
                    'source_tables' => ['biomie_notas_fiscais_canonicas', 'biomie_notas_fiscais_itens_canonicos'],
                    'period_scope' => 'exact_range',
                ],
            ];
        }

        [$startMonth, $endMonth] = $this->monthRange($start, $end);
        $tenantFilter = \BIomie\Support\TenantContext::sqlFilter('p');
        $sql  = "SELECT p.descricao_produto AS description,
                       p.codigo_produto AS product_code,
                       SUM(p.quantidade) AS quantity,
                       SUM(p.valor_total) AS revenue,
                       SUM(p.nfes_count) AS orders
                FROM mart_produto_periodo p
                WHERE {$tenantFilter}
                  AND " . $this->martChannelFilter('p') . "
                  AND p.competencia >= :start_month AND p.competencia <= :end_month
                GROUP BY p.descricao_produto, p.codigo_produto
                ORDER BY revenue DESC
                LIMIT :lim OFFSET :off";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':start_month', $startMonth);
        $stmt->bindValue(':end_month', $endMonth);
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return [
            'ok' => true,
            'chart_id' => 'top_products',
            'rows' => $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [],
            'meta' => ['source_tables' => ['mart_produto_periodo']],
        ];
    }

    // ── Top Customers ─────────────────────────────────────────────────────────

    /**
     * @param array<string,string> $filters
     * @return array<string,mixed>
     */
    private function handleTopCustomers(string $start, string $end, array $filters, int $limit, int $offset): array
    {
        if (!$this->isWholeMonthRange($start, $end)) {
            $rows = $this->canonical->topClientsCommercial($start, $end, min($offset + $limit, 500));
            $summary = $this->canonical->summarizeInvoicesCommercial($start, $end);
            $periodTotal = (float) ($summary['billed_value'] ?? 0);
            $rows = array_map(static function (array $row) use ($periodTotal): array {
                $invoiceTotal = (float) ($row['invoice_total'] ?? 0);
                $row['client_id'] = $row['codigo_cliente_omie'] ?? null;
                $row['share_pct'] = $periodTotal > 0
                    ? round(($invoiceTotal / $periodTotal) * 100, 1)
                    : 0.0;
                return $row;
            }, $rows);

            return [
                'ok' => true,
                'chart_id' => 'top_customers',
                'rows' => DataMasker::applyToRows(array_slice($rows, $offset, $limit)),
                'meta' => [
                    'source_tables' => ['biomie_notas_fiscais_canonicas'],
                    'period_scope' => 'exact_range',
                ],
            ];
        }

        [$startMonth, $endMonth] = $this->monthRange($start, $end);
        $tenantFilter  = \BIomie\Support\TenantContext::sqlFilter('c');
        $tenantFilter0 = \BIomie\Support\TenantContext::sqlFilter('total_c');
        $sql  = "SELECT COALESCE(c.nome_cliente, 'Cliente nao informado') AS client_name,
                       c.codigo_cliente_omie AS client_id,
                       c.documento_cliente AS client_document,
                       SUM(c.nfes_emitidas) AS notes_count,
                       SUM(c.valor_nfe) AS invoice_total,
                       ROUND(SUM(c.valor_nfe) * 100.0 / NULLIF((SELECT SUM(total_c.valor_nfe) FROM mart_cliente_periodo total_c WHERE {$tenantFilter0} AND " . $this->martChannelFilter('total_c') . " AND total_c.competencia >= :start2 AND total_c.competencia <= :end2), 0), 1) AS share_pct
                FROM mart_cliente_periodo c
                WHERE {$tenantFilter}
                  AND " . $this->martChannelFilter('c') . "
                  AND c.competencia >= :start AND c.competencia <= :end
                GROUP BY c.codigo_cliente_omie, c.documento_cliente, client_name
                ORDER BY invoice_total DESC
                LIMIT :lim OFFSET :off";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':start', $startMonth);
        $stmt->bindValue(':end', $endMonth);
        $stmt->bindValue(':start2', $startMonth);
        $stmt->bindValue(':end2', $endMonth);
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return [
            'ok' => true,
            'chart_id' => 'top_customers',
            'rows' => DataMasker::applyToRows($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []),
            'meta' => ['source_tables' => ['mart_cliente_periodo']],
        ];
    }

    // ── Top Invoices ──────────────────────────────────────────────────────────

    /**
     * @param array<string,string> $filters
     * @return array<string,mixed>
     */
    private function handleTopInvoices(string $start, string $end, array $filters, int $limit, int $offset): array
    {
        $sourceTables = [];
        $rows = [];
        $hasCanonicalInvoices = $this->tableExists('biomie_notas_fiscais_canonicas');

        if ($hasCanonicalInvoices) {
            $commercialBase = $this->canonicalReadSupport->commercialBaseSql('n');
            $itemsJoin = $this->tableExists('biomie_notas_fiscais_itens_canonicos')
                ? 'LEFT JOIN (SELECT company_id, business_channel, nota_key, COUNT(*) AS items_qty FROM biomie_notas_fiscais_itens_canonicos GROUP BY company_id, business_channel, nota_key) i ON i.company_id = n.company_id AND i.business_channel = n.business_channel AND i.nota_key = n.nota_key'
                : '';
            $itemsExpr = $itemsJoin !== '' ? 'COALESCE(i.items_qty, n.quantidade_itens, 0)' : 'COALESCE(n.quantidade_itens, 0)';
            $sourceTables[] = 'biomie_notas_fiscais_canonicas';
            if ($itemsJoin !== '') {
                $sourceTables[] = 'biomie_notas_fiscais_itens_canonicos';
            }

            $tenantFilter = \BIomie\Support\TenantContext::sqlFilter('n');
            $sql  = "SELECT n.nota_key,
                           n.chave_nfe,
                           n.numero_nf AS code,
                           n.numero_nf AS numero_nf,
                           n.serie_nf AS serie,
                           n.chave_nfe AS document_key,
                           n.data_competencia AS issue_date,
                           COALESCE(n.nome_cliente, 'Cliente nao informado') AS client,
                           n.documento_cliente AS customer_document,
                           COALESCE(n.nome_vendedor, 'Nao identificado') AS vendor_name,
                           {$itemsExpr} AS items_qty,
                           {$commercialBase} AS total,
                           n.valor_total AS gross_total,
                           n.valor_mercadorias AS merchandise_total,
                           n.valor_descontos AS discount_total,
                           n.codigo_pedido_origem AS origin_order_code
                    FROM biomie_notas_fiscais_canonicas n
                    {$itemsJoin}
                    WHERE {$tenantFilter}
                      AND " . $this->canonicalCommercialFilter('n') . "
                      AND n.status_fiscal = 'autorizada'
                      AND n.data_competencia >= :start
                      AND n.data_competencia <= :end
                    ORDER BY total DESC
                    LIMIT :lim OFFSET :off";

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':start', $start);
            $stmt->bindValue(':end', $end);
            $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
            $stmt->bindValue(':off', $offset, \PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        }

        if ($rows === [] && !$hasCanonicalInvoices && $this->tableExists('mart_fiscal_nfe')) {
            $sourceTables = ['mart_fiscal_nfe'];
            $goldCommercialBase = $this->columnExists('mart_fiscal_nfe', 'valor_base_comercial_nf')
                ? 'COALESCE(n.valor_base_comercial_nf, n.valor_total)'
                : 'n.valor_total';
            $itemsJoin = $this->tableExists('biomie_notas_fiscais_itens_canonicos')
                ? 'LEFT JOIN (SELECT company_id, business_channel, nota_key, COUNT(*) AS items_qty FROM biomie_notas_fiscais_itens_canonicos GROUP BY company_id, business_channel, nota_key) i ON i.company_id = n.company_id AND i.business_channel = n.business_channel AND i.nota_key = n.nota_key'
                : '';
            $itemsExpr = $itemsJoin !== '' ? 'COALESCE(i.items_qty, 0)' : '0';
            $bridgeJoin = $this->tableExists('mart_bridge_pedido_nfe_titulo')
                ? 'LEFT JOIN mart_bridge_pedido_nfe_titulo b ON b.company_id = n.company_id AND b.business_channel = n.business_channel AND b.nota_key = n.nota_key'
                : '';
            $bridgeExpr = $bridgeJoin !== '' ? "COALESCE(b.codigo_pedido, '')" : "''";
            if ($itemsJoin !== '') {
                $sourceTables[] = 'biomie_notas_fiscais_itens_canonicos';
            }
            if ($bridgeJoin !== '') {
                $sourceTables[] = 'mart_bridge_pedido_nfe_titulo';
            }
            $tenantFilter = \BIomie\Support\TenantContext::sqlFilter('n');
            $sql = "SELECT n.nota_key,
                           n.numero_nf AS code,
                           n.numero_nf AS numero_nf,
                           COALESCE(n.data_competencia, n.data_emissao) AS issue_date,
                           COALESCE(n.nome_cliente, 'Cliente nao informado') AS client,
                           COALESCE(n.nome_vendedor, 'Nao identificado') AS vendor_name,
                           {$itemsExpr} AS items_qty,
                           {$goldCommercialBase} AS total,
                           n.valor_total AS gross_total,
                           n.valor_mercadorias AS merchandise_total,
                           n.valor_descontos AS discount_total,
                           n.nota_key AS document_key,
                           {$bridgeExpr} AS origin_order_code
                    FROM mart_fiscal_nfe n
                    {$itemsJoin}
                    {$bridgeJoin}
                    WHERE {$tenantFilter}
                      AND " . $this->martChannelFilter('n') . "
                      AND n.status_fiscal = 'autorizada'
                      AND COALESCE(n.data_competencia, n.data_emissao) >= :start
                      AND COALESCE(n.data_competencia, n.data_emissao) <= :end
                    ORDER BY total DESC
                    LIMIT :lim OFFSET :off";

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':start', $start);
            $stmt->bindValue(':end', $end);
            $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
            $stmt->bindValue(':off', $offset, \PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        }

        return [
            'ok' => true,
            'chart_id' => 'top_invoices',
            'rows' => DataMasker::applyToRows($rows),
            'meta' => [
                'source_tables' => array_values(array_unique($sourceTables)),
                'warnings' => $rows === [] ? ['Nenhuma NF encontrada nas fontes locais para o periodo.'] : [],
            ],
        ];
    }

    // ── Geo Compact ───────────────────────────────────────────────────────────

    /**
     * @param array<string,string> $filters
     * @return array<string,mixed>
     */
    private function handleGeoCompact(string $start, string $end, array $filters, int $limit, int $offset): array
    {
        $bairro = $filters['bairro'] ?? '';
        $city = trim((string) ($filters['city'] ?? 'Fortaleza'));
        [$startMonth, $endMonth] = $this->monthRange($start, $end);
        $tenantFilter = \BIomie\Support\TenantContext::sqlFilter('g');
        $where = [
            $tenantFilter,
            $this->martChannelFilter('g'),
            'g.competencia >= :start_month',
            'g.competencia <= :end_month',
        ];
        $params = [':start_month' => $startMonth, ':end_month' => $endMonth];

        if ($bairro !== '') {
            $where[] = 'UPPER(g.bairro_norm) = UPPER(:bairro)';
            $params[':bairro'] = $bairro;
        }
        if ($city !== '') {
            $where[] = 'UPPER(g.cidade_norm) = UPPER(:city)';
            $params[':city'] = $city;
        }

        $sql = 'SELECT g.bairro_norm AS neighborhood,
                       SUM(g.faturamento) AS revenue,
                       SUM(g.qtd_notas) AS notes_count,
                       COUNT(DISTINCT COALESCE(CAST(g.codigo_cliente_omie AS CHAR), g.documento, g.cliente_nome)) AS customers_count,
                       MAX(g.ultima_compra) AS last_purchase
                FROM mart_compradores_por_geo g
                WHERE ' . implode(' AND ', $where) . '
                GROUP BY g.bairro_norm
                ORDER BY revenue DESC
                LIMIT :lim OFFSET :off';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return [
            'ok' => true,
            'chart_id' => 'geo_compact',
            'rows' => $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [],
            'meta' => [
                'source_tables' => ['mart_compradores_por_geo'],
                'diagnostics' => [
                    'city_filter' => $city,
                    'period_mode' => 'competencia',
                    'start_competencia' => $startMonth,
                    'end_competencia' => $endMonth,
                ],
            ],
        ];
    }
    // ── Pipeline Period ───────────────────────────────────────────────────────

    /**
     * @param array<string,string> $filters
     * @return array<string,mixed>
     */
    private function handlePipelinePeriod(string $start, string $end, array $filters, int $limit, int $offset): array
    {
        // Pipeline usa a mesma base de revenue_evolution
        return $this->handleRevenueEvolution($start, $end, $filters, $limit, $offset);
    }

    // ── Monthly Comparison ────────────────────────────────────────────────────

    /**
     * @param array<string,string> $filters
     * @return array<string,mixed>
     */
    private function handleMonthlyComparison(string $start, string $end, array $filters, int $limit, int $offset): array
    {
        // Comparativo mensal também usa a mesma base, mas a data é o mês inteiro.
        return $this->handleRevenueEvolution($start, $end, $filters, $limit, $offset);
    }

    /**
     * @param array<string,string> $filters
     * @return array<string,mixed>
     */
    private function handleFinanceRisk(string $start, string $end, array $filters, int $limit, int $offset): array
    {
        [$startMonth, $endMonth] = $this->monthRange($start, $end);
        if (!$this->tableExists('mart_financeiro_titulo')) {
            return [
                'ok' => true,
                'status' => 'empty',
                'chart_id' => 'finance_risk',
                'rows' => [],
                'meta' => [
                    'warnings' => ['mart_financeiro_titulo nao disponivel no cache local.'],
                    'source_tables' => [],
                ],
            ];
        }

        $sql = 'SELECT f.numero_documento AS document,
                       f.nome_cliente AS client,
                       f.nome_vendedor AS vendor_name,
                       f.status_titulo AS status,
                       f.data_vencimento AS due_date,
                       f.valor_aberto AS open_value,
                       f.valor_recebido AS received_value,
                       f.dias_atraso AS overdue_days
                FROM mart_financeiro_titulo f
                WHERE ' . TenantContext::sqlFilter('f') . '
                  AND ' . $this->martChannelFilter('f') . '
                  AND f.competencia >= :start_month AND f.competencia <= :end_month
                ORDER BY f.dias_atraso DESC, f.valor_aberto DESC
                LIMIT :lim OFFSET :off';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':start_month', $startMonth);
        $stmt->bindValue(':end_month', $endMonth);
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return [
            'ok' => true,
            'chart_id' => 'finance_risk',
            'rows' => DataMasker::applyToRows($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []),
            'meta' => ['source_tables' => ['mart_financeiro_titulo']],
        ];
    }

    /**
     * @param array<string,string> $filters
     * @return array<string,mixed>
     */
    private function handleAttentionPoints(string $start, string $end, array $filters, int $limit, int $offset): array
    {
        [$startMonth, $endMonth] = $this->monthRange($start, $end);
        $rows = [];
        $sourceTables = [];

        if ($this->tableExists('mart_bridge_pedido_nfe_titulo')) {
            $sourceTables[] = 'mart_bridge_pedido_nfe_titulo';
            $tenantFilter = \BIomie\Support\TenantContext::sqlFilter();
            $sql  = "SELECT bridge_status AS type,
                           COUNT(*) AS total,
                           SUM(valor_pedido) AS amount,
                           MAX(refreshed_at) AS last_seen
                    FROM mart_bridge_pedido_nfe_titulo
                    WHERE {$tenantFilter}
                      AND " . $this->martChannelFilter('mart_bridge_pedido_nfe_titulo') . "
                      AND competencia >= :start_month AND competencia <= :end_month
                      AND bridge_status <> 'completo'
                    GROUP BY bridge_status
                    ORDER BY total DESC
                    LIMIT :lim OFFSET :off";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':start_month', $startMonth);
            $stmt->bindValue(':end_month', $endMonth);
            $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
            $stmt->bindValue(':off', $offset, \PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        }

        return [
            'ok' => true,
            'chart_id' => 'attention_points',
            'rows' => $rows,
            'meta' => [
                'source_tables' => $sourceTables,
                'warnings' => $sourceTables === [] ? ['mart_bridge_pedido_nfe_titulo nao disponivel no cache local.'] : [],
            ],
        ];
    }
}

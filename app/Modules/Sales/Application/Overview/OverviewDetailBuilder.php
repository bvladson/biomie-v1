<?php

declare(strict_types=1);

namespace BIomie\Modules\Sales\Application\Overview;

use BIomie\CanonicalRepository;
use BIomie\Config;
use BIomie\Database;
use BIomie\DataMasker;
use BIomie\Logger;
use BIomie\RequestContext;
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

    private function __construct(\PDO $pdo, Logger $logger, CanonicalRepository $canonical)
    {
        $this->pdo = $pdo;
        $this->logger = $logger;
        $this->canonical = $canonical;
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
        );
        $stmt->execute([':table' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function monthRange(string $start, string $end): array
    {
        return [substr($start, 0, 7), substr($end, 0, 7)];
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
        $dateFilter = $filters['date'] ?? '';
        $where = ['n.data_emissao >= :start', 'n.data_emissao <= :end'];
        $params = [':start' => $start, ':end' => $end];

        if ($dateFilter !== '') {
            $where[] = 'n.data_emissao = :date_filter';
            $params[':date_filter'] = $dateFilter;
        }

        $sql = 'SELECT n.numero_nf AS code, n.data_emissao AS date, n.valor_total AS revenue,
                       COALESCE(n.nome_vendedor, \'Nao identificado\') AS vendor_name,
                       COALESCE(n.nome_cliente, \'Cliente nao informado\') AS client
                FROM mart_fiscal_nfe n
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY n.valor_total DESC
                LIMIT :lim OFFSET :off';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return ['ok' => true, 'chart_id' => 'revenue_evolution', 'rows' => DataMasker::maskRows($rows)];
    }

    // ── Vendor Ranking ────────────────────────────────────────────────────────

    /**
     * @param array<string,string> $filters
     * @return array<string,mixed>
     */
    private function handleVendorRanking(string $start, string $end, array $filters, int $limit, int $offset): array
    {
        $sql = 'SELECT COALESCE(n.nome_vendedor, \'Nao identificado\') AS vendor_name,
                       COUNT(*) AS orders,
                       SUM(n.valor_total) AS revenue,
                       AVG(n.valor_total) AS ticket
                FROM mart_fiscal_nfe n
                WHERE n.data_emissao >= :start AND n.data_emissao <= :end
                GROUP BY vendor_name
                ORDER BY revenue DESC
                LIMIT :lim OFFSET :off';

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
        [$startMonth, $endMonth] = $this->monthRange($start, $end);
        $sql = 'SELECT p.descricao_produto AS description,
                       p.codigo_produto AS product_code,
                       SUM(p.quantidade) AS quantity,
                       SUM(p.valor_total) AS revenue,
                       SUM(p.nfes_count) AS orders
                FROM mart_produto_periodo p
                WHERE p.competencia >= :start_month AND p.competencia <= :end_month
                GROUP BY p.descricao_produto, p.codigo_produto
                ORDER BY revenue DESC
                LIMIT :lim OFFSET :off';

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
        [$startMonth, $endMonth] = $this->monthRange($start, $end);
        $sql = 'SELECT COALESCE(c.nome_cliente, \'Cliente nao informado\') AS client_name,
                       c.codigo_cliente_omie AS client_id,
                       c.documento_cliente AS client_document,
                       SUM(c.nfes_emitidas) AS notes_count,
                       SUM(c.valor_nfe) AS invoice_total,
                       ROUND(SUM(c.valor_nfe) * 100.0 / NULLIF((SELECT SUM(valor_nfe) FROM mart_cliente_periodo WHERE competencia >= :start2 AND competencia <= :end2), 0), 1) AS share_pct
                FROM mart_cliente_periodo c
                WHERE c.competencia >= :start AND c.competencia <= :end
                GROUP BY c.codigo_cliente_omie, c.documento_cliente, client_name
                ORDER BY invoice_total DESC
                LIMIT :lim OFFSET :off';

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
            'rows' => DataMasker::maskRows($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []),
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

        if ($this->tableExists('mart_fiscal_nfe')) {
            $itemsJoin = $this->tableExists('biomie_notas_fiscais_itens_canonicos')
                ? 'LEFT JOIN (SELECT nota_key, COUNT(*) AS items_qty FROM biomie_notas_fiscais_itens_canonicos GROUP BY nota_key) i ON i.nota_key = n.nota_key'
                : '';
            $itemsExpr = $itemsJoin !== '' ? 'COALESCE(i.items_qty, 0)' : '0';
            $bridgeJoin = $this->tableExists('mart_bridge_pedido_nfe_titulo')
                ? 'LEFT JOIN mart_bridge_pedido_nfe_titulo b ON b.nota_key = n.nota_key'
                : '';
            $bridgeExpr = $bridgeJoin !== '' ? "COALESCE(b.codigo_pedido, '')" : "''";
            $sourceTables[] = 'mart_fiscal_nfe';
            if ($itemsJoin !== '') {
                $sourceTables[] = 'biomie_notas_fiscais_itens_canonicos';
            }
            if ($bridgeJoin !== '') {
                $sourceTables[] = 'mart_bridge_pedido_nfe_titulo';
            }

            $sql = "SELECT n.nota_key,
                           n.numero_nf AS code,
                           n.numero_nf AS numero_nf,
                           COALESCE(n.data_competencia, n.data_emissao) AS issue_date,
                           COALESCE(n.nome_cliente, 'Cliente nao informado') AS client,
                           COALESCE(n.nome_vendedor, 'Nao identificado') AS vendor_name,
                           {$itemsExpr} AS items_qty,
                           n.valor_total AS total,
                           n.valor_mercadorias AS merchandise_total,
                           n.valor_descontos AS discount_total,
                           n.nota_key AS document_key,
                           {$bridgeExpr} AS origin_order_code
                    FROM mart_fiscal_nfe n
                    {$itemsJoin}
                    {$bridgeJoin}
                    WHERE COALESCE(n.data_competencia, n.data_emissao) >= :start
                      AND COALESCE(n.data_competencia, n.data_emissao) <= :end
                    ORDER BY n.valor_total DESC
                    LIMIT :lim OFFSET :off";

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':start', $start);
            $stmt->bindValue(':end', $end);
            $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
            $stmt->bindValue(':off', $offset, \PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        }

        if ($rows === [] && $this->tableExists('biomie_notas_fiscais_canonicas')) {
            $sourceTables = ['biomie_notas_fiscais_canonicas'];
            $itemsJoin = $this->tableExists('biomie_notas_fiscais_itens_canonicos')
                ? 'LEFT JOIN (SELECT nota_key, COUNT(*) AS items_qty FROM biomie_notas_fiscais_itens_canonicos GROUP BY nota_key) i ON i.nota_key = n.nota_key'
                : '';
            $itemsExpr = $itemsJoin !== '' ? 'COALESCE(i.items_qty, n.quantidade_itens, 0)' : 'COALESCE(n.quantidade_itens, 0)';
            if ($itemsJoin !== '') {
                $sourceTables[] = 'biomie_notas_fiscais_itens_canonicos';
            }

            $sql = "SELECT n.nota_key,
                           n.numero_nf AS code,
                           n.numero_nf AS numero_nf,
                           n.serie_nf AS serie,
                           n.chave_nfe AS document_key,
                           COALESCE(n.data_competencia, n.data_emissao) AS issue_date,
                           COALESCE(n.nome_cliente, 'Cliente nao informado') AS client,
                           n.documento_cliente AS customer_document,
                           COALESCE(n.nome_vendedor, 'Nao identificado') AS vendor_name,
                           {$itemsExpr} AS items_qty,
                           n.valor_total AS total,
                           n.valor_mercadorias AS merchandise_total,
                           n.valor_descontos AS discount_total,
                           n.codigo_pedido_origem AS origin_order_code
                    FROM biomie_notas_fiscais_canonicas n
                    {$itemsJoin}
                    WHERE COALESCE(n.data_competencia, n.data_emissao) >= :start
                      AND COALESCE(n.data_competencia, n.data_emissao) <= :end
                    ORDER BY n.valor_total DESC
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
            'rows' => DataMasker::maskRows($rows),
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
        $where = ['g.competencia >= :start_month', 'g.competencia <= :end_month'];
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

        $sql = 'SELECT numero_documento AS document,
                       nome_cliente AS client,
                       nome_vendedor AS vendor_name,
                       status_titulo AS status,
                       data_vencimento AS due_date,
                       valor_aberto AS open_value,
                       valor_recebido AS received_value,
                       dias_atraso AS overdue_days
                FROM mart_financeiro_titulo
                WHERE competencia >= :start_month AND competencia <= :end_month
                ORDER BY dias_atraso DESC, valor_aberto DESC
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
            'rows' => DataMasker::maskRows($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []),
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
            $sql = 'SELECT bridge_status AS type,
                           COUNT(*) AS total,
                           SUM(valor_pedido) AS amount,
                           MAX(refreshed_at) AS last_seen
                    FROM mart_bridge_pedido_nfe_titulo
                    WHERE competencia >= :start_month AND competencia <= :end_month
                      AND bridge_status <> \'completo\'
                    GROUP BY bridge_status
                    ORDER BY total DESC
                    LIMIT :lim OFFSET :off';
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

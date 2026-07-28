<?php

declare(strict_types=1);

namespace BIomie\Modules\Sales\Application\Overview;

use BIomie\CanonicalRepository;
use BIomie\Commercial\CommercialFilterPreferenceStore;
use BIomie\Commercial\CommercialRequestScope;
use BIomie\Config;
use BIomie\Database;
use BIomie\Acl\DataMasker;
use BIomie\DataPlatform\Silver\Readers\CanonicalReadSupport;
use BIomie\Logger;
use BIomie\RequestContext;
use BIomie\Support\TenantContext;
use BIomie\Support\VendorScopeFilter;
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
    private CommercialRequestScope $commercialRequestScope;

    private function __construct(
        \PDO $pdo,
        Logger $logger,
        CanonicalRepository $canonical,
        CommercialRequestScope $commercialRequestScope,
    )
    {
        $this->pdo = $pdo;
        $this->logger = $logger;
        $this->canonical = $canonical;
        $this->canonicalReadSupport = new CanonicalReadSupport(static fn (): \PDO => $pdo);
        $this->commercialRequestScope = $commercialRequestScope;
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

    /**
     * Corrige somente texto de exibicao que chegou HTML-encoded da origem.
     * Duas passagens cobrem "&amp;amp;" sem tocar em codigos/chaves.
     */
    private function decodeDisplayText(mixed $value): string
    {
        $decoded = trim((string) $value);
        for ($pass = 0; $pass < 2; $pass++) {
            $next = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($next === $decoded) {
                break;
            }
            $decoded = $next;
        }

        return $decoded;
    }

    public static function boot(): self
    {
        $config = new Config();
        $logger = new Logger(\BIomie\Bootstrap::rootPath('storage/logs/overview_detail.log'));
        $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
        $pdo = Database::connect($config, $connection);
        $canonical = new CanonicalRepository($pdo, $logger);
        $requestScope = CommercialRequestScope::fromRequest(
            $pdo,
            CommercialFilterPreferenceStore::PAGE_COMMERCIAL,
        );

        return new self($pdo, $logger, $canonical, $requestScope);
    }

    /**
     * Intersecta qualquer filtro solicitado com o escopo de vendedores concedido.
     * A ausência de vendor_ids nunca amplia um perfil restrito.
     *
     * @param array<string,mixed> $params
     */
    private function sellerScopeCondition(string $alias, array &$params): string
    {
        $user = \BIomie\Auth::currentUser();
        $userId = is_array($user) ? (int) ($user['id'] ?? 0) : 0;
        $companyIds = TenantContext::allowedCompanyIds();
        $sellerSelfOnly = !\BIomie\Auth::hasCapability('sales.quotes.followup.view')
            && \BIomie\Auth::hasCapability('sales.quotes.followup.self');

        if ($this->commercialRequestScope->forceEmptySellers()) {
            return '1=0';
        }

        return VendorScopeFilter::conditionForContext(
            $this->pdo,
            $userId,
            $companyIds,
            $this->commercialRequestScope->vendorScopeSeed(),
            $alias,
            $params,
            'codigo_vendedor_omie',
            'company_id',
            $sellerSelfOnly,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function commercialScopeMeta(string $start, string $end): array
    {
        return $this->commercialRequestScope->meta($start, $end);
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
        $tenantFilter = TenantContext::sqlFilter('nota');
        $where = [
            $tenantFilter,
            $this->canonicalCommercialFilter('nota'),
            "nota.status_fiscal = 'autorizada'",
            'nota.data_competencia >= :start',
            'nota.data_competencia <= :end',
        ];
        $params = [':start' => $start, ':end' => $end];
        $where[] = $this->sellerScopeCondition('nota', $params);
        $where[] = $this->commercialRequestScope->channelRefineSql(
            $this->pdo,
            'biomie_notas_fiscais_canonicas',
            'nota',
        );

        $productCode = trim((string) ($filters['product_code'] ?? ''));
        if ($productCode !== '') {
            $where[] = 'TRIM(COALESCE(item.codigo_produto, \'\')) = :product_code';
            $params[':product_code'] = $productCode;
        }

        $vendorName = mb_substr(trim((string) ($filters['vendor_name'] ?? '')), 0, 120);
        if ($vendorName !== '' && $this->commercialRequestScope->vendorScopeSeed() === []) {
            $where[] = 'nota.nome_vendedor LIKE :vendor_name';
            $params[':vendor_name'] = '%' . $vendorName . '%';
        }

        $client = mb_substr(trim((string) ($filters['client'] ?? '')), 0, 120);
        if ($client !== '') {
            $where[] = 'nota.nome_cliente LIKE :client';
            $params[':client'] = '%' . $client . '%';
        }

        $product = mb_substr(trim((string) ($filters['product'] ?? '')), 0, 120);
        if ($product !== '') {
            $where[] = 'EXISTS (
                SELECT 1
                FROM biomie_notas_fiscais_itens_canonicos filtered_item
                WHERE filtered_item.company_id = nota.company_id
                  AND filtered_item.nota_key = nota.nota_key
                  AND filtered_item.descricao_produto LIKE :product
            )';
            $params[':product'] = '%' . $product . '%';
        }

        $search = mb_substr(trim((string) ($filters['search'] ?? '')), 0, 120);
        if ($search !== '') {
            $where[] = '(item.descricao_produto LIKE :search OR item.codigo_produto LIKE :search)';
            $params[':search'] = '%' . $search . '%';
        }

        $sortBy = strtolower(trim((string) ($filters['sort_by'] ?? 'revenue')));
        $sortOrder = strtolower(trim((string) ($filters['sort_order'] ?? 'desc'))) === 'asc' ? 'ASC' : 'DESC';
        $sortColumns = [
            'revenue' => 'products.revenue',
            'quantity' => 'products.quantity',
            'average_unit_price' => '(products.revenue / NULLIF(products.quantity, 0))',
            'invoice_count' => 'products.invoice_count',
            'customers_count' => 'products.customers_count',
            'discount' => 'products.discount_total',
            'last_sale' => 'products.last_sale',
            'description' => 'products.description',
        ];
        $sortBy = array_key_exists($sortBy, $sortColumns) ? $sortBy : 'revenue';
        $orderBy = $sortColumns[$sortBy] . ' ' . $sortOrder
            . ', products.revenue DESC, products.description ASC';

        $aggregateSql = 'SELECT
                    nota.company_id,
                    COALESCE(NULLIF(TRIM(item.codigo_produto), \'\'), \'SEM_SKU\') AS product_code,
                    COALESCE(NULLIF(TRIM(item.descricao_produto), \'\'), \'Produto sem descricao\') AS description,
                    COALESCE(SUM(item.quantidade), 0) AS quantity,
                    COALESCE(SUM(item.valor_total), 0) AS revenue,
                    COALESCE(SUM(item.valor_desconto), 0) AS discount_total,
                    COUNT(DISTINCT CONCAT(item.company_id, \':\', item.nota_key)) AS invoice_count,
                    COUNT(DISTINCT COALESCE(
                        NULLIF(CAST(nota.codigo_cliente_omie AS CHAR), \'\'),
                        NULLIF(TRIM(nota.documento_cliente), \'\'),
                        NULLIF(TRIM(nota.nome_cliente), \'\')
                    )) AS customers_count,
                    COUNT(DISTINCT COALESCE(
                        NULLIF(TRIM(nota.codigo_vendedor_omie), \'\'),
                        NULLIF(TRIM(nota.nome_vendedor), \'\')
                    )) AS vendors_count,
                    GROUP_CONCAT(
                        DISTINCT COALESCE(NULLIF(TRIM(nota.nome_vendedor), \'\'), \'Nao identificado\')
                        ORDER BY COALESCE(NULLIF(TRIM(nota.nome_vendedor), \'\'), \'Nao identificado\')
                        SEPARATOR \' | \'
                    ) AS vendor_names,
                    MIN(nota.data_competencia) AS first_sale,
                    MAX(nota.data_competencia) AS last_sale,
                    SUBSTRING_INDEX(
                        GROUP_CONCAT(
                            COALESCE(NULLIF(TRIM(nota.numero_nf), \'\'), nota.nota_key)
                            ORDER BY nota.data_competencia DESC, nota.id DESC
                            SEPARATOR \'||\'
                        ),
                        \'||\',
                        1
                    ) AS latest_nf
                FROM biomie_notas_fiscais_itens_canonicos item
                INNER JOIN biomie_notas_fiscais_canonicas nota
                    ON nota.company_id = item.company_id
                   AND nota.nota_key = item.nota_key
                WHERE ' . implode(' AND ', $where) . '
                GROUP BY
                    nota.company_id,
                    COALESCE(NULLIF(TRIM(item.codigo_produto), \'\'), \'SEM_SKU\'),
                    COALESCE(NULLIF(TRIM(item.descricao_produto), \'\'), \'Produto sem descricao\')';

        $sql = 'SELECT
                    products.*,
                    products.invoice_count AS orders,
                    ROUND(products.revenue / NULLIF(products.quantity, 0), 2) AS average_unit_price,
                    ROUND(products.revenue / NULLIF(products.invoice_count, 0), 2) AS average_revenue_per_nf,
                    ROUND(products.quantity / NULLIF(products.invoice_count, 0), 2) AS average_quantity_per_nf,
                    ROUND(
                        products.discount_total * 100.0
                        / NULLIF(products.revenue + products.discount_total, 0),
                        1
                    ) AS discount_pct,
                    ROUND(
                        products.revenue * 100.0
                        / NULLIF(SUM(products.revenue) OVER (), 0),
                        1
                    ) AS share_pct,
                    COUNT(*) OVER () AS filtered_count,
                    SUM(products.revenue) OVER () AS filtered_revenue,
                    SUM(products.quantity) OVER () AS filtered_quantity
                FROM (' . $aggregateSql . ') products
                ORDER BY ' . $orderBy . '
                LIMIT :lim OFFSET :off';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $summary = [
            'products_count' => $rows !== [] ? (int) ($rows[0]['filtered_count'] ?? 0) : 0,
            'revenue' => $rows !== [] ? (float) ($rows[0]['filtered_revenue'] ?? 0) : 0.0,
            'quantity' => $rows !== [] ? (float) ($rows[0]['filtered_quantity'] ?? 0) : 0.0,
        ];
        foreach ($rows as &$row) {
            unset($row['filtered_count'], $row['filtered_revenue'], $row['filtered_quantity']);
            $row['description'] = $this->decodeDisplayText($row['description'] ?? '');
            $row['vendor_names'] = $this->decodeDisplayText($row['vendor_names'] ?? '');
            foreach (['quantity', 'revenue', 'discount_total', 'average_unit_price', 'average_revenue_per_nf', 'average_quantity_per_nf', 'discount_pct', 'share_pct'] as $field) {
                $row[$field] = (float) ($row[$field] ?? 0);
            }
            foreach (['invoice_count', 'orders', 'customers_count', 'vendors_count'] as $field) {
                $row[$field] = (int) ($row[$field] ?? 0);
            }
        }
        unset($row);

        return [
            'ok' => true,
            'chart_id' => 'top_products',
            'rows' => $rows,
            'summary' => $summary,
            'meta' => [
                'source_tables' => ['biomie_notas_fiscais_canonicas', 'biomie_notas_fiscais_itens_canonicos'],
                'period_scope' => 'exact_range',
                'pagination' => [
                    'limit' => $limit,
                    'offset' => $offset,
                    'total' => $summary['products_count'],
                ],
                'sort' => ['by' => $sortBy, 'order' => strtolower($sortOrder)],
            ] + $this->commercialScopeMeta($start, $end),
        ];
    }

    // ── Top Customers ─────────────────────────────────────────────────────────

    /**
     * @param array<string,string> $filters
     * @return array<string,mixed>
     */
    private function handleTopCustomers(string $start, string $end, array $filters, int $limit, int $offset): array
    {
        $tenantFilter = TenantContext::sqlFilter('n');
        $commercialBase = $this->canonicalReadSupport->commercialBaseSql('n');
        $documentDigits = "REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(n.documento_cliente, ''), '.', ''), '/', ''), '-', ''), ' ', '')";
        $where = [
            $tenantFilter,
            $this->canonicalCommercialFilter('n'),
            "n.status_fiscal = 'autorizada'",
            'n.data_competencia >= :start',
            'n.data_competencia <= :end',
        ];
        $params = [':start' => $start, ':end' => $end];
        $where[] = $this->sellerScopeCondition('n', $params);
        $where[] = $this->commercialRequestScope->channelRefineSql(
            $this->pdo,
            'biomie_notas_fiscais_canonicas',
            'n',
        );

        $vendorName = mb_substr(trim((string) ($filters['vendor_name'] ?? '')), 0, 120);
        if ($vendorName !== '' && $this->commercialRequestScope->vendorScopeSeed() === []) {
            $where[] = 'n.nome_vendedor LIKE :vendor_name';
            $params[':vendor_name'] = '%' . $vendorName . '%';
        }

        $client = mb_substr(trim((string) ($filters['client'] ?? '')), 0, 120);
        if ($client !== '') {
            $where[] = 'n.nome_cliente LIKE :client';
            $params[':client'] = '%' . $client . '%';
        }

        $product = mb_substr(trim((string) ($filters['product'] ?? '')), 0, 120);
        if ($product !== '') {
            $where[] = 'EXISTS (
                SELECT 1
                FROM biomie_notas_fiscais_itens_canonicos filtered_item
                WHERE filtered_item.company_id = n.company_id
                  AND filtered_item.nota_key = n.nota_key
                  AND filtered_item.descricao_produto LIKE :product
            )';
            $params[':product'] = '%' . $product . '%';
        }

        $customerDocument = preg_replace('/\D+/', '', (string) ($filters['customer_document'] ?? '')) ?? '';
        if ($customerDocument !== '') {
            $where[] = $documentDigits . ' = :customer_document';
            $params[':customer_document'] = $customerDocument;
        }

        $customerType = strtolower(trim((string) ($filters['customer_type'] ?? '')));
        if ($customerType === 'cpf') {
            $where[] = 'CHAR_LENGTH(' . $documentDigits . ') = 11';
        } elseif ($customerType === 'cnpj') {
            $where[] = 'CHAR_LENGTH(' . $documentDigits . ') = 14';
        } elseif (in_array($customerType, ['unknown', 'nao_identificado'], true)) {
            $where[] = 'CHAR_LENGTH(' . $documentDigits . ') NOT IN (11, 14)';
        }

        $search = mb_substr(trim((string) ($filters['search'] ?? '')), 0, 120);
        if ($search !== '') {
            $where[] = '(n.nome_cliente LIKE :search OR n.documento_cliente LIKE :search OR n.nome_vendedor LIKE :search)';
            $params[':search'] = '%' . $search . '%';
        }

        $sortBy = strtolower(trim((string) ($filters['sort_by'] ?? 'revenue')));
        $sortOrder = strtolower(trim((string) ($filters['sort_order'] ?? 'desc'))) === 'asc' ? 'ASC' : 'DESC';
        $sortColumns = [
            'revenue' => 'customers.invoice_total',
            'notes' => 'customers.notes_count',
            'ticket' => 'customers.average_ticket',
            'last_purchase' => 'customers.last_purchase',
            'first_purchase' => 'customers.first_purchase',
            'vendors' => 'customers.vendors_count',
            'name' => 'customers.client_name',
        ];
        $sortBy = array_key_exists($sortBy, $sortColumns) ? $sortBy : 'revenue';
        $orderBy = $sortColumns[$sortBy] . ' ' . $sortOrder
            . ', customers.invoice_total DESC, customers.client_name ASC';

        $aggregateSql = 'SELECT
                    n.company_id,
                    CASE
                        WHEN n.codigo_cliente_omie IS NOT NULL AND n.codigo_cliente_omie > 0
                            THEN CONCAT(n.company_id, \':id:\', n.codigo_cliente_omie)
                        WHEN NULLIF(TRIM(COALESCE(n.documento_cliente, \'\')), \'\') IS NOT NULL
                            THEN CONCAT(n.company_id, \':doc:\', TRIM(n.documento_cliente))
                        WHEN NULLIF(TRIM(COALESCE(n.nome_cliente, \'\')), \'\') IS NOT NULL
                            THEN CONCAT(n.company_id, \':name:\', TRIM(n.nome_cliente))
                        ELSE CONCAT(n.company_id, \':unknown\')
                    END AS client_identity,
                    MAX(n.codigo_cliente_omie) AS client_id,
                    COALESCE(MAX(NULLIF(TRIM(n.nome_cliente), \'\')), \'Cliente nao informado\') AS client_name,
                    COALESCE(MAX(NULLIF(TRIM(n.documento_cliente), \'\')), \'\') AS client_document,
                    CASE
                        WHEN MAX(CHAR_LENGTH(' . $documentDigits . ')) = 11 THEN \'CPF\'
                        WHEN MAX(CHAR_LENGTH(' . $documentDigits . ')) = 14 THEN \'CNPJ\'
                        ELSE \'Nao identificado\'
                    END AS document_type,
                    COUNT(DISTINCT CONCAT(n.company_id, \':\', n.nota_key)) AS notes_count,
                    COALESCE(SUM(' . $commercialBase . '), 0) AS invoice_total,
                    COALESCE(AVG(' . $commercialBase . '), 0) AS average_ticket,
                    COALESCE(SUM(n.valor_mercadorias), 0) AS merchandise_total,
                    COALESCE(SUM(n.valor_descontos), 0) AS discount_total,
                    COUNT(DISTINCT COALESCE(
                        NULLIF(TRIM(n.codigo_vendedor_omie), \'\'),
                        NULLIF(TRIM(n.nome_vendedor), \'\')
                    )) AS vendors_count,
                    GROUP_CONCAT(
                        DISTINCT COALESCE(NULLIF(TRIM(n.nome_vendedor), \'\'), \'Nao identificado\')
                        ORDER BY COALESCE(NULLIF(TRIM(n.nome_vendedor), \'\'), \'Nao identificado\')
                        SEPARATOR \' | \'
                    ) AS vendor_names,
                    GROUP_CONCAT(
                        DISTINCT NULLIF(TRIM(n.codigo_vendedor_omie), \'\')
                        ORDER BY NULLIF(TRIM(n.codigo_vendedor_omie), \'\')
                        SEPARATOR \'|\'
                    ) AS vendor_ids,
                    MIN(n.data_competencia) AS first_purchase,
                    MAX(n.data_competencia) AS last_purchase,
                    SUBSTRING_INDEX(
                        GROUP_CONCAT(
                            COALESCE(NULLIF(TRIM(n.numero_nf), \'\'), n.nota_key)
                            ORDER BY n.data_competencia DESC, n.id DESC
                            SEPARATOR \'||\'
                        ),
                        \'||\',
                        1
                    ) AS latest_nf
                FROM biomie_notas_fiscais_canonicas n
                WHERE ' . implode(' AND ', $where) . '
                GROUP BY n.company_id, client_identity';

        $sql = 'SELECT
                    customers.*,
                    ROUND(
                        customers.invoice_total * 100.0
                        / NULLIF(SUM(customers.invoice_total) OVER (), 0),
                        1
                    ) AS share_pct,
                    COUNT(*) OVER () AS filtered_count,
                    SUM(customers.notes_count) OVER () AS filtered_invoices,
                    SUM(customers.invoice_total) OVER () AS filtered_revenue
                FROM (' . $aggregateSql . ') customers
                ORDER BY ' . $orderBy . '
                LIMIT :lim OFFSET :off';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $clientsCount = $rows !== [] ? (int) ($rows[0]['filtered_count'] ?? 0) : 0;
        $invoicesCount = $rows !== [] ? (int) ($rows[0]['filtered_invoices'] ?? 0) : 0;
        $revenue = $rows !== [] ? (float) ($rows[0]['filtered_revenue'] ?? 0) : 0.0;
        $summary = [
            'clients_count' => $clientsCount,
            'invoices_count' => $invoicesCount,
            'revenue' => $revenue,
            'average_ticket' => $invoicesCount > 0 ? round($revenue / $invoicesCount, 2) : 0.0,
        ];
        foreach ($rows as &$row) {
            unset($row['filtered_count'], $row['filtered_invoices'], $row['filtered_revenue']);
            $row['client_name'] = $this->decodeDisplayText($row['client_name'] ?? '');
            $row['vendor_names'] = $this->decodeDisplayText($row['vendor_names'] ?? '');
            foreach (['invoice_total', 'average_ticket', 'merchandise_total', 'discount_total', 'share_pct'] as $field) {
                $row[$field] = (float) ($row[$field] ?? 0);
            }
            foreach (['notes_count', 'vendors_count'] as $field) {
                $row[$field] = (int) ($row[$field] ?? 0);
            }
        }
        unset($row);

        return [
            'ok' => true,
            'chart_id' => 'top_customers',
            'rows' => DataMasker::applyToRows($rows, \BIomie\Auth::canViewFullDocuments()),
            'summary' => $summary,
            'meta' => [
                'source_tables' => ['biomie_notas_fiscais_canonicas'],
                'period_scope' => 'exact_range',
                'pagination' => [
                    'limit' => $limit,
                    'offset' => $offset,
                    'total' => $clientsCount,
                ],
                'sort' => ['by' => $sortBy, 'order' => strtolower($sortOrder)],
                'documents_masked' => !\BIomie\Auth::canViewFullDocuments(),
            ] + $this->commercialScopeMeta($start, $end),
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

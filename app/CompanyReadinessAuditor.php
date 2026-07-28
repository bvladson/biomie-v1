<?php

declare(strict_types=1);

namespace BIomie;

use PDO;
use RuntimeException;

/**
 * Diagnóstico operacional por empresa.
 *
 * O auditor é somente leitura e nunca usa TenantContext: cada consulta recebe
 * explicitamente o company_id que está sendo auditado.
 */
final class CompanyReadinessAuditor
{
    /** @var array<string,int> */
    private const STATUS_RANK = [
        'ok' => 0,
        'no_data' => 0,
        'warning' => 1,
        'critical' => 2,
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function audit(?string $companySelector = null): array
    {
        $companies = $this->companies($companySelector);
        if ($companies === []) {
            throw new RuntimeException('Nenhuma empresa ativa encontrada para o filtro informado.');
        }

        $databaseNow = (string) $this->pdo->query('SELECT NOW()')->fetchColumn();
        $results = [];
        foreach ($companies as $company) {
            $companyId = (int) $company['id'];
            $freshness = $this->freshness($companyId, $databaseNow);
            $completeness = $this->completeness($companyId);
            $status = $this->worstStatus([
                (string) $freshness['status'],
                (string) $completeness['status'],
            ]);

            $results[] = [
                'company_id' => $companyId,
                'slug' => (string) $company['slug'],
                'name' => (string) $company['nome_fantasia'],
                'status' => $status,
                'freshness' => $freshness,
                'completeness' => $completeness,
                'recommended_actions' => $this->recommendedActions(
                    (string) $company['slug'],
                    $freshness,
                    $completeness,
                ),
            ];
        }

        $summary = [
            'companies_total' => count($results),
            'ok' => 0,
            'warning' => 0,
            'critical' => 0,
            'no_data' => 0,
        ];
        foreach ($results as $result) {
            $status = (string) $result['status'];
            if (array_key_exists($status, $summary)) {
                $summary[$status]++;
            }
        }

        return [
            'ok' => $summary['critical'] === 0,
            'generated_at' => date(DATE_ATOM),
            'database_now' => $databaseNow,
            'criteria' => [
                'freshness' => [
                    'commercial' => ['ok_hours' => 6, 'warning_until_hours' => 36],
                    'finance' => ['ok_hours' => 8, 'warning_until_hours' => 48],
                    'customers' => ['ok_hours' => 24, 'warning_until_hours' => 72],
                    'gold' => ['ok_hours' => 24, 'warning_until_hours' => 72],
                    'stock' => ['ok_hours' => 24, 'warning_until_hours' => 72],
                ],
                'completeness' => 'Cada métrica declara seus limites ok_at_pct e warning_at_pct.',
            ],
            'summary' => $summary,
            'companies' => $results,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function companies(?string $selector): array
    {
        $sql = 'SELECT id, slug, nome_fantasia
                  FROM biomie_companies
                 WHERE ativo = 1';
        $params = [];
        $selector = trim((string) $selector);
        if ($selector !== '') {
            $sql .= ' AND (id = :company_id OR UPPER(slug) = UPPER(:slug))';
            $params = [
                'company_id' => ctype_digit($selector) ? (int) $selector : 0,
                'slug' => $selector,
            ];
        }
        $sql .= ' ORDER BY id';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<string,mixed>
     */
    private function freshness(int $companyId, string $databaseNow): array
    {
        $id = max(1, $companyId);
        $row = $this->pdo->query(<<<SQL
            SELECT
                (SELECT active
                   FROM biomie_company_integrations
                  WHERE company_id = {$id} AND provider = 'omie'
                  LIMIT 1) AS integration_active,
                (SELECT last_success_at
                   FROM biomie_company_integrations
                  WHERE company_id = {$id} AND provider = 'omie'
                  LIMIT 1) AS integration_last_success,
                (SELECT MAX(CASE WHEN status IN ('completed','completed_with_warnings') THEN finished_at END)
                   FROM biomie_sync_runs
                  WHERE company_id = {$id} AND domain_name IN ('commercial','comercial')) AS commercial_run_at,
                (SELECT MAX(CASE WHEN status IN ('completed','completed_with_warnings') THEN finished_at END)
                   FROM biomie_sync_runs
                  WHERE company_id = {$id} AND domain_name IN ('finance','financeiro')) AS finance_run_at,
                (SELECT MAX(sincronizado_em) FROM biomie_pedidos_canonicos WHERE company_id = {$id}) AS orders_at,
                (SELECT COUNT(*) FROM biomie_pedidos_canonicos WHERE company_id = {$id}) AS orders_rows,
                (SELECT MAX(sincronizado_em) FROM biomie_notas_fiscais_canonicas WHERE company_id = {$id}) AS invoices_at,
                (SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas WHERE company_id = {$id}) AS invoices_rows,
                (SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas
                  WHERE company_id = {$id}
                    AND status_fiscal = 'autorizada'
                    AND operation_scope = 'commercial'
                    AND COALESCE(is_biomie_commercial_visible, 1) = 1) AS commercial_invoices_rows,
                (SELECT MAX(sincronizado_em) FROM biomie_vendedores_omie WHERE company_id = {$id}) AS vendors_at,
                (SELECT COUNT(*) FROM biomie_vendedores_omie WHERE company_id = {$id}) AS vendors_rows,
                (SELECT MAX(sincronizado_em) FROM biomie_titulos_financeiros_canonicos WHERE company_id = {$id}) AS finance_at,
                (SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos WHERE company_id = {$id}) AS finance_rows,
                (SELECT MAX(sincronizado_em) FROM biomie_clientes_dim WHERE company_id = {$id}) AS customers_at,
                (SELECT COUNT(*) FROM biomie_clientes_dim WHERE company_id = {$id}) AS customers_rows,
                (SELECT MAX(refreshed_at) FROM mart_cliente_periodo WHERE company_id = {$id}) AS customer_mart_at,
                (SELECT COUNT(*) FROM mart_cliente_periodo WHERE company_id = {$id}) AS customer_mart_rows,
                (SELECT MAX(refreshed_at) FROM mart_produto_periodo WHERE company_id = {$id}) AS product_mart_at,
                (SELECT COUNT(*) FROM mart_produto_periodo WHERE company_id = {$id}) AS product_mart_rows,
                (SELECT MAX(refreshed_at) FROM mart_compradores_por_geo WHERE company_id = {$id}) AS geo_mart_at,
                (SELECT COUNT(*) FROM mart_compradores_por_geo WHERE company_id = {$id}) AS geo_mart_rows,
                (SELECT MAX(refreshed_at) FROM mart_vendas_por_cnae_cnpj WHERE company_id = {$id}) AS cnae_mart_at,
                (SELECT COUNT(*) FROM mart_vendas_por_cnae_cnpj WHERE company_id = {$id}) AS cnae_mart_rows,
                (SELECT MAX(atualizado_em) FROM biomie_produtos_estoque_saldos WHERE company_id = {$id}) AS stock_at,
                (SELECT COUNT(*) FROM biomie_produtos_estoque_saldos WHERE company_id = {$id}) AS stock_rows
        SQL)->fetch(PDO::FETCH_ASSOC) ?: [];

        $commercialRows = (int) ($row['orders_rows'] ?? 0)
            + (int) ($row['invoices_rows'] ?? 0)
            + (int) ($row['vendors_rows'] ?? 0);
        $commercialAt = $this->freshest([
            $row['commercial_run_at'] ?? null,
            $row['orders_at'] ?? null,
            $row['invoices_at'] ?? null,
            $row['vendors_at'] ?? null,
        ]);
        $financeAt = $this->freshest([
            $row['finance_run_at'] ?? null,
            $row['finance_at'] ?? null,
        ]);

        $integration = $this->freshnessMetric(
            'Integração Omie',
            $this->nullableString($row['integration_last_success'] ?? null),
            (int) ($row['integration_active'] ?? 0) === 1 ? 1 : 0,
            24,
            72,
            $databaseNow,
            'biomie_company_integrations',
        );
        if ((int) ($row['integration_active'] ?? 0) !== 1) {
            $integration['status'] = 'critical';
            $integration['reason'] = 'Integração Omie ausente ou inativa.';
        }

        $domains = [
            'integration' => $integration,
            'commercial' => $this->freshnessMetric(
                'Comercial',
                $commercialAt,
                $commercialRows,
                6,
                36,
                $databaseNow,
                'sync_runs + pedidos + notas + vendedores',
            ),
            'finance' => $this->freshnessMetric(
                'Financeiro',
                $financeAt,
                (int) ($row['finance_rows'] ?? 0),
                8,
                48,
                $databaseNow,
                'sync_runs + títulos canônicos',
            ),
            'customers' => $this->freshnessMetric(
                'Clientes',
                $this->nullableString($row['customers_at'] ?? null),
                (int) ($row['customers_rows'] ?? 0),
                24,
                72,
                $databaseNow,
                'biomie_clientes_dim',
                (int) ($row['invoices_rows'] ?? 0) > 0,
            ),
            'gold' => $this->goldFreshness($row, $databaseNow),
            'stock' => $this->freshnessMetric(
                'Estoque oficial',
                $this->nullableString($row['stock_at'] ?? null),
                (int) ($row['stock_rows'] ?? 0),
                24,
                72,
                $databaseNow,
                'biomie_produtos_estoque_saldos',
            ),
        ];

        return [
            'status' => $this->worstStatus(array_values(array_map(
                static fn (array $domain): string => (string) $domain['status'],
                $domains,
            ))),
            'domains' => $domains,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function goldFreshness(array $row, string $databaseNow): array
    {
        $expectedCommercial = (int) ($row['commercial_invoices_rows'] ?? 0) > 0
            || (int) ($row['orders_rows'] ?? 0) > 0;
        $components = [
            'customers' => $this->freshnessMetric(
                'Mart de clientes',
                $this->nullableString($row['customer_mart_at'] ?? null),
                (int) ($row['customer_mart_rows'] ?? 0),
                24,
                72,
                $databaseNow,
                'mart_cliente_periodo',
                $expectedCommercial,
            ),
            'products' => $this->freshnessMetric(
                'Mart de produtos',
                $this->nullableString($row['product_mart_at'] ?? null),
                (int) ($row['product_mart_rows'] ?? 0),
                24,
                72,
                $databaseNow,
                'mart_produto_periodo',
                $expectedCommercial,
            ),
            'geo' => $this->freshnessMetric(
                'Mart geográfico',
                $this->nullableString($row['geo_mart_at'] ?? null),
                (int) ($row['geo_mart_rows'] ?? 0),
                24,
                72,
                $databaseNow,
                'mart_compradores_por_geo',
                $expectedCommercial,
            ),
            'cnae' => $this->freshnessMetric(
                'Mart CNAE',
                $this->nullableString($row['cnae_mart_at'] ?? null),
                (int) ($row['cnae_mart_rows'] ?? 0),
                24,
                72,
                $databaseNow,
                'mart_vendas_por_cnae_cnpj',
                $expectedCommercial,
            ),
        ];

        return [
            'label' => 'Materializações Gold',
            'status' => $this->worstStatus(array_values(array_map(
                static fn (array $component): string => (string) $component['status'],
                $components,
            ))),
            'components' => $components,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function completeness(int $companyId): array
    {
        $id = max(1, $companyId);
        $row = $this->pdo->query(<<<SQL
            SELECT
                (SELECT COUNT(*) FROM biomie_clientes_dim WHERE company_id = {$id}) AS clients_total,
                (SELECT COUNT(*) FROM biomie_clientes_dim
                  WHERE company_id = {$id}
                    AND TRIM(COALESCE(documento_normalizado, cpf_cnpj, '')) <> '') AS clients_document,
                (SELECT COUNT(*) FROM biomie_clientes_dim
                  WHERE company_id = {$id}
                    AND TRIM(COALESCE(vendedor_responsavel_codigo_omie, '')) <> '') AS clients_vendor,
                (SELECT COUNT(*) FROM biomie_clientes_dim
                  WHERE company_id = {$id}
                    AND TRIM(COALESCE(cidade_normalizada, cidade, '')) <> '') AS clients_city,
                (SELECT COUNT(*) FROM biomie_clientes_dim
                  WHERE company_id = {$id}
                    AND TRIM(COALESCE(bairro_normalizado, bairro, '')) <> ''
                    AND LOWER(TRIM(COALESCE(bairro_normalizado, bairro, '')))
                        NOT IN ('nao informado','não informado','nao_informado','sem bairro','sem_bairro')) AS clients_neighborhood,
                (SELECT COUNT(*) FROM biomie_clientes_dim
                  WHERE company_id = {$id}
                    AND tipo_pessoa IN ('pj','pj_sem_cnae','governo','associacao')) AS clients_cnae_eligible,
                (SELECT COUNT(*) FROM biomie_clientes_dim
                  WHERE company_id = {$id}
                    AND tipo_pessoa IN ('pj','pj_sem_cnae','governo','associacao')
                    AND TRIM(COALESCE(cnae_codigo, '')) <> ''
                    AND LOWER(TRIM(cnae_codigo)) NOT IN ('0','00','000','0000','sem cnae','nao informado')) AS clients_cnae,
                (SELECT COUNT(*) FROM biomie_clientes_dim d
                  WHERE d.company_id = {$id}
                    AND EXISTS (
                        SELECT 1 FROM biomie_clientes_geo_dim g
                         WHERE g.company_id = d.company_id
                           AND g.codigo_cliente_omie = d.codigo_cliente_omie
                    )) AS clients_geo_dimension,

                (SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas n
                  WHERE n.company_id = {$id}
                    AND n.status_fiscal = 'autorizada'
                    AND n.operation_scope = 'commercial'
                    AND COALESCE(n.is_biomie_commercial_visible, 1) = 1) AS invoices_total,
                (SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas n
                  WHERE n.company_id = {$id}
                    AND n.status_fiscal = 'autorizada'
                    AND n.operation_scope = 'commercial'
                    AND COALESCE(n.is_biomie_commercial_visible, 1) = 1
                    AND TRIM(COALESCE(n.documento_cliente, '')) <> '') AS invoices_document,
                (SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas n
                  WHERE n.company_id = {$id}
                    AND n.status_fiscal = 'autorizada'
                    AND n.operation_scope = 'commercial'
                    AND COALESCE(n.is_biomie_commercial_visible, 1) = 1
                    AND n.codigo_cliente_omie IS NOT NULL AND n.codigo_cliente_omie > 0) AS invoices_client,
                (SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas n
                  WHERE n.company_id = {$id}
                    AND n.status_fiscal = 'autorizada'
                    AND n.operation_scope = 'commercial'
                    AND COALESCE(n.is_biomie_commercial_visible, 1) = 1
                    AND TRIM(COALESCE(n.codigo_vendedor_omie, '')) <> '') AS invoices_vendor,
                (SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas n
                  WHERE n.company_id = {$id}
                    AND n.status_fiscal = 'autorizada'
                    AND n.operation_scope = 'commercial'
                    AND COALESCE(n.is_biomie_commercial_visible, 1) = 1
                    AND EXISTS (
                        SELECT 1 FROM biomie_notas_fiscais_itens_canonicos i
                         WHERE i.company_id = n.company_id AND i.nota_key = n.nota_key
                    )) AS invoices_items,

                (SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos t
                  WHERE t.company_id = {$id} AND t.valor_aberto > 0.005) AS open_titles_total,
                (SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos t
                  WHERE t.company_id = {$id} AND t.valor_aberto > 0.005
                    AND t.codigo_cliente_omie IS NOT NULL AND t.codigo_cliente_omie > 0) AS open_titles_client,
                (SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos t
                  WHERE t.company_id = {$id} AND t.valor_aberto > 0.005
                    AND TRIM(COALESCE(t.codigo_vendedor_omie, '')) <> '') AS open_titles_vendor,
                (SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos t
                  WHERE t.company_id = {$id} AND t.valor_aberto > 0.005
                    AND (
                        TRIM(COALESCE(t.nota_key, '')) <> ''
                        OR TRIM(COALESCE(t.chave_nfe, '')) <> ''
                        OR t.pedido_id_omie IS NOT NULL
                        OR TRIM(COALESCE(t.documento_origem_ref, '')) <> ''
                    )) AS open_titles_trace,
                (SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos t
                  WHERE t.company_id = {$id} AND t.valor_aberto > 0.005
                    AND TRIM(COALESCE(t.forma_pagamento, '')) <> ''
                    AND LOWER(TRIM(t.forma_pagamento))
                        NOT IN ('nao informado','não informado','desconhecido')) AS open_titles_payment_method,

                (SELECT COUNT(*)
                   FROM biomie_notas_fiscais_itens_canonicos i
                   JOIN biomie_notas_fiscais_canonicas n
                     ON n.company_id = i.company_id AND n.nota_key = i.nota_key
                  WHERE n.company_id = {$id}
                    AND n.status_fiscal = 'autorizada'
                    AND n.operation_scope = 'commercial'
                    AND COALESCE(n.is_biomie_commercial_visible, 1) = 1) AS product_items_total,
                (SELECT COUNT(*)
                   FROM biomie_notas_fiscais_itens_canonicos i
                   JOIN biomie_notas_fiscais_canonicas n
                     ON n.company_id = i.company_id AND n.nota_key = i.nota_key
                  WHERE n.company_id = {$id}
                    AND n.status_fiscal = 'autorizada'
                    AND n.operation_scope = 'commercial'
                    AND COALESCE(n.is_biomie_commercial_visible, 1) = 1
                    AND TRIM(COALESCE(i.codigo_produto, '')) <> '') AS product_items_sku,
                (SELECT COUNT(*)
                   FROM biomie_notas_fiscais_itens_canonicos i
                   JOIN biomie_notas_fiscais_canonicas n
                     ON n.company_id = i.company_id AND n.nota_key = i.nota_key
                  WHERE n.company_id = {$id}
                    AND n.status_fiscal = 'autorizada'
                    AND n.operation_scope = 'commercial'
                    AND COALESCE(n.is_biomie_commercial_visible, 1) = 1
                    AND TRIM(COALESCE(i.descricao_produto, '')) <> '') AS product_items_description,
                (SELECT COUNT(DISTINCT TRIM(i.codigo_produto))
                   FROM biomie_notas_fiscais_itens_canonicos i
                   JOIN biomie_notas_fiscais_canonicas n
                     ON n.company_id = i.company_id AND n.nota_key = i.nota_key
                  WHERE n.company_id = {$id}
                    AND n.status_fiscal = 'autorizada'
                    AND n.operation_scope = 'commercial'
                    AND COALESCE(n.is_biomie_commercial_visible, 1) = 1
                    AND TRIM(COALESCE(i.codigo_produto, '')) <> '') AS sold_products_total,
                (SELECT COUNT(DISTINCT TRIM(i.codigo_produto))
                   FROM biomie_notas_fiscais_itens_canonicos i
                   JOIN biomie_notas_fiscais_canonicas n
                     ON n.company_id = i.company_id AND n.nota_key = i.nota_key
                  WHERE n.company_id = {$id}
                    AND n.status_fiscal = 'autorizada'
                    AND n.operation_scope = 'commercial'
                    AND COALESCE(n.is_biomie_commercial_visible, 1) = 1
                    AND TRIM(COALESCE(i.codigo_produto, '')) <> ''
                    AND EXISTS (
                        SELECT 1 FROM biomie_produtos_estoque_saldos s
                         WHERE s.company_id = i.company_id
                           AND TRIM(s.codigo_produto) = TRIM(i.codigo_produto)
                    )) AS sold_products_with_stock,
                (SELECT COUNT(*) FROM biomie_produtos_estoque_saldos
                  WHERE company_id = {$id}) AS stock_balance_rows
        SQL)->fetch(PDO::FETCH_ASSOC) ?: [];

        $stockCoverage = (int) ($row['stock_balance_rows'] ?? 0) > 0
            ? $this->coverageMetric('Produtos vendidos com saldo oficial', $row, 'sold_products_total', 'sold_products_with_stock', 80, 50)
            : [
                'label' => 'Produtos vendidos com saldo oficial',
                'status' => 'no_data',
                'total' => 0,
                'complete' => 0,
                'missing' => 0,
                'coverage_pct' => null,
                'ok_at_pct' => 80.0,
                'warning_at_pct' => 50.0,
                'reason' => 'Estoque oficial ainda não sincronizado para a empresa.',
            ];

        $groups = [
            'customers' => [
                'document' => $this->coverageMetric('Clientes com documento', $row, 'clients_total', 'clients_document', 90, 70),
                'responsible_vendor' => $this->coverageMetric('Clientes com vendedor responsável', $row, 'clients_total', 'clients_vendor', 90, 70),
                'city' => $this->coverageMetric('Clientes com cidade', $row, 'clients_total', 'clients_city', 80, 50),
                'neighborhood' => $this->coverageMetric('Clientes com bairro', $row, 'clients_total', 'clients_neighborhood', 70, 40),
                'geo_dimension' => $this->coverageMetric('Clientes presentes na dimensão geográfica', $row, 'clients_total', 'clients_geo_dimension', 95, 80),
                'cnae' => $this->coverageMetric('Pessoas jurídicas com CNAE', $row, 'clients_cnae_eligible', 'clients_cnae', 80, 50),
            ],
            'invoices' => [
                'document' => $this->coverageMetric('NFs comerciais com documento', $row, 'invoices_total', 'invoices_document', 95, 80),
                'client' => $this->coverageMetric('NFs comerciais com cliente', $row, 'invoices_total', 'invoices_client', 99, 95),
                'vendor' => $this->coverageMetric('NFs comerciais com vendedor', $row, 'invoices_total', 'invoices_vendor', 95, 80),
                'items' => $this->coverageMetric('NFs comerciais com itens', $row, 'invoices_total', 'invoices_items', 99, 95),
            ],
            'finance' => [
                'client' => $this->coverageMetric('Títulos abertos com cliente', $row, 'open_titles_total', 'open_titles_client', 95, 80),
                'vendor' => $this->coverageMetric('Títulos abertos com vendedor', $row, 'open_titles_total', 'open_titles_vendor', 80, 50),
                'trace' => $this->coverageMetric('Títulos abertos rastreáveis a NF/pedido', $row, 'open_titles_total', 'open_titles_trace', 70, 40),
                'payment_method' => $this->coverageMetric('Títulos abertos com forma de pagamento', $row, 'open_titles_total', 'open_titles_payment_method', 70, 40),
            ],
            'products' => [
                'sku' => $this->coverageMetric('Itens faturados com SKU', $row, 'product_items_total', 'product_items_sku', 99, 95),
                'description' => $this->coverageMetric('Itens faturados com descrição', $row, 'product_items_total', 'product_items_description', 95, 80),
                'stock_coverage' => $stockCoverage,
            ],
        ];

        $statuses = [];
        $weightedTotal = 0;
        $weightedComplete = 0;
        foreach ($groups as $metrics) {
            foreach ($metrics as $metric) {
                $statuses[] = (string) $metric['status'];
                $weightedTotal += (int) $metric['total'];
                $weightedComplete += (int) $metric['complete'];
            }
        }

        return [
            'status' => $this->worstStatus($statuses),
            'field_coverage_pct' => $weightedTotal > 0
                ? round(($weightedComplete / $weightedTotal) * 100, 2)
                : null,
            'groups' => $groups,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function coverageMetric(
        string $label,
        array $row,
        string $totalKey,
        string $completeKey,
        float $okAtPct,
        float $warningAtPct,
    ): array {
        $total = (int) ($row[$totalKey] ?? 0);
        $complete = min($total, (int) ($row[$completeKey] ?? 0));
        if ($total <= 0) {
            return [
                'label' => $label,
                'status' => 'no_data',
                'total' => 0,
                'complete' => 0,
                'missing' => 0,
                'coverage_pct' => null,
                'ok_at_pct' => $okAtPct,
                'warning_at_pct' => $warningAtPct,
            ];
        }

        $coverage = round(($complete / $total) * 100, 2);
        $status = match (true) {
            $coverage >= $okAtPct => 'ok',
            $coverage >= $warningAtPct => 'warning',
            default => 'critical',
        };

        return [
            'label' => $label,
            'status' => $status,
            'total' => $total,
            'complete' => $complete,
            'missing' => max(0, $total - $complete),
            'coverage_pct' => $coverage,
            'ok_at_pct' => $okAtPct,
            'warning_at_pct' => $warningAtPct,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function freshnessMetric(
        string $label,
        ?string $lastAt,
        int $rows,
        int $okHours,
        int $warningUntilHours,
        string $databaseNow,
        string $source,
        bool $dataExpected = false,
    ): array {
        if ($lastAt === null) {
            return [
                'label' => $label,
                'status' => ($rows > 0 || $dataExpected) ? 'critical' : 'no_data',
                'last_success_at' => null,
                'lag_minutes' => null,
                'rows' => $rows,
                'source' => $source,
                'ok_hours' => $okHours,
                'warning_until_hours' => $warningUntilHours,
            ];
        }

        $lastTs = strtotime($lastAt);
        $nowTs = strtotime($databaseNow);
        $lagMinutes = ($lastTs === false || $nowTs === false)
            ? null
            : max(0, (int) floor(($nowTs - $lastTs) / 60));
        $hours = $lagMinutes !== null ? $lagMinutes / 60 : null;
        $status = match (true) {
            $hours === null => 'critical',
            $hours <= $okHours => 'ok',
            $hours <= $warningUntilHours => 'warning',
            default => 'critical',
        };

        return [
            'label' => $label,
            'status' => $status,
            'last_success_at' => $lastAt,
            'lag_minutes' => $lagMinutes,
            'rows' => $rows,
            'source' => $source,
            'ok_hours' => $okHours,
            'warning_until_hours' => $warningUntilHours,
        ];
    }

    /**
     * @param list<string> $statuses
     */
    private function worstStatus(array $statuses): string
    {
        if ($statuses !== [] && count(array_filter(
            $statuses,
            static fn (string $status): bool => $status !== 'no_data',
        )) === 0) {
            return 'no_data';
        }

        $worst = 'ok';
        foreach ($statuses as $status) {
            if ((self::STATUS_RANK[$status] ?? 2) > (self::STATUS_RANK[$worst] ?? 0)) {
                $worst = $status;
            }
        }
        return $worst;
    }

    /**
     * @param list<mixed> $values
     */
    private function freshest(array $values): ?string
    {
        $latest = null;
        $latestTs = false;
        foreach ($values as $value) {
            $normalized = $this->nullableString($value);
            $timestamp = $normalized !== null ? strtotime($normalized) : false;
            if ($timestamp !== false && ($latestTs === false || $timestamp > $latestTs)) {
                $latest = $normalized;
                $latestTs = $timestamp;
            }
        }
        return $latest;
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);
        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @param array<string,mixed> $freshness
     * @param array<string,mixed> $completeness
     * @return list<array{priority:string,domain:string,reason:string,command:string|null}>
     */
    private function recommendedActions(string $slug, array $freshness, array $completeness): array
    {
        $actions = [];
        $domains = is_array($freshness['domains'] ?? null) ? $freshness['domains'] : [];
        foreach (['commercial', 'finance'] as $domain) {
            $status = (string) ($domains[$domain]['status'] ?? 'no_data');
            if (in_array($status, ['warning', 'critical'], true)) {
                $actions[] = [
                    'priority' => $status === 'critical' ? 'high' : 'medium',
                    'domain' => $domain,
                    'reason' => "Frescor {$domain} em {$status}.",
                    'command' => "php cron/sync_realtime_pipeline.php --company={$slug} --domains={$domain}",
                ];
            }
        }
        if (in_array((string) ($domains['gold']['status'] ?? ''), ['warning', 'critical'], true)) {
            $actions[] = [
                'priority' => 'high',
                'domain' => 'gold',
                'reason' => 'Uma ou mais materializações estão ausentes ou fora do SLA.',
                'command' => "php bin/materialize_marts.php --company={$slug} --months=2",
            ];
        }
        if (in_array((string) ($domains['customers']['status'] ?? ''), ['warning', 'critical'], true)) {
            $actions[] = [
                'priority' => 'medium',
                'domain' => 'customers',
                'reason' => 'Dimensão de clientes fora do SLA.',
                'command' => "php bin/materialize_client_dim.php --company={$slug}",
            ];
        }

        $groups = is_array($completeness['groups'] ?? null) ? $completeness['groups'] : [];
        $cnae = (string) ($groups['customers']['cnae']['status'] ?? 'no_data');
        if (in_array($cnae, ['warning', 'critical'], true)) {
            $actions[] = [
                'priority' => $cnae === 'critical' ? 'high' : 'medium',
                'domain' => 'cnae',
                'reason' => 'Cobertura de CNAE abaixo do limite esperado.',
                'command' => "php bin/cnae_enrich_clients.php --company={$slug} --apply --limit=50 --max-batches=200",
            ];
        }
        $geo = (string) ($groups['customers']['geo_dimension']['status'] ?? 'no_data');
        if (in_array($geo, ['warning', 'critical'], true)) {
            $actions[] = [
                'priority' => 'high',
                'domain' => 'geo',
                'reason' => 'Clientes sem dimensão geográfica correspondente.',
                'command' => "php bin/materialize_client_dim.php --company={$slug}",
            ];
        }
        $financeVendor = (string) ($groups['finance']['vendor']['status'] ?? 'no_data');
        if (in_array($financeVendor, ['warning', 'critical'], true)) {
            $actions[] = [
                'priority' => 'medium',
                'domain' => 'finance',
                'reason' => 'Títulos abertos sem vendedor responsável.',
                'command' => "php bin/enrich_finance_titulos.php --company={$slug}",
            ];
        }
        $invoiceItems = (string) ($groups['invoices']['items']['status'] ?? 'no_data');
        if (in_array($invoiceItems, ['warning', 'critical'], true)) {
            $actions[] = [
                'priority' => 'high',
                'domain' => 'fiscal',
                'reason' => 'Existem NFs comerciais sem itens canônicos.',
                'command' => "php cron/sync_realtime_pipeline.php --company={$slug} --domains=commercial --reconcile=30",
            ];
        }
        $stock = (string) ($groups['products']['stock_coverage']['status'] ?? 'no_data');
        if (in_array($stock, ['warning', 'critical'], true)) {
            $actions[] = [
                'priority' => 'medium',
                'domain' => 'stock',
                'reason' => 'Produtos faturados sem saldo oficial de estoque.',
                'command' => null,
            ];
        }

        $unique = [];
        foreach ($actions as $action) {
            $key = $action['domain'] . '|' . $action['reason'] . '|' . ($action['command'] ?? '');
            $unique[$key] = $action;
        }
        return array_values($unique);
    }
}

<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Semantic;

/**
 * Central registry for all semantic BI objects: entities, dimensions, measures, and KPIs.
 *
 * Call MetricRegistry::build() to get the canonical registry pre-loaded with all
 * official KPIs. Each KPI marked active has a verified source, table, and date.
 * KPIs with unresolved rules are marked draft — do not promote without review.
 */
final class MetricRegistry
{
    /** @var array<string,KpiDefinition> */
    private array $kpis = [];
    /** @var array<string,MetricEntity> */
    private array $entities = [];
    /** @var array<string,MetricDimension> */
    private array $dimensions = [];
    /** @var array<string,MetricMeasure> */
    private array $measures = [];

    // ── Registration ─────────────────────────────────────────────────────────

    public function registerKpi(KpiDefinition $kpi): void
    {
        if (isset($this->kpis[$kpi->id])) {
            throw new \LogicException("Duplicate KPI id: {$kpi->id}");
        }
        $this->kpis[$kpi->id] = $kpi;
    }

    public function registerEntity(MetricEntity $entity): void
    {
        $this->entities[$entity->id] = $entity;
    }

    public function registerDimension(MetricDimension $dim): void
    {
        $this->dimensions[$dim->id] = $dim;
    }

    public function registerMeasure(MetricMeasure $measure): void
    {
        $this->measures[$measure->id] = $measure;
    }

    // ── KPI queries ──────────────────────────────────────────────────────────

    /** @return list<KpiDefinition> */
    public function all(): array { return array_values($this->kpis); }

    public function get(string $id): KpiDefinition
    {
        if (!isset($this->kpis[$id])) {
            throw new \OutOfBoundsException("KPI '{$id}' not found in registry.");
        }
        return $this->kpis[$id];
    }

    public function has(string $id): bool { return isset($this->kpis[$id]); }

    /** @return list<KpiDefinition> */
    public function byDomain(string $domain): array
    {
        return array_values(array_filter($this->kpis, fn (KpiDefinition $k): bool => $k->domain === $domain));
    }

    /** @return list<KpiDefinition> */
    public function active(): array
    {
        return array_values(array_filter($this->kpis, fn (KpiDefinition $k): bool => $k->isActive()));
    }

    /** @return list<KpiDefinition> */
    public function draft(): array
    {
        return array_values(array_filter($this->kpis, fn (KpiDefinition $k): bool => $k->isDraft()));
    }

    /** @return list<KpiDefinition> */
    public function deprecated(): array
    {
        return array_values(array_filter($this->kpis, fn (KpiDefinition $k): bool => $k->isDeprecated()));
    }

    /** @return array<string,MetricEntity> */
    public function allEntities(): array { return $this->entities; }
    /** @return array<string,MetricDimension> */
    public function allDimensions(): array { return $this->dimensions; }
    /** @return array<string,MetricMeasure> */
    public function allMeasures(): array { return $this->measures; }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'kpis'       => array_map(fn (KpiDefinition $k): array => $k->toArray(), $this->kpis),
            'entities'   => array_map(fn (MetricEntity $e): array => $e->toArray(), $this->entities),
            'dimensions' => array_map(fn (MetricDimension $d): array => $d->toArray(), $this->dimensions),
            'measures'   => array_map(fn (MetricMeasure $m): array => $m->toArray(), $this->measures),
        ];
    }

    // ── Factory ───────────────────────────────────────────────────────────────

    public static function build(): self
    {
        $r = new self();
        self::registerEntities($r);
        self::registerDimensions($r);
        self::registerMeasures($r);
        self::registerSalesKpis($r);
        self::registerFiscalKpis($r);
        self::registerFinanceKpis($r);
        self::registerCustomerKpis($r);
        self::registerGeoKpis($r);
        self::registerCnaeKpis($r);
        self::registerOperationsKpis($r);
        return $r;
    }

    // ── Entities ──────────────────────────────────────────────────────────────

    private static function registerEntities(self $r): void
    {
        $defs = [
            ['id' => 'company',           'name' => 'Empresa',              'description' => 'Empresa emissora (MAG Moveis Corporativos)',    'primary_key' => 'empresa_id',           'source_table' => 'omie_config'],
            ['id' => 'order',             'name' => 'Pedido',               'description' => 'Pedido comercial sincronizado do Omie',         'primary_key' => 'pedido_id_omie',       'source_table' => 'biomie_pedidos_canonicos'],
            ['id' => 'invoice',           'name' => 'Nota Fiscal',          'description' => 'NF-e emitida e sincronizada do Omie',           'primary_key' => 'nota_key',             'source_table' => 'mart_fiscal_nfe'],
            ['id' => 'financial_title',   'name' => 'Titulo Financeiro',    'description' => 'Titulo a receber/pagar sincronizado do Omie',   'primary_key' => 'titulo_key',           'source_table' => 'biomie_titulos_financeiros_canonicos'],
            ['id' => 'customer',          'name' => 'Cliente',              'description' => 'Cliente cadastrado no Omie',                   'primary_key' => 'codigo_cliente_omie',  'source_table' => 'biomie_clientes_dim'],
            ['id' => 'vendor',            'name' => 'Vendedor',             'description' => 'Vendedor responsavel pelo pedido/NF',          'primary_key' => 'codigo_vendedor_omie', 'source_table' => 'mart_vendedor_dia'],
            ['id' => 'product',           'name' => 'Produto',              'description' => 'Produto ou item de pedido/NF',                 'primary_key' => 'produto_id_omie',      'source_table' => 'mart_produto_periodo'],
            ['id' => 'neighborhood',      'name' => 'Bairro',               'description' => 'Bairro oficial de Fortaleza',                  'primary_key' => 'geojson_feature_id',   'source_table' => 'biomie_bairros_fortaleza_dim'],
            ['id' => 'city',              'name' => 'Cidade',               'description' => 'Cidade do cliente',                            'primary_key' => 'cidade_norm',          'source_table' => 'biomie_clientes_geo_dim'],
            ['id' => 'state',             'name' => 'UF',                   'description' => 'Estado (UF) do cliente',                       'primary_key' => 'uf',                   'source_table' => 'biomie_clientes_geo_dim'],
            ['id' => 'cnae',              'name' => 'CNAE',                 'description' => 'Classificacao CNAE do cliente',                'primary_key' => 'cnae_codigo',          'source_table' => 'biomie_clientes_dim'],
            ['id' => 'job',               'name' => 'Job Pipeline',         'description' => 'Job de materialization/sync registrado',       'primary_key' => 'id',                   'source_table' => 'biomie_pipeline_jobs'],
            ['id' => 'webhook',           'name' => 'Webhook Omie',         'description' => 'Evento recebido via webhook do Omie',          'primary_key' => 'id',                   'source_table' => 'biomie_omie_webhooks'],
            ['id' => 'shadow_validation', 'name' => 'Shadow Validation',    'description' => 'Registro de divergencia shadow legacy/canonical','primary_key' => 'id',                  'source_table' => 'biomie_shadow_validations'],
        ];
        foreach ($defs as $d) {
            $r->registerEntity(MetricEntity::fromArray($d));
        }
    }

    // ── Dimensions ────────────────────────────────────────────────────────────

    private static function registerDimensions(self $r): void
    {
        $all = ['sales','fiscal','finance','customers','geo','cnae','operations','omie','shadow','admin'];
        $defs = [
            ['id' => 'company_id',      'name' => 'Empresa ID',         'type' => 'integer', 'source_column' => 'empresa_id',           'allowed_domains' => $all],
            ['id' => 'period',          'name' => 'Competência',        'type' => 'period',  'source_column' => 'competencia',          'allowed_domains' => $all],
            ['id' => 'date',            'name' => 'Data',               'type' => 'date',    'source_column' => 'data_referencia',      'allowed_domains' => $all],
            ['id' => 'vendor_id',       'name' => 'Vendedor ID',        'type' => 'integer', 'source_column' => 'codigo_vendedor_omie', 'allowed_domains' => ['sales','fiscal']],
            ['id' => 'vendor_name',     'name' => 'Vendedor Nome',      'type' => 'string',  'source_column' => 'nome_vendedor',        'allowed_domains' => ['sales','fiscal']],
            ['id' => 'customer_id',     'name' => 'Cliente ID',         'type' => 'integer', 'source_column' => 'codigo_cliente_omie',  'allowed_domains' => ['sales','fiscal','finance','customers','geo','cnae']],
            ['id' => 'customer_name',   'name' => 'Cliente Nome',       'type' => 'string',  'source_column' => 'nome_cliente',         'allowed_domains' => ['sales','fiscal','finance','customers','geo']],
            ['id' => 'product_id',      'name' => 'Produto ID',         'type' => 'integer', 'source_column' => 'produto_id_omie',      'allowed_domains' => ['sales','fiscal']],
            ['id' => 'product_name',    'name' => 'Produto Nome',       'type' => 'string',  'source_column' => 'nome_produto',         'allowed_domains' => ['sales','fiscal']],
            ['id' => 'neighborhood',    'name' => 'Bairro',             'type' => 'string',  'source_column' => 'bairro_norm',          'allowed_domains' => ['geo','cnae','customers']],
            ['id' => 'city',            'name' => 'Cidade',             'type' => 'string',  'source_column' => 'cidade_norm',          'allowed_domains' => ['geo','cnae','customers','sales']],
            ['id' => 'uf',              'name' => 'UF',                 'type' => 'string',  'source_column' => 'uf',                   'allowed_domains' => ['geo','cnae','customers','sales']],
            ['id' => 'cnae',            'name' => 'CNAE',               'type' => 'string',  'source_column' => 'cnae_codigo',          'allowed_domains' => ['cnae','customers']],
            ['id' => 'payment_status',  'name' => 'Status Pagamento',   'type' => 'enum',    'source_column' => 'status_pagamento',     'allowed_domains' => ['finance']],
            ['id' => 'invoice_status',  'name' => 'Status Fiscal',      'type' => 'enum',    'source_column' => 'status_fiscal',        'allowed_domains' => ['fiscal','sales']],
            ['id' => 'order_status',    'name' => 'Status Pedido',      'type' => 'enum',    'source_column' => 'status_comercial',     'allowed_domains' => ['sales']],
            ['id' => 'job_status',      'name' => 'Status Job',         'type' => 'enum',    'source_column' => 'status',               'allowed_domains' => ['operations']],
        ];
        foreach ($defs as $d) {
            $r->registerDimension(MetricDimension::fromArray(array_merge(['description' => ''], $d)));
        }
    }

    // ── Measures ──────────────────────────────────────────────────────────────

    private static function registerMeasures(self $r): void
    {
        $defs = [
            ['id' => 'sum_order_value',      'name' => 'Soma Valor Pedido',       'aggregation' => 'sum',            'source_column' => 'valor_total',     'source_table' => 'biomie_pedidos_canonicos',          'filter_rule' => "status_comercial = 'faturado'"],
            ['id' => 'sum_invoice_value',    'name' => 'Soma Valor NF',           'aggregation' => 'sum',            'source_column' => 'valor_total',     'source_table' => 'mart_fiscal_nfe',                   'filter_rule' => "status_fiscal = 'autorizada'"],
            ['id' => 'sum_financial_paid',   'name' => 'Soma Recebido',           'aggregation' => 'sum',            'source_column' => 'valor_recebido',  'source_table' => 'mart_cliente_periodo',              'filter_rule' => ''],
            ['id' => 'sum_financial_open',   'name' => 'Soma Em Aberto',          'aggregation' => 'sum',            'source_column' => 'valor_aberto',    'source_table' => 'mart_cliente_periodo',              'filter_rule' => ''],
            ['id' => 'count_orders',         'name' => 'Qtd Pedidos',             'aggregation' => 'count',          'source_column' => 'pedido_id_omie',  'source_table' => 'biomie_pedidos_canonicos',          'filter_rule' => ''],
            ['id' => 'count_invoices',       'name' => 'Qtd NFs',                 'aggregation' => 'count',          'source_column' => 'nota_key',        'source_table' => 'mart_fiscal_nfe',                   'filter_rule' => "status_fiscal = 'autorizada'"],
            ['id' => 'count_customers',      'name' => 'Qtd Clientes',            'aggregation' => 'count_distinct', 'source_column' => 'codigo_cliente_omie', 'source_table' => 'mart_cliente_periodo',          'filter_rule' => ''],
            ['id' => 'count_titles',         'name' => 'Qtd Titulos',             'aggregation' => 'count',          'source_column' => 'titulo_key',      'source_table' => 'biomie_titulos_financeiros_canonicos','filter_rule' => ''],
            ['id' => 'count_jobs',           'name' => 'Qtd Jobs',                'aggregation' => 'count',          'source_column' => 'id',              'source_table' => 'biomie_pipeline_jobs',              'filter_rule' => ''],
            ['id' => 'count_webhooks',       'name' => 'Qtd Webhooks',            'aggregation' => 'count',          'source_column' => 'id',              'source_table' => 'biomie_omie_webhooks',              'filter_rule' => ''],
            ['id' => 'avg_ticket',           'name' => 'Ticket Medio',            'aggregation' => 'avg',            'source_column' => 'valor_total',     'source_table' => 'mart_fiscal_nfe',                   'filter_rule' => "status_fiscal = 'autorizada'"],
        ];
        foreach ($defs as $d) {
            $r->registerMeasure(MetricMeasure::fromArray(array_merge(['description' => ''], $d)));
        }
    }

    // ── KPI Registrations ─────────────────────────────────────────────────────

    private static function kpi(array $data): KpiDefinition
    {
        return KpiDefinition::fromArray(array_merge([
            'description' => '', 'entities' => [], 'dimensions' => [], 'measures' => [],
            'include_rule' => '', 'exclude_rule' => '', 'cancel_rule' => '',
            'grain' => 'month', 'owner' => 'bi', 'notes' => '',
        ], $data));
    }

    private static function registerSalesKpis(self $r): void
    {
        $r->registerKpi(self::kpi([
            'id' => 'vendas_comerciais', 'name' => 'Vendas Comerciais', 'domain' => 'sales',
            'description' => 'Valor total de NFs autorizadas por periodo (dominio comercial).',
            'source_of_truth' => 'gold', 'source_table' => 'mart_fiscal_nfe', 'value_column' => 'valor_total',
            'official_date' => 'COALESCE(data_competencia, data_emissao)', 'status' => 'active',
            'include_rule' => "status_fiscal = 'autorizada'",
            'exclude_rule' => "status_fiscal IN ('cancelada', 'denegada', 'inutilizada')",
            'permission' => 'sales.view', 'freshness_domain' => 'sales',
            'entities' => ['invoice', 'customer', 'vendor'],
            'dimensions' => ['period', 'vendor_id', 'customer_id', 'invoice_status'],
            'measures' => ['sum_invoice_value'],
        ]));

        $r->registerKpi(self::kpi([
            'id' => 'pedidos_faturados', 'name' => 'Pedidos Faturados', 'domain' => 'sales',
            'description' => 'Contagem de pedidos com status_comercial faturado no periodo.',
            'source_of_truth' => 'gold', 'source_table' => 'mart_cliente_periodo', 'value_column' => 'pedidos_faturados',
            'official_date' => 'COALESCE(data_faturamento, data_emissao)', 'status' => 'active',
            'include_rule' => "status_comercial = 'faturado'",
            'permission' => 'sales.view', 'freshness_domain' => 'sales',
            'entities' => ['order', 'customer'], 'dimensions' => ['period', 'customer_id'],
            'measures' => ['count_orders'],
        ]));

        $r->registerKpi(self::kpi([
            'id' => 'pedidos_em_aberto', 'name' => 'Pedidos em Aberto', 'domain' => 'sales',
            'description' => 'Pedidos nao faturados e nao cancelados. Regra de status ainda em revisao.',
            'source_of_truth' => 'canonical', 'source_table' => 'biomie_pedidos_canonicos', 'value_column' => 'valor_total',
            'official_date' => 'data_emissao', 'status' => 'draft',
            'notes' => 'Regra de quais status_comercial representam "aberto" nao foi formalizada. Usar com cautela.',
            'permission' => 'sales.view', 'freshness_domain' => 'sales',
            'entities' => ['order'], 'dimensions' => ['period', 'order_status'],
            'measures' => ['count_orders'],
        ]));

        $r->registerKpi(self::kpi([
            'id' => 'ranking_vendedor', 'name' => 'Ranking de Vendedores', 'domain' => 'sales',
            'description' => 'Faturamento acumulado por vendedor no periodo.',
            'source_of_truth' => 'gold', 'source_table' => 'mart_vendedor_dia', 'value_column' => 'valor_total',
            'official_date' => 'data_referencia', 'status' => 'active',
            'include_rule' => "status_fiscal = 'autorizada'",
            'permission' => 'sales.view', 'freshness_domain' => 'sales',
            'entities' => ['vendor', 'invoice'],
            'dimensions' => ['period', 'vendor_id', 'vendor_name'],
            'measures' => ['sum_invoice_value'],
        ]));

        $r->registerKpi(self::kpi([
            'id' => 'ticket_medio_pedido', 'name' => 'Ticket Medio por Pedido', 'domain' => 'sales',
            'description' => 'Valor medio por pedido faturado. Denominador (contagem de pedidos) precisa de definicao unica.',
            'source_of_truth' => 'gold', 'source_table' => 'mart_fiscal_nfe', 'value_column' => 'valor_total',
            'official_date' => 'COALESCE(data_competencia, data_emissao)', 'status' => 'draft',
            'notes' => 'Calcular avg(valor_total) ou sum(valor_total)/count(pedidos_faturados)? Pendente decisao.',
            'permission' => 'sales.view', 'freshness_domain' => 'sales',
            'measures' => ['avg_ticket'],
        ]));

        $r->registerKpi(self::kpi([
            'id' => 'top_clientes', 'name' => 'Top Clientes', 'domain' => 'sales',
            'description' => 'Ranking de clientes por faturamento NF no periodo.',
            'source_of_truth' => 'gold', 'source_table' => 'mart_cliente_periodo', 'value_column' => 'valor_nfe',
            'official_date' => 'competencia', 'status' => 'active',
            'include_rule' => 'nfes_emitidas > 0',
            'permission' => 'sales.view', 'freshness_domain' => 'sales',
            'entities' => ['customer', 'invoice'],
            'dimensions' => ['period', 'customer_id', 'customer_name'],
            'measures' => ['sum_invoice_value', 'count_invoices'],
        ]));

        $r->registerKpi(self::kpi([
            'id' => 'top_produtos', 'name' => 'Top Produtos', 'domain' => 'sales',
            'description' => 'Ranking de produtos por faturamento no periodo.',
            'source_of_truth' => 'gold', 'source_table' => 'mart_produto_periodo', 'value_column' => 'valor_total',
            'official_date' => 'competencia', 'status' => 'active',
            'permission' => 'sales.view', 'freshness_domain' => 'sales',
            'entities' => ['product'],
            'dimensions' => ['period', 'product_id', 'product_name'],
            'measures' => ['sum_invoice_value'],
        ]));
    }

    private static function registerFiscalKpis(self $r): void
    {
        $r->registerKpi(self::kpi([
            'id' => 'receita_fiscal', 'name' => 'Receita Fiscal', 'domain' => 'fiscal',
            'description' => 'Valor total de NFs autorizadas, excluindo canceladas, denegadas e devolvidas.',
            'source_of_truth' => 'gold', 'source_table' => 'mart_fiscal_nfe', 'value_column' => 'valor_total',
            'official_date' => 'COALESCE(data_competencia, data_emissao)', 'status' => 'active',
            'include_rule' => "status_fiscal = 'autorizada'",
            'exclude_rule' => "status_fiscal IN ('cancelada', 'denegada', 'inutilizada')",
            'cancel_rule'  => "status_fiscal IN ('cancelada', 'denegada')",
            'permission' => 'fiscal.view', 'freshness_domain' => 'fiscal',
            'entities' => ['invoice'], 'dimensions' => ['period', 'invoice_status'],
            'measures' => ['sum_invoice_value'],
        ]));

        $r->registerKpi(self::kpi([
            'id' => 'notas_autorizadas', 'name' => 'Notas Autorizadas', 'domain' => 'fiscal',
            'description' => 'Contagem de NFs com status autorizada no periodo.',
            'source_of_truth' => 'gold', 'source_table' => 'mart_fiscal_nfe', 'value_column' => 'nota_key',
            'official_date' => 'COALESCE(data_competencia, data_emissao)', 'status' => 'active',
            'include_rule' => "status_fiscal = 'autorizada'",
            'permission' => 'fiscal.view', 'freshness_domain' => 'fiscal',
            'entities' => ['invoice'], 'dimensions' => ['period'],
            'measures' => ['count_invoices'],
        ]));

        $r->registerKpi(self::kpi([
            'id' => 'notas_canceladas', 'name' => 'Notas Canceladas', 'domain' => 'fiscal',
            'description' => 'Contagem de NFs canceladas ou denegadas no periodo.',
            'source_of_truth' => 'gold', 'source_table' => 'mart_fiscal_nfe', 'value_column' => 'nota_key',
            'official_date' => 'COALESCE(data_competencia, data_emissao)', 'status' => 'active',
            'include_rule' => "status_fiscal IN ('cancelada', 'denegada')",
            'permission' => 'fiscal.view', 'freshness_domain' => 'fiscal',
            'entities' => ['invoice'], 'dimensions' => ['period', 'invoice_status'],
            'measures' => ['count_invoices'],
        ]));

        $r->registerKpi(self::kpi([
            'id' => 'valor_cancelado', 'name' => 'Valor Cancelado', 'domain' => 'fiscal',
            'description' => 'Soma do valor_total de NFs canceladas ou denegadas no periodo.',
            'source_of_truth' => 'gold', 'source_table' => 'mart_fiscal_nfe', 'value_column' => 'valor_total',
            'official_date' => 'COALESCE(data_competencia, data_emissao)', 'status' => 'active',
            'include_rule' => "status_fiscal IN ('cancelada', 'denegada')",
            'permission' => 'fiscal.view', 'freshness_domain' => 'fiscal',
            'entities' => ['invoice'], 'dimensions' => ['period'],
            'measures' => ['sum_invoice_value'],
        ]));

        $r->registerKpi(self::kpi([
            'id' => 'devolucoes', 'name' => 'Devoluções', 'domain' => 'fiscal',
            'description' => 'Notas de devolucao emitidas. Regra de identificacao ainda nao formalizada.',
            'source_of_truth' => 'canonical', 'source_table' => 'biomie_notas_fiscais_canonicas', 'value_column' => 'valor_total',
            'official_date' => 'data_emissao', 'status' => 'draft',
            'notes' => 'Identificar NFs de devolucao requer criterio adicional (CFOP, tipo_nf). Pendente.',
            'permission' => 'fiscal.view', 'freshness_domain' => 'fiscal',
        ]));
    }

    private static function registerFinanceKpis(self $r): void
    {
        $r->registerKpi(self::kpi([
            'id' => 'receita_realizada', 'name' => 'Receita Realizada', 'domain' => 'finance',
            'description' => 'Valor recebido (data_liquidacao preenchida) no periodo.',
            'source_of_truth' => 'canonical', 'source_table' => 'biomie_titulos_financeiros_canonicos', 'value_column' => 'valor_recebido',
            'official_date' => 'data_liquidacao', 'status' => 'active',
            'include_rule' => 'data_liquidacao IS NOT NULL',
            'permission' => 'finance.view', 'freshness_domain' => 'finance',
            'entities' => ['financial_title'], 'dimensions' => ['period', 'customer_id'],
            'measures' => ['sum_financial_paid'],
        ]));

        $r->registerKpi(self::kpi([
            'id' => 'contas_a_receber', 'name' => 'Contas a Receber', 'domain' => 'finance',
            'description' => 'Valor em aberto de titulos nao liquidados.',
            'source_of_truth' => 'canonical', 'source_table' => 'biomie_titulos_financeiros_canonicos', 'value_column' => 'valor_aberto',
            'official_date' => 'data_vencimento', 'status' => 'active',
            'include_rule' => 'valor_aberto > 0 AND data_liquidacao IS NULL',
            'permission' => 'finance.view', 'freshness_domain' => 'finance',
            'entities' => ['financial_title', 'customer'], 'dimensions' => ['period', 'customer_id', 'payment_status'],
            'measures' => ['sum_financial_open'],
        ]));

        $r->registerKpi(self::kpi([
            'id' => 'inadimplencia_total', 'name' => 'Inadimplencia Total', 'domain' => 'finance',
            'description' => 'Valor em aberto de titulos com data_vencimento anterior a hoje.',
            'source_of_truth' => 'canonical', 'source_table' => 'biomie_titulos_financeiros_canonicos', 'value_column' => 'valor_aberto',
            'official_date' => 'data_vencimento', 'status' => 'active',
            'include_rule' => 'valor_aberto > 0 AND data_vencimento < CURDATE() AND data_liquidacao IS NULL',
            'permission' => 'finance.view', 'freshness_domain' => 'finance',
            'entities' => ['financial_title', 'customer'], 'dimensions' => ['date', 'customer_id', 'payment_status'],
            'measures' => ['sum_financial_open'],
        ]));

        $r->registerKpi(self::kpi([
            'id' => 'titulos_vencidos', 'name' => 'Titulos Vencidos', 'domain' => 'finance',
            'description' => 'Contagem de titulos vencidos e nao pagos.',
            'source_of_truth' => 'canonical', 'source_table' => 'biomie_titulos_financeiros_canonicos', 'value_column' => 'titulo_key',
            'official_date' => 'data_vencimento', 'status' => 'active',
            'include_rule' => 'data_vencimento < CURDATE() AND data_liquidacao IS NULL',
            'permission' => 'finance.view', 'freshness_domain' => 'finance',
            'entities' => ['financial_title'], 'dimensions' => ['date', 'customer_id'],
            'measures' => ['count_titles'],
        ]));

        $r->registerKpi(self::kpi([
            'id' => 'aging_recebiveis', 'name' => 'Aging de Recebíveis', 'domain' => 'finance',
            'description' => 'Distribuicao de titulos vencidos por bucket de atraso (0-30, 31-60, 61-90, >90 dias).',
            'source_of_truth' => 'canonical', 'source_table' => 'biomie_titulos_financeiros_canonicos', 'value_column' => 'valor_aberto',
            'official_date' => 'data_vencimento', 'status' => 'draft',
            'notes' => 'Buckets de aging nao formalizados. Pendente definicao de faixas e nome das colunas.',
            'permission' => 'finance.view', 'freshness_domain' => 'finance',
            'entities' => ['financial_title'], 'dimensions' => ['payment_status'],
        ]));
    }

    private static function registerCustomerKpis(self $r): void
    {
        $r->registerKpi(self::kpi([
            'id' => 'clientes_ativos', 'name' => 'Clientes Ativos', 'domain' => 'customers',
            'description' => 'Clientes com pelo menos uma compra nos ultimos 90 dias (RecurrencePolicy.DORMANT_DAYS).',
            'source_of_truth' => 'gold', 'source_table' => 'mart_cliente_periodo', 'value_column' => 'codigo_cliente_omie',
            'official_date' => 'competencia', 'status' => 'draft',
            'notes' => 'Definicao de ativo = compra nos ultimos 90 dias. Consolidar no registry quando fonte for confirmada.',
            'include_rule' => 'ultima_compra_em >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)',
            'permission' => 'sales.view', 'freshness_domain' => 'customers',
            'entities' => ['customer'], 'dimensions' => ['period', 'customer_id'],
            'measures' => ['count_customers'],
        ]));

        $r->registerKpi(self::kpi([
            'id' => 'clientes_recorrentes', 'name' => 'Clientes Recorrentes', 'domain' => 'customers',
            'description' => 'Clientes com 2+ competencias de compra (MIN_MONTHS_RECURRING=2).',
            'source_of_truth' => 'gold', 'source_table' => 'mart_cliente_periodo', 'value_column' => 'codigo_cliente_omie',
            'official_date' => 'competencia', 'status' => 'draft',
            'notes' => 'Definicao de recorrente nao consolidada em coluna unica. Requer agrupamento por cliente.',
            'permission' => 'sales.view', 'freshness_domain' => 'customers',
            'entities' => ['customer'], 'dimensions' => ['period', 'customer_id'],
            'measures' => ['count_customers'],
        ]));
    }

    private static function registerGeoKpis(self $r): void
    {
        $r->registerKpi(self::kpi([
            'id' => 'compradores_por_bairro', 'name' => 'Compradores por Bairro', 'domain' => 'geo',
            'description' => 'Faturamento e contagem de compradores agrupados por bairro oficial de Fortaleza.',
            'source_of_truth' => 'gold', 'source_table' => 'mart_compradores_por_geo', 'value_column' => 'faturamento',
            'official_date' => 'competencia', 'status' => 'active',
            'include_rule' => "bairro_norm != ''",
            'permission' => 'geo.view', 'freshness_domain' => 'geo',
            'entities' => ['customer', 'neighborhood'],
            'dimensions' => ['period', 'neighborhood', 'city', 'uf'],
            'measures' => ['sum_invoice_value', 'count_customers'],
        ]));

        $r->registerKpi(self::kpi([
            'id' => 'vendas_por_cidade', 'name' => 'Vendas por Cidade', 'domain' => 'geo',
            'description' => 'Faturamento agrupado por cidade_norm no periodo.',
            'source_of_truth' => 'gold', 'source_table' => 'biomie_geo_vendas_diaria', 'value_column' => 'valor_total',
            'official_date' => 'data_referencia', 'status' => 'active',
            'include_rule' => "cidade_norm != '' AND status_fiscal = 'autorizada'",
            'permission' => 'geo.view', 'freshness_domain' => 'geo',
            'entities' => ['city', 'invoice'],
            'dimensions' => ['period', 'city', 'uf', 'invoice_status'],
            'measures' => ['sum_invoice_value'],
        ]));

        $r->registerKpi(self::kpi([
            'id' => 'vendas_por_uf', 'name' => 'Vendas por UF', 'domain' => 'geo',
            'description' => 'Faturamento agrupado por UF no periodo.',
            'source_of_truth' => 'gold', 'source_table' => 'biomie_geo_vendas_diaria', 'value_column' => 'valor_total',
            'official_date' => 'data_referencia', 'status' => 'active',
            'include_rule' => "uf != '' AND status_fiscal = 'autorizada'",
            'permission' => 'geo.view', 'freshness_domain' => 'geo',
            'entities' => ['state', 'invoice'],
            'dimensions' => ['period', 'uf', 'invoice_status'],
            'measures' => ['sum_invoice_value'],
        ]));
    }

    private static function registerCnaeKpis(self $r): void
    {
        $r->registerKpi(self::kpi([
            'id' => 'vendas_por_cnae', 'name' => 'Vendas por CNAE', 'domain' => 'cnae',
            'description' => 'Faturamento agrupado por cnae_codigo do cliente no periodo.',
            'source_of_truth' => 'gold', 'source_table' => 'mart_vendas_por_cnae_cnpj', 'value_column' => 'faturamento',
            'official_date' => 'competencia', 'status' => 'active',
            'include_rule' => "cnae_codigo IS NOT NULL AND cnae_codigo != ''",
            'permission' => 'geo.view', 'freshness_domain' => 'cnae',
            'entities' => ['cnae', 'customer', 'invoice'],
            'dimensions' => ['period', 'cnae', 'city', 'uf'],
            'measures' => ['sum_invoice_value', 'count_customers'],
        ]));

        $r->registerKpi(self::kpi([
            'id' => 'ticket_medio_por_cnae', 'name' => 'Ticket Medio por CNAE', 'domain' => 'cnae',
            'description' => 'Valor medio de NF por CNAE. Formula ainda em revisao.',
            'source_of_truth' => 'gold', 'source_table' => 'mart_vendas_por_cnae_cnpj', 'value_column' => 'faturamento',
            'official_date' => 'competencia', 'status' => 'draft',
            'notes' => 'Ticket medio = faturamento / qtd_notas. Pendente definicao oficial de denominador.',
            'permission' => 'geo.view', 'freshness_domain' => 'cnae',
            'entities' => ['cnae'], 'dimensions' => ['period', 'cnae'],
            'measures' => ['avg_ticket'],
        ]));
    }

    private static function registerOperationsKpis(self $r): void
    {
        $r->registerKpi(self::kpi([
            'id' => 'omie_sync_freshness', 'name' => 'Freshness Sync Omie', 'domain' => 'omie',
            'description' => 'Tempo desde o ultimo job de sincronizacao Omie bem-sucedido.',
            'source_of_truth' => 'operational', 'source_table' => 'biomie_pipeline_jobs', 'value_column' => 'completed_at',
            'official_date' => 'completed_at', 'status' => 'active', 'grain' => 'realtime',
            'permission' => 'jobs.view', 'freshness_domain' => 'omie',
            'entities' => ['job'], 'dimensions' => ['job_status'],
            'measures' => ['count_jobs'],
        ]));

        $r->registerKpi(self::kpi([
            'id' => 'jobs_falhos', 'name' => 'Jobs com Falha', 'domain' => 'operations',
            'description' => 'Contagem de jobs com status failed nas ultimas 24 horas.',
            'source_of_truth' => 'operational', 'source_table' => 'biomie_pipeline_jobs', 'value_column' => 'id',
            'official_date' => 'started_at', 'status' => 'active', 'grain' => 'realtime',
            'include_rule' => "status = 'failed'",
            'permission' => 'jobs.view', 'freshness_domain' => 'operations',
            'entities' => ['job'], 'dimensions' => ['job_status', 'date'],
            'measures' => ['count_jobs'],
        ]));

        $r->registerKpi(self::kpi([
            'id' => 'webhooks_pendentes', 'name' => 'Webhooks Pendentes', 'domain' => 'operations',
            'description' => 'Eventos Omie recebidos via webhook aguardando processamento.',
            'source_of_truth' => 'operational', 'source_table' => 'biomie_omie_webhooks', 'value_column' => 'id',
            'official_date' => 'received_at', 'status' => 'active', 'grain' => 'realtime',
            'include_rule' => "status = 'pending'",
            'permission' => 'jobs.view', 'freshness_domain' => 'operations',
            'entities' => ['webhook'], 'dimensions' => ['job_status'],
            'measures' => ['count_webhooks'],
        ]));

        $r->registerKpi(self::kpi([
            'id' => 'shadow_divergencias', 'name' => 'Shadow Divergencias', 'domain' => 'shadow',
            'description' => 'Contagem de divergencias detectadas entre payload legacy e canonical.',
            'source_of_truth' => 'operational', 'source_table' => 'biomie_shadow_validations', 'value_column' => 'id',
            'official_date' => 'validated_at', 'status' => 'active', 'grain' => 'realtime',
            'include_rule' => "has_divergence = 1",
            'permission' => 'logs.view', 'freshness_domain' => 'operational',
            'entities' => ['shadow_validation'], 'dimensions' => ['date'],
            'measures' => ['count_jobs'],
        ]));

        $r->registerKpi(self::kpi([
            'id' => 'coverage_canonical', 'name' => 'Cobertura Canonical', 'domain' => 'shadow',
            'description' => 'Percentual de registros com cobertura canonical vs legacy por dominio.',
            'source_of_truth' => 'operational', 'source_table' => 'biomie_canonical_coverage', 'value_column' => 'coverage_pct',
            'official_date' => 'measured_at', 'status' => 'active', 'grain' => 'realtime',
            'permission' => 'logs.view', 'freshness_domain' => 'operational',
            'entities' => ['shadow_validation'], 'dimensions' => ['date'],
            'measures' => ['avg_ticket'],
        ]));
    }
}

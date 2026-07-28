<?php

declare(strict_types=1);

namespace BIomie\Modules\Customers\Application\CustomerDetail;

use BIomie\Support\CommercialMartScope;
use PDO;
use Throwable;

final class CustomerDetailBuilder
{
    private const SOURCE_TABLES = [
        'mart_compradores_por_geo',
        'mart_cliente_periodo',
        'biomie_notas_fiscais_canonicas',
        'biomie_notas_fiscais_itens_canonicos',
        'biomie_pedidos_canonicos',
        'mart_bridge_pedido_nfe_titulo',
        'biomie_titulos_financeiros_canonicos',
        'biomie_clientes_dim',
        'biomie_clientes_geo_dim',
    ];

    /** @var array<string,bool> */
    private array $tableCache = [];

    /** @var array<string,array<string,bool>> */
    private array $columnCache = [];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function build(array $input): array
    {
        $resolver = new CustomerIdentityResolver();
        $identity = $resolver->resolve($input);
        $warnings = $identity['warnings'];

        if (!$identity['resolved']) {
            $warnings[] = 'CustomerIdentityResolver nao conseguiu resolver o cliente.';
        }

        $sourceTables = $this->existingSourceTables();
        $period = [
            'start' => (string) ($input['start'] ?? $input['date_from'] ?? $identity['period_start'] ?? ''),
            'end' => (string) ($input['end'] ?? $input['date_to'] ?? $identity['period_end'] ?? ''),
            'city' => (string) ($input['city'] ?? $input['cidade'] ?? $identity['cidade_norm'] ?? ''),
            'bairro' => (string) ($input['bairro'] ?? $input['neighborhood'] ?? $identity['bairro_norm'] ?? ''),
        ];

        $mart = $this->fetchMartSummary($identity, $input, $warnings);
        $profile = $this->fetchOneByIdentity('biomie_clientes_dim', 'd', $identity, true);
        $geoProfile = $this->fetchOneByIdentity('biomie_clientes_geo_dim', 'g', $identity, true);
        $invoices = $this->fetchInvoices($identity, $period['start'], $period['end'], $warnings);
        $orders = $this->fetchOrders($identity, $period['start'], $period['end'], $invoices, $warnings);
        $products = $this->fetchProducts($identity, $period['start'], $period['end'], $warnings);
        $financeTitles = $this->fetchFinanceTitles($identity, $period['start'], $period['end'], $warnings);
        $vendors = $this->buildVendors($invoices, $products);

        $customerSource = $profile !== []
            ? 'clientes_dim'
            : (($mart['rows'] ?? 0) > 0 ? 'mart' : ($invoices !== [] ? 'nf_canonica' : 'pendente_omie'));
        if ($profile === []) {
            $this->enqueuePendingEnrichment($identity, $mart['row'], $invoices[0] ?? [], $input);
            $warnings[] = 'Cliente ainda nao existe em biomie_clientes_dim; perfil montado por ' . $customerSource . ' e enviado para enriquecimento Omie.';
        }

        $customer = $this->buildCustomer($identity, $profile, $geoProfile, $mart['row'], $invoices[0] ?? [], $input, $customerSource, $warnings);
        $summary = $this->buildSummary($mart['row'], $invoices, $orders, $products, $vendors, $financeTitles);
        $timeline = $this->buildTimeline($summary, $invoices, $orders, $financeTitles);

        if (($mart['rows'] ?? 0) > 0 && $invoices === []) {
            $warnings[] = 'Cliente encontrado no mart_geo, mas sem NF detalhada vinculada por documento_norm/codigo_cliente_omie na canonical.';
        }
        if ($invoices !== [] && $products === []) {
            $warnings[] = 'NFs encontradas, mas os itens da NF nao estao disponiveis no cache local.';
        }
        if ($financeTitles === [] && !$this->tableExists('biomie_titulos_financeiros_canonicos')) {
            $warnings[] = 'Tabela de titulos financeiros canonicos nao disponivel no cache local.';
        }

        $diagnostics = [
            'cliente_documento_recebido' => (string) ($input['cliente_documento'] ?? ''),
            'documento_norm_recebido' => (string) ($input['documento_norm'] ?? ''),
            'codigo_cliente_omie_recebido' => (string) ($input['codigo_cliente_omie'] ?? ''),
            'cliente_nome_recebido' => (string) ($input['cliente_nome'] ?? ''),
            'bairro_recebido' => (string) ($input['bairro'] ?? $input['neighborhood'] ?? ''),
            'periodo_recebido' => [$period['start'], $period['end']],
            'linhas_mart_compradores_por_geo' => (int) ($mart['rows'] ?? 0),
            'linhas_biomie_notas_fiscais_canonicas' => count($invoices),
            'linhas_biomie_notas_fiscais_itens_canonicos' => array_sum(array_map(static fn (array $row): int => (int) ($row['notas_count'] ?? 0), $products)),
            'linhas_biomie_pedidos_canonicos' => count($orders),
            'linhas_biomie_titulos_financeiros_canonicos' => count($financeTitles),
            'cliente_profile_source' => $customerSource,
            'pendente_omie' => $profile === [],
            'motivo_provavel' => $this->likelyReason($mart['rows'] ?? 0, $invoices, $products, $identity),
        ];

        return [
            'ok' => true,
            'customer' => $customer,
            'period' => $period,
            'summary' => $summary,
            'invoices' => $invoices,
            'orders' => $orders,
            'products' => $products,
            'vendors' => $vendors,
            'finance_titles' => $financeTitles,
            'timeline' => $timeline,
            'meta' => [
                'source_tables' => $sourceTables,
                'resolved_by' => (string) ($identity['resolved_by'] ?? ''),
                'customer_key' => (string) ($identity['customer_key'] ?? ''),
                'customer_source' => $customerSource,
                'pending_omie' => $profile === [],
                'warnings' => array_values(array_unique(array_filter($warnings))),
                'diagnostics' => $diagnostics,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function existingSourceTables(): array
    {
        $tables = [];
        foreach (self::SOURCE_TABLES as $table) {
            if ($this->tableExists($table)) {
                $tables[] = $table;
            }
        }
        return $tables;
    }

    /**
     * @param array<string,mixed> $identity
     * @param array<string,mixed> $input
     * @param list<string> $warnings
     * @return array{row:array<string,mixed>,rows:int}
     */
    private function fetchMartSummary(array $identity, array $input, array &$warnings): array
    {
        $table = 'mart_compradores_por_geo';
        if (!$this->tableExists($table)) {
            $warnings[] = 'mart_compradores_por_geo nao disponivel no cache local.';
            return ['row' => [], 'rows' => 0];
        }

        [$identitySql, $identityParams] = $this->identityCondition('m', $table, $identity, 'mart', true);
        if ($identitySql === null) {
            return ['row' => [], 'rows' => 0];
        }

        $start = (string) ($identity['period_start'] ?? $input['start'] ?? $input['date_from'] ?? '');
        $end = (string) ($identity['period_end'] ?? $input['end'] ?? $input['date_to'] ?? '');
        $where = [
            CommercialMartScope::condition($this->pdo, $table, 'm'),
            'm.`competencia` BETWEEN :mart_start_comp AND :mart_end_comp',
            $identitySql,
        ];
        $params = $identityParams + [
            'mart_start_comp' => substr($start, 0, 7),
            'mart_end_comp' => substr($end, 0, 7),
        ];

        $city = $this->normalizeKey($input['city'] ?? $input['cidade'] ?? $identity['cidade_norm'] ?? '');
        if ($city !== '' && $this->columnExists($table, 'cidade_norm')) {
            $where[] = 'LOWER(m.`cidade_norm`) = :mart_city';
            $params['mart_city'] = $city;
        }

        $bairro = $this->normalizeKey($input['bairro'] ?? $input['neighborhood'] ?? $identity['bairro_norm'] ?? '');
        if ($bairro !== '' && $this->columnExists($table, 'bairro_norm')) {
            $where[] = 'LOWER(m.`bairro_norm`) = :mart_bairro';
            $params['mart_bairro'] = $bairro;
        }

        $sql = '
            SELECT
                COUNT(*) AS _rows_count,
                MAX(m.`codigo_cliente_omie`) AS codigo_cliente_omie,
                MAX(m.`cliente_nome`) AS cliente_nome,
                MAX(m.`documento`) AS documento,
                MAX(m.`uf`) AS uf,
                MAX(m.`cidade_norm`) AS cidade_norm,
                MAX(m.`bairro_norm`) AS bairro_norm,
                MAX(m.`cnae_codigo`) AS cnae_codigo,
                MAX(m.`cnae_descricao`) AS cnae_descricao,
                COALESCE(SUM(m.`qtd_notas`), 0) AS qtd_notas,
                COALESCE(SUM(m.`faturamento`), 0) AS faturamento,
                MAX(m.`ultima_compra`) AS ultima_compra
            FROM `mart_compradores_por_geo` m
            WHERE ' . implode(' AND ', $where);

        $row = $this->fetchOne($sql, $params);
        if ($row === []) {
            return ['row' => [], 'rows' => 0];
        }

        return [
            'row' => $row,
            'rows' => (int) ($row['_rows_count'] ?? 0),
        ];
    }

    /**
     * @param array<string,mixed> $identity
     * @return array<string,mixed>
     */
    private function fetchOneByIdentity(string $table, string $alias, array $identity, bool $allowName): array
    {
        if (!$this->tableExists($table)) {
            return [];
        }

        [$identitySql, $params] = $this->identityCondition($alias, $table, $identity, $alias, $allowName);
        if ($identitySql === null) {
            return [];
        }

        $tenantSql = \BIomie\Support\TenantContext::sqlFilter($alias);
        return $this->fetchOne(
            'SELECT ' . $alias . '.* FROM `' . $table . '` ' . $alias . ' WHERE ' . $tenantSql . ' AND ' . $identitySql . ' LIMIT 1',
            $params
        );
    }

    /**
     * @param array<string,mixed> $identity
     * @param list<string> $warnings
     * @return list<array<string,mixed>>
     */
    private function fetchInvoices(array $identity, string $start, string $end, array &$warnings): array
    {
        $table = 'biomie_notas_fiscais_canonicas';
        if (!$this->tableExists($table)) {
            $warnings[] = 'biomie_notas_fiscais_canonicas nao disponivel no cache local.';
            return [];
        }

        [$identitySql, $params] = $this->identityCondition('n', $table, $identity, 'nf', true);
        if ($identitySql === null) {
            return [];
        }

        $where = [\BIomie\Support\TenantContext::sqlFilter('n'), $identitySql];
        [$dateSql, $dateParams] = $this->dateCondition('n', $table, ['data_competencia', 'data_emissao'], $start, $end, 'nf_date');
        if ($dateSql !== null) {
            $where[] = $dateSql;
            $params += $dateParams;
        }
        if ($this->columnExists($table, 'status_fiscal')) {
            $where[] = "n.`status_fiscal` = 'autorizada'";
        }

        $joins = [];
        $selectBridge = [
            "'' AS bridge_codigo_pedido",
            "'' AS bridge_pedido_id_omie",
            "'' AS bridge_nome_vendedor",
            "'' AS bridge_status_comercial",
            "NULL AS bridge_data_faturamento",
        ];
        if ($this->tableExists('mart_bridge_pedido_nfe_titulo') && $this->columnExists('mart_bridge_pedido_nfe_titulo', 'nota_key')) {
            $joins[] = 'LEFT JOIN `mart_bridge_pedido_nfe_titulo` b ON b.`nota_key` = n.`nota_key`';
            $selectBridge = [
                $this->selectColumn('b', 'mart_bridge_pedido_nfe_titulo', 'codigo_pedido', 'bridge_codigo_pedido', "''"),
                $this->selectColumn('b', 'mart_bridge_pedido_nfe_titulo', 'pedido_id_omie', 'bridge_pedido_id_omie', "''"),
                $this->selectColumn('b', 'mart_bridge_pedido_nfe_titulo', 'nome_vendedor', 'bridge_nome_vendedor', "''"),
                $this->selectColumn('b', 'mart_bridge_pedido_nfe_titulo', 'status_comercial', 'bridge_status_comercial', "''"),
                $this->selectColumn('b', 'mart_bridge_pedido_nfe_titulo', 'data_faturamento', 'bridge_data_faturamento', 'NULL'),
            ];
        }

        $selectItems = '0 AS _items_count';
        if ($this->tableExists('biomie_notas_fiscais_itens_canonicos')) {
            $joins[] = 'LEFT JOIN (SELECT `nota_key`, COUNT(*) AS items_count FROM `biomie_notas_fiscais_itens_canonicos` GROUP BY `nota_key`) ic ON ic.`nota_key` = n.`nota_key`';
            $selectItems = 'COALESCE(ic.items_count, 0) AS _items_count';
        }

        $orderExpr = $this->coalesceColumns('n', $table, ['data_competencia', 'data_emissao'], 'n.`data_emissao`');
        $sql = '
            SELECT n.*, ' . implode(', ', $selectBridge) . ', ' . $selectItems . '
            FROM `biomie_notas_fiscais_canonicas` n
            ' . implode("\n", $joins) . '
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY ' . $orderExpr . ' DESC, n.`numero_nf` DESC
            LIMIT 100';

        $rows = $this->fetchAll($sql, $params);
        $deduped = [];
        foreach ($rows as $row) {
            $key = (string) ($row['nota_key'] ?? '');
            if ($key === '') {
                $key = (string) ($row['numero_nf'] ?? '') . '|' . (string) ($row['serie_nf'] ?? '');
            }
            $deduped[$key] = $this->mapInvoice($row);
        }

        return array_values($deduped);
    }

    /**
     * @param array<string,mixed> $identity
     * @param list<array<string,mixed>> $invoices
     * @param list<string> $warnings
     * @return list<array<string,mixed>>
     */
    private function fetchOrders(array $identity, string $start, string $end, array $invoices, array &$warnings): array
    {
        $invoiceByOrder = [];
        foreach ($invoices as $invoice) {
            foreach (['pedido_codigo', 'pedido_numero'] as $key) {
                $orderKey = trim((string) ($invoice[$key] ?? ''));
                if ($orderKey !== '' && !isset($invoiceByOrder[$orderKey])) {
                    $invoiceByOrder[$orderKey] = $invoice;
                }
            }
        }

        $orders = [];
        $table = 'biomie_pedidos_canonicos';
        if ($this->tableExists($table)) {
            [$identitySql, $params] = $this->identityCondition('p', $table, $identity, 'ped', true);
            if ($identitySql !== null) {
                $where = [\BIomie\Support\TenantContext::sqlFilter('p'), $identitySql];
                [$dateSql, $dateParams] = $this->dateCondition('p', $table, ['data_faturamento', 'data_emissao'], $start, $end, 'ped_date');
                if ($dateSql !== null) {
                    $where[] = $dateSql;
                    $params += $dateParams;
                }

                $orderExpr = $this->coalesceColumns('p', $table, ['data_faturamento', 'data_emissao'], 'p.`data_emissao`');
                $rows = $this->fetchAll(
                    'SELECT p.* FROM `biomie_pedidos_canonicos` p WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $orderExpr . ' DESC LIMIT 100',
                    $params
                );
                foreach ($rows as $row) {
                    $codigo = (string) ($row['codigo_pedido'] ?? '');
                    $pedidoId = (string) ($row['pedido_id_omie'] ?? '');
                    $linkedInvoice = $invoiceByOrder[$codigo] ?? $invoiceByOrder[$pedidoId] ?? [];
                    $orders[$codigo !== '' ? $codigo : $pedidoId] = $this->mapOrder($row, $linkedInvoice);
                }
            }
        } else {
            $warnings[] = 'biomie_pedidos_canonicos nao disponivel no cache local.';
        }

        foreach ($invoices as $invoice) {
            $codigo = trim((string) ($invoice['pedido_codigo'] ?? ''));
            if ($codigo === '' || isset($orders[$codigo])) {
                continue;
            }
            $orders[$codigo] = [
                'codigo_pedido' => $codigo,
                'numero_pedido' => (string) ($invoice['pedido_numero'] ?? ''),
                'data_inclusao' => '',
                'data_faturamento' => (string) ($invoice['data_emissao'] ?? ''),
                'status' => 'Nao disponivel no cache local',
                'etapa' => '',
                'valor_total' => (float) ($invoice['valor_total'] ?? 0),
                'vendedor_nome' => (string) ($invoice['vendedor_nome'] ?? ''),
                'nf_display' => (string) ($invoice['nf_display'] ?? ''),
                'nota_key' => (string) ($invoice['nota_key'] ?? ''),
            ];
        }

        return array_values($orders);
    }

    /**
     * @param array<string,mixed> $identity
     * @param list<string> $warnings
     * @return list<array<string,mixed>>
     */
    private function fetchProducts(array $identity, string $start, string $end, array &$warnings): array
    {
        if (!$this->tableExists('biomie_notas_fiscais_itens_canonicos')) {
            $warnings[] = 'biomie_notas_fiscais_itens_canonicos nao disponivel no cache local.';
            return [];
        }
        if (!$this->tableExists('biomie_notas_fiscais_canonicas')) {
            return [];
        }

        [$identitySql, $params] = $this->identityCondition('n', 'biomie_notas_fiscais_canonicas', $identity, 'prod', true);
        if ($identitySql === null) {
            return [];
        }

        $where = [\BIomie\Support\TenantContext::sqlFilter('n'), $identitySql];
        [$dateSql, $dateParams] = $this->dateCondition('n', 'biomie_notas_fiscais_canonicas', ['data_competencia', 'data_emissao'], $start, $end, 'prod_date');
        if ($dateSql !== null) {
            $where[] = $dateSql;
            $params += $dateParams;
        }
        if ($this->columnExists('biomie_notas_fiscais_canonicas', 'status_fiscal')) {
            $where[] = "n.`status_fiscal` = 'autorizada'";
        }

        $ncmExpr = $this->columnExists('biomie_notas_fiscais_itens_canonicos', 'ncm')
            ? 'MAX(i.`ncm`)'
            : "''";
        $vendorExpr = $this->columnExists('biomie_notas_fiscais_canonicas', 'nome_vendedor')
            ? "GROUP_CONCAT(DISTINCT NULLIF(n.`nome_vendedor`, '') SEPARATOR '||')"
            : "''";
        $dateExpr = $this->coalesceColumns('n', 'biomie_notas_fiscais_canonicas', ['data_competencia', 'data_emissao'], 'n.`data_emissao`');

        $rows = $this->fetchAll(
            '
            SELECT
                COALESCE(NULLIF(i.`codigo_produto`, \'\'), \'SEM_CODIGO\') AS codigo_produto,
                MAX(i.`descricao_produto`) AS descricao,
                ' . $ncmExpr . ' AS ncm,
                COALESCE(SUM(i.`quantidade`), 0) AS quantidade,
                COALESCE(SUM(i.`valor_total`), 0) AS valor_total,
                COALESCE(SUM(i.`valor_desconto`), 0) AS desconto_total,
                COUNT(DISTINCT i.`nota_key`) AS notas_count,
                MAX(' . $dateExpr . ') AS ultima_compra,
                ' . $vendorExpr . ' AS vendedores
            FROM `biomie_notas_fiscais_itens_canonicos` i
            INNER JOIN `biomie_notas_fiscais_canonicas` n ON n.`nota_key` = i.`nota_key`
            WHERE ' . implode(' AND ', $where) . '
            GROUP BY COALESCE(NULLIF(i.`codigo_produto`, \'\'), \'SEM_CODIGO\'), i.`descricao_produto`
            ORDER BY valor_total DESC
            LIMIT 100',
            $params
        );

        return array_map(static function (array $row): array {
            $quantity = (float) ($row['quantidade'] ?? 0);
            $total = (float) ($row['valor_total'] ?? 0);
            $vendors = array_values(array_filter(array_map('trim', explode('||', (string) ($row['vendedores'] ?? '')))));
            return [
                'codigo_produto' => (string) ($row['codigo_produto'] ?? ''),
                'descricao' => (string) ($row['descricao'] ?? ''),
                'ncm' => (string) ($row['ncm'] ?? ''),
                'quantidade' => $quantity,
                'valor_unitario' => $quantity > 0 ? round($total / $quantity, 2) : 0.0,
                'desconto_total' => (float) ($row['desconto_total'] ?? 0),
                'valor_total' => $total,
                'notas_count' => (int) ($row['notas_count'] ?? 0),
                'ultima_compra' => (string) ($row['ultima_compra'] ?? ''),
                'vendedores' => $vendors,
            ];
        }, $rows);
    }

    /**
     * @param array<string,mixed> $identity
     * @param list<string> $warnings
     * @return list<array<string,mixed>>
     */
    private function fetchFinanceTitles(array $identity, string $start, string $end, array &$warnings): array
    {
        $table = 'biomie_titulos_financeiros_canonicos';
        if (!$this->tableExists($table)) {
            return [];
        }

        [$identitySql, $params] = $this->identityCondition('t', $table, $identity, 'fin', true);
        if ($identitySql === null) {
            return [];
        }

        $where = [\BIomie\Support\TenantContext::sqlFilter('t'), $identitySql];
        [$dateSql, $dateParams] = $this->dateCondition('t', $table, ['data_competencia', 'data_emissao', 'data_vencimento'], $start, $end, 'fin_date');
        if ($dateSql !== null) {
            $where[] = $dateSql;
            $params += $dateParams;
        }

        $orderExpr = $this->coalesceColumns('t', $table, ['data_vencimento', 'data_emissao', 'data_competencia'], 't.`data_vencimento`');
        $rows = $this->fetchAll(
            'SELECT t.* FROM `biomie_titulos_financeiros_canonicos` t WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $orderExpr . ' ASC LIMIT 100',
            $params
        );

        return array_map(function (array $row): array {
            $valor = $this->firstNumber($row, ['valor_liquido', 'valor_pago', 'valor_aberto']);
            return [
                'titulo_key' => (string) ($row['titulo_key'] ?? ''),
                'numero_documento' => (string) ($row['numero_documento'] ?? ''),
                'data_emissao' => (string) ($row['data_emissao'] ?? ''),
                'data_vencimento' => (string) ($row['data_vencimento'] ?? ''),
                'data_liquidacao' => (string) ($row['data_liquidacao'] ?? ''),
                'valor' => $valor,
                'valor_aberto' => (float) ($row['valor_aberto'] ?? 0),
                'valor_pago' => (float) ($row['valor_pago'] ?? 0),
                'status' => (string) ($row['status_titulo'] ?? ''),
                'especie_documento' => (string) ($row['especie_documento'] ?? ''),
                'nota_key' => (string) ($row['nota_key'] ?? ''),
                'codigo_pedido' => (string) ($row['codigo_pedido_origem'] ?? $row['pedido_id_omie'] ?? ''),
            ];
        }, $rows);
    }

    /**
     * @param list<array<string,mixed>> $invoices
     * @param list<array<string,mixed>> $products
     * @return list<array<string,mixed>>
     */
    private function buildVendors(array $invoices, array $products): array
    {
        $vendors = [];
        foreach ($invoices as $invoice) {
            $name = trim((string) ($invoice['vendedor_nome'] ?? ''));
            if ($name === '') {
                $name = 'Nao disponivel no cache local';
            }
            $vendors[$name] ??= [
                'vendedor_nome' => $name,
                'notas' => 0,
                'faturamento' => 0.0,
                'produtos' => 0,
                '_notes' => [],
                '_products' => [],
            ];
            $noteKey = (string) ($invoice['nota_key'] ?? $invoice['nf_display'] ?? '');
            $vendors[$name]['_notes'][$noteKey] = true;
            $vendors[$name]['notas'] = count($vendors[$name]['_notes']);
            $vendors[$name]['faturamento'] += (float) ($invoice['valor_total'] ?? 0);
        }

        foreach ($products as $product) {
            foreach (($product['vendedores'] ?? []) as $vendorName) {
                $vendorName = trim((string) $vendorName);
                if ($vendorName === '' || !isset($vendors[$vendorName])) {
                    continue;
                }
                $vendors[$vendorName]['_products'][(string) ($product['codigo_produto'] ?? $product['descricao'] ?? '')] = true;
                $vendors[$vendorName]['produtos'] = count($vendors[$vendorName]['_products']);
            }
        }

        $rows = [];
        foreach ($vendors as $vendor) {
            unset($vendor['_notes'], $vendor['_products']);
            $rows[] = $vendor;
        }

        usort($rows, static fn (array $a, array $b): int => ((float) ($b['faturamento'] ?? 0)) <=> ((float) ($a['faturamento'] ?? 0)));
        return $rows;
    }

    /**
     * @param array<string,mixed> $identity
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $geoProfile
     * @param array<string,mixed> $mart
     * @param array<string,mixed> $invoice
     * @param array<string,mixed> $input
     * @param list<string> $warnings
     * @return array<string,mixed>
     */
    private function buildCustomer(array $identity, array $profile, array $geoProfile, array $mart, array $invoice, array $input, string $customerSource, array &$warnings): array
    {
        $doc = $this->firstString($profile, ['documento_normalizado', 'cpf_cnpj', 'documento_cliente']);
        if ($doc === '') {
            $doc = $this->firstString($mart, ['documento']);
        }
        if ($doc === '') {
            $doc = $this->firstString($invoice, ['documento_cliente']);
        }
        if ($doc === '') {
            $doc = (string) ($identity['documento_norm'] ?? '');
        }

        $address = $this->composeAddress($profile, $geoProfile);
        $telefone = $this->firstString($profile, ['telefone', 'phone', 'fone', 'ddd_telefone1']);
        $email = $this->firstString($profile, ['email', 'email_cliente', 'client_email']);

        $missing = [];
        if ($address === '') {
            $missing[] = 'endereco';
        }
        if ($telefone === '') {
            $missing[] = 'telefone';
        }
        if ($email === '') {
            $missing[] = 'email';
        }
        if ($this->firstString($profile, ['cnae_codigo']) === '' && $this->firstString($mart, ['cnae_codigo']) === '') {
            $missing[] = 'CNAE';
        }
        if ($missing !== []) {
            $warnings[] = implode(', ', $missing) . ' nao disponivel no cache local.';
        }

        return [
            'codigo_cliente_omie' => $this->firstNonEmpty([
                (string) ($identity['codigo_cliente_omie'] ?? ''),
                $this->firstString($profile, ['codigo_cliente_omie']),
                $this->firstString($mart, ['codigo_cliente_omie']),
                $this->firstString($invoice, ['codigo_cliente_omie']),
            ]),
            'nome' => $this->firstNonEmpty([
                $this->firstString($profile, ['cliente_nome_exibicao', 'nome_fantasia', 'nome_cliente']),
                $this->firstString($mart, ['cliente_nome']),
                $this->firstString($invoice, ['nome_cliente']),
                (string) ($identity['cliente_nome'] ?? ''),
            ]),
            'documento' => $doc,
            'cidade' => $this->firstNonEmpty([
                $this->firstString($profile, ['cidade', 'cidade_normalizada']),
                $this->firstString($geoProfile, ['cidade_raw', 'cidade_norm']),
                $this->firstString($mart, ['cidade_norm']),
                $this->firstString($invoice, ['cidade_cliente', 'cidade', 'cidade_norm']),
            ]),
            'uf' => $this->firstNonEmpty([
                $this->firstString($profile, ['uf']),
                $this->firstString($geoProfile, ['uf']),
                $this->firstString($mart, ['uf']),
                $this->firstString($invoice, ['uf_cliente', 'uf']),
            ]),
            'bairro' => $this->firstNonEmpty([
                $this->firstString($profile, ['bairro', 'bairro_normalizado']),
                $this->firstString($geoProfile, ['bairro_raw', 'bairro_norm', 'bairro_oficial_nome_fortaleza']),
                $this->firstString($mart, ['bairro_norm']),
                $this->firstString($invoice, ['bairro_cliente', 'bairro', 'bairro_norm']),
            ]),
            'endereco' => $address,
            'telefone' => $telefone,
            'email' => $email,
            'cnae_codigo' => $this->firstNonEmpty([
                $this->firstString($profile, ['cnae_codigo']),
                $this->firstString($mart, ['cnae_codigo']),
            ]),
            'cnae_descricao' => $this->firstNonEmpty([
                $this->firstString($profile, ['cnae_descricao']),
                $this->firstString($mart, ['cnae_descricao']),
            ]),
            'source' => $customerSource,
            'pending_omie' => $customerSource !== 'clientes_dim',
        ];
    }

    /**
     * @param list<array<string,mixed>> $invoices
     * @param list<array<string,mixed>> $orders
     * @param list<array<string,mixed>> $products
     * @param list<array<string,mixed>> $vendors
     * @param list<array<string,mixed>> $financeTitles
     * @return array<string,mixed>
     */
    private function buildSummary(array $mart, array $invoices, array $orders, array $products, array $vendors, array $financeTitles): array
    {
        $invoiceRevenue = array_sum(array_map(static fn (array $row): float => (float) ($row['valor_total'] ?? 0), $invoices));
        $martRevenue = (float) ($mart['faturamento'] ?? 0);
        $revenue = $martRevenue > 0 ? $martRevenue : $invoiceRevenue;
        $martNotes = (int) ($mart['qtd_notas'] ?? 0);
        $notesCount = $martNotes > 0 ? $martNotes : count($invoices);
        $discount = array_sum(array_map(static fn (array $row): float => (float) ($row['desconto'] ?? 0), $invoices));

        $invoiceDates = array_values(array_filter(array_map(static fn (array $row): string => (string) ($row['data_emissao'] ?? ''), $invoices)));
        sort($invoiceDates);
        $firstPurchase = $invoiceDates[0] ?? '';
        $lastPurchase = $invoiceDates !== [] ? $invoiceDates[count($invoiceDates) - 1] : (string) ($mart['ultima_compra'] ?? '');

        return [
            'faturamento' => round($revenue, 2),
            'notas_count' => $notesCount,
            'pedidos_count' => count($orders),
            'produtos_count' => count($products),
            'vendedores_count' => count($vendors),
            'ticket_medio' => $notesCount > 0 ? round($revenue / $notesCount, 2) : 0.0,
            'desconto_total' => round($discount, 2),
            'ultima_compra' => $lastPurchase,
            'primeira_compra' => $firstPurchase,
            'titulos_count' => count($financeTitles),
        ];
    }

    /**
     * @param list<array<string,mixed>> $invoices
     * @param list<array<string,mixed>> $orders
     * @param list<array<string,mixed>> $financeTitles
     * @return list<array<string,mixed>>
     */
    private function buildTimeline(array $summary, array $invoices, array $orders, array $financeTitles): array
    {
        $events = [];
        if (($summary['primeira_compra'] ?? '') !== '') {
            $events[] = [
                'date' => (string) $summary['primeira_compra'],
                'type' => 'customer',
                'label' => 'Primeira compra no periodo',
                'amount' => 0,
                'ref' => '',
            ];
        }

        foreach ($invoices as $invoice) {
            if (($invoice['data_emissao'] ?? '') === '') {
                continue;
            }
            $events[] = [
                'date' => (string) $invoice['data_emissao'],
                'type' => 'invoice',
                'label' => 'NF ' . (string) ($invoice['nf_display'] ?? $invoice['numero_nf'] ?? '') . ' emitida',
                'amount' => (float) ($invoice['valor_total'] ?? 0),
                'ref' => (string) ($invoice['nota_key'] ?? ''),
            ];
        }

        foreach ($orders as $order) {
            $date = (string) ($order['data_faturamento'] ?? '');
            if ($date === '') {
                continue;
            }
            $events[] = [
                'date' => $date,
                'type' => 'order',
                'label' => 'Pedido ' . (string) ($order['codigo_pedido'] ?? $order['numero_pedido'] ?? '') . ' faturado',
                'amount' => (float) ($order['valor_total'] ?? 0),
                'ref' => (string) ($order['codigo_pedido'] ?? ''),
            ];
        }

        foreach ($financeTitles as $title) {
            if (($title['data_emissao'] ?? '') !== '') {
                $events[] = [
                    'date' => (string) $title['data_emissao'],
                    'type' => 'finance',
                    'label' => 'Titulo financeiro criado',
                    'amount' => (float) ($title['valor'] ?? 0),
                    'ref' => (string) ($title['titulo_key'] ?? ''),
                ];
            }
            if (($title['data_vencimento'] ?? '') !== '') {
                $events[] = [
                    'date' => (string) $title['data_vencimento'],
                    'type' => 'finance',
                    'label' => 'Titulo vencido: ' . (string) ($title['status'] ?? ''),
                    'amount' => (float) ($title['valor_aberto'] ?? $title['valor'] ?? 0),
                    'ref' => (string) ($title['titulo_key'] ?? ''),
                ];
            }
            if (($title['data_liquidacao'] ?? '') !== '') {
                $events[] = [
                    'date' => (string) $title['data_liquidacao'],
                    'type' => 'finance',
                    'label' => 'Titulo pago',
                    'amount' => (float) ($title['valor_pago'] ?? $title['valor'] ?? 0),
                    'ref' => (string) ($title['titulo_key'] ?? ''),
                ];
            }
        }

        if (($summary['ultima_compra'] ?? '') !== '') {
            $events[] = [
                'date' => (string) $summary['ultima_compra'],
                'type' => 'customer',
                'label' => 'Ultima compra no periodo',
                'amount' => (float) ($summary['faturamento'] ?? 0),
                'ref' => '',
            ];
        }

        usort($events, static fn (array $a, array $b): int => strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? '')));
        return array_slice($events, 0, 150);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function mapInvoice(array $row): array
    {
        $numero = (string) ($row['numero_nf'] ?? '');
        $serie = (string) ($row['serie_nf'] ?? $row['serie'] ?? '');
        $nfDisplay = $numero;
        if ($serie !== '') {
            $nfDisplay .= '/' . $serie;
        }
        $pedidoCodigo = $this->firstNonEmpty([
            (string) ($row['bridge_codigo_pedido'] ?? ''),
            (string) ($row['codigo_pedido_origem'] ?? ''),
            (string) ($row['pedido_id_omie'] ?? ''),
        ]);

        return [
            'nota_key' => (string) ($row['nota_key'] ?? ''),
            'numero_nf' => $numero,
            'serie' => $serie,
            'nf_display' => $nfDisplay,
            'chave_nfe' => (string) ($row['chave_nfe'] ?? ''),
            'data_emissao' => (string) ($row['data_emissao'] ?? $row['data_competencia'] ?? ''),
            'valor_total' => (float) ($row['valor_total'] ?? 0),
            'valor_mercadoria' => (float) ($row['valor_mercadorias'] ?? 0),
            'desconto' => (float) ($row['valor_descontos'] ?? 0),
            'pedido_codigo' => $pedidoCodigo,
            'pedido_numero' => $this->firstNonEmpty([
                (string) ($row['bridge_codigo_pedido'] ?? ''),
                (string) ($row['codigo_pedido_origem'] ?? ''),
                (string) ($row['bridge_pedido_id_omie'] ?? ''),
                (string) ($row['pedido_id_omie'] ?? ''),
            ]),
            'vendedor_nome' => $this->firstNonEmpty([
                (string) ($row['bridge_nome_vendedor'] ?? ''),
                (string) ($row['nome_vendedor'] ?? ''),
            ]),
            'status' => (string) ($row['status_fiscal'] ?? $row['bridge_status_comercial'] ?? ''),
            'items_count' => (int) ($row['_items_count'] ?? $row['quantidade_itens'] ?? 0),
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $invoice
     * @return array<string,mixed>
     */
    private function mapOrder(array $row, array $invoice): array
    {
        return [
            'codigo_pedido' => (string) ($row['codigo_pedido'] ?? ''),
            'numero_pedido' => (string) ($row['pedido_id_omie'] ?? $row['codigo_integracao'] ?? ''),
            'data_inclusao' => (string) ($row['data_emissao'] ?? ''),
            'data_faturamento' => (string) ($row['data_faturamento'] ?? ''),
            'status' => (string) ($row['status_comercial'] ?? $row['status_pedido_omie'] ?? ''),
            'etapa' => (string) ($row['etapa_omie'] ?? ''),
            'valor_total' => (float) ($row['valor_total'] ?? 0),
            'vendedor_nome' => (string) ($row['nome_vendedor'] ?? ''),
            'nf_display' => (string) ($invoice['nf_display'] ?? ''),
            'nota_key' => (string) ($invoice['nota_key'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $identity
     * @return array{0:string|null,1:array<string,mixed>}
     */
    private function identityCondition(string $alias, string $table, array $identity, string $prefix, bool $allowName): array
    {
        $code = trim((string) ($identity['codigo_cliente_omie'] ?? ''));
        if ($code !== '' && $this->columnExists($table, 'codigo_cliente_omie')) {
            return [$alias . '.`codigo_cliente_omie` = :' . $prefix . '_codigo_cliente', [$prefix . '_codigo_cliente' => $code]];
        }

        $doc = trim((string) ($identity['documento_norm'] ?? ''));
        $docCol = $this->firstExistingColumn($table, ['documento_norm', 'documento_normalizado', 'cliente_documento', 'documento_cliente', 'documento', 'cpf_cnpj', 'cnpj_cpf']);
        if ($doc !== '' && $docCol !== null) {
            return [
                $this->documentSql($alias, $docCol) . ' = :' . $prefix . '_documento',
                [$prefix . '_documento' => $doc],
            ];
        }

        $notaKey = trim((string) ($identity['nota_key'] ?? ''));
        if ($notaKey !== '' && $this->columnExists($table, 'nota_key')) {
            return [$alias . '.`nota_key` = :' . $prefix . '_nota_key', [$prefix . '_nota_key' => $notaKey]];
        }

        $pedido = trim((string) ($identity['pedido_codigo'] ?? ''));
        $pedidoCol = $this->firstExistingColumn($table, ['codigo_pedido', 'codigo_pedido_origem', 'pedido_id_omie']);
        if ($pedido !== '' && $pedidoCol !== null) {
            return [$alias . '.`' . $pedidoCol . '` = :' . $prefix . '_pedido', [$prefix . '_pedido' => $pedido]];
        }

        $name = trim((string) ($identity['cliente_nome'] ?? ''));
        $nameCol = $this->firstExistingColumn($table, ['cliente_nome', 'nome_cliente', 'customer_name', 'cliente_nome_exibicao']);
        if ($allowName && $name !== '' && $nameCol !== null) {
            return [
                'LOWER(TRIM(' . $alias . '.`' . $nameCol . '`)) = LOWER(TRIM(:' . $prefix . '_nome_cliente))',
                [$prefix . '_nome_cliente' => $name],
            ];
        }

        return [null, []];
    }

    /**
     * @param list<string> $candidates
     * @return array{0:string|null,1:array<string,string>}
     */
    private function dateCondition(string $alias, string $table, array $candidates, string $start, string $end, string $prefix): array
    {
        if ($start === '' || $end === '') {
            return [null, []];
        }

        $columns = [];
        foreach ($candidates as $column) {
            if ($this->columnExists($table, $column)) {
                $columns[] = $alias . '.`' . $column . '`';
            }
        }
        if ($columns === []) {
            return [null, []];
        }

        $expr = count($columns) === 1 ? $columns[0] : 'COALESCE(' . implode(', ', $columns) . ')';
        return [
            'DATE(' . $expr . ') BETWEEN :' . $prefix . '_start AND :' . $prefix . '_end',
            [$prefix . '_start' => $start, $prefix . '_end' => $end],
        ];
    }

    /**
     * @param list<string> $candidates
     */
    private function coalesceColumns(string $alias, string $table, array $candidates, string $fallback): string
    {
        $columns = [];
        foreach ($candidates as $column) {
            if ($this->columnExists($table, $column)) {
                $columns[] = $alias . '.`' . $column . '`';
            }
        }
        if ($columns === []) {
            return $fallback;
        }
        return count($columns) === 1 ? $columns[0] : 'COALESCE(' . implode(', ', $columns) . ')';
    }

    private function selectColumn(string $alias, string $table, string $column, string $as, string $default): string
    {
        if ($this->columnExists($table, $column)) {
            return $alias . '.`' . $column . '` AS `' . $as . '`';
        }
        return $default . ' AS `' . $as . '`';
    }

    private function documentSql(string $alias, string $column): string
    {
        $ref = $alias . '.`' . $column . '`';
        return "REPLACE(REPLACE(REPLACE(REPLACE(COALESCE({$ref}, ''), '.', ''), '-', ''), '/', ''), ' ', '')";
    }

    /**
     * @param list<string> $candidates
     */
    private function firstExistingColumn(string $table, array $candidates): ?string
    {
        foreach ($candidates as $column) {
            if ($this->columnExists($table, $column)) {
                return $column;
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $identity
     * @param array<string,mixed> $mart
     * @param array<string,mixed> $invoice
     * @param array<string,mixed> $input
     */
    private function enqueuePendingEnrichment(array $identity, array $mart, array $invoice, array $input): void
    {
        if (!$this->tableExists('biomie_clientes_para_enriquecer')) {
            return;
        }

        $codigo = (int) $this->firstNonEmpty([
            (string) ($identity['codigo_cliente_omie'] ?? ''),
            $this->firstString($mart, ['codigo_cliente_omie']),
            $this->firstString($invoice, ['codigo_cliente_omie']),
            (string) ($input['codigo_cliente_omie'] ?? ''),
        ]);
        if ($codigo <= 0) {
            return;
        }

        $nome = $this->firstNonEmpty([
            $this->firstString($mart, ['cliente_nome']),
            $this->firstString($invoice, ['nome_cliente']),
            (string) ($identity['cliente_nome'] ?? ''),
            (string) ($input['cliente_nome'] ?? ''),
        ]);

        $columns = ['codigo_cliente_omie', 'source_origin', 'status'];
        $values = [
            'codigo_cliente_omie' => ':codigo_cliente_omie',
            'source_origin' => ':source_origin',
            'status' => ':status',
        ];
        $params = [
            'codigo_cliente_omie' => $codigo,
            'source_origin' => 'customer_detail_api',
            'status' => 'pendente',
        ];
        $updates = [
            "source_origin = VALUES(source_origin)",
            "status = IF(status = 'concluido', status, VALUES(status))",
        ];

        $optional = [
            'motivo' => ['value' => 'dim_ausente', 'update' => true],
            'cliente_nome_exibicao' => ['value' => mb_substr($nome, 0, 255), 'update' => true],
            'ultima_tentativa' => ['raw' => 'CURRENT_TIMESTAMP', 'update_raw' => 'ultima_tentativa = CURRENT_TIMESTAMP'],
        ];

        foreach ($optional as $column => $def) {
            if (!$this->columnExists('biomie_clientes_para_enriquecer', $column)) {
                continue;
            }
            $columns[] = $column;
            if (isset($def['raw'])) {
                $values[$column] = (string) $def['raw'];
            } else {
                $values[$column] = ':' . $column;
                $params[$column] = $def['value'];
            }
            if (!empty($def['update_raw'])) {
                $updates[] = (string) $def['update_raw'];
            } elseif (!empty($def['update'])) {
                $updates[] = $column . ' = VALUES(' . $column . ')';
            }
        }

        try {
            $sql = 'INSERT INTO biomie_clientes_para_enriquecer (`' . implode('`, `', $columns) . '`) VALUES ('
                . implode(', ', array_map(static fn (string $column): string => $values[$column], $columns))
                . ') ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
            $stmt = $this->pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue(':' . $key, $value);
            }
            $stmt->execute();
        } catch (Throwable) {
            // Cliente 360 nao deve falhar por impossibilidade de enfileirar.
        }
    }

    private function tableExists(string $table): bool
    {
        if (array_key_exists($table, $this->tableCache)) {
            return $this->tableCache[$table];
        }
        try {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table LIMIT 1'
            );
            $stmt->execute(['table' => $table]);
            return $this->tableCache[$table] = (bool) $stmt->fetchColumn();
        } catch (Throwable) {
            return $this->tableCache[$table] = false;
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        if (!isset($this->columnCache[$table])) {
            $this->columnCache[$table] = [];
            try {
                $stmt = $this->pdo->prepare(
                    'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
                );
                $stmt->execute(['table' => $table]);
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $name) {
                    $this->columnCache[$table][(string) $name] = true;
                }
            } catch (Throwable) {
                $this->columnCache[$table] = [];
            }
        }

        return isset($this->columnCache[$table][$column]);
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function fetchOne(string $sql, array $params): array
    {
        $rows = $this->fetchAll($sql, $params);
        return $rows[0] ?? [];
    }

    /**
     * @param array<string,mixed> $params
     * @return list<array<string,mixed>>
     */
    private function fetchAll(string $sql, array $params): array
    {
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->execute();
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return $rows;
    }

    /**
     * @param array<string,mixed> $row
     * @param list<string> $keys
     */
    private function firstString(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    /**
     * @param list<string> $values
     */
    private function firstNonEmpty(array $values): string
    {
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    /**
     * @param array<string,mixed> $row
     * @param list<string> $keys
     */
    private function firstNumber(array $row, array $keys): float
    {
        foreach ($keys as $key) {
            $value = (float) ($row[$key] ?? 0);
            if ($value > 0) {
                return $value;
            }
        }
        return 0.0;
    }

    /**
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $geoProfile
     */
    private function composeAddress(array $profile, array $geoProfile): string
    {
        $logradouro = $this->firstNonEmpty([
            $this->firstString($profile, ['logradouro', 'endereco', 'address']),
            $this->firstString($geoProfile, ['logradouro']),
        ]);
        $numero = $this->firstNonEmpty([
            $this->firstString($profile, ['numero', 'endereco_numero']),
            $this->firstString($geoProfile, ['numero']),
        ]);
        $complemento = $this->firstNonEmpty([
            $this->firstString($profile, ['complemento']),
            $this->firstString($geoProfile, ['complemento']),
        ]);

        return trim(implode(' ', array_filter([$logradouro, $numero, $complemento], static fn (string $v): bool => $v !== '')));
    }

    private function normalizeKey(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return '';
        }
        if (function_exists('iconv')) {
            $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($ascii) && $ascii !== '') {
                $value = $ascii;
            }
        }
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9 ]+/', '', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        return trim($value);
    }

    /**
     * @param list<array<string,mixed>> $invoices
     * @param list<array<string,mixed>> $products
     * @param array<string,mixed> $identity
     */
    private function likelyReason(int $martRows, array $invoices, array $products, array $identity): string
    {
        if ($martRows > 0 && $invoices === []) {
            return 'Cliente encontrado no mart_geo, mas sem NF detalhada vinculada por documento_norm/codigo_cliente_omie na canonical.';
        }
        if ($invoices !== [] && $products === []) {
            return 'NFs encontradas, mas sem itens vinculados por nota_key.';
        }
        if (!$identity['resolved']) {
            return 'Identificadores insuficientes no clique do comprador.';
        }
        return 'ok';
    }
}

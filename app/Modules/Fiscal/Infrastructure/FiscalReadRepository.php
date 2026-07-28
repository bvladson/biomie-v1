<?php

declare(strict_types=1);

namespace BIomie\Modules\Fiscal\Infrastructure;

use BIomie\DataPlatform\Silver\Readers\CanonicalDimensionEnricher;
use BIomie\DataPlatform\Silver\Readers\CanonicalDisplayMapper;
use BIomie\DataPlatform\Silver\Readers\CanonicalReadSupport;
use BIomie\Support;
use Closure;
use PDO;
use RuntimeException;

final class FiscalReadRepository
{
    private ?CanonicalDisplayMapper $displayMapper = null;
    private ?CanonicalDimensionEnricher $dimensionEnricher = null;
    private ?CanonicalReadSupport $readSupport = null;

    public function __construct(
        private readonly Closure $pdoResolver,
    ) {
    }

    /**
     * @return array{notes_count:int,merchandise_total:float,invoice_total:float,discount_total:float}
     */
    public function summarizeInvoicedCommercial(string $start, string $end): array
    {
        $summary = $this->summarizeInvoicesCommercial($start, $end);

        return [
            'notes_count'       => (int) ($summary['billed_orders'] ?? 0),
            'merchandise_total' => Support::money((float) ($summary['merchandise_total'] ?? 0)),
            'invoice_total'     => Support::money((float) ($summary['billed_value'] ?? 0)),
            'discount_total'    => Support::money((float) ($summary['discounts_total'] ?? 0)),
        ];
    }

    /**
     * @return array{notes_count:int,merchandise_total:float,invoice_total:float}
     */
    public function summarizeCancelledCommercial(string $start, string $end): array
    {
        $commercialBase = $this->readSupport()->commercialBaseSql('nf');
        $scopeFilter = $this->readSupport()->commercialScopeFilter('nf.');
        $stmt = $this->pdo()->prepare(<<<SQL
            SELECT
                COUNT(*) AS notes_count,
                COALESCE(SUM(nf.valor_mercadorias), 0) AS merchandise_total,
                COALESCE(SUM({$commercialBase}), 0) AS invoice_total
            FROM biomie_notas_fiscais_canonicas nf
            WHERE nf.status_fiscal = 'cancelada'
              AND nf.data_competencia >= :start
              AND nf.data_competencia < DATE_ADD(:end, INTERVAL 1 DAY)
              {$scopeFilter}
        SQL);
        $stmt->execute(['start' => $start, 'end' => $end]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'notes_count'       => (int) ($row['notes_count'] ?? 0),
            'merchandise_total' => Support::money((float) ($row['merchandise_total'] ?? 0)),
            'invoice_total'     => Support::money((float) ($row['invoice_total'] ?? 0)),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function aggregateInvoicedByVendorCommercial(string $start, string $end): array
    {
        $sql = <<<'SQL'
            SELECT
                codigo_vendedor_omie, nome_vendedor,
                COUNT(*) AS notes_count,
                COUNT(DISTINCT COALESCE(NULLIF(codigo_cliente_omie, 0), NULL)) AS customers_count,
                COALESCE(SUM(valor_mercadorias), 0) AS merchandise_total,
                COALESCE(SUM(valor_total), 0) AS invoice_total,
                COALESCE(SUM(valor_descontos), 0) AS discount_total
            FROM biomie_notas_fiscais_canonicas
            WHERE status_fiscal = 'autorizada'
              AND COALESCE(data_competencia, data_emissao) BETWEEN :start AND :end
        SQL . $this->readSupport()->commercialScopeFilter() . <<<'SQL'

            GROUP BY codigo_vendedor_omie, nome_vendedor
            ORDER BY nome_vendedor ASC
        SQL;
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute(['start' => $start, 'end' => $end]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function aggregateCancelledByVendorCommercial(string $start, string $end): array
    {
        $baseSql = $this->readSupport()->commercialBaseSql();
        $sql = <<<SQL
            SELECT
                codigo_vendedor_omie, nome_vendedor,
                COUNT(*) AS notes_count,
                COALESCE(SUM(valor_mercadorias), 0) AS merchandise_total,
                COALESCE(SUM({$baseSql}), 0) AS invoice_total
            FROM biomie_notas_fiscais_canonicas
            WHERE status_fiscal = 'cancelada'
              AND COALESCE(data_competencia, data_emissao) BETWEEN :start AND :end
        SQL . $this->readSupport()->commercialScopeFilter() . <<<'SQL'

            GROUP BY codigo_vendedor_omie, nome_vendedor
            ORDER BY nome_vendedor ASC
        SQL;
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute(['start' => $start, 'end' => $end]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function aggregateInvoicesByVendorCommercial(string $start, string $end): array
    {
        $baseSql = $this->readSupport()->commercialBaseSql();
        $sql = <<<SQL
            SELECT
                codigo_vendedor_omie, nome_vendedor,
                'faturado' AS status_comercial,
                COUNT(*) AS pedidos,
                COALESCE(SUM({$baseSql}), 0) AS total,
                COALESCE(SUM(valor_mercadorias), 0) AS mercadorias,
                COALESCE(SUM(valor_descontos), 0) AS descontos
            FROM biomie_notas_fiscais_canonicas
            WHERE status_fiscal = 'autorizada'
              AND data_competencia BETWEEN :start AND :end
        SQL . $this->readSupport()->commercialScopeFilter() . <<<'SQL'

            GROUP BY codigo_vendedor_omie, nome_vendedor
            ORDER BY nome_vendedor
        SQL;
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute(['start' => $start, 'end' => $end]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function aggregateCancelledVendorStatusCommercial(string $start, string $end): array
    {
        $baseSql = $this->readSupport()->commercialBaseSql();
        $sql = <<<SQL
            SELECT
                codigo_vendedor_omie, nome_vendedor,
                'cancelado' AS status_comercial,
                COUNT(*) AS pedidos,
                COALESCE(SUM({$baseSql}), 0) AS total,
                COALESCE(SUM(valor_mercadorias), 0) AS mercadorias,
                COALESCE(SUM(valor_descontos), 0) AS descontos
            FROM biomie_notas_fiscais_canonicas
            WHERE status_fiscal = 'cancelada'
              AND data_competencia BETWEEN :start AND :end
        SQL . $this->readSupport()->commercialScopeFilter() . <<<'SQL'

            GROUP BY codigo_vendedor_omie, nome_vendedor
            ORDER BY nome_vendedor
        SQL;
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute(['start' => $start, 'end' => $end]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function dailyInvoicedCommercial(string $start, string $end, ?string $vendorId = null): array
    {
        return $this->readSupport()->dailyByFiscalStatus($start, $end, 'autorizada', $vendorId);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function dailyCancelledCommercial(string $start, string $end, ?string $vendorId = null): array
    {
        return $this->readSupport()->dailyByFiscalStatus($start, $end, 'cancelada', $vendorId);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function dailyInvoiceStatusRowsCommercial(string $start, string $end): array
    {
        return $this->readSupport()->dailyFiscalStatusRows($start, $end, 'autorizada', 'faturado');
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function dailyCancelledStatusRowsCommercial(string $start, string $end): array
    {
        return $this->readSupport()->dailyFiscalStatusRows($start, $end, 'cancelada', 'cancelado');
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listInvoicedOrdersForDisplayCommercial(
        string $start,
        string $end,
        ?string $vendorId = null,
        bool $enrichDimensions = true,
    ): array
    {
        $rows = $this->fetchInvoiceRows($start, $end, 'autorizada', $vendorId, $this->readSupport()->commercialScopeFilter('nf.'));
        if ($enrichDimensions) {
            $rows = $this->enrichCustomerSellerIdentityRows($rows);
        }

        return $this->mapInvoiceDisplayRows($rows);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listInvoicesCommercial(string $start, string $end): array
    {
        return $this->fetchInvoiceRows($start, $end, 'autorizada', null, $this->readSupport()->commercialScopeFilter('nf.'));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listCancelledInvoicesCommercial(string $start, string $end): array
    {
        return $this->fetchInvoiceRows($start, $end, 'cancelada', null, $this->readSupport()->commercialScopeFilter('nf.'));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listFiscalDocumentsByIssueDate(string $start, string $end): array
    {
        $tenantFilter = \BIomie\Support\TenantContext::sqlFilter();
        $stmt = $this->pdo()->prepare(<<<SQL
            SELECT *
            FROM biomie_notas_fiscais_canonicas
            WHERE status_fiscal IN ('autorizada', 'cancelada', 'nao_autorizada')
              AND data_emissao BETWEEN :start AND :end
              AND {$tenantFilter}
            ORDER BY data_emissao DESC, numero_nf DESC, serie_nf DESC
        SQL);
        $stmt->execute(['start' => $start, 'end' => $end]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listInvoiceRowsForReconciliation(string $start, string $end): array
    {
        $tenantFilter = \BIomie\Support\TenantContext::sqlFilter();
        $stmt = $this->pdo()->prepare(<<<SQL
            SELECT *
            FROM biomie_notas_fiscais_canonicas
            WHERE {$tenantFilter}
              AND (
                    data_competencia BETWEEN :start AND :end
                 OR (data_emissao IS NOT NULL AND data_emissao BETWEEN :start AND :end)
                 OR (data_cancelamento IS NOT NULL AND data_cancelamento BETWEEN :start AND :end)
              )
            ORDER BY data_competencia DESC, nota_key DESC
        SQL);
        $stmt->execute(['start' => $start, 'end' => $end]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listInvoicesForDisplayCommercial(string $start, string $end): array
    {
        return $this->mapInvoiceDisplayRows($this->enrichCustomerSellerIdentityRows($this->listInvoicesCommercial($start, $end)));
    }

    /**
     * Fase H — NFs com operation_scope = 'remittance'.
     * Retorna lista vazia se o campo ainda não existir (pré-migration H3).
     *
     * @return list<array<string,mixed>>
     */
    public function listInvoicesForDisplayRemittance(string $start, string $end): array
    {
        $scopeFilter = $this->readSupport()->scopeFilter('nf.', 'remittance');
        if ($scopeFilter === '') {
            return [];
        }

        $sql = $this->readSupport()->invoiceSelectSql() . <<<'SQL'
            FROM biomie_notas_fiscais_canonicas nf
            LEFT JOIN biomie_vendedores_omie v
                ON v.codigo_omie = nf.codigo_vendedor_omie
               AND v.company_id = nf.company_id
               AND v.ativo = 1
            WHERE nf.status_fiscal = 'autorizada'
              AND nf.data_competencia BETWEEN :start AND :end
        SQL . ' AND ' . \BIomie\Support\TenantContext::sqlFilter('nf') . $scopeFilter . <<<'SQL'

            ORDER BY nf.data_competencia DESC, nf.numero_nf DESC, nf.serie_nf DESC
        SQL;

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute(['start' => $start, 'end' => $end]);

        return $this->mapInvoiceDisplayRows($this->enrichCustomerSellerIdentityRows($stmt->fetchAll(\PDO::FETCH_ASSOC)));
    }

    public function operationScopeAvailable(): bool
    {
        return $this->readSupport()->operationScopeAvailable();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listTopInvoicesForDisplayCommercial(string $start, string $end, int $limit = 8): array
    {
        $sql = $this->readSupport()->invoiceSelectSql() . <<<'SQL'
            FROM biomie_notas_fiscais_canonicas nf
            LEFT JOIN biomie_vendedores_omie v
                ON v.codigo_omie = nf.codigo_vendedor_omie
               AND v.company_id = nf.company_id
               AND v.ativo = 1
            WHERE nf.status_fiscal = 'autorizada'
              AND nf.data_competencia BETWEEN :start AND :end
        SQL . $this->readSupport()->commercialScopeFilter('nf.') . <<<'SQL'

            ORDER BY nf.valor_total DESC, nf.data_competencia DESC, nf.numero_nf DESC
            LIMIT :limit
        SQL;
        $stmt = $this->pdo()->prepare($sql);
        $stmt->bindValue('start', $start);
        $stmt->bindValue('end', $end);
        $stmt->bindValue('limit', min(max($limit, 1), 50), PDO::PARAM_INT);
        $stmt->execute();

        return $this->mapInvoiceDisplayRows($this->enrichCustomerSellerIdentityRows($stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listCancelledInvoicesForDisplayCommercial(string $start, string $end): array
    {
        return $this->mapInvoiceDisplayRows($this->enrichCustomerSellerIdentityRows($this->listCancelledInvoicesCommercial($start, $end)));
    }

    /**
     * @return array<string,int|float>
     */
    public function summarizeInvoicesCommercial(string $start, string $end): array
    {
        // Fase O: usar valor_base_comercial (valor_total - frete - outras - seguro) como receita comercial.
        $baseSql = $this->readSupport()->commercialBaseSql();
        $sql = "
            SELECT
                COUNT(*) AS pedidos,
                COALESCE(SUM({$baseSql}), 0) AS total,
                COALESCE(SUM(valor_total), 0) AS total_nf_bruto,
                COALESCE(SUM(valor_mercadorias), 0) AS mercadorias,
                COALESCE(SUM(valor_descontos), 0) AS descontos,
                COUNT(DISTINCT CASE
                    WHEN NULLIF(TRIM(COALESCE(codigo_vendedor_omie, '')), '') IS NOT NULL
                        THEN CONCAT(company_id, ':id:', TRIM(codigo_vendedor_omie))
                    WHEN NULLIF(TRIM(COALESCE(nome_vendedor, '')), '') IS NOT NULL
                        THEN CONCAT(company_id, ':name:', TRIM(nome_vendedor))
                    ELSE NULL
                END) AS vendedores,
                COUNT(DISTINCT CASE
                    WHEN codigo_cliente_omie IS NOT NULL AND codigo_cliente_omie > 0 THEN CONCAT(company_id, ':id:', codigo_cliente_omie)
                    WHEN NULLIF(TRIM(COALESCE(documento_cliente, '')), '') IS NOT NULL THEN CONCAT(company_id, ':doc:', TRIM(COALESCE(documento_cliente, '')))
                    WHEN NULLIF(TRIM(COALESCE(nome_cliente, '')), '') IS NOT NULL THEN CONCAT(company_id, ':name:', TRIM(COALESCE(nome_cliente, '')))
                    ELSE NULL
                END) AS clientes
            FROM biomie_notas_fiscais_canonicas
            WHERE status_fiscal = 'autorizada'
              AND data_competencia BETWEEN :start AND :end
        " . $this->readSupport()->commercialScopeFilter();
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute(['start' => $start, 'end' => $end]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'billed_value'      => (float) ($row['total'] ?? 0),          // Fase O: valor_base_comercial
            'billed_value_nf'   => (float) ($row['total_nf_bruto'] ?? 0), // valor_total bruto (diagnóstico)
            'billed_orders'     => (int) ($row['pedidos'] ?? 0),
            'authorized_value'  => 0.0,
            'authorized_orders' => 0,
            'cancelled_value'   => 0.0,
            'cancelled_orders'  => 0,
            'merchandise_total' => (float) ($row['mercadorias'] ?? 0),
            'discounts_total'   => (float) ($row['descontos'] ?? 0),
            'active_vendors'    => (int) ($row['vendedores'] ?? 0),
            'active_clients'    => (int) ($row['clientes'] ?? 0),
        ];
    }

    /**
     * Resumo faturado agrupado por empresa (board consolidado / TV).
     *
     * Quando $channelsByCompany é informado, cada empresa é filtrada pelos canais
     * efetivamente autorizados para o usuário (varejo/licitação). Isso é
     * indispensável no board: is_biomie_commercial_visible representa o recorte
     * do dashboard comercial e não o resultado executivo de todas as empresas.
     *
     * Sem o mapa, preserva o recorte comercial legado para os demais leitores.
     *
     * @param list<int> $companyIds
     * @param array<int,list<string>> $channelsByCompany
     * @param array<int,list<string>> $sellerIdsByCompany
     * @return array<int,array{
     *   company_id:int,
     *   billed_value:float,
     *   billed_orders:int,
     *   merchandise_total:float,
     *   active_vendors:int,
     *   active_clients:int,
     *   retail_value:float,
     *   bidding_value:float,
     *   last_source_at:?string
     * }>
     */
    public function summarizeInvoicesByCompanies(
        array $companyIds,
        string $start,
        string $end,
        array $channelsByCompany = [],
        array $sellerIdsByCompany = [],
    ): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $companyIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return [];
        }

        $table   = 'biomie_notas_fiscais_canonicas';
        $baseSql = $this->readSupport()->commercialBaseSql();

        $placeholders = [];
        $params = ['start' => $start, 'end' => $end];
        foreach ($ids as $i => $id) {
            $key = 'c' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }
        $inClause = implode(', ', $placeholders);

        // Mesmo escopo fiscal de summarizeInvoicesCommercial (sem o filtro de
        // tenant da sessão — aqui o company_id é explícito e o board é cross-empresa).
        $scope = '';
        if (\BIomie\SchemaCapabilities::columnExists($this->pdo(), $table, 'operation_scope')) {
            $scope .= " AND operation_scope = 'commercial'";
        }
        $channelAware = $channelsByCompany !== []
            && \BIomie\SchemaCapabilities::columnExists($this->pdo(), $table, 'business_channel');
        if ($channelAware) {
            $hasCommercialVisibility = \BIomie\SchemaCapabilities::columnExists(
                $this->pdo(),
                $table,
                'is_biomie_commercial_visible',
            );
            $channelClauses = [];
            foreach ($ids as $i => $id) {
                $allowed = array_values(array_intersect(
                    ['varejo', 'licitacao'],
                    array_map('strval', $channelsByCompany[$id] ?? []),
                ));
                if ($allowed === []) {
                    continue;
                }

                $companyKey = 'scope_company_' . $i;
                $params[$companyKey] = $id;
                $channelPredicates = [];
                foreach ($allowed as $channelIndex => $channel) {
                    $channelKey = 'scope_channel_' . $i . '_' . $channelIndex;
                    $params[$channelKey] = $channel;
                    $predicate = 'business_channel = :' . $channelKey;
                    // A visibilidade comercial continua valendo para varejo.
                    // Licitação usa sua classificação de canal, não essa flag.
                    if ($channel === 'varejo' && $hasCommercialVisibility) {
                        $predicate .= ' AND COALESCE(is_biomie_commercial_visible, 1) = 1';
                    }
                    $channelPredicates[] = '(' . $predicate . ')';
                }
                $channelClauses[] = sprintf(
                    '(company_id = :%s AND (%s))',
                    $companyKey,
                    implode(' OR ', $channelPredicates),
                );
            }
            $scope .= $channelClauses === []
                ? ' AND 1 = 0'
                : ' AND (' . implode(' OR ', $channelClauses) . ')';
        } elseif (\BIomie\SchemaCapabilities::columnExists($this->pdo(), $table, 'is_biomie_commercial_visible')) {
            $scope .= " AND COALESCE(is_biomie_commercial_visible, 1) = 1";
        }
        $sellerAware = $sellerIdsByCompany !== []
            && \BIomie\SchemaCapabilities::columnExists($this->pdo(), $table, 'codigo_vendedor_omie');
        if ($sellerAware) {
            $sellerClauses = [];
            foreach ($ids as $i => $id) {
                $sellerIds = array_values(array_unique(array_filter(
                    array_map(
                        static fn (mixed $sellerId): string => trim((string) $sellerId),
                        $sellerIdsByCompany[$id] ?? [],
                    ),
                    static fn (string $sellerId): bool => $sellerId !== '',
                )));
                if ($sellerIds === []) {
                    continue;
                }

                $companyKey = 'seller_scope_company_' . $i;
                $params[$companyKey] = $id;
                $sellerPlaceholders = [];
                foreach ($sellerIds as $sellerIndex => $sellerId) {
                    $sellerKey = 'seller_scope_id_' . $i . '_' . $sellerIndex;
                    $params[$sellerKey] = $sellerId;
                    $sellerPlaceholders[] = ':' . $sellerKey;
                }
                $sellerClauses[] = sprintf(
                    '(company_id = :%s AND codigo_vendedor_omie IN (%s))',
                    $companyKey,
                    implode(', ', $sellerPlaceholders),
                );
            }
            $scope .= $sellerClauses === []
                ? ' AND 1 = 0'
                : ' AND (' . implode(' OR ', $sellerClauses) . ')';
        }

        $channelColumns = $channelAware
            ? ",
                COALESCE(SUM(CASE WHEN business_channel = 'varejo' THEN {$baseSql} ELSE 0 END), 0) AS varejo_total,
                COALESCE(SUM(CASE WHEN business_channel = 'licitacao' THEN {$baseSql} ELSE 0 END), 0) AS licitacao_total"
            : ', 0 AS varejo_total, 0 AS licitacao_total';

        $sql = "
            SELECT
                company_id,
                COUNT(*) AS pedidos,
                COALESCE(SUM({$baseSql}), 0) AS total,
                COALESCE(SUM(valor_mercadorias), 0) AS mercadorias,
                COUNT(DISTINCT NULLIF(TRIM(COALESCE(nome_vendedor, '')), '')) AS vendedores,
                COUNT(DISTINCT NULLIF(TRIM(COALESCE(nome_cliente, '')), '')) AS clientes,
                MAX(sincronizado_em) AS last_source_at
                {$channelColumns}
            FROM {$table}
            WHERE status_fiscal = 'autorizada'
              AND data_competencia BETWEEN :start AND :end
              AND company_id IN ({$inClause})
              {$scope}
            GROUP BY company_id
        ";
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cid = (int) ($row['company_id'] ?? 0);
            if ($cid <= 0) {
                continue;
            }
            $out[$cid] = [
                'company_id'        => $cid,
                'billed_value'      => (float) ($row['total'] ?? 0),
                'billed_orders'     => (int) ($row['pedidos'] ?? 0),
                'merchandise_total' => (float) ($row['mercadorias'] ?? 0),
                'active_vendors'    => (int) ($row['vendedores'] ?? 0),
                'active_clients'    => (int) ($row['clientes'] ?? 0),
                'retail_value'      => (float) ($row['varejo_total'] ?? 0),
                'bidding_value'     => (float) ($row['licitacao_total'] ?? 0),
                'last_source_at'    => isset($row['last_source_at']) && $row['last_source_at'] !== ''
                    ? (string) $row['last_source_at']
                    : null,
            ];
        }

        return $out;
    }

    /**
     * Consolida, em uma unica leitura fiscal, todos os periodos usados pelo
     * Painel da Diretoria. Evita cinco round-trips e cinco varreduras da mesma
     * tabela a cada renovacao do cache.
     *
     * @param list<int> $companyIds
     * @param array<int,list<string>> $channelsByCompany
     * @param array<int,list<string>> $monthSellerIdsByCompany
     * @param array<int,list<string>> $annualSellerIdsByCompany
     * @return array{
     *   current:array<int,array<string,mixed>>,
     *   annual:array<int,array<string,mixed>>,
     *   comparison:array<int,array<string,mixed>>,
     *   targeted_current:array<int,array<string,mixed>>,
     *   targeted_annual:array<int,array<string,mixed>>
     * }
     */
    public function summarizeBoardPeriodsByCompanies(
        array $companyIds,
        string $currentStart,
        string $currentEnd,
        string $annualStart,
        string $comparisonStart,
        string $comparisonEnd,
        array $channelsByCompany,
        array $monthSellerIdsByCompany = [],
        array $annualSellerIdsByCompany = [],
    ): array {
        $empty = [
            'current' => [],
            'annual' => [],
            'comparison' => [],
            'targeted_current' => [],
            'targeted_annual' => [],
        ];
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $companyIds),
            static fn (int $id): bool => $id > 0,
        )));
        if ($ids === []) {
            return $empty;
        }

        $table = 'biomie_notas_fiscais_canonicas';
        $baseSql = $this->readSupport()->commercialBaseSql('nf');
        $columns = \BIomie\SchemaCapabilities::columns($this->pdo(), $table);
        foreach (['company_id', 'status_fiscal', 'data_competencia'] as $requiredColumn) {
            if (!isset($columns[$requiredColumn])) {
                throw new RuntimeException(
                    'Schema fiscal indisponivel para consolidar o Painel da Diretoria.',
                );
            }
        }
        $channelAware = $channelsByCompany !== [] && isset($columns['business_channel']);
        $hasCommercialVisibility = isset($columns['is_biomie_commercial_visible']);
        $hasSeller = isset($columns['codigo_vendedor_omie']);

        $params = [
            'current_start' => $currentStart,
            'current_end' => $currentEnd,
            'annual_start' => $annualStart,
            'comparison_start' => $comparisonStart,
            'comparison_end' => $comparisonEnd,
            'scan_start' => min($currentStart, $annualStart, $comparisonStart),
            'scan_end' => max($currentEnd, $comparisonEnd),
        ];
        $companyPlaceholders = [];
        foreach ($ids as $index => $companyId) {
            $key = 'board_company_' . $index;
            $companyPlaceholders[] = ':' . $key;
            $params[$key] = $companyId;
        }

        $scope = isset($columns['operation_scope'])
            ? " AND nf.operation_scope = 'commercial'"
            : '';
        if ($channelAware) {
            $channelClauses = [];
            foreach ($ids as $index => $companyId) {
                $allowed = array_values(array_intersect(
                    ['varejo', 'licitacao'],
                    array_map('strval', $channelsByCompany[$companyId] ?? []),
                ));
                if ($allowed === []) {
                    continue;
                }

                $companyKey = 'board_scope_company_' . $index;
                $params[$companyKey] = $companyId;
                $channelPredicates = [];
                foreach ($allowed as $channelIndex => $channel) {
                    $channelKey = 'board_scope_channel_' . $index . '_' . $channelIndex;
                    $params[$channelKey] = $channel;
                    $predicate = 'nf.business_channel = :' . $channelKey;
                    if ($channel === 'varejo' && $hasCommercialVisibility) {
                        $predicate .= ' AND COALESCE(nf.is_biomie_commercial_visible, 1) = 1';
                    }
                    $channelPredicates[] = '(' . $predicate . ')';
                }
                $channelClauses[] = sprintf(
                    '(nf.company_id = :%s AND (%s))',
                    $companyKey,
                    implode(' OR ', $channelPredicates),
                );
            }
            $scope .= $channelClauses === []
                ? ' AND 1 = 0'
                : ' AND (' . implode(' OR ', $channelClauses) . ')';
        } elseif ($hasCommercialVisibility) {
            $scope .= ' AND COALESCE(nf.is_biomie_commercial_visible, 1) = 1';
        }

        $sellerPredicate = function (array $sellerIdsByCompany, string $prefix) use (
            $ids,
            $hasSeller,
            &$params,
        ): string {
            if (!$hasSeller || $sellerIdsByCompany === []) {
                return '1 = 0';
            }

            $clauses = [];
            foreach ($ids as $index => $companyId) {
                $sellerIds = array_values(array_unique(array_filter(
                    array_map(
                        static fn (mixed $sellerId): string => trim((string) $sellerId),
                        $sellerIdsByCompany[$companyId] ?? [],
                    ),
                    static fn (string $sellerId): bool => $sellerId !== '',
                )));
                if ($sellerIds === []) {
                    continue;
                }

                $companyKey = $prefix . '_company_' . $index;
                $params[$companyKey] = $companyId;
                $sellerPlaceholders = [];
                foreach ($sellerIds as $sellerIndex => $sellerId) {
                    $sellerKey = $prefix . '_seller_' . $index . '_' . $sellerIndex;
                    $params[$sellerKey] = $sellerId;
                    $sellerPlaceholders[] = ':' . $sellerKey;
                }
                $clauses[] = sprintf(
                    '(nf.company_id = :%s AND nf.codigo_vendedor_omie IN (%s))',
                    $companyKey,
                    implode(', ', $sellerPlaceholders),
                );
            }

            return $clauses === [] ? '1 = 0' : '(' . implode(' OR ', $clauses) . ')';
        };
        // Native MySQL prepares do not allow the same named placeholder to be
        // referenced more than once. Build independent predicates for the
        // order counters and revenue totals.
        $monthSellerCountScope = $sellerPredicate($monthSellerIdsByCompany, 'board_month_target_count');
        $annualSellerCountScope = $sellerPredicate($annualSellerIdsByCompany, 'board_annual_target_count');
        $monthSellerTotalScope = $sellerPredicate($monthSellerIdsByCompany, 'board_month_target_total');
        $annualSellerTotalScope = $sellerPredicate($annualSellerIdsByCompany, 'board_annual_target_total');

        $retailColumns = $channelAware
            ? ",
                COALESCE(SUM(CASE
                    WHEN nf.data_competencia BETWEEN bounds.current_start AND bounds.current_end
                     AND nf.business_channel = 'varejo'
                    THEN {$baseSql} ELSE 0 END), 0) AS current_retail_total,
                COALESCE(SUM(CASE
                    WHEN nf.data_competencia BETWEEN bounds.current_start AND bounds.current_end
                     AND nf.business_channel = 'licitacao'
                    THEN {$baseSql} ELSE 0 END), 0) AS current_bidding_total,
                COALESCE(SUM(CASE
                    WHEN nf.data_competencia BETWEEN bounds.annual_start AND bounds.current_end
                     AND nf.business_channel = 'varejo'
                    THEN {$baseSql} ELSE 0 END), 0) AS annual_retail_total,
                COALESCE(SUM(CASE
                    WHEN nf.data_competencia BETWEEN bounds.annual_start AND bounds.current_end
                     AND nf.business_channel = 'licitacao'
                    THEN {$baseSql} ELSE 0 END), 0) AS annual_bidding_total"
            : ",
                0 AS current_retail_total,
                0 AS current_bidding_total,
                0 AS annual_retail_total,
                0 AS annual_bidding_total";

        $sql = "
            SELECT
                nf.company_id,
                COUNT(CASE
                    WHEN nf.data_competencia BETWEEN bounds.current_start AND bounds.current_end
                    THEN 1 ELSE NULL END) AS current_orders,
                COUNT(CASE
                    WHEN nf.data_competencia BETWEEN bounds.annual_start AND bounds.current_end
                    THEN 1 ELSE NULL END) AS annual_orders,
                COUNT(CASE
                    WHEN nf.data_competencia BETWEEN bounds.comparison_start AND bounds.comparison_end
                    THEN 1 ELSE NULL END) AS comparison_orders,
                COUNT(CASE
                    WHEN nf.data_competencia BETWEEN bounds.current_start AND bounds.current_end
                     AND {$monthSellerCountScope}
                    THEN 1 ELSE NULL END) AS targeted_current_orders,
                COUNT(CASE
                    WHEN nf.data_competencia BETWEEN bounds.annual_start AND bounds.current_end
                     AND {$annualSellerCountScope}
                    THEN 1 ELSE NULL END) AS targeted_annual_orders,
                COALESCE(SUM(CASE
                    WHEN nf.data_competencia BETWEEN bounds.current_start AND bounds.current_end
                    THEN {$baseSql} ELSE 0 END), 0) AS current_total,
                COALESCE(SUM(CASE
                    WHEN nf.data_competencia BETWEEN bounds.current_start AND bounds.current_end
                    THEN nf.valor_mercadorias ELSE 0 END), 0) AS current_merchandise,
                COUNT(DISTINCT CASE
                    WHEN nf.data_competencia BETWEEN bounds.current_start AND bounds.current_end
                    THEN NULLIF(TRIM(COALESCE(nf.nome_vendedor, '')), '') ELSE NULL END
                ) AS current_vendors,
                COUNT(DISTINCT CASE
                    WHEN nf.data_competencia BETWEEN bounds.current_start AND bounds.current_end
                    THEN NULLIF(TRIM(COALESCE(nf.nome_cliente, '')), '') ELSE NULL END
                ) AS current_clients,
                COALESCE(SUM(CASE
                    WHEN nf.data_competencia BETWEEN bounds.annual_start AND bounds.current_end
                    THEN {$baseSql} ELSE 0 END), 0) AS annual_total,
                COALESCE(SUM(CASE
                    WHEN nf.data_competencia BETWEEN bounds.comparison_start AND bounds.comparison_end
                    THEN {$baseSql} ELSE 0 END), 0) AS comparison_total,
                COALESCE(SUM(CASE
                    WHEN nf.data_competencia BETWEEN bounds.current_start AND bounds.current_end
                     AND {$monthSellerTotalScope}
                    THEN {$baseSql} ELSE 0 END), 0) AS targeted_current_total,
                COALESCE(SUM(CASE
                    WHEN nf.data_competencia BETWEEN bounds.annual_start AND bounds.current_end
                     AND {$annualSellerTotalScope}
                    THEN {$baseSql} ELSE 0 END), 0) AS targeted_annual_total,
                MAX(CASE
                    WHEN nf.data_competencia BETWEEN bounds.annual_start AND bounds.current_end
                    THEN nf.sincronizado_em ELSE NULL END) AS last_source_at
                {$retailColumns}
            FROM {$table} nf
            CROSS JOIN (
                SELECT
                    CAST(:current_start AS DATE) AS current_start,
                    CAST(:current_end AS DATE) AS current_end,
                    CAST(:annual_start AS DATE) AS annual_start,
                    CAST(:comparison_start AS DATE) AS comparison_start,
                    CAST(:comparison_end AS DATE) AS comparison_end,
                    CAST(:scan_start AS DATE) AS scan_start,
                    CAST(:scan_end AS DATE) AS scan_end
            ) bounds
            WHERE nf.status_fiscal = 'autorizada'
              AND nf.data_competencia BETWEEN bounds.scan_start AND bounds.scan_end
              AND nf.company_id IN (" . implode(', ', $companyPlaceholders) . ")
              {$scope}
            GROUP BY nf.company_id
        ";
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);

        $out = $empty;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $companyId = (int) ($row['company_id'] ?? 0);
            if ($companyId <= 0) {
                continue;
            }
            $lastSourceAt = isset($row['last_source_at']) && $row['last_source_at'] !== ''
                ? (string) $row['last_source_at']
                : null;
            if ((int) ($row['current_orders'] ?? 0) > 0) {
                $out['current'][$companyId] = [
                    'company_id' => $companyId,
                    'billed_value' => (float) ($row['current_total'] ?? 0),
                    'billed_orders' => (int) ($row['current_orders'] ?? 0),
                    'merchandise_total' => (float) ($row['current_merchandise'] ?? 0),
                    'active_vendors' => (int) ($row['current_vendors'] ?? 0),
                    'active_clients' => (int) ($row['current_clients'] ?? 0),
                    'retail_value' => (float) ($row['current_retail_total'] ?? 0),
                    'bidding_value' => (float) ($row['current_bidding_total'] ?? 0),
                    'last_source_at' => $lastSourceAt,
                ];
            }
            if ((int) ($row['annual_orders'] ?? 0) > 0) {
                $out['annual'][$companyId] = [
                    'company_id' => $companyId,
                    'billed_value' => (float) ($row['annual_total'] ?? 0),
                    'retail_value' => (float) ($row['annual_retail_total'] ?? 0),
                    'bidding_value' => (float) ($row['annual_bidding_total'] ?? 0),
                    'last_source_at' => $lastSourceAt,
                ];
            }
            if ((int) ($row['comparison_orders'] ?? 0) > 0) {
                $out['comparison'][$companyId] = [
                    'company_id' => $companyId,
                    'billed_value' => (float) ($row['comparison_total'] ?? 0),
                ];
            }
            if ((int) ($row['targeted_current_orders'] ?? 0) > 0) {
                $out['targeted_current'][$companyId] = [
                    'company_id' => $companyId,
                    'billed_value' => (float) ($row['targeted_current_total'] ?? 0),
                ];
            }
            if ((int) ($row['targeted_annual_orders'] ?? 0) > 0) {
                $out['targeted_annual'][$companyId] = [
                    'company_id' => $companyId,
                    'billed_value' => (float) ($row['targeted_annual_total'] ?? 0),
                ];
            }
        }

        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchInvoiceRows(string $start, string $end, string $status, ?string $vendorId = null, string $extraWhere = ''): array
    {
        $sql = $this->readSupport()->invoiceSelectSql() . <<<'SQL'
            FROM biomie_notas_fiscais_canonicas nf
            LEFT JOIN biomie_vendedores_omie v
                ON v.codigo_omie = nf.codigo_vendedor_omie
               AND v.company_id = nf.company_id
               AND v.ativo = 1
            WHERE nf.status_fiscal = :status
              AND nf.data_competencia BETWEEN :start AND :end
        SQL;

        if ($extraWhere !== '') {
            $sql .= $extraWhere;
        }

        $params = ['status' => $status, 'start' => $start, 'end' => $end];
        if ($vendorId !== null && $vendorId !== '') {
            $sql .= ' AND nf.codigo_vendedor_omie = :vendor_id';
            $params['vendor_id'] = $vendorId;
        }
        $sql .= ' ORDER BY nf.data_competencia DESC, nf.numero_nf DESC, nf.serie_nf DESC';

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function enrichCustomerSellerIdentityRows(array $rows): array
    {
        return $this->dimensionEnricher()->enrichCustomerSellerIdentityRows($rows);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function mapInvoiceDisplayRows(array $rows): array
    {
        return $this->displayMapper()->mapInvoiceRows($rows);
    }

    private function displayMapper(): CanonicalDisplayMapper
    {
        return $this->displayMapper ??= new CanonicalDisplayMapper();
    }

    private function dimensionEnricher(): CanonicalDimensionEnricher
    {
        return $this->dimensionEnricher ??= new CanonicalDimensionEnricher($this->pdoResolver);
    }

    private function readSupport(): CanonicalReadSupport
    {
        return $this->readSupport ??= new CanonicalReadSupport($this->pdoResolver);
    }

    private function pdo(): PDO
    {
        $pdo = ($this->pdoResolver)();
        if (!$pdo instanceof PDO) {
            throw new RuntimeException('PDO resolver returned an invalid connection.');
        }

        return $pdo;
    }
}

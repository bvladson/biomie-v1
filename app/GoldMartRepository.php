<?php

declare(strict_types=1);

namespace BIomie;

use BIomie\Support\TenantContext;
use PDO;
use Throwable;

/**
 * Leitura da camada gold (mart_*).
 *
 * Fornece os mesmos dados que CanonicalRepository::aggregate*Commercial()
 * mas a partir de tabelas pré-computadas, eliminando GROUP BY em tempo
 * de requisição.
 *
 * Todas as consultas retornam [] quando o mart não está disponível — o
 * chamador decide se faz fallback para CanonicalRepository.
 *
 * ISOLAMENTO MULTI-EMPRESA: TODA consulta filtra por company_id via
 * TenantContext::sqlFilter() (company_id = N, ou IN (...) no modo
 * consolidado). Sem o tenant carregado, sqlFilter() lança e o try/catch
 * devolve [] — falha FECHADA (nunca vaza dados de outra empresa).
 */
final class GoldMartRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Logger $logger,
    ) {
    }

    // ── Disponibilidade ──────────────────────────────────────────────────────

    /**
     * Retorna true se o mart de vendedor_dia tem linhas para o período.
     */
    public function isAvailableForPeriod(string $start, string $end): bool
    {
        try {
            $scope = $this->martScope();
            $stmt = $this->pdo->prepare(
                "SELECT 1
                   FROM mart_vendedor_dia
                  WHERE {$scope}
                    AND dia BETWEEN :availability_start AND :availability_end
                    AND (nfes_emitidas > 0 OR valor_nfe <> 0)
                  LIMIT 1"
            );
            $stmt->execute([
                'availability_start' => $start,
                'availability_end' => $end,
            ]);
            return $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            return false;
        }
    }

    // ── Equivalentes de CanonicalRepository (mesma assinatura de retorno) ───

    /**
     * Equivale a CanonicalRepository::aggregateByVendorCommercial().
     * Retorna rows com (codigo_vendedor_omie, nome_vendedor, status_comercial, total, mercadorias, descontos, pedidos).
     *
     * @return list<array<string,mixed>>
     */
    public function aggregateByVendorCommercial(string $start, string $end): array
    {
        try {
            $scope = $this->martScope();
            $stmt = $this->pdo->prepare(<<<SQL
                SELECT
                    codigo_vendedor_omie,
                    nome_vendedor,
                    'faturado'                   AS status_comercial,
                    SUM(valor_nfe)               AS total,
                    SUM(mercadorias_nfe)         AS mercadorias,
                    SUM(descontos_faturado)      AS descontos,
                    SUM(nfes_emitidas)            AS pedidos
                FROM mart_vendedor_dia
                WHERE {$scope} AND dia BETWEEN :billed_start AND :billed_end
                GROUP BY codigo_vendedor_omie, nome_vendedor
                HAVING SUM(nfes_emitidas) > 0 OR SUM(valor_nfe) <> 0

                UNION ALL

                SELECT
                    codigo_vendedor_omie,
                    nome_vendedor,
                    'autorizado'                 AS status_comercial,
                    SUM(valor_autorizado)        AS total,
                    SUM(mercadorias_autorizadas) AS mercadorias,
                    0                            AS descontos,
                    SUM(pedidos_autorizados)     AS pedidos
                FROM mart_vendedor_dia
                WHERE {$scope} AND dia BETWEEN :authorized_start AND :authorized_end
                GROUP BY codigo_vendedor_omie, nome_vendedor
                HAVING SUM(pedidos_autorizados) > 0

                UNION ALL

                SELECT
                    codigo_vendedor_omie,
                    nome_vendedor,
                    'cancelado'                  AS status_comercial,
                    SUM(valor_cancelado)         AS total,
                    0                            AS mercadorias,
                    0                            AS descontos,
                    SUM(pedidos_cancelados)      AS pedidos
                FROM mart_vendedor_dia
                WHERE {$scope} AND dia BETWEEN :cancelled_start AND :cancelled_end
                GROUP BY codigo_vendedor_omie, nome_vendedor
                HAVING SUM(pedidos_cancelados) > 0
            SQL);
            $stmt->execute([
                'billed_start' => $start,
                'billed_end' => $end,
                'authorized_start' => $start,
                'authorized_end' => $end,
                'cancelled_start' => $start,
                'cancelled_end' => $end,
            ]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $this->logger->warning('GoldMartRepository::aggregateByVendorCommercial falhou.', ['message' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Equivale a CanonicalRepository::dailyByStatusCommercial().
     * Retorna rows com (dia, status_comercial, total, mercadorias, pedidos).
     *
     * @return list<array<string,mixed>>
     */
    public function dailyByStatusCommercial(string $start, string $end): array
    {
        try {
            $scope = $this->martScope();
            $stmt = $this->pdo->prepare(<<<SQL
                SELECT
                    dia,
                    'faturado'                   AS status_comercial,
                    SUM(valor_nfe)               AS total,
                    SUM(mercadorias_nfe)         AS mercadorias,
                    SUM(nfes_emitidas)            AS pedidos
                FROM mart_vendedor_dia
                WHERE {$scope} AND dia BETWEEN :billed_start AND :billed_end
                GROUP BY dia
                HAVING SUM(nfes_emitidas) > 0 OR SUM(valor_nfe) <> 0

                UNION ALL

                SELECT
                    dia,
                    'autorizado'                 AS status_comercial,
                    SUM(valor_autorizado)        AS total,
                    SUM(mercadorias_autorizadas) AS mercadorias,
                    SUM(pedidos_autorizados)     AS pedidos
                FROM mart_vendedor_dia
                WHERE {$scope} AND dia BETWEEN :authorized_start AND :authorized_end
                GROUP BY dia
                HAVING SUM(pedidos_autorizados) > 0

                UNION ALL

                SELECT
                    dia,
                    'cancelado'                  AS status_comercial,
                    SUM(valor_cancelado)         AS total,
                    0                            AS mercadorias,
                    SUM(pedidos_cancelados)      AS pedidos
                FROM mart_vendedor_dia
                WHERE {$scope} AND dia BETWEEN :cancelled_start AND :cancelled_end
                GROUP BY dia
                HAVING SUM(pedidos_cancelados) > 0
            SQL);
            $stmt->execute([
                'billed_start' => $start,
                'billed_end' => $end,
                'authorized_start' => $start,
                'authorized_end' => $end,
                'cancelled_start' => $start,
                'cancelled_end' => $end,
            ]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $this->logger->warning('GoldMartRepository::dailyByStatusCommercial falhou.', ['message' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Equivale a CanonicalRepository::summarizeCommercial().
     * Retorna um único row com totais por status.
     *
     * @return array<string,mixed>
     */
    public function summarizeCommercial(string $start, string $end): array
    {
        try {
            $scope = $this->martScope();
            $stmt = $this->pdo->prepare(<<<SQL
                SELECT
                    SUM(valor_nfe)           AS billed_value,
                    SUM(nfes_emitidas)        AS billed_orders,
                    SUM(valor_autorizado)    AS authorized_value,
                    SUM(pedidos_autorizados) AS authorized_orders,
                    SUM(valor_cancelado)     AS cancelled_value,
                    SUM(pedidos_cancelados)  AS cancelled_orders
                FROM mart_vendedor_dia
                WHERE {$scope} AND dia BETWEEN :start AND :end
            SQL);
            $stmt->execute(['start' => $start, 'end' => $end]);
            $summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $summary['active_clients'] = $this->countActiveClients($scope, $start, $end);

            return $summary;
        } catch (Throwable $e) {
            $this->logger->warning('GoldMartRepository::summarizeCommercial falhou.', ['message' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Equivale a CanonicalRepository::summarizeInvoicesCommercial().
     * Retorna totais das NF-e emitidas no período.
     *
     * @return array<string,mixed>
     */
    public function summarizeInvoicesCommercial(string $start, string $end): array
    {
        try {
            $scope = $this->martScope();
            $stmt = $this->pdo->prepare(<<<SQL
                SELECT
                    SUM(valor_nfe)                  AS billed_value,
                    SUM(nfes_emitidas)              AS billed_orders,
                    SUM(mercadorias_nfe)            AS merchandise_total,
                    SUM(descontos_faturado)         AS discounts_total,
                    COUNT(DISTINCT codigo_vendedor_omie) AS active_vendors
                FROM mart_vendedor_dia
                WHERE {$scope} AND dia BETWEEN :start AND :end
                  AND (nfes_emitidas > 0 OR valor_nfe <> 0)
            SQL);
            $stmt->execute(['start' => $start, 'end' => $end]);
            $summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $summary['active_clients'] = $this->countActiveClients($scope, $start, $end);

            return $summary;
        } catch (Throwable $e) {
            $this->logger->warning('GoldMartRepository::summarizeInvoicesCommercial falhou.', ['message' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Equivale a CanonicalRepository::topClientsCommercial().
     *
     * @return list<array<string,mixed>>
     */
    public function topClientsCommercial(string $start, string $end, int $limit = 8): array
    {
        try {
            $scope = $this->martScope();
            $competencias = $this->competenciasInRange($start, $end);
            if ($competencias === []) {
                return [];
            }

            $placeholders = implode(',', array_map(static fn (int $i): string => ':c' . $i, array_keys($competencias)));
            $params = [];
            foreach ($competencias as $i => $comp) {
                $params['c' . $i] = $comp;
            }

            $stmt = $this->pdo->prepare(sprintf(
                <<<SQL
                    SELECT
                        codigo_cliente_omie,
                        nome_cliente                 AS client_name,
                        documento_cliente            AS client_document,
                        SUM(nfes_emitidas)           AS notes_count,
                        SUM(valor_nfe)               AS invoice_total,
                        SUM(mercadorias_faturado)    AS merchandise_total,
                        0                            AS discount_total
                    FROM mart_cliente_periodo
                    WHERE {$scope}
                      AND competencia IN (%s)
                      AND codigo_cliente_omie IS NOT NULL
                    GROUP BY codigo_cliente_omie, client_name, client_document
                    ORDER BY invoice_total DESC
                    LIMIT :limit
                SQL,
                $placeholders
            ));
            $params['limit'] = $limit;
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $this->logger->warning('GoldMartRepository::topClientsCommercial falhou.', ['message' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Equivale a CanonicalRepository::topProductsCommercial().
     *
     * @return list<array<string,mixed>>
     */
    public function topProductsCommercial(string $start, string $end, int $limit = 12): array
    {
        try {
            $scope = $this->martScope();
            $competencias = $this->competenciasInRange($start, $end);
            if ($competencias === []) {
                return [];
            }

            $placeholders = implode(',', array_map(static fn (int $i): string => ':c' . $i, array_keys($competencias)));
            $params = [];
            foreach ($competencias as $i => $comp) {
                $params['c' . $i] = $comp;
            }

            $stmt = $this->pdo->prepare(sprintf(
                <<<SQL
                    SELECT
                        codigo_produto   AS product_code,
                        descricao_produto AS description,
                        SUM(quantidade)  AS quantity,
                        SUM(valor_total) AS revenue,
                        SUM(nfes_count)  AS orders
                    FROM mart_produto_periodo
                    WHERE {$scope}
                      AND competencia IN (%s)
                    GROUP BY codigo_produto, descricao_produto
                    ORDER BY revenue DESC
                    LIMIT :limit
                SQL,
                $placeholders
            ));
            $params['limit'] = $limit;
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $this->logger->warning('GoldMartRepository::topProductsCommercial falhou.', ['message' => $e->getMessage()]);
            return [];
        }
    }

    // ── Bridge: visão cross-domain ───────────────────────────────────────────

    /**
     * Sumário da ponte entre os três domínios para o período.
     * Expõe explicitamente as divergências cross-domain.
     *
     * @return array<string,mixed>
     */
    public function bridgeSummary(string $start, string $end): array
    {
        try {
            $scope = $this->martScope();
            $stmt = $this->pdo->prepare(<<<SQL
                SELECT
                    COUNT(*)                                          AS total_pedidos,
                    SUM(bridge_status = 'completo')                  AS pedidos_completos,
                    SUM(bridge_status = 'pedido_sem_nf')             AS pedidos_sem_nf,
                    SUM(bridge_status = 'nf_sem_titulo')             AS nf_sem_titulo,
                    SUM(bridge_status = 'nf_cancelada')              AS nf_cancelada,
                    SUM(bridge_status = 'cancelado')                 AS cancelados,
                    SUM(valor_pedido)                                AS valor_pedido_total,
                    SUM(valor_nfe)                                   AS valor_nfe_total,
                    SUM(valor_recebido)                              AS valor_recebido_total,
                    SUM(valor_aberto)                                AS valor_aberto_total,
                    SUM(IF(bridge_status = 'pedido_sem_nf', valor_pedido, 0)) AS valor_sem_cobertura_fiscal,
                    SUM(IF(bridge_status = 'nf_sem_titulo', valor_nfe, 0))   AS valor_sem_cobertura_financeira
                FROM mart_bridge_pedido_nfe_titulo
                WHERE {$scope}
                  AND COALESCE(data_faturamento, data_emissao) BETWEEN :start AND :end
            SQL);
            $stmt->execute(['start' => $start, 'end' => $end]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $this->logger->warning('GoldMartRepository::bridgeSummary falhou.', ['message' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Linhas da bridge com status divergente (pedido_sem_nf, nf_sem_titulo).
     * Usado no endpoint de divergências e no comparativo executivo.
     *
     * @return list<array<string,mixed>>
     */
    public function bridgeDivergences(string $start, string $end, int $limit = 50): array
    {
        try {
            $scope = $this->martScope();
            $stmt = $this->pdo->prepare(<<<SQL
                SELECT
                    pedido_id_omie, codigo_pedido,
                    codigo_vendedor_omie, nome_vendedor,
                    nome_cliente,
                    status_comercial, bridge_status,
                    data_emissao, data_faturamento,
                    valor_pedido, valor_nfe,
                    valor_aberto, valor_recebido
                FROM mart_bridge_pedido_nfe_titulo
                WHERE {$scope}
                  AND bridge_status NOT IN ('completo', 'cancelado')
                  AND COALESCE(data_faturamento, data_emissao) BETWEEN :start AND :end
                ORDER BY valor_pedido DESC
                LIMIT :limit
            SQL);
            $stmt->execute(['start' => $start, 'end' => $end, 'limit' => $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $this->logger->warning('GoldMartRepository::bridgeDivergences falhou.', ['message' => $e->getMessage()]);
            return [];
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Escopo fail-closed dos marts comerciais.
     *
     * A materializacao preserva todos os canais. A leitura combina o tenant
     * com o canal ativo; o consolidado segue o contrato canonico de varejo.
     */
    private function martScope(string $alias = ''): string
    {
        $prefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';
        // Todas as operações: os DOIS canais do mart (rows já são o universo
        // comercial elegível — remessa fora na materialização). Sem isto, mixed
        // cairia no ramo varejo e esconderia licitação nos cards/overview.
        if (TenantContext::isMixed()) {
            return TenantContext::sqlFilter($alias)
                . " AND {$prefix}business_channel IN ('licitacao', 'varejo')";
        }
        $channel = TenantContext::isLicitacao() ? 'licitacao' : 'varejo';

        return TenantContext::sqlFilter($alias)
            . " AND {$prefix}business_channel = '{$channel}'";
    }

    /**
     * Vendor-day marts cannot express distinct customers. The customer-period
     * mart uses the same tenant/channel scope and keeps Gold summary semantics
     * aligned with the canonical identity fallback.
     */
    private function countActiveClients(string $scope, string $start, string $end): int
    {
        $stmt = $this->pdo->prepare(<<<SQL
            SELECT COUNT(DISTINCT CASE
                WHEN codigo_cliente_omie IS NOT NULL AND codigo_cliente_omie > 0
                    THEN CONCAT(company_id, ':id:', codigo_cliente_omie)
                WHEN NULLIF(TRIM(COALESCE(documento_cliente, '')), '') IS NOT NULL
                    THEN CONCAT(company_id, ':doc:', TRIM(documento_cliente))
                WHEN NULLIF(TRIM(COALESCE(nome_cliente, '')), '') IS NOT NULL
                    THEN CONCAT(company_id, ':name:', TRIM(nome_cliente))
                ELSE NULL
            END)
            FROM mart_cliente_periodo
            WHERE {$scope}
              AND competencia >= DATE_FORMAT(:client_start, '%Y-%m')
              AND competencia <= DATE_FORMAT(:client_end, '%Y-%m')
              AND (nfes_emitidas > 0 OR valor_nfe <> 0)
        SQL);
        $stmt->execute(['client_start' => $start, 'client_end' => $end]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return list<string>
     */
    private function competenciasInRange(string $start, string $end): array
    {
        $startDate = \DateTimeImmutable::createFromFormat('Y-m-d', $start) ?: new \DateTimeImmutable($start);
        $endDate   = \DateTimeImmutable::createFromFormat('Y-m-d', $end)   ?: new \DateTimeImmutable($end);
        $cursor    = $startDate->modify('first day of this month');
        $result    = [];

        while ($cursor <= $endDate) {
            $result[] = $cursor->format('Y-m');
            $cursor   = $cursor->modify('+1 month');
        }

        return array_values(array_unique($result));
    }
}

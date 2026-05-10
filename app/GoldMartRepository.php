<?php

declare(strict_types=1);

namespace BIomie;

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
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM mart_vendedor_dia WHERE dia BETWEEN :start AND :end LIMIT 1'
            );
            $stmt->execute(['start' => $start, 'end' => $end]);
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
            $stmt = $this->pdo->prepare(<<<'SQL'
                SELECT
                    codigo_vendedor_omie,
                    nome_vendedor,
                    'faturado'                   AS status_comercial,
                    SUM(valor_faturado)          AS total,
                    SUM(mercadorias_faturado)    AS mercadorias,
                    SUM(descontos_faturado)      AS descontos,
                    SUM(pedidos_faturados)       AS pedidos
                FROM mart_vendedor_dia
                WHERE dia BETWEEN :start AND :end
                GROUP BY codigo_vendedor_omie, nome_vendedor
                HAVING SUM(pedidos_faturados) > 0

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
                WHERE dia BETWEEN :start AND :end
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
                WHERE dia BETWEEN :start AND :end
                GROUP BY codigo_vendedor_omie, nome_vendedor
                HAVING SUM(pedidos_cancelados) > 0
            SQL);
            $stmt->execute(['start' => $start, 'end' => $end]);
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
            $stmt = $this->pdo->prepare(<<<'SQL'
                SELECT
                    dia,
                    'faturado'                   AS status_comercial,
                    SUM(valor_faturado)          AS total,
                    SUM(mercadorias_faturado)    AS mercadorias,
                    SUM(pedidos_faturados)       AS pedidos
                FROM mart_vendedor_dia
                WHERE dia BETWEEN :start AND :end
                GROUP BY dia
                HAVING SUM(pedidos_faturados) > 0

                UNION ALL

                SELECT
                    dia,
                    'autorizado'                 AS status_comercial,
                    SUM(valor_autorizado)        AS total,
                    SUM(mercadorias_autorizadas) AS mercadorias,
                    SUM(pedidos_autorizados)     AS pedidos
                FROM mart_vendedor_dia
                WHERE dia BETWEEN :start AND :end
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
                WHERE dia BETWEEN :start AND :end
                GROUP BY dia
                HAVING SUM(pedidos_cancelados) > 0
            SQL);
            $stmt->execute(['start' => $start, 'end' => $end]);
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
            $stmt = $this->pdo->prepare(<<<'SQL'
                SELECT
                    SUM(valor_faturado)      AS billed_value,
                    SUM(pedidos_faturados)   AS billed_orders,
                    SUM(valor_autorizado)    AS authorized_value,
                    SUM(pedidos_autorizados) AS authorized_orders,
                    SUM(valor_cancelado)     AS cancelled_value,
                    SUM(pedidos_cancelados)  AS cancelled_orders
                FROM mart_vendedor_dia
                WHERE dia BETWEEN :start AND :end
            SQL);
            $stmt->execute(['start' => $start, 'end' => $end]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
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
            $stmt = $this->pdo->prepare(<<<'SQL'
                SELECT
                    SUM(valor_faturado)            AS billed_value,
                    SUM(pedidos_faturados)          AS billed_orders,
                    SUM(mercadorias_faturado)       AS merchandise_total,
                    SUM(descontos_faturado)         AS discounts_total,
                    COUNT(DISTINCT codigo_vendedor_omie) AS active_vendors
                FROM mart_vendedor_dia
                WHERE dia BETWEEN :start AND :end
                  AND pedidos_faturados > 0
            SQL);
            $stmt->execute(['start' => $start, 'end' => $end]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
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
                <<<'SQL'
                    SELECT
                        codigo_cliente_omie,
                        nome_cliente                 AS client_name,
                        documento_cliente            AS client_document,
                        SUM(nfes_emitidas)           AS notes_count,
                        SUM(valor_nfe)               AS invoice_total,
                        SUM(mercadorias_faturado)    AS merchandise_total,
                        0                            AS discount_total
                    FROM mart_cliente_periodo
                    WHERE competencia IN (%s)
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
                <<<'SQL'
                    SELECT
                        codigo_produto   AS product_code,
                        descricao_produto AS description,
                        SUM(quantidade)  AS quantity,
                        SUM(valor_total) AS revenue,
                        SUM(nfes_count)  AS orders
                    FROM mart_produto_periodo
                    WHERE competencia IN (%s)
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
            $stmt = $this->pdo->prepare(<<<'SQL'
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
                WHERE COALESCE(data_faturamento, data_emissao) BETWEEN :start AND :end
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
            $stmt = $this->pdo->prepare(<<<'SQL'
                SELECT
                    pedido_id_omie, codigo_pedido,
                    codigo_vendedor_omie, nome_vendedor,
                    nome_cliente,
                    status_comercial, bridge_status,
                    data_emissao, data_faturamento,
                    valor_pedido, valor_nfe,
                    valor_aberto, valor_recebido
                FROM mart_bridge_pedido_nfe_titulo
                WHERE bridge_status NOT IN ('completo', 'cancelado')
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

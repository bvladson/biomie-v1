<?php

declare(strict_types=1);

namespace BIomie\Stock;

use BIomie\Commercial\CommercialMetricDate;
use BIomie\Support\BiVisibilityScope;
use PDO;
use Throwable;

/**
 * EST.R1.1 — Inteligência de estoque calculada exclusivamente por documentos Omie canônicos.
 *
 * Fonte: biomie_notas_fiscais_itens_canonicos × biomie_notas_fiscais_canonicas.
 * Nunca chama a API Omie diretamente. Nunca usa CSV ou fonte manual.
 *
 * O que pode responder:
 *   - Volume de saídas por produto (NF comercial autorizadas)
 *   - Volume de remessas por produto
 *   - Giro de vendas no período
 *   - Recência (última venda, meses ativos)
 *   - Risco operacional de ruptura (alto giro + sem evidência recente de reposição)
 *   - Ranking por giro
 *
 * O que NÃO pode responder (sem módulo oficial Omie):
 *   - Saldo físico real por local
 *   - Estoque mínimo configurado
 *   - Movimentos de entrada (reposição)
 *
 * Honestidade semântica: toda saída desta classe DEVE declarar source='omie_document_ledger'
 * e confidence='operational'. Nunca usar os termos "saldo real" ou "estoque físico".
 */
final class OmieDocumentStockLedgerBuilder
{
    private string $visCond;

    public function __construct(private readonly PDO $pdo)
    {
        $this->visCond = BiVisibilityScope::getCondition($pdo, 'biomie_notas_fiscais_canonicas', 'nf');
    }

    // ── Por produto (Product 360) ─────────────────────────────────────────────

    /**
     * Ledger completo para um produto no período.
     *
     * @return array<string,mixed>
     */
    public function buildForProduct(string $productCode, string $start, string $end, int $limit = 50): array
    {
        if ($productCode === '') {
            return $this->emptyLedger('product_code obrigatório');
        }

        $commercial = $this->queryProductSales($productCode, $start, $end, 'commercial');
        $remittance = $this->queryProductSales($productCode, $start, $end, 'remittance');
        $giro30d    = $this->queryProductSales($productCode, date('Y-m-d', strtotime('-30 days')), date('Y-m-d'), 'commercial');
        $giro90d    = $this->queryProductSales($productCode, date('Y-m-d', strtotime('-90 days')), date('Y-m-d'), 'commercial');
        $topClients = $this->queryTopClientsForProduct($productCode, $start, $end, min($limit, 10));
        $monthly    = $this->queryMonthlyBreakdown($productCode, $start, $end);
        $riskScore  = $this->calcRuptureRisk($commercial, $giro30d, $giro90d, $remittance);

        return [
            'source'             => OmieStockSourceResolver::SOURCE_DOCUMENT,
            'confidence'         => 'operational',
            'source_note'        => 'Calculado por NFs autorizadas Omie. Não é saldo físico real.',
            'period'             => ['start' => $start, 'end' => $end],
            'commercial' => [
                'qty_total'      => (float)($commercial['qty'] ?? 0),
                'value_total'    => (float)($commercial['value'] ?? 0),
                'nfs_count'      => (int)($commercial['nfs'] ?? 0),
                'customers'      => (int)($commercial['customers'] ?? 0),
                'first_sale'     => (string)($commercial['first_sale'] ?? ''),
                'last_sale'      => (string)($commercial['last_sale'] ?? ''),
                'active_months'  => (int)($commercial['active_months'] ?? 0),
            ],
            'remittance' => [
                'qty_total'      => (float)($remittance['qty'] ?? 0),
                'nfs_count'      => (int)($remittance['nfs'] ?? 0),
            ],
            'velocity' => [
                'giro_30d_qty'   => (float)($giro30d['qty'] ?? 0),
                'giro_30d_nfs'   => (int)($giro30d['nfs'] ?? 0),
                'giro_90d_qty'   => (float)($giro90d['qty'] ?? 0),
                'giro_90d_nfs'   => (int)($giro90d['nfs'] ?? 0),
            ],
            'rupture_risk' => $riskScore,
            'top_clients'  => $topClients,
            'monthly_breakdown' => $monthly,
            'empty_state'  => ((float)($commercial['qty'] ?? 0) + (float)($remittance['qty'] ?? 0)) <= 0
                ? 'Produto sem histórico de documentos no período'
                : null,
        ];
    }

    // ── Agregado (mercadorias.html) ───────────────────────────────────────────

    /**
     * Ranking de produtos por giro (volume de vendas) no período.
     *
     * @return list<array<string,mixed>>
     */
    public function buildVelocityRanking(string $start, string $end, int $limit = 50): array
    {
        $dateExpr = CommercialMetricDate::metricDateSql('nf');
        try {
            $stmt = $this->pdo->prepare(
                "SELECT
                    COALESCE(NULLIF(TRIM(i.codigo_produto), ''), 'SEM_SKU') AS codigo_produto,
                    MAX(COALESCE(NULLIF(TRIM(i.descricao_produto), ''), 'Produto sem descrição')) AS descricao_produto,
                    SUM(i.quantidade) AS qty_total,
                    SUM(i.valor_total) AS value_total,
                    COUNT(DISTINCT i.nota_key) AS nfs_count,
                    COUNT(DISTINCT nf.codigo_cliente_omie) AS customers,
                    MAX({$dateExpr}) AS last_sale,
                    COUNT(DISTINCT DATE_FORMAT({$dateExpr}, '%Y-%m')) AS active_months
                 FROM biomie_notas_fiscais_itens_canonicos i
                 JOIN biomie_notas_fiscais_canonicas nf ON nf.nota_key = i.nota_key
                 WHERE nf.status_fiscal = 'autorizada'
                   AND nf.operation_scope = 'commercial'
                   AND {$dateExpr} BETWEEN :start AND :end
                   AND {$this->visCond}
                 GROUP BY COALESCE(NULLIF(TRIM(i.codigo_produto), ''), 'SEM_SKU')
                 ORDER BY qty_total DESC
                 LIMIT :lim"
            );
            $stmt->bindValue(':start', $start);
            $stmt->bindValue(':end',   $end);
            $stmt->bindValue(':lim',   $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }

        return array_map(fn(array $r): array => [
            'codigo_produto'  => (string)($r['codigo_produto']  ?? ''),
            'descricao_produto' => (string)($r['descricao_produto'] ?? ''),
            'qty_total'       => round((float)($r['qty_total']   ?? 0), 2),
            'value_total'     => round((float)($r['value_total'] ?? 0), 2),
            'nfs_count'       => (int)($r['nfs_count']           ?? 0),
            'customers'       => (int)($r['customers']           ?? 0),
            'last_sale'       => substr((string)($r['last_sale'] ?? ''), 0, 10),
            'active_months'   => (int)($r['active_months']       ?? 0),
            'source'          => OmieStockSourceResolver::SOURCE_DOCUMENT,
        ], $rows);
    }

    /**
     * Produtos com alto giro recente e sem evidência de reposição.
     * "Risco operacional" — não confundir com ruptura física.
     *
     * @return list<array<string,mixed>>
     */
    public function buildRuptureRiskList(string $start, string $end, int $limit = 30): array
    {
        $dateExpr  = CommercialMetricDate::metricDateSql('nf');
        $cut30d    = date('Y-m-d', strtotime('-30 days'));
        try {
            // Products with recent sales (last 30 days) and high total volume
            $stmt = $this->pdo->prepare(
                "SELECT
                    COALESCE(NULLIF(TRIM(i.codigo_produto), ''), 'SEM_SKU') AS codigo_produto,
                    MAX(COALESCE(NULLIF(TRIM(i.descricao_produto), ''), '')) AS descricao_produto,
                    SUM(i.quantidade) AS qty_period,
                    COUNT(DISTINCT i.nota_key) AS nfs_count,
                    MAX({$dateExpr}) AS last_sale,
                    SUM(CASE WHEN {$dateExpr} >= :cut30 THEN i.quantidade ELSE 0 END) AS qty_30d
                 FROM biomie_notas_fiscais_itens_canonicos i
                 JOIN biomie_notas_fiscais_canonicas nf ON nf.nota_key = i.nota_key
                 WHERE nf.status_fiscal = 'autorizada'
                   AND nf.operation_scope = 'commercial'
                   AND {$dateExpr} BETWEEN :start AND :end
                   AND {$this->visCond}
                 GROUP BY COALESCE(NULLIF(TRIM(i.codigo_produto), ''), 'SEM_SKU')
                 HAVING qty_30d > 0
                 ORDER BY qty_30d DESC
                 LIMIT :lim"
            );
            $stmt->bindValue(':start', $start);
            $stmt->bindValue(':end',   $end);
            $stmt->bindValue(':cut30', $cut30d);
            $stmt->bindValue(':lim',   $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }

        // Average giro_30d to classify risk
        $qtds30 = array_column($rows, 'qty_30d');
        $avg30  = count($qtds30) > 0 ? array_sum($qtds30) / count($qtds30) : 0;

        return array_map(function (array $r) use ($avg30): array {
            $qty30 = (float)($r['qty_30d'] ?? 0);
            $risk  = $qty30 >= $avg30 * 1.5 ? 'high' : ($qty30 >= $avg30 ? 'medium' : 'low');
            return [
                'codigo_produto'   => (string)($r['codigo_produto']   ?? ''),
                'descricao_produto' => (string)($r['descricao_produto'] ?? ''),
                'qty_period'       => round((float)($r['qty_period']  ?? 0), 2),
                'qty_30d'          => round((float)($r['qty_30d']     ?? 0), 2),
                'nfs_count'        => (int)($r['nfs_count']            ?? 0),
                'last_sale'        => substr((string)($r['last_sale']  ?? ''), 0, 10),
                'rupture_risk'     => $risk,
                'risk_note'        => 'Risco estimado por saídas. Sem dado de entrada disponível.',
                'source'           => OmieStockSourceResolver::SOURCE_DOCUMENT,
            ];
        }, $rows);
    }

    /**
     * Stats gerais do ledger documental.
     *
     * @return array<string,mixed>
     */
    public function statsGerais(string $start, string $end): array
    {
        $dateExpr = CommercialMetricDate::metricDateSql('nf');
        try {
            $stmt = $this->pdo->prepare(
                "SELECT
                    COUNT(DISTINCT COALESCE(NULLIF(TRIM(i.codigo_produto),''),'SEM_SKU')) AS produtos_distintos,
                    SUM(i.quantidade) AS qty_total,
                    SUM(i.valor_total) AS value_total,
                    COUNT(DISTINCT i.nota_key) AS nfs_count,
                    MAX({$dateExpr}) AS last_sale
                 FROM biomie_notas_fiscais_itens_canonicos i
                 JOIN biomie_notas_fiscais_canonicas nf ON nf.nota_key = i.nota_key
                 WHERE nf.status_fiscal = 'autorizada'
                   AND nf.operation_scope = 'commercial'
                   AND {$dateExpr} BETWEEN :start AND :end
                   AND {$this->visCond}"
            );
            $stmt->execute([':start' => $start, ':end' => $end]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            $row = [];
        }

        return [
            'produtos_distintos' => (int)($row['produtos_distintos'] ?? 0),
            'qty_total'          => round((float)($row['qty_total']  ?? 0), 2),
            'value_total'        => round((float)($row['value_total'] ?? 0), 2),
            'nfs_count'          => (int)($row['nfs_count']           ?? 0),
            'last_sale'          => substr((string)($row['last_sale'] ?? ''), 0, 10),
            'source'             => OmieStockSourceResolver::SOURCE_DOCUMENT,
            'confidence'         => 'operational',
        ];
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function queryProductSales(string $productCode, string $start, string $end, string $scope): array
    {
        $dateExpr = CommercialMetricDate::metricDateSql('nf');
        $scopeCond = $scope === 'commercial'
            ? "nf.operation_scope = 'commercial' AND {$this->visCond}"
            : "nf.operation_scope = 'remittance'";

        try {
            $stmt = $this->pdo->prepare(
                "SELECT
                    SUM(i.quantidade) AS qty,
                    SUM(i.valor_total) AS value,
                    COUNT(DISTINCT i.nota_key) AS nfs,
                    COUNT(DISTINCT nf.codigo_cliente_omie) AS customers,
                    MIN({$dateExpr}) AS first_sale,
                    MAX({$dateExpr}) AS last_sale,
                    COUNT(DISTINCT DATE_FORMAT({$dateExpr}, '%Y-%m')) AS active_months
                 FROM biomie_notas_fiscais_itens_canonicos i
                 JOIN biomie_notas_fiscais_canonicas nf ON nf.nota_key = i.nota_key
                 WHERE nf.status_fiscal = 'autorizada'
                   AND {$scopeCond}
                   AND {$dateExpr} BETWEEN :start AND :end
                   AND COALESCE(NULLIF(TRIM(i.codigo_produto), ''), 'SEM_SKU') = :code"
            );
            $stmt->execute([':start' => $start, ':end' => $end, ':code' => $productCode]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /** @return list<array<string,mixed>> */
    private function queryTopClientsForProduct(string $productCode, string $start, string $end, int $limit): array
    {
        $dateExpr = CommercialMetricDate::metricDateSql('nf');
        try {
            $stmt = $this->pdo->prepare(
                "SELECT nf.nome_cliente, COUNT(DISTINCT i.nota_key) AS nfs,
                        SUM(i.quantidade) AS qty, SUM(i.valor_total) AS value
                 FROM biomie_notas_fiscais_itens_canonicos i
                 JOIN biomie_notas_fiscais_canonicas nf ON nf.nota_key = i.nota_key
                 WHERE nf.status_fiscal = 'autorizada'
                   AND nf.operation_scope = 'commercial'
                   AND {$dateExpr} BETWEEN :start AND :end
                   AND {$this->visCond}
                   AND COALESCE(NULLIF(TRIM(i.codigo_produto), ''), 'SEM_SKU') = :code
                 GROUP BY nf.nome_cliente
                 ORDER BY qty DESC
                 LIMIT :lim"
            );
            $stmt->bindValue(':start', $start);
            $stmt->bindValue(':end',   $end);
            $stmt->bindValue(':code',  $productCode);
            $stmt->bindValue(':lim',   $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /** @return list<array<string,mixed>> */
    private function queryMonthlyBreakdown(string $productCode, string $start, string $end): array
    {
        $dateExpr = CommercialMetricDate::metricDateSql('nf');
        try {
            $stmt = $this->pdo->prepare(
                "SELECT DATE_FORMAT({$dateExpr}, '%Y-%m') AS mes,
                        SUM(i.quantidade) AS qty, SUM(i.valor_total) AS value,
                        COUNT(DISTINCT i.nota_key) AS nfs
                 FROM biomie_notas_fiscais_itens_canonicos i
                 JOIN biomie_notas_fiscais_canonicas nf ON nf.nota_key = i.nota_key
                 WHERE nf.status_fiscal = 'autorizada'
                   AND nf.operation_scope = 'commercial'
                   AND {$dateExpr} BETWEEN :start AND :end
                   AND {$this->visCond}
                   AND COALESCE(NULLIF(TRIM(i.codigo_produto), ''), 'SEM_SKU') = :code
                 GROUP BY mes ORDER BY mes ASC"
            );
            $stmt->execute([':start' => $start, ':end' => $end, ':code' => $productCode]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array{score:float,level:string,note:string} */
    private function calcRuptureRisk(array $commercial, array $giro30d, array $giro90d, array $remittance): array
    {
        $qty30  = (float)($giro30d['qty']    ?? 0);
        $qty90  = (float)($giro90d['qty']    ?? 0);
        $qtyRem = (float)($remittance['qty'] ?? 0);

        if ($qty30 <= 0) {
            return ['score' => 0, 'level' => 'none', 'note' => 'Sem saídas nos últimos 30 dias'];
        }

        // Simple heuristic: rate = qty_30d / (qty_90d/3) — accelerating or stable?
        $avg90monthly = $qty90 > 0 ? $qty90 / 3 : 0;
        $acceleration = $avg90monthly > 0 ? $qty30 / $avg90monthly : 1.0;
        $hasRemessa   = $qtyRem > 0;

        $score = min(1.0, ($acceleration * 0.6) + ($hasRemessa ? 0 : 0.4));

        $level = $score >= 0.75 ? 'high' : ($score >= 0.45 ? 'medium' : 'low');
        $note  = match ($level) {
            'high'   => 'Alto giro recente sem remessa documentada. Risco operacional elevado.',
            'medium' => 'Giro moderado. Monitorar reposição.',
            default  => 'Giro estável ou com remessa documentada.',
        };

        return ['score' => round($score, 2), 'level' => $level, 'note' => $note];
    }

    /** @return array<string,mixed> */
    private function emptyLedger(string $reason): array
    {
        return [
            'source'      => OmieStockSourceResolver::SOURCE_DOCUMENT,
            'confidence'  => 'operational',
            'source_note' => 'Calculado por NFs autorizadas Omie.',
            'empty_state' => $reason,
            'commercial'  => ['qty_total' => 0, 'value_total' => 0, 'nfs_count' => 0],
            'remittance'  => ['qty_total' => 0, 'nfs_count' => 0],
            'velocity'    => ['giro_30d_qty' => 0, 'giro_90d_qty' => 0],
            'rupture_risk' => ['score' => 0, 'level' => 'none', 'note' => $reason],
        ];
    }
}

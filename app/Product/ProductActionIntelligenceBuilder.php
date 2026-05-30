<?php

declare(strict_types=1);

namespace BIomie\Product;

use BIomie\Commercial\CommercialMetricDate;
use BIomie\Support\BiVisibilityScope;
use BIomie\Support\ProductCommercialIdentityResolver;
use BIomie\Support\ProductTemporalBucketResolver;
use PDO;
use Throwable;

/**
 * PROD.R2 — Inteligência de Produto para Ação Comercial.
 *
 * Transforma dados canônicos de NF/pedido em recomendações acionáveis:
 *   - Quais produtos merecem ação agora?
 *   - Quais clientes podem recomprar?
 *   - Quais vendedores têm aderência ao produto?
 *   - Quais oportunidades de cross-sell existem?
 *
 * REGRAS OBRIGATÓRIAS:
 *   - Read-only. Nunca escreve dados de negócio.
 *   - Sem CSV/manual. Apenas dados canônicos da API Omie.
 *   - Sem saldo físico real quando fonte for document_ledger.
 *   - Sem SELECT *.
 *   - Prepared statements em todas as queries.
 *   - Não mistura licitação no escopo Orç-PD.
 *   - Datas: COALESCE(data_competencia, data_emissao).
 */
final class ProductActionIntelligenceBuilder
{
    private string $visCond;
    private string $dateExpr;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ?ProductCommercialIdentityResolver $identityResolver = null,
    ) {
        $this->visCond  = BiVisibilityScope::getCondition($pdo, 'biomie_notas_fiscais_canonicas', 'nf');
        $this->dateExpr = CommercialMetricDate::metricDateSql('nf');
    }

    // ── Executive Summary ─────────────────────────────────────────────────────

    /**
     * Resumo executivo de oportunidades comerciais no período.
     *
     * @return array<string,mixed>
     */
    public function buildExecutiveSummary(string $start, string $end): array
    {
        $opportunities = $this->buildProductOpportunities($start, $end, 200);

        $campeoes      = array_filter($opportunities, fn($r) => ($r['categoria'] ?? '') === 'Produto campeão');
        $reativar      = array_filter($opportunities, fn($r) => ($r['categoria'] ?? '') === 'Reativar produto');
        $recorrentes   = array_filter($opportunities, fn($r) => ($r['meses_ativos'] ?? 0) >= 2);
        $concentrados  = array_filter($opportunities, fn($r) => ($r['top_cliente_share_pct'] ?? 0) >= 60);
        $dormentes     = array_filter($opportunities, fn($r) => ($r['dias_sem_venda'] ?? 0) >= 90);

        // Total clientes impactáveis (clientes únicos com score de recompra)
        $repurchaseCandidates = $this->countRepurchaseCandidates($start, $end);

        return [
            'period'                    => ['start' => $start, 'end' => $end],
            'total_products'            => count($opportunities),
            'campeoes'                  => count($campeoes),
            'para_reativar'             => count($reativar),
            'recorrentes'               => count($recorrentes),
            'concentrados'              => count($concentrados),
            'dormentes'                 => count($dormentes),
            'clientes_recompra'         => $repurchaseCandidates,
            'top_oportunidades'         => array_values(array_slice(
                array_filter($opportunities, fn($r) => ($r['score_acao'] ?? 0) >= 40),
                0, 10
            )),
            'source'                    => 'omie_canonical',
            'confidence'                => 'operational',
        ];
    }

    // ── Product Opportunities ─────────────────────────────────────────────────

    /**
     * Lista de produtos com scoring de oportunidade comercial.
     *
     * @return list<array<string,mixed>>
     */
    public function buildProductOpportunities(string $start, string $end, int $limit = 50): array
    {
        // Period span in months (for recurrence scoring)
        $months = max(1, (int) round(
            (strtotime($end) - strtotime($start)) / (86400 * 30)
        ));

        try {
            $stmt = $this->pdo->prepare(
                "SELECT
                    COALESCE(NULLIF(TRIM(i.codigo_produto), ''), 'SEM_SKU')         AS product_code,
                    MAX(COALESCE(NULLIF(TRIM(i.descricao_produto), ''), ''))         AS product_name,
                    SUM(i.valor_total)                                               AS receita_periodo,
                    SUM(i.quantidade)                                                AS qtd_periodo,
                    COUNT(DISTINCT i.nota_key)                                       AS nfs_periodo,
                    COUNT(DISTINCT nf.codigo_cliente_omie)                           AS clientes_periodo,
                    COUNT(DISTINCT nf.codigo_vendedor_omie)                          AS vendedores_periodo,
                    COUNT(DISTINCT DATE_FORMAT({$this->dateExpr}, '%Y-%m'))          AS meses_ativos,
                    MAX({$this->dateExpr})                                           AS ultima_venda,
                    DATEDIFF(CURDATE(), MAX({$this->dateExpr}))                      AS dias_sem_venda
                 FROM biomie_notas_fiscais_itens_canonicos i
                 INNER JOIN biomie_notas_fiscais_canonicas nf ON nf.nota_key = i.nota_key
                 WHERE nf.status_fiscal = 'autorizada'
                   AND nf.operation_scope = 'commercial'
                   AND {$this->dateExpr} BETWEEN :start AND :end
                   AND {$this->visCond}
                 GROUP BY COALESCE(NULLIF(TRIM(i.codigo_produto), ''), 'SEM_SKU')
                 ORDER BY receita_periodo DESC
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

        // Compute concentration for each product in bulk (PHP aggregation)
        $concentrationMap = $this->buildConcentrationMap($start, $end);

        // Global averages for relative scoring
        $qtds = array_column($rows, 'qtd_periodo');
        $avgQty = count($qtds) > 0 ? array_sum($qtds) / count($qtds) : 1.0;

        return array_map(function (array $r) use ($months, $concentrationMap, $avgQty): array {
            $code     = (string) ($r['product_code'] ?? '');
            $conc     = $concentrationMap[$code] ?? ['pct' => 0.0, 'top_client' => ''];
            $score    = $this->buildActionScore($r, $months, $avgQty, $conc['pct']);
            $categoria = $this->classifyOpportunity($r, $score, $conc['pct']);

            // Resolve identity for display_name
            $displayName = (string) ($r['product_name'] ?? $code);
            if ($displayName === '' || $displayName === 'SEM_SKU') {
                $displayName = "Produto {$code}";
            }

            return [
                'product_code'          => $code,
                'product_name'          => $displayName,
                'receita_periodo'       => round((float) ($r['receita_periodo'] ?? 0), 2),
                'qtd_periodo'           => round((float) ($r['qtd_periodo'] ?? 0), 2),
                'nfs_periodo'           => (int) ($r['nfs_periodo'] ?? 0),
                'clientes_periodo'      => (int) ($r['clientes_periodo'] ?? 0),
                'vendedores_periodo'    => (int) ($r['vendedores_periodo'] ?? 0),
                'meses_ativos'          => (int) ($r['meses_ativos'] ?? 0),
                'ultima_venda'          => substr((string) ($r['ultima_venda'] ?? ''), 0, 10),
                'dias_sem_venda'        => (int) ($r['dias_sem_venda'] ?? 0),
                'top_cliente_share_pct' => $conc['pct'],
                'top_cliente_nome'      => $conc['top_client'],
                'score_acao'            => $score,
                'score_breakdown'       => $this->buildScoreBreakdown($r, $months, $avgQty, $conc['pct']),
                'categoria'             => $categoria,
                'acao_sugerida'         => $this->suggestAction($categoria, $r, $conc['pct']),
                'source'                => 'omie_canonical',
            ];
        }, $rows);
    }

    // ── Repurchase Candidates ─────────────────────────────────────────────────

    /**
     * Clientes com alta probabilidade de recompra de um produto.
     *
     * @return list<array<string,mixed>>
     */
    public function buildRepurchaseCandidates(string $productCode, string $start, string $end, int $limit = 30): array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT
                    nf.codigo_cliente_omie                                               AS cliente_id,
                    MAX(nf.nome_cliente)                                                 AS cliente_nome,
                    MAX(nf.documento_cliente)                                            AS documento_cliente,
                    COUNT(DISTINCT i.nota_key)                                           AS n_compras,
                    SUM(i.quantidade)                                                    AS qtd_total,
                    SUM(i.valor_total)                                                   AS receita_total,
                    MIN({$this->dateExpr})                                               AS primeira_compra,
                    MAX({$this->dateExpr})                                               AS ultima_compra,
                    DATEDIFF(CURDATE(), MAX({$this->dateExpr}))                          AS dias_desde_ultima,
                    COUNT(DISTINCT DATE_FORMAT({$this->dateExpr}, '%Y-%m'))              AS meses_ativos,
                    MAX(nf.nome_vendedor)                                                AS vendedor_ultima_venda
                 FROM biomie_notas_fiscais_itens_canonicos i
                 INNER JOIN biomie_notas_fiscais_canonicas nf ON nf.nota_key = i.nota_key
                 WHERE nf.status_fiscal = 'autorizada'
                   AND {$this->dateExpr} BETWEEN :start AND :end
                   AND {$this->visCond}
                   AND TRIM(i.codigo_produto) = TRIM(:code)
                 GROUP BY nf.codigo_cliente_omie
                 HAVING dias_desde_ultima >= 30
                 ORDER BY dias_desde_ultima ASC, n_compras DESC
                 LIMIT :lim"
            );
            $stmt->bindValue(':start', $start);
            $stmt->bindValue(':end',   $end);
            $stmt->bindValue(':code',  trim($productCode));
            $stmt->bindValue(':lim',   $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }

        return array_map(function (array $r): array {
            $score = $this->repurchaseScore(
                (int) ($r['n_compras'] ?? 0),
                (int) ($r['dias_desde_ultima'] ?? 999),
                (float) ($r['receita_total'] ?? 0),
                (int) ($r['meses_ativos'] ?? 0)
            );
            return [
                'cliente_id'           => (string) ($r['cliente_id'] ?? ''),
                'cliente_nome'         => (string) ($r['cliente_nome'] ?? ''),
                'documento_cliente'    => (string) ($r['documento_cliente'] ?? ''),
                'n_compras'            => (int) ($r['n_compras'] ?? 0),
                'qtd_total'            => round((float) ($r['qtd_total'] ?? 0), 2),
                'receita_total'        => round((float) ($r['receita_total'] ?? 0), 2),
                'primeira_compra'      => substr((string) ($r['primeira_compra'] ?? ''), 0, 10),
                'ultima_compra'        => substr((string) ($r['ultima_compra'] ?? ''), 0, 10),
                'dias_desde_ultima'    => (int) ($r['dias_desde_ultima'] ?? 0),
                'meses_ativos'         => (int) ($r['meses_ativos'] ?? 0),
                'vendedor_ultima_venda' => (string) ($r['vendedor_ultima_venda'] ?? ''),
                'score_recompra'       => $score,
                'motivo_score'         => $this->repurchaseMotivo($score, $r),
                'source'               => 'omie_canonical',
            ];
        }, $rows);
    }

    // ── Vendor × Product Affinity ─────────────────────────────────────────────

    /**
     * Aderência vendedor × produto.
     * Para cada vendedor: quais produtos são fortes, quais são oportunidade.
     *
     * @return list<array<string,mixed>>
     */
    public function buildVendorProductAffinity(string $start, string $end, ?string $vendorCode = null): array
    {
        $vendorCond = $vendorCode !== null ? 'AND nf.codigo_vendedor_omie = :vendor' : '';

        try {
            $stmt = $this->pdo->prepare(
                "SELECT
                    nf.codigo_vendedor_omie                                              AS vendor_id,
                    MAX(nf.nome_vendedor)                                                AS vendor_name,
                    COALESCE(NULLIF(TRIM(i.codigo_produto), ''), 'SEM_SKU')             AS product_code,
                    MAX(COALESCE(NULLIF(TRIM(i.descricao_produto), ''), ''))             AS product_name,
                    SUM(i.valor_total)                                                   AS receita,
                    SUM(i.quantidade)                                                    AS qtd,
                    COUNT(DISTINCT i.nota_key)                                           AS nfs,
                    COUNT(DISTINCT nf.codigo_cliente_omie)                               AS clientes,
                    MAX({$this->dateExpr})                                               AS ultima_venda
                 FROM biomie_notas_fiscais_itens_canonicos i
                 INNER JOIN biomie_notas_fiscais_canonicas nf ON nf.nota_key = i.nota_key
                 WHERE nf.status_fiscal = 'autorizada'
                   AND nf.operation_scope = 'commercial'
                   AND {$this->dateExpr} BETWEEN :start AND :end
                   AND {$this->visCond}
                   {$vendorCond}
                 GROUP BY nf.codigo_vendedor_omie, COALESCE(NULLIF(TRIM(i.codigo_produto), ''), 'SEM_SKU')
                 ORDER BY nf.codigo_vendedor_omie, receita DESC"
            );
            $stmt->bindValue(':start', $start);
            $stmt->bindValue(':end',   $end);
            if ($vendorCode !== null) {
                $stmt->bindValue(':vendor', $vendorCode);
            }
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }

        // Global per-product revenue for share calculation
        $productTotals = [];
        foreach ($rows as $r) {
            $p = (string) ($r['product_code'] ?? '');
            $productTotals[$p] = ($productTotals[$p] ?? 0) + (float) ($r['receita'] ?? 0);
        }

        // Group by vendor
        $byVendor = [];
        foreach ($rows as $r) {
            $vid = (string) ($r['vendor_id'] ?? '');
            if (!isset($byVendor[$vid])) {
                $byVendor[$vid] = [
                    'vendor_id'   => $vid,
                    'vendor_name' => (string) ($r['vendor_name'] ?? ''),
                    'produtos'    => [],
                    'receita_total' => 0.0,
                ];
            }
            $pCode  = (string) ($r['product_code'] ?? '');
            $pRev   = (float) ($r['receita'] ?? 0);
            $pTotal = $productTotals[$pCode] ?? 1;
            $share  = $pTotal > 0 ? round($pRev / $pTotal * 100, 1) : 0.0;

            $byVendor[$vid]['receita_total'] += $pRev;
            $byVendor[$vid]['produtos'][] = [
                'product_code'  => $pCode,
                'product_name'  => (string) ($r['product_name'] ?? $pCode),
                'receita'       => round($pRev, 2),
                'qtd'           => round((float) ($r['qtd'] ?? 0), 2),
                'nfs'           => (int) ($r['nfs'] ?? 0),
                'clientes'      => (int) ($r['clientes'] ?? 0),
                'share_produto' => $share,
                'ultima_venda'  => substr((string) ($r['ultima_venda'] ?? ''), 0, 10),
            ];
        }

        // For each vendor, tag products as "forte" or "oportunidade"
        return array_values(array_map(function (array $v): array {
            $totalVendorRev = max(1, $v['receita_total']);
            $produtos = $v['produtos'];
            foreach ($produtos as &$p) {
                $vendorShare = round($p['receita'] / $totalVendorRev * 100, 1);
                $p['participacao_vendedor_pct'] = $vendorShare;
                $p['classificacao'] = $vendorShare >= 15 ? 'forte' : ($vendorShare >= 5 ? 'regular' : 'fraco');
            }
            unset($p);

            // Top 3 produtos fortes + identify gaps
            usort($produtos, fn($a, $b) => $b['receita'] <=> $a['receita']);
            $fortes      = array_slice(array_filter($produtos, fn($p) => $p['classificacao'] === 'forte'), 0, 3);
            $oportunidade = array_slice(array_filter($produtos, fn($p) => $p['classificacao'] !== 'forte'), 0, 5);

            return [
                'vendor_id'          => $v['vendor_id'],
                'vendor_name'        => $v['vendor_name'],
                'receita_total'      => round($v['receita_total'], 2),
                'n_produtos'         => count($produtos),
                'produtos_fortes'    => array_values($fortes),
                'produtos_oportunidade' => array_values($oportunidade),
                'todos_produtos'     => $produtos,
            ];
        }, $byVendor));
    }

    // ── Summary count helpers ─────────────────────────────────────────────────

    public function countRepurchaseCandidates(string $start, string $end): int
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(DISTINCT nf.codigo_cliente_omie)
                 FROM biomie_notas_fiscais_canonicas nf
                 WHERE nf.status_fiscal = 'autorizada'
                   AND {$this->dateExpr} BETWEEN :start AND :end
                   AND {$this->visCond}
                   AND DATEDIFF(CURDATE(), {$this->dateExpr}) >= 30"
            );
            $stmt->execute([':start' => $start, ':end' => $end]);
            return (int) $stmt->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    // ── Scoring ───────────────────────────────────────────────────────────────

    /**
     * Score de ação comercial (0–100). Auditável, sem IA externa.
     *
     * Componentes:
     *   recorrência (40 pts) — meses_ativos / meses_possíveis
     *   recência    (30 pts) — 1 - dias_sem_venda/180, capped at 0
     *   diversidade (15 pts) — n_clientes/5, capped at 1
     *   volume      (15 pts) — qtd_produto/avg_qtd, capped at 1
     *
     * @param array<string,mixed> $r
     */
    public function buildActionScore(array $r, int $periodMonths, float $avgQty, float $concentrationPct): int
    {
        return $this->buildScoreBreakdown($r, $periodMonths, $avgQty, $concentrationPct)['total'];
    }

    /**
     * Score completo com breakdown explicável para o drawer.
     *
     * @param array<string,mixed> $r
     * @return array{total:int,recorrencia:int,recencia:int,diversidade:int,volume:int,penalidade:int,motivos:list<string>}
     */
    public function buildScoreBreakdown(array $r, int $periodMonths, float $avgQty, float $concentrationPct): array
    {
        $mesesAtivos    = (int) ($r['meses_ativos']   ?? 0);
        $diasSemVenda   = (int) ($r['dias_sem_venda'] ?? 999);
        $nClientes      = (int) ($r['clientes_periodo'] ?? $r['clientes'] ?? 0);
        $qtd            = (float) ($r['qtd_periodo']  ?? $r['qtd'] ?? 0);

        $recorrencia = $periodMonths > 0 ? min(1.0, $mesesAtivos / $periodMonths) : 0;
        $recencia    = max(0.0, 1.0 - $diasSemVenda / 180.0);
        $diversidade = min(1.0, $nClientes / 5.0);
        $volume      = $avgQty > 0 ? min(1.0, $qtd / ($avgQty * 2)) : 0;

        $pts_rec = (int) round($recorrencia * 40);
        $pts_ren = (int) round($recencia    * 30);
        $pts_div = (int) round($diversidade * 15);
        $pts_vol = (int) round($volume      * 15);

        $penalidade = 0;
        if ($concentrationPct >= 80) $penalidade = -10;
        elseif ($concentrationPct >= 60) $penalidade = -5;

        $total = max(0, min(100, $pts_rec + $pts_ren + $pts_div + $pts_vol + $penalidade));

        // Human-readable motivos
        $motivos = [];
        if ($pts_rec >= 30) $motivos[] = "+ Recorrente em {$mesesAtivos} de {$periodMonths} meses possíveis";
        elseif ($pts_rec > 0) $motivos[] = "± Vendido em {$mesesAtivos} meses (pouco recorrente)";
        else $motivos[] = "− Sem recorrência no período";

        if ($pts_ren >= 25) $motivos[] = "+ Venda recente (há {$diasSemVenda} dias)";
        elseif ($pts_ren > 0) $motivos[] = "± Última venda há {$diasSemVenda} dias";
        else $motivos[] = "− Sem venda nos últimos 180 dias";

        if ($pts_div >= 12) $motivos[] = "+ {$nClientes} clientes compradores (base diversificada)";
        elseif ($pts_div > 0) $motivos[] = "± {$nClientes} cliente(s) — base ainda pequena";
        else $motivos[] = "− Sem clientes no período";

        if ($pts_vol >= 10) $motivos[] = "+ Volume acima da média do período";
        else $motivos[] = "± Volume abaixo ou na média do período";

        if ($penalidade < 0) {
            $motivos[] = "− Concentração em top cliente ({$concentrationPct}%) penaliza o score";
        }

        return [
            'total'       => $total,
            'recorrencia' => $pts_rec,
            'recencia'    => $pts_ren,
            'diversidade' => $pts_div,
            'volume'      => $pts_vol,
            'penalidade'  => $penalidade,
            'motivos'     => $motivos,
        ];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * @return array<string,array{pct:float,top_client:string}>
     */
    private function buildConcentrationMap(string $start, string $end): array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT
                    COALESCE(NULLIF(TRIM(i.codigo_produto), ''), 'SEM_SKU') AS cp,
                    nf.codigo_cliente_omie                                  AS cid,
                    MAX(nf.nome_cliente)                                    AS cn,
                    SUM(i.quantidade)                                       AS cqty
                 FROM biomie_notas_fiscais_itens_canonicos i
                 INNER JOIN biomie_notas_fiscais_canonicas nf ON nf.nota_key = i.nota_key
                 WHERE nf.status_fiscal = 'autorizada'
                   AND nf.operation_scope = 'commercial'
                   AND {$this->dateExpr} BETWEEN :start AND :end
                   AND {$this->visCond}
                 GROUP BY cp, nf.codigo_cliente_omie"
            );
            $stmt->execute([':start' => $start, ':end' => $end]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }

        $totals = [];
        $tops   = [];
        foreach ($rows as $r) {
            $cp  = (string) ($r['cp']   ?? '');
            $qty = (float)  ($r['cqty'] ?? 0);
            if (!isset($totals[$cp])) {
                $totals[$cp] = 0.0;
                $tops[$cp]   = ['qty' => 0.0, 'name' => ''];
            }
            $totals[$cp] += $qty;
            if ($qty > $tops[$cp]['qty']) {
                $tops[$cp] = ['qty' => $qty, 'name' => (string) ($r['cn'] ?? '')];
            }
        }

        $result = [];
        foreach ($totals as $cp => $total) {
            $topQty = $tops[$cp]['qty'] ?? 0;
            $result[$cp] = [
                'pct'        => $total > 0 ? round($topQty / $total * 100, 1) : 0.0,
                'top_client' => $tops[$cp]['name'] ?? '',
            ];
        }
        return $result;
    }

    private function classifyOpportunity(array $r, int $score, float $concentrationPct): string
    {
        $diasSemVenda = (int) ($r['dias_sem_venda'] ?? 0);
        $mesesAtivos  = (int) ($r['meses_ativos']   ?? 0);
        $nClientes    = (int) ($r['clientes_periodo'] ?? 0);

        if ($score >= 75) return 'Produto campeão';
        if ($diasSemVenda >= 90) return 'Reativar produto';
        if ($concentrationPct >= 60) return 'Acompanhar concentração';
        if ($mesesAtivos >= 2 && $nClientes >= 2 && $score >= 40) return 'Campanha para clientes antigos';
        if ($nClientes >= 3 && $score >= 35) return 'Cross-sell com vendedores fortes';
        if ($mesesAtivos >= 1 && $score >= 20) return 'Produto sazonal';
        if ($score < 20) return 'Baixo giro';
        return 'Monitorar';
    }

    private function suggestAction(string $categoria, array $r, float $concentrationPct): string
    {
        return match ($categoria) {
            'Produto campeão'              => 'Manter presença e replicar para novos clientes',
            'Reativar produto'             => 'Contatar clientes anteriores — produto parado há ' . ($r['dias_sem_venda'] ?? 0) . ' dias',
            'Acompanhar concentração'      => 'Diversificar carteira — top cliente tem ' . $concentrationPct . '% das saídas',
            'Campanha para clientes antigos' => 'Criar campanha de recompra para ' . ($r['clientes_periodo'] ?? 0) . ' clientes',
            'Cross-sell com vendedores fortes' => 'Identificar vendedor mais aderente e ampliar base',
            'Produto sazonal'              => 'Monitorar sazonalidade e antecipar reposição',
            'Baixo giro'                   => 'Avaliar continuidade ou promoção de liquidação',
            default                        => 'Monitorar evolução no próximo período',
        };
    }

    private function repurchaseScore(int $nCompras, int $diasUltima, float $receita, int $mesesAtivos): int
    {
        // Frequency (40): mais compras = melhor
        $freq = min(1.0, $nCompras / 5.0);
        // Recency (40): quantos dias atrás — janela de 365 dias
        $rec  = max(0.0, 1.0 - $diasUltima / 365.0);
        // Monetary (20): normalizado — valor alto = melhor
        $mon  = min(1.0, $receita / 5000.0);

        return max(0, min(100, (int) round($freq * 40 + $rec * 40 + $mon * 20)));
    }

    private function repurchaseMotivo(int $score, array $r): string
    {
        $dias = (int) ($r['dias_desde_ultima'] ?? 0);
        $n    = (int) ($r['n_compras'] ?? 0);
        if ($score >= 70) return "Alta probabilidade — {$n} compra(s), última há {$dias} dias";
        if ($score >= 40) return "Média probabilidade — {$n} compra(s), {$dias} dias sem atividade";
        return "Baixa probabilidade — última compra há {$dias} dias";
    }
}

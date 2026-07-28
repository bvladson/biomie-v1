<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Gold\Builders;

/**
 * Recalcula apenas o resumo da ponte NF x titulo a partir dos vinculos ja
 * existentes. Nao cria, remove ou reclassifica vinculos de conciliacao.
 */
final class NfTituloBridgeSummaryBuilder extends AbstractMartBuilder
{
    public function refresh(): int
    {
        if (
            !$this->tableExists('biomie_bridge_nf_titulo_items')
            || !$this->tableExists('biomie_bridge_nf_titulo_summary')
            || !$this->tableExists('biomie_titulos_financeiros_canonicos')
        ) {
            $this->logger->warning('NfTituloBridgeSummaryBuilder: tabelas obrigatorias ausentes; refresh ignorado.');
            return 0;
        }

        $this->exec(
            'DELETE FROM biomie_bridge_nf_titulo_summary
              WHERE company_id = :company_id
                AND NOT EXISTS (
                    SELECT 1
                      FROM biomie_bridge_nf_titulo_items i
                      JOIN biomie_titulos_financeiros_canonicos t
                        ON t.company_id = i.company_id AND t.titulo_key = i.titulo_key
                     WHERE i.company_id = biomie_bridge_nf_titulo_summary.company_id
                       AND i.nota_key = biomie_bridge_nf_titulo_summary.nota_key
                )',
            ['company_id' => $this->companyId()],
            'materialize_bridge_summary',
            'biomie_bridge_nf_titulo_summary',
            'delete_orphan_bridge_summaries',
        );

        $sql = <<<'SQL'
            INSERT INTO biomie_bridge_nf_titulo_summary
                (company_id, nota_key, qtd_titulos, valor_titulos_total, valor_titulos_recebido,
                 valor_titulos_aberto, menor_vencimento, maior_vencimento,
                 min_confidence_score, best_match_method, has_manual_override, updated_at)
            SELECT
                bni.company_id,
                bni.nota_key,
                COUNT(DISTINCT bni.titulo_key) AS qtd_titulos,
                ROUND(SUM(COALESCE(t.valor_liquido, 0)), 2) AS valor_titulos_total,
                ROUND(GREATEST(
                    0,
                    SUM(COALESCE(t.valor_liquido, 0)) - SUM(COALESCE(t.valor_aberto, 0))
                ), 2) AS valor_titulos_recebido,
                ROUND(SUM(COALESCE(t.valor_aberto, 0)), 2) AS valor_titulos_aberto,
                MIN(t.data_vencimento) AS menor_vencimento,
                MAX(t.data_vencimento) AS maior_vencimento,
                MIN(bni.confidence_score) AS min_confidence_score,
                (
                    SELECT bni2.match_method
                    FROM biomie_bridge_nf_titulo_items bni2
                    WHERE bni2.company_id = bni.company_id
                      AND bni2.nota_key = bni.nota_key
                    ORDER BY bni2.confidence_score DESC, bni2.id ASC
                    LIMIT 1
                ) AS best_match_method,
                MAX(bni.is_manual_override) AS has_manual_override,
                CURRENT_TIMESTAMP
            FROM biomie_bridge_nf_titulo_items bni
            JOIN biomie_titulos_financeiros_canonicos t
              ON t.company_id = bni.company_id AND t.titulo_key = bni.titulo_key
            WHERE bni.company_id = :company_id
            GROUP BY bni.company_id, bni.nota_key
            ON DUPLICATE KEY UPDATE
                qtd_titulos            = VALUES(qtd_titulos),
                valor_titulos_total    = VALUES(valor_titulos_total),
                valor_titulos_recebido = VALUES(valor_titulos_recebido),
                valor_titulos_aberto   = VALUES(valor_titulos_aberto),
                menor_vencimento       = VALUES(menor_vencimento),
                maior_vencimento       = VALUES(maior_vencimento),
                min_confidence_score   = VALUES(min_confidence_score),
                best_match_method      = VALUES(best_match_method),
                has_manual_override    = VALUES(has_manual_override),
                updated_at             = CURRENT_TIMESTAMP
        SQL;

        return $this->exec(
            $sql,
            ['company_id' => $this->companyId()],
            'materialize_bridge_summary',
            'biomie_bridge_nf_titulo_summary',
            'refresh_existing_links_summary'
        );
    }
}

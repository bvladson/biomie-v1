<?php

declare(strict_types=1);

namespace BIomie\Modules\Geo\Infrastructure;

use BIomie\Support\TenantContext;
use PDO;

/**
 * Reconstrói as tabelas fato geográficas para um período dado.
 *
 * Extraído de CanonicalRepository (Fase 5).
 */
final class GeoFactReconstructor
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function reconstructGeoFactTables(string $start, string $end): void
    {
        if (TenantContext::companyId() <= 0) {
            throw new \RuntimeException('Selecione uma empresa antes de reconstruir os fatos geograficos.');
        }
        $this->rebuildVendasGeoFact($start, $end);
        $this->rebuildGeoVendasDiaria($start, $end);
    }

    private function rebuildVendasGeoFact(string $start, string $end): void
    {
        $this->pdo->prepare(
            'DELETE FROM biomie_vendas_geo_cliente_fact
             WHERE company_id = ? AND data_referencia BETWEEN ? AND ?'
        )->execute([TenantContext::companyId(), $start, $end]);

        $this->pdo->prepare(<<<'SQL'
            INSERT INTO biomie_vendas_geo_cliente_fact
                (company_id, data_referencia, nf_id, pedido_id, codigo_cliente_omie, codigo_vendedor_omie,
                 status_fiscal, uf, cidade, bairro, tipo_documento, segmento_macro,
                 is_recorrente, valor_total, valor_mercadorias, descontos, quantidade_itens)
            SELECT
                nf.company_id,
                COALESCE(nf.data_competencia, nf.data_emissao)                                   AS data_referencia,
                nf.id                                                                             AS nf_id,
                nf.pedido_id_omie                                                                 AS pedido_id,
                nf.codigo_cliente_omie,
                nf.codigo_vendedor_omie,
                nf.status_fiscal,
                g.uf,
                g.cidade_norm                                                                     AS cidade,
                COALESCE(NULLIF(g.bairro_oficial_nome_fortaleza, ''), NULLIF(g.bairro_norm, '')) AS bairro,
                cd.tipo_documento,
                COALESCE(cd.segmento_macro, 'PJ_SEM_CNAE')                                       AS segmento_macro,
                COALESCE(cd.is_recorrente, 0)                                                    AS is_recorrente,
                nf.valor_total,
                nf.valor_mercadorias,
                nf.valor_descontos                                                                AS descontos,
                nf.quantidade_itens
            FROM biomie_notas_fiscais_canonicas AS nf
            LEFT JOIN biomie_clientes_geo_dim AS g
                ON g.codigo_cliente_omie = nf.codigo_cliente_omie
               AND g.company_id = nf.company_id
            LEFT JOIN biomie_clientes_dim AS cd
                ON cd.codigo_cliente_omie = nf.codigo_cliente_omie
               AND cd.company_id = nf.company_id
            WHERE nf.company_id = ?
              AND nf.codigo_cliente_omie IS NOT NULL
              AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN ? AND ?
        SQL)->execute([TenantContext::companyId(), $start, $end]);
    }

    private function rebuildGeoVendasDiaria(string $start, string $end): void
    {
        $this->pdo->prepare(
            'DELETE FROM biomie_geo_vendas_diaria
             WHERE company_id = ? AND data_referencia BETWEEN ? AND ?'
        )->execute([TenantContext::companyId(), $start, $end]);

        $this->pdo->prepare(<<<'SQL'
            INSERT INTO biomie_geo_vendas_diaria
                (company_id, escopo_geo, data_referencia, uf, cidade_norm, bairro_norm,
                 segmento_macro, tipo_documento, is_recorrente,
                 vendas_qtd, clientes_unicos, valor_total, valor_mercadorias, ticket_medio)
            SELECT
                f.company_id,
                CASE
                    WHEN COALESCE(f.bairro, '') != '' AND COALESCE(f.cidade, '') != '' THEN 'bairro_fortaleza'
                    WHEN f.uf = 'CE' AND COALESCE(f.cidade, '') != ''                  THEN 'cidade_ce'
                    ELSE 'uf_brasil'
                END                                                      AS escopo_geo,
                f.data_referencia,
                COALESCE(f.uf, '')                                       AS uf,
                COALESCE(f.cidade, '')                                   AS cidade_norm,
                COALESCE(f.bairro, '')                                   AS bairro_norm,
                COALESCE(f.segmento_macro, 'OUTRO')                      AS segmento_macro,
                COALESCE(f.tipo_documento, 'PJ')                         AS tipo_documento,
                f.is_recorrente,
                COUNT(*)                                                 AS vendas_qtd,
                COUNT(DISTINCT f.codigo_cliente_omie)                   AS clientes_unicos,
                SUM(f.valor_total)                                       AS valor_total,
                SUM(f.valor_mercadorias)                                 AS valor_mercadorias,
                IF(COUNT(*) > 0, SUM(f.valor_total) / COUNT(*), 0)      AS ticket_medio
            FROM biomie_vendas_geo_cliente_fact AS f
            WHERE f.company_id = ?
              AND f.status_fiscal = 'autorizada'
              AND f.data_referencia BETWEEN ? AND ?
            GROUP BY f.company_id, escopo_geo, f.data_referencia, uf, cidade_norm, bairro_norm,
                     segmento_macro, tipo_documento, f.is_recorrente
            ON DUPLICATE KEY UPDATE
                vendas_qtd        = VALUES(vendas_qtd),
                clientes_unicos   = VALUES(clientes_unicos),
                valor_total       = VALUES(valor_total),
                valor_mercadorias = VALUES(valor_mercadorias),
                ticket_medio      = VALUES(ticket_medio)
        SQL)->execute([TenantContext::companyId(), $start, $end]);
    }

    private function normalizeGeoTextSql(string $col): string
    {
        return "TRIM(LOWER(REGEXP_REPLACE({$col}, '[^a-zA-Z0-9 ]', '')))";
    }
}

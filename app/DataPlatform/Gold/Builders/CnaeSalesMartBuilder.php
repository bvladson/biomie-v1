<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Gold\Builders;

final class CnaeSalesMartBuilder extends AbstractMartBuilder
{
    public function refresh(string $start, string $end): int
    {
        if (!$this->tableExists('mart_vendas_por_cnae_cnpj')) {
            $this->logger->warning('CnaeSalesMartBuilder: mart_vendas_por_cnae_cnpj ausente; materializacao opcional ignorada.');
            return 0;
        }

        $sql = <<<'SQL'
            INSERT INTO mart_vendas_por_cnae_cnpj (
                competencia, documento_normalizado, codigo_cliente_omie, cliente_nome,
                cnae_codigo, cnae_descricao, segmento_macro,
                uf, cidade_norm, bairro_norm,
                qtd_notas, faturamento, ticket_medio, primeira_compra, ultima_compra, refreshed_at
            )
            SELECT
                DATE_FORMAT(COALESCE(nf.data_competencia, nf.data_emissao), '%Y-%m'),
                nf.documento_cliente,
                nf.codigo_cliente_omie,
                MAX(nf.nome_cliente),
                MAX(dim.cnae_codigo),
                MAX(dim.cnae_descricao),
                COALESCE(MAX(dim.segmento_macro), 'Nao classificado'),
                COALESCE(MAX(geo.uf), 'NA'),
                COALESCE(MAX(geo.cidade_norm), 'NAO INFORMADA'),
                COALESCE(MAX(geo.bairro_norm), 'NAO INFORMADO'),
                COUNT(nf.id),
                SUM(nf.valor_total),
                SUM(nf.valor_total) / NULLIF(COUNT(nf.id), 0),
                MIN(nf.data_emissao),
                MAX(nf.data_emissao),
                NOW()
            FROM biomie_notas_fiscais_canonicas nf
            LEFT JOIN biomie_clientes_dim dim ON dim.codigo_cliente_omie = nf.codigo_cliente_omie
            LEFT JOIN biomie_clientes_geo_dim geo ON geo.codigo_cliente_omie = nf.codigo_cliente_omie
            WHERE nf.status_fiscal = 'autorizada'
              AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end
            GROUP BY
                DATE_FORMAT(COALESCE(nf.data_competencia, nf.data_emissao), '%Y-%m'),
                nf.codigo_cliente_omie,
                nf.documento_cliente
            ON DUPLICATE KEY UPDATE
                cliente_nome          = VALUES(cliente_nome),
                cnae_codigo           = VALUES(cnae_codigo),
                cnae_descricao        = VALUES(cnae_descricao),
                segmento_macro        = VALUES(segmento_macro),
                uf                    = VALUES(uf),
                cidade_norm           = VALUES(cidade_norm),
                bairro_norm           = VALUES(bairro_norm),
                qtd_notas             = VALUES(qtd_notas),
                faturamento           = VALUES(faturamento),
                ticket_medio          = VALUES(ticket_medio),
                primeira_compra       = VALUES(primeira_compra),
                ultima_compra         = VALUES(ultima_compra),
                refreshed_at          = NOW()
        SQL;

        return $this->exec($sql, ['start' => $start, 'end' => $end], 'materialize_vendas_cnae_cnpj', 'mart_vendas_por_cnae_cnpj', 'insert_mart_vendas_por_cnae_cnpj');
    }
}

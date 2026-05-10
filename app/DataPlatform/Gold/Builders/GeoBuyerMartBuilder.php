<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Gold\Builders;

final class GeoBuyerMartBuilder extends AbstractMartBuilder
{
    public function refresh(string $start, string $end): int
    {
        if (!$this->tableExists('mart_compradores_por_geo')) {
            $this->logger->warning('GeoBuyerMartBuilder: mart_compradores_por_geo ausente; materializacao opcional ignorada.');
            return 0;
        }

        $regiaoExpression = 'NULL';
        if ($this->columnExists('biomie_clientes_geo_dim', 'regiao_nome')) {
            $regiaoExpression = 'MAX(geo.regiao_nome)';
        } else {
            $this->logger->warning('GeoBuyerMartBuilder: coluna geo.regiao_nome ausente; regiao_nome sera materializada como NULL.');
        }

        $sql = <<<SQL
            INSERT INTO mart_compradores_por_geo (
                competencia, codigo_cliente_omie, cliente_nome, documento,
                uf, cidade_norm, bairro_norm, regiao_nome,
                cnae_codigo, cnae_descricao, segmento_macro,
                qtd_notas, faturamento, ticket_medio, ultima_compra, refreshed_at
            )
            SELECT
                DATE_FORMAT(COALESCE(nf.data_competencia, nf.data_emissao), '%Y-%m'),
                nf.codigo_cliente_omie,
                MAX(nf.nome_cliente),
                MAX(nf.documento_cliente),
                COALESCE(geo.uf, 'NA'),
                COALESCE(geo.cidade_norm, ''),
                COALESCE(geo.bairro_norm, ''),
                {$regiaoExpression},
                MAX(dim.cnae_codigo),
                MAX(dim.cnae_descricao),
                COALESCE(MAX(dim.segmento_macro), 'Nao classificado'),
                COUNT(nf.id),
                SUM(nf.valor_total),
                SUM(nf.valor_total) / NULLIF(COUNT(nf.id), 0),
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
                COALESCE(geo.uf, 'NA'),
                COALESCE(geo.cidade_norm, ''),
                COALESCE(geo.bairro_norm, '')
            ON DUPLICATE KEY UPDATE
                cliente_nome   = VALUES(cliente_nome),
                documento      = VALUES(documento),
                regiao_nome    = VALUES(regiao_nome),
                cnae_codigo    = VALUES(cnae_codigo),
                cnae_descricao = VALUES(cnae_descricao),
                segmento_macro = VALUES(segmento_macro),
                qtd_notas      = VALUES(qtd_notas),
                faturamento    = VALUES(faturamento),
                ticket_medio   = VALUES(ticket_medio),
                ultima_compra  = VALUES(ultima_compra),
                refreshed_at   = NOW()
        SQL;

        return $this->exec($sql, ['start' => $start, 'end' => $end], 'materialize_compradores_geo', 'mart_compradores_por_geo', 'insert_mart_compradores_por_geo');
    }
}

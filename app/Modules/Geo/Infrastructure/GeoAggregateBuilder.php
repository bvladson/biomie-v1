<?php

declare(strict_types=1);

namespace BIomie\Modules\Geo\Infrastructure;

use PDO;
use Throwable;

final class GeoAggregateBuilder
{
    public function __construct(private readonly PDO $pdo) {}

    public function buildCompradoresPorGeo(): int
    {
        $hasNomeExibicao   = $this->columnExists('biomie_clientes_dim', 'cliente_nome_exibicao');
        $hasDocMascarado   = $this->columnExists('biomie_clientes_dim', 'documento_mascarado');
        $hasDocNormalizado = $this->columnExists('biomie_clientes_dim', 'documento_normalizado');
        $hasBairroNorm     = $this->columnExists('biomie_clientes_dim', 'bairro_normalizado');
        $hasCidadeNorm     = $this->columnExists('biomie_clientes_dim', 'cidade_normalizada');
        $hasBairroRaw      = $this->columnExists('biomie_clientes_dim', 'bairro');
        $hasCidadeRaw      = $this->columnExists('biomie_clientes_dim', 'cidade');
        $hasUfInDim        = $this->columnExists('biomie_clientes_dim', 'uf');

        $nomeExpr = $hasNomeExibicao
            ? 'COALESCE(cd.cliente_nome_exibicao, cd.nome_cliente, cp.nome_cliente)'
            : 'COALESCE(cd.nome_cliente, cp.nome_cliente)';

        $docExpr = $hasDocMascarado  ? 'cd.documento_mascarado'
            : ($hasDocNormalizado    ? 'cd.documento_normalizado'
            : 'NULL');

        $bairroFallback = $hasBairroNorm ? "NULLIF(cd.bairro_normalizado, '')"
            : ($hasBairroRaw ? "NULLIF(LOWER(TRIM(cd.bairro)), '')" : null);
        $bairroExpr = $bairroFallback
            ? "COALESCE(NULLIF(g.bairro_oficial_nome_fortaleza, ''), NULLIF(g.bairro_norm, ''), {$bairroFallback}, '')"
            : "COALESCE(NULLIF(g.bairro_oficial_nome_fortaleza, ''), NULLIF(g.bairro_norm, ''), '')";

        $cidadeFallback = $hasCidadeNorm ? "NULLIF(cd.cidade_normalizada, '')"
            : ($hasCidadeRaw ? "NULLIF(LOWER(TRIM(cd.cidade)), '')" : null);
        $cidadeExpr = $cidadeFallback
            ? "COALESCE(NULLIF(g.cidade_norm, ''), {$cidadeFallback}, '')"
            : "COALESCE(g.cidade_norm, '')";

        $ufExpr = $hasUfInDim
            ? "COALESCE(NULLIF(g.uf, ''), NULLIF(cd.uf, ''), '')"
            : "COALESCE(g.uf, '')";

        $bairroDimJoin = $bairroFallback
            ? "COALESCE(NULLIF(g.bairro_norm, ''), NULLIF({$bairroFallback}, ''))"
            : "g.bairro_norm";

        $this->pdo->exec('DELETE FROM mart_compradores_por_geo');

        $stmt = $this->pdo->prepare(
            "INSERT INTO mart_compradores_por_geo
                 (competencia, codigo_cliente_omie, cliente_nome, documento,
                  uf, cidade_norm, bairro_norm, regiao_nome,
                  cnae_codigo, cnae_descricao, segmento_macro,
                  qtd_notas, faturamento, ticket_medio, ultima_compra, refreshed_at)
             SELECT
                 cp.competencia,
                 cp.codigo_cliente_omie,
                 {$nomeExpr}                                                          AS cliente_nome,
                 {$docExpr}                                                           AS documento,
                 {$ufExpr}                                                            AS uf,
                 {$cidadeExpr}                                                        AS cidade_norm,
                 {$bairroExpr}                                                        AS bairro_norm,
                 b.regional_atual                                                      AS regiao_nome,
                 cd.cnae_codigo,
                 cd.cnae_descricao,
                 COALESCE(cd.segmento_macro, 'PJ_SEM_CNAE')                          AS segmento_macro,
                 cp.nfes_emitidas                                                     AS qtd_notas,
                 cp.valor_nfe                                                         AS faturamento,
                 IF(cp.nfes_emitidas > 0, cp.valor_nfe / cp.nfes_emitidas, 0)       AS ticket_medio,
                 NULL                                                                 AS ultima_compra,
                 NOW()                                                                AS refreshed_at
             FROM mart_cliente_periodo AS cp
             LEFT JOIN biomie_clientes_dim AS cd ON cd.codigo_cliente_omie = cp.codigo_cliente_omie
             LEFT JOIN biomie_clientes_geo_dim AS g  ON g.codigo_cliente_omie = cp.codigo_cliente_omie
             LEFT JOIN biomie_bairros_fortaleza_dim AS b
               ON b.bairro_nome_normalizado = {$bairroDimJoin} AND b.ativo = 1
             WHERE cp.nfes_emitidas > 0 OR cp.valor_nfe > 0"
        );
        $stmt->execute();
        return $stmt->rowCount();
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
            );
            $stmt->execute([$table, $column]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }
}

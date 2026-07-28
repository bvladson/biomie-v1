<?php

declare(strict_types=1);

namespace BIomie\Modules\Geo\Infrastructure;

use BIomie\Support\TenantContext;
use PDO;
use Throwable;

final class GeoCnaeMartRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function ensureGeoDimSkeletons(bool $rebuild): int
    {
        $companyId = $this->companyId();
        if ($rebuild) {
            $stmt = $this->pdo->prepare('DELETE FROM biomie_clientes_geo_dim WHERE company_id = :company_id');
            $stmt->execute(['company_id' => $companyId]);
        }

        $hasBairro  = $this->columnExists('biomie_clientes_dim', 'bairro');
        $hasCidade  = $this->columnExists('biomie_clientes_dim', 'cidade');
        $hasUf      = $this->columnExists('biomie_clientes_dim', 'uf');
        $bairroExpr = $hasBairro ? 'd.bairro' : 'NULL';
        $cidadeExpr = $hasCidade ? 'd.cidade' : 'NULL';
        $ufExpr     = $hasUf     ? 'd.uf'     : 'NULL';

        $affected = $this->pdo->exec(
            "INSERT IGNORE INTO biomie_clientes_geo_dim
                 (company_id, codigo_cliente_omie, bairro_raw, cidade_raw, uf)
             SELECT d.company_id, d.codigo_cliente_omie, {$bairroExpr}, {$cidadeExpr}, {$ufExpr}
             FROM biomie_clientes_dim AS d
             LEFT JOIN biomie_clientes_geo_dim AS g
               ON g.codigo_cliente_omie = d.codigo_cliente_omie
              AND g.company_id = d.company_id
             WHERE d.company_id = {$companyId}
               AND g.id IS NULL"
        );

        if ($hasBairro) {
            try {
                $this->pdo->exec(
                    "UPDATE biomie_clientes_geo_dim AS g
                     JOIN biomie_clientes_dim AS d
                       ON d.codigo_cliente_omie = g.codigo_cliente_omie
                      AND d.company_id = g.company_id
                     SET g.bairro_raw = d.bairro,
                         g.cidade_raw = COALESCE(NULLIF(g.cidade_raw, ''), {$cidadeExpr}),
                         g.uf = COALESCE(NULLIF(g.uf, ''), {$ufExpr})
                     WHERE g.company_id = {$companyId}
                       AND (g.bairro_raw IS NULL OR g.bairro_raw = '')
                       AND d.bairro IS NOT NULL AND d.bairro != ''"
                );
            } catch (Throwable) {}
        }

        return $affected !== false ? (int) $affected : 0;
    }

    public function normalizeGeoFields(): int
    {
        try {
            $companyId = $this->companyId();
            $stmt = $this->pdo->prepare(
                "UPDATE biomie_clientes_geo_dim AS g
                 SET
                   g.bairro_norm = LOWER(TRIM(REGEXP_REPLACE(g.bairro_raw, '[[:space:]]+', ' '))),
                   g.cidade_norm = LOWER(TRIM(REGEXP_REPLACE(COALESCE(g.cidade_raw, ''), '[[:space:]]+', ' ')))
                 WHERE g.company_id = {$companyId}
                   AND (g.bairro_norm IS NULL OR g.bairro_norm = '')
                   AND g.bairro_raw IS NOT NULL
                   AND g.bairro_raw != ''"
            );
            $stmt->execute();
            return $stmt->rowCount();
        } catch (Throwable) {
            return 0;
        }
    }

    public function queueUnmatchedFortaleza(): int
    {
        $companyId = $this->companyId();
        $hasDimId  = $this->columnExists('biomie_clientes_para_enriquecer', 'cliente_dim_id');
        $hasMotivo = $this->columnExists('biomie_clientes_para_enriquecer', 'motivo');

        if ($hasDimId && $hasMotivo) {
            $stmt = $this->pdo->prepare(
                "INSERT INTO biomie_clientes_para_enriquecer
                     (company_id, codigo_cliente_omie, source_origin, status, motivo, cliente_dim_id, sugestao_bairro_normalizado)
                 SELECT
                     g.company_id,
                     g.codigo_cliente_omie,
                     'geo_materialize',
                     'pendente',
                     CASE
                         WHEN g.bairro_norm IS NULL OR g.bairro_norm = '' THEN 'sem_bairro'
                         ELSE 'bairro_sem_match'
                     END,
                     d.id,
                     g.bairro_norm
                 FROM biomie_clientes_geo_dim AS g
                 LEFT JOIN biomie_clientes_dim AS d
                   ON d.codigo_cliente_omie = g.codigo_cliente_omie
                  AND d.company_id = g.company_id
                 WHERE g.company_id = {$companyId}
                   AND g.bairro_oficial_id_fortaleza IS NULL
                 ON DUPLICATE KEY UPDATE
                     motivo = VALUES(motivo),
                     status = IF(status = 'concluido', 'pendente', status)"
            );
        } else {
            $stmt = $this->pdo->prepare(
                "INSERT IGNORE INTO biomie_clientes_para_enriquecer
                     (company_id, codigo_cliente_omie, source_origin, status)
                 SELECT g.company_id, g.codigo_cliente_omie, 'geo_materialize', 'pendente'
                 FROM biomie_clientes_geo_dim AS g
                 WHERE g.company_id = {$companyId}
                   AND g.bairro_oficial_id_fortaleza IS NULL"
            );
        }

        $stmt->execute();
        return $stmt->rowCount();
    }

    /**
     * @return array{0:int, 1:list<string>}
     */
    public function buildGeoFact(): array
    {
        $companyId = $this->companyId();
        $warnings = [];
        $qtyCol   = $this->pickColumn('mart_fiscal_nfe', ['quantidade_itens', 'qtd_itens', 'total_itens', 'itens_count', 'qtde_itens']);
        $qtyExpr  = $qtyCol !== null ? "COALESCE(nf.`{$qtyCol}`, 0)" : '0';
        if ($qtyCol === null) {
            $warnings[] = 'mart_fiscal_nfe: coluna quantidade_itens nao encontrada. Usando 0 como fallback.';
        }

        $delete = $this->pdo->prepare('DELETE FROM biomie_vendas_geo_cliente_fact WHERE company_id = :company_id');
        $delete->execute(['company_id' => $companyId]);

        $stmt = $this->pdo->prepare(
            "INSERT INTO biomie_vendas_geo_cliente_fact
                 (company_id, data_referencia, nf_id, pedido_id, codigo_cliente_omie, codigo_vendedor_omie,
                  status_fiscal, uf, cidade, bairro, tipo_documento, segmento_macro,
                  is_recorrente, valor_total, valor_mercadorias, descontos, quantidade_itens)
             SELECT
                 nf.company_id,
                 COALESCE(nf.data_competencia, nf.data_emissao)                                  AS data_referencia,
                 nf.id                                                                            AS nf_id,
                 nf.pedido_id_omie                                                                AS pedido_id,
                 nf.codigo_cliente_omie,
                 nf.codigo_vendedor_omie,
                 nf.status_fiscal,
                 g.uf,
                 g.cidade_norm                                                                    AS cidade,
                 COALESCE(NULLIF(g.bairro_oficial_nome_fortaleza, ''), NULLIF(g.bairro_norm, '')) AS bairro,
                 cd.tipo_documento,
                 COALESCE(cd.segmento_macro, 'PJ_SEM_CNAE')                                      AS segmento_macro,
                 0                                                                                AS is_recorrente,
                 nf.valor_total,
                 nf.valor_mercadorias,
                 nf.valor_descontos                                                               AS descontos,
                 {$qtyExpr}                                                                       AS quantidade_itens
             FROM mart_fiscal_nfe AS nf
             LEFT JOIN biomie_clientes_geo_dim AS g
               ON g.codigo_cliente_omie = nf.codigo_cliente_omie AND g.company_id = nf.company_id
             LEFT JOIN biomie_clientes_dim AS cd
               ON cd.codigo_cliente_omie = nf.codigo_cliente_omie AND cd.company_id = nf.company_id
             WHERE nf.company_id = {$companyId}
               AND nf.business_channel = 'varejo'
               AND nf.status_fiscal = 'autorizada'
               AND nf.codigo_cliente_omie IS NOT NULL
               AND COALESCE(nf.data_competencia, nf.data_emissao) IS NOT NULL"
        );
        $stmt->execute();
        return [$stmt->rowCount(), $warnings];
    }

    public function buildGeoDaily(): int
    {
        $companyId = $this->companyId();
        $delete = $this->pdo->prepare('DELETE FROM biomie_geo_vendas_diaria WHERE company_id = :company_id');
        $delete->execute(['company_id' => $companyId]);

        $stmt = $this->pdo->prepare(
            "INSERT INTO biomie_geo_vendas_diaria
                 (company_id, escopo_geo, data_referencia, uf, cidade_norm, bairro_norm,
                  segmento_macro, tipo_documento, is_recorrente,
                  vendas_qtd, clientes_unicos, valor_total, valor_mercadorias, ticket_medio)
             SELECT
                 f.company_id,
                 CASE
                     WHEN COALESCE(f.bairro, '') != '' AND COALESCE(f.cidade, '') != '' THEN 'bairro_fortaleza'
                     WHEN f.uf = 'CE' AND COALESCE(f.cidade, '') != ''                  THEN 'cidade_ce'
                     ELSE 'uf_brasil'
                 END                                                     AS escopo_geo,
                 f.data_referencia,
                 COALESCE(f.uf, '')                                      AS uf,
                 COALESCE(f.cidade, '')                                  AS cidade_norm,
                 COALESCE(f.bairro, '')                                  AS bairro_norm,
                 COALESCE(f.segmento_macro, 'OUTRO')                     AS segmento_macro,
                 COALESCE(f.tipo_documento, 'PJ')                        AS tipo_documento,
                 f.is_recorrente,
                 COUNT(*)                                                AS vendas_qtd,
                 COUNT(DISTINCT f.codigo_cliente_omie)                  AS clientes_unicos,
                 SUM(f.valor_total)                                      AS valor_total,
                 SUM(f.valor_mercadorias)                                AS valor_mercadorias,
                 IF(COUNT(*) > 0, SUM(f.valor_total) / COUNT(*), 0)     AS ticket_medio
             FROM biomie_vendas_geo_cliente_fact AS f
             WHERE f.company_id = {$companyId}
               AND f.status_fiscal = 'autorizada'
             GROUP BY f.company_id, escopo_geo, f.data_referencia, uf, cidade_norm, bairro_norm,
                      segmento_macro, tipo_documento, f.is_recorrente
             ON DUPLICATE KEY UPDATE
                 vendas_qtd        = VALUES(vendas_qtd),
                 clientes_unicos   = VALUES(clientes_unicos),
                 valor_total       = VALUES(valor_total),
                 valor_mercadorias = VALUES(valor_mercadorias),
                 ticket_medio      = VALUES(ticket_medio)"
        );
        $stmt->execute();
        return $stmt->rowCount();
    }

    private function companyId(): int
    {
        $companyId = TenantContext::companyId();
        if ($companyId <= 0) {
            throw new \RuntimeException('Selecione uma empresa antes de materializar Geo/CNAE.');
        }
        return $companyId;
    }

    /** @param list<string> $candidates */
    private function pickColumn(string $table, array $candidates): ?string
    {
        foreach ($candidates as $col) {
            if ($this->columnExists($table, $col)) {
                return $col;
            }
        }
        return null;
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

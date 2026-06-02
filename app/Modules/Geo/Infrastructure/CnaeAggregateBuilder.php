<?php

declare(strict_types=1);

namespace BIomie\Modules\Geo\Infrastructure;

use BIomie\Support\Geo\GeoKeyNormalizer;
use BIomie\TableHealthService;
use PDO;
use Throwable;

final class CnaeAggregateBuilder
{
    public function __construct(
        private readonly PDO                $pdo,
        private readonly TableHealthService $tableHealth,
    ) {}

    public function buildVendasPorCnae(): int
    {
        if (!$this->columnExists('biomie_clientes_dim', 'cnae_codigo')) {
            return 0;
        }

        $nfCnaeCol   = $this->pickColumn('biomie_notas_fiscais_canonicas', ['cnae_codigo', 'cnae', 'cnae_principal']);
        $pedCnaeCol  = $this->pickColumn('biomie_pedidos_canonicos',       ['cnae_codigo', 'cnae', 'cnae_principal']);
        $hasNfTable  = $nfCnaeCol  !== null && $this->tableHealth->tableExists('biomie_notas_fiscais_canonicas');
        $hasPedTable = $pedCnaeCol !== null && $this->tableHealth->tableExists('biomie_pedidos_canonicos');

        $countWithCnaeInDim = 0;
        $countNfCnae        = 0;
        $countPedCnae       = 0;

        try {
            $countWithCnaeInDim = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM biomie_clientes_dim WHERE cnae_codigo IS NOT NULL AND cnae_codigo != ''"
            )->fetchColumn();
        } catch (Throwable) {}

        if ($hasNfTable) {
            try {
                $countNfCnae = (int) $this->pdo->query(
                    "SELECT COUNT(DISTINCT codigo_cliente_omie) FROM biomie_notas_fiscais_canonicas
                     WHERE `{$nfCnaeCol}` IS NOT NULL AND `{$nfCnaeCol}` != ''"
                )->fetchColumn();
            } catch (Throwable) {}
        }

        if ($hasPedTable) {
            try {
                $countPedCnae = (int) $this->pdo->query(
                    "SELECT COUNT(DISTINCT codigo_cliente_omie) FROM biomie_pedidos_canonicos
                     WHERE `{$pedCnaeCol}` IS NOT NULL AND `{$pedCnaeCol}` != ''"
                )->fetchColumn();
            } catch (Throwable) {}
        }

        if ($countWithCnaeInDim === 0 && $countNfCnae === 0 && $countPedCnae === 0) {
            return 0;
        }

        $this->pdo->exec('DELETE FROM mart_vendas_por_cnae_cnpj');

        $hasNomeExibicao = $this->columnExists('biomie_clientes_dim', 'cliente_nome_exibicao');
        $hasDocNorm      = $this->columnExists('biomie_clientes_dim', 'documento_normalizado');
        $hasSourceCol    = $this->columnExists('mart_vendas_por_cnae_cnpj', 'cnae_source');
        $hasConfCol      = $this->columnExists('mart_vendas_por_cnae_cnpj', 'cnae_confidence');
        $hasUpdatedAt    = $this->columnExists('mart_vendas_por_cnae_cnpj', 'updated_at');
        $hasCnaeDim      = $this->tableHealth->tableExists('biomie_cnae_dim')
            && $this->columnExists('biomie_cnae_dim', 'cnae_codigo_normalizado')
            && $this->columnExists('biomie_cnae_dim', 'cnae_descricao');

        $nomeExpr = $hasNomeExibicao
            ? 'COALESCE(cd.cliente_nome_exibicao, cd.nome_cliente, cp.nome_cliente)'
            : 'COALESCE(cd.nome_cliente, cp.nome_cliente)';
        $docExpr  = $hasDocNorm ? 'cd.documento_normalizado' : 'NULL';

        $joinFragments  = [];
        $coalesceFields = ['cd.cnae_codigo'];

        if ($hasNfTable) {
            $joinFragments['nf'] = "LEFT JOIN (
                SELECT codigo_cliente_omie, MAX(`{$nfCnaeCol}`) AS nf_cnae
                FROM biomie_notas_fiscais_canonicas
                WHERE `{$nfCnaeCol}` IS NOT NULL AND `{$nfCnaeCol}` != ''
                GROUP BY codigo_cliente_omie
            ) cnae_nf ON cnae_nf.codigo_cliente_omie = cp.codigo_cliente_omie";
            array_unshift($coalesceFields, 'cnae_nf.nf_cnae');
        }

        if ($hasPedTable) {
            $joinFragments['ped'] = "LEFT JOIN (
                SELECT codigo_cliente_omie, MAX(`{$pedCnaeCol}`) AS ped_cnae
                FROM biomie_pedidos_canonicos
                WHERE `{$pedCnaeCol}` IS NOT NULL AND `{$pedCnaeCol}` != ''
                GROUP BY codigo_cliente_omie
            ) cnae_ped ON cnae_ped.codigo_cliente_omie = cp.codigo_cliente_omie";
            $dimIdx = array_search('cd.cnae_codigo', $coalesceFields, true);
            array_splice($coalesceFields, (int) $dimIdx, 0, ['cnae_ped.ped_cnae']);
        }

        $cnaeCoalesce = count($coalesceFields) > 1
            ? 'COALESCE(' . implode(', ', $coalesceFields) . ')'
            : $coalesceFields[0];
        $joinSql = !empty($joinFragments) ? implode("\n             ", $joinFragments) : '';
        $cnaeDimJoin = $hasCnaeDim
            ? "LEFT JOIN biomie_cnae_dim AS cdim ON cdim.cnae_codigo_normalizado = REGEXP_REPLACE({$cnaeCoalesce}, '[^0-9]', '')"
            : '';
        $cnaeDescExpr = $hasCnaeDim
            ? "COALESCE(NULLIF(cdim.cnae_descricao, ''), NULLIF(cd.cnae_descricao, ''), 'Sem CNAE informado')"
            : "COALESCE(NULLIF(cd.cnae_descricao, ''), 'Sem CNAE informado')";

        $insertOptionalCols = '';
        $selectOptionalCols = '';

        if ($hasSourceCol) {
            $whenParts = [];
            if (isset($joinFragments['nf']))  { $whenParts[] = "WHEN cnae_nf.nf_cnae IS NOT NULL THEN 'nf_canonical'"; }
            if (isset($joinFragments['ped'])) { $whenParts[] = "WHEN cnae_ped.ped_cnae IS NOT NULL THEN 'pedido_canonical'"; }
            $whenParts[]         = "WHEN cd.cnae_codigo IS NOT NULL AND cd.cnae_codigo != '' THEN 'clientes_dim'";
            $insertOptionalCols .= ', cnae_source';
            $selectOptionalCols .= ', CASE ' . implode(' ', $whenParts) . " ELSE 'unknown' END";
        }

        if ($hasConfCol) {
            $whenParts = [];
            if (isset($joinFragments['nf']))  { $whenParts[] = 'WHEN cnae_nf.nf_cnae IS NOT NULL THEN 90'; }
            if (isset($joinFragments['ped'])) { $whenParts[] = 'WHEN cnae_ped.ped_cnae IS NOT NULL THEN 85'; }
            $whenParts[]         = "WHEN cd.cnae_codigo IS NOT NULL AND cd.cnae_codigo != '' THEN 80";
            $insertOptionalCols .= ', cnae_confidence';
            $selectOptionalCols .= ', CASE ' . implode(' ', $whenParts) . ' ELSE 0 END';
        }

        if ($hasUpdatedAt) {
            $insertOptionalCols .= ', updated_at';
            $selectOptionalCols .= ', NOW()';
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO mart_vendas_por_cnae_cnpj
                 (competencia, documento_normalizado, codigo_cliente_omie, cliente_nome,
                  cnae_codigo, cnae_descricao, segmento_macro,
                  uf, cidade_norm, bairro_norm,
                  qtd_notas, faturamento, ticket_medio,
                  primeira_compra, ultima_compra, refreshed_at{$insertOptionalCols})
             SELECT
                 cp.competencia,
                 {$docExpr},
                 cp.codigo_cliente_omie,
                 {$nomeExpr}                                                          AS cliente_nome,
                 {$cnaeCoalesce}                                                      AS cnae_codigo,
                 {$cnaeDescExpr},
                 COALESCE(cd.segmento_macro, 'PJ_SEM_CNAE')                          AS segmento_macro,
                 COALESCE(g.uf, '')                                                   AS uf,
                 COALESCE(g.cidade_norm, '')                                          AS cidade_norm,
                 COALESCE(NULLIF(g.bairro_oficial_nome_fortaleza, ''),
                           NULLIF(g.bairro_norm, ''), '')                             AS bairro_norm,
                 cp.nfes_emitidas                                                     AS qtd_notas,
                 cp.valor_nfe                                                         AS faturamento,
                 IF(cp.nfes_emitidas > 0, cp.valor_nfe / cp.nfes_emitidas, 0)       AS ticket_medio,
                 NULL                                                                 AS primeira_compra,
                 NULL                                                                 AS ultima_compra,
                 NOW()                                                                AS refreshed_at
                 {$selectOptionalCols}
             FROM mart_cliente_periodo AS cp
             LEFT JOIN biomie_clientes_dim AS cd ON cd.codigo_cliente_omie = cp.codigo_cliente_omie
             LEFT JOIN biomie_clientes_geo_dim AS g  ON g.codigo_cliente_omie = cp.codigo_cliente_omie
             {$cnaeDimJoin}
             {$joinSql}
             WHERE (cp.nfes_emitidas > 0 OR cp.valor_nfe > 0)
               AND {$cnaeCoalesce} IS NOT NULL
               AND {$cnaeCoalesce} != ''"
        );
        $stmt->execute();
        $rows = $stmt->rowCount();
        $this->backfillGeoKeys();

        return $rows;
    }

    private function backfillGeoKeys(): void
    {
        if (!$this->columnExists('mart_vendas_por_cnae_cnpj', 'bairro_key')) {
            return;
        }

        $stmt = $this->pdo->query(
            "SELECT DISTINCT cidade_norm, bairro_norm
             FROM mart_vendas_por_cnae_cnpj
             WHERE bairro_key IS NULL OR bairro_key = ''"
        );
        $pairs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($pairs === []) {
            return;
        }

        $hasCidadeKey = $this->columnExists('mart_vendas_por_cnae_cnpj', 'cidade_key');
        $upFields = ['bairro_key = :bk', 'bairro_norm = :bn'];
        if ($hasCidadeKey) {
            $upFields[] = 'cidade_key = :ck';
        }

        $up = $this->pdo->prepare(
            "UPDATE mart_vendas_por_cnae_cnpj
             SET " . implode(', ', $upFields) . "
             WHERE cidade_norm <=> :city_raw
               AND bairro_norm <=> :bairro_raw
               AND (bairro_key IS NULL OR bairro_key = '')"
        );

        foreach ($pairs as $pair) {
            $cityRaw = $pair['cidade_norm'];
            $bairroRaw = $pair['bairro_norm'];
            $cityText = $cityRaw === null ? '' : (string) $cityRaw;
            $bairroText = $bairroRaw === null ? '' : (string) $bairroRaw;
            $bairroKey = GeoKeyNormalizer::makeNeighborhoodKey($bairroText);

            if ($bairroKey === '') {
                continue;
            }

            $params = [
                ':bk' => $bairroKey,
                ':bn' => GeoKeyNormalizer::normalizeNeighborhood($bairroText),
                ':city_raw' => $cityRaw,
                ':bairro_raw' => $bairroRaw,
            ];
            if ($hasCidadeKey) {
                $params[':ck'] = GeoKeyNormalizer::makeCityKey($cityText);
            }

            $up->execute($params);
        }
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

<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Gold\Builders;

use BIomie\Support\Geo\GeoKeyNormalizer;
use PDO;

final class CnaeSalesMartBuilder extends AbstractMartBuilder
{
    public function refresh(string $start, string $end): int
    {
        if (!$this->tableExists('mart_vendas_por_cnae_cnpj')) {
            $this->logger->warning('CnaeSalesMartBuilder: mart_vendas_por_cnae_cnpj ausente; materializacao opcional ignorada.');
            return 0;
        }

        // Delete-before-insert: removes stale rows when NFs are reclassified (scope changes).
        $this->exec(
            "DELETE FROM mart_vendas_por_cnae_cnpj
             WHERE competencia BETWEEN DATE_FORMAT(:start, '%Y-%m') AND DATE_FORMAT(:end, '%Y-%m')",
            ['start' => $start, 'end' => $end],
            'materialize_vendas_cnae_cnpj',
            'mart_vendas_por_cnae_cnpj',
            'delete_mart_vendas_cnae_periodo',
        );

        // Align INSERT to first day of month — DELETE removes the full month, so the
        // INSERT must cover from day 1, not from the potentially mid-month $start.
        $monthStart = date('Y-m-01', strtotime($start));

        $scopeFilter      = $this->commercialScopeFragment('biomie_notas_fiscais_canonicas', 'nf');
        $visibilityFilter = $this->visibilityFragment('biomie_notas_fiscais_canonicas', 'nf');
        // Fase O: use fiscal basis formula when columns exist; fall back to valor_total pre-migration.
        $nfBasisExpr = $this->columnExists('biomie_notas_fiscais_canonicas', 'valor_frete')
            ? \BIomie\Support\FiscalValueBasis::commercialBaseSql('nf')
            : 'nf.valor_total';
        $hasCnaeDim = $this->tableExists('biomie_cnae_dim')
            && $this->columnExists('biomie_cnae_dim', 'cnae_codigo_normalizado')
            && $this->columnExists('biomie_cnae_dim', 'cnae_descricao');
        $hasBairroOficial = $this->columnExists('biomie_clientes_geo_dim', 'bairro_oficial_nome_fortaleza');
        $hasBairroNormDim = $this->columnExists('biomie_clientes_dim', 'bairro_normalizado');
        $hasBairroRawDim  = !$hasBairroNormDim && $this->columnExists('biomie_clientes_dim', 'bairro');
        $hasCidadeNormDim = $this->columnExists('biomie_clientes_dim', 'cidade_normalizada');
        $hasCidadeRawDim  = !$hasCidadeNormDim && $this->columnExists('biomie_clientes_dim', 'cidade');
        $hasUfDim         = $this->columnExists('biomie_clientes_dim', 'uf');

        $bairroExpr = CompradoresPorGeo::bairroNormSql($hasBairroOficial, $hasBairroNormDim, $hasBairroRawDim, 'geo', 'dim');
        $cidadeExpr = CompradoresPorGeo::cidadeNormSql($hasCidadeNormDim, $hasCidadeRawDim, 'geo', 'dim');
        $ufExpr     = CompradoresPorGeo::ufSql($hasUfDim, 'geo', 'dim', 'NA');

        $cnaeDimJoin = $hasCnaeDim
            ? "LEFT JOIN biomie_cnae_dim cdim ON cdim.cnae_codigo_normalizado = REGEXP_REPLACE(COALESCE(dim.cnae_codigo, 'SEM_CNAE'), '[^0-9]', '')"
            : '';
        $cnaeDescExpr = $hasCnaeDim
            ? "COALESCE(NULLIF(MAX(cdim.cnae_descricao), ''), NULLIF(MAX(dim.cnae_descricao), ''), 'Sem CNAE informado')"
            : "COALESCE(NULLIF(MAX(dim.cnae_descricao), ''), 'Sem CNAE informado')";
        $sql = <<<SQL
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
                COALESCE(MAX(dim.cnae_codigo),    'SEM_CNAE'),
                {$cnaeDescExpr},
                COALESCE(MAX(dim.segmento_macro), 'Nao classificado'),
                MAX({$ufExpr}),
                MAX({$cidadeExpr}),
                MAX({$bairroExpr}),
                COUNT(nf.id),
                SUM({$nfBasisExpr}),
                SUM({$nfBasisExpr}) / NULLIF(COUNT(nf.id), 0),
                MIN(nf.data_emissao),
                MAX(nf.data_emissao),
                NOW()
            FROM biomie_notas_fiscais_canonicas nf
            LEFT JOIN biomie_clientes_dim dim ON dim.codigo_cliente_omie = nf.codigo_cliente_omie
            LEFT JOIN biomie_clientes_geo_dim geo ON geo.codigo_cliente_omie = nf.codigo_cliente_omie
            {$cnaeDimJoin}
            WHERE nf.status_fiscal = 'autorizada'
              AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end{$scopeFilter}{$visibilityFilter}
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

        $inserted = $this->exec($sql, ['start' => $monthStart, 'end' => $end], 'materialize_vendas_cnae_cnpj', 'mart_vendas_por_cnae_cnpj', 'insert_mart_vendas_por_cnae_cnpj');
        $this->backfillGeoKeys(substr($monthStart, 0, 7), substr($end, 0, 7));

        return $inserted;
    }

    private function backfillGeoKeys(string $startComp, string $endComp): void
    {
        if (!$this->columnExists('mart_vendas_por_cnae_cnpj', 'bairro_key')) {
            return;
        }

        $hasCidadeKey = $this->columnExists('mart_vendas_por_cnae_cnpj', 'cidade_key');
        $needsKeySql = $hasCidadeKey
            ? "((bairro_key IS NULL OR bairro_key = '') OR (cidade_key IS NULL OR cidade_key = ''))"
            : "(bairro_key IS NULL OR bairro_key = '')";

        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT cidade_norm, bairro_norm
             FROM mart_vendas_por_cnae_cnpj
             WHERE competencia BETWEEN :start_comp AND :end_comp
               AND {$needsKeySql}"
        );
        $stmt->execute(['start_comp' => $startComp, 'end_comp' => $endComp]);
        $pairs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($pairs === []) {
            return;
        }

        $setFields = ['bairro_key = :bairro_key', 'bairro_norm = :bairro_norm'];
        if ($hasCidadeKey) {
            $setFields[] = 'cidade_key = :cidade_key';
            $setFields[] = 'cidade_norm = :cidade_norm';
        }

        $update = $this->pdo->prepare(
            "UPDATE mart_vendas_por_cnae_cnpj
             SET " . implode(', ', $setFields) . "
             WHERE competencia BETWEEN :start_comp AND :end_comp
               AND cidade_norm <=> :city_raw
               AND bairro_norm <=> :bairro_raw
               AND {$needsKeySql}"
        );

        foreach ($pairs as $pair) {
            $cityRaw = $pair['cidade_norm'];
            $bairroRaw = $pair['bairro_norm'];
            $cityText = $cityRaw === null ? '' : (string) $cityRaw;
            $bairroText = $bairroRaw === null ? '' : (string) $bairroRaw;
            $bairroKey = GeoKeyNormalizer::makeNeighborhoodKey($bairroText);

            $params = [
                'bairro_key' => $bairroKey,
                'bairro_norm' => GeoKeyNormalizer::normalizeNeighborhood($bairroText),
                'start_comp' => $startComp,
                'end_comp' => $endComp,
                'city_raw' => $cityRaw,
                'bairro_raw' => $bairroRaw,
            ];
            if ($hasCidadeKey) {
                $params['cidade_key'] = GeoKeyNormalizer::makeCityKey($cityText);
                $params['cidade_norm'] = GeoKeyNormalizer::normalizeCity($cityText);
            }

            $update->execute($params);
        }
    }
}

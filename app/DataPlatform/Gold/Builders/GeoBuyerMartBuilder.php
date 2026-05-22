<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Gold\Builders;

final class GeoBuyerMartBuilder extends AbstractMartBuilder
{
    /**
     * Rebuilds the geo mart with delete+insert scoped to affected competencies.
     *
     * @param list<string> $competencias
     * @return array<string,mixed>
     */
    public function refreshReplace(string $start, string $end, array $competencias): array
    {
        if (!$this->tableExists('mart_compradores_por_geo')) {
            $this->logger->warning('GeoBuyerMartBuilder: mart_compradores_por_geo ausente; materializacao opcional ignorada.');
            return [
                'deleted_rows' => 0,
                'inserted_rows' => 0,
                'final_rows' => 0,
                'faturamento' => 0.0,
                'bairros_distintos' => 0,
                'clientes_distintos' => 0,
                'notas' => 0,
            ];
        }

        $startComp = substr($start, 0, 7);
        $endComp = substr($end, 0, 7);
        if ($competencias !== []) {
            $startComp = min($competencias);
            $endComp = max($competencias);
        }

        $deleted = $this->exec(
            'DELETE FROM mart_compradores_por_geo WHERE competencia BETWEEN :start_comp AND :end_comp',
            ['start_comp' => $startComp, 'end_comp' => $endComp],
            'materialize_compradores_geo',
            'mart_compradores_por_geo',
            'delete_mart_compradores_por_geo_periodo'
        );

        $inserted = $this->refresh($start, $end);
        $summary = $this->periodSummary($startComp, $endComp);

        return [
            'deleted_rows' => $deleted,
            'inserted_rows' => $inserted,
            'final_rows' => (int) ($summary['final_rows'] ?? 0),
            'faturamento' => (float) ($summary['faturamento'] ?? 0),
            'bairros_distintos' => (int) ($summary['bairros_distintos'] ?? 0),
            'clientes_distintos' => (int) ($summary['clientes_distintos'] ?? 0),
            'notas' => (int) ($summary['notas'] ?? 0),
        ];
    }

    public function refresh(string $start, string $end): int
    {
        if (!$this->tableExists('mart_compradores_por_geo')) {
            $this->logger->warning('GeoBuyerMartBuilder: mart_compradores_por_geo ausente; materializacao opcional ignorada.');
            return 0;
        }

        // ── Column probe ──────────────────────────────────────────────────────

        $regiaoExpression = 'NULL';
        if ($this->columnExists('biomie_clientes_geo_dim', 'regiao_nome')) {
            $regiaoExpression = 'MAX(geo.regiao_nome)';
        } else {
            $this->logger->warning('GeoBuyerMartBuilder: coluna geo.regiao_nome ausente; regiao_nome sera materializada como NULL.');
        }

        // Bairro resolution: probe columns needed for the fallback chain.
        $hasBairroOficial = $this->columnExists('biomie_clientes_geo_dim', 'bairro_oficial_nome_fortaleza');
        $hasBairroNormDim = $this->columnExists('biomie_clientes_dim', 'bairro_normalizado');
        $hasBairroRawDim  = !$hasBairroNormDim && $this->columnExists('biomie_clientes_dim', 'bairro');

        // geo_quality and geo_scope are optional columns added by migration.
        $hasGeoQualityCol = $this->columnExists('mart_compradores_por_geo', 'geo_quality');
        $hasGeoScopeCol   = $this->columnExists('mart_compradores_por_geo', 'geo_scope');

        // Fase H: filter by commercial operation scope if column exists.
        $operationScopeFilter = $this->columnExists('biomie_notas_fiscais_canonicas', 'operation_scope')
            ? "\n              AND nf.operation_scope = 'commercial'"
            : '';

        // Fase O: use fiscal basis formula when columns exist; fall back to valor_total pre-migration.
        $nfBasisExpr = $this->columnExists('biomie_notas_fiscais_canonicas', 'valor_frete')
            ? \BIomie\Support\FiscalValueBasis::commercialBaseSql('nf')
            : 'nf.valor_total';

        // ── Build expressions using shared helper ─────────────────────────────

        // In this builder the geo_dim alias is 'geo' and clientes_dim is 'dim'.
        $bairroExpr     = CompradoresPorGeo::bairroNormSql($hasBairroOficial, $hasBairroNormDim, $hasBairroRawDim, 'geo', 'dim');
        $geoQualityExpr = CompradoresPorGeo::geoQualitySql($hasBairroOficial, 'geo');
        $geoScopeExpr   = CompradoresPorGeo::geoScopeSql('geo');

        // ── Dynamic column lists ───────────────────────────────────────────────

        $extraInsertCols   = '';
        $extraSelectVals   = '';
        $extraUpsertFields = '';

        if ($hasGeoQualityCol) {
            $extraInsertCols   .= ', geo_quality';
            $extraSelectVals   .= ",\n        {$geoQualityExpr}";
            $extraUpsertFields .= ",\n            geo_quality   = " . CompradoresPorGeo::geoQualityUpsertSql();
        }
        if ($hasGeoScopeCol) {
            $extraInsertCols   .= ', geo_scope';
            $extraSelectVals   .= ",\n        {$geoScopeExpr}";
            $extraUpsertFields .= ",\n            geo_scope     = " . CompradoresPorGeo::geoScopeUpsertSql();
        }

        $sql = "
            INSERT INTO mart_compradores_por_geo (
                competencia, codigo_cliente_omie, cliente_nome, documento,
                uf, cidade_norm, bairro_norm, regiao_nome,
                cnae_codigo, cnae_descricao, segmento_macro,
                qtd_notas, faturamento, ticket_medio, ultima_compra, refreshed_at{$extraInsertCols}
            )
            SELECT
                DATE_FORMAT(COALESCE(nf.data_competencia, nf.data_emissao), '%Y-%m'),
                nf.codigo_cliente_omie,
                MAX(nf.nome_cliente),
                MAX(nf.documento_cliente),
                COALESCE(geo.uf, 'NA'),
                COALESCE(geo.cidade_norm, ''),
                {$bairroExpr},
                {$regiaoExpression},
                MAX(dim.cnae_codigo),
                MAX(dim.cnae_descricao),
                COALESCE(MAX(dim.segmento_macro), 'Nao classificado'),
                COUNT(nf.id),
                SUM({$nfBasisExpr}),
                SUM({$nfBasisExpr}) / NULLIF(COUNT(nf.id), 0),
                MAX(nf.data_emissao),
                NOW(){$extraSelectVals}
            FROM biomie_notas_fiscais_canonicas nf
            LEFT JOIN biomie_clientes_dim dim ON dim.codigo_cliente_omie = nf.codigo_cliente_omie
            LEFT JOIN biomie_clientes_geo_dim geo ON geo.codigo_cliente_omie = nf.codigo_cliente_omie
            WHERE nf.status_fiscal = 'autorizada'
              AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end{$operationScopeFilter}
            GROUP BY
                DATE_FORMAT(COALESCE(nf.data_competencia, nf.data_emissao), '%Y-%m'),
                nf.codigo_cliente_omie,
                COALESCE(geo.uf, 'NA'),
                COALESCE(geo.cidade_norm, ''),
                {$bairroExpr}
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
                refreshed_at   = NOW(){$extraUpsertFields}
        ";

        return $this->exec($sql, ['start' => $start, 'end' => $end], 'materialize_compradores_geo', 'mart_compradores_por_geo', 'insert_mart_compradores_por_geo');
    }

    /** @return array<string,mixed> */
    private function periodSummary(string $startComp, string $endComp): array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) AS final_rows,
                        COALESCE(SUM(faturamento), 0) AS faturamento,
                        COUNT(DISTINCT CASE WHEN bairro_norm IS NOT NULL AND TRIM(bairro_norm) <> '' THEN bairro_norm END) AS bairros_distintos,
                        COUNT(DISTINCT codigo_cliente_omie) AS clientes_distintos,
                        COALESCE(SUM(qtd_notas), 0) AS notas
                 FROM mart_compradores_por_geo
                 WHERE competencia BETWEEN :start_comp AND :end_comp"
            );
            $stmt->execute(['start_comp' => $startComp, 'end_comp' => $endComp]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return is_array($row) ? $row : [];
        } catch (\Throwable $exception) {
            $this->logger->warning('GeoBuyerMartBuilder: falha ao sumarizar periodo.', [
                'start_comp' => $startComp,
                'end_comp' => $endComp,
                'message' => $exception->getMessage(),
            ]);
            return [];
        }
    }
}

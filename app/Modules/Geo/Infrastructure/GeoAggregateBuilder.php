<?php

declare(strict_types=1);

namespace BIomie\Modules\Geo\Infrastructure;

use BIomie\DataPlatform\Gold\Builders\CompradoresPorGeo;
use BIomie\Support\Geo\GeoKeyNormalizer;
use BIomie\Support\TenantContext;
use PDO;
use RuntimeException;
use Throwable;

final class GeoAggregateBuilder
{
    public function __construct(private readonly PDO $pdo) {}

    public function buildCompradoresPorGeo(): int
    {
        throw new RuntimeException(
            'GeoAggregateBuilder::buildCompradoresPorGeo exige periodo explicito. Use buildCompradoresPorGeoForPeriod($start, $end).'
        );
    }

    public function buildCompradoresPorGeoForPeriod(string $start, string $end): int
    {
        $companyId = TenantContext::companyId();
        if ($companyId <= 0) {
            throw new RuntimeException('Selecione uma empresa antes de materializar compradores por geografia.');
        }
        $startComp = substr($start, 0, 7);
        $endComp = substr($end, 0, 7);
        if (!preg_match('/^\d{4}-\d{2}$/', $startComp) || !preg_match('/^\d{4}-\d{2}$/', $endComp)) {
            throw new RuntimeException('Periodo invalido para buildCompradoresPorGeoForPeriod.');
        }

        $hasNomeExibicao   = $this->columnExists('biomie_clientes_dim', 'cliente_nome_exibicao');
        $hasDocMascarado   = $this->columnExists('biomie_clientes_dim', 'documento_mascarado');
        $hasDocNormalizado = $this->columnExists('biomie_clientes_dim', 'documento_normalizado');
        $hasBairroNorm     = $this->columnExists('biomie_clientes_dim', 'bairro_normalizado');
        $hasCidadeNorm     = $this->columnExists('biomie_clientes_dim', 'cidade_normalizada');
        $hasBairroRaw      = $this->columnExists('biomie_clientes_dim', 'bairro');
        $hasCidadeRaw      = $this->columnExists('biomie_clientes_dim', 'cidade');
        $hasUfInDim        = $this->columnExists('biomie_clientes_dim', 'uf');

        // Optional mart columns added by migration (geo quality/scope).
        $hasBairroOficial = $this->columnExists('biomie_clientes_geo_dim', 'bairro_oficial_nome_fortaleza');
        $hasGeoQualityCol = $this->columnExists('mart_compradores_por_geo', 'geo_quality');
        $hasGeoScopeCol   = $this->columnExists('mart_compradores_por_geo', 'geo_scope');

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

        // geo_quality and geo_scope — inserted only when columns exist.
        $extraInsertCols   = '';
        $extraSelectVals   = '';
        if ($hasGeoQualityCol) {
            $geoQualityExpr  = CompradoresPorGeo::geoQualitySql($hasBairroOficial, 'g', $bairroExpr, $cidadeExpr);
            $extraInsertCols .= ', geo_quality';
            $extraSelectVals .= ",\n                 {$geoQualityExpr}";
        }
        if ($hasGeoScopeCol) {
            $geoScopeExpr    = CompradoresPorGeo::geoScopeSql('g', $cidadeExpr, $ufExpr);
            $extraInsertCols .= ', geo_scope';
            $extraSelectVals .= ",\n                 {$geoScopeExpr}";
        }

        $hasCnaeDim = $this->columnExists('biomie_cnae_dim', 'cnae_codigo_normalizado')
            && $this->columnExists('biomie_cnae_dim', 'cnae_descricao');
        $cnaeDimJoin = $hasCnaeDim
            ? "LEFT JOIN biomie_cnae_dim AS cdim ON cdim.cnae_codigo_normalizado = REGEXP_REPLACE(COALESCE(cd.cnae_codigo, 'SEM_CNAE'), '[^0-9]', '')"
            : '';
        $cnaeDescExpr = $hasCnaeDim
            ? "COALESCE(NULLIF(cdim.cnae_descricao, ''), NULLIF(cd.cnae_descricao, ''), 'CNAE nao informado')"
            : "COALESCE(NULLIF(cd.cnae_descricao, ''), 'CNAE nao informado')";

        $startedTransaction = false;
        try {
            if (!$this->pdo->inTransaction()) {
                $this->pdo->beginTransaction();
                $startedTransaction = true;
            }

            $delete = $this->pdo->prepare(
                'DELETE FROM mart_compradores_por_geo
                 WHERE company_id = :company_id
                   AND competencia BETWEEN :start_comp AND :end_comp'
            );
            $delete->execute(['company_id' => $companyId, 'start_comp' => $startComp, 'end_comp' => $endComp]);

            $stmt = $this->pdo->prepare(
                "INSERT INTO mart_compradores_por_geo
                     (company_id, competencia, codigo_cliente_omie, cliente_nome, documento,
                      uf, cidade_norm, bairro_norm, regiao_nome,
                      cnae_codigo, cnae_descricao, segmento_macro,
                      qtd_notas, faturamento, ticket_medio, ultima_compra, refreshed_at{$extraInsertCols})
                 SELECT
                     cp.company_id,
                     cp.competencia,
                     cp.codigo_cliente_omie,
                     {$nomeExpr}                                                          AS cliente_nome,
                     {$docExpr}                                                           AS documento,
                     {$ufExpr}                                                            AS uf,
                     {$cidadeExpr}                                                        AS cidade_norm,
                     {$bairroExpr}                                                        AS bairro_norm,
                     b.regional_atual                                                      AS regiao_nome,
                     cd.cnae_codigo,
                     {$cnaeDescExpr},
                     COALESCE(cd.segmento_macro, 'PJ_SEM_CNAE')                          AS segmento_macro,
                     cp.nfes_emitidas                                                     AS qtd_notas,
                     cp.valor_nfe                                                         AS faturamento,
                     IF(cp.nfes_emitidas > 0, cp.valor_nfe / cp.nfes_emitidas, 0)       AS ticket_medio,
                     NULL                                                                 AS ultima_compra,
                     NOW()                                                                AS refreshed_at{$extraSelectVals}
                 FROM mart_cliente_periodo AS cp
                 LEFT JOIN biomie_clientes_dim AS cd
                   ON cd.codigo_cliente_omie = cp.codigo_cliente_omie AND cd.company_id = cp.company_id
                 LEFT JOIN biomie_clientes_geo_dim AS g
                   ON g.codigo_cliente_omie = cp.codigo_cliente_omie AND g.company_id = cp.company_id
                 {$cnaeDimJoin}
                 LEFT JOIN biomie_bairros_fortaleza_dim AS b
                   ON b.bairro_nome_normalizado = {$bairroDimJoin} AND b.ativo = 1
                 WHERE cp.company_id = :company_id
                   AND cp.competencia BETWEEN :start_comp AND :end_comp
                   AND (cp.nfes_emitidas > 0 OR cp.valor_nfe > 0)"
            );
            $stmt->execute(['company_id' => $companyId, 'start_comp' => $startComp, 'end_comp' => $endComp]);
            $rows = (int) $stmt->rowCount();

            // Populate bairro_key / cidade_key using PHP normalizer (handles accents correctly).
            $this->backfillGeoKeys($startComp, $endComp);

            if ($startedTransaction) {
                $this->pdo->commit();
            }

            return $rows;
        } catch (Throwable $exception) {
            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function backfillGeoKeys(string $startComp, string $endComp): void
    {
        if (!$this->columnExists('mart_compradores_por_geo', 'bairro_key')) {
            return;
        }

        $hasCidadeKey = $this->columnExists('mart_compradores_por_geo', 'cidade_key');
        $needsKeySql = $hasCidadeKey
            ? "((bairro_key IS NULL OR bairro_key = '') OR (cidade_key IS NULL OR cidade_key = ''))"
            : "(bairro_key IS NULL OR bairro_key = '')";

        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT cidade_norm, bairro_norm
             FROM mart_compradores_por_geo
             WHERE company_id = :company_id
               AND competencia BETWEEN :s AND :e
               AND {$needsKeySql}"
        );
        $stmt->execute([':company_id' => TenantContext::companyId(), ':s' => $startComp, ':e' => $endComp]);
        $pairs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($pairs === []) {
            return;
        }

        $hasBairroNome  = $this->columnExists('mart_compradores_por_geo', 'bairro_nome');
        $hasCidadeNome  = $this->columnExists('mart_compradores_por_geo', 'cidade_nome');

        $upFields = ['bairro_key = :bk', 'bairro_norm = :bn'];
        if ($hasCidadeKey) {
            $upFields[] = 'cidade_key = :ck';
            $upFields[] = 'cidade_norm = :cn';
        }
        if ($hasBairroNome) {
            $upFields[] = 'bairro_nome = :nome';
        }
        if ($hasCidadeNome) {
            $upFields[] = 'cidade_nome = :cnome';
        }

        $up = $this->pdo->prepare(
            "UPDATE mart_compradores_por_geo
             SET " . implode(', ', $upFields) . "
             WHERE competencia BETWEEN :s AND :e
               AND company_id = :company_id
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
                ':bk' => $bairroKey,
                ':bn' => GeoKeyNormalizer::normalizeNeighborhood($bairroText),
                ':s' => $startComp,
                ':e' => $endComp,
                ':company_id' => TenantContext::companyId(),
                ':city_raw' => $cityRaw,
                ':bairro_raw' => $bairroRaw,
            ];
            if ($hasCidadeKey) {
                $params[':ck'] = GeoKeyNormalizer::makeCityKey($cityText);
                $params[':cn'] = GeoKeyNormalizer::normalizeCity($cityText);
            }
            if ($hasBairroNome) {
                $params[':nome'] = GeoKeyNormalizer::displayName($bairroText);
            }
            if ($hasCidadeNome) {
                $params[':cnome'] = GeoKeyNormalizer::displayName($cityText);
            }

            $up->execute($params);
        }
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

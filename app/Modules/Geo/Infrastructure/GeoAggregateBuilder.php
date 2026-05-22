<?php

declare(strict_types=1);

namespace BIomie\Modules\Geo\Infrastructure;

use BIomie\DataPlatform\Gold\Builders\CompradoresPorGeo;
use BIomie\Support\Geo\GeoKeyNormalizer;
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
            $geoQualityExpr  = CompradoresPorGeo::geoQualitySql($hasBairroOficial, 'g');
            $extraInsertCols .= ', geo_quality';
            $extraSelectVals .= ",\n                 {$geoQualityExpr}";
        }
        if ($hasGeoScopeCol) {
            $geoScopeExpr    = CompradoresPorGeo::geoScopeSql('g');
            $extraInsertCols .= ', geo_scope';
            $extraSelectVals .= ",\n                 {$geoScopeExpr}";
        }

        $startedTransaction = false;
        try {
            if (!$this->pdo->inTransaction()) {
                $this->pdo->beginTransaction();
                $startedTransaction = true;
            }

            $delete = $this->pdo->prepare(
                'DELETE FROM mart_compradores_por_geo WHERE competencia BETWEEN :start_comp AND :end_comp'
            );
            $delete->execute(['start_comp' => $startComp, 'end_comp' => $endComp]);

            $stmt = $this->pdo->prepare(
                "INSERT INTO mart_compradores_por_geo
                     (competencia, codigo_cliente_omie, cliente_nome, documento,
                      uf, cidade_norm, bairro_norm, regiao_nome,
                      cnae_codigo, cnae_descricao, segmento_macro,
                      qtd_notas, faturamento, ticket_medio, ultima_compra, refreshed_at{$extraInsertCols})
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
                     NOW()                                                                AS refreshed_at{$extraSelectVals}
                 FROM mart_cliente_periodo AS cp
                 LEFT JOIN biomie_clientes_dim AS cd ON cd.codigo_cliente_omie = cp.codigo_cliente_omie
                 LEFT JOIN biomie_clientes_geo_dim AS g  ON g.codigo_cliente_omie = cp.codigo_cliente_omie
                 LEFT JOIN biomie_bairros_fortaleza_dim AS b
                   ON b.bairro_nome_normalizado = {$bairroDimJoin} AND b.ativo = 1
                 WHERE cp.competencia BETWEEN :start_comp AND :end_comp
                   AND (cp.nfes_emitidas > 0 OR cp.valor_nfe > 0)"
            );
            $stmt->execute(['start_comp' => $startComp, 'end_comp' => $endComp]);
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

        try {
            $stmt = $this->pdo->prepare(
                "SELECT DISTINCT cidade_norm, bairro_norm
                 FROM mart_compradores_por_geo
                 WHERE competencia BETWEEN :s AND :e
                   AND (bairro_key IS NULL OR bairro_key = '')"
            );
            $stmt->execute([':s' => $startComp, ':e' => $endComp]);
            $pairs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if ($pairs === []) {
                return;
            }

            $hasBairroNome  = $this->columnExists('mart_compradores_por_geo', 'bairro_nome');
            $hasCidadeNome  = $this->columnExists('mart_compradores_por_geo', 'cidade_nome');

            $upFields = ['bairro_key = :bk', 'cidade_key = :ck', 'bairro_norm = :bn'];
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
                   AND (cidade_norm <=> :city OR (cidade_norm IS NULL AND :city IS NULL))
                   AND (bairro_norm <=> :bairro OR (bairro_norm IS NULL AND :bairro IS NULL))
                   AND (bairro_key IS NULL OR bairro_key = '')"
            );

            foreach ($pairs as $pair) {
                $cityRaw   = (string) ($pair['cidade_norm'] ?? '');
                $bairroRaw = (string) ($pair['bairro_norm'] ?? '');
                $bairroKey = GeoKeyNormalizer::makeNeighborhoodKey($bairroRaw);
                $cidadeKey = GeoKeyNormalizer::makeCityKey($cityRaw);
                $bairroNorm = GeoKeyNormalizer::normalizeNeighborhood($bairroRaw);

                $params = [
                    ':bk'     => $bairroKey,
                    ':ck'     => $cidadeKey,
                    ':bn'     => $bairroNorm,
                    ':s'      => $startComp,
                    ':e'      => $endComp,
                    ':city'   => $cityRaw !== '' ? $cityRaw : null,
                    ':bairro' => $bairroRaw !== '' ? $bairroRaw : null,
                ];
                if ($hasBairroNome) {
                    $params[':nome'] = GeoKeyNormalizer::displayName($bairroRaw);
                }
                if ($hasCidadeNome) {
                    $params[':cnome'] = GeoKeyNormalizer::displayName($cityRaw);
                }

                $up->execute($params);
            }
        } catch (Throwable) {
            // Backfill is best-effort; do not fail the main materialization.
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

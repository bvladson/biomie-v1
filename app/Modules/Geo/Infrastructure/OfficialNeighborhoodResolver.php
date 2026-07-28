<?php

declare(strict_types=1);

namespace BIomie\Modules\Geo\Infrastructure;

use BIomie\Support\NeighborhoodAliases;
use BIomie\Support\NeighborhoodNormalizer;
use BIomie\Support\TenantContext;
use PDO;

final class OfficialNeighborhoodResolver
{
    public function __construct(private readonly PDO $pdo) {}

    public function matchBairrosOfficials(): int
    {
        $this->normalizeOfficialKeys();
        $this->clearInvalidFortalezaMatches();

        $stmt = $this->pdo->prepare(
            "UPDATE biomie_clientes_geo_dim AS g
             JOIN biomie_bairros_fortaleza_dim AS b
               ON b.bairro_nome_normalizado = g.bairro_norm
              AND b.ativo = 1
             SET
               g.bairro_oficial_id_fortaleza  = b.geojson_feature_id,
               g.bairro_oficial_nome_fortaleza = b.bairro_nome,
               g.match_source                 = 'bairros_dim',
               g.match_confidence             = 100.00
             WHERE " . TenantContext::sqlFilter('g') . "
               AND g.bairro_norm IS NOT NULL
               AND g.bairro_norm != ''
               AND LOWER(TRIM(g.cidade_norm)) = 'fortaleza'"
        );
        $stmt->execute();
        $matched = $stmt->rowCount();

        $aliasStmt = $this->pdo->prepare(
            "UPDATE biomie_clientes_geo_dim AS g
             JOIN biomie_bairros_fortaleza_dim AS b
               ON b.bairro_nome_normalizado = :canonical_key
              AND b.ativo = 1
             SET
               g.bairro_oficial_id_fortaleza   = b.geojson_feature_id,
               g.bairro_oficial_nome_fortaleza = b.bairro_nome,
               g.match_source                  = 'bairro_alias',
               g.match_confidence              = 85.00
             WHERE " . TenantContext::sqlFilter('g') . "
               AND g.bairro_norm = :alias_key
               AND LOWER(TRIM(g.cidade_norm)) = 'fortaleza'"
        );
        foreach (NeighborhoodAliases::activeMap() as $aliasKey => $canonicalKey) {
            if ($aliasKey === $canonicalKey) {
                continue;
            }
            $aliasStmt->execute([
                'alias_key' => $aliasKey,
                'canonical_key' => $canonicalKey,
            ]);
            $matched += $aliasStmt->rowCount();
        }

        return $matched;
    }

    private function normalizeOfficialKeys(): void
    {
        $rows = $this->pdo->query(
            "SELECT geojson_feature_id, bairro_nome, bairro_nome_normalizado
             FROM biomie_bairros_fortaleza_dim
             WHERE ativo = 1"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $stmt = $this->pdo->prepare(
            "UPDATE biomie_bairros_fortaleza_dim
             SET bairro_nome_normalizado = :normalized
             WHERE geojson_feature_id = :feature_id"
        );
        foreach ($rows as $row) {
            $normalized = NeighborhoodNormalizer::normalizeKey((string) ($row['bairro_nome'] ?? ''));
            if ($normalized === '' || $normalized === (string) ($row['bairro_nome_normalizado'] ?? '')) {
                continue;
            }
            $stmt->execute([
                'normalized' => $normalized,
                'feature_id' => (int) ($row['geojson_feature_id'] ?? 0),
            ]);
        }
    }

    private function clearInvalidFortalezaMatches(): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE biomie_clientes_geo_dim
             SET
               bairro_oficial_id_fortaleza   = NULL,
               bairro_oficial_nome_fortaleza = NULL,
               match_source                  = NULL,
               match_confidence              = NULL
             WHERE " . TenantContext::sqlFilter() . "
               AND bairro_oficial_id_fortaleza IS NOT NULL
               AND (cidade_norm IS NULL
                 OR TRIM(cidade_norm) = ''
                 OR LOWER(TRIM(cidade_norm)) != 'fortaleza')"
        );
        $stmt->execute();
    }
}

<?php

declare(strict_types=1);

namespace BIomie\Modules\Geo\Infrastructure;

use PDO;

final class OfficialNeighborhoodResolver
{
    public function __construct(private readonly PDO $pdo) {}

    public function matchBairrosOfficials(): int
    {
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
             WHERE g.bairro_norm IS NOT NULL
               AND g.bairro_norm != ''"
        );
        $stmt->execute();
        return $stmt->rowCount();
    }
}

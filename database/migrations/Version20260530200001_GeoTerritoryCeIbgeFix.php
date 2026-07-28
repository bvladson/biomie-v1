<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

/**
 * GEO.R1.1 — Corrige códigos IBGE incorretos em biomie_geo_municipios_ce.
 *
 * Problemas identificados ao cruzar com a API oficial IBGE
 * (servicodados.ibge.gov.br/api/v1/localidades/estados/CE/municipios):
 *
 *   2306256 estava apontando para Horizonte (errado — é o código de Itaitinga)
 *   2306306 estava para Itaitinga (errado — é código de Itapajé, fora da RMF)
 *   2311264 estava para Pindoretama (errado — é código de Quiterianópolis)
 *   2313401 estava para Trairi (errado — é código de Tianguá)
 *
 * Códigos corretos confirmados via IBGE API:
 *   Horizonte   = 2305233
 *   Itaitinga   = 2306256
 *   Pindoretama = 2310852
 *   Trairi      = 2313500
 *   Tianguá     = 2313161
 */
return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260530200001';
    }

    public function getDescription(): string
    {
        return 'GEO.R1.1 — Corrige códigos IBGE incorretos em biomie_geo_municipios_ce (Horizonte, Itaitinga, Pindoretama, Trairi, Tianguá).';
    }

    /** @return list<string> */
    public function getChecksumSources(): array
    {
        return [];
    }

    public function up(\PDO $pdo): void
    {
        // Apenas executa se a tabela existe (migration anterior pode não ter rodado)
        $tableExists = (bool) $pdo->query(
            "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='biomie_geo_municipios_ce' LIMIT 1"
        )->fetchColumn();

        if (!$tableExists) {
            return; // tabela não existe, skip silencioso
        }

        // Remove duplicata de Tianguá que tinha código errado de Maranguape
        $pdo->exec("DELETE FROM biomie_geo_municipios_ce WHERE ibge_code = 2307700 AND is_rmf = 0");

        // Correções de código: UPDATE se old existe + new não existe; DELETE old se new já existe
        $corrections = [
            [2306256, 2305233, 'Horizonte',   'horizonte'],
            [2306306, 2306256, 'Itaitinga',   'itaitinga'],
            [2311264, 2310852, 'Pindoretama', 'pindoretama'],
            [2313401, 2313500, 'Trairi',      'trairi'],
        ];

        foreach ($corrections as [$old, $new, $nome, $norm]) {
            $oldExists = (int) $pdo->query("SELECT COUNT(*) FROM biomie_geo_municipios_ce WHERE ibge_code = $old")->fetchColumn();
            if (!$oldExists) continue;

            $newExists = (int) $pdo->query("SELECT COUNT(*) FROM biomie_geo_municipios_ce WHERE ibge_code = $new")->fetchColumn();
            if (!$newExists) {
                $stmt = $pdo->prepare("UPDATE biomie_geo_municipios_ce SET ibge_code = :new, nome = :nome, nome_norm = :norm WHERE ibge_code = :old");
                $stmt->execute([':new' => $new, ':nome' => $nome, ':norm' => $norm, ':old' => $old]);
            } else {
                $pdo->exec("DELETE FROM biomie_geo_municipios_ce WHERE ibge_code = $old");
            }
        }

        // Insere Tianguá com código correto se ausente
        $pdo->exec("
            INSERT IGNORE INTO biomie_geo_municipios_ce
                (ibge_code, nome, nome_norm, uf, is_rmf, is_adjacent_fortaleza, has_bairro_layer, regiao_planejamento_id, lat, lng, source)
            VALUES
                (2313161, 'Tianguá', 'tiangua', 'CE', 0, 0, 0, 5, -3.7281000, -40.9919000, 'ibge')
        ");
    }

    public function down(\PDO $pdo): void
    {
        // Rollback não implementado: os códigos errados não devem ser restaurados
    }
};

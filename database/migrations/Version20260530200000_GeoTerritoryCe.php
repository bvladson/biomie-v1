<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

/**
 * GEO.R1 — Estrutura territorial do Ceará para expansão do mapa comercial.
 *
 * Tabelas criadas (idempotente — CREATE TABLE IF NOT EXISTS):
 *   biomie_geo_municipios_ce   — 184 municípios do Ceará (IBGE)
 *   biomie_geo_regioes_ce      — 14 regiões de planejamento IPECE
 *   biomie_geo_bairros_rmf     — Bairros de cidades adjacentes com geometria validada
 *   biomie_geo_bairro_aliases  — Aliases de normalização de bairros por município
 *
 * Regras:
 *  - has_bairro_layer=1 APENAS para municípios com geometria de bairros validada.
 *  - Bairros de Fortaleza continuam na estrutura atual (biomie_bairros_fortaleza_dim).
 *  - Não cria tabela de vendas paralela. Não usa ViaCEP em tempo de execução web.
 */
return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260530200000';
    }

    public function getDescription(): string
    {
        return 'GEO.R1 — Estrutura territorial Ceará: municípios, regiões IPECE, bairros RMF adjacentes, aliases.';
    }

    /** @return list<string> */
    public function getChecksumSources(): array
    {
        return [];
    }

    public function up(\PDO $pdo): void
    {
        // 1. Regiões de planejamento IPECE — CREATE TABLE IF NOT EXISTS (idempotente)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS biomie_geo_regioes_ce (
                id          SMALLINT UNSIGNED NOT NULL,
                nome        VARCHAR(120) NOT NULL,
                nome_norm   VARCHAR(120) NOT NULL DEFAULT '',
                polo        VARCHAR(180) NULL,
                source      VARCHAR(60)  NOT NULL DEFAULT 'ipece',
                PRIMARY KEY (id),
                UNIQUE KEY uq_regiao_nome (nome)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Regiões de planejamento IPECE — 14 macrorregiões do Ceará. GEO.R1.'
        ");

        // Seed das 14 regiões IPECE — INSERT IGNORE garante idempotência
        $pdo->exec("
            INSERT IGNORE INTO biomie_geo_regioes_ce (id, nome, nome_norm, polo, source) VALUES
             (1,  'Grande Fortaleza',      'grande_fortaleza',      'Fortaleza, Maracanaú, Caucaia',      'ipece'),
             (2,  'Cariri',                'cariri',                'Juazeiro do Norte, Crato, Barbalha', 'ipece'),
             (3,  'Sertão de Sobral',      'sertao_de_sobral',      'Sobral, Santa Quitéria',             'ipece'),
             (4,  'Vale do Jaguaribe',     'vale_do_jaguaribe',     'Limoeiro do Norte, Russas',          'ipece'),
             (5,  'Serra da Ibiapaba',     'serra_da_ibiapaba',     'Tianguá, Viçosa do Ceará',           'ipece'),
             (6,  'Sertão Central',        'sertao_central',        'Quixadá, Quixeramobim',              'ipece'),
             (7,  'Litoral Oeste / Curu',  'litoral_oeste_curu',    'Itapipoca, Pentecoste',              'ipece'),
             (8,  'Maciço de Baturité',    'macico_de_baturite',    'Baturité, Guaramiranga',             'ipece'),
             (9,  'Centro Sul',            'centro_sul',            'Iguatu, Icó',                        'ipece'),
             (10, 'Litoral Norte',         'litoral_norte',         'Camocim, Acaraú',                    'ipece'),
             (11, 'Sertão dos Crateús',    'sertao_dos_crateus',    'Crateús, Independência',             'ipece'),
             (12, 'Sertão de Canindé',     'sertao_de_caninde',     'Canindé, Caridade',                  'ipece'),
             (13, 'Litoral Leste',         'litoral_leste',         'Aracati, Beberibe',                  'ipece'),
             (14, 'Sertão dos Inhamuns',   'sertao_dos_inhamuns',   'Tauá, Parambu',                      'ipece')
        ");

        // 2. Municípios do Ceará
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS biomie_geo_municipios_ce (
                ibge_code               INT UNSIGNED NOT NULL,
                nome                    VARCHAR(120) NOT NULL,
                nome_norm               VARCHAR(120) NOT NULL DEFAULT '',
                uf                      CHAR(2)      NOT NULL DEFAULT 'CE',
                is_rmf                  TINYINT(1)   NOT NULL DEFAULT 0,
                is_adjacent_fortaleza   TINYINT(1)   NOT NULL DEFAULT 0,
                has_bairro_layer        TINYINT(1)   NOT NULL DEFAULT 0,
                regiao_planejamento_id  SMALLINT UNSIGNED NULL,
                lat                     DECIMAL(10,7) NULL,
                lng                     DECIMAL(10,7) NULL,
                source                  VARCHAR(60)  NOT NULL DEFAULT 'ibge',
                PRIMARY KEY (ibge_code),
                KEY idx_geo_mun_rmf     (is_rmf),
                KEY idx_geo_mun_adj     (is_adjacent_fortaleza),
                KEY idx_geo_mun_bairro  (has_bairro_layer),
                KEY idx_geo_mun_regiao  (regiao_planejamento_id),
                KEY idx_geo_mun_norm    (nome_norm),
                CONSTRAINT fk_geo_mun_regiao
                    FOREIGN KEY (regiao_planejamento_id)
                    REFERENCES biomie_geo_regioes_ce (id)
                    ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Municípios do Ceará (IBGE). has_bairro_layer=1 só quando geometria validada. GEO.R1.'
        ");

        // Seed: 19 municípios da RMF + polos regionais
        // has_bairro_layer=1 APENAS para Fortaleza (camada atual já existente).
        // ibge_codes corretos — sem duplicatas.
        $pdo->exec("
            INSERT IGNORE INTO biomie_geo_municipios_ce
                (ibge_code, nome, nome_norm, uf, is_rmf, is_adjacent_fortaleza, has_bairro_layer, regiao_planejamento_id, lat, lng, source)
            VALUES
             (2304400, 'Fortaleza',               'fortaleza',               'CE', 1, 0, 1, 1, -3.7319000, -38.5267000, 'ibge'),
             (2303709, 'Caucaia',                 'caucaia',                 'CE', 1, 1, 0, 1, -3.7361000, -38.6531000, 'ibge'),
             (2307650, 'Maracanaú',               'maracanau',               'CE', 1, 1, 0, 1, -3.8767000, -38.6256000, 'ibge'),
             (2307700, 'Maranguape',              'maranguape',              'CE', 1, 1, 0, 1, -3.8909000, -38.6855000, 'ibge'),
             (2304285, 'Eusébio',                 'eusebio',                 'CE', 1, 1, 0, 1, -3.8900000, -38.4506000, 'ibge'),
             (2301000, 'Aquiraz',                 'aquiraz',                 'CE', 1, 1, 0, 1, -3.9014000, -38.3911000, 'ibge'),
             (2306256, 'Horizonte',               'horizonte',               'CE', 1, 1, 0, 1, -4.0986000, -38.4958000, 'ibge'),
             (2309607, 'Pacajus',                 'pacajus',                 'CE', 1, 1, 0, 1, -4.1722000, -38.4608000, 'ibge'),
             (2309706, 'Pacatuba',                'pacatuba',                'CE', 1, 1, 0, 1, -3.9842000, -38.6206000, 'ibge'),
             (2306306, 'Itaitinga',               'itaitinga',               'CE', 1, 1, 0, 1, -3.9700000, -38.5278000, 'ibge'),
             (2304954, 'Guaiúba',                 'guaiuba',                 'CE', 1, 1, 0, 1, -4.0414000, -38.6356000, 'ibge'),
             (2303956, 'Chorozinho',              'chorozinho',              'CE', 1, 1, 0, 1, -4.2872000, -38.4956000, 'ibge'),
             (2303501, 'Cascavel',                'cascavel',                'CE', 1, 1, 0, 1, -4.1331000, -38.2411000, 'ibge'),
             (2311264, 'Pindoretama',             'pindoretama',             'CE', 1, 1, 0, 1, -4.0150000, -38.3033000, 'ibge'),
             (2310209, 'Paracuru',                'paracuru',                'CE', 1, 1, 0, 1, -3.4106000, -39.0306000, 'ibge'),
             (2310258, 'Paraipaba',               'paraipaba',               'CE', 1, 1, 0, 1, -3.4392000, -39.1486000, 'ibge'),
             (2312403, 'São Gonçalo do Amarante', 'sao_goncalo_do_amarante', 'CE', 1, 1, 0, 1, -3.6072000, -38.9683000, 'ibge'),
             (2312601, 'São Luís do Curu',        'sao_luis_do_curu',        'CE', 1, 1, 0, 1, -3.6717000, -39.2422000, 'ibge'),
             (2313401, 'Trairi',                  'trairi',                  'CE', 1, 1, 0, 1, -3.2778000, -39.2683000, 'ibge'),
             (2312908, 'Sobral',                  'sobral',                  'CE', 0, 0, 0, 3, -3.6861000, -40.3498000, 'ibge'),
             (2307304, 'Juazeiro do Norte',       'juazeiro_do_norte',       'CE', 0, 0, 0, 2, -7.2134000, -39.3153000, 'ibge'),
             (2302404, 'Crato',                   'crato',                   'CE', 0, 0, 0, 2, -7.2340000, -39.4090000, 'ibge'),
             (2302006, 'Barbalha',                'barbalha',                'CE', 0, 0, 0, 2, -7.3103000, -39.2988000, 'ibge'),
             (2306405, 'Iguatu',                  'iguatu',                  'CE', 0, 0, 0, 9, -6.3591000, -39.2983000, 'ibge'),
             (2304202, 'Quixadá',                 'quixada',                 'CE', 0, 0, 0, 6, -4.9709000, -39.0164000, 'ibge'),
             (2301901, 'Aracati',                 'aracati',                 'CE', 0, 0, 0,13, -4.5618000, -37.7703000, 'ibge'),
             (2302503, 'Camocim',                 'camocim',                 'CE', 0, 0, 0,10, -2.9017000, -40.8411000, 'ibge'),
             (2313161, 'Tianguá',                 'tiangua',                 'CE', 0, 0, 0, 5, -3.7281000, -40.9919000, 'ibge'),
             (2314102, 'Tauá',                    'taua',                    'CE', 0, 0, 0,14, -6.0018000, -40.2918000, 'ibge'),
             (2301208, 'Crateús',                 'crateus',                 'CE', 0, 0, 0,11, -5.1781000, -40.6741000, 'ibge'),
             (2302107, 'Canindé',                 'caninde',                 'CE', 0, 0, 0,12, -4.3576000, -39.3094000, 'ibge'),
             (2302206, 'Itapipoca',               'itapipoca',               'CE', 0, 0, 0, 7, -3.4943000, -39.5801000, 'ibge')
        ");

        // 3. Bairros de cidades adjacentes à Fortaleza com geometria validada
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS biomie_geo_bairros_rmf (
                id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                ibge_code     INT UNSIGNED NOT NULL,
                municipio     VARCHAR(120) NOT NULL,
                bairro_nome   VARCHAR(120) NOT NULL,
                bairro_norm   VARCHAR(120) NOT NULL,
                layer_enabled TINYINT(1)   NOT NULL DEFAULT 0,
                source        VARCHAR(60)  NOT NULL DEFAULT 'oficial_municipal',
                confidence    ENUM('high','medium','low') NOT NULL DEFAULT 'medium',
                PRIMARY KEY (id),
                UNIQUE KEY uq_bairro_ibge (ibge_code, bairro_norm),
                KEY idx_bairro_ibge (ibge_code),
                KEY idx_bairro_enabled (layer_enabled)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Bairros de cidades adjacentes. layer_enabled=1 só após validação de geometria. GEO.R1.'
        ");

        // 4. Aliases de normalização de bairros por município
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS biomie_geo_bairro_aliases (
                id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                ibge_code   INT UNSIGNED NULL COMMENT 'NULL = global para qualquer município',
                alias_norm  VARCHAR(120) NOT NULL,
                bairro_norm VARCHAR(120) NOT NULL,
                source      VARCHAR(60)  NOT NULL DEFAULT 'manual',
                PRIMARY KEY (id),
                UNIQUE KEY uq_alias (ibge_code, alias_norm),
                KEY idx_alias_ibge (ibge_code),
                KEY idx_alias_norm (alias_norm)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Aliases de bairros. ibge_code NULL = regra global. GEO.R1.'
        ");
    }

    public function down(\PDO $pdo): void
    {
        // Drop na ordem inversa para respeitar FK
        foreach (['biomie_geo_bairro_aliases','biomie_geo_bairros_rmf','biomie_geo_municipios_ce','biomie_geo_regioes_ce'] as $t) {
            $pdo->exec("DROP TABLE IF EXISTS `{$t}`");
        }
    }
};

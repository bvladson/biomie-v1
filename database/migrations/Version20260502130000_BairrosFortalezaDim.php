<?php

declare(strict_types=1);

use BIomie\GeoNameNormalizer;
use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260502130000';
    }

    public function getDescription(): string
    {
        return 'Dimensao oficial dos 121 bairros de Fortaleza (PMF/IPLANFOR) + colunas de enriquecimento geo.';
    }

    /** @return list<string> */
    public function getChecksumSources(): array
    {
        return [];
    }

    public function up(\PDO $pdo): void
    {
        $this->ensureBairrosDim($pdo);
        $this->seedBairros($pdo);
        $this->ensureEnriquecerColumns($pdo);
    }

    public function down(\PDO $pdo): void
    {
        $this->dropTables($pdo, ['biomie_bairros_fortaleza_dim']);
    }

    // ── Table creation ────────────────────────────────────────────────────────

    private function ensureBairrosDim(\PDO $pdo): void
    {
        if ($this->tableExists($pdo, 'biomie_bairros_fortaleza_dim')) {
            return;
        }

        $pdo->exec(
            "CREATE TABLE biomie_bairros_fortaleza_dim ("
            . "id                    INT UNSIGNED     NOT NULL AUTO_INCREMENT,"
            . "geojson_feature_id    INT UNSIGNED     NOT NULL COMMENT 'id do feature no GeoJSON (1-121)',"
            . "bairro_nome           VARCHAR(150)     NOT NULL,"
            . "bairro_nome_normalizado VARCHAR(150)   NOT NULL,"
            . "regional_antiga       VARCHAR(20)      NULL COMMENT 'SER I..VI + SERCE',"
            . "regional_atual        VARCHAR(100)     NULL COMMENT 'Nome completo da regional',"
            . "codigo_regiao         VARCHAR(10)      NULL COMMENT 'Codigo romano I..XII',"
            . "territorio            TINYINT UNSIGNED NULL,"
            . "codigo_bairro         SMALLINT UNSIGNED NULL COMMENT 'Codigo oficial PMF',"
            . "ativo                 TINYINT(1)       NOT NULL DEFAULT 1,"
            . "created_at            TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,"
            . "updated_at            TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,"
            . "PRIMARY KEY (id),"
            . "UNIQUE KEY uq_geojson_id (geojson_feature_id),"
            . "UNIQUE KEY uq_bairro_nome_norm (bairro_nome_normalizado),"
            . "KEY idx_bairro_nome (bairro_nome),"
            . "KEY idx_ativo (ativo)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    // ── Seed ─────────────────────────────────────────────────────────────────

    private function seedBairros(\PDO $pdo): void
    {
        $existing = (int) $pdo->query('SELECT COUNT(*) FROM biomie_bairros_fortaleza_dim')->fetchColumn();
        if ($existing >= 121) {
            return;
        }

        // [geojson_feature_id, bairro_nome, regional_antiga, regional_atual, codigo_regiao, territorio, codigo_bairro]
        $bairros = [
            [1,   'São Gerardo',                    'SER I',   'Secretaria Executiva Regional I',   'I',    1,  1],
            [2,   'Aldeota',                         'SER II',  'Secretaria Executiva Regional II',  'II',   2,  2],
            [3,   'Álvaro Weyne',                    'SER I',   'Secretaria Executiva Regional I',   'I',    1,  3],
            [4,   'Amadeu Furtado',                  'SER I',   'Secretaria Executiva Regional I',   'I',    1,  4],
            [5,   'Moura Brasil',                    'SER I',   'Secretaria Executiva Regional I',   'I',    1,  5],
            [6,   'Barra do Ceará',                  'SER I',   'Secretaria Executiva Regional I',   'I',    1,  6],
            [7,   'Benfica',                         'SER II',  'Secretaria Executiva Regional II',  'II',   2,  7],
            [8,   'Bom Futuro',                      'SER VI',  'Secretaria Executiva Regional VI',  'VI',   6,  8],
            [9,   'Carlito Pamplona',                'SER I',   'Secretaria Executiva Regional I',   'I',    1,  9],
            [10,  'Centro',                          'SER I',   'Secretaria Executiva Regional I',   'I',    1,  10],
            [11,  'Cocó',                            'SER II',  'Secretaria Executiva Regional II',  'II',   2,  11],
            [12,  'Cristo Redentor',                 'SER IV',  'Secretaria Executiva Regional IV',  'IV',   4,  12],
            [13,  'Damas',                           'SER IV',  'Secretaria Executiva Regional IV',  'IV',   4,  13],
            [14,  'Dionísio Torres',                 'SER II',  'Secretaria Executiva Regional II',  'II',   2,  14],
            [15,  'Farias Brito',                    'SER II',  'Secretaria Executiva Regional II',  'II',   2,  15],
            [16,  'Fátima',                          'SER IV',  'Secretaria Executiva Regional IV',  'IV',   4,  16],
            [17,  'Floresta',                        'SER I',   'Secretaria Executiva Regional I',   'I',    1,  17],
            [18,  'Jacarecanga',                     'SER I',   'Secretaria Executiva Regional I',   'I',    1,  18],
            [19,  'Jardim América',                  'SER IV',  'Secretaria Executiva Regional IV',  'IV',   4,  19],
            [20,  'Jardim Guanabara',                'SER II',  'Secretaria Executiva Regional II',  'II',   2,  20],
            [21,  'Jardim Iracema',                  'SER I',   'Secretaria Executiva Regional I',   'I',    1,  21],
            [22,  'Joaquim Távora',                  'SER II',  'Secretaria Executiva Regional II',  'II',   2,  22],
            [23,  'José Bonifácio',                  'SER II',  'Secretaria Executiva Regional II',  'II',   2,  23],
            [24,  'Meireles',                        'SER II',  'Secretaria Executiva Regional II',  'II',   2,  24],
            [25,  'Monte Castelo',                   'SER I',   'Secretaria Executiva Regional I',   'I',    1,  25],
            [26,  'Mucuripe',                        'SER II',  'Secretaria Executiva Regional II',  'II',   2,  26],
            [27,  'Papicu',                          'SER II',  'Secretaria Executiva Regional II',  'II',   2,  27],
            [28,  'Parque Araçagi',                  'SER I',   'Secretaria Executiva Regional I',   'I',    1,  28],
            [29,  'Parquelândia',                    'SER IV',  'Secretaria Executiva Regional IV',  'IV',   4,  29],
            [30,  'Parrão',                          'SER I',   'Secretaria Executiva Regional I',   'I',    1,  30],
            [31,  'Pirambu',                         'SER I',   'Secretaria Executiva Regional I',   'I',    1,  31],
            [32,  'Praia de Iracema',                'SER I',   'Secretaria Executiva Regional I',   'I',    1,  32],
            [33,  'Presidente Kennedy',              'SER I',   'Secretaria Executiva Regional I',   'I',    1,  33],
            [34,  'Rodolfo Teófilo',                 'SER IV',  'Secretaria Executiva Regional IV',  'IV',   4,  34],
            [35,  'Tauape',                          'SER II',  'Secretaria Executiva Regional II',  'II',   2,  35],
            [36,  'Varjota',                         'SER II',  'Secretaria Executiva Regional II',  'II',   2,  36],
            [37,  'Vicente Pinzón',                  'SER II',  'Secretaria Executiva Regional II',  'II',   2,  37],
            [38,  'Ellery',                          'SER I',   'Secretaria Executiva Regional I',   'I',    1,  38],
            [39,  'Vila Velha',                      'SER I',   'Secretaria Executiva Regional I',   'I',    1,  39],
            [40,  'Antônio Bezerra',                 'SER IV',  'Secretaria Executiva Regional IV',  'IV',   4,  40],
            [41,  'Autran Nunes',                    'SER IV',  'Secretaria Executiva Regional IV',  'IV',   4,  41],
            [42,  'Conjunto Ceará I',                'SER V',   'Secretaria Executiva Regional V',   'V',    5,  42],
            [43,  'Dom Lustosa',                     'SER IV',  'Secretaria Executiva Regional IV',  'IV',   4,  43],
            [44,  'Genibaú',                         'SER V',   'Secretaria Executiva Regional V',   'V',    5,  44],
            [45,  'Henrique Jorge',                  'SER IV',  'Secretaria Executiva Regional IV',  'IV',   4,  45],
            [46,  'João XXIII',                      'SER IV',  'Secretaria Executiva Regional IV',  'IV',   4,  46],
            [47,  'Padre Andrade',                   'SER IV',  'Secretaria Executiva Regional IV',  'IV',   4,  47],
            [48,  'Quintino Cunha',                  'SER IV',  'Secretaria Executiva Regional IV',  'IV',   4,  48],
            [49,  'José de Alencar',                 'SER V',   'Secretaria Executiva Regional V',   'V',    5,  49],
            [50,  'Ancuri',                          'SER VI',  'Secretaria Executiva Regional VI',  'VI',   6,  50],
            [51,  'Barroso',                         'SER VI',  'Secretaria Executiva Regional VI',  'VI',   6,  51],
            [52,  'Cajazeiras',                      'SER VI',  'Secretaria Executiva Regional VI',  'VI',   6,  52],
            [53,  'Cambeba',                         'SER VI',  'Secretaria Executiva Regional VI',  'VI',   6,  53],
            [54,  'Cidade dos Funcionários',         'SER VI',  'Secretaria Executiva Regional VI',  'VI',   6,  54],
            [55,  'Coaçu',                           'SER VI',  'Secretaria Executiva Regional VI',  'VI',   6,  55],
            [56,  'Curió',                           'SER VI',  'Secretaria Executiva Regional VI',  'VI',   6,  56],
            [57,  'Edson Queiroz',                   'SER VI',  'Secretaria Executiva Regional VI',  'VI',   6,  57],
            [58,  'Engenheiro Luciano Cavalcante',   'SER VI',  'Secretaria Executiva Regional VI',  'VI',   6,  58],
            [59,  'Guajeru',                         'SER VI',  'Secretaria Executiva Regional VI',  'VI',   6,  59],
            [60,  'Guararapes',                      'SER VI',  'Secretaria Executiva Regional VI',  'VI',   6,  60],
            [61,  'Jangurussu',                      'SER VI',  'Secretaria Executiva Regional VI',  'VI',   6,  61],
            [62,  'Jardim das Oliveiras',            'SER VI',  'Secretaria Executiva Regional VI',  'VI',   6,  62],
            [63,  'Lagoa Redonda',                   'SER VI',  'Secretaria Executiva Regional VI',  'VI',   6,  63],
            [64,  'Sapiranga/Coité',                 'SER II',  'Secretaria Executiva Regional II',  'II',   2,  64],
            [65,  'Messejana',                       'SER VI',  'Secretaria Executiva Regional VI',  'VI',   6,  65],
            [66,  'Parque Iracema',                  'SER VI',  'Secretaria Executiva Regional VI',  'VI',   6,  66],
            [67,  'Parque Manibura',                 'SER VI',  'Secretaria Executiva Regional VI',  'VI',   6,  67],
            [68,  'Paupina',                         'SER VI',  'Secretaria Executiva Regional VI',  'VI',   6,  68],
            [69,  'Pedras',                          'SER VI',  'Secretaria Executiva Regional VI',  'VI',   6,  69],
            [70,  'Sabiaguaba',                      'SER II',  'Secretaria Executiva Regional II',  'II',   2,  70],
            [71,  'Salinas',                         'SER VI',  'Secretaria Executiva Regional VI',  'VI',   6,  71],
            [72,  'Bom Jardim',                      'SER V',   'Secretaria Executiva Regional V',   'V',    5,  72],
            [73,  'Bonsucesso',                      'SER V',   'Secretaria Executiva Regional V',   'V',    5,  73],
            [74,  'Canindezinho',                    'SER V',   'Secretaria Executiva Regional V',   'V',    5,  74],
            [75,  'Conjunto Ceará II',               'SER V',   'Secretaria Executiva Regional V',   'V',    5,  75],
            [76,  'Conjunto Esperança',              'SER V',   'Secretaria Executiva Regional V',   'V',    5,  76],
            [77,  'Rachel de Queiroz',               'SER V',   'Secretaria Executiva Regional V',   'V',    5,  77],
            [78,  'Granja Lisboa',                   'SER V',   'Secretaria Executiva Regional V',   'V',    5,  78],
            [79,  'Granja Portugal',                 'SER V',   'Secretaria Executiva Regional V',   'V',    5,  79],
            [80,  'Jardim Cearense',                 'SER V',   'Secretaria Executiva Regional V',   'V',    5,  80],
            [81,  'Manoel Sátiro',                   'SER V',   'Secretaria Executiva Regional V',   'V',    5,  81],
            [82,  'Maraponga',                       'SER V',   'Secretaria Executiva Regional V',   'V',    5,  82],
            [83,  'Mondubim',                        'SER V',   'Secretaria Executiva Regional V',   'V',    5,  83],
            [84,  'Parque Dois Irmãos',              'SER V',   'Secretaria Executiva Regional V',   'V',    5,  84],
            [85,  'Parque Presidente Vargas',        'SER V',   'Secretaria Executiva Regional V',   'V',    5,  85],
            [86,  'Parque São José',                 'SER V',   'Secretaria Executiva Regional V',   'V',    5,  86],
            [87,  'Parque Santa Rosa',               'SER V',   'Secretaria Executiva Regional V',   'V',    5,  87],
            [88,  'Passaré',                         'SER VI',  'Secretaria Executiva Regional VI',  'VI',   6,  88],
            [89,  'Prefeito José Walter',            'SER VI',  'Secretaria Executiva Regional VI',  'VI',   6,  89],
            [90,  'Siqueira',                        'SER V',   'Secretaria Executiva Regional V',   'V',    5,  90],
            [91,  'Aerolândia',                      'SER III', 'Secretaria Executiva Regional III', 'III',  3,  91],
            [92,  'Aeroporto',                       'SER III', 'Secretaria Executiva Regional III', 'III',  3,  92],
            [93,  'Alto da Balança',                 'SER III', 'Secretaria Executiva Regional III', 'III',  3,  93],
            [94,  'Bela Vista',                      'SER III', 'Secretaria Executiva Regional III', 'III',  3,  94],
            [95,  'Couto Fernandes',                 'SER III', 'Secretaria Executiva Regional III', 'III',  3,  95],
            [96,  'Demócrito Rocha',                 'SER III', 'Secretaria Executiva Regional III', 'III',  3,  96],
            [97,  'Dias Macedo',                     'SER III', 'Secretaria Executiva Regional III', 'III',  3,  97],
            [98,  'Itaoca',                          'SER III', 'Secretaria Executiva Regional III', 'III',  3,  98],
            [99,  'Itaperi',                         'SER III', 'Secretaria Executiva Regional III', 'III',  3,  99],
            [100, 'Jóquei Clube',                    'SER III', 'Secretaria Executiva Regional III', 'III',  3,  100],
            [101, 'Montese',                         'SER III', 'Secretaria Executiva Regional III', 'III',  3,  101],
            [102, 'Panamericano',                    'SER III', 'Secretaria Executiva Regional III', 'III',  3,  102],
            [103, 'Parangaba',                       'SER III', 'Secretaria Executiva Regional III', 'III',  3,  103],
            [104, 'Pici',                            'SER IV',  'Secretaria Executiva Regional IV',  'IV',   4,  104],
            [105, 'Serrinha',                        'SER III', 'Secretaria Executiva Regional III', 'III',  3,  105],
            [106, 'Vila Peri',                       'SER V',   'Secretaria Executiva Regional V',   'V',    5,  106],
            [107, 'Vila União',                      'SER III', 'Secretaria Executiva Regional III', 'III',  3,  107],
            [108, 'Cais do Porto',                   'SER I',   'Secretaria Executiva Regional I',   'I',    1,  108],
            [109, 'Cidade 2000',                     'SER II',  'Secretaria Executiva Regional II',  'II',   2,  109],
            [110, 'Manuel Dias Branco',              'SER II',  'Secretaria Executiva Regional II',  'II',   2,  110],
            [111, 'Praia do Futuro I',               'SER II',  'Secretaria Executiva Regional II',  'II',   2,  111],
            [112, 'Praia do Futuro II',              'SER II',  'Secretaria Executiva Regional II',  'II',   2,  112],
            [113, 'De Lourdes',                      'SER II',  'Secretaria Executiva Regional II',  'II',   2,  113],
            [114, 'Planalto Ayrton Senna',           'SER V',   'Secretaria Executiva Regional V',   'V',    5,  114],
            [115, 'Conjunto Palmeiras',              'SER VI',  'Secretaria Executiva Regional VI',  'VI',   6,  115],
            [116, 'São Bento',                       'SER VI',  'Secretaria Executiva Regional VI',  'VI',   6,  116],
            [117, 'Novo Mondubim',                   'SER V',   'Secretaria Executiva Regional V',   'V',    5,  117],
            [118, 'Olavo Oliveira',                  'SER V',   'Secretaria Executiva Regional V',   'V',    5,  118],
            [119, 'Parque Santa Maria',              'SER VI',  'Secretaria Executiva Regional VI',  'VI',   6,  119],
            [120, 'Aracapé',                         'SER VI',  'Secretaria Executiva Regional VI',  'VI',   6,  120],
            [121, 'Boa Vista/Castelão',              'SERCE',   'Secretaria Executiva Regional de Eventos', 'VII', 7, 121],
        ];

        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO biomie_bairros_fortaleza_dim
                (geojson_feature_id, bairro_nome, bairro_nome_normalizado,
                 regional_antiga, regional_atual, codigo_regiao, territorio, codigo_bairro)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );

        foreach ($bairros as [$fid, $nome, $regAntig, $regAtual, $codReg, $territ, $codBairro]) {
            $stmt->execute([
                $fid,
                $nome,
                GeoNameNormalizer::normalizeKey($nome),
                $regAntig,
                $regAtual,
                $codReg,
                $territ,
                $codBairro,
            ]);
        }
    }

    // ── biomie_clientes_para_enriquecer columns ───────────────────────────────

    private function ensureEnriquecerColumns(\PDO $pdo): void
    {
        $t = 'biomie_clientes_para_enriquecer';

        if (!$this->tableExists($pdo, $t)) {
            return;
        }

        if (!$this->columnExists($pdo, $t, 'motivo')) {
            $pdo->exec("ALTER TABLE {$t} ADD COLUMN motivo VARCHAR(60) NULL AFTER status");
        }
        if (!$this->columnExists($pdo, $t, 'cidade_atual')) {
            $pdo->exec("ALTER TABLE {$t} ADD COLUMN cidade_atual VARCHAR(120) NULL");
        }
        if (!$this->columnExists($pdo, $t, 'uf_atual')) {
            $pdo->exec("ALTER TABLE {$t} ADD COLUMN uf_atual CHAR(2) NULL");
        }
        if (!$this->columnExists($pdo, $t, 'bairro_atual')) {
            $pdo->exec("ALTER TABLE {$t} ADD COLUMN bairro_atual VARCHAR(120) NULL");
        }
        if (!$this->columnExists($pdo, $t, 'sugestao_bairro_normalizado')) {
            $pdo->exec("ALTER TABLE {$t} ADD COLUMN sugestao_bairro_normalizado VARCHAR(150) NULL");
        }
        if (!$this->columnExists($pdo, $t, 'cliente_nome_exibicao')) {
            $pdo->exec("ALTER TABLE {$t} ADD COLUMN cliente_nome_exibicao VARCHAR(255) NULL");
        }
        if (!$this->columnExists($pdo, $t, 'cliente_dim_id')) {
            $pdo->exec("ALTER TABLE {$t} ADD COLUMN cliente_dim_id BIGINT UNSIGNED NULL");
        }
    }

    // ── Information-schema helpers ────────────────────────────────────────────

    private function tableExists(\PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
        );
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function columnExists(\PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
        );
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    }
};

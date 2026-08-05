<?php

declare(strict_types=1);

/**
 * ENUM declarado no CODIGO x ENUM que existe no BANCO.
 *
 * ─── POR QUE ESTA FERRAMENTA EXISTE ─────────────────────────────────────────
 * Boa parte do schema deste projeto e criada por `CREATE TABLE IF NOT EXISTS`
 * dentro do proprio codigo (`ensureSchema()`, `CanonicalSchemaManager`, etc.).
 * Esse padrao tem um modo de falha silencioso: quando alguem acrescenta um
 * membro a um ENUM na string do CREATE TABLE, a tabela que JA EXISTE nao muda.
 * O codigo passa a gravar um valor que o banco recusa, e a divergencia nao tem
 * sintoma proprio — ela so aparece na hora do INSERT.
 *
 * Foi assim que `phase = 'chunked'` derrubou ~60% das execucoes de sync por
 * semanas: o CREATE TABLE do repositorio ja listava 'chunked', a tabela em
 * producao nao. Sob STRICT_TRANS_TABLES virou 1265 "Data truncated"; antes do
 * STRICT virava string vazia em silencio, e 504 de 505 linhas ficaram com o
 * sentinela.
 *
 * Um teste estatico nao pega isso: os dois lados estao certos isoladamente. So
 * comparar o codigo com o banco VIVO revela a diferenca — por isso e uma
 * ferramenta de diagnostico, para rodar apos o deploy, e nao um teste de CI.
 *
 *   php tools/dev/enum_domain_drift.php            # legivel
 *   php tools/dev/enum_domain_drift.php --json     # para pipeline
 *
 * Sai 1 quando ha divergencia — da para encadear no pos-deploy.
 */

require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;

Bootstrap::init();

$json = in_array('--json', $_SERVER['argv'] ?? [], true);
$root = dirname(__DIR__, 2);

/**
 * Varre o codigo atras de colunas ENUM declaradas em CREATE TABLE.
 *
 * @return array<string,array<string,array{valores:list<string>,origem:string}>>
 */
$declaradas = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app'));
foreach ($rii as $arquivo) {
    if (!$arquivo->isFile() || $arquivo->getExtension() !== 'php') {
        continue;
    }
    $src = (string) file_get_contents($arquivo->getPathname());
    if (stripos($src, 'CREATE TABLE') === false || stripos($src, 'ENUM(') === false) {
        continue;
    }

    // Cada bloco CREATE TABLE, com o nome da tabela e o corpo ate o fecho.
    if (preg_match_all(
        '~CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?([a-z0-9_%]+)[`"]?\s*\((.*?)\)\s*ENGINE~is',
        $src,
        $blocos,
        PREG_SET_ORDER
    ) === 0) {
        continue;
    }

    foreach ($blocos as $bloco) {
        $tabela = $bloco[1];
        // Nome vindo de sprintf('%s', self::T) nao da para resolver estaticamente.
        if (str_contains($tabela, '%')) {
            continue;
        }
        preg_match_all('~[`"]?([a-z0-9_]+)[`"]?\s+ENUM\s*\(([^)]*)\)~i', $bloco[2], $colunas, PREG_SET_ORDER);
        foreach ($colunas as $coluna) {
            preg_match_all("~'([^']*)'~", $coluna[2], $valores);
            $declaradas[$tabela][strtolower($coluna[1])] = [
                'valores' => $valores[1],
                'origem' => substr($arquivo->getPathname(), strlen($root) + 1),
            ];
        }
    }
}

$config = new Config();
$pdo = Database::connect($config, Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db');

$stmt = $pdo->prepare(
    'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
);

$divergencias = [];
$conferidas = 0;
$ausentes = [];

foreach ($declaradas as $tabela => $colunas) {
    foreach ($colunas as $coluna => $info) {
        $stmt->execute(['t' => $tabela, 'c' => $coluna]);
        $tipo = $stmt->fetchColumn();
        if ($tipo === false) {
            $ausentes[] = "{$tabela}.{$coluna}";
            continue;
        }
        $conferidas++;

        preg_match_all("~'([^']*)'~", (string) $tipo, $m);
        $noBanco = $m[1];
        $noCodigo = $info['valores'];

        $faltamNoBanco = array_values(array_diff($noCodigo, $noBanco));
        $sobramNoBanco = array_values(array_diff($noBanco, $noCodigo));

        if ($faltamNoBanco === [] && $sobramNoBanco === []) {
            continue;
        }

        $divergencias[] = [
            'tabela' => $tabela,
            'coluna' => $coluna,
            'origem' => $info['origem'],
            // O caso perigoso: o codigo grava e o banco recusa.
            'faltam_no_banco' => $faltamNoBanco,
            // Menos grave, mas sinaliza codigo que envelheceu.
            'sobram_no_banco' => $sobramNoBanco,
            'grave' => $faltamNoBanco !== [],
        ];
    }
}

$graves = array_values(array_filter($divergencias, static fn (array $d): bool => $d['grave']));

$resultado = [
    'ok' => $graves === [],
    'colunas_conferidas' => $conferidas,
    'tabelas_declaradas' => count($declaradas),
    'nao_encontradas_no_banco' => $ausentes,
    'divergencias' => $divergencias,
];

if ($json) {
    echo json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit($resultado['ok'] ? 0 : 1);
}

printf("ENUM: codigo x banco — %d colunas conferidas em %d tabelas%s",
    $conferidas, count($declaradas), PHP_EOL);
if ($divergencias === []) {
    echo "nenhuma divergencia.\n";
    exit(0);
}
foreach ($divergencias as $d) {
    printf("\n%s %s.%s  (%s)\n", $d['grave'] ? '🔴' : '  ', $d['tabela'], $d['coluna'], $d['origem']);
    if ($d['faltam_no_banco'] !== []) {
        printf("   o codigo grava e o banco RECUSA: %s\n", implode(', ', $d['faltam_no_banco']));
    }
    if ($d['sobram_no_banco'] !== []) {
        printf("   existe no banco e o codigo nao declara: %s\n", implode(', ', $d['sobram_no_banco']));
    }
}
printf("\n%d divergencia(s), %d grave(s).\n", count($divergencias), count($graves));
exit($graves === [] ? 0 : 1);

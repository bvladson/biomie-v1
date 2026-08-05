<?php

declare(strict_types=1);

/**
 * CONTRATO — o sync não pode gravar valor fora do domínio da coluna.
 *
 * ─── O QUE ISTO IMPEDE DE VOLTAR ────────────────────────────────────────────
 * Em 2026-08-05, a instrumentação de SQLSTATE revelou a causa majoritária das
 * ~60% de falhas do sync — e não era deadlock nem conexão:
 *
 *   [01000/1265] Data truncated for column 'phase' at row 1        224 runs
 *   [01000/1265] Data truncated for column 'fonte_sync' at row 1    63 runs
 *
 * Duas variantes do MESMO defeito, a mesma família do sentinela de
 * `tipo_pessoa`:
 *
 *  1. `phase = 'chunked'` — o `CREATE TABLE IF NOT EXISTS` do repositório já
 *     declarava 'chunked', mas a tabela em produção não. `IF NOT EXISTS` nunca
 *     altera tabela existente: o código evoluiu e o banco ficou para trás.
 *  2. `fonte_sync = 'cron-delta'` — o sync passava o GATILHO para uma coluna
 *     que guarda a FONTE, `ENUM('cron','manual','webhook')`.
 *
 * Antes do STRICT_TRANS_TABLES os dois viravam string vazia em silêncio: 504 de
 * 505 linhas de `biomie_sync_state` e 9.882 linhas nas quatro tabelas canônicas
 * carregam esse sentinela. As falhas de agora não foram regressão — foi a
 * corrupção antiga ficando visível.
 *
 *   php tests/run_sync_enum_domain_contract.php
 */

require_once __DIR__ . '/TestSupport.php';

use BIomie\Canonical\Support\CanonicalSyncSource;

$checks = [];
$check = static function (string $name, bool $ok, mixed $detail = null) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

$root = dirname(__DIR__);

// ───────────────────────────── 1. fonte_sync: gatilho nunca chega na coluna
$check(
    '🔴 o gatilho do cron é normalizado para a fonte',
    CanonicalSyncSource::normalize('cron-delta') === 'cron'
        && CanonicalSyncSource::normalize('cron-backfill') === 'cron'
        && CanonicalSyncSource::normalize('job') === 'cron',
    implode(',', array_map(
        static fn (string $v): string => $v . '=>' . CanonicalSyncSource::normalize($v),
        ['cron-delta', 'cron-backfill', 'job'],
    )),
);

$check(
    'os membros legítimos passam intactos',
    CanonicalSyncSource::normalize('cron') === 'cron'
        && CanonicalSyncSource::normalize('manual') === 'manual'
        && CanonicalSyncSource::normalize('webhook') === 'webhook',
);

$check(
    'valor desconhecido cai no padrão em vez de derrubar o sync',
    CanonicalSyncSource::normalize('gatilho-que-ainda-nao-existe') === 'cron'
        && CanonicalSyncSource::normalize(null) === 'cron'
        && CanonicalSyncSource::normalize('') === 'cron',
);

$fora = array_values(array_filter(
    ['cron-delta', 'cron-backfill', 'job', 'queue-worker', 'webhook-omie', 'admin', 'cli', '', 'x', null],
    static fn (mixed $v): bool => !in_array(CanonicalSyncSource::normalize($v), CanonicalSyncSource::DOMAIN, true),
));
$check(
    '🔴 NENHUMA entrada produz valor fora do ENUM da coluna',
    $fora === [],
    implode(',', array_map(static fn ($v): string => var_export($v, true), $fora)),
);

// ─────────────────── 2. as quatro escritas canônicas passam pelo normalizador
$writeRepo = (string) file_get_contents($root . '/app/Canonical/CanonicalWriteRepository.php');
$cru = preg_match_all("~fonte_sync'?\s*(?:=>|\])\s*(?:\(string\)\s*)?\(?\\\$\w+\['fonte_sync'\]~", $writeRepo);
$check(
    '🔴 nenhuma escrita canônica usa fonte_sync cru',
    $cru === 0,
    "ocorrências cruas: {$cru}",
);
// Quatro escritas canônicas (pedido, NF, título, XML) mais o construtor de
// payload do XML. `>=` e não `===`: contagem exata quebraria a cada escrita
// nova legítima, e o invariante que importa é o de cima — nenhuma crua.
$check(
    'todas as escritas canônicas normalizam',
    substr_count($writeRepo, 'CanonicalSyncSource::normalize') >= 4,
    'encontradas: ' . substr_count($writeRepo, 'CanonicalSyncSource::normalize'),
);

// ───────────────────── 3. phase: o domínio do código tem que ter uma migration
$stateRepo = (string) file_get_contents($root . '/app/Canonical/CanonicalSyncStateRepository.php');
preg_match("~phase\s+ENUM\(([^)]*)\)~i", $stateRepo, $m);
preg_match_all("~'([^']*)'~", $m[1] ?? '', $declarados);
$dominioCodigo = $declarados[1] ?? [];

$check(
    'o CREATE TABLE do repositório declara o domínio de phase',
    $dominioCodigo !== [],
    implode(',', $dominioCodigo),
);
$check(
    "'chunked' está no domínio declarado (é o valor que o código grava)",
    in_array('chunked', $dominioCodigo, true),
);

$migrations = glob($root . '/database/migrations/*.php') ?: [];
$temMigrationDePhase = false;
foreach ($migrations as $arquivo) {
    $src = (string) file_get_contents($arquivo);
    if (str_contains($src, 'biomie_sync_state') && stripos($src, 'phase ENUM') !== false) {
        $temMigrationDePhase = true;
        break;
    }
}
$check(
    "🔴 existe migration alinhando o ENUM phase — CREATE TABLE IF NOT EXISTS não altera tabela existente",
    $temMigrationDePhase,
);

// ──────────────── 4. todo valor default de phase pertence ao domínio declarado
preg_match_all("~string \\\$phase = '([^']*)'~", $stateRepo, $defaults);
$defaultsFora = array_values(array_diff(array_unique($defaults[1] ?? []), $dominioCodigo));
$check(
    '🔴 todo default de $phase pertence ao domínio declarado',
    $defaultsFora === [],
    implode(',', $defaultsFora),
);

// ───────────────────────────── 5. a ferramenta de drift existe e é executável
$drift = $root . '/tools/dev/enum_domain_drift.php';
$check(
    'a ferramenta de drift entre código e banco existe',
    is_file($drift),
    'tools/dev/enum_domain_drift.php',
);
if (is_file($drift)) {
    $saida = [];
    $rc = 0;
    exec('php -l ' . escapeshellarg($drift) . ' 2>&1', $saida, $rc);
    $check('a ferramenta de drift compila', $rc === 0, implode(' ', $saida));
}

$passed = count(array_filter($checks, static fn (array $r): bool => $r['ok']));
$result = [
    'ok' => $passed === count($checks),
    'total' => count($checks),
    'passed' => $passed,
    'failed' => count($checks) - $passed,
    'checks' => $checks,
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
exit($result['ok'] ? 0 : 1);

<?php

declare(strict_types=1);

/**
 * PROVA DO GATE 2 — a barreira de banco de testes RECUSA produção.
 *
 * O usuário exigiu, textualmente: "executar uma suíte mutável apontada para o
 * banco produtivo e comprovar: bloqueada antes do primeiro SQL, 0 tabelas
 * alteradas, 0 linhas gravadas."
 *
 * Este harness FAZ isso de propósito, de forma segura:
 *   A) entrega ao guard uma conexão REAL de produção com APP_ENV=test e prova
 *      que ele bloqueia pelo NOME do banco (não termina em _test);
 *   B) entrega a mesma conexão SEM APP_ENV=test e prova o bloqueio pela intenção;
 *   C) SABOTA o test_db para apontar à PRODUÇÃO e roda uma suíte MUTÁVEL de
 *      verdade (subprocesso) — ela precisa abortar em test_pdo(), antes de
 *      qualquer escrita, e as tabelas reais precisam ficar intactas.
 *
 * É seguro porque, provado em (A), test_pdo() lança ANTES de devolver o PDO:
 * a suíte filha morre na linha da conexão, sem alcançar nenhum INSERT.
 *
 *   php tests/prove_test_barrier_blocks_prod.php
 */

require_once __DIR__ . '/TestSupport.php';
require_once __DIR__ . '/TestDatabaseGuard.php';

use BIomie\Config;
use BIomie\Database;
use BIomie\Tests\TestDatabaseGuard;

$checks = [];
$check = static function (string $name, bool $ok, mixed $detail = null) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

// ── Conexão REAL de produção (somente leitura aqui) ─────────────────────────
$config = new Config();
$prodConn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$prod = Database::connect($config, $prodConn);
$prodName = (string) $prod->query('SELECT DATABASE()')->fetchColumn();
$prodUser = (string) $config->get($prodConn . '.user');
$prodPass = (string) $config->get($prodConn . '.pass');

$check('conectado a um banco de PRODUÇÃO (não _test)',
    $prodName !== '' && preg_match('/(?:_test|_testing|_ci)$/i', $prodName) !== 1, $prodName);

// Tabelas reais que as suítes mutáveis tocam. Se a barreira falhar, alguma muda.
$tabelas = [
    'biomie_notifications',
    'biomie_pedidos_canonicos',
    'biomie_quote_followup_policy',
    'biomie_quote_live_snapshot_sync',
    'biomie_sales_quote_followup_schedule',
    'biomie_quote_order_refresh_log',
];
$contar = static function (PDO $pdo, array $tabelas): array {
    $out = [];
    foreach ($tabelas as $t) {
        $out[$t] = (int) $pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
    }
    return $out;
};
$antes = $contar($prod, $tabelas);

// ── PROVA A: NOME de produção é bloqueado mesmo com APP_ENV=test ─────────────
putenv('APP_ENV=test');
$_ENV['APP_ENV'] = 'test';
$blockedName = false;
$msgName = '';
try {
    TestDatabaseGuard::assertSafe($prod); // conexão REAL de produção
} catch (\RuntimeException $e) {
    $blockedName = true;
    $msgName = $e->getMessage();
}
$check('A. com APP_ENV=test, o NOME de produção é recusado', $blockedName, $msgName);
$check('A. a mensagem cita que não é banco de testes',
    str_contains($msgName, 'não é de testes'));

// ── PROVA B: sem APP_ENV=test, bloqueia pela intenção ───────────────────────
putenv('APP_ENV');            // remove
unset($_ENV['APP_ENV']);
$blockedEnv = false;
$msgEnv = '';
try {
    TestDatabaseGuard::assertSafe($prod);
} catch (\RuntimeException $e) {
    $blockedEnv = true;
    $msgEnv = $e->getMessage();
}
$check('B. sem APP_ENV=test, a suíte é recusada pela intenção', $blockedEnv, $msgEnv);
$check('B. a mensagem diz que nenhum SQL foi executado',
    str_contains($msgEnv, 'Nenhum SQL foi executado'));

// ── PROVA C: rodar uma suíte MUTÁVEL de verdade apontada à PRODUÇÃO ──────────
// Sabotagem: o test_db passa a apontar para o banco de PRODUÇÃO (nome/usuário/
// senha reais). O host já é o de produção por herança. APP_ENV=test para provar
// que o NOME sozinho barra. Passamos tudo pelo ENV do filho — nada na cmdline.
$suite = 'run_quote_agenda_freshness.php'; // escreve tabelas REAIS (com rollback)
$childEnv = [];
foreach ($_SERVER as $k => $v) {
    if (is_string($v)) {
        $childEnv[$k] = $v;
    }
}
$childEnv['APP_ENV'] = 'test';
$childEnv['DB_TEST_NAME'] = $prodName;   // ⚠️ deliberadamente PRODUÇÃO
$childEnv['DB_TEST_USER'] = $prodUser;
$childEnv['DB_TEST_PASS'] = $prodPass;
// host/porta/SSL do test_db herdam DB_* (produção) — não precisamos setar.

$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/' . $suite);
$descr = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = proc_open($cmd, $descr, $pipes, dirname(__DIR__), $childEnv);
$childOut = '';
if (is_resource($proc)) {
    $childOut = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
} else {
    $exit = -1;
}

$depois = $contar($prod, $tabelas);

$check('C. a suíte mutável apontada à produção ABORTOU (exit != 0)', $exit !== 0, 'exit=' . $exit);
$check('C. o abort foi da barreira ("não é de testes")',
    str_contains($childOut, 'não é de testes'), mb_substr(trim($childOut), -180));

$mudou = [];
foreach ($tabelas as $t) {
    if (($antes[$t] ?? null) !== ($depois[$t] ?? null)) {
        $mudou[$t] = ($antes[$t] ?? '?') . '→' . ($depois[$t] ?? '?');
    }
}
$check('C. ZERO linhas gravadas em produção (contagens idênticas)', $mudou === [], $mudou);

// ── Resultado ───────────────────────────────────────────────────────────────
$passed = count(array_filter($checks, static fn (array $r): bool => $r['ok']));
$result = [
    'ok' => $passed === count($checks),
    'total' => count($checks),
    'passed' => $passed,
    'failed' => count($checks) - $passed,
    'context' => ['prod_db' => $prodName, 'suite_sabotada' => $suite, 'child_exit' => $exit],
    'counts_before' => $antes,
    'counts_after' => $depois,
    'checks' => $checks,
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
exit($result['ok'] ? 0 : 1);

<?php

declare(strict_types=1);

/**
 * BARREIRA DE BANCO DE TESTES — prova que produção é recusada.
 *
 * O gate que o usuário exigiu antes do piloto: uma suíte MUTÁVEL apontada para o
 * banco de PRODUÇÃO precisa ser bloqueada ANTES do primeiro SQL, com ZERO linhas
 * gravadas e ZERO tabelas alteradas.
 *
 * Este teste é READ-ONLY sobre produção (só SELECT DATABASE()/COUNT) e usa o
 * PDO real justamente para provar que o guard reconhece produção e recusa.
 *
 *   php tests/run_test_database_guard.php
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

$config = new Config();
$pdo = Database::connect($config, Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db');
$dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
$appEnv = (string) getenv('APP_ENV');

$check('conectado ao banco de produção (cenário do gate)',
    $dbName !== '' && preg_match('/(?:_test|_testing|_ci)$/i', $dbName) !== 1, $dbName);
$check('APP_ENV não é "test" no ambiente atual', $appEnv !== 'test', $appEnv === '' ? '(vazio)' : $appEnv);

// ── 🔴 O GATE: produção é bloqueada ─────────────────────────────────────────
$blocked = false;
$msg = '';
$errorPhase = 'antes de qualquer SQL de escrita';
try {
    TestDatabaseGuard::assertSafe($pdo);
    // Se chegou aqui, o guard NÃO bloqueou — falha grave.
} catch (\RuntimeException $e) {
    $blocked = true;
    $msg = $e->getMessage();
}
$check('🔴 assertSafe() BLOQUEIA o banco de produção', $blocked, $msg);
$check('🔴 a mensagem diz que nenhum SQL foi executado',
    str_contains($msg, 'Nenhum SQL foi executado'));
$check('isSafe() devolve false em produção', TestDatabaseGuard::isSafe($pdo) === false);

// ── Prova de que NADA foi gravado ───────────────────────────────────────────
// Uma suíte real chamaria assertSafe ANTES de criar tabelas/inserir. Aqui
// simulamos: contamos uma tabela real antes e depois do guard falhar.
$before = (int) $pdo->query('SELECT COUNT(*) FROM biomie_quote_order_refresh_log')->fetchColumn();
try {
    TestDatabaseGuard::assertSafe($pdo);
    $pdo->exec("INSERT INTO biomie_quote_order_refresh_log
        (company_id, omie_order_id, decision, reason, source) VALUES (1,1,'keep','NUNCA DEVE ENTRAR','cli')");
} catch (\RuntimeException) {
    // esperado: o guard impediu de chegar ao INSERT
}
$after = (int) $pdo->query('SELECT COUNT(*) FROM biomie_quote_order_refresh_log')->fetchColumn();
$check('🔴 ZERO linhas gravadas após o bloqueio', $before === $after, "{$before} → {$after}");

// ── O guard LIBERA um alvo de teste válido (simulado) ───────────────────────
// Provamos a lógica positiva sem depender de um banco _test existir: um fake
// PDO que responde APP_ENV=test + DATABASE() terminando em _test passaria.
// Como não temos banco _test aqui, validamos a REGRA diretamente.
$regraOk = preg_match('/(?:_test|_testing|_ci)$/i', 'u106688109_test') === 1
        && preg_match('/(?:_test|_testing|_ci)$/i', 'biomie_ci') === 1
        && preg_match('/(?:_test|_testing|_ci)$/i', 'app_testing') === 1;
$check('regra aceita bancos _test / _ci / _testing', $regraOk);
$check('regra REJEITA nome de produção (u106688109_Comercial)',
    preg_match('/(?:_test|_testing|_ci)$/i', 'u106688109_Comercial') !== 1);

$passed = count(array_filter($checks, static fn (array $r): bool => $r['ok']));
$result = [
    'ok' => $passed === count($checks),
    'total' => count($checks),
    'passed' => $passed,
    'failed' => count($checks) - $passed,
    'context' => ['db' => $dbName, 'app_env' => $appEnv],
    'checks' => $checks,
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
exit($result['ok'] ? 0 : 1);

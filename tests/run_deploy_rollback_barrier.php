<?php

declare(strict_types=1);

/**
 * DEPLOY — barreira de rollback + liberação verificada de locks.
 *
 * Cobre os dois caminhos que um deploy BEM SUCEDIDO nunca exercita:
 *
 *  1. A decisão de rollback depois do DDL. Reverter demais é tão perigoso
 *     quanto reverter de menos: devolver o CLI antigo a um schema novo deixa
 *     o servidor com código que desconhece as colunas que passaram a existir.
 *  2. A liberação do lock. GET_LOCK pertence à CONEXÃO — nem commit nem fim
 *     de transação o soltam. Um lock esquecido trava o pipeline das 5 empresas.
 *
 * O lock exercitado aqui tem nome PRÓPRIO de teste: nenhum job de produção é
 * bloqueado, mas a semântica SQL provada é exatamente a mesma do deploy.
 *
 *   php tests/run_deploy_rollback_barrier.php
 */

require_once __DIR__ . '/TestSupport.php';

use BIomie\Config;
use BIomie\Database;

// Carrega apenas as definições do script de deploy (ele sai cedo sem --apply
// só depois de I/O pesado, então isolamos a função pura por include guardado).
require_once __DIR__ . '/../tools/dev/quote_agenda_deploy_barrier_fn.php';

$checks = [];
$check = static function (string $name, bool $ok, mixed $detail = null) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

const ALVO = '20260718120000';

// ── 1. DDL_DONE — único estado que autoriza seguir para o runtime ────────────
$check(
    'sucesso completo → DDL_DONE',
    agendaBarreiraDecidir(true, 3, true, [ALVO], []) === 'DDL_DONE'
);

// ── 2. PRE_DDL — nada mudou no schema, tudo é reversível ─────────────────────
$check(
    'falha sem tocar o schema → PRE_DDL',
    agendaBarreiraDecidir(false, 0, false, [], []) === 'PRE_DDL'
);

// ── 3. RECOVERY_REQUIRED — todo meio-termo ───────────────────────────────────
// Cada linha é um jeito real de o DDL parar no meio.
$intermediarios = [
    'DDL parcial: 1 de 3 colunas, sem versão'   => [false, 1, false, [], []],
    'DDL parcial: 2 de 3 colunas, sem versão'   => [false, 2, false, [], []],
    'colunas criadas mas versão NÃO registrada' => [false, 3, false, [], []],
    'versão registrada mas colunas ausentes'    => [false, 0, true,  [ALVO], []],
    'versão registrada com colunas parciais'    => [true,  2, true,  [ALVO], []],
    'migrate saiu 0 porém colunas incompletas'  => [true,  1, true,  [ALVO], []],
];
foreach ($intermediarios as $nome => $args) {
    $r = agendaBarreiraDecidir(...$args);
    $check("{$nome} → RECOVERY_REQUIRED", $r === 'RECOVERY_REQUIRED', $r);
}

// ── 4. Migration ALHEIA arrastada nunca pode ser tratada como sucesso ────────
// Este é o cenário que mais assusta: o out-of-order aplicou algo de outro
// fluxo de trabalho. Reverter arquivos aqui destruiria trabalho de terceiros.
$r = agendaBarreiraDecidir(true, 3, true, [ALVO, '20260718010000'], ['20260718010000']);
$check('alheia arrastada → RECOVERY_REQUIRED (nunca DDL_DONE)', $r === 'RECOVERY_REQUIRED', $r);

$r = agendaBarreiraDecidir(true, 3, true, [ALVO], ['20260718020000']);
$check('alheia já aplicada detectada → RECOVERY_REQUIRED', $r === 'RECOVERY_REQUIRED', $r);

// ── 5. Nenhum estado inesperado escapa ───────────────────────────────────────
$estados = [];
foreach ([true, false] as $ok) {
    foreach ([0, 1, 2, 3] as $cols) {
        foreach ([true, false] as $reg) {
            foreach ([[], [ALVO], [ALVO, '20260718010000']] as $novas) {
                foreach ([[], ['20260718010000']] as $alh) {
                    $estados[agendaBarreiraDecidir($ok, $cols, $reg, $novas, $alh)] = true;
                }
            }
        }
    }
}
ksort($estados);
$check(
    'apenas 3 estados possíveis (nenhum retorno inesperado)',
    array_keys($estados) === ['DDL_DONE', 'PRE_DDL', 'RECOVERY_REQUIRED'],
    implode(',', array_keys($estados))
);

// ── 6. Só DDL_DONE preserva o stage1 sem restaurar runtime ───────────────────
// Invariante de segurança: PRE_DDL é o ÚNICO estado que pode apagar o stage1.
$podeReverterStage1 = static fn (string $b): bool => $b === 'PRE_DDL';
$check('DDL_DONE NÃO permite reverter o stage1', !$podeReverterStage1('DDL_DONE'));
$check('RECOVERY_REQUIRED NÃO permite reverter o stage1', !$podeReverterStage1('RECOVERY_REQUIRED'));
$check('PRE_DDL permite reverter o stage1', $podeReverterStage1('PRE_DDL'));

// ── 7. LOCK: RELEASE_LOCK = 1 e ausência confirmada ──────────────────────────
$config = new Config();
$pdo = Database::connect($config, Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db');
$nome = 'biomie:deploy_barrier_selftest:' . getmypid();

$got = $pdo->prepare('SELECT GET_LOCK(:n, 5) AS a');
$got->execute(['n' => $nome]);
$check('GET_LOCK adquirido', (int) $got->fetchColumn() === 1);

$st = $pdo->prepare('SELECT IS_USED_LOCK(:n) AS used, IS_FREE_LOCK(:n2) AS free');
$st->execute(['n' => $nome, 'n2' => $nome]);
$antes = $st->fetch(PDO::FETCH_ASSOC) ?: [];
$check('com o lock preso: IS_USED_LOCK != NULL', $antes['used'] !== null, (string) ($antes['used'] ?? 'NULL'));
$check('com o lock preso: IS_FREE_LOCK = 0', (int) $antes['free'] === 0, (string) $antes['free']);

// Uma transação não solta o lock — é justamente por isso que o deploy confere.
$pdo->beginTransaction();
$pdo->query('SELECT 1');
$pdo->commit();
$st->execute(['n' => $nome, 'n2' => $nome]);
$posCommit = $st->fetch(PDO::FETCH_ASSOC) ?: [];
$check('COMMIT NÃO libera o lock (por isso a conferência existe)',
    (int) $posCommit['free'] === 0, 'IS_FREE_LOCK=' . (string) $posCommit['free']);

$rl = $pdo->prepare('SELECT RELEASE_LOCK(:n) AS r');
$rl->execute(['n' => $nome]);
$r = $rl->fetchColumn();
$check('RELEASE_LOCK retornou exatamente 1', $r !== null && (int) $r === 1, $r === null ? 'NULL' : (string) $r);

$st->execute(['n' => $nome, 'n2' => $nome]);
$dep = $st->fetch(PDO::FETCH_ASSOC) ?: [];
$check('após liberar: IS_USED_LOCK IS NULL', $dep['used'] === null, (string) ($dep['used'] ?? 'NULL'));
$check('após liberar: IS_FREE_LOCK = 1', (int) $dep['free'] === 1, (string) $dep['free']);

// Liberar de novo devolve 0 (existe alguém?) ou NULL (não existe) — nunca 1.
// Prova que exigir "= 1" distingue liberação REAL de no-op silencioso.
$rl->execute(['n' => $nome]);
$r2 = $rl->fetchColumn();
$check('segundo RELEASE_LOCK NÃO retorna 1 (no-op é detectável)',
    $r2 === null || (int) $r2 !== 1, $r2 === null ? 'NULL' : (string) $r2);

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

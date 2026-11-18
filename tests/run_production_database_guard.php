<?php

declare(strict_types=1);

/**
 * BARREIRA DE PRODUÇÃO — prova de que o ProductionDatabaseGuard trava escrita
 * estrutural em produção sem intenção declarada (Fase B/C, endurecimento).
 *
 * A propriedade é de SEGURANÇA, simétrica à do TestDatabaseGuard: assim como uma
 * suíte mutável não pode tocar produção, um comando local (migration, DDL) não
 * pode escrever em produção por acidente. O que já aconteceu em 2026-07-23 (uma
 * migration "local" que caiu em produção porque o ambiente aponta para lá).
 *
 * Testa a LÓGICA sem precisar de um PDO de produção: o casamento do nome do
 * banco e a detecção da flag/env são estáticos. O bloqueio real (contra o banco
 * de produção vivo) foi provado manualmente com `migrate-one` recusado.
 *
 *   php tests/run_production_database_guard.php
 */

require_once __DIR__ . '/TestSupport.php';

use BIomie\Support\ProductionDatabaseGuard as G;

$checks = [];
$check = static function (string $name, bool $ok, mixed $detail = null) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

// ── 1. Casamento do nome do banco ───────────────────────────────────────────
$check('🔴 banco de produção reconhecido', G::isProductionDatabase('u106688109_Comercial'));
$check('banco de teste NÃO é produção', !G::isProductionDatabase('u106688109_test'));
$check('banco local NÃO é produção', !G::isProductionDatabase('flight_plan_local'));
$check('vazio NÃO é produção (mas também não libera escrita — ver abaixo)', !G::isProductionDatabase(''));

// ── 2. Detecção de intenção (flag / env) ────────────────────────────────────
// Estado inicial: sem flag, sem env.
putenv('BIOMIE_ALLOW_PRODUCTION');
$argvBackup = $_SERVER['argv'] ?? [];
$_SERVER['argv'] = ['tests/run_production_database_guard.php'];
$check('🔴 sem flag e sem env ⇒ intenção NÃO declarada', !G::isExplicitlyAllowed());

$_SERVER['argv'] = ['x', '--allow-production'];
$check('flag --allow-production ⇒ intenção declarada', G::isExplicitlyAllowed());

$_SERVER['argv'] = ['tests/run_production_database_guard.php'];
putenv('BIOMIE_ALLOW_PRODUCTION=1');
$check('env BIOMIE_ALLOW_PRODUCTION=1 ⇒ intenção declarada (caminho do servidor)', G::isExplicitlyAllowed());
putenv('BIOMIE_ALLOW_PRODUCTION=0');
$check('env =0 NÃO declara intenção', !G::isExplicitlyAllowed());
putenv('BIOMIE_ALLOW_PRODUCTION');

// ── 2b. Classificação servidor + banco (21/09/2026) ─────────────────────────
// Depois da migração para a VPS o guard protegia só o banco ANTIGO e deixava a
// produção real sem barreira. Produção agora é o par servidor+banco; o nome
// "biomie" sozinho não basta; o legado da Hostinger segue protegido pelo nome.
$check('🔴 produção real reconhecida (biomie @ srv-vps)',
    G::classify('biomie', 'srv-vps') === G::TARGET_PRODUCTION);
$check('servidor sem diferenciar maiúsculas',
    G::classify('biomie', 'SRV1851839') === G::TARGET_PRODUCTION);
$check('"biomie" em outro servidor NÃO é produção (cópia local/staging)',
    G::classify('biomie', 'notebook-dev') === G::TARGET_OTHER);
$check('🔴 legado congelado reconhecido em qualquer servidor',
    G::classify('u106688109_Comercial', 'br-asc-web1659') === G::TARGET_LEGACY);
$check('banco _test continua fora da proteção',
    G::classify('u106688109_test', 'srv-vps') === G::TARGET_OTHER);

// ── 3. O guard NÃO atrapalha bancos de não-produção (test_db) ───────────────
// test_pdo aponta para o banco _test — o guard deve LIBERAR (não é produção).
$pdo = test_pdo();
$_SERVER['argv'] = ['tests/run_production_database_guard.php']; // sem flag
$check('banco de teste ⇒ escrita liberada (guard não interfere em não-produção)',
    G::isWriteAllowed($pdo));
$didThrow = false;
try {
    G::assertWriteAllowed($pdo, 'fixture de teste');
} catch (\RuntimeException) {
    $didThrow = true;
}
$check('🔴 assertWriteAllowed NÃO lança em banco de teste', !$didThrow);

// Restaura argv.
$_SERVER['argv'] = $argvBackup;

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

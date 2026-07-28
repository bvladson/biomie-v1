<?php

declare(strict_types=1);

/**
 * VISIBILIDADE COMERCIAL — modos persistidos e sqlFilter (Fase B).
 *
 * A Fase A provou o contrato null/[]/intersect com o modo implícito (own via
 * vínculo). Aqui provamos o modo EXPLÍCITO, gravado em biomie_user_seller_scope:
 *
 *   · `all`      vence o vínculo — um vendedor promovido a gestor vê tudo mesmo
 *                tendo binding.
 *   · `own`      lê o vínculo; SEM vínculo ⇒ [] (o admin restringiu de propósito,
 *                não pode degradar para "todos").
 *   · `selected` lê a lista escolhida; lista vazia ⇒ [] (idem).
 *
 * E provamos a primitiva da onda (Fase C): sqlFilter() → 1=1 / 1=0 / IN(...),
 * com binds distintos por chamada para não colidir na mesma query.
 *
 * Todas as tabelas são TEMPORARY: sombreiam as reais na sessão, isolam o teste.
 * Cada cenário usa um user_id distinto — o resolvedor tem cache por (empresa,
 * usuário), então reusar o mesmo id entre cenários leria valor velho.
 *
 *   php tests/run_seller_visibility_mode.php
 */

require_once __DIR__ . '/TestSupport.php';

use BIomie\Support\SellerVisibilityScope;

$checks = [];
$check = static function (string $name, bool $ok, mixed $detail = null) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

$pdo = test_pdo(); // suíte MUTÁVEL

$pdo->exec('CREATE TEMPORARY TABLE biomie_quote_seller_binding (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT NOT NULL, company_id BIGINT NOT NULL, seller_id VARCHAR(32) NOT NULL,
    created_by BIGINT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id), UNIQUE KEY uq_seller_binding (company_id, seller_id)
) ENGINE=InnoDB');

$pdo->exec("CREATE TEMPORARY TABLE biomie_user_seller_scope (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id TINYINT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL,
    visibility_mode ENUM('all','own','selected') NOT NULL DEFAULT 'all',
    created_by INT UNSIGNED NULL, updated_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id), UNIQUE KEY uq_user_seller_scope (company_id, user_id)
) ENGINE=InnoDB");

$pdo->exec('CREATE TEMPORARY TABLE biomie_user_visible_sellers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id TINYINT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL,
    seller_id VARCHAR(32) NOT NULL, created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id), UNIQUE KEY uq_user_visible_seller (company_id, user_id, seller_id)
) ENGINE=InnoDB');

$bindIns   = $pdo->prepare('INSERT INTO biomie_quote_seller_binding (user_id, company_id, seller_id) VALUES (:u,:c,:s)');
$modeIns   = $pdo->prepare('INSERT INTO biomie_user_seller_scope (company_id, user_id, visibility_mode) VALUES (:c,:u,:m)');
$pickIns   = $pdo->prepare('INSERT INTO biomie_user_visible_sellers (company_id, user_id, seller_id) VALUES (:c,:u,:s)');

$CID = 1;
$scope = new SellerVisibilityScope($pdo);

// ── Cenário A: modo `all` VENCE o vínculo ───────────────────────────────────
$UA = 7001;
$bindIns->execute(['u' => $UA, 'c' => $CID, 's' => 'COD_A']);   // tem carteira própria
$modeIns->execute(['c' => $CID, 'u' => $UA, 'm' => 'all']);     // mas admin liberou tudo
$check('modo all ⇒ null (todos), mesmo com vínculo',
    $scope->allowedSellerIds($CID, $UA) === null);
$check('mode() reporta "all"', $scope->mode($CID, $UA) === SellerVisibilityScope::MODE_ALL);

// ── Cenário B: modo `own` lê o vínculo ──────────────────────────────────────
$UB = 7002;
$bindIns->execute(['u' => $UB, 'c' => $CID, 's' => 'COD_B1']);
$bindIns->execute(['u' => $UB, 'c' => $CID, 's' => 'COD_B2']);
$modeIns->execute(['c' => $CID, 'u' => $UB, 'm' => 'own']);
$check('modo own ⇒ códigos do vínculo',
    $scope->allowedSellerIds($CID, $UB) === ['COD_B1', 'COD_B2'],
    json_encode($scope->allowedSellerIds($CID, $UB)));
$check('mode() reporta "own"', $scope->mode($CID, $UB) === SellerVisibilityScope::MODE_OWN);

// ── Cenário C: 🔴 modo `own` SEM vínculo ⇒ [] (não null) ────────────────────
$UC = 7003;
$modeIns->execute(['c' => $CID, 'u' => $UC, 'm' => 'own']);     // own, mas nenhum binding
$check('🔴 own sem vínculo ⇒ [] (o admin restringiu; NUNCA vira "todos")',
    $scope->allowedSellerIds($CID, $UC) === []);

// ── Cenário D: modo `selected` lê a lista ───────────────────────────────────
$UD = 7004;
$modeIns->execute(['c' => $CID, 'u' => $UD, 'm' => 'selected']);
$pickIns->execute(['c' => $CID, 'u' => $UD, 's' => 'COD_X']);
$pickIns->execute(['c' => $CID, 'u' => $UD, 's' => 'COD_Y']);
$check('modo selected ⇒ os vendedores escolhidos',
    $scope->allowedSellerIds($CID, $UD) === ['COD_X', 'COD_Y'],
    json_encode($scope->allowedSellerIds($CID, $UD)));
$check('mode() reporta "selected"', $scope->mode($CID, $UD) === SellerVisibilityScope::MODE_SELECTED);

// ── Cenário E: 🔴 modo `selected` SEM ninguém escolhido ⇒ [] ────────────────
$UE = 7005;
$modeIns->execute(['c' => $CID, 'u' => $UE, 'm' => 'selected']);   // selected, lista vazia
$check('🔴 selected sem escolhas ⇒ [] (não degrada para todos)',
    $scope->allowedSellerIds($CID, $UE) === []);

// ── Cenário F: selected filtra código "0"/vazio ─────────────────────────────
$UF = 7006;
$modeIns->execute(['c' => $CID, 'u' => $UF, 'm' => 'selected']);
$pickIns->execute(['c' => $CID, 'u' => $UF, 's' => 'COD_REAL']);
$pickIns->execute(['c' => $CID, 'u' => $UF, 's' => '0']);          // sem vendedor
$check('selected ignora código "0" (não vira carteira fantasma)',
    $scope->allowedSellerIds($CID, $UF) === ['COD_REAL']);

// ── Cenário G: escopo é POR EMPRESA (modo de uma não vaza na outra) ─────────
$UG = 7007;
$modeIns->execute(['c' => 1, 'u' => $UG, 'm' => 'selected']);
$pickIns->execute(['c' => 1, 'u' => $UG, 's' => 'C1_ONLY']);
$modeIns->execute(['c' => 2, 'u' => $UG, 'm' => 'all']);
$check('empresa 1 do UG ⇒ [C1_ONLY]', $scope->allowedSellerIds(1, $UG) === ['C1_ONLY']);
$check('🔴 empresa 2 do UG ⇒ null (config independente por empresa)',
    $scope->allowedSellerIds(2, $UG) === null);

// ── Cenário H: sem linha ⇒ DEFAULT (own se tem vínculo, all senão) ──────────
$UH_bound = 7008; $UH_free = 7009;
$bindIns->execute(['u' => $UH_bound, 'c' => $CID, 's' => 'COD_H']);
$check('sem linha + vínculo ⇒ default own (carteira própria)',
    $scope->allowedSellerIds($CID, $UH_bound) === ['COD_H']);
$check('sem linha + sem vínculo ⇒ default all (null)',
    $scope->allowedSellerIds($CID, $UH_free) === null);

// ── sqlFilter: a primitiva da onda (Fase C) ─────────────────────────────────
$pA = []; $sqlAll = $scope->sqlFilter($CID, $UA, 'nf', $pA);       // modo all
$check('sqlFilter modo all ⇒ 1=1 sem binds', $sqlAll === '1=1' && $pA === []);

$pE = []; $sqlNone = $scope->sqlFilter($CID, $UE, 'nf', $pE);      // selected vazio
$check('🔴 sqlFilter escopo vazio ⇒ 1=0 (fail-closed)', $sqlNone === '1=0' && $pE === []);

$pD = []; $sqlSel = $scope->sqlFilter($CID, $UD, 'nf', $pD, 'codigo_vendedor_omie');
$check('sqlFilter selected ⇒ IN com alias e coluna',
    str_starts_with($sqlSel, 'nf.codigo_vendedor_omie IN (') && count($pD) === 2,
    $sqlSel . ' :: ' . json_encode($pD));
$check('sqlFilter binds batem com os códigos escolhidos',
    array_values($pD) === ['COD_X', 'COD_Y'], json_encode($pD));

// Duas aplicações na MESMA query não colidem (binds com nomes distintos).
$pMulti = [];
$f1 = $scope->sqlFilter($CID, $UD, 'a', $pMulti);
$f2 = $scope->sqlFilter($CID, $UD, 'b', $pMulti);
$check('🔴 dois sqlFilter na mesma query não colidem binds',
    $f1 !== $f2 && count($pMulti) === 4 && count(array_unique(array_keys($pMulti))) === 4,
    $f1 . ' | ' . $f2 . ' :: ' . json_encode(array_keys($pMulti)));

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

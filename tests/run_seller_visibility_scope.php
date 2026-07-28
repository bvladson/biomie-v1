<?php

declare(strict_types=1);

/**
 * VISIBILIDADE COMERCIAL — SellerVisibilityScope (Fase A).
 *
 * A propriedade sob teste é de SEGURANÇA e tem uma sutileza que decide tudo:
 * `null` (todos) e `[]` (nenhum) são estados DIFERENTES. Um bug que confunda os
 * dois é escalada de privilégio silenciosa — o usuário mais restrito do sistema
 * passa a ver a empresa inteira, sem erro nem log.
 *
 * Provamos também que o filtro pedido pelo usuário só RESTRINGE: `intersect()`
 * nunca pode devolver um código fora do escopo autorizado, venha ele da query
 * string, do dropdown ou de uma URL montada à mão.
 *
 * Tabela criada como TEMPORARY: auto-limpa, invisível a outras sessões, não
 * toca no schema real.
 *
 *   php tests/run_seller_visibility_scope.php
 */

require_once __DIR__ . '/TestSupport.php';

use BIomie\Support\SellerVisibilityScope;

$checks = [];
$check = static function (string $name, bool $ok, mixed $detail = null) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

$pdo = test_pdo(); // suíte MUTÁVEL: escreve → só o test_db aceita (TestDatabaseGuard bloqueia produção)

// Espelha a migração 20260720000000, porém TEMPORARY.
$pdo->exec('CREATE TEMPORARY TABLE biomie_quote_seller_binding (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT NOT NULL,
    company_id BIGINT NOT NULL,
    seller_id VARCHAR(32) NOT NULL,
    created_by BIGINT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_seller_binding (company_id, seller_id),
    KEY idx_seller_binding_user (user_id)
) ENGINE=InnoDB');

$UVEND   = 9101;   // vendedor com 1 código na empresa 1
$UMULTI  = 9102;   // vendedor com 2 códigos na empresa 1 e 1 na empresa 2
$UGESTOR = 9103;   // sem vínculo — gestor

$ins = $pdo->prepare('INSERT INTO biomie_quote_seller_binding (user_id, company_id, seller_id, created_by) VALUES (:u,:c,:s,1)');
$ins->execute(['u' => $UVEND,  'c' => 1, 's' => '8788268737']);
$ins->execute(['u' => $UMULTI, 'c' => 1, 's' => '8766948159']);
$ins->execute(['u' => $UMULTI, 'c' => 1, 's' => '8774120215']);
$ins->execute(['u' => $UMULTI, 'c' => 2, 's' => '8767742583']);

$scope = new SellerVisibilityScope($pdo);

// ── 1. Default sem configuração (decisão de 2026-07-23) ─────────────────────
$check('gestor SEM vínculo ⇒ null (todos da empresa)',
    $scope->allowedSellerIds(1, $UGESTOR) === null);
$check('vendedor COM vínculo ⇒ só o próprio código',
    $scope->allowedSellerIds(1, $UVEND) === ['8788268737']);
$check('vendedor multi-código ⇒ os 2 códigos da empresa',
    $scope->allowedSellerIds(1, $UMULTI) === ['8766948159', '8774120215'],
    json_encode($scope->allowedSellerIds(1, $UMULTI)));

// ── 2. O escopo é POR EMPRESA — a base do pedido do gestor ──────────────────
$check('mesma conta, outra empresa ⇒ escopo independente',
    $scope->allowedSellerIds(2, $UMULTI) === ['8767742583']);
$check('vendedor da empresa 1 NÃO herda restrição na empresa 2 (sem vínculo lá ⇒ null)',
    $scope->allowedSellerIds(2, $UVEND) === null);

// ── 3. 🔴 null ≠ [] — a distinção que impede escalada de privilégio ─────────
$check('🔴 usuário inválido (0) ⇒ [] e NUNCA null',
    $scope->allowedSellerIds(1, 0) === []);
$check('🔴 empresa inválida (0) ⇒ [] e NUNCA null',
    $scope->allowedSellerIds(0, $UVEND) === []);
$check('🔴 sem contexto NÃO degrada para "todos"',
    $scope->allowedSellerIds(0, 0) !== null);

// ── 4. canSee: a validação de seller_id vindo da request ────────────────────
$check('vendedor pode ver o próprio código', $scope->canSee(1, $UVEND, '8788268737'));
$check('🔴 vendedor NÃO pode ver código alheio (URL adulterada)',
    !$scope->canSee(1, $UVEND, '8766948159'));
$check('gestor sem restrição pode ver qualquer código', $scope->canSee(1, $UGESTOR, '8766948159'));
$check('🔴 usuário sem contexto não pode ver nada', !$scope->canSee(1, 0, '8788268737'));

// ── 5. intersect: o filtro RESTRINGE, jamais AMPLIA ─────────────────────────
$check('sem filtro do usuário ⇒ escopo puro',
    $scope->intersect(1, $UMULTI, []) === ['8766948159', '8774120215']);
$check('filtro dentro do escopo ⇒ só o pedido',
    $scope->intersect(1, $UMULTI, ['8774120215']) === ['8774120215']);
$check('🔴 filtro FORA do escopo ⇒ [] (não o código pedido, nem o escopo inteiro)',
    $scope->intersect(1, $UMULTI, ['8788268737']) === []);
$check('🔴 filtro misto mantém só o autorizado',
    $scope->intersect(1, $UMULTI, ['8774120215', '8788268737']) === ['8774120215'],
    json_encode($scope->intersect(1, $UMULTI, ['8774120215', '8788268737'])));
$check('gestor sem restrição + filtro ⇒ o filtro pedido',
    $scope->intersect(1, $UGESTOR, ['8788268737']) === ['8788268737']);
$check('gestor sem restrição sem filtro ⇒ null (todos)',
    $scope->intersect(1, $UGESTOR, []) === null);

// ── 5b. MATRIZ COMPLETA — é ela que impede o retorno da divergência entre
// card, calendário e Agenda: os três chamam intersect() com o mesmo contrato,
// então cada célula precisa estar fixada por teste, não por inspeção.
//
//   escopo null + sem filtro   → null (todos)
//   escopo null + filtro       → só o filtro
//   escopo []   + qualquer     → [] SEMPRE
//   escopo [A,B] + sem filtro  → [A,B]
//   escopo [A,B] + filtro[B,C] → [B]
$m = [
    'null + sem filtro ⇒ null (todos)'      => $scope->intersect(1, $UGESTOR, []) === null,
    'null + filtro ⇒ só o filtro'           => $scope->intersect(1, $UGESTOR, ['X', 'Y']) === ['X', 'Y'],
    '🔴 [] + sem filtro ⇒ []'               => $scope->intersect(0, 0, []) === [],
    '🔴 [] + filtro ⇒ [] (filtro NÃO amplia)' => $scope->intersect(0, 0, ['8788268737']) === [],
    '[A,B] + sem filtro ⇒ [A,B]'            => $scope->intersect(1, $UMULTI, []) === ['8766948159', '8774120215'],
    '[A,B] + filtro [B,C] ⇒ [B]'            => $scope->intersect(1, $UMULTI, ['8774120215', 'C_ESTRANHO']) === ['8774120215'],
];
foreach ($m as $caso => $ok) {
    $check("matriz intersect · {$caso}", $ok);
}

// ── 6. mode(): o rótulo que a UI usa para explicar o que está sendo visto ───
$check('mode do gestor é "all"', $scope->mode(1, $UGESTOR) === SellerVisibilityScope::MODE_ALL);
$check('mode do vendedor é "own"', $scope->mode(1, $UVEND) === SellerVisibilityScope::MODE_OWN);

// ── 7. Cache por request não vaza entre pares (empresa, usuário) ────────────
$scope->allowedSellerIds(1, $UMULTI);                       // aquece o cache
$check('🔴 cache não devolve o escopo da empresa 1 para a empresa 2',
    $scope->allowedSellerIds(2, $UMULTI) === ['8767742583']);
$check('🔴 cache não devolve o escopo de um usuário para outro',
    $scope->allowedSellerIds(1, $UVEND) === ['8788268737']);

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

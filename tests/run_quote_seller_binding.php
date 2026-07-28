<?php

declare(strict_types=1);

/**
 * AGENDA — visão do VENDEDOR: vínculo + escopo (Fase 10, fundação).
 *
 * A propriedade que importa aqui é de SEGURANÇA: um vendedor vê SÓ a própria
 * carteira, e "sem vínculo" significa VER NADA — nunca a carteira inteira.
 * Um escopo vazio que degradasse para "tudo" exporia todos os orçamentos de
 * todos os vendedores a qualquer conta.
 *
 * A tabela de vínculo é criada como TEMPORARY (auto-limpa, invisível a outras
 * sessões, não toca no schema real nem na migração ainda pendente).
 *
 *   php tests/run_quote_seller_binding.php
 */

require_once __DIR__ . '/TestSupport.php';

use BIomie\Config;
use BIomie\Database;
use BIomie\QuoteAgenda\QuoteAgendaReadService;
use BIomie\QuoteAgenda\SellerBindingResolver;

$checks = [];
$check = static function (string $name, bool $ok, mixed $detail = null) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

$config = new Config();
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

$resolver = new SellerBindingResolver($pdo);

// IDs sintéticos de teste (não colidem com usuários reais 1-3).
$UVEND_A = 9001; // vendedor A
$UVEND_B = 9002; // vendedor B
$UMULTI  = 9003; // vendedor com 2 códigos
$UNADA   = 9004; // sem vínculo

$ins = $pdo->prepare('INSERT INTO biomie_quote_seller_binding (user_id, company_id, seller_id, created_by) VALUES (:u,:c,:s,1)');
$ins->execute(['u' => $UVEND_A, 'c' => 1, 's' => '8788268737']); // 15 orçamentos na MAG
$ins->execute(['u' => $UVEND_B, 'c' => 1, 's' => '8831004323']); //  8 orçamentos na MAG
$ins->execute(['u' => $UMULTI,  'c' => 1, 's' => '8766948159']); //  7 orçamentos
$ins->execute(['u' => $UMULTI,  'c' => 1, 's' => '8774120215']); //  4 orçamentos (mesmo user, 2 códigos)
$ins->execute(['u' => $UMULTI,  'c' => 2, 's' => '8767742583']); // outra empresa

// ── 1. Resolver ─────────────────────────────────────────────────────────────
$check('vendedor A resolve para seu código', $resolver->sellerIdsFor($UVEND_A, 1) === ['8788268737']);
$check('usuário SEM vínculo resolve para lista VAZIA', $resolver->sellerIdsFor($UNADA, 1) === []);
$check('vendedor multi-código resolve para os 2 códigos da empresa',
    $resolver->sellerIdsFor($UMULTI, 1) === ['8766948159', '8774120215'],
    json_encode($resolver->sellerIdsFor($UMULTI, 1)));
$check('escopo é POR EMPRESA (empresa 2 traz só o código da empresa 2)',
    $resolver->sellerIdsFor($UMULTI, 2) === ['8767742583']);
$check('vendedor A não enxerga vínculo em empresa onde não atua',
    $resolver->sellerIdsFor($UVEND_A, 2) === []);
$check('isBoundSeller true para vendedor mapeado', $resolver->isBoundSeller($UVEND_A, 1));
$check('isBoundSeller false para não mapeado', !$resolver->isBoundSeller($UNADA, 1));
$check('user_id inválido (0) resolve vazio (nunca vê tudo)', $resolver->sellerIdsFor(0, 1) === []);
$check('bindingsFor agrupa por empresa',
    $resolver->bindingsFor($UMULTI) === [1 => ['8766948159', '8774120215'], 2 => ['8767742583']],
    json_encode($resolver->bindingsFor($UMULTI)));

// UNIQUE (company_id, seller_id): um código pertence a UM usuário.
$dup = false;
try {
    $ins->execute(['u' => 9999, 'c' => 1, 's' => '8788268737']); // já é do vendedor A
} catch (\PDOException) {
    $dup = true;
}
$check('UNIQUE impede o mesmo código Omie pertencer a 2 usuários', $dup);

// seller_id '0' (pedido sem vendedor) nunca vira carteira.
$ins->execute(['u' => 9005, 'c' => 1, 's' => '0']);
$check("código '0' (sem vendedor) é filtrado — não vira carteira fantasma",
    $resolver->sellerIdsFor(9005, 1) === []);

// ── 2. Escopo na leitura (dado REAL de followup) ────────────────────────────
$svc = new QuoteAgendaReadService($pdo, 1);

$total = count($svc->openItems());
$doA   = count($svc->openItems(['seller_ids' => ['8788268737']]));
$doB   = count($svc->openItems(['seller_ids' => ['8831004323']]));
$check('escopo de A traz só os itens de A (>0 e < total)', $doA > 0 && $doA < $total, "{$doA}/{$total}");

// A não pode ver B: os conjuntos são disjuntos.
$idsA = array_column($svc->openItems(['seller_ids' => ['8788268737']]), 'followup_id');
$idsB = array_column($svc->openItems(['seller_ids' => ['8831004323']]), 'followup_id');
$check('🔴 carteira de A e de B são DISJUNTAS (vendedor não vê alheio)',
    array_intersect($idsA, $idsB) === []);

// Multi-código soma os dois.
$doMulti = count($svc->openItems(['seller_ids' => ['8766948159', '8774120215']]));
$check('multi-código soma as duas carteiras', $doMulti > $doA - $doA, "{$doMulti}");
$check('todo item do escopo pertence a um dos códigos pedidos',
    array_filter($svc->openItems(['seller_ids' => ['8766948159', '8774120215']]),
        static fn (array $it): bool => !in_array((string) $it['seller_id'], ['8766948159', '8774120215'], true)) === []);

// ── 3. 🔴 A TRAVA DE SEGURANÇA: escopo vazio ⇒ NADA (nunca tudo) ────────────
$check('🔴 seller_ids = [] retorna ZERO itens (não a carteira inteira)',
    $svc->openItems(['seller_ids' => []]) === []);
$check('🔴 seller_ids só com "0"/vazios retorna ZERO',
    $svc->openItems(['seller_ids' => ['0', '']]) === []);
$check('código inexistente retorna ZERO (não vaza)',
    $svc->openItems(['seller_ids' => ['nao_existe_999']]) === []);

// O escopo também vale para summary (KPIs do vendedor).
$sumA = $svc->summary(['seller_ids' => ['8788268737']]);
$sumVazio = $svc->summary(['seller_ids' => []]);
$check('summary escopado conta só a carteira do vendedor',
    (int) $sumA['kpis']['open']['count'] === $doA, (int) $sumA['kpis']['open']['count'] . " vs {$doA}");
$check('summary com escopo vazio zera os KPIs',
    (int) $sumVazio['kpis']['open']['count'] === 0);

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

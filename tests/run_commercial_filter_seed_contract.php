<?php

declare(strict_types=1);

/**
 * CONTRATO DE SEMEADURA do filtro comercial — o que a preferência pode injetar.
 *
 * Onde o bug mora: o endpoint semeia `$fVendorIds` a partir do escopo resolvido
 * quando a URL não pediu vendedor. Se ele semear o ESCOPO DE VISIBILIDADE (o que
 * o usuário PODE ver) em vez da INTENÇÃO (o que ele PEDIU), um usuário restrito
 * passa a se comportar como se tivesse filtrado vendedores sem ter filtrado:
 *
 *   · `if ($fVendorIds === [] && $fVendedor !== '')` vira falso ⇒ o filtro por
 *     NOME de vendedor é silenciosamente IGNORADO para usuários restritos.
 *   · `seller_scope.requested_vendor_ids` passa a reportar um pedido que não houve.
 *
 * A regra correta: semear SÓ `requestedSellerIds` (intenção). O escopo já é
 * aplicado incondicionalmente pelo VendorScopeFilter — semear de novo é
 * redundante e, pior, muda o comportamento de outros filtros.
 *
 *   php tests/run_commercial_filter_seed_contract.php
 */

require_once __DIR__ . '/TestSupport.php';

use BIomie\Commercial\CommercialFilterPreferenceStore;
use BIomie\Commercial\CommercialFilterResolver;

$checks = [];
$check = static function (string $name, bool $ok, mixed $detail = null) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

$pdo = test_pdo();
$PAGE = CommercialFilterPreferenceStore::PAGE_COMMERCIAL;

$pdo->exec('CREATE TEMPORARY TABLE biomie_user_companies (
    user_id INT UNSIGNED NOT NULL, company_id TINYINT UNSIGNED NOT NULL,
    can_varejo TINYINT(1) DEFAULT 1, can_licitacao TINYINT(1) DEFAULT 0,
    is_default TINYINT(1) DEFAULT 0, role_override VARCHAR(20) NULL, default_canal VARCHAR(20) NULL,
    PRIMARY KEY (user_id, company_id)) ENGINE=InnoDB');
$pdo->exec('CREATE TEMPORARY TABLE biomie_quote_seller_binding (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT, company_id BIGINT, seller_id VARCHAR(32),
    created_by BIGINT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq (company_id, seller_id)) ENGINE=InnoDB');
$pdo->exec("CREATE TEMPORARY TABLE biomie_user_seller_scope (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id TINYINT UNSIGNED, user_id INT UNSIGNED,
    visibility_mode ENUM('all','own','selected') DEFAULT 'all', created_by INT NULL, updated_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq (company_id, user_id)) ENGINE=InnoDB");
$pdo->exec('CREATE TEMPORARY TABLE biomie_user_visible_sellers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id TINYINT UNSIGNED, user_id INT UNSIGNED,
    seller_id VARCHAR(32), created_by INT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq (company_id, user_id, seller_id)) ENGINE=InnoDB');
$pdo->exec("CREATE TEMPORARY TABLE biomie_user_commercial_filters (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id TINYINT UNSIGNED, user_id INT UNSIGNED,
    page VARCHAR(48), business_scope ENUM('varejo','licitacao','mixed'),
    channels_json VARCHAR(64) NULL, seller_ids_json TEXT NULL, updated_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq (company_id, user_id, page, business_scope)) ENGINE=InnoDB");

// U_REST = restrito (selected A1,A2,A3) · U_ALL = sem restrição (all)
$U_REST = 9001; $U_ALL = 9002;
$ins = $pdo->prepare('INSERT INTO biomie_user_companies (user_id,company_id,can_varejo,can_licitacao) VALUES (?,1,1,1)');
$ins->execute([$U_REST]);
$ins->execute([$U_ALL]);
$pdo->prepare("INSERT INTO biomie_user_seller_scope (company_id,user_id,visibility_mode) VALUES (1,?,'selected')")->execute([$U_REST]);
foreach (['A1', 'A2', 'A3'] as $s) {
    $pdo->prepare('INSERT INTO biomie_user_visible_sellers (company_id,user_id,seller_id) VALUES (1,?,?)')->execute([$U_REST, $s]);
}

$store = new CommercialFilterPreferenceStore($pdo);
$noUrl = ['channel_present' => false, 'channels' => [], 'seller_present' => false, 'seller_ids' => []];

// ── 1. Restrito, SEM preferência: não pode semear nada ───────────────────────
$r = CommercialFilterResolver::fromPdo($pdo)->resolve(1, $U_REST, $PAGE, 'mixed', $noUrl);
$check('🔴 restrito sem pref: requestedSellerIds = null (não houve pedido)',
    $r->requestedSellerIds === null, json_encode($r->requestedSellerIds));
$check('restrito sem pref: effectiveSellerIds = o escopo (aplicado pelo VendorScopeFilter)',
    is_array($r->effectiveSellerIds) && count($r->effectiveSellerIds) === 3, json_encode($r->effectiveSellerIds));
$check('🔴 semear por INTENÇÃO não injeta filtro fantasma (o de escopo injetaria)',
    $r->requestedSellerIds === null && $r->effectiveSellerIds !== null);

// ── 2. Sem restrição, SEM preferência ────────────────────────────────────────
$r = CommercialFilterResolver::fromPdo($pdo)->resolve(1, $U_ALL, $PAGE, 'mixed', $noUrl);
$check('irrestrito sem pref: intenção null E efetivo null (nada a semear)',
    $r->requestedSellerIds === null && $r->effectiveSellerIds === null);

// ── 3. Preferência REAL de vendedores deve semear ────────────────────────────
$store->save(1, $U_REST, $PAGE, 'mixed', null, ['A2']);
$r = CommercialFilterResolver::fromPdo($pdo)->resolve(1, $U_REST, $PAGE, 'mixed', $noUrl);
$check('pref [A2]: intenção semeia [A2]', $r->requestedSellerIds === ['A2'], json_encode($r->requestedSellerIds));
$check('pref [A2]: efetivo também [A2]', $r->effectiveSellerIds === ['A2']);
$store->clear(1, $U_REST, $PAGE, 'mixed');

// ── 4. Preferência "todos os autorizados" NÃO deve virar filtro explícito ────
$store->save(1, $U_REST, $PAGE, 'mixed', null, ['A1', 'A2', 'A3']);
$r = CommercialFilterResolver::fromPdo($pdo)->resolve(1, $U_REST, $PAGE, 'mixed', $noUrl);
$check('🔴 pref = todos os autorizados ⇒ intenção volta a null (não congela a lista)',
    $r->requestedSellerIds === null, json_encode($r->requestedSellerIds));
$store->clear(1, $U_REST, $PAGE, 'mixed');

// ── 5. Preferência "nenhum" ([]) precisa continuar zerando ───────────────────
$store->save(1, $U_REST, $PAGE, 'mixed', null, []);
$r = CommercialFilterResolver::fromPdo($pdo)->resolve(1, $U_REST, $PAGE, 'mixed', $noUrl);
$check('🔴 pref [] (nenhum): intenção [] ⇒ força zero linhas',
    $r->requestedSellerIds === [] && $r->effectiveSellerIds === []);
$store->clear(1, $U_REST, $PAGE, 'mixed');

// ── 6. Preferência sanitizada a zero (todos revogados) ⇒ zero, não "todos" ───
$store->save(1, $U_REST, $PAGE, 'mixed', null, ['ZZ9']);   // nunca autorizado
$r = CommercialFilterResolver::fromPdo($pdo)->resolve(1, $U_REST, $PAGE, 'mixed', $noUrl);
$check('🔴 pref inteiramente revogada ⇒ [] (zero linhas), jamais "todos"',
    $r->requestedSellerIds === [] && $r->effectiveSellerIds === [] && $r->sellersSanitized);
$store->clear(1, $U_REST, $PAGE, 'mixed');

// ── 7. URL explícita continua vencendo a preferência ─────────────────────────
$store->save(1, $U_REST, $PAGE, 'mixed', null, ['A1']);
$r = CommercialFilterResolver::fromPdo($pdo)->resolve(1, $U_REST, $PAGE, 'mixed', [
    'channel_present' => false, 'channels' => [],
    'seller_present' => true, 'seller_ids' => ['A3'],
]);
$check('URL [A3] vence pref [A1]', $r->effectiveSellerIds === ['A3'] && $r->source === 'url');
$store->clear(1, $U_REST, $PAGE, 'mixed');

$passed = count(array_filter($checks, static fn (array $r): bool => $r['ok']));
$result = ['ok' => $passed === count($checks), 'total' => count($checks),
           'passed' => $passed, 'failed' => count($checks) - $passed, 'checks' => $checks];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
exit($result['ok'] ? 0 : 1);

<?php

declare(strict_types=1);

/**
 * FILTRO COMERCIAL INTELIGENTE — persistência + resolução + sanitização.
 *
 * Propriedades provadas (SEGURANÇA e CONSISTÊNCIA):
 *  · null ≠ []  sobrevive à ida-e-volta no Store (todos vs nenhum).
 *  · Precedência: URL explícita > preferência salva > default inteligente.
 *  · URL pedindo algo FORA do autorizado ⇒ 403 (não interseção silenciosa).
 *  · Preferência desatualizada ⇒ remove SÓ o proibido, sinaliza, NUNCA amplia.
 *  · Intenção "todas" persiste como null (não congela a lista atual).
 *  · filter_hash estável e sensível.
 *
 * Tabelas TEMPORARY sombreiam as reais (test_db via TestDatabaseGuard).
 *
 *   php tests/run_commercial_filter_intelligence.php
 */

require_once __DIR__ . '/TestSupport.php';

use BIomie\Commercial\CommercialFilterPreferenceStore;
use BIomie\Commercial\CommercialFilterResolver;
use BIomie\Commercial\CommercialScopeDeniedException;

$checks = [];
$check = static function (string $name, bool $ok, mixed $detail = null) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};
$throws = static function (callable $fn): bool {
    try { $fn(); return false; } catch (CommercialScopeDeniedException) { return true; }
};

$pdo = test_pdo();
$PAGE = CommercialFilterPreferenceStore::PAGE_COMMERCIAL_DASHBOARD;

// ── Tabelas de autorização + preferência ─────────────────────────────────────
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

// Cenário empresa 1:
//   U_BOTH = gestor varejo+licitacao, sem restrição de vendedor (all)
//   U_VAR  = só varejo, modo selected [S1,S2,S3]
$U_BOTH = 701; $U_VAR = 702;
$ins = $pdo->prepare('INSERT INTO biomie_user_companies (user_id,company_id,can_varejo,can_licitacao) VALUES (:u,1,:v,:l)');
$ins->execute(['u' => $U_BOTH, 'v' => 1, 'l' => 1]);
$ins->execute(['u' => $U_VAR, 'v' => 1, 'l' => 0]);
$pdo->prepare("INSERT INTO biomie_user_seller_scope (company_id,user_id,visibility_mode) VALUES (1,:u,'selected')")->execute(['u' => $U_VAR]);
foreach (['S1', 'S2', 'S3'] as $s) {
    $pdo->prepare('INSERT INTO biomie_user_visible_sellers (company_id,user_id,seller_id) VALUES (1,:u,:s)')->execute(['u' => $U_VAR, 's' => $s]);
}

$store = new CommercialFilterPreferenceStore($pdo);
$R = CommercialFilterResolver::fromPdo($pdo);

$req = static fn (array $o = []): array => [
    'channel_present' => $o['channel_present'] ?? false,
    'channels' => $o['channels'] ?? [],
    'seller_present' => $o['seller_present'] ?? false,
    'seller_ids' => $o['seller_ids'] ?? [],
];

// ── 1. STORE — null ≠ [] sobrevive à ida-e-volta ─────────────────────────────
$store->save(1, $U_BOTH, $PAGE, 'mixed', null, null);
$loaded = $store->load(1, $U_BOTH, $PAGE, 'mixed');
$check('store: null salvo ⇒ null lido (todos), não []',
    $loaded !== null && $loaded['channels'] === null && $loaded['seller_ids'] === null, json_encode($loaded));

$store->save(1, $U_BOTH, $PAGE, 'mixed', ['licitacao'], []);
$loaded = $store->load(1, $U_BOTH, $PAGE, 'mixed');
$check('store: [] salvo ⇒ [] lido (nenhum), ≠ null',
    $loaded['seller_ids'] === [] && $loaded['channels'] === ['licitacao'], json_encode($loaded));

$store->save(1, $U_BOTH, $PAGE, 'mixed', ['varejo', 'licitacao'], ['A', 'B']);
$loaded = $store->load(1, $U_BOTH, $PAGE, 'mixed');
$check('store: upsert sobrescreve; lista preservada',
    $loaded['seller_ids'] === ['A', 'B'] && $loaded['channels'] === ['varejo', 'licitacao'], json_encode($loaded));

$check('store: recorte diferente (business_scope) não vaza',
    $store->load(1, $U_BOTH, $PAGE, 'varejo') === null);
$store->clear(1, $U_BOTH, $PAGE, 'mixed');
$check('store: clear remove ⇒ null', $store->load(1, $U_BOTH, $PAGE, 'mixed') === null);
$check('store: página desconhecida ⇒ não persiste (load null)',
    $store->load(1, $U_BOTH, 'pagina_fantasma', 'mixed') === null);

// ── 2. DEFAULT (sem URL, sem preferência) ────────────────────────────────────
$r = $R->resolve(1, $U_BOTH, $PAGE, 'mixed', $req());
$check('default: canais = todos autorizados [varejo,licitacao]',
    $r->effectiveChannels === ['varejo', 'licitacao'], json_encode($r->effectiveChannels));
$check('default: vendedores = null (todos autorizados)', $r->effectiveSellerIds === null);
$check('default: source=default, sem sanitização', $r->source === 'default' && !$r->sanitized());
$check('default: intenção de canal = null (todas)', $r->requestedChannels === null);

// ── 3. URL EXPLÍCITA — precedência e 403 ─────────────────────────────────────
$r = $R->resolve(1, $U_BOTH, $PAGE, 'mixed', $req(['channel_present' => true, 'channels' => ['licitacao']]));
$check('url: gestor pede só licitacao ⇒ [licitacao]', $r->effectiveChannels === ['licitacao']);
$check('url: intenção persiste subconjunto (licitacao)', $r->requestedChannels === ['licitacao']);
$check('url: source=url', $r->source === 'url');

$check('🔴 url: só-varejo pede licitacao ⇒ 403',
    $throws(fn () => $R->resolve(1, $U_VAR, $PAGE, 'varejo', $req(['channel_present' => true, 'channels' => ['licitacao']]))));
$check('🔴 url: canal fora do allowlist ⇒ 403',
    $throws(fn () => $R->resolve(1, $U_BOTH, $PAGE, 'mixed', $req(['channel_present' => true, 'channels' => ['consolidado']]))));

$r = $R->resolve(1, $U_VAR, $PAGE, 'varejo', $req(['seller_present' => true, 'seller_ids' => ['S2']]));
$check('url: selected pede S2 (dentro) ⇒ [S2]', $r->effectiveSellerIds === ['S2']);
$check('🔴 url: selected pede S9 (fora) ⇒ 403',
    $throws(fn () => $R->resolve(1, $U_VAR, $PAGE, 'varejo', $req(['seller_present' => true, 'seller_ids' => ['S9']]))));
$check('🔴 url: selected pede misto S1+S9 ⇒ 403',
    $throws(fn () => $R->resolve(1, $U_VAR, $PAGE, 'varejo', $req(['seller_present' => true, 'seller_ids' => ['S1', 'S9']]))));

$r = $R->resolve(1, $U_VAR, $PAGE, 'varejo', $req(['seller_present' => true, 'seller_ids' => []]));
$check('url: seller presente vazio = limpar ⇒ todos autorizados [S1,S2,S3]',
    $r->effectiveSellerIds !== null && count($r->effectiveSellerIds) === 3);

// URL vence preferência salva.
$store->save(1, $U_BOTH, $PAGE, 'mixed', ['varejo'], ['A']);
$r = $R->resolve(1, $U_BOTH, $PAGE, 'mixed', $req(['channel_present' => true, 'channels' => ['licitacao']]));
$check('precedência: URL vence preferência salva', $r->effectiveChannels === ['licitacao'] && $r->source === 'url');
$store->clear(1, $U_BOTH, $PAGE, 'mixed');

// ── 4. PREFERÊNCIA — leitura e default ───────────────────────────────────────
$store->save(1, $U_BOTH, $PAGE, 'mixed', ['licitacao'], null);
$r = $R->resolve(1, $U_BOTH, $PAGE, 'mixed', $req());
$check('pref: canal salvo [licitacao] ⇒ efetivo [licitacao]', $r->effectiveChannels === ['licitacao']);
$check('pref: source=preference', $r->source === 'preference');
$store->clear(1, $U_BOTH, $PAGE, 'mixed');

$store->save(1, $U_VAR, $PAGE, 'varejo', null, ['S1', 'S2']);
$r = $R->resolve(1, $U_VAR, $PAGE, 'varejo', $req());
$prefSellers = $r->effectiveSellerIds ?? [];
sort($prefSellers);
$check('pref: vendedores salvos [S1,S2] ⇒ efetivo [S1,S2]', $prefSellers === ['S1', 'S2']);
$store->clear(1, $U_VAR, $PAGE, 'varejo');

// ── 5. SANITIZAÇÃO — remove só o proibido, nunca amplia ──────────────────────
// Preferência tem S1,S2 mas o admin removeu S2 do escopo (agora só S1,S3 visíveis
// mudando a lista): simulamos removendo S2 de visible_sellers.
$pdo->prepare('DELETE FROM biomie_user_visible_sellers WHERE company_id=1 AND user_id=:u AND seller_id=:s')
    ->execute(['u' => $U_VAR, 's' => 'S2']);   // agora U_VAR vê S1,S3
// A permissão mudou entre requisições ⇒ resolvedor NOVO (o antigo cacheou o escopo).
$R2 = CommercialFilterResolver::fromPdo($pdo);
$store->save(1, $U_VAR, $PAGE, 'varejo', null, ['S1', 'S2']);   // pref pede S1(ok) + S2(proibido)
$r = $R2->resolve(1, $U_VAR, $PAGE, 'varejo', $req());
$check('sanitiza: pref [S1,S2] com S2 revogado ⇒ efetivo só [S1]',
    $r->effectiveSellerIds === ['S1'], json_encode($r->effectiveSellerIds));
$check('sanitiza: sinaliza sellersSanitized + dropped=[S2]',
    $r->sellersSanitized && $r->droppedSellers === ['S2']);
$check('🔴 sanitiza: NUNCA vira todos (não amplia p/ S1,S3)',
    $r->effectiveSellerIds === ['S1']);
$store->clear(1, $U_VAR, $PAGE, 'varejo');

// Preferência de todos os proibidos ⇒ efetivo [] (nenhum), não "todos".
$store->save(1, $U_VAR, $PAGE, 'varejo', null, ['S9']);   // S9 nunca foi autorizado
$r = $R2->resolve(1, $U_VAR, $PAGE, 'varejo', $req());
$check('🔴 sanitiza: todos proibidos ⇒ [] (nenhum), jamais todos',
    $r->effectiveSellerIds === [] && $r->sellersSanitized, json_encode($r->effectiveSellerIds));
$store->clear(1, $U_VAR, $PAGE, 'varejo');

// Preferência [] (nenhum explícito) permanece [] — não degrada para todos.
$store->save(1, $U_BOTH, $PAGE, 'mixed', null, []);
$r = $R->resolve(1, $U_BOTH, $PAGE, 'mixed', $req());
$check('pref []: nenhum explícito permanece [] (não vira todos)',
    $r->effectiveSellerIds === [] && !$r->sellersSanitized);
$store->clear(1, $U_BOTH, $PAGE, 'mixed');

// Canal salvo que o usuário perdeu (defensivo): mixed já garante 2 canais, então
// usamos U_VAR (só varejo) com pref de licitacao ⇒ sanitiza p/ todos autorizados.
$store->save(1, $U_VAR, $PAGE, 'varejo', ['licitacao'], null);
$r = $R2->resolve(1, $U_VAR, $PAGE, 'varejo', $req());
$check('sanitiza canal: pref [licitacao] não autorizada ⇒ efetivo [varejo] (autorizado) + flag',
    $r->effectiveChannels === ['varejo'] && $r->channelsSanitized && $r->droppedChannels === ['licitacao']);
$store->clear(1, $U_VAR, $PAGE, 'varejo');

// ── 6. INTENÇÃO "todas" ⇒ null ───────────────────────────────────────────────
$r = $R->resolve(1, $U_BOTH, $PAGE, 'mixed', $req(['channel_present' => true, 'channels' => ['varejo', 'licitacao']]));
$check('intenção: pedir os 2 canais (== autorizado) ⇒ requestedChannels null (todas)',
    $r->requestedChannels === null && $r->effectiveChannels === ['varejo', 'licitacao']);

// ── 6.5 MERGE PARCIAL (endpoint) — trocar canal NÃO apaga vendedor ────────────
// Simula o commercial_filter_pref.php: SAVE só-canal ⇒ eixo vendedor vem da pref.
$store->save(1, $U_BOTH, $PAGE, 'mixed', ['varejo'], ['S1']);
// só-canal: channel_present=true, seller_present=false ⇒ sellers preservados
$merge = $R->resolve(1, $U_BOTH, $PAGE, 'mixed', $req(['channel_present' => true, 'channels' => ['licitacao']]));
$store->save(1, $U_BOTH, $PAGE, 'mixed', $merge->requestedChannels, $merge->requestedSellerIds);
$after = $store->load(1, $U_BOTH, $PAGE, 'mixed');
$check('merge só-canal: canal vira [licitacao], vendedor [S1] PRESERVADO',
    $after['channels'] === ['licitacao'] && $after['seller_ids'] === ['S1'], json_encode($after));
// só-vendedor: channel_present=false ⇒ canal preservado
$merge2 = $R->resolve(1, $U_BOTH, $PAGE, 'mixed', $req(['seller_present' => true, 'seller_ids' => ['S1', 'S2']]));
$store->save(1, $U_BOTH, $PAGE, 'mixed', $merge2->requestedChannels, $merge2->requestedSellerIds);
$after2 = $store->load(1, $U_BOTH, $PAGE, 'mixed');
sort($after2['seller_ids']);
$check('merge só-vendedor: vendedor vira [S1,S2], canal [licitacao] PRESERVADO',
    $after2['channels'] === ['licitacao'] && $after2['seller_ids'] === ['S1', 'S2'], json_encode($after2));
$store->clear(1, $U_BOTH, $PAGE, 'mixed');

// ── 7. filter_hash estável e sensível ────────────────────────────────────────
$scopeA = $R->resolve(1, $U_BOTH, $PAGE, 'mixed', $req(['channel_present' => true, 'channels' => ['licitacao']]))
    ->toEffectiveScope(1, '2026-07-01', '2026-07-31');
$scopeB = $R->resolve(1, $U_BOTH, $PAGE, 'mixed', $req(['channel_present' => true, 'channels' => ['licitacao']]))
    ->toEffectiveScope(1, '2026-07-01', '2026-07-31');
$check('filter_hash: mesmo recorte ⇒ mesmo hash', $scopeA->filterHash() === $scopeB->filterHash());
$scopeC = $R->resolve(1, $U_BOTH, $PAGE, 'mixed', $req())
    ->toEffectiveScope(1, '2026-07-01', '2026-07-31');
$check('filter_hash: recorte diferente ⇒ hash diferente', $scopeA->filterHash() !== $scopeC->filterHash());

$passed = count(array_filter($checks, static fn (array $r): bool => $r['ok']));
$result = ['ok' => $passed === count($checks), 'total' => count($checks),
           'passed' => $passed, 'failed' => count($checks) - $passed, 'checks' => $checks];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
exit($result['ok'] ? 0 : 1);

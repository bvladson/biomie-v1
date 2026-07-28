<?php

declare(strict_types=1);

/**
 * FASE D — contrato único de escopo comercial (vendedores ∩ canais).
 *
 * A propriedade é de SEGURANÇA e de CONSISTÊNCIA: um só objeto decide o que
 * cada card/gráfico/tabela pode ler, e o payload do cliente NUNCA amplia. Prova:
 *  · vendedor adulterado ⇒ 403 (não interseção silenciosa)
 *  · canal adulterado / fora do allowlist ⇒ 403
 *  · null = todos autorizados · [] = nenhum (jamais "vazio = todos")
 *  · "todos autorizados" = a lista autorizada, não a empresa inteira
 *  · filter_hash estável (mesma seleção, mesmo hash, independe da ordem)
 *
 * Tabelas TEMPORARY sombreiam as reais.
 *
 *   php tests/run_commercial_scope_resolver.php
 */

require_once __DIR__ . '/TestSupport.php';

use BIomie\Commercial\CommercialChannelScope;
use BIomie\Commercial\CommercialFilterSet;
use BIomie\Commercial\CommercialScopeDeniedException;
use BIomie\Commercial\CommercialScopeResolver;

$checks = [];
$check = static function (string $name, bool $ok, mixed $detail = null) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};
$throws = static function (callable $fn): bool {
    try { $fn(); return false; } catch (CommercialScopeDeniedException) { return true; }
};

$pdo = test_pdo();

// Espelha as tabelas de autorização.
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

// Cenário: empresa 1.
//  U_BOTH  = gestor com varejo+licitacao, sem restrição de vendedor (all)
//  U_VAR   = só varejo, modo selected [S1,S2]
//  U_LIC   = só licitacao, sem vínculo
$U_BOTH = 501; $U_VAR = 502; $U_LIC = 503;
$pdo->prepare('INSERT INTO biomie_user_companies (user_id,company_id,can_varejo,can_licitacao) VALUES (:u,1,:v,:l)')
    ->execute(['u' => $U_BOTH, 'v' => 1, 'l' => 1]);
$pdo->prepare('INSERT INTO biomie_user_companies (user_id,company_id,can_varejo,can_licitacao) VALUES (:u,1,:v,:l)')
    ->execute(['u' => $U_VAR, 'v' => 1, 'l' => 0]);
$pdo->prepare('INSERT INTO biomie_user_companies (user_id,company_id,can_varejo,can_licitacao) VALUES (:u,1,:v,:l)')
    ->execute(['u' => $U_LIC, 'v' => 0, 'l' => 1]);
// U_VAR: modo selected com S1,S2
$pdo->prepare("INSERT INTO biomie_user_seller_scope (company_id,user_id,visibility_mode) VALUES (1,:u,'selected')")->execute(['u' => $U_VAR]);
$pdo->prepare('INSERT INTO biomie_user_visible_sellers (company_id,user_id,seller_id) VALUES (1,:u,:s)')->execute(['u' => $U_VAR, 's' => 'S1']);
$pdo->prepare('INSERT INTO biomie_user_visible_sellers (company_id,user_id,seller_id) VALUES (1,:u,:s)')->execute(['u' => $U_VAR, 's' => 'S2']);

$R = CommercialScopeResolver::fromPdo($pdo);
$fs = static fn (array $over = []): CommercialFilterSet => new CommercialFilterSet(
    sellerIds: $over['sellerIds'] ?? null,
    channels: $over['channels'] ?? [],
    start: '2026-07-01', end: '2026-07-31',
);

// ── 1. CANAIS ───────────────────────────────────────────────────────────────
$chan = new CommercialChannelScope($pdo);
$check('gestor both ⇒ allowedChannels [varejo,licitacao]',
    $chan->allowedChannels(1, $U_BOTH) === ['varejo', 'licitacao']);
$check('só varejo ⇒ [varejo]', $chan->allowedChannels(1, $U_VAR) === ['varejo']);
$check('só licitacao ⇒ [licitacao]', $chan->allowedChannels(1, $U_LIC) === ['licitacao']);

$check('canal vazio (todos autorizados) ⇒ todos os canais do gestor',
    $R->resolve(1, $U_BOTH, $fs())->channels === ['varejo', 'licitacao']);
$check('gestor pede só licitacao ⇒ [licitacao]',
    $R->resolve(1, $U_BOTH, $fs(['channels' => ['licitacao']]))->channels === ['licitacao']);
$check('🔴 só-varejo pede licitacao (URL adulterada) ⇒ 403',
    $throws(fn () => $R->resolve(1, $U_VAR, $fs(['channels' => ['licitacao']]))));
$check('🔴 canal fora do allowlist ("consolidado") ⇒ 403',
    $throws(fn () => $R->resolve(1, $U_BOTH, $fs(['channels' => ['consolidado']]))));
$check('só-varejo pede varejo ⇒ [varejo] (dentro do autorizado)',
    $R->resolve(1, $U_VAR, $fs(['channels' => ['varejo']]))->channels === ['varejo']);

// ── 2. VENDEDORES ─────────────────────────────────────────────────────────
$check('gestor (all) sem filtro ⇒ null (todos autorizados)',
    $R->resolve(1, $U_BOTH, $fs())->sellerIds === null);
$check('gestor (all) + filtro ⇒ o filtro pedido',
    $R->resolve(1, $U_BOTH, $fs(['sellerIds' => ['X', 'Y']]))->sellerIds === ['X', 'Y']);

$sel = $R->resolve(1, $U_VAR, $fs())->sellerIds;
sort($sel);
$check('selected sem filtro ⇒ os 2 escolhidos', $sel === ['S1', 'S2'], json_encode($sel));
$check('selected + filtro dentro ⇒ interseção',
    $R->resolve(1, $U_VAR, $fs(['sellerIds' => ['S2']]))->sellerIds === ['S2']);
$check('🔴 selected + vendedor FORA do escopo ⇒ 403 (não interseção silenciosa)',
    $throws(fn () => $R->resolve(1, $U_VAR, $fs(['sellerIds' => ['S9']]))));
$check('🔴 selected + misto (um proibido) ⇒ 403',
    $throws(fn () => $R->resolve(1, $U_VAR, $fs(['sellerIds' => ['S1', 'S9']]))));

// ── 3. SEMÂNTICA null/[] — jamais "vazio = todos" ─────────────────────────
$check('🔴 usuário sem contexto (0/0) ⇒ vendedores [] E canais []',
    $R->resolve(0, 0, $fs())->sellerIds === [] && $R->resolve(0, 0, $fs())->channels === []);
$check('🔴 escopo vazio ⇒ isEmpty() = true (query deve dar zero)',
    $R->resolve(0, 0, $fs())->isEmpty());
$check('gestor com dados ⇒ isEmpty() = false',
    !$R->resolve(1, $U_BOTH, $fs())->isEmpty());

// ── 4. filter_hash estável e sensível ─────────────────────────────────────
$h1 = $R->resolve(1, $U_BOTH, new CommercialFilterSet(sellerIds: ['B', 'A'], channels: ['licitacao', 'varejo'], start: '2026-07-01', end: '2026-07-31'))->filterHash();
$h2 = $R->resolve(1, $U_BOTH, new CommercialFilterSet(sellerIds: ['A', 'B'], channels: ['varejo', 'licitacao'], start: '2026-07-01', end: '2026-07-31'))->filterHash();
$check('🔴 filter_hash IGUAL independente da ordem de vendedores/canais', $h1 === $h2);
$h3 = $R->resolve(1, $U_BOTH, new CommercialFilterSet(sellerIds: ['A'], channels: ['varejo', 'licitacao'], start: '2026-07-01', end: '2026-07-31'))->filterHash();
$check('filter_hash MUDA quando a seleção muda', $h1 !== $h3);
$hNull = $R->resolve(1, $U_BOTH, $fs())->filterHash();
$hEmpty = $R->resolve(1, $U_BOTH, new CommercialFilterSet(sellerIds: [], channels: [], start: '2026-07-01', end: '2026-07-31'));
$check('🔴 sellerIds null e [] geram hashes DIFERENTES (semântica preservada)',
    $hNull !== $hEmpty->filterHash());

// ── 5. fromRequest: ausência de seller = null; presença = lista ───────────
$fromNone = CommercialFilterSet::fromRequest([], '2026-07-01', '2026-07-31');
$check('fromRequest sem seller ⇒ sellerIds null (todos autorizados)', $fromNone->sellerIds === null);
$fromList = CommercialFilterSet::fromRequest(['seller' => ['A', 'B'], 'channel' => 'varejo'], '2026-07-01', '2026-07-31');
$check('fromRequest com seller[] ⇒ lista; channel string ⇒ [varejo]',
    $fromList->sellerIds === ['A', 'B'] && $fromList->channels === ['varejo']);

$passed = count(array_filter($checks, static fn (array $r): bool => $r['ok']));
$result = ['ok' => $passed === count($checks), 'total' => count($checks),
           'passed' => $passed, 'failed' => count($checks) - $passed, 'checks' => $checks];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
exit($result['ok'] ? 0 : 1);

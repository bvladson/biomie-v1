<?php

declare(strict_types=1);

/**
 * CommercialRequestScope — a cola SQL que faz o filtro comercial PROPAGAR.
 *
 * O cérebro (CommercialFilterResolver) já é provado em run_commercial_filter_
 * intelligence (32/32). Aqui provamos a DERIVAÇÃO para SQL que cada superfície usa:
 *   · channelRefineSql — só estreita quando há canal específico; nunca sem coluna.
 *   · vendorScopeSeed / forceEmptySellers — pref agindo como filtro (null≠[]).
 *   · meta — filter_hash + channel_scope alinhados ao Dashboard.
 *
 * Constrói o helper via reflexão (o construtor é privado; fromRequest depende de
 * TenantContext/HTTP). Uma tabela real efêmera dá lastro ao columnExists.
 *
 *   php tests/run_commercial_request_scope.php
 */

require_once __DIR__ . '/TestSupport.php';

use BIomie\Commercial\CommercialRequestScope;
use BIomie\Commercial\ResolvedCommercialFilter;

$checks = [];
$check = static function (string $name, bool $ok, mixed $detail = null) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

$pdo = test_pdo();

// Tabela REAL efêmera (não TEMPORARY) para o columnExists (information_schema).
$pdo->exec('DROP TABLE IF EXISTS zz_cfi_scope_probe');
$pdo->exec('CREATE TABLE zz_cfi_scope_probe (id INT, business_channel VARCHAR(16)) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE IF NOT EXISTS zz_cfi_no_channel (id INT) ENGINE=InnoDB');

/** Constrói o helper via reflexão com um ResolvedCommercialFilter sintético. */
$make = static function (ResolvedCommercialFilter $rf): CommercialRequestScope {
    $ref = new ReflectionClass(CommercialRequestScope::class);
    $obj = $ref->newInstanceWithoutConstructor();
    $ctor = $ref->getConstructor();
    $ctor->invoke($obj, 1, 42, 'mixed', $rf, $rf->requestedChannels !== null);

    return $obj;
};
$rf = static fn (array $o = []): ResolvedCommercialFilter => new ResolvedCommercialFilter(
    effectiveChannels: $o['eff'] ?? ['licitacao', 'varejo'],
    effectiveSellerIds: $o['sellers'] ?? null,
    requestedChannels: $o['reqCh'] ?? null,
    requestedSellerIds: $o['reqSe'] ?? null,
    allowedChannels: ['licitacao', 'varejo'],
    source: $o['source'] ?? 'default',
    channelsSanitized: $o['chSan'] ?? false,
    sellersSanitized: $o['seSan'] ?? false,
    droppedChannels: $o['dropCh'] ?? [],
    droppedSellers: $o['dropSe'] ?? [],
);

// ── 1. channelRefineSql ───────────────────────────────────────────────────────
$todas = $make($rf(['eff' => ['licitacao', 'varejo'], 'reqCh' => null]));
$check('todas (requested null) ⇒ sem refino (1=1)',
    $todas->channelRefineSql($pdo, 'zz_cfi_scope_probe', 'nf') === '1=1');
$check('todas ⇒ isChannelNarrowed() false', !$todas->isChannelNarrowed());

$soLic = $make($rf(['eff' => ['licitacao'], 'reqCh' => ['licitacao']]));
$check('estreita licitacao ⇒ nf.business_channel IN (\'licitacao\')',
    $soLic->channelRefineSql($pdo, 'zz_cfi_scope_probe', 'nf') === "nf.business_channel IN ('licitacao')",
    $soLic->channelRefineSql($pdo, 'zz_cfi_scope_probe', 'nf'));
$check('estreita ⇒ isChannelNarrowed() true', $soLic->isChannelNarrowed());

$check('🔴 sem coluna business_channel ⇒ NÃO inventa filtro (1=1)',
    $soLic->channelRefineSql($pdo, 'zz_cfi_no_channel', 'nf') === '1=1');

$vazio = $make($rf(['eff' => [], 'reqCh' => []]));
$check('🔴 canais efetivos vazios ⇒ 1=0 (zero linhas, nunca todos)',
    $vazio->channelRefineSql($pdo, 'zz_cfi_scope_probe', 'nf') === '1=0');

// injeção: valores fora do allowlist jamais entram no SQL (só varejo/licitacao)
$forjado = $make($rf(['eff' => ["licitacao') OR 1=1 --"], 'reqCh' => ["licitacao') OR 1=1 --"]]));
$check('🔴 canal forjado fora do allowlist ⇒ descartado ⇒ 1=0',
    $forjado->channelRefineSql($pdo, 'zz_cfi_scope_probe', 'nf') === '1=0');

// ── 2. vendedores: semeia a INTENÇÃO, nunca o escopo ─────────────────────────
// O escopo de visibilidade já é aplicado incondicionalmente pelo VendorScopeFilter.
// Semear o escopo criaria um "filtro fantasma" (ver run_commercial_filter_seed_contract).
$allSellers = $make($rf(['sellers' => null, 'reqSe' => null]));
$check('sellers null ⇒ seed [] (sem restrição) e forceEmpty false',
    $allSellers->vendorScopeSeed() === [] && !$allSellers->forceEmptySellers());

$noneSellers = $make($rf(['sellers' => [], 'reqSe' => []]));
$check('🔴 intenção [] ⇒ forceEmptySellers true (nenhum)', $noneSellers->forceEmptySellers());

$someSellers = $make($rf(['sellers' => ['S1', 'S2'], 'reqSe' => ['S1', 'S2']]));
$check('intenção [S1,S2] ⇒ seed [S1,S2], forceEmpty false',
    $someSellers->vendorScopeSeed() === ['S1', 'S2'] && !$someSellers->forceEmptySellers());

// REGRESSÃO: usuário restrito que NÃO filtrou nada. O escopo está preenchido
// (ele só enxerga S1,S2) mas a intenção é null ⇒ NADA pode ser semeado, senão o
// filtro por NOME de vendedor no endpoint seria silenciosamente descartado.
$scopeOnly = $make($rf(['sellers' => ['S1', 'S2'], 'reqSe' => null]));
$check('🔴 escopo preenchido + intenção null ⇒ seed [] (sem filtro fantasma)',
    $scopeOnly->vendorScopeSeed() === [], json_encode($scopeOnly->vendorScopeSeed()));
$check('🔴 escopo preenchido + intenção null ⇒ forceEmpty false (não zera a tela)',
    !$scopeOnly->forceEmptySellers());

// ── 3. meta (filter_hash + channel_scope + sanitização) ───────────────────────
$meta = $soLic->meta('2026-07-01', '2026-07-31');
$check('meta traz filter_hash não-vazio', is_string($meta['filter_hash']) && $meta['filter_hash'] !== '');
$check('meta.channel_scope.effective = [licitacao]',
    ($meta['channel_scope']['effective'] ?? null) === ['licitacao']);

$san = $make($rf(['sellers' => ['S1'], 'seSan' => true, 'dropSe' => ['S9'], 'source' => 'preference']))
    ->meta('2026-07-01', '2026-07-31');
$check('meta.sanitized true + sellers_dropped=[S9]',
    $san['sanitized'] === true && ($san['sanitization']['sellers_dropped'] ?? null) === ['S9']);

$pdo->exec('DROP TABLE IF EXISTS zz_cfi_scope_probe');
$pdo->exec('DROP TABLE IF EXISTS zz_cfi_no_channel');

$passed = count(array_filter($checks, static fn (array $r): bool => $r['ok']));
$result = ['ok' => $passed === count($checks), 'total' => count($checks),
           'passed' => $passed, 'failed' => count($checks) - $passed, 'checks' => $checks];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
exit($result['ok'] ? 0 : 1);

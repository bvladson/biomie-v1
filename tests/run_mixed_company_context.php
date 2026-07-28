<?php

declare(strict_types=1);

/**
 * CONTEXTO MIXED (Fase D, Incremento 1A) — backend, opção ainda oculta.
 *
 * Prova, pelo CAMINHO REAL (createCompanyContext → store → resolveFromRequest),
 * que "Todas as operações" (varejo+licitação numa empresa) funciona de ponta a
 * ponta, SEM regredir os contextos single-channel e SEM fallback silencioso.
 *
 * A propriedade é de SEGURANÇA multiempresa: mixed só existe com os DOIS canais;
 * remover um canal REVOGA o contexto; canal adulterado por single-channel ⇒ 403.
 *
 * Instancia o TenantContextResolver REAL com $mixedContextEnabled explícito (não
 * depende da flag de ambiente). Tabelas TEMPORARY sombreiam as reais.
 *
 *   php tests/run_mixed_company_context.php
 */

require_once __DIR__ . '/TestSupport.php';

use BIomie\Support\BiVisibilityScope;
use BIomie\Support\CompanyScopeNormalizer;
use BIomie\Support\TenantContext;
use BIomie\Support\TenantContextResolver;
use BIomie\Support\TenantContextUnavailableException;

$checks = [];
$check = static function (string $name, bool $ok, mixed $detail = null) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};
/** Captura o reason code de uma recusa. */
$reason = static function (callable $fn): string {
    try { $fn(); return '(não lançou)'; }
    catch (TenantContextUnavailableException $e) { return $e->getReasonCode(); }
};

$pdo = test_pdo();

// ── Schema mínimo (espelha as tabelas reais que o resolver toca) ───────────
$pdo->exec('CREATE TEMPORARY TABLE biomie_users (
    id INT UNSIGNED PRIMARY KEY, role VARCHAR(20), is_active TINYINT(1) DEFAULT 1, permissions_version INT DEFAULT 1
) ENGINE=InnoDB');
$pdo->exec("CREATE TEMPORARY TABLE biomie_companies (
    id TINYINT UNSIGNED PRIMARY KEY, slug VARCHAR(32), nome_fantasia VARCHAR(80), canal_padrao VARCHAR(20) DEFAULT 'varejo', ativo TINYINT(1) DEFAULT 1
) ENGINE=InnoDB");
$pdo->exec('CREATE TEMPORARY TABLE biomie_user_companies (
    user_id INT UNSIGNED, company_id TINYINT UNSIGNED, can_varejo TINYINT(1) DEFAULT 0, can_licitacao TINYINT(1) DEFAULT 0,
    is_default TINYINT(1) DEFAULT 0, role_override VARCHAR(20) NULL, default_canal VARCHAR(20) NULL, PRIMARY KEY (user_id, company_id)
) ENGINE=InnoDB');
$pdo->exec("CREATE TEMPORARY TABLE biomie_user_contexts (
    context_id CHAR(32) PRIMARY KEY, user_id INT UNSIGNED, context_type VARCHAR(16), company_id TINYINT UNSIGNED NULL,
    allowed_company_ids_json LONGTEXT, company_scope_map_json LONGTEXT, business_scope ENUM('varejo','licitacao','mixed'),
    role_name VARCHAR(20), seller_scope_hash CHAR(64), permissions_version INT,
    created_at DATETIME, expires_at DATETIME, last_used_at DATETIME NULL, revoked_at DATETIME NULL
) ENGINE=InnoDB");
$pdo->exec('CREATE TEMPORARY TABLE biomie_vendedores_omie (id INT AUTO_INCREMENT PRIMARY KEY, company_id TINYINT UNSIGNED, codigo_omie VARCHAR(32), nome VARCHAR(120), ativo TINYINT(1) DEFAULT 1) ENGINE=InnoDB');
$pdo->exec("CREATE TEMPORARY TABLE biomie_user_seller_scope (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id TINYINT UNSIGNED, user_id INT UNSIGNED, visibility_mode ENUM('all','own','selected') DEFAULT 'all', created_by INT NULL, updated_by INT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq (company_id,user_id)) ENGINE=InnoDB");
$pdo->exec('CREATE TEMPORARY TABLE biomie_user_visible_sellers (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id TINYINT UNSIGNED, user_id INT UNSIGNED, seller_id VARCHAR(32), created_by INT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq (company_id,user_id,seller_id)) ENGINE=InnoDB');
$pdo->exec('CREATE TEMPORARY TABLE biomie_quote_seller_binding (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT, company_id BIGINT, seller_id VARCHAR(32), created_by BIGINT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq (company_id,seller_id)) ENGINE=InnoDB');

// Empresa 1. U_BOTH tem os 2 canais; U_VAR só varejo.
$pdo->exec("INSERT INTO biomie_companies VALUES (1,'MAG_FILIAL','Mag Filial','varejo',1)");
$pdo->exec('INSERT INTO biomie_users VALUES (10,\'admin\',1,1),(11,\'viewer\',1,1)');
$pdo->exec('INSERT INTO biomie_user_companies (user_id,company_id,can_varejo,can_licitacao,default_canal) VALUES (10,1,1,1,\'varejo\'),(11,1,1,0,\'varejo\')');
$U_BOTH = 10; $U_VAR = 11;

// ── 1. NORMALIZAÇÃO — a matriz obrigatória ──────────────────────────────────
$both = ['licitacao', 'varejo']; $onlyV = ['varejo']; $onlyL = ['licitacao'];
$check('varejo+varejo ⇒ varejo', CompanyScopeNormalizer::normalize('varejo', $both) === 'varejo');
$check('varejo autorizado, pede licitacao ⇒ 403',
    $reason(fn () => CompanyScopeNormalizer::normalize('licitacao', $onlyV)) === 'context_business_scope_forbidden');
$check('🔴 só-varejo pede mixed ⇒ 403 (não tem os 2)',
    $reason(fn () => CompanyScopeNormalizer::normalize('mixed', $onlyV)) === 'context_business_scope_forbidden');
$check('só-licitacao pede varejo ⇒ 403',
    $reason(fn () => CompanyScopeNormalizer::normalize('varejo', $onlyL)) === 'context_business_scope_forbidden');
$check('both pede mixed (flag ON) ⇒ mixed', CompanyScopeNormalizer::normalize('mixed', $both, '', true) === 'mixed');
$check('both pede varejo ⇒ varejo', CompanyScopeNormalizer::normalize('varejo', $both) === 'varejo');
$check('nenhum canal + qualquer ⇒ 403',
    $reason(fn () => CompanyScopeNormalizer::normalize('varejo', [])) === 'context_business_scope_forbidden');
$check('escopo desconhecido ⇒ invalid',
    $reason(fn () => CompanyScopeNormalizer::normalize('consolidado', $both)) === 'context_business_scope_invalid');
$check('🔴 mixed com FLAG OFF ⇒ context_mixed_disabled (não degrada)',
    $reason(fn () => CompanyScopeNormalizer::normalize('mixed', $both, '', false)) === 'context_mixed_disabled');
$check('effectiveChannels(mixed) = [licitacao,varejo]', CompanyScopeNormalizer::effectiveChannels('mixed') === $both);
$check('effectiveChannels(varejo) = [varejo]', CompanyScopeNormalizer::effectiveChannels('varejo') === ['varejo']);

// ── 2. ROUND-TRIP REAL: create mixed → store → resolve mixed ────────────────
$Ron = new TenantContextResolver($pdo, true);   // flag ON
$ctx = $Ron->createCompanyContext($U_BOTH, 1, 'mixed');
$check('CREATE mixed OK · business_scope=mixed', $ctx->businessScope === 'mixed');
$check('STORE mixed · scopeMap = [1 => [licitacao,varejo]]', $ctx->allowedBusinessScopes === [1 => $both], json_encode($ctx->allowedBusinessScopes));

// Persistido no banco?
$stored = $pdo->query("SELECT business_scope, company_scope_map_json FROM biomie_user_contexts WHERE context_id='" . $ctx->contextId . "'")->fetch(PDO::FETCH_ASSOC);
$check('persistido com business_scope=mixed', $stored['business_scope'] === 'mixed');

// Resolve pelo caminho real (findOwned + resolveStoredCompany).
$_SESSION['biomie_auth']['user_id'] = $U_BOTH;
$_GET['ctx'] = $ctx->contextId;
$resolved = $Ron->resolveFromRequest($U_BOTH);
$check('🔴 RESOLVE mixed OK · company_id=1', $resolved->companyId === 1);
$check('RESOLVE · business_scope=mixed', $resolved->businessScope === 'mixed');
$check('RESOLVE · 2 canais efetivos', $resolved->allowedBusinessScopes === [1 => $both]);

// ── 3. REGRESSÃO: single-channel intacto ────────────────────────────────────
$ctxV = $Ron->createCompanyContext($U_VAR, 1, 'varejo');
$_GET['ctx'] = $ctxV->contextId; $_SESSION['biomie_auth']['user_id'] = $U_VAR;
$rv = $Ron->resolveFromRequest($U_VAR);
$check('varejo continua criando e resolvendo varejo',
    $rv->businessScope === 'varejo' && $rv->allowedBusinessScopes === [1 => ['varejo']]);
// Contexto varejo criado ANTES do mixed (com flag OFF) continua válido.
$Roff = new TenantContextResolver($pdo, false);
$ctxV2 = $Roff->createCompanyContext($U_VAR, 1, 'varejo');   // flag OFF não bloqueia single
$_GET['ctx'] = $ctxV2->contextId;
$check('🔴 flag OFF ainda cria/valida varejo (single-channel intacto)',
    $Roff->resolveFromRequest($U_VAR)->businessScope === 'varejo');

// ── 4. SEGURANÇA: single-channel não cria mixed; canal adulterado ⇒ 403 ─────
$check('🔴 só-varejo pede mixed no create ⇒ 403',
    $reason(fn () => $Ron->createCompanyContext($U_VAR, 1, 'mixed')) === 'context_business_scope_forbidden');
$check('🔴 flag OFF: both pede mixed ⇒ context_mixed_disabled',
    $reason(fn () => $Roff->createCompanyContext($U_BOTH, 1, 'mixed')) === 'context_mixed_disabled');

// ── 5. REVOGAÇÃO DE CANAL: mixo válido, remove licitação, resolve ⇒ rejeita ──
$ctxM = $Ron->createCompanyContext($U_BOTH, 1, 'mixed');
$pdo->exec('UPDATE biomie_user_companies SET can_licitacao = 0 WHERE user_id = ' . $U_BOTH . ' AND company_id = 1');
$_GET['ctx'] = $ctxM->contextId; $_SESSION['biomie_auth']['user_id'] = $U_BOTH;
$rej = $reason(fn () => $Ron->resolveFromRequest($U_BOTH));
$check('🔴 canal removido ⇒ contexto mixed REJEITADO (channel_scope_changed)',
    $rej === 'context_channel_scope_changed', $rej);
$wasRevoked = $pdo->query("SELECT revoked_at FROM biomie_user_contexts WHERE context_id='" . $ctxM->contextId . "'")->fetchColumn();
$check('🔴 e o contexto foi REVOGADO no banco', $wasRevoked !== null && $wasRevoked !== '');
// Restaura para não afetar asserts finais.
$pdo->exec('UPDATE biomie_user_companies SET can_licitacao = 1 WHERE user_id = ' . $U_BOTH . ' AND company_id = 1');

// ── 6. DADOS: mixed = varejo + licitação (sem dupla contagem) ───────────────
// Prova que o ReadModel de NF sob contexto mixed soma os dois canais, com a
// regra do CommercialNfUniverse (licitação preservada, remessa/varejo-invisível
// fora), via BiVisibilityScope::getCondition — o caminho real do dashboard.
$pdo->exec('CREATE TEMPORARY TABLE biomie_notas_fiscais_canonicas (
    id INT AUTO_INCREMENT PRIMARY KEY, company_id TINYINT UNSIGNED, business_channel VARCHAR(20),
    is_biomie_commercial_visible TINYINT(1) NULL, valor_total DECIMAL(15,2)
) ENGINE=InnoDB');
$pdo->exec("INSERT INTO biomie_notas_fiscais_canonicas (company_id,business_channel,is_biomie_commercial_visible,valor_total) VALUES
    (1,'varejo',1,100.00),      -- varejo visível  → conta
    (1,'varejo',1,60.00),       -- varejo visível  → conta
    (1,'varejo',0,999.00),      -- remessa (invisível) → NÃO conta
    (1,'licitacao',0,51000.00), -- licitação (visible=0 mas preservada) → conta
    (1,'licitacao',1,200.00)    -- licitação → conta");

$Rmix = new TenantContextResolver($pdo, true);
$ctxData = $Rmix->createCompanyContext($U_BOTH, 1, 'mixed');
$_GET['ctx'] = $ctxData->contextId; $_SESSION['biomie_auth']['user_id'] = $U_BOTH;
TenantContext::boot($pdo);

$sum = static function (PDO $pdo, string $where): float {
    return (float) $pdo->query("SELECT COALESCE(SUM(valor_total),0) FROM biomie_notas_fiscais_canonicas nf WHERE {$where}")->fetchColumn();
};
$mixedCond = BiVisibilityScope::getCondition($pdo, 'biomie_notas_fiscais_canonicas', 'nf');
$mixedTotal = $sum($pdo, $mixedCond);
$varejoTotal = $sum($pdo, "nf.business_channel='varejo' AND COALESCE(nf.is_biomie_commercial_visible,1)=1");
$licitTotal  = $sum($pdo, "nf.business_channel='licitacao'");

$check('mixed inclui os 2 canais (varejo visível + licitação)',
    abs($mixedTotal - 51360.00) < 0.01, "mixed={$mixedTotal}");
$check('🔴 mixed = varejo + licitação, SEM dupla contagem',
    abs($mixedTotal - ($varejoTotal + $licitTotal)) < 0.01,
    "mixed={$mixedTotal} varejo={$varejoTotal} licit={$licitTotal}");
$check('🔴 remessa (varejo invisível) fora do total', abs($mixedTotal - 51360.00) < 0.01 && $mixedTotal < 52000.00);

unset($_GET['ctx'], $_SESSION['biomie_auth']);

$passed = count(array_filter($checks, static fn (array $r): bool => $r['ok']));
$result = ['ok' => $passed === count($checks), 'total' => count($checks),
           'passed' => $passed, 'failed' => count($checks) - $passed, 'checks' => $checks];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
exit($result['ok'] ? 0 : 1);

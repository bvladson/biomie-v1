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
    (1,'licitacao',1,200.00),   -- licitação → conta
    (1,'outros',0,330180.00)    -- intra-grupo (caso NF 26) → NÃO conta");

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
$check('🔴 intra-grupo (outros) fora do total', $mixedTotal < 330180.00, "mixed={$mixedTotal}");

// ── 7. PADRÃO "Todas as operações" (29/09/2026) ─────────────────────────────
// Sem canal pedido, quem tem os dois canais abre em mixed — licitação entra nos
// números e varejo/licitação vira filtro. Caso Uni Design: o único contexto era
// "Licitação" e o painel dava R$ 0,00 com R$ 46.710,95 de varejo faturado.
$check('🔴 both sem canal pedido (flag ON) ⇒ mixed',
    $Ron->createCompanyContext($U_BOTH, 1, null)->businessScope === 'mixed');
$check('só-varejo sem canal pedido ⇒ varejo',
    $Ron->createCompanyContext($U_VAR, 1, null)->businessScope === 'varejo');
$check('flag OFF: both sem canal pedido ⇒ default_canal (varejo)',
    $Roff->createCompanyContext($U_BOTH, 1, null)->businessScope === 'varejo');
$check('both pedindo canal explícito continua respeitado',
    $Ron->createCompanyContext($U_BOTH, 1, 'licitacao')->businessScope === 'licitacao');

// ── 8. WORKER em mixed: job enfileirado de "Todas as operações" ─────────────
// JobRunner repassa payload canal = TenantContext::canal() = 'mixed'. Antes o
// bootForCompany recusava e derrubava o job.
$bootErr = null;
try {
    TenantContext::bootForCompany($pdo, 1, 'mixed');
} catch (\Throwable $e) {
    $bootErr = $e->getMessage();
}
$check('🔴 bootForCompany aceita mixed', $bootErr === null, $bootErr);
$check('bootForCompany(mixed) ⇒ 2 canais efetivos',
    TenantContext::isMixed() && TenantContext::allowedBusinessScopes() === [1 => $both],
    json_encode(TenantContext::allowedBusinessScopes()));
$workerTotal = $sum($pdo, BiVisibilityScope::getCondition($pdo, 'biomie_notas_fiscais_canonicas', 'nf'));
$check('worker em mixed lê o mesmo universo do painel', abs($workerTotal - $mixedTotal) < 0.01, "worker={$workerTotal}");
$bootBad = null;
try {
    TenantContext::bootForCompany($pdo, 1, 'consolidado');
} catch (\InvalidArgumentException $e) {
    $bootBad = 'recusado';
}
$check('bootForCompany continua recusando canal inválido', $bootBad === 'recusado');

// ── 9. METAS da empresa em mixed = soma dos canais ──────────────────────────
$pdo->exec('CREATE TEMPORARY TABLE biomie_company_targets (
    id INT AUTO_INCREMENT PRIMARY KEY, company_id TINYINT UNSIGNED, canal VARCHAR(20),
    competencia CHAR(7), meta_valor DECIMAL(15,2), ativo TINYINT(1) DEFAULT 1
) ENGINE=InnoDB');
$pdo->exec("INSERT INTO biomie_company_targets (company_id,canal,competencia,meta_valor,ativo) VALUES
    (1,'varejo','2026-09',600000.00,1),
    (1,'licitacao','2026-09',400000.00,1),
    (1,'licitacao','2026-08',50000.00,0)");
$logger = new \BIomie\Logger(sys_get_temp_dir() . '/biomie_mixed_test.log');
$metaMixed = (new \BIomie\TargetsRepository($pdo, $logger, tenantCompanyId: 1, tenantChannel: 'mixed'))
    ->getCompanyTargetsForMonths(['2026-09', '2026-08']);
$metaVarejo = (new \BIomie\TargetsRepository($pdo, $logger, tenantCompanyId: 1, tenantChannel: 'varejo'))
    ->getCompanyTargetsForMonths(['2026-09']);
$metaLicit = (new \BIomie\TargetsRepository($pdo, $logger, tenantCompanyId: 1, tenantChannel: 'licitacao'))
    ->getCompanyTargetsForMonths(['2026-09']);
$check('🔴 meta mixed = varejo + licitação', abs(($metaMixed['2026-09'] ?? 0) - 1000000.00) < 0.01, json_encode($metaMixed));
$check('meta inativa não entra', !isset($metaMixed['2026-08']), json_encode($metaMixed));
$check('meta varejo isolada', abs(($metaVarejo['2026-09'] ?? 0) - 600000.00) < 0.01, json_encode($metaVarejo));
$check('meta licitação isolada', abs(($metaLicit['2026-09'] ?? 0) - 400000.00) < 0.01, json_encode($metaLicit));

// ── 10. REGRESSÃO UNI DESIGN / SETEMBRO 2026 ────────────────────────────────
// O bug que originou a mudança: 33 NFs autorizadas, todas varejo, R$ 46.710,95
// (conferido ao centavo com o relatório Omie "Faturamento por Mês/Ano"). No
// contexto "Licitação" o painel mostrava R$ 0,00. Composição real por vendedor:
// ROBERTO SIQ. 2 NFs = R$ 2.938,00; CAIO - REV 31 NFs = R$ 43.772,95.
// Os predicados são os MESMOS do painel: BiVisibilityScope (universo do
// contexto), refino de canal (business_channel IN ...) e VendorScopeFilter.
// A seção 8 trocou o contexto para o de worker; volta ao contexto mixed do usuário.
$_GET['ctx'] = $ctxData->contextId; $_SESSION['biomie_auth']['user_id'] = $U_BOTH;
TenantContext::boot($pdo);
$pdo->exec('TRUNCATE TABLE biomie_notas_fiscais_canonicas');
$pdo->exec('ALTER TABLE biomie_notas_fiscais_canonicas ADD COLUMN codigo_vendedor_omie VARCHAR(32) NULL');
$ins = $pdo->prepare('INSERT INTO biomie_notas_fiscais_canonicas
    (company_id, business_channel, is_biomie_commercial_visible, valor_total, codigo_vendedor_omie)
    VALUES (1, \'varejo\', 1, :v, :s)');
$ROBERTO = '10120611147'; $CAIO = '10128336497';
$ins->execute(['v' => 1469.00, 's' => $ROBERTO]);
$ins->execute(['v' => 1469.00, 's' => $ROBERTO]);
for ($i = 0; $i < 30; $i++) {
    $ins->execute(['v' => 1400.00, 's' => $CAIO]);
}
$ins->execute(['v' => 1772.95, 's' => $CAIO]);

$universo = BiVisibilityScope::getCondition($pdo, 'biomie_notas_fiscais_canonicas', 'nf');
$regTotal = static function (PDO $pdo, string $universo, string $canal = '1=1', array $sellers = []) use ($U_BOTH): array {
    $params = [];
    $seller = \BIomie\Support\VendorScopeFilter::conditionForContext($pdo, $U_BOTH, [1], $sellers, 'nf', $params);
    $stmt = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(nf.valor_total),0) AS v
        FROM biomie_notas_fiscais_canonicas nf WHERE {$universo} AND {$canal} AND {$seller}");
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return ['n' => (int) $row['n'], 'v' => round((float) $row['v'], 2)];
};
$todas = $regTotal($pdo, $universo);
$varejo = $regTotal($pdo, $universo, "nf.business_channel IN ('varejo')");
$licit = $regTotal($pdo, $universo, "nf.business_channel IN ('licitacao')");
$roberto = $regTotal($pdo, $universo, '1=1', [$ROBERTO]);
$caio = $regTotal($pdo, $universo, '1=1', [$CAIO]);
$check('🔴 UNI DESIGN set/26 · Todas as operações = R$ 46.710,95 (33 NFs)',
    $todas['v'] === 46710.95 && $todas['n'] === 33, json_encode($todas));
$check('🔴 UNI DESIGN set/26 · Varejo = R$ 46.710,95', $varejo['v'] === 46710.95, json_encode($varejo));
$check('🔴 UNI DESIGN set/26 · Licitação = R$ 0,00', $licit['v'] === 0.0 && $licit['n'] === 0, json_encode($licit));
$check('UNI DESIGN set/26 · ROBERTO SIQ. = R$ 2.938,00 (2 NFs)',
    $roberto['v'] === 2938.00 && $roberto['n'] === 2, json_encode($roberto));
$check('UNI DESIGN set/26 · CAIO - REV = R$ 43.772,95 (31 NFs)',
    $caio['v'] === 43772.95 && $caio['n'] === 31, json_encode($caio));
$check('🔴 UNI DESIGN set/26 · soma dos vendedores = total da empresa',
    round($roberto['v'] + $caio['v'], 2) === $todas['v'], json_encode([$roberto['v'], $caio['v'], $todas['v']]));
$check('🔴 UNI DESIGN set/26 · Varejo + Licitação = Todas',
    round($varejo['v'] + $licit['v'], 2) === $todas['v']);

unset($_GET['ctx'], $_SESSION['biomie_auth']);

$passed = count(array_filter($checks, static fn (array $r): bool => $r['ok']));
$result = ['ok' => $passed === count($checks), 'total' => count($checks),
           'passed' => $passed, 'failed' => count($checks) - $passed, 'checks' => $checks];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
exit($result['ok'] ? 0 : 1);

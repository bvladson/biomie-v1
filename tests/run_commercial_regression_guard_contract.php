<?php

declare(strict_types=1);

/**
 * PORTÃO DE DEPLOY — anti-regressão do incidente de 29/09/2026.
 *
 * Roda no remote_deploy ANTES da troca do symlink (sem banco): se qualquer
 * proteção abaixo sumir do código, o deploy aborta e a produção fica na versão
 * anterior. Cada linha é uma causa do incidente:
 *
 *   Uni Design R$ 0,00  — contexto de um canal só escondia a venda do outro;
 *   MAG Filial R$ 0,00  — exclusão dura descartava a licitação antes de gravar;
 *   pedidos x NFs       — pedido herdava o canal do contexto do sync;
 *   257 NFs             — nunca passaram pelas regras de canal.
 *
 * Os testes com banco (run_commercial_integrity_guard, run_licitacao_*,
 * run_seller_assignment, run_mixed_company_context) provam o comportamento;
 * este contrato garante que as peças continuam no lugar.
 *
 *   php tests/run_commercial_regression_guard_contract.php
 */

$root = dirname(__DIR__);
$read = static fn (string $rel): string => is_file($root . '/' . $rel) ? (string) file_get_contents($root . '/' . $rel) : '';

$checks = [];
$check = static function (string $name, bool $ok) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok];
};

// ── Exclusão dura não descarta licitação ────────────────────────────────────
$check('🔴 ferramenta de remoção de licitação não existe', !is_file($root . '/tools/dev/remove_licitacao_data.php'));
$policy = $read('app/Support/HardDataExclusionPolicy.php');
$check('🔴 HardDataExclusionPolicy ignora exclusão pelo vendedor Licitação (cadastro)',
    str_contains($policy, 'function licitacaoSellerCodes') && str_contains($policy, "unset(\$rules['vendor_code:' . \$code])"));
$write = $read('app/Canonical/CanonicalWriteRepository.php');
$check('🔴 isHardExcluded ignora o código quando o vendedor é "Licitação"',
    (bool) preg_match('/function isHardExcluded.*?LicitacaoSeller::isLicitacaoName\(\$vendorName\)\s*\?\s*null/s', $write));

// ── Licitação dentro dos números ────────────────────────────────────────────
$check('🔴 "Todas as operações" ligado por padrão',
    str_contains($read('config/env.php'), "env('BIOMIE_MIXED_COMPANY_CONTEXT_ENABLED', '1'"));
$check('🔴 contexto sem canal pedido abre em "Todas" quando há os dois canais',
    str_contains($read('app/Support/TenantContextResolver.php'), "count(\$allowedScopes) === 2"));
$check('universo comercial inclui licitação',
    str_contains($read('app/Support/CommercialNfUniverse.php'), "business_channel = 'licitacao'"));
$check('BiVisibilityScope em "Todas" soma licitação e varejo',
    str_contains($read('app/Support/BiVisibilityScope.php'), "business_channel = 'licitacao' OR ("));
$check('Plano de Voo não descarta vendedor de licitação',
    !preg_match('/CHANNEL_LICITACAO\)\s*\{\s*continue;/', $read('app/FlightPlanV2Service.php')));
$check('Geografia/CNAE monta varejo e licitação',
    str_contains($read('app/Modules/Geo/Infrastructure/GeoCnaeMartRepository.php'), "IN ('varejo', 'licitacao')"));
$check('card de vendas não promete "Licitação excluídas"',
    !str_contains($read('views/index.html'), 'Licitação excluídas'));

// ── Canal pela regra de negócio, nunca pelo contexto ────────────────────────
$check('🔴 canal de pedidos/títulos não herda o canal do contexto do sync',
    !preg_match('/function resolveBusinessChannel.*?\$this->businessChannel/s', $write));
$check('pedido passa pelo mesmo classificador da NF',
    (bool) preg_match('/function upsertOrder.*?resolveInvoiceVisibility\(\$order\)/s', $write));
$check('regra do vendedor Licitação por igualdade (não substring)',
    str_contains($read('app/Support/LicitacaoSeller.php'), 'self::normalize($name) === self::NORMALIZED_NAME'));

// ── Vigilância e portão continuam ligados ───────────────────────────────────
$check('check "Integridade Comercial" registrado no painel de saúde',
    str_contains($read('app/SystemHealth/SystemHealthService.php'), 'new CommercialIntegrityHealthCheck('));
$check('sentinela de integridade comercial agendada no cron',
    str_contains($read('tools/deploy/vps/biomie.cron'), 'cron/hostinger/commercial_integrity.sh'));
$deploy = $read('tools/deploy/vps/remote_deploy.sh');
$check('🔴 este contrato roda no deploy antes da troca de versão',
    str_contains($deploy, 'run_commercial_regression_guard_contract.php'));

$passed = count(array_filter($checks, static fn (array $r): bool => $r['ok']));
$result = ['ok' => $passed === count($checks), 'total' => count($checks),
           'passed' => $passed, 'failed' => count($checks) - $passed, 'checks' => $checks];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
exit($result['ok'] ? 0 : 1);

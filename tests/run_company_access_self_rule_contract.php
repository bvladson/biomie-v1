<?php

declare(strict_types=1);

/**
 * CONTRATO — ajuste do PRÓPRIO acesso em api/company_access.php (29/09/2026).
 *
 * O bloqueio total deixava o único administrador sem como corrigir o próprio
 * escopo: preso em "Uni Design · Licitação" enquanto as vendas eram varejo.
 * Regra nova: o admin ajusta só os CANAIS de empresas que já acessa, com
 * confirmação e log próprio. Anti-escalada preservada: não remove o próprio
 * acesso, não se dá empresa nova, não muda o próprio papel nem a empresa padrão.
 *
 * Verificação estática (o endpoint exige sessão HTTP), no mesmo estilo dos
 * checks de endpoint do biomie_release_guard.
 *
 *   php tests/run_company_access_self_rule_contract.php
 */

$api = (string) file_get_contents(dirname(__DIR__) . '/api/company_access.php');
$tab = (string) file_get_contents(dirname(__DIR__) . '/views/admin/tabs/access.php');

$checks = [];
$check = static function (string $name, bool $ok) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok];
};

$check('exige users.manage', str_contains($api, "Auth::requireCapability('users.manage', true)"));
$check('🔴 próprio acesso: só grant (revoke do próprio acesso recusado)',
    str_contains($api, "if (\$action !== 'grant')") && str_contains($api, 'não pode remover o seu próprio acesso'));
$check('🔴 próprio acesso: só empresa que já acessa (sem empresa nova)',
    str_contains($api, 'FROM biomie_user_companies WHERE user_id = :uid AND company_id = :cid')
    && str_contains($api, 'não pode se dar acesso a uma empresa nova'));
$check('🔴 próprio acesso: confirmação explícita (confirm_self)',
    str_contains($api, "empty(\$body['confirm_self'])") && str_contains($api, 'self_confirmation_required'));
$check('🔴 próprio acesso: papel e empresa padrão preservados do vínculo atual',
    str_contains($api, "\$roleOverride = \$selfLink['role_override']") && str_contains($api, "\$isDefault = (int) \$selfLink['is_default']"));
$check('próprio acesso: log próprio com o antes',
    str_contains($api, "'company_access_self_channels'") && str_contains($api, "'antes' =>"));
$check('tela pede confirmação e envia confirm_self',
    str_contains($tab, 'window.confirm(') && str_contains($tab, 'confirm_self: self'));
$check('tela: papel e padrão continuam travados no próprio acesso',
    str_contains($tab, "['[data-acc-role]', '[data-acc-default]']") && str_contains($tab, 'el.disabled = self || !has'));

$passed = count(array_filter($checks, static fn (array $r): bool => $r['ok']));
$result = ['ok' => $passed === count($checks), 'total' => count($checks),
           'passed' => $passed, 'failed' => count($checks) - $passed, 'checks' => $checks];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
exit($result['ok'] ? 0 : 1);

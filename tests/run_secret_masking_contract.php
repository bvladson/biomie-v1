<?php

declare(strict_types=1);

/**
 * Contrato do mascaramento de segredos na rota (auditoria 21/09/2026).
 *
 * O token do webhook Omie é a credencial da própria URL e era gravado inteiro
 * em biomie_operational_spans.route_name (12.540 linhas) e no biomie.log.
 */

use BIomie\RequestContext;

require_once dirname(__DIR__) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

$checks = [];
$check = static function (string $id, bool $ok, mixed $actual = null) use (&$checks): void {
    $checks[] = ['id' => $id, 'ok' => $ok, 'actual' => $actual];
};

$token = str_repeat('a1b2', 15) . '7a3c';
$masked = RequestContext::maskSecrets('POST /biomie/webhooks/omie/mag_filial/' . $token);
$check('webhook_path_token_masked', $masked === 'POST /biomie/webhooks/omie/mag_filial/wh_****7a3c', $masked);
$check('webhook_token_never_survives', !str_contains($masked, substr($token, 0, 12)), $masked);

$query = RequestContext::maskSecrets('GET /api/omie_webhook.php?company_slug=expert&path_token=' . $token . '&x=1');
$check(
    'query_token_masked_other_params_kept',
    $query === 'GET /api/omie_webhook.php?company_slug=expert&path_token=wh_****7a3c&x=1',
    $query,
);

$cli = RequestContext::maskSecrets('php bin/company_webhooks.php --company=mag --token=' . $token);
$check('cli_token_flag_masked', str_ends_with($cli, '--token=wh_****7a3c'), $cli);

$check(
    'short_secret_keeps_no_tail',
    RequestContext::maskSecrets('GET /x?token=abc123') === 'GET /x?token=wh_****',
);
$check(
    'plain_routes_untouched',
    RequestContext::maskSecrets('GET /api/vendors.php?page=2') === 'GET /api/vendors.php?page=2',
);

$webhook = (string) file_get_contents(dirname(__DIR__) . '/api/omie_webhook.php');
$check(
    'webhook_endpoint_uses_same_rule',
    str_contains($webhook, 'RequestContext::maskSecrets($uri)'),
);

$failed = array_values(array_filter($checks, static fn (array $row): bool => !$row['ok']));
$result = [
    'ok' => $failed === [],
    'passed' => count($checks) - count($failed),
    'total' => count($checks),
    'checks' => $checks,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($result['ok'] ? 0 : 1);

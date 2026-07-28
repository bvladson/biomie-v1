<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Bootstrap.php';

use BIomie\Bootstrap;
use BIomie\Modules\Finance\Application\CommercialHealthSourceResolver;

Bootstrap::init();

$checks = [];
$check = static function (string $name, bool $ok, mixed $detail = null) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

$resolver = new CommercialHealthSourceResolver();
$canonical = ['ok' => true, 'available' => true, 'source' => 'canonical-finance'];
$cached = ['ok' => true, 'available' => true, 'source' => 'finance-cache-fallback'];
$unavailable = ['ok' => true, 'available' => false, 'source' => 'finance-unavailable'];

$liveCalls = 0;
$normal = $resolver->resolve(
    false,
    $canonical,
    $cached,
    $unavailable,
    static function () use (&$liveCalls): array {
        $liveCalls++;
        return ['source' => 'finance-live-widget'];
    },
);
$check('GET normal seleciona canonical', ($normal['source'] ?? '') === 'canonical-finance', $normal);
$check('GET normal nunca chama ERP live', $liveCalls === 0, $liveCalls);
$check('metadado registra canonical-first', ($normal['meta']['source_strategy'] ?? '') === 'canonical-first', $normal['meta'] ?? null);
$check('metadado registra live_attempted=false', ($normal['meta']['live_attempted'] ?? null) === false, $normal['meta'] ?? null);

$cachedOnly = $resolver->resolve(
    false,
    null,
    $cached,
    $unavailable,
    static function () use (&$liveCalls): array {
        $liveCalls++;
        return ['source' => 'finance-live-widget'];
    },
);
$check('sem canonical usa cache sem chamar ERP', ($cachedOnly['source'] ?? '') === 'finance-cache-fallback' && $liveCalls === 0, $cachedOnly);
$check('cache informa fallback canonical', ($cachedOnly['meta']['fallback_reason'] ?? '') === 'canonical_unavailable', $cachedOnly['meta'] ?? null);

$none = $resolver->resolve(false, null, null, $unavailable);
$check('sem canonical/cache retorna estado seguro', ($none['available'] ?? true) === false, $none);
$check('estado seguro nao tenta live', ($none['meta']['live_attempted'] ?? null) === false, $none['meta'] ?? null);

$forced = $resolver->resolve(
    true,
    $canonical,
    $cached,
    $unavailable,
    static function () use (&$liveCalls): array {
        $liveCalls++;
        return ['ok' => true, 'available' => true, 'source' => 'finance-live-widget'];
    },
);
$check('force=1 autoriza refresh live', ($forced['source'] ?? '') === 'finance-live-widget' && $liveCalls === 1, $forced);
$check('force=1 registra live_attempted=true', ($forced['meta']['live_attempted'] ?? null) === true, $forced['meta'] ?? null);

$redundant = $resolver->resolve(
    true,
    $canonical,
    $cached,
    $unavailable,
    static function (): array {
        throw new RuntimeException('Consumo redundante detectado (REDUNDANT).');
    },
);
$check('REDUNDANT cai para canonical', ($redundant['source'] ?? '') === 'canonical-finance', $redundant);
$check('REDUNDANT tem reason_code estavel', ($redundant['meta']['fallback_reason'] ?? '') === 'omie_redundant', $redundant['meta'] ?? null);
$check('fallback de force registra tentativa live', ($redundant['meta']['live_attempted'] ?? null) === true, $redundant['meta'] ?? null);

$check(
    'periodo vazio em tabela saudavel e canonical autoritativo',
    CommercialHealthSourceResolver::canonicalIsAuthoritative(['reason_code' => 'no_titles_in_period']),
);
$check(
    'tabela canonical ausente nao e autoritativa',
    !CommercialHealthSourceResolver::canonicalIsAuthoritative(['reason_code' => 'finance_table_missing']),
);
$check(
    'erro de query canonical nao e autoritativo',
    !CommercialHealthSourceResolver::canonicalIsAuthoritative(['reason_code' => 'query_error']),
);

$endpoint = file_get_contents(dirname(__DIR__) . '/api/commercial_health.php') ?: '';
$check(
    'endpoint entrega loader live apenas ao resolver',
    str_contains($endpoint, '$resolver->resolve(')
        && str_contains($endpoint, '$finance->getSnapshot($start, $end, true)')
        && !str_contains($endpoint, '$finance->getSnapshot($start, $end, $force)'),
);

$passed = count(array_filter($checks, static fn (array $row): bool => $row['ok']));
$result = [
    'ok' => $passed === count($checks),
    'total' => count($checks),
    'passed' => $passed,
    'failed' => count($checks) - $passed,
    'checks' => $checks,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
exit($result['ok'] ? 0 : 1);

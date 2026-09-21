<?php

declare(strict_types=1);

/** Contrato estático do wrapper noturno de enriquecimento financeiro. */

$root = dirname(__DIR__);
$path = $root . '/cron/hostinger/enrich_finance_titulos.sh';
$source = is_file($path) ? (string) file_get_contents($path) : '';

$checks = [];
$check = static function (string $id, bool $ok, mixed $actual = null) use (&$checks): void {
    $checks[] = ['id' => $id, 'ok' => $ok, 'actual' => $actual];
};

$check('wrapper_exists', $source !== '', $path);
$check(
    'uses_configurable_system_php',
    str_contains($source, 'BIOMIE_PHP_BIN:-/usr/bin/php')
        && !str_contains($source, 'PHP_BIN="/usr/local/bin/php"'),
);
$check(
    'fails_fast_when_php_is_missing',
    str_contains($source, '[ ! -x "$PHP_BIN" ]')
        && str_contains($source, 'exit 127'),
);
$check(
    'aggregates_company_failures',
    str_contains($source, 'FAILED=$((FAILED + 1))')
        && str_contains($source, '[ "$FAILED" -ne 0 ]'),
);
$check(
    'returns_nonzero_when_any_company_fails',
    preg_match('/if \[ "\$FAILED" -ne 0 \]; then.+?exit 1/s', $source) === 1,
);
$check(
    'quotes_php_binary',
    str_contains($source, '"$PHP_BIN" "$BASEDIR/bin/enrich_finance_titulos.php"'),
);
$check(
    'writes_to_rotated_cron_log',
    str_contains($source, 'storage/logs/cron')
        && str_contains($source, 'enrich_finance_titulos.log'),
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

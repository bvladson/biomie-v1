<?php

declare(strict_types=1);

require_once __DIR__ . '/TestSupport.php';

use BIomie\Http\RequestInput;

$input = new RequestInput([
    'name' => '  MAG  ',
    'id' => '12',
    'tags' => [' a ', '', 'b'],
    'ints' => ['1', 3],
    'enabled' => 'true',
    'date' => '2026-07-25',
]);
$checks = [
    'string' => $input->requiredString('name') === 'MAG',
    'positive_int' => $input->positiveInt('id') === 12,
    'string_list' => $input->stringList('tags') === ['a', 'b'],
    'int_list' => $input->intList('ints') === [1, 3],
    'boolean' => $input->boolean('enabled') === true,
    'date' => $input->date('date')?->format('Y-m-d') === '2026-07-25',
];

foreach ([
    ['invalid_date', new RequestInput(['date' => '2026-02-30']), static fn (RequestInput $v) => $v->date('date')],
    ['invalid_int', new RequestInput(['id' => 0]), static fn (RequestInput $v) => $v->positiveInt('id')],
    ['invalid_list', new RequestInput(['ints' => ['x']]), static fn (RequestInput $v) => $v->intList('ints')],
] as [$name, $value, $call]) {
    try {
        $call($value);
        $checks[$name] = false;
    } catch (InvalidArgumentException) {
        $checks[$name] = true;
    }
}

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
echo json_encode([
    'ok' => $failed === [],
    'total' => count($checks),
    'passed' => count($checks) - count($failed),
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failed === [] ? 0 : 1);


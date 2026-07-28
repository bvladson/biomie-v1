<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Support\ChannelClassifierService;

$reflection = new ReflectionClass(ChannelClassifierService::class);
/** @var ChannelClassifierService $classifier */
$classifier = $reflection->newInstanceWithoutConstructor();
$rules = $reflection->getProperty('rules');
$rules->setValue($classifier, [
    [
        'id' => 10,
        'rule_type' => 'manual',
        'match_value' => '123',
        'target_channel' => 'varejo',
        'confidence' => 'manual',
    ],
    [
        'id' => 20,
        'rule_type' => 'projeto_id_list',
        'match_value' => '000123, 456',
        'target_channel' => 'licitacao',
        'confidence' => 'alta',
    ],
    [
        'id' => 30,
        'rule_type' => 'default',
        'match_value' => '',
        'target_channel' => 'varejo',
        'confidence' => 'baixa',
    ],
]);
$reflection->getProperty('rulesLoaded')->setValue($classifier, true);

$checks = [];
$check = static function (string $id, bool $ok, mixed $actual) use (&$checks): void {
    $checks[] = ['id' => $id, 'ok' => $ok, 'actual' => $actual];
};

$normalized = $classifier->classify('', '', '', '123');
$check(
    'project_id_matches_zero_padded_rule',
    $normalized['channel'] === 'licitacao'
        && $normalized['confidence'] === 'alta'
        && $normalized['rule_id'] === 20,
    $normalized,
);

$listed = $classifier->classify('', '', '', '000456');
$check(
    'project_id_input_is_normalized',
    $listed['channel'] === 'licitacao' && $listed['rule_id'] === 20,
    $listed,
);

$fallback = $classifier->classify('', '', '', '999');
$check(
    'unknown_project_falls_back_to_default',
    $fallback['channel'] === 'varejo' && $fallback['rule_id'] === 30,
    $fallback,
);

$withoutProject = $classifier->classify('Vendedor comum', '', '', '');
$check(
    'empty_project_does_not_match_project_rule',
    $withoutProject['channel'] === 'varejo' && $withoutProject['rule_id'] === 30,
    $withoutProject,
);

$failed = array_values(array_filter($checks, static fn (array $row): bool => !$row['ok']));
echo json_encode([
    'ok' => $failed === [],
    'passed' => count($checks) - count($failed),
    'total' => count($checks),
    'checks' => $checks,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failed === [] ? 0 : 1);

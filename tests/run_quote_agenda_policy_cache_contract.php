<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\QuoteAgenda\QuoteAgendaReadService;

require dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(
    'CREATE TABLE biomie_quote_followup_policy (
        company_id INTEGER PRIMARY KEY,
        enabled INTEGER NOT NULL,
        stale_after_minutes INTEGER NOT NULL,
        cadence_days_json TEXT,
        cadence_model TEXT,
        weekend_policy TEXT,
        holiday_policy TEXT,
        critical_after_step INTEGER
    )'
);
$pdo->exec(
    'INSERT INTO biomie_quote_followup_policy (company_id, enabled, stale_after_minutes)
     VALUES (1, 1, 30), (2, 0, 180)'
);

$method = new ReflectionMethod(QuoteAgendaReadService::class, 'policyRow');
$first = $method->invoke(new QuoteAgendaReadService($pdo, 1));
$second = $method->invoke(new QuoteAgendaReadService($pdo, 2));
$firstAgain = $method->invoke(new QuoteAgendaReadService($pdo, 1));

$checks = [
    'empresa 1 resolve politica propria' => (int) ($first['company_id'] ?? 0) === 1
        && (int) ($first['stale_after_minutes'] ?? 0) === 30,
    'empresa 2 nao reutiliza cache da empresa 1' => (int) ($second['company_id'] ?? 0) === 2
        && (int) ($second['stale_after_minutes'] ?? 0) === 180,
    'cache da empresa 1 permanece estavel' => $firstAgain === $first,
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
echo json_encode([
    'ok' => $failed === [],
    'total' => count($checks),
    'passed' => count($checks) - count($failed),
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;

exit($failed === [] ? 0 : 1);

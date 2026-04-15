<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\ConsistencyHomologationService;

require_once dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

/**
 * @return array<string,mixed>
 */
function run_consistency_homologation_suite(): array
{
    /** @var list<array<string,mixed>> $cases */
    $cases = require __DIR__ . '/fixtures/consistency_homologation_cases.php';
    $service = new ConsistencyHomologationService();
    return $service->runSuite($cases);
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $result = run_consistency_homologation_suite();
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(($result['ok'] ?? false) ? 0 : 1);
}

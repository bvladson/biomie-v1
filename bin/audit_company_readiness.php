<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\CompanyReadinessAuditor;
use BIomie\Config;
use BIomie\Database;
use BIomie\Support\SecretMask;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

$options = getopt('', ['company:', 'pretty', 'fail-on:', 'output:']);
$company = isset($options['company']) ? trim((string) $options['company']) : null;
$pretty = isset($options['pretty']);
$failOn = strtolower(trim((string) ($options['fail-on'] ?? '')));
$output = trim((string) ($options['output'] ?? ''));

try {
    $config = new Config();
    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo = Database::connect($config, $connection);
    $result = (new CompanyReadinessAuditor($pdo))->audit($company);

    $json = json_encode(
        $result,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    );
    if ($json === false) {
        throw new RuntimeException('Falha ao serializar o diagnóstico.');
    }

    if ($output !== '') {
        $directory = dirname($output);
        if ($directory !== '.' && !is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
        if (@file_put_contents($output, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Não foi possível gravar o relatório em ' . $output);
        }
    }

    if ($pretty) {
        printCompanyReadinessSummary($result);
    } else {
        echo $json . PHP_EOL;
    }

    $statuses = array_map(
        static fn (array $row): string => (string) ($row['status'] ?? 'critical'),
        is_array($result['companies'] ?? null) ? $result['companies'] : [],
    );
    $shouldFail = ($failOn === 'critical' && in_array('critical', $statuses, true))
        || ($failOn === 'warning' && count(array_intersect(['warning', 'critical'], $statuses)) > 0);
    exit($shouldFail ? 1 : 0);
} catch (Throwable $exception) {
    fwrite(STDERR, SecretMask::sanitizeErrorMessage($exception->getMessage(), 500) . PHP_EOL);
    exit(2);
}

/**
 * @param array<string,mixed> $result
 */
function printCompanyReadinessSummary(array $result): void
{
    echo 'Diagnóstico de frescor e completude por empresa' . PHP_EOL;
    echo 'Banco: ' . (string) ($result['database_now'] ?? '-') . PHP_EOL . PHP_EOL;
    printf(
        "%-14s %-10s %-12s %-14s %12s\n",
        'EMPRESA',
        'STATUS',
        'FRESCOR',
        'COMPLETUDE',
        'COBERTURA',
    );
    echo str_repeat('-', 68) . PHP_EOL;

    foreach ((array) ($result['companies'] ?? []) as $company) {
        $freshness = is_array($company['freshness'] ?? null) ? $company['freshness'] : [];
        $completeness = is_array($company['completeness'] ?? null) ? $company['completeness'] : [];
        $coverage = $completeness['field_coverage_pct'] ?? null;
        printf(
            "%-14s %-10s %-12s %-14s %11s\n",
            (string) ($company['slug'] ?? ''),
            strtoupper((string) ($company['status'] ?? '')),
            strtoupper((string) ($freshness['status'] ?? '')),
            strtoupper((string) ($completeness['status'] ?? '')),
            $coverage !== null ? number_format((float) $coverage, 2, ',', '.') . '%' : '-',
        );
    }

    echo PHP_EOL;
    foreach ((array) ($result['companies'] ?? []) as $company) {
        $actions = is_array($company['recommended_actions'] ?? null)
            ? $company['recommended_actions']
            : [];
        if ($actions === []) {
            continue;
        }
        echo (string) ($company['slug'] ?? '') . ':' . PHP_EOL;
        foreach ($actions as $action) {
            echo '  - [' . strtoupper((string) ($action['priority'] ?? '')) . '] '
                . (string) ($action['reason'] ?? '');
            if (!empty($action['command'])) {
                echo ' | ' . (string) $action['command'];
            }
            echo PHP_EOL;
        }
    }
}

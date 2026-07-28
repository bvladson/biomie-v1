<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\CustomerDimensionMaterializationService;
use BIomie\Database;
use BIomie\Logger;
use BIomie\TableHealthService;
use BIomie\Support\TenantContext;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

try {
    $config = new Config();
    $logger = new Logger(Bootstrap::rootPath('storage/logs/biomie.log'));
    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo = Database::connect($config, $connection);
    bootProbeTenant($pdo, probeCompanySelector($argv ?? []));
    $service = new CustomerDimensionMaterializationService(
        $pdo,
        $logger,
        new TableHealthService($pdo, $logger),
    );

    $first = $service->run();
    $updatedAtAfterFirst = $pdo->query('SELECT MAX(updated_at) FROM biomie_clientes_dim')->fetchColumn();
    sleep(2);
    $second = $service->run();
    $updatedAtAfterSecond = $pdo->query('SELECT MAX(updated_at) FROM biomie_clientes_dim')->fetchColumn();

    $secondChanged = (int) ($second['inserted_count'] ?? 0) + (int) ($second['updated_count'] ?? 0);
    $payload = [
        'ok' => (bool) ($first['ok'] ?? false)
            && (bool) ($second['ok'] ?? false)
            && $secondChanged === 0
            && $updatedAtAfterFirst === $updatedAtAfterSecond,
        'connection' => $connection,
        'first' => materializationSummary($first),
        'second' => materializationSummary($second),
        'updated_at_after_first' => $updatedAtAfterFirst,
        'updated_at_after_second' => $updatedAtAfterSecond,
        'physical_idempotence' => $updatedAtAfterFirst === $updatedAtAfterSecond,
    ];

    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($payload['ok'] ? 0 : 1);
} catch (Throwable $exception) {
    echo json_encode([
        'ok' => false,
        'error' => $exception->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(2);
}

/**
 * @param list<string> $argv
 */
function probeCompanySelector(array $argv): ?string
{
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with((string) $arg, '--company=')) {
            return substr((string) $arg, 10);
        }
    }

    return null;
}

/**
 * @return array{id:int,slug:string,canal:string}
 */
function bootProbeTenant(PDO $pdo, ?string $selector): array
{
    $selector = strtoupper(trim((string) $selector));
    if ($selector !== '') {
        $stmt = $pdo->prepare(
            'SELECT id, slug, canal_padrao
               FROM biomie_companies
              WHERE ativo = 1
                AND (id = :id OR slug = :slug)
              LIMIT 1'
        );
        $stmt->execute([
            'id' => ctype_digit($selector) ? (int) $selector : 0,
            'slug' => $selector,
        ]);
    } else {
        $stmt = $pdo->query(
            'SELECT id, slug, canal_padrao
               FROM biomie_companies
              WHERE ativo = 1
              ORDER BY id
              LIMIT 1'
        );
    }

    $company = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($company)) {
        throw new RuntimeException('Nenhuma empresa ativa encontrada para inicializar o TenantContext.');
    }

    $canal = (string) ($company['canal_padrao'] ?: 'varejo');
    TenantContext::bootForCompany($pdo, (int) $company['id'], $canal);

    return [
        'id' => (int) $company['id'],
        'slug' => (string) $company['slug'],
        'canal' => $canal,
    ];
}

/**
 * @param array<string,mixed> $report
 * @return array<string,mixed>
 */
function materializationSummary(array $report): array
{
    return [
        'status' => $report['status'] ?? null,
        'inserted' => $report['inserted_count'] ?? null,
        'updated' => $report['updated_count'] ?? null,
        'unchanged' => $report['unchanged_count'] ?? null,
        'duration_ms' => $report['duration_ms'] ?? null,
        'performance' => $report['performance'] ?? null,
    ];
}

<?php

declare(strict_types=1);

putenv('BIOMIE_ALLOW_DEFAULT_TENANT=1');

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\DatasetFreshnessService;
use BIomie\Logger;
use BIomie\OperationalPayloadFingerprint;
use BIomie\Tests\TestDatabaseGuard;

require_once dirname(__DIR__) . '/app/Bootstrap.php';
require_once __DIR__ . '/TestDatabaseGuard.php';
Bootstrap::init();

/**
 * PDO para suítes MUTÁVEIS — o ÚNICO caminho autorizado a escrever no banco.
 *
 * Conecta ao `test_db` (banco cujo nome termina em _test) e passa pelo
 * {@see TestDatabaseGuard}, que recusa produção ANTES de qualquer SQL. Duas
 * salvaguardas independentes, não uma:
 *   1. force APP_ENV=test aqui (intenção explícita exigida pelo guard);
 *   2. a conexão é a `test_db`, separada da `db`/`flight_plan_db` de produção.
 * Se o `test_db` não estiver configurado, falha ALTO — nunca cai para produção.
 *
 * Toda suíte que cria tabela, insere ou apaga DEVE obter seu PDO por aqui.
 */
function test_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    putenv('APP_ENV=test');
    $_ENV['APP_ENV'] = 'test';

    $config = new Config();
    if (!Database::isConfigured($config, 'test_db')) {
        throw new RuntimeException(
            'test_pdo(): conexão "test_db" não configurada. Defina DB_TEST_NAME/DB_TEST_USER/'
            . 'DB_TEST_PASS no .env.local.php. NUNCA aponte suíte mutável para produção.'
        );
    }

    $candidate = Database::connect($config, 'test_db');
    // Portão estrutural: recusa produção mesmo que a config acima esteja errada.
    TestDatabaseGuard::assertSafe($candidate);

    return $pdo = $candidate;
}

/**
 * @return array{config:Config,logger:Logger}
 */
function test_environment(): array
{
    static $env = null;
    if ($env !== null) {
        return $env;
    }

    $env = [
        'config' => new Config(),
        'logger' => new Logger(dirname(__DIR__) . '/storage/logs/tests.log'),
    ];

    return $env;
}

/**
 * @param array<string,mixed> $caches
 */
function make_freshness_service(array $caches = []): DatasetFreshnessService
{
    $env = test_environment();
    $service = new DatasetFreshnessService($env['config'], $env['logger'], null, null);

    foreach ([
        'recentRunsCache' => 'recent_runs',
        'pipelineSummaryCache' => 'pipeline_summary',
        'divergenceSummaryCache' => 'divergence_summary',
    ] as $property => $cacheKey) {
        if (!array_key_exists($cacheKey, $caches)) {
            continue;
        }

        $reflection = new ReflectionProperty($service, $property);
        $reflection->setValue($service, $caches[$cacheKey]);
    }

    return $service;
}

/**
 * @param list<string> $paths
 */
function golden_normalize(array $payload, array $paths = []): array
{
    $defaultPaths = [
        'freshness_at',
        'coverage_start',
        'coverage_end',
        'reconciled_until',
        'staleness_reason',
        'meta.data_contract',
        'meta.freshness_at',
        'meta.coverage_start',
        'meta.coverage_end',
        'meta.reconciled_until',
        'meta.staleness_reason',
        'meta.reconcile_status',
        'meta.reconciliation_pct',
        'meta.open_divergences',
        'meta.has_retry_or_delay',
        'meta.data_mode',
        'data_contract.freshness_lag_seconds',
        'data_contract.pending_backlog_age_seconds',
        'generated_at',
        'trace',
        'meta.trace',
    ];

    foreach (array_merge($defaultPaths, $paths) as $path) {
        unset_path($payload, $path);
    }

    /** @var array<string,mixed> $normalized */
    $normalized = OperationalPayloadFingerprint::normalizeValue($payload);
    return $normalized;
}

function unset_path(array &$payload, string $path): void
{
    $segments = explode('.', $path);
    $current =& $payload;
    $last = array_pop($segments);

    foreach ($segments as $segment) {
        if (!array_key_exists($segment, $current) || !is_array($current[$segment])) {
            return;
        }
        $current =& $current[$segment];
    }

    if ($last !== null && array_key_exists($last, $current)) {
        unset($current[$last]);
    }
}

/**
 * @return array<string,mixed>
 */
function load_fixture(string $path): array
{
    /** @var array<string,mixed> $fixture */
    $fixture = require $path;
    return $fixture;
}

/**
 * @return array<string,mixed>
 */
function load_expected_json(string $path): array
{
    $decoded = json_decode((string) file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * @param array<string,mixed> $value
 */
function pretty_json(array $value): string
{
    return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
}

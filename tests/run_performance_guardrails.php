<?php

declare(strict_types=1);

use BIomie\Logger;
use BIomie\Cache;
use BIomie\Config;
use BIomie\FinanceAnalyticsService;
use BIomie\Observability\ExternalCallGuard;
use BIomie\Observability\RequestPerformanceContext;
use BIomie\Omie\OmieExecutionContext;
use BIomie\Support\CompanyContext;
use BIomie\Support\TenantContext;

require_once __DIR__ . '/TestSupport.php';
require_once __DIR__ . '/TestRunner.php';

function run_performance_guardrails_suite(): array
{
    $runner = new TestRunner();
    $logger = new Logger(sys_get_temp_dir() . '/biomie-performance-guardrails.log');

    $runner->run(
        'performance:observe_external_get',
        'Observe mode records an external GET violation without blocking.',
        static function () use ($logger): array {
            RequestPerformanceContext::reset();
            $_SERVER['REQUEST_METHOD'] = 'GET';
            ExternalCallGuard::beforeNetworkCall('omie', 'ListarPedidos', OmieExecutionContext::WEB, 'observe', $logger);
            $snapshot = RequestPerformanceContext::snapshot();

            return [
                'ok' => (int) ($snapshot['counters']['external_calls'] ?? 0) === 1
                    && (int) ($snapshot['counters']['external_get_violations'] ?? 0) === 1,
                'snapshot' => $snapshot,
            ];
        }
    );

    $runner->run(
        'performance:enforce_external_get',
        'Enforce mode blocks an external GET before network I/O.',
        static function () use ($logger): array {
            RequestPerformanceContext::reset();
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $blocked = false;
            try {
                ExternalCallGuard::beforeNetworkCall('omie', 'ListarPedidos', OmieExecutionContext::WEB, 'enforce', $logger);
            } catch (RuntimeException) {
                $blocked = true;
            }

            return ['ok' => $blocked];
        }
    );

    $runner->run(
        'performance:worker_external_call_allowed',
        'Worker calls are recorded but are not classified as GET violations.',
        static function () use ($logger): array {
            RequestPerformanceContext::reset();
            $_SERVER['REQUEST_METHOD'] = 'GET';
            ExternalCallGuard::beforeNetworkCall('omie', 'ListarPedidos', OmieExecutionContext::JOB, 'enforce', $logger);
            $snapshot = RequestPerformanceContext::snapshot();

            return [
                'ok' => (int) ($snapshot['counters']['external_calls'] ?? 0) === 1
                    && !isset($snapshot['counters']['external_get_violations']),
                'snapshot' => $snapshot,
            ];
        }
    );

    $runner->run(
        'performance:finance_stale_while_revalidate',
        'Finance serves the last valid snapshot while a refresh is pending.',
        static function () use ($logger): array {
            $root = sys_get_temp_dir() . '/biomie-finance-stale-' . bin2hex(random_bytes(5));
            $cacheDir = $root . '/cache';
            mkdir($cacheDir, 0777, true);
            $configFile = $root . '/env.php';
            file_put_contents($configFile, <<<'PHP'
<?php
return [
    'app' => ['timezone' => 'America/Sao_Paulo'],
    'finance' => ['cache_ttl_minutes' => 1],
    'analytics' => ['cache_ttl_minutes' => 1],
    'omie' => ['app_key' => '', 'app_secret' => ''],
];
PHP);

            $tenantInstance = new ReflectionProperty(TenantContext::class, 'instance');
            $previousContext = $tenantInstance->getValue();
            $tenantInstance->setValue(null, new CompanyContext(
                str_repeat('a', 32),
                'company',
                0,
                1,
                'PERFORMANCE_FIXTURE',
                'Performance Fixture',
                'varejo',
                'system',
                hash('sha256', 'performance-fixture'),
                0,
                [1],
                [1 => ['varejo']],
            ));
            try {
                // Fixture explicitamente isolada: não contém payload HTTP de
                // usuário e já recebeu company_id no contexto acima.
                $cache = new Cache($cacheDir, isolated: true);
                $key = FinanceAnalyticsService::cacheKey('2026-06-01', '2026-06-11');
                $cache->put($key, ['ok' => true, 'meta' => [], 'summary' => ['received' => 123.45]]);
                touch($cacheDir . '/' . $key . '.json', time() - 120);

                $snapshot = (new FinanceAnalyticsService(new Config($configFile), $cache, $logger))
                    ->getSnapshot('2026-06-01', '2026-06-11');

                return [
                    'ok' => ($snapshot['meta']['served_from'] ?? '') === 'cache-stale'
                        && ($snapshot['meta']['refresh_strategy'] ?? '') === 'stale-while-revalidate'
                        && (float) ($snapshot['summary']['received'] ?? 0) === 123.45,
                    'meta' => $snapshot['meta'] ?? [],
                ];
            } finally {
                $tenantInstance->setValue(null, $previousContext);
                foreach (glob($cacheDir . '/*') ?: [] as $file) {
                    @unlink($file);
                }
                @unlink($configFile);
                @rmdir($cacheDir);
                @rmdir($root);
            }
        }
    );

    return $runner->summary();
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $result = run_performance_guardrails_suite();
    echo pretty_json($result) . PHP_EOL;
    exit(($result['ok'] ?? false) ? 0 : 1);
}

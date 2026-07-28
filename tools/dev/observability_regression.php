<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Logger;
use BIomie\OperationalObservabilityService;
use BIomie\PageAssetRegistry;
use BIomie\PageRenderer;
use BIomie\Support\TenantContext;

/**
 * Guard de regressao da pagina de observabilidade.
 *
 * O HTML e renderizado pelo PageRenderer sempre que o contexto CLI permite.
 * Em ambientes sem sessao/auth utilizavel, a view e validada estruturalmente e
 * o motivo do fallback e exibido, sem ocultar qual modo de verificacao foi usado.
 */

$root = dirname(__DIR__, 2);
$checks = [];
$notes = [];

/**
 * @param list<array{name:string,ok:bool,detail:string}> $checks
 */
function recordCheck(array &$checks, string $name, bool $ok, string $detail): void
{
    $checks[] = [
        'name' => $name,
        'ok' => $ok,
        'detail' => $detail,
    ];
}

function countExactId(string $html, string $expectedId): int
{
    if (preg_match_all('/\bid\s*=\s*(["\'])(.*?)\1/is', $html, $matches) === false) {
        return 0;
    }

    $count = 0;
    foreach ($matches[2] as $value) {
        if ($value === $expectedId) {
            $count++;
        }
    }

    return $count;
}

function readRequiredFile(string $path): string
{
    $contents = is_file($path) ? file_get_contents($path) : false;
    if (!is_string($contents)) {
        throw new RuntimeException('Arquivo obrigatorio indisponivel: ' . $path);
    }

    return $contents;
}

/**
 * @param mixed $value
 * @param list<string> $forbidden
 */
function collectForbiddenPayloadKeys(mixed $value, array &$forbidden, string $path = ''): void
{
    if (!is_array($value)) {
        return;
    }

    foreach ($value as $key => $item) {
        $nextPath = $path === '' ? (string) $key : $path . '.' . $key;
        if (in_array(strtolower((string) $key), [
            'context_json', 'attributes_json', 'result_json', 'app_secret', 'password', 'token',
        ], true)) {
            $forbidden[] = $nextPath;
        }
        collectForbiddenPayloadKeys($item, $forbidden, $nextPath);
    }
}

try {
    require_once $root . '/app/Bootstrap.php';

    $bootstrapWarning = null;
    try {
        Bootstrap::init();
    } catch (Throwable $exception) {
        // Bootstrap registra o autoloader antes dos preflights de extensoes.
        // Assim ainda podemos tentar a renderizacao e relatar a limitacao CLI.
        $bootstrapWarning = $exception;
    }

    $renderMode = 'PageRenderer';
    try {
        $html = PageRenderer::render('observabilidade.html', [
            'page_key' => 'observabilidade',
            'user' => null,
            'is_admin' => false,
        ]);
    } catch (Throwable $renderException) {
        $html = readRequiredFile($root . '/views/observabilidade.html');
        $renderMode = 'fallback estrutural da view';
        $notes[] = sprintf(
            'Render completo indisponivel (%s: %s); aplicada checagem estrutural equivalente.',
            $renderException::class,
            $renderException->getMessage()
        );
    }

    if ($bootstrapWarning !== null) {
        $notes[] = sprintf(
            'Preflight do Bootstrap no CLI: %s: %s',
            $bootstrapWarning::class,
            $bootstrapWarning->getMessage()
        );
    }
    $notes[] = 'Modo de validacao HTML: ' . $renderMode . '.';

    foreach ([
        'obsRefreshButton',
        'obsWindowHours',
        'obsGeneratedAt',
        'obsTraceId',
        'obsWindowSelect',
    ] as $id) {
        $count = countExactId($html, $id);
        recordCheck(
            $checks,
            'HTML ID ' . $id,
            $count === 1,
            sprintf('esperado 1, encontrado %d (%s)', $count, $renderMode)
        );
    }

    $h1Count = preg_match_all('/<h1\b/i', $html, $unused);
    $h1Count = is_int($h1Count) ? $h1Count : 0;
    recordCheck(
        $checks,
        'HTML possui um unico h1',
        $h1Count === 1,
        sprintf('esperado 1, encontrado %d (%s)', $h1Count, $renderMode)
    );

    $serviceReflection = new ReflectionClass(OperationalObservabilityService::class);
    $service = $serviceReflection->newInstanceWithoutConstructor();
    $summarizeJobs = $serviceReflection->getMethod('summarizeJobs');

    // Contrato: recent_runs chega em ordem DESC. Ambas as runs pertencem ao
    // mesmo dominio e usam duracoes menores que um segundo com casas decimais.
    $jobSummary = $summarizeJobs->invoke($service, [
        'recent_runs' => [
            [
                'id' => 102,
                'domain_name' => 'orders',
                'status' => 'completed',
                'started_at' => '2026-07-13 12:00:02',
                'finished_at' => '2026-07-13 12:00:03',
                'duration_ms' => 125.50,
            ],
            [
                'id' => 101,
                'domain_name' => 'orders',
                'status' => 'failed',
                'started_at' => '2026-07-13 12:00:01',
                'finished_at' => '2026-07-13 12:00:02',
                'duration_ms' => 375.25,
            ],
        ],
        'webhook_inbox' => [
            'pending_total' => 0,
            'oldest_pending_age_seconds' => null,
        ],
    ], []);

    $domains = is_array($jobSummary['by_domain'] ?? null) ? $jobSummary['by_domain'] : [];
    $orders = null;
    foreach ($domains as $domain) {
        if (is_array($domain) && ($domain['domain'] ?? null) === 'orders') {
            $orders = $domain;
            break;
        }
    }

    recordCheck(
        $checks,
        'summarizeJobs mantem status da run mais nova',
        is_array($orders) && ($orders['last_status'] ?? null) === 'completed',
        'esperado completed; recebido ' . (is_array($orders) ? (string) ($orders['last_status'] ?? 'ausente') : 'dominio ausente')
    );

    $avgDuration = is_array($orders) && is_numeric($orders['avg_duration_ms'] ?? null)
        ? (float) $orders['avg_duration_ms']
        : -1.0;
    $p95Duration = is_array($orders) && is_numeric($orders['p95_duration_ms'] ?? null)
        ? (float) $orders['p95_duration_ms']
        : -1.0;
    $subsecondPrecisionOk = abs($avgDuration - 250.38) < 0.001
        && abs($p95Duration - 375.25) < 0.001
        && $avgDuration < 1000
        && $p95Duration < 1000;
    recordCheck(
        $checks,
        'summarizeJobs preserva duration_ms subsegundo',
        $subsecondPrecisionOk,
        sprintf('media %.2f ms; p95 %.2f ms', $avgDuration, $p95Duration)
    );

    $cssAssets = PageAssetRegistry::cssForPage('observabilidade');
    $jsAssets = PageAssetRegistry::jsBodyForPage('observabilidade');
    recordCheck(
        $checks,
        'PageAssetRegistry inclui observability.css',
        in_array('assets/css/observability.css', $cssAssets, true),
        implode(', ', $cssAssets)
    );
    recordCheck(
        $checks,
        'PageAssetRegistry inclui observability.js',
        in_array('assets/js/observability.js', $jsAssets, true),
        implode(', ', $jsAssets)
    );

    $javascript = readRequiredFile($root . '/assets/js/observability.js');
    recordCheck(
        $checks,
        'JavaScript usa AbortController',
        str_contains($javascript, 'AbortController'),
        'requisicoes concorrentes podem ser canceladas'
    );

    $hasHtmlEscape = preg_match('/function\s+(?:h|escape[A-Za-z0-9_]*)\s*\(/', $javascript) === 1
        && str_contains($javascript, '&amp;')
        && str_contains($javascript, '&lt;')
        && str_contains($javascript, '&gt;');
    recordCheck(
        $checks,
        'JavaScript escapa HTML dinamico',
        $hasHtmlEscape,
        'funcao de escape e entidades HTML obrigatorias'
    );

    $hasTimeout = str_contains($javascript, 'REQUEST_TIMEOUT_MS')
        && str_contains($javascript, 'setTimeout')
        && str_contains($javascript, 'clearTimeout');
    recordCheck(
        $checks,
        'JavaScript possui timeout de requisicao',
        $hasTimeout,
        'REQUEST_TIMEOUT_MS + setTimeout/clearTimeout'
    );
    recordCheck(
        $checks,
        'JavaScript nao possui LOADING_MIN_MS',
        !str_contains($javascript, 'LOADING_MIN_MS'),
        'nenhum atraso minimo artificial no carregamento'
    );

    try {
        $config = new Config();
        $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
        $pdo = Database::connect($config, $connection);
        $company = $pdo->query(
            'SELECT id, canal_padrao FROM biomie_companies WHERE ativo = 1 ORDER BY id LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        if (!is_array($company)) {
            throw new RuntimeException('Nenhuma empresa ativa para o teste de runtime.');
        }

        TenantContext::bootForCompany(
            $pdo,
            (int) $company['id'],
            (string) ($company['canal_padrao'] ?: 'varejo'),
        );
        $logger = new Logger($root . '/storage/logs/biomie.log');
        $runtimeService = OperationalObservabilityService::boot($config, $logger);
        $runtimeStartedAt = microtime(true);
        $dashboard = $runtimeService->buildDashboard(1);
        $runtimeMs = (int) round((microtime(true) - $runtimeStartedAt) * 1000);
        $encodedDashboard = json_encode($dashboard, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $payloadBytes = is_string($encodedDashboard) ? strlen($encodedDashboard) : PHP_INT_MAX;
        $forbiddenKeys = [];
        collectForbiddenPayloadKeys($dashboard, $forbiddenKeys);

        recordCheck(
            $checks,
            'Dashboard executa no banco real',
            isset($dashboard['jobs'], $dashboard['datasets'], $dashboard['endpoint_latency'])
                && count($dashboard['datasets'] ?? []) === 6,
            sprintf('%d ms; %d bases; %d endpoints', $runtimeMs, count($dashboard['datasets'] ?? []), count($dashboard['endpoint_latency'] ?? []))
        );
        recordCheck(
            $checks,
            'Payload permanece enxuto',
            $payloadBytes <= 150_000,
            sprintf('%d bytes (limite 150000)', $payloadBytes)
        );
        recordCheck(
            $checks,
            'Payload nao expoe campos internos ou secretos',
            $forbiddenKeys === [],
            $forbiddenKeys === [] ? 'nenhuma chave proibida' : implode(', ', $forbiddenKeys)
        );
        recordCheck(
            $checks,
            'Construcao sem cache respeita orcamento local',
            $runtimeMs <= 2_000,
            sprintf('%d ms (limite local 2000 ms)', $runtimeMs)
        );
    } catch (Throwable $runtimeException) {
        recordCheck(
            $checks,
            'Dashboard executa no banco real',
            false,
            $runtimeException::class . ': ' . $runtimeException->getMessage()
        );
    }
} catch (Throwable $exception) {
    recordCheck(
        $checks,
        'Execucao do guard',
        false,
        $exception::class . ': ' . $exception->getMessage()
    );
}

foreach ($notes as $note) {
    fwrite(STDOUT, '[INFO] ' . $note . PHP_EOL);
}

$failures = 0;
foreach ($checks as $check) {
    $status = $check['ok'] ? 'PASS' : 'FAIL';
    if (!$check['ok']) {
        $failures++;
    }
    fwrite(STDOUT, sprintf('[%s] %s - %s', $status, $check['name'], $check['detail']) . PHP_EOL);
}

$total = count($checks);
if ($failures === 0) {
    fwrite(STDOUT, sprintf('PASS: observabilidade sem regressoes (%d/%d checks).', $total, $total) . PHP_EOL);
    exit(0);
}

fwrite(STDERR, sprintf('FAIL: %d de %d checks falharam.', $failures, $total) . PHP_EOL);
exit(1);

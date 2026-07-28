<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Cache;
use BIomie\CanonicalRepository;
use BIomie\CommercialCompetenciaSnapshotService;
use BIomie\Config;
use BIomie\Database;
use BIomie\HomeBootstrapService;
use BIomie\GoldMartRepository;
use BIomie\Logger;
use BIomie\Modules\Sales\Application\Overview\OverviewRequest;
use BIomie\TargetsRepository;
use BIomie\Telemetry;
use BIomie\Support\TenantCliContext;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require_once dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

$config = new Config();
$logger = new Logger(dirname(__DIR__) . '/storage/logs/biomie.log');
$telemetry = Telemetry::boot($config, $logger);
$telemetry->startRequestSpan('job.materialize_competencia_snapshots', [
    'domain_name' => 'competencia',
    'source_name' => 'cron',
]);

try {
    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo = Database::connect($config, $connection);
    $tenant = TenantCliContext::boot($pdo, $_SERVER['argv'] ?? []);
    $options = parseCliOptions($tenant['args']);
    $canonical = new CanonicalRepository(
        $pdo,
        $logger,
        null,
        $tenant['company_id'],
        $tenant['canal']
    );
    if (!$canonical->isAvailable()) {
        throw new RuntimeException('Camada canonical indisponivel para materializacao historica.');
    }

    $targetsRepository = new TargetsRepository(
        $pdo,
        $logger,
        tenantCompanyId: $tenant['company_id'],
        tenantChannel: $tenant['canal']
    );
    $service = new CommercialCompetenciaSnapshotService(
        $pdo,
        $logger,
        $canonical,
        $targetsRepository,
        (float) $config->get('flight_plan.saturday_weight', 0.33),
        new GoldMartRepository($pdo, $logger),
    );
    $cache = new Cache(dirname(__DIR__) . '/storage/cache');

    $competencias = resolveCompetencias(
        $pdo,
        $options,
        $tenant['company_id'],
        $tenant['canal'],
    );
    $results = [];
    $hasErrors = false;

    foreach ($competencias as $competencia) {
        try {
            $result = $service->materializeCompetencia($competencia, $options['force']);
            invalidateCommercialMonthCaches($cache, $competencia);
            $results[] = [
                'competencia' => $competencia,
                'status' => $result['meta']['status'] ?? 'ready',
                'duration_ms' => $result['meta']['duration_ms'] ?? null,
                'cache_scope' => 'commercial-month-only',
            ];
        } catch (Throwable $exception) {
            $hasErrors = true;
            $results[] = [
                'competencia' => $competencia,
                'status' => 'error',
                'message' => $exception->getMessage(),
            ];

            $logger->error('Falha ao materializar competencia do lote historico.', [
                'competencia' => $competencia,
                'message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);
        }
    }

    echo json_encode([
        'ok' => !$hasErrors,
        'mode' => $options['mode'],
        'force' => $options['force'],
        'processed' => count($results),
        'competencia' => count($results) === 1 ? $results[0]['competencia'] : null,
        'results' => $results,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;

    if ($hasErrors) {
        exit(1);
    }
} catch (Throwable $exception) {
    $logger->error('Falha ao materializar snapshots por competencia.', [
        'message' => $exception->getMessage(),
        'trace' => $exception->getTraceAsString(),
    ]);
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}

function invalidateCommercialMonthCaches(Cache $cache, string $competencia): void
{
    $start = $competencia . '-01';
    $end = date('Y-m-t', strtotime($start));

    $cache->delete(OverviewRequest::cacheKeyForRange($start, $end));
    $cache->delete(OverviewRequest::cacheKeyForRange($start, $end, $competencia));
    $cache->delete(HomeBootstrapService::cacheKey($start, $end));

    foreach (['admin', 'user'] as $profile) {
        $cache->delete(sprintf(
            'dashboard_boot_v2_%s_%s',
            OverviewRequest::tenantCacheScope(),
            md5($start . '_' . $end . '_' . $profile),
        ));
    }
}

/**
 * @param list<string> $argv
 * @return array{mode:string,competencia:?string,from:?string,to:?string,force:bool}
 */
function parseCliOptions(array $argv): array
{
    $competencia = null;
    $from = null;
    $to = null;
    $allClosed = false;
    $latestClosed = false;
    $force = null;

    foreach ($argv as $arg) {
        if (!is_string($arg)) {
            continue;
        }

        if (str_starts_with($arg, '--competencia=')) {
            $competencia = trim(substr($arg, 14));
            continue;
        }

        if (str_starts_with($arg, '--from=')) {
            $from = trim(substr($arg, 7));
            continue;
        }

        if (str_starts_with($arg, '--to=')) {
            $to = trim(substr($arg, 5));
            continue;
        }

        if ($arg === '--all-closed') {
            $allClosed = true;
            continue;
        }

        if ($arg === '--latest-closed') {
            $latestClosed = true;
            continue;
        }

        if ($arg === '--force') {
            $force = true;
            continue;
        }

        if ($arg === '--no-force') {
            $force = false;
        }
    }

    $modes = (int) ($competencia !== null) + (int) ($from !== null || $to !== null) + (int) $allClosed + (int) $latestClosed;
    if ($modes !== 1) {
        throw new InvalidArgumentException(
            'Use um modo por vez: --competencia=YYYY-MM, --from=YYYY-MM --to=YYYY-MM, --latest-closed ou --all-closed.'
        );
    }

    if ($competencia !== null) {
        return [
            'mode' => 'single',
            'competencia' => normalizeCompetencia($competencia),
            'from' => null,
            'to' => null,
            'force' => $force ?? true,
        ];
    }

    if ($from !== null || $to !== null) {
        if ($from === null || $to === null) {
            throw new InvalidArgumentException('Use o intervalo completo com --from=YYYY-MM --to=YYYY-MM.');
        }

        $from = normalizeCompetencia($from);
        $to = normalizeCompetencia($to);
        if ($from > $to) {
            throw new InvalidArgumentException('O intervalo historico precisa respeitar from <= to.');
        }

        return [
            'mode' => 'range',
            'competencia' => null,
            'from' => $from,
            'to' => $to,
            'force' => $force ?? false,
        ];
    }

    if ($latestClosed) {
        return [
            'mode' => 'latest_closed',
            'competencia' => null,
            'from' => null,
            'to' => null,
            'force' => $force ?? false,
        ];
    }

    return [
        'mode' => 'all_closed',
        'competencia' => null,
        'from' => null,
        'to' => null,
        'force' => $force ?? false,
    ];
}

/**
 * @param array{mode:string,competencia:?string,from:?string,to:?string,force:bool} $options
 * @return list<string>
 */
function resolveCompetencias(
    PDO $pdo,
    array $options,
    int $companyId,
    string $businessChannel,
): array
{
    $latestClosed = latestClosedCompetencia();

    if ($options['mode'] === 'single') {
        $competencia = (string) $options['competencia'];
        assertClosedCompetencia($competencia, $latestClosed);
        return [$competencia];
    }

    if ($options['mode'] === 'latest_closed') {
        return [$latestClosed];
    }

    if ($options['mode'] === 'range') {
        $from = (string) $options['from'];
        $to = (string) $options['to'];
        assertClosedCompetencia($from, $latestClosed);
        assertClosedCompetencia($to, $latestClosed);
        return buildCompetenciaRange($from, $to);
    }

    [$from, $to] = detectAvailableClosedRange(
        $pdo,
        $latestClosed,
        $companyId,
        $businessChannel,
    );
    if ($from === null || $to === null) {
        throw new RuntimeException('Nao foi possivel detectar competencias fechadas para backfill.');
    }

    return buildCompetenciaRange($from, $to);
}

function normalizeCompetencia(string $competencia): string
{
    $competencia = trim($competencia);
    if (preg_match('/^\d{4}-\d{2}$/', $competencia) !== 1) {
        throw new InvalidArgumentException('Competencia deve seguir o formato YYYY-MM.');
    }

    $month = DateTimeImmutable::createFromFormat('!Y-m', $competencia);
    if (!$month instanceof DateTimeImmutable || $month->format('Y-m') !== $competencia) {
        throw new InvalidArgumentException('Competencia invalida. Use o formato YYYY-MM.');
    }

    return $competencia;
}

function latestClosedCompetencia(): string
{
    return (new DateTimeImmutable('first day of this month'))->modify('-1 month')->format('Y-m');
}

function assertClosedCompetencia(string $competencia, string $latestClosed): void
{
    if ($competencia > $latestClosed) {
        throw new InvalidArgumentException(
            sprintf('Competencia %s ainda nao esta fechada. A ultima competencia fechada hoje e %s.', $competencia, $latestClosed)
        );
    }
}

/**
 * @return array{0:?string,1:?string}
 */
function detectAvailableClosedRange(
    PDO $pdo,
    string $latestClosed,
    int $companyId,
    string $businessChannel,
): array
{
    $candidates = [];

    $invoiceMin = scalarYearMonth(
        $pdo,
        "SELECT DATE_FORMAT(MIN(data_competencia), '%Y-%m')
           FROM biomie_notas_fiscais_canonicas
          WHERE company_id = :company_id
            AND business_channel = :business_channel
            AND data_competencia IS NOT NULL",
        ['company_id' => $companyId, 'business_channel' => $businessChannel],
    );
    if ($invoiceMin !== null) {
        $candidates[] = $invoiceMin;
    }

    $orderMin = scalarYearMonth(
        $pdo,
        "SELECT DATE_FORMAT(MIN(data_emissao), '%Y-%m')
           FROM biomie_pedidos_canonicos
          WHERE company_id = :company_id
            AND business_channel = :business_channel
            AND data_emissao IS NOT NULL",
        ['company_id' => $companyId, 'business_channel' => $businessChannel],
    );
    if ($orderMin !== null) {
        $candidates[] = $orderMin;
    }

    // A target without any fiscal/order fact is not sufficient evidence that a
    // tenant/channel month should receive a commercial snapshot. Legacy target
    // tables are not uniformly tenant-scoped, so all-closed is derived only
    // from canonical facts above.

    if ($candidates === []) {
        return [null, null];
    }

    sort($candidates, SORT_STRING);
    $from = $candidates[0];
    if ($from > $latestClosed) {
        return [null, null];
    }

    return [$from, $latestClosed];
}

/** @param array<string,int|string> $params */
function scalarYearMonth(PDO $pdo, string $sql, array $params = []): ?string
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $value = $stmt->fetchColumn();
    if (!is_string($value) || preg_match('/^\d{4}-\d{2}$/', $value) !== 1) {
        return null;
    }

    return $value;
}

/**
 * @return list<string>
 */
function buildCompetenciaRange(string $from, string $to): array
{
    $cursor = DateTimeImmutable::createFromFormat('!Y-m', $from);
    $limit = DateTimeImmutable::createFromFormat('!Y-m', $to);
    if (!$cursor instanceof DateTimeImmutable || !$limit instanceof DateTimeImmutable) {
        throw new InvalidArgumentException('Intervalo de competencias invalido.');
    }

    $competencias = [];
    while ($cursor <= $limit) {
        $competencias[] = $cursor->format('Y-m');
        $cursor = $cursor->modify('+1 month');
    }

    return $competencias;
}

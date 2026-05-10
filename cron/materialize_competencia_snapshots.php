<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\CanonicalRepository;
use BIomie\CommercialCompetenciaSnapshotService;
use BIomie\Config;
use BIomie\Database;
use BIomie\Logger;
use BIomie\TargetsRepository;
use BIomie\Telemetry;

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
    $options = parseCliOptions($_SERVER['argv'] ?? []);

    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo = Database::connect($config, $connection);
    $canonical = new CanonicalRepository($pdo, $logger);
    if (!$canonical->isAvailable()) {
        throw new RuntimeException('Camada canonical indisponivel para materializacao historica.');
    }

    $targetsRepository = new TargetsRepository($pdo, $logger);
    $service = new CommercialCompetenciaSnapshotService(
        $pdo,
        $logger,
        $canonical,
        $targetsRepository,
        (float) $config->get('flight_plan.saturday_weight', 0.33)
    );

    $competencias = resolveCompetencias($pdo, $targetsRepository, $options);
    $results = [];
    $hasErrors = false;

    foreach ($competencias as $competencia) {
        try {
            $result = $service->materializeCompetencia($competencia, $options['force']);
            $results[] = [
                'competencia' => $competencia,
                'status' => $result['meta']['status'] ?? 'ready',
                'duration_ms' => $result['meta']['duration_ms'] ?? null,
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
function resolveCompetencias(PDO $pdo, TargetsRepository $targetsRepository, array $options): array
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

    [$from, $to] = detectAvailableClosedRange($pdo, $targetsRepository, $latestClosed);
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
function detectAvailableClosedRange(PDO $pdo, TargetsRepository $targetsRepository, string $latestClosed): array
{
    $candidates = [];

    $invoiceMin = scalarYearMonth(
        $pdo,
        "SELECT DATE_FORMAT(MIN(data_competencia), '%Y-%m') FROM biomie_notas_fiscais_canonicas WHERE data_competencia IS NOT NULL"
    );
    if ($invoiceMin !== null) {
        $candidates[] = $invoiceMin;
    }

    $orderMin = scalarYearMonth(
        $pdo,
        "SELECT DATE_FORMAT(MIN(data_emissao), '%Y-%m') FROM biomie_pedidos_canonicos WHERE data_emissao IS NOT NULL"
    );
    if ($orderMin !== null) {
        $candidates[] = $orderMin;
    }

    if ($targetsRepository->isAvailable()) {
        $targetsMin = scalarYearMonth(
            $pdo,
            "SELECT MIN(`year_month`) FROM biomie_sales_targets_monthly WHERE `year_month` REGEXP '^[0-9]{4}-[0-9]{2}$'"
        );
        if ($targetsMin !== null) {
            $candidates[] = $targetsMin;
        }
    }

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

function scalarYearMonth(PDO $pdo, string $sql): ?string
{
    $value = $pdo->query($sql)->fetchColumn();
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

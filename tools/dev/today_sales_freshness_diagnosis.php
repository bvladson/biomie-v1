<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Cache;
use BIomie\Config;
use BIomie\Database;
use BIomie\Logger;
use BIomie\OmieClient;
use BIomie\OmieMethodPolicyRegistry;
use BIomie\OmieRepository;
use BIomie\Support\BiVisibilityScope;
use BIomie\Support\FiscalValueBasis;
use BIomie\Support\SecretMask;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$opts = parseArgs($argv);
$json = isset($opts['json']);
$pretty = isset($opts['pretty']) || !$json;
$date = isset($opts['today']) ? date('Y-m-d') : (string) ($opts['date'] ?? date('Y-m-d'));
$limit = max(1, min(200, (int) ($opts['limit'] ?? 50)));
$omieProbe = isset($opts['omie-probe']) && !isset($opts['no-omie']);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    fwrite(STDERR, "Use --date=YYYY-MM-DD ou --today.\n");
    exit(2);
}

$config = new Config();
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo = Database::connect($config, $connection);
$logger = new Logger(Bootstrap::rootPath('storage/logs/biomie.log'));

$report = buildTodaySalesReport($pdo, $config, $logger, $date, $limit, $omieProbe);

if ($json) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($report['status'] === 'CRITICAL' ? 1 : 0);
}

printPretty($report);
exit($report['status'] === 'CRITICAL' ? 1 : 0);

/**
 * @return array<string,string|bool>
 */
function parseArgs(array $argv): array
{
    $out = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        [$key, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, true);
        $out[$key] = $value;
    }
    return $out;
}

/**
 * @return array<string,mixed>
 */
function buildTodaySalesReport(PDO $pdo, Config $config, Logger $logger, string $date, int $limit, bool $omieProbe): array
{
    $period = ['start' => $date, 'end' => $date, 'competencia' => substr($date, 0, 7)];
    $report = [
        'ok' => true,
        'status' => 'OK',
        'classification' => 'unknown',
        'generated_at' => date(DATE_ATOM),
        'date' => $date,
        'period' => $period,
        'read_only' => true,
        'sync_source' => syncSource($pdo),
        'bronze' => bronzeStatus($pdo, $date),
        'canonical_fiscal' => canonicalFiscalStatus($pdo, $date, $limit),
        'canonical_orders' => canonicalOrdersStatus($pdo, $date, $limit),
        'invoice_items' => invoiceItemsStatus($pdo, $date),
        'marts' => martStatus($pdo, $date),
        'dashboard' => dashboardSignals($pdo, $date),
        'cache' => cacheStatus($date),
        'omie_probe' => [
            'enabled' => $omieProbe,
            'status' => $omieProbe ? 'pending' : 'skipped',
            'message' => $omieProbe ? 'Probe sera executado.' : 'Use --omie-probe para consultar a Omie sem gravar bronze.',
        ],
    ];

    if ($omieProbe) {
        $report['omie_probe'] = probeOmie($config, $logger, $date, $limit);
    }

    $classification = classifyTodaySales($report);
    $report['classification'] = $classification['code'];
    $report['status'] = $classification['status'];
    $report['ok'] = $classification['status'] !== 'CRITICAL';
    $report['summary'] = $classification['message'];
    $report['recommendations'] = $classification['recommendations'];

    return $report;
}

/**
 * @return array<string,mixed>
 */
function syncSource(PDO $pdo): array
{
    if (!tableExists($pdo, 'biomie_sync_runs')) {
        return ['exists' => false, 'latest_commercial_runs' => []];
    }

    $rows = fetchAll($pdo, <<<'SQL'
        SELECT
            id,
            run_type,
            domain_name,
            endpoint_group,
            status,
            items_processed,
            records_seen,
            records_inserted,
            records_updated,
            records_skipped,
            pages_processed,
            window_start,
            window_end,
            started_at,
            finished_at,
            error_code,
            error_message_sanitized
        FROM biomie_sync_runs
        WHERE domain_name = 'commercial'
        ORDER BY COALESCE(finished_at, started_at) DESC, id DESC
        LIMIT 10
    SQL);

    return [
        'exists' => true,
        'latest_commercial_runs' => $rows,
        'latest_success_at' => firstNonEmpty($rows, 'finished_at', static fn (array $row): bool =>
            in_array((string) ($row['status'] ?? ''), ['completed', 'completed_with_warnings'], true)
        ),
    ];
}

/**
 * @return array<string,mixed>
 */
function bronzeStatus(PDO $pdo, string $date): array
{
    if (!tableExists($pdo, 'biomie_omie_bronze')) {
        return ['exists' => false, 'by_method' => [], 'recent_errors' => []];
    }

    $sql = <<<'SQL'
            SELECT
                domain_name,
                entity_name,
                omie_method,
                source,
                COUNT(*) AS records,
                MIN(ingested_at) AS first_ingested_at,
                MAX(ingested_at) AS last_ingested_at
            FROM biomie_omie_bronze
            WHERE DATE(ingested_at) = :date
            GROUP BY domain_name, entity_name, omie_method, source
            ORDER BY last_ingested_at DESC
        SQL;

    return [
        'exists' => true,
        'by_method' => fetchAll($pdo, $sql, ['date' => $date]),
    ];
}

/**
 * @return array<string,mixed>
 */
function canonicalFiscalStatus(PDO $pdo, string $date, int $limit): array
{
    if (!tableExists($pdo, 'biomie_notas_fiscais_canonicas')) {
        return ['exists' => false];
    }

    $vis = BiVisibilityScope::getCondition($pdo, 'biomie_notas_fiscais_canonicas', 'nf');
    $baseSql = FiscalValueBasis::preComputedBaseSql('nf');
    $dateCols = ['data_competencia', 'data_emissao'];
    $out = ['exists' => true, 'by_date_basis' => []];

    foreach ($dateCols as $column) {
        $out['by_date_basis'][$column] = fetchOne($pdo, "
            SELECT
                COUNT(*) AS total_rows,
                SUM(CASE WHEN nf.status_fiscal = 'autorizada' THEN 1 ELSE 0 END) AS authorized_rows,
                SUM(CASE WHEN nf.status_fiscal = 'cancelada' THEN 1 ELSE 0 END) AS cancelled_rows,
                SUM(CASE WHEN nf.status_fiscal = 'autorizada' AND {$vis} THEN 1 ELSE 0 END) AS commercial_visible_rows,
                COALESCE(SUM(CASE WHEN nf.status_fiscal = 'autorizada' AND {$vis} THEN {$baseSql} ELSE 0 END), 0) AS commercial_base_value,
                COALESCE(SUM(CASE WHEN nf.status_fiscal = 'autorizada' THEN nf.valor_total ELSE 0 END), 0) AS gross_value,
                MIN(nf.numero_nf) AS min_nf,
                MAX(nf.numero_nf) AS max_nf,
                MAX(nf.sincronizado_em) AS last_sync_at
            FROM biomie_notas_fiscais_canonicas nf
            WHERE nf.{$column} = :date
        ", ['date' => $date]);
    }

    $out['by_status_scope'] = fetchAll($pdo, "
        SELECT
            nf.status_fiscal,
            COALESCE(nf.operation_scope, 'unknown') AS operation_scope,
            COALESCE(nf.is_biomie_commercial_visible, 0) AS visible,
            COUNT(*) AS rows_count,
            COALESCE(SUM({$baseSql}), 0) AS commercial_base_value,
            MAX(nf.sincronizado_em) AS last_sync_at
        FROM biomie_notas_fiscais_canonicas nf
        WHERE nf.data_competencia = :date_comp OR nf.data_emissao = :date_emis
        GROUP BY nf.status_fiscal, operation_scope, visible
        ORDER BY rows_count DESC
    ", ['date_comp' => $date, 'date_emis' => $date]);

    $out['sample'] = fetchAll($pdo, "
        SELECT
            nf.nota_key,
            nf.numero_nf,
            nf.status_fiscal,
            nf.data_emissao,
            nf.data_competencia,
            nf.nome_cliente,
            nf.nome_vendedor,
            nf.valor_total,
            {$baseSql} AS valor_base_comercial,
            nf.operation_scope,
            nf.is_biomie_commercial_visible,
            nf.sincronizado_em
        FROM biomie_notas_fiscais_canonicas nf
        WHERE nf.data_competencia = :date_comp OR nf.data_emissao = :date_emis
        ORDER BY nf.data_competencia DESC, nf.numero_nf DESC
        LIMIT {$limit}
    ", ['date_comp' => $date, 'date_emis' => $date]);

    return $out;
}

/**
 * @return array<string,mixed>
 */
function canonicalOrdersStatus(PDO $pdo, string $date, int $limit): array
{
    if (!tableExists($pdo, 'biomie_pedidos_canonicos')) {
        return ['exists' => false];
    }

    $vis = BiVisibilityScope::getCondition($pdo, 'biomie_pedidos_canonicos', 'p');
    return [
        'exists' => true,
        'by_emission' => fetchOne($pdo, "
            SELECT
                COUNT(*) AS total_rows,
                SUM(CASE WHEN p.status_comercial = 'faturado' THEN 1 ELSE 0 END) AS billed_rows,
                SUM(CASE WHEN p.status_comercial = 'autorizado' THEN 1 ELSE 0 END) AS authorized_rows,
                SUM(CASE WHEN p.status_comercial = 'cancelado' THEN 1 ELSE 0 END) AS cancelled_rows,
                SUM(CASE WHEN {$vis} THEN 1 ELSE 0 END) AS commercial_visible_rows,
                COALESCE(SUM(CASE WHEN {$vis} THEN p.valor_total ELSE 0 END), 0) AS commercial_value,
                MIN(p.codigo_pedido) AS min_order,
                MAX(p.codigo_pedido) AS max_order,
                MAX(p.sincronizado_em) AS last_sync_at
            FROM biomie_pedidos_canonicos p
            WHERE p.data_emissao = :date
        ", ['date' => $date]),
        'sample' => fetchAll($pdo, "
            SELECT
                p.pedido_id_omie,
                p.codigo_pedido,
                p.status_comercial,
                p.data_emissao,
                p.data_faturamento,
                p.nome_cliente,
                p.nome_vendedor,
                p.valor_total,
                p.operation_scope,
                p.is_biomie_commercial_visible,
                p.sincronizado_em
            FROM biomie_pedidos_canonicos p
            WHERE p.data_emissao = :date_emis OR p.data_faturamento = :date_fat
            ORDER BY COALESCE(p.data_faturamento, p.data_emissao) DESC, p.codigo_pedido DESC
            LIMIT {$limit}
        ", ['date_emis' => $date, 'date_fat' => $date]),
    ];
}

/**
 * @return array<string,mixed>
 */
function invoiceItemsStatus(PDO $pdo, string $date): array
{
    if (!tableExists($pdo, 'biomie_notas_fiscais_itens_canonicos') || !tableExists($pdo, 'biomie_notas_fiscais_canonicas')) {
        return ['exists' => false];
    }

    $sql = <<<'SQL'
            SELECT
                COUNT(DISTINCT nf.nota_key) AS invoices_with_any_join,
                COUNT(item.id) AS item_rows,
                SUM(CASE WHEN item.nota_key IS NULL THEN 1 ELSE 0 END) AS invoices_without_items
            FROM biomie_notas_fiscais_canonicas nf
            LEFT JOIN biomie_notas_fiscais_itens_canonicos item
                ON item.nota_key = nf.nota_key
            WHERE nf.data_competencia = :date_comp OR nf.data_emissao = :date_emis
        SQL;

    return [
        'exists' => true,
        'for_day_invoices' => fetchOne($pdo, $sql, ['date_comp' => $date, 'date_emis' => $date]),
        'items_without_note_key' => (int) fetchValue($pdo, "SELECT COUNT(*) FROM biomie_notas_fiscais_itens_canonicos WHERE nota_key IS NULL OR nota_key = ''"),
    ];
}

/**
 * @return array<string,mixed>
 */
function martStatus(PDO $pdo, string $date): array
{
    $competencia = substr($date, 0, 7);
    $checks = [
        'mart_vendedor_dia' => ['where' => 'dia = :date', 'params' => ['date' => $date], 'granularity' => 'day'],
        'mart_cliente_periodo' => ['where' => 'competencia = :competencia', 'params' => ['competencia' => $competencia], 'granularity' => 'month'],
        'mart_produto_periodo' => ['where' => 'competencia = :competencia', 'params' => ['competencia' => $competencia], 'granularity' => 'month'],
        'mart_compradores_por_geo' => ['where' => 'competencia = :competencia', 'params' => ['competencia' => $competencia], 'granularity' => 'month'],
        'mart_vendas_por_cnae_cnpj' => ['where' => 'competencia = :competencia', 'params' => ['competencia' => $competencia], 'granularity' => 'month'],
    ];
    $out = [];

    foreach ($checks as $table => $check) {
        if (!tableExists($pdo, $table)) {
            $out[$table] = ['exists' => false, 'rows' => null, 'granularity' => $check['granularity']];
            continue;
        }
        $out[$table] = fetchOne(
            $pdo,
            "SELECT COUNT(*) AS rows_count, MAX(refreshed_at) AS last_refreshed_at FROM {$table} WHERE {$check['where']}",
            $check['params']
        ) + ['exists' => true, 'granularity' => $check['granularity']];
    }

    return $out;
}

/**
 * @return array<string,mixed>
 */
function dashboardSignals(PDO $pdo, string $date): array
{
    if (!tableExists($pdo, 'biomie_notas_fiscais_canonicas')) {
        return ['available' => false];
    }

    $vis = BiVisibilityScope::getCondition($pdo, 'biomie_notas_fiscais_canonicas', 'nf');
    $baseSql = FiscalValueBasis::preComputedBaseSql('nf');
    $row = fetchOne($pdo, "
        SELECT
            COUNT(*) AS nfs,
            COUNT(DISTINCT nf.codigo_cliente_omie) AS clientes,
            COALESCE(SUM({$baseSql}), 0) AS faturamento
        FROM biomie_notas_fiscais_canonicas nf
        WHERE nf.status_fiscal = 'autorizada'
          AND nf.data_competencia = :date
          AND {$vis}
    ", ['date' => $date]);

    return ['available' => true, 'direct_canonical_kpis' => $row];
}

/**
 * @return array<string,mixed>
 */
function cacheStatus(string $date): array
{
    $cacheRoot = Bootstrap::rootPath('storage/cache');
    $matches = [];
    foreach (glob($cacheRoot . '/*.json') ?: [] as $path) {
        $name = basename($path);
        if (str_contains($name, $date) || str_contains($name, str_replace('-', '', $date)) || str_contains($name, 'overview')) {
            $matches[] = [
                'file' => 'storage/cache/' . $name,
                'mtime' => date(DATE_ATOM, (int) (@filemtime($path) ?: time())),
                'bytes' => (int) (@filesize($path) ?: 0),
            ];
        }
        if (count($matches) >= 20) {
            break;
        }
    }

    return [
        'cache_control_expected' => 'no-store for PHP APIs; file cache can still serve overview unless force/cache key changes',
        'matching_files' => $matches,
    ];
}

/**
 * @return array<string,mixed>
 */
function probeOmie(Config $config, Logger $logger, string $date, int $limit): array
{
    if (!$config->hasOmieCredentials()) {
        return ['enabled' => true, 'status' => 'error', 'error' => 'credentials_missing', 'message' => 'Credenciais Omie nao configuradas.'];
    }

    try {
        $cache = new Cache(Bootstrap::rootPath('storage/cache'));
        $client = new OmieClient(
            (string) $config->get('omie.app_key'),
            (string) $config->get('omie.app_secret'),
            (int) $config->get('omie.request_timeout', 20),
            $logger,
            new OmieMethodPolicyRegistry($config),
            $cache,
            'diagnosis',
        );
        $repo = new OmieRepository($client, $config, null, 'diagnosis-readonly');
        $authorized = $repo->listInvoices($date, $date, 'N');
        $cancelled = $repo->listInvoices($date, $date, 'C');

        return [
            'enabled' => true,
            'status' => 'ok',
            'method' => 'ListarNF',
            'filters' => [
                'authorized' => ['dEmiInicial' => brDate($date), 'dEmiFinal' => brDate($date), 'filtrar_por_status' => 'N'],
                'cancelled' => ['dCanInicial' => brDate($date), 'dCanFinal' => brDate($date), 'filtrar_por_status' => 'C'],
            ],
            'total_authorized' => count($authorized),
            'total_cancelled' => count($cancelled),
            'first_nf' => firstInvoiceNumber($authorized),
            'last_nf' => lastInvoiceNumber($authorized),
            'sample' => array_slice(array_map(static fn (array $row): array => [
                'nf' => invoiceNumber($row),
                'cliente' => invoiceClientName($row),
            ], $authorized), 0, $limit),
            'writes_executed' => 0,
        ];
    } catch (Throwable $e) {
        $message = SecretMask::sanitizeErrorMessage($e->getMessage(), 300);
        return [
            'enabled' => true,
            'status' => 'error',
            'error' => str_contains(mb_strtolower($message), 'ssl') ? 'omie_ssl_error' : 'omie_api_error',
            'message' => $message,
            'writes_executed' => 0,
        ];
    }
}

/**
 * @param array<string,mixed> $report
 * @return array{status:string,code:string,message:string,recommendations:list<string>}
 */
function classifyTodaySales(array $report): array
{
    $probe = $report['omie_probe'] ?? [];
    $fiscal = $report['canonical_fiscal']['by_date_basis']['data_competencia'] ?? [];
    $fiscalEmission = $report['canonical_fiscal']['by_date_basis']['data_emissao'] ?? [];
    $orders = $report['canonical_orders']['by_emission'] ?? [];
    $martVendor = $report['marts']['mart_vendedor_dia'] ?? [];
    $visibleNfs = (int) ($fiscal['commercial_visible_rows'] ?? 0);
    $emissionNfs = (int) ($fiscalEmission['commercial_visible_rows'] ?? 0);
    $orderRows = (int) ($orders['commercial_visible_rows'] ?? 0);
    $martRows = (int) ($martVendor['rows_count'] ?? 0);

    if (($probe['status'] ?? null) === 'error') {
        return [
            'status' => 'WARNING',
            'code' => (string) ($probe['error'] ?? 'omie_api_error'),
            'message' => 'Probe Omie falhou; nao e seguro afirmar que nao houve movimento.',
            'recommendations' => ['Corrigir conectividade/SSL Omie e repetir com --omie-probe.', 'Nao mascarar dashboard zero como OK sem confirmar origem.'],
        ];
    }

    if (($probe['status'] ?? null) === 'ok' && (int) ($probe['total_authorized'] ?? 0) > 0 && max($visibleNfs, $emissionNfs) === 0) {
        return [
            'status' => 'CRITICAL',
            'code' => 'bronze_has_data_canonical_missing',
            'message' => 'A Omie retornou NFs autorizadas para a data, mas o canonico comercial nao tem NFs visiveis.',
            'recommendations' => ['Executar sync comercial restrito para a data: php cron/sync_realtime_pipeline.php --domains=commercial --mode=backfill --start=' . $report['date'] . ' --end=' . $report['date'], 'Depois materializar Gold para a mesma data.'],
        ];
    }

    if (($probe['status'] ?? null) === 'ok' && (int) ($probe['total_authorized'] ?? 0) === 0 && max($visibleNfs, $emissionNfs) === 0) {
        return [
            'status' => 'OK',
            'code' => 'omie_no_data',
            'message' => 'Probe Omie confirmou ausencia de NFs autorizadas na data.',
            'recommendations' => ['Exibir dashboard zero com motivo: sem movimento confirmado na Omie.'],
        ];
    }

    if (max($visibleNfs, $emissionNfs) > 0 && $martRows === 0) {
        return [
            'status' => 'CRITICAL',
            'code' => 'canonical_has_data_marts_missing',
            'message' => 'O canonico tem vendas comerciais na data, mas mart_vendedor_dia nao tem linhas para o dia.',
            'recommendations' => ['Executar php bin/materialize_marts.php --start=' . $report['date'] . ' --end=' . $report['date'], 'Rodar dashboard_period_consistency_check depois da materializacao.'],
        ];
    }

    if (max($visibleNfs, $emissionNfs, $orderRows) > 0 && $martRows > 0) {
        return [
            'status' => 'OK',
            'code' => 'canonical_and_marts_have_data',
            'message' => 'Canonico e mart diario possuem sinal comercial para a data.',
            'recommendations' => ['Validar dashboard e daily_sales_integrity_check para a data.'],
        ];
    }

    return [
        'status' => 'WARNING',
        'code' => 'unknown',
        'message' => 'Nao ha vendas canonicas/mart na data e a Omie nao foi consultada nesta execucao.',
        'recommendations' => ['Rodar novamente com --omie-probe para confirmar origem.', 'Se a Omie tiver NFs, executar backfill comercial restrito para a data.'],
    ];
}

function printPretty(array $report): void
{
    echo PHP_EOL . 'AUTO.R1.1 Today Sales Freshness Diagnosis' . PHP_EOL;
    echo str_repeat('-', 72) . PHP_EOL;
    echo 'Data: ' . $report['date'] . PHP_EOL;
    echo 'Status: ' . $report['status'] . ' | Classificacao: ' . $report['classification'] . PHP_EOL;
    echo 'Resumo: ' . ($report['summary'] ?? '-') . PHP_EOL . PHP_EOL;

    $fiscal = $report['canonical_fiscal']['by_date_basis']['data_competencia'] ?? [];
    $emission = $report['canonical_fiscal']['by_date_basis']['data_emissao'] ?? [];
    $orders = $report['canonical_orders']['by_emission'] ?? [];
    $mart = $report['marts']['mart_vendedor_dia'] ?? [];
    echo 'Canonico NF competencia: ' . (int) ($fiscal['commercial_visible_rows'] ?? 0)
        . ' NF(s), R$ ' . number_format((float) ($fiscal['commercial_base_value'] ?? 0), 2, ',', '.')
        . ', sync=' . (($fiscal['last_sync_at'] ?? null) ?: '-') . PHP_EOL;
    echo 'Canonico NF emissao    : ' . (int) ($emission['commercial_visible_rows'] ?? 0)
        . ' NF(s), R$ ' . number_format((float) ($emission['commercial_base_value'] ?? 0), 2, ',', '.')
        . ', sync=' . (($emission['last_sync_at'] ?? null) ?: '-') . PHP_EOL;
    echo 'Pedidos canonicos      : ' . (int) ($orders['commercial_visible_rows'] ?? 0)
        . ' pedido(s), R$ ' . number_format((float) ($orders['commercial_value'] ?? 0), 2, ',', '.')
        . ', sync=' . (($orders['last_sync_at'] ?? null) ?: '-') . PHP_EOL;
    echo 'mart_vendedor_dia      : ' . (int) ($mart['rows_count'] ?? 0)
        . ' linha(s), refreshed=' . (($mart['last_refreshed_at'] ?? null) ?: '-') . PHP_EOL;

    $probe = $report['omie_probe'] ?? [];
    echo 'Omie probe             : ' . (($probe['status'] ?? 'skipped')) . PHP_EOL;
    if (($probe['status'] ?? null) === 'ok') {
        echo '  ListarNF autorizadas : ' . (int) ($probe['total_authorized'] ?? 0) . PHP_EOL;
        echo '  NF primeira/ultima   : ' . (($probe['first_nf'] ?? null) ?: '-') . ' / ' . (($probe['last_nf'] ?? null) ?: '-') . PHP_EOL;
    } elseif (($probe['status'] ?? null) === 'error') {
        echo '  Erro                 : ' . ($probe['message'] ?? '-') . PHP_EOL;
    }

    echo PHP_EOL . 'Recomendacoes:' . PHP_EOL;
    foreach ((array) ($report['recommendations'] ?? []) as $recommendation) {
        echo ' - ' . $recommendation . PHP_EOL;
    }
    echo PHP_EOL;
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
    $stmt->execute(['table' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * @return array<string,mixed>
 */
function fetchOne(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
}

/**
 * @return list<array<string,mixed>>
 */
function fetchAll(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function fetchValue(PDO $pdo, string $sql, array $params = []): mixed
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

/**
 * @param list<array<string,mixed>> $rows
 */
function firstNonEmpty(array $rows, string $field, callable $predicate): ?string
{
    foreach ($rows as $row) {
        if (!$predicate($row)) {
            continue;
        }
        $value = (string) ($row[$field] ?? '');
        if ($value !== '') {
            return $value;
        }
    }
    return null;
}

function brDate(string $date): string
{
    return date('d/m/Y', strtotime($date) ?: time());
}

/**
 * @param list<array<string,mixed>> $rows
 */
function firstInvoiceNumber(array $rows): ?string
{
    return $rows === [] ? null : invoiceNumber($rows[0]);
}

/**
 * @param list<array<string,mixed>> $rows
 */
function lastInvoiceNumber(array $rows): ?string
{
    return $rows === [] ? null : invoiceNumber($rows[count($rows) - 1]);
}

/**
 * @param array<string,mixed> $row
 */
function invoiceNumber(array $row): string
{
    foreach (['numero_nf', 'nNF', 'cNF', 'nIdNF'] as $key) {
        if (isset($row[$key]) && trim((string) $row[$key]) !== '') {
            return trim((string) $row[$key]);
        }
    }
    foreach (['ide', 'cabecalho', 'compl'] as $nested) {
        if (is_array($row[$nested] ?? null)) {
            $value = invoiceNumber($row[$nested]);
            if ($value !== '') {
                return $value;
            }
        }
    }
    return '';
}

/**
 * @param array<string,mixed> $row
 */
function invoiceClientName(array $row): string
{
    foreach (['nome_cliente', 'xNome', 'razao_social'] as $key) {
        if (isset($row[$key]) && trim((string) $row[$key]) !== '') {
            return trim((string) $row[$key]);
        }
    }
    foreach (['dest', 'cliente', 'cabecalho'] as $nested) {
        if (is_array($row[$nested] ?? null)) {
            $value = invoiceClientName($row[$nested]);
            if ($value !== '') {
                return $value;
            }
        }
    }
    return '';
}

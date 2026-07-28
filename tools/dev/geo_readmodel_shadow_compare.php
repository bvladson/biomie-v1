<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\DataPlatform\Query\GeoQuery;
use BIomie\DataPlatform\ReadModel\GeoReadModelFactory;
use BIomie\Support\CliGuard;
use BIomie\Support\TenantContext;

$root = dirname(dirname(__DIR__));

require $root . '/app/Support/CliGuard.php';
CliGuard::requireCli();
require $root . '/app/Bootstrap.php';
Bootstrap::init();

$opts = getopt('', ['start:', 'end:', 'layer:', 'city:', 'cnae:', 'seller_id:', 'company:', 'format:', 'json']);
$json = isset($opts['json']) || (string) ($opts['format'] ?? '') === 'json';
$start = (string) ($opts['start'] ?? date('Y-01-01'));
$end = (string) ($opts['end'] ?? date('Y-m-d'));
$layer = (string) ($opts['layer'] ?? GeoQuery::DEFAULT_LAYER);
$company = trim((string) ($opts['company'] ?? ''));

try {
    $request = [
        'date_from' => $start,
        'date_to' => $end,
        'layer' => $layer,
    ];
    foreach (['city', 'cnae', 'seller_id'] as $key) {
        if (isset($opts[$key]) && trim((string) $opts[$key]) !== '') {
            $request[$key] = (string) $opts[$key];
        }
    }

    $config = new Config();
    [$pdo] = Database::getGeoConnection($config);

    // Sem tenant, o ReadModel devolve 0 enquanto o lado "direct" somava as 5
    // empresas — a ferramenta acusava divergencia de milhoes que nao existia.
    // --company e obrigatorio: comparar leitura escopada com total global nao
    // e comparacao, e ruido.
    $companyId = resolveCompanyId($pdo, $company);
    TenantContext::bootForCompany($pdo, $companyId);

    $query = GeoQuery::fromRequest($request);
    $geo = GeoReadModelFactory::make($config, $pdo);

    $readSummary = $geo->legacySummaryPayload($query)['summary'] ?? [];
    $readComposite = $geo->legacyCompositePayload($query)['summary'] ?? [];
    $direct = directGoldSummary($pdo, $query);

    $checks = [
        compareMoney('summary.revenue', (float) ($direct['revenue'] ?? 0), (float) ($readSummary['revenue'] ?? 0)),
        compareInt('summary.customers', (int) ($direct['customers'] ?? 0), (int) ($readSummary['customers'] ?? 0)),
        compareInt('summary.invoices', (int) ($direct['invoices'] ?? 0), (int) ($readSummary['invoices'] ?? 0)),
        compareInt('summary.rows_without_neighborhood', (int) ($direct['rows_without_neighborhood'] ?? 0), (int) ($readSummary['rows_without_neighborhood'] ?? 0)),
        compareMoney('composite.total_vendido', (float) ($direct['revenue'] ?? 0), (float) ($readComposite['total_vendido'] ?? 0)),
        compareInt('composite.clientes', (int) ($direct['customers'] ?? 0), (int) ($readComposite['clientes'] ?? 0)),
        compareInt('composite.notas', (int) ($direct['invoices'] ?? 0), (int) ($readComposite['notas'] ?? 0)),
    ];

    $failed = array_values(array_filter($checks, static fn (array $check): bool => !$check['pass']));
    $warned = array_values(array_filter($checks, static fn (array $check): bool => $check['warn']));
    $payload = [
        'ok' => $failed === [],
        'company' => $company,
        'company_id' => $companyId,
        'period' => ['start' => $start, 'end' => $end],
        'layer' => $layer,
        'source_contracts' => ['geo.sales_by_territory.v1', 'geo.map_payload.v1'],
        'direct_gold' => $direct,
        'checks' => $checks,
        'failed' => $failed,
        'warned' => $warned,
    ];

    if ($json) {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        echo 'Geo ReadModel shadow compare' . PHP_EOL;
        echo "Empresa: {$company} (id {$companyId})" . PHP_EOL;
        echo "Periodo: {$start} ate {$end} | layer: {$layer}" . PHP_EOL;
        echo 'Falha SO se readmodel > fatia da empresa; deficit e esperado.' . PHP_EOL;
        foreach ($checks as $check) {
            $status = !$check['pass'] ? 'FAIL' : ($check['warn'] ? 'WARN' : 'OK');
            echo "[{$status}] {$check['metric']}: fatia={$check['direct']} readmodel={$check['readmodel']}"
                . " diff={$check['diff']} sem_geo={$check['shortfall_pct']}%" . PHP_EOL;
        }
        if ($failed !== []) {
            echo 'FAIL: ' . count($failed) . ' metrica(s) acima da fatia da empresa — investigar escopo.' . PHP_EOL;
        } elseif ($warned !== []) {
            echo 'PASS com ressalva: ' . count($warned)
                . ' metrica(s) com mais de 20% sem georreferencia no layer.' . PHP_EOL;
        } else {
            echo 'PASS' . PHP_EOL;
        }
    }

    exit($failed === [] ? 0 : 2);
} catch (Throwable $exception) {
    $payload = [
        'ok' => false,
        'error' => 'geo_readmodel_shadow_compare_error',
        'message' => $exception->getMessage(),
    ];
    if ($json) {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        echo 'Erro: ' . $exception->getMessage() . PHP_EOL;
    }
    exit(1);
}

/**
 * Resolve slug ou id numerico da empresa. Falha ALTO quando ausente: o valor
 * default silencioso seria comparar empresa nenhuma com todas.
 */
function resolveCompanyId(PDO $pdo, string $company): int
{
    if ($company === '') {
        $rows = $pdo->query('SELECT slug FROM biomie_companies WHERE ativo = 1 ORDER BY id')
            ->fetchAll(PDO::FETCH_COLUMN) ?: [];
        throw new RuntimeException(
            '--company e obrigatorio (slug ou id). Ativas: ' . implode(', ', $rows)
        );
    }

    // Placeholders distintos de proposito: com prepares nao-emulados, reusar o
    // mesmo nome duas vezes devolve "Invalid parameter number".
    $stmt = $pdo->prepare(
        'SELECT id FROM biomie_companies
          WHERE ativo = 1 AND (slug = :slug OR CAST(id AS CHAR) = :id_txt)
          LIMIT 1'
    );
    $stmt->execute([':slug' => $company, ':id_txt' => $company]);
    $id = $stmt->fetchColumn();
    if ($id === false) {
        throw new RuntimeException("Empresa nao encontrada ou inativa: {$company}");
    }

    return (int) $id;
}

/** @return array<string,mixed> */
function directGoldSummary(PDO $pdo, GeoQuery $query): array
{
    // TenantContext::sqlFilter() e o mesmo choke-point que o ReadModel usa;
    // sem ele os dois lados mediriam universos diferentes.
    $where = [
        'competencia BETWEEN :start_comp AND :end_comp',
        TenantContext::sqlFilter(),
    ];
    $params = [
        'start_comp' => $query->startComp,
        'end_comp' => $query->endComp,
    ];

    if (!$query->isMetroNeighborhoodLayer() && !$query->isMultiCityLayer() && $query->city !== null) {
        $where[] = "LOWER(TRIM(COALESCE(cidade_norm, ''))) = :city";
        $params['city'] = $query->city;
    }
    if ($query->neighborhood !== null) {
        $where[] = 'LOWER(TRIM(bairro_norm)) = :neighborhood';
        $params['neighborhood'] = $query->neighborhood;
    }
    if ($query->cnae !== null) {
        if (strtoupper($query->cnae) === 'SEM_CNAE') {
            $where[] = "(cnae_codigo IS NULL OR TRIM(cnae_codigo) = '')";
        } else {
            $where[] = '(cnae_codigo = :cnae OR LOWER(cnae_descricao) LIKE :cnae_like)';
            $params['cnae'] = $query->cnae;
            $params['cnae_like'] = '%' . mb_strtolower($query->cnae) . '%';
        }
    }
    if ($query->sellerId !== null && columnExists($pdo, 'mart_compradores_por_geo', 'nome_vendedor')) {
        $where[] = 'LOWER(nome_vendedor) LIKE :seller_pct';
        $params['seller_pct'] = '%' . mb_strtolower(trim($query->sellerId)) . '%';
    }

    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(faturamento), 0) AS revenue,
            COUNT(DISTINCT COALESCE(CAST(codigo_cliente_omie AS CHAR), documento, cliente_nome)) AS customers,
            COALESCE(SUM(qtd_notas), 0) AS invoices,
            SUM(CASE WHEN bairro_norm IS NULL OR TRIM(bairro_norm) = '' THEN 1 ELSE 0 END) AS rows_without_neighborhood
        FROM mart_compradores_por_geo
        WHERE " . implode(' AND ', $where)
    );
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value);
    }
    $stmt->execute();

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Comparacao ASSIMETRICA, e de proposito.
 *
 * O lado "direct" e a fatia BRUTA da empresa no mart. O ReadModel filtra alem
 * disso (o layer composto do Ceara so posiciona o que consegue georreferenciar),
 * entao readmodel < direct e esperado e NAO e defeito. O unico resultado
 * impossivel sem contaminacao e readmodel > direct: a leitura escopada nunca
 * pode superar tudo o que a empresa possui.
 *
 * Tratar o deficit como falha foi o bug que manteve o TenantIsolationGuard
 * preso em falso positivo (Probe 3, corrigido em 2026-07-28): comparar
 * universos diferentes e chamar a diferenca de vazamento.
 *
 * @return array{metric:string,direct:string,readmodel:string,diff:string,shortfall_pct:string,pass:bool,warn:bool}
 */
function compareMoney(string $metric, float $direct, float $readmodel): array
{
    return buildCheck($metric, $direct, $readmodel, 0.01, 2);
}

/** @return array{metric:string,direct:string,readmodel:string,diff:string,shortfall_pct:string,pass:bool,warn:bool} */
function compareInt(string $metric, int $direct, int $readmodel): array
{
    return buildCheck($metric, (float) $direct, (float) $readmodel, 0.0, 0);
}

/** @return array{metric:string,direct:string,readmodel:string,diff:string,shortfall_pct:string,pass:bool,warn:bool} */
function buildCheck(string $metric, float $direct, float $readmodel, float $tolerance, int $decimals): array
{
    $diff = round($readmodel - $direct, 4);
    $shortfall = $direct > 0 ? max(0.0, ($direct - $readmodel) * 100.0 / $direct) : 0.0;

    return [
        'metric' => $metric,
        'direct' => number_format($direct, $decimals, '.', ''),
        'readmodel' => number_format($readmodel, $decimals, '.', ''),
        'diff' => number_format($diff, 4, '.', ''),
        'shortfall_pct' => number_format($shortfall, 1, '.', ''),
        // Falha SO quando a leitura escopada supera a fatia da empresa.
        'pass' => $diff <= $tolerance,
        // Deficit grande nao e vazamento, mas indica dado sem georreferencia.
        'warn' => $shortfall > 20.0,
    ];
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
    );
    $stmt->execute(['table' => $table, 'column' => $column]);
    return (int) $stmt->fetchColumn() > 0;
}

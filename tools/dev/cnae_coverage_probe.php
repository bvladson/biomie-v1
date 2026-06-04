<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$args = cnaeProbeArgs(array_slice($_SERVER['argv'] ?? [], 1));

try {
    $config = new Config();
    [$pdo, $connection] = Database::getGeoConnection($config);

    $start = $args['date_from'];
    $end = $args['date_to'];
    $startComp = substr($start, 0, 7);
    $endComp = substr($end, 0, 7);

    $payload = [
        'ok' => true,
        'connection' => $connection,
        'period' => ['date_from' => $start, 'date_to' => $end, 'start_comp' => $startComp, 'end_comp' => $endComp],
        'schema' => [
            'biomie_cnae_dim' => cnaeProbeTable($pdo, 'biomie_cnae_dim'),
            'biomie_clientes_dim' => cnaeProbeTable($pdo, 'biomie_clientes_dim'),
            'mart_vendas_por_cnae_cnpj' => cnaeProbeTable($pdo, 'mart_vendas_por_cnae_cnpj'),
            'mart_compradores_por_geo' => cnaeProbeTable($pdo, 'mart_compradores_por_geo'),
        ],
        'dimension' => cnaeProbeDimension($pdo),
        'clients' => cnaeProbeClients($pdo),
        'sales_mart' => cnaeProbeSalesMart($pdo, $startComp, $endComp),
        'geo_mart' => cnaeProbeGeoMart($pdo, $startComp, $endComp),
        'document_segments' => cnaeProbeDocumentSegments($pdo, $startComp, $endComp),
        'top_unknown' => cnaeProbeUnknownCustomers($pdo, $startComp, $endComp, $args['sample']),
        'generated_at' => date(DATE_ATOM),
    ];

    $payload['status'] = cnaeProbeStatus($payload);
    $payload['artifacts'] = cnaeProbeArtifacts($payload);

    if ($args['format'] === 'json') {
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
    } else {
        cnaeProbeHuman($payload);
    }

    exit($payload['status'] === 'ok' ? 0 : 2);
} catch (Throwable $exception) {
    $payload = ['ok' => false, 'error' => $exception->getMessage()];
    if ($args['format'] === 'json') {
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
    } else {
        fwrite(STDERR, 'ERRO probe CNAE: ' . $exception->getMessage() . PHP_EOL);
    }
    exit(1);
}

/**
 * @param list<string> $argv
 * @return array{date_from:string,date_to:string,format:string,sample:int}
 */
function cnaeProbeArgs(array $argv): array
{
    $args = [
        'date_from' => '2026-01-01',
        'date_to' => date('Y-m-d'),
        'format' => 'human',
        'sample' => 10,
    ];

    foreach ($argv as $arg) {
        if ($arg === '--format=json' || $arg === '--json') {
            $args['format'] = 'json';
            continue;
        }
        if (str_starts_with($arg, '--date-from=')) {
            $args['date_from'] = substr($arg, 12);
            continue;
        }
        if (str_starts_with($arg, '--date-to=')) {
            $args['date_to'] = substr($arg, 10);
            continue;
        }
        if (str_starts_with($arg, '--sample=')) {
            $args['sample'] = max(0, (int) substr($arg, 9));
        }
    }

    return $args;
}

function cnaeProbeTable(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

function cnaeProbeColumn(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

/** @return array<string,mixed> */
function cnaeProbeDimension(PDO $pdo): array
{
    if (!cnaeProbeTable($pdo, 'biomie_cnae_dim')) {
        return ['available' => false];
    }

    $officialWhere = cnaeProbeColumn($pdo, 'biomie_cnae_dim', 'origem')
        ? "origem = 'concla_ibge'"
        : '0=1';
    $hasHierarchy = cnaeProbeColumn($pdo, 'biomie_cnae_dim', 'secao');

    $row = $pdo->query(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN cnae_codigo_normalizado REGEXP '^[0-9]{7}$' THEN 1 ELSE 0 END) AS valid_codes,
            SUM(CASE WHEN NOT (cnae_codigo_normalizado REGEXP '^[0-9]{7}$') OR cnae_codigo_normalizado IS NULL THEN 1 ELSE 0 END) AS invalid_codes,
            SUM(CASE WHEN {$officialWhere} THEN 1 ELSE 0 END) AS official_rows,
            SUM(CASE WHEN cnae_descricao IS NULL OR TRIM(cnae_descricao) = '' THEN 1 ELSE 0 END) AS missing_description"
        . ($hasHierarchy ? ", COUNT(DISTINCT NULLIF(secao, '')) AS sections" : ", 0 AS sections") .
        " FROM biomie_cnae_dim"
    )->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'available' => true,
        'total' => (int) ($row['total'] ?? 0),
        'valid_codes' => (int) ($row['valid_codes'] ?? 0),
        'invalid_codes' => (int) ($row['invalid_codes'] ?? 0),
        'official_rows' => (int) ($row['official_rows'] ?? 0),
        'missing_description' => (int) ($row['missing_description'] ?? 0),
        'sections' => (int) ($row['sections'] ?? 0),
    ];
}

/** @return array<string,mixed> */
function cnaeProbeClients(PDO $pdo): array
{
    if (!cnaeProbeTable($pdo, 'biomie_clientes_dim')) {
        return ['available' => false];
    }

    $hasSource = cnaeProbeColumn($pdo, 'biomie_clientes_dim', 'cnae_source');
    $sourceSelect = $hasSource
        ? "cnae_source, COUNT(*) AS total"
        : "'legacy' AS cnae_source, COUNT(*) AS total";

    $row = $pdo->query(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN d.cnae_codigo IS NOT NULL AND TRIM(d.cnae_codigo) != '' THEN 1 ELSE 0 END) AS with_cnae,
            SUM(CASE WHEN d.cnae_codigo IS NULL OR TRIM(d.cnae_codigo) = '' THEN 1 ELSE 0 END) AS without_cnae,
            SUM(CASE WHEN REGEXP_REPLACE(COALESCE(d.cnae_codigo, ''), '[^0-9]', '') REGEXP '^[0-9]{7}$' THEN 1 ELSE 0 END) AS valid_cnae,
            SUM(CASE WHEN c.cnae_codigo IS NOT NULL THEN 1 ELSE 0 END) AS matched_dim,
            SUM(CASE WHEN d.cnae_codigo IS NOT NULL AND TRIM(d.cnae_codigo) != '' AND c.cnae_codigo IS NULL THEN 1 ELSE 0 END) AS unmatched_dim
         FROM biomie_clientes_dim d
         LEFT JOIN biomie_cnae_dim c ON c.cnae_codigo_normalizado = REGEXP_REPLACE(COALESCE(d.cnae_codigo, ''), '[^0-9]', '')"
    )->fetch(PDO::FETCH_ASSOC) ?: [];

    $sources = [];
    try {
        $stmt = $pdo->query(
            "SELECT {$sourceSelect}
             FROM biomie_clientes_dim
             WHERE cnae_codigo IS NOT NULL AND TRIM(cnae_codigo) != ''
             GROUP BY cnae_source
             ORDER BY total DESC"
        );
        foreach (($stmt?->fetchAll(PDO::FETCH_ASSOC) ?: []) as $sourceRow) {
            $sources[] = [
                'source' => (string) (($sourceRow['cnae_source'] ?? '') ?: 'sem_origem'),
                'total' => (int) ($sourceRow['total'] ?? 0),
            ];
        }
    } catch (Throwable) {
    }

    return [
        'available' => true,
        'total' => (int) ($row['total'] ?? 0),
        'with_cnae' => (int) ($row['with_cnae'] ?? 0),
        'without_cnae' => (int) ($row['without_cnae'] ?? 0),
        'valid_cnae' => (int) ($row['valid_cnae'] ?? 0),
        'matched_dim' => (int) ($row['matched_dim'] ?? 0),
        'unmatched_dim' => (int) ($row['unmatched_dim'] ?? 0),
        'coverage_pct' => cnaeProbePct((int) ($row['with_cnae'] ?? 0), (int) ($row['total'] ?? 0)),
        'matched_pct' => cnaeProbePct((int) ($row['matched_dim'] ?? 0), max(1, (int) ($row['with_cnae'] ?? 0))),
        'sources' => $sources,
    ];
}

/** @return array<string,mixed> */
function cnaeProbeSalesMart(PDO $pdo, string $startComp, string $endComp): array
{
    if (!cnaeProbeTable($pdo, 'mart_vendas_por_cnae_cnpj')) {
        return ['available' => false];
    }

    $row = cnaeProbeFetch($pdo,
        "SELECT
            COUNT(*) AS rows_total,
            COUNT(DISTINCT codigo_cliente_omie) AS clients,
            SUM(qtd_notas) AS notes,
            SUM(faturamento) AS revenue,
            SUM(CASE WHEN cnae_codigo IS NULL OR TRIM(cnae_codigo) = '' OR cnae_codigo = 'SEM_CNAE' THEN 1 ELSE 0 END) AS rows_without_cnae,
            SUM(CASE WHEN cnae_descricao IS NULL OR TRIM(cnae_descricao) = '' OR LOWER(TRIM(cnae_descricao)) IN ('sem cnae informado', 'cnae nao informado', 'cnae não informado') THEN 1 ELSE 0 END) AS rows_without_description
         FROM mart_vendas_por_cnae_cnpj
         WHERE competencia BETWEEN :s AND :e",
        [':s' => $startComp, ':e' => $endComp]
    );

    return [
        'available' => true,
        'rows_total' => (int) ($row['rows_total'] ?? 0),
        'clients' => (int) ($row['clients'] ?? 0),
        'notes' => (int) ($row['notes'] ?? 0),
        'revenue' => (float) ($row['revenue'] ?? 0),
        'rows_without_cnae' => (int) ($row['rows_without_cnae'] ?? 0),
        'rows_without_description' => (int) ($row['rows_without_description'] ?? 0),
    ];
}

/** @return array<string,mixed> */
function cnaeProbeGeoMart(PDO $pdo, string $startComp, string $endComp): array
{
    if (!cnaeProbeTable($pdo, 'mart_compradores_por_geo') || !cnaeProbeColumn($pdo, 'mart_compradores_por_geo', 'cnae_codigo')) {
        return ['available' => false];
    }

    $row = cnaeProbeFetch($pdo,
        "SELECT
            COUNT(*) AS rows_total,
            COUNT(DISTINCT codigo_cliente_omie) AS clients,
            SUM(qtd_notas) AS notes,
            SUM(faturamento) AS revenue,
            SUM(CASE WHEN cnae_codigo IS NULL OR TRIM(cnae_codigo) = '' OR cnae_codigo = 'SEM_CNAE' THEN 1 ELSE 0 END) AS rows_without_cnae,
            SUM(CASE WHEN cnae_descricao IS NULL OR TRIM(cnae_descricao) = '' OR LOWER(TRIM(cnae_descricao)) IN ('sem cnae informado', 'cnae nao informado', 'cnae não informado') THEN 1 ELSE 0 END) AS rows_without_description
         FROM mart_compradores_por_geo
         WHERE competencia BETWEEN :s AND :e",
        [':s' => $startComp, ':e' => $endComp]
    );

    return [
        'available' => true,
        'rows_total' => (int) ($row['rows_total'] ?? 0),
        'clients' => (int) ($row['clients'] ?? 0),
        'notes' => (int) ($row['notes'] ?? 0),
        'revenue' => (float) ($row['revenue'] ?? 0),
        'rows_without_cnae' => (int) ($row['rows_without_cnae'] ?? 0),
        'rows_without_description' => (int) ($row['rows_without_description'] ?? 0),
    ];
}

/** @return list<array<string,mixed>> */
function cnaeProbeDocumentSegments(PDO $pdo, string $startComp, string $endComp): array
{
    if (!cnaeProbeTable($pdo, 'mart_compradores_por_geo')) {
        return [];
    }

    $typeExpr = cnaeProbeDocumentTypeSql('documento');
    $stmt = $pdo->prepare(
        "SELECT
            {$typeExpr} AS document_type,
            COUNT(*) AS rows_total,
            COUNT(DISTINCT COALESCE(CAST(codigo_cliente_omie AS CHAR), documento, cliente_nome)) AS clientes,
            SUM(qtd_notas) AS notas,
            SUM(faturamento) AS faturamento,
            SUM(CASE WHEN cnae_codigo IS NULL OR TRIM(cnae_codigo) = '' OR cnae_codigo = 'SEM_CNAE' THEN 1 ELSE 0 END) AS linhas_sem_cnae,
            SUM(CASE WHEN cnae_codigo IS NULL OR TRIM(cnae_codigo) = '' OR cnae_codigo = 'SEM_CNAE' THEN faturamento ELSE 0 END) AS faturamento_sem_cnae,
            COUNT(DISTINCT CASE WHEN cnae_codigo IS NULL OR TRIM(cnae_codigo) = '' OR cnae_codigo = 'SEM_CNAE' THEN COALESCE(CAST(codigo_cliente_omie AS CHAR), documento, cliente_nome) END) AS clientes_sem_cnae
         FROM mart_compradores_por_geo
         WHERE competencia BETWEEN :s AND :e
         GROUP BY document_type
         ORDER BY faturamento_sem_cnae DESC, faturamento DESC"
    );
    $stmt->execute([':s' => $startComp, ':e' => $endComp]);

    return array_map(static function (array $row): array {
        $clientes = (int) ($row['clientes'] ?? 0);
        $clientesSem = (int) ($row['clientes_sem_cnae'] ?? 0);

        return [
            'document_type' => (string) ($row['document_type'] ?? 'unknown'),
            'label' => cnaeProbeDocumentLabel((string) ($row['document_type'] ?? 'unknown')),
            'rows_total' => (int) ($row['rows_total'] ?? 0),
            'clientes' => $clientes,
            'notas' => (int) ($row['notas'] ?? 0),
            'faturamento' => (float) ($row['faturamento'] ?? 0),
            'linhas_sem_cnae' => (int) ($row['linhas_sem_cnae'] ?? 0),
            'clientes_sem_cnae' => $clientesSem,
            'faturamento_sem_cnae' => (float) ($row['faturamento_sem_cnae'] ?? 0),
            'coverage_pct' => cnaeProbePct($clientes - $clientesSem, max(1, $clientes)),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/** @return list<array<string,mixed>> */
function cnaeProbeUnknownCustomers(PDO $pdo, string $startComp, string $endComp, int $limit): array
{
    if ($limit <= 0 || !cnaeProbeTable($pdo, 'mart_compradores_por_geo')) {
        return [];
    }

    $stmt = $pdo->prepare(
        "SELECT
            codigo_cliente_omie,
            MAX(cliente_nome) AS cliente_nome,
            MAX(documento) AS documento,
            " . cnaeProbeDocumentTypeSql('MAX(documento)') . " AS document_type,
            MAX(cidade_norm) AS cidade,
            MAX(bairro_norm) AS bairro,
            SUM(qtd_notas) AS notas,
            SUM(faturamento) AS faturamento
         FROM mart_compradores_por_geo
         WHERE competencia BETWEEN :s AND :e
           AND (cnae_codigo IS NULL OR TRIM(cnae_codigo) = '' OR cnae_codigo = 'SEM_CNAE')
         GROUP BY codigo_cliente_omie
         ORDER BY faturamento DESC
         LIMIT {$limit}"
    );
    $stmt->execute([':s' => $startComp, ':e' => $endComp]);

    return array_map(static fn (array $row): array => [
        'codigo_cliente_omie' => $row['codigo_cliente_omie'] !== null ? (int) $row['codigo_cliente_omie'] : null,
        'cliente_nome' => (string) ($row['cliente_nome'] ?? ''),
        'documento' => cnaeProbeMask((string) ($row['documento'] ?? '')),
        'document_type' => (string) ($row['document_type'] ?? 'unknown'),
        'document_label' => cnaeProbeDocumentLabel((string) ($row['document_type'] ?? 'unknown')),
        'cidade' => (string) ($row['cidade'] ?? ''),
        'bairro' => (string) ($row['bairro'] ?? ''),
        'notas' => (int) ($row['notas'] ?? 0),
        'faturamento' => (float) ($row['faturamento'] ?? 0),
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/** @param array<string,mixed> $params */
function cnaeProbeFetch(PDO $pdo, string $sql, array $params): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
}

function cnaeProbePct(int $num, int $den): float
{
    if ($den <= 0) {
        return 0.0;
    }
    return round(($num / $den) * 100, 2);
}

function cnaeProbeMask(string $doc): string
{
    $digits = preg_replace('/\D+/', '', $doc) ?? '';
    if ($digits === '') {
        return '';
    }
    if (strlen($digits) === 11) {
        return '***.***.***-**';
    }
    if (strlen($digits) === 14) {
        return substr($digits, 0, 2) . '.' . substr($digits, 2, 3) . '.***/****-**';
    }
    return substr($digits, 0, 3) . '***' . substr($digits, -2);
}

function cnaeProbeDocumentTypeSql(string $expr): string
{
    return "CASE
        WHEN CHAR_LENGTH(REGEXP_REPLACE(COALESCE({$expr}, ''), '[^0-9]', '')) = 14 THEN 'cnpj'
        WHEN CHAR_LENGTH(REGEXP_REPLACE(COALESCE({$expr}, ''), '[^0-9]', '')) = 11 THEN 'cpf'
        ELSE 'unknown'
    END";
}

function cnaeProbeDocumentLabel(string $type): string
{
    return match ($type) {
        'cnpj' => 'CNPJ / B2B',
        'cpf' => 'CPF',
        default => 'Documento pendente',
    };
}

/** @param array<string,mixed> $payload */
function cnaeProbeStatus(array $payload): string
{
    $dim = $payload['dimension'] ?? [];
    $clients = $payload['clients'] ?? [];
    if (($dim['available'] ?? false) !== true || (int) ($dim['official_rows'] ?? 0) === 0) {
        return 'official_dimension_required';
    }
    if ((int) ($dim['invalid_codes'] ?? 0) > 0) {
        return 'invalid_dimension_codes';
    }
    if ((int) ($clients['without_cnae'] ?? 0) > 0) {
        return 'client_cnae_enrichment_required';
    }
    return 'ok';
}

/** @param array<string,mixed> $payload */
function cnaeProbeArtifacts(array $payload): array
{
    $dayDir = Bootstrap::rootPath('storage/diagnostics/' . date('Ymd'));
    if (!is_dir($dayDir)) {
        mkdir($dayDir, 0775, true);
    }
    $stamp = date('Ymd_His');
    $path = $dayDir . '/cnae_coverage_probe_' . $stamp . '.json';
    file_put_contents(
        $path,
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL
    );
    return ['json' => $path];
}

/** @param array<string,mixed> $payload */
function cnaeProbeHuman(array $payload): void
{
    $dim = $payload['dimension'] ?? [];
    $clients = $payload['clients'] ?? [];
    $mart = $payload['sales_mart'] ?? [];
    $geo = $payload['geo_mart'] ?? [];
    $segments = is_array($payload['document_segments'] ?? null) ? $payload['document_segments'] : [];

    echo 'CNAE.R2.3 coverage probe' . PHP_EOL;
    echo 'Status: ' . (string) ($payload['status'] ?? 'unknown') . PHP_EOL;
    echo 'Periodo: ' . (string) ($payload['period']['date_from'] ?? '') . ' a ' . (string) ($payload['period']['date_to'] ?? '') . PHP_EOL;
    echo PHP_EOL;
    echo 'Dimensao CNAE: ' . (int) ($dim['official_rows'] ?? 0) . ' oficiais / ' . (int) ($dim['total'] ?? 0) . ' total';
    echo ' | invalidos: ' . (int) ($dim['invalid_codes'] ?? 0);
    echo ' | sem descricao: ' . (int) ($dim['missing_description'] ?? 0) . PHP_EOL;
    echo 'Clientes: ' . (int) ($clients['with_cnae'] ?? 0) . '/' . (int) ($clients['total'] ?? 0);
    echo ' com CNAE (' . number_format((float) ($clients['coverage_pct'] ?? 0), 2, ',', '.') . '%)';
    echo ' | batem na dimensao: ' . (int) ($clients['matched_dim'] ?? 0) . PHP_EOL;
    echo 'Mart CNAE: linhas=' . (int) ($mart['rows_total'] ?? 0);
    echo ' | receita=R$ ' . number_format((float) ($mart['revenue'] ?? 0), 2, ',', '.');
    echo ' | sem descricao=' . (int) ($mart['rows_without_description'] ?? 0) . PHP_EOL;
    echo 'Geo mart: linhas=' . (int) ($geo['rows_total'] ?? 0);
    echo ' | receita=R$ ' . number_format((float) ($geo['revenue'] ?? 0), 2, ',', '.');
    echo ' | sem CNAE=' . (int) ($geo['rows_without_cnae'] ?? 0) . PHP_EOL;

    if ($segments !== []) {
        echo PHP_EOL . 'Impacto sem CNAE por tipo de documento:' . PHP_EOL;
        foreach ($segments as $segment) {
            echo '- ' . (string) ($segment['label'] ?? $segment['document_type'] ?? 'Documento');
            echo ' | clientes sem CNAE=' . (int) ($segment['clientes_sem_cnae'] ?? 0);
            echo ' | receita sem CNAE=R$ ' . number_format((float) ($segment['faturamento_sem_cnae'] ?? 0), 2, ',', '.');
            echo ' | cobertura=' . number_format((float) ($segment['coverage_pct'] ?? 0), 2, ',', '.') . '%' . PHP_EOL;
        }
    }

    $unknown = $payload['top_unknown'] ?? [];
    if (is_array($unknown) && $unknown !== []) {
        echo PHP_EOL . 'Top clientes sem CNAE no geo mart:' . PHP_EOL;
        foreach ($unknown as $row) {
            echo '- [' . (string) ($row['document_label'] ?? 'Documento') . '] ' . (string) ($row['cliente_nome'] ?? '') . ' | R$ ' . number_format((float) ($row['faturamento'] ?? 0), 2, ',', '.') . ' | ' . (int) ($row['notas'] ?? 0) . ' notas' . PHP_EOL;
        }
    }

    $artifacts = $payload['artifacts'] ?? [];
    if (is_array($artifacts) && isset($artifacts['json'])) {
        echo PHP_EOL . 'Artefato: ' . $artifacts['json'] . PHP_EOL;
    }
}

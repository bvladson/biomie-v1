<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Support\BiVisibilityScope;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$opts = getopt('', ['start:', 'end:', 'pretty', 'json', 'operation-scope:', 'include-examples']);
$json = isset($opts['json']);
$pretty = isset($opts['pretty']) || !$json;
$start = (string) ($opts['start'] ?? date('Y-m-01'));
$end = (string) ($opts['end'] ?? date('Y-m-d'));
$scope = pmxNormalizeScope((string) ($opts['operation-scope'] ?? 'orc-pd'));
$includeExamples = isset($opts['include-examples']);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
    fwrite(STDERR, "Use --start=YYYY-MM-DD --end=YYYY-MM-DD.\n");
    exit(2);
}

$config = new Config();
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo = Database::connect($config, $connection);

$report = buildProductMixScopeReport($pdo, $start, $end, $scope, $includeExamples);

if ($json) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($report['status'] === 'FAIL' ? 1 : 0);
}

pmxPrintPretty($report);
exit($report['status'] === 'FAIL' ? 1 : 0);

function pmxNormalizeScope(string $scope): string
{
    $scope = strtolower(trim($scope));
    return match ($scope) {
        'remessa', 'remessas', 'remittance' => 'remittance',
        'all', 'todas', 'todos' => 'all',
        default => 'commercial',
    };
}

function pmxScopeLabel(string $scope): string
{
    return match ($scope) {
        'remittance' => 'Remessas',
        'all' => 'Todas',
        default => 'Orc-PD',
    };
}

function pmxColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
    );
    $stmt->execute(['table_name' => $table, 'column_name' => $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function pmxScopeCondition(PDO $pdo, string $scope, string $alias = 'n'): string
{
    if (!pmxColumnExists($pdo, 'biomie_notas_fiscais_canonicas', 'operation_scope')) {
        return '1=1';
    }
    $prefix = $alias !== '' ? "{$alias}." : '';
    return match ($scope) {
        'remittance' => "{$prefix}operation_scope = 'remittance'",
        'all' => "{$prefix}operation_scope IN ('commercial', 'remittance')",
        default => "{$prefix}operation_scope = 'commercial'",
    };
}

function pmxVisibilityCondition(PDO $pdo, string $alias = 'n'): string
{
    return BiVisibilityScope::getCondition($pdo, 'biomie_notas_fiscais_canonicas', $alias);
}

/**
 * @return array<string,mixed>
 */
function buildProductMixScopeReport(PDO $pdo, string $start, string $end, string $scope, bool $includeExamples): array
{
    $hasScope = pmxColumnExists($pdo, 'biomie_notas_fiscais_canonicas', 'operation_scope');
    $hasVisibility = pmxColumnExists($pdo, 'biomie_notas_fiscais_canonicas', 'is_biomie_commercial_visible');
    $visibilityExpr = $hasVisibility ? 'COALESCE(n.is_biomie_commercial_visible, 1)' : '1';
    $scopeExpr = $hasScope ? "COALESCE(n.operation_scope, 'unknown')" : "'unknown'";
    $dateExpr = 'COALESCE(n.data_competencia, n.data_emissao)';

    $byScopeSql = <<<SQL
        SELECT
            {$scopeExpr} AS operation_scope,
            {$visibilityExpr} AS commercial_visible,
            COUNT(DISTINCT i.codigo_produto) AS products_count,
            COUNT(DISTINCT n.nota_key) AS invoices_count,
            COALESCE(SUM(i.valor_total), 0) AS revenue,
            COALESCE(SUM(i.quantidade), 0) AS quantity
        FROM biomie_notas_fiscais_itens_canonicos i
        INNER JOIN biomie_notas_fiscais_canonicas n ON n.nota_key = i.nota_key
        WHERE n.status_fiscal = 'autorizada'
          AND {$dateExpr} BETWEEN :start AND :end
        GROUP BY operation_scope, commercial_visible
        ORDER BY revenue DESC
    SQL;
    $stmt = $pdo->prepare($byScopeSql);
    $stmt->execute(['start' => $start, 'end' => $end]);
    $byScope = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $officialWhere = [
        "n.status_fiscal = 'autorizada'",
        "{$dateExpr} BETWEEN :start AND :end",
        pmxScopeCondition($pdo, $scope, 'n'),
        pmxVisibilityCondition($pdo, 'n'),
    ];

    $topSql = <<<SQL
        SELECT
            NULLIF(TRIM(COALESCE(i.codigo_produto, '')), '') AS product_code,
            COALESCE(NULLIF(TRIM(i.descricao_produto), ''), 'Produto sem descricao') AS product_name,
            COALESCE(SUM(i.quantidade), 0) AS quantity,
            COALESCE(SUM(i.valor_total), 0) AS revenue,
            COUNT(DISTINCT n.nota_key) AS invoices_count,
            COUNT(DISTINCT n.codigo_cliente_omie) AS customers_count,
            COUNT(DISTINCT n.codigo_vendedor_omie) AS vendors_count,
            MIN(n.numero_nf) AS example_numero_nf,
            MIN(n.nota_key) AS example_nota_key,
            MIN(n.nome_vendedor) AS example_vendor
        FROM biomie_notas_fiscais_itens_canonicos i
        INNER JOIN biomie_notas_fiscais_canonicas n ON n.nota_key = i.nota_key
        WHERE {$officialWhere[0]} AND {$officialWhere[1]} AND {$officialWhere[2]} AND {$officialWhere[3]}
        GROUP BY product_code, product_name
        ORDER BY revenue DESC, quantity DESC, product_name ASC
        LIMIT 15
    SQL;
    $stmt = $pdo->prepare($topSql);
    $stmt->execute(['start' => $start, 'end' => $end]);
    $topProducts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $legacyLeakSql = <<<SQL
        SELECT
            COUNT(DISTINCT n.nota_key) AS invoices_count,
            COUNT(DISTINCT i.codigo_produto) AS products_count,
            COALESCE(SUM(i.valor_total), 0) AS revenue
        FROM biomie_notas_fiscais_itens_canonicos i
        INNER JOIN biomie_notas_fiscais_canonicas n ON n.nota_key = i.nota_key
        WHERE n.status_fiscal = 'autorizada'
          AND {$dateExpr} BETWEEN :start AND :end
          AND {$scopeExpr} = 'commercial'
          AND NOT ({$visibilityExpr} = 1)
    SQL;
    $stmt = $pdo->prepare($legacyLeakSql);
    $stmt->execute(['start' => $start, 'end' => $end]);
    $legacyWeakScopeLeak = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $officialLeakSql = <<<SQL
        SELECT COUNT(*) AS leaks
        FROM biomie_notas_fiscais_itens_canonicos i
        INNER JOIN biomie_notas_fiscais_canonicas n ON n.nota_key = i.nota_key
        WHERE {$officialWhere[0]} AND {$officialWhere[1]} AND {$officialWhere[2]} AND {$officialWhere[3]}
          AND NOT ({$visibilityExpr} = 1)
    SQL;
    $stmt = $pdo->prepare($officialLeakSql);
    $stmt->execute(['start' => $start, 'end' => $end]);
    $officialLicitacaoLeaks = (int) $stmt->fetchColumn();

    $remittanceLeak = 0;
    if ($hasScope && $scope === 'commercial') {
        $stmt = $pdo->prepare(<<<SQL
            SELECT COUNT(*)
            FROM biomie_notas_fiscais_itens_canonicos i
            INNER JOIN biomie_notas_fiscais_canonicas n ON n.nota_key = i.nota_key
            WHERE {$officialWhere[0]} AND {$officialWhere[1]} AND {$officialWhere[2]} AND {$officialWhere[3]}
              AND n.operation_scope = 'remittance'
        SQL);
        $stmt->execute(['start' => $start, 'end' => $end]);
        $remittanceLeak = (int) $stmt->fetchColumn();
    }

    $examples = [];
    if ($includeExamples && (float) ($legacyWeakScopeLeak['revenue'] ?? 0) > 0) {
        $stmt = $pdo->prepare(<<<SQL
            SELECT
                n.numero_nf,
                n.nota_key,
                n.nome_vendedor,
                i.codigo_produto,
                i.descricao_produto,
                i.valor_total
            FROM biomie_notas_fiscais_itens_canonicos i
            INNER JOIN biomie_notas_fiscais_canonicas n ON n.nota_key = i.nota_key
            WHERE n.status_fiscal = 'autorizada'
              AND {$dateExpr} BETWEEN :start AND :end
              AND {$scopeExpr} = 'commercial'
              AND NOT ({$visibilityExpr} = 1)
            ORDER BY i.valor_total DESC
            LIMIT 10
        SQL);
        $stmt->execute(['start' => $start, 'end' => $end]);
        $examples = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $failures = [];
    if ($officialLicitacaoLeaks > 0) {
        $failures[] = 'official_filter_has_licitacao_leak';
    }
    if ($scope === 'commercial' && $remittanceLeak > 0) {
        $failures[] = 'official_commercial_scope_has_remittance_leak';
    }

    return [
        'ok' => $failures === [],
        'status' => $failures === [] ? 'OK' : 'FAIL',
        'generated_at' => date(DATE_ATOM),
        'read_only' => true,
        'period' => ['start' => $start, 'end' => $end],
        'scope' => $scope,
        'scope_label' => pmxScopeLabel($scope),
        'classification' => $failures === [] ? 'product_mix_scope_ok' : implode(',', $failures),
        'columns' => [
            'operation_scope' => $hasScope,
            'is_biomie_commercial_visible' => $hasVisibility,
        ],
        'by_scope' => $byScope,
        'official_filter' => [
            'licitacao_leaks' => $officialLicitacaoLeaks,
            'remittance_leaks_in_orc_pd' => $remittanceLeak,
        ],
        'legacy_scope_only' => [
            'would_include_licitacao_invoices' => (int) ($legacyWeakScopeLeak['invoices_count'] ?? 0),
            'would_include_licitacao_products' => (int) ($legacyWeakScopeLeak['products_count'] ?? 0),
            'would_include_licitacao_revenue' => (float) ($legacyWeakScopeLeak['revenue'] ?? 0),
        ],
        'top_products_official' => $topProducts,
        'examples' => $examples,
        'recommendations' => $failures === []
            ? ['Manter BiVisibilityScope no ProductMixReadRepository e nos endpoints de produtos.']
            : ['Bloquear release ate remover vazamento de escopo no Mix Faturado.'],
    ];
}

/**
 * @param array<string,mixed> $report
 */
function pmxPrintPretty(array $report): void
{
    echo PHP_EOL . 'Product Mix Scope Audit' . PHP_EOL;
    echo str_repeat('-', 72) . PHP_EOL;
    echo 'Status: ' . $report['status'] . PHP_EOL;
    echo 'Periodo: ' . $report['period']['start'] . ' ate ' . $report['period']['end'] . PHP_EOL;
    echo 'Escopo: ' . $report['scope_label'] . ' (' . $report['scope'] . ')' . PHP_EOL;
    echo 'Classificacao: ' . $report['classification'] . PHP_EOL . PHP_EOL;

    echo 'Colunas: operation_scope=' . ($report['columns']['operation_scope'] ? 'sim' : 'nao')
        . ', is_biomie_commercial_visible=' . ($report['columns']['is_biomie_commercial_visible'] ? 'sim' : 'nao') . PHP_EOL;
    echo 'Filtro oficial: licitacao_leaks=' . $report['official_filter']['licitacao_leaks']
        . ', remittance_leaks_in_orc_pd=' . $report['official_filter']['remittance_leaks_in_orc_pd'] . PHP_EOL;
    echo 'Filtro legado por operation_scope incluiria: '
        . $report['legacy_scope_only']['would_include_licitacao_invoices'] . ' NF(s), '
        . $report['legacy_scope_only']['would_include_licitacao_products'] . ' produto(s), R$ '
        . number_format((float) $report['legacy_scope_only']['would_include_licitacao_revenue'], 2, ',', '.') . PHP_EOL . PHP_EOL;

    echo 'Resumo por escopo/visibilidade:' . PHP_EOL;
    foreach ($report['by_scope'] as $row) {
        echo '- ' . ($row['operation_scope'] ?? 'unknown')
            . ' visible=' . ($row['commercial_visible'] ?? '-')
            . ' produtos=' . (int) ($row['products_count'] ?? 0)
            . ' NFs=' . (int) ($row['invoices_count'] ?? 0)
            . ' receita=R$ ' . number_format((float) ($row['revenue'] ?? 0), 2, ',', '.')
            . PHP_EOL;
    }

    echo PHP_EOL . 'Top produtos oficiais:' . PHP_EOL;
    foreach (array_slice($report['top_products_official'], 0, 10) as $idx => $row) {
        echo str_pad((string) ($idx + 1), 2, ' ', STR_PAD_LEFT) . '. '
            . ($row['product_name'] ?? 'Produto')
            . ' | SKU ' . ($row['product_code'] ?? '-')
            . ' | R$ ' . number_format((float) ($row['revenue'] ?? 0), 2, ',', '.')
            . PHP_EOL;
    }
}

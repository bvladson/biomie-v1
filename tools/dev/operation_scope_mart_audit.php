<?php

declare(strict_types=1);

/**
 * Fase H — T6: Auditoria de contaminação por remessa nos gold marts.
 *
 * Verifica se algum mart gold contém linhas materializadas a partir de NFs/pedidos
 * com operation_scope != 'commercial' (remittance ou unknown).
 *
 * Uso:
 *   php tools/dev/operation_scope_mart_audit.php
 *   php tools/dev/operation_scope_mart_audit.php --json
 *   php tools/dev/operation_scope_mart_audit.php --competencia=2025-04
 *   php tools/dev/operation_scope_mart_audit.php --fix-hint   (imprime SQL de re-materialização)
 */

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$opts        = getopt('', ['json', 'competencia:', 'fix-hint']);
$isJson      = isset($opts['json']);
$competencia = isset($opts['competencia']) ? trim((string) $opts['competencia']) : null;
$fixHint     = isset($opts['fix-hint']);

$config     = Config::fromEnvironment();
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
if (!Database::isConfigured($config, $connection)) {
    fwrite(STDERR, "ERRO: Banco nao configurado.\n");
    exit(1);
}
$pdo = Database::connect($config, $connection);
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

// ── Helpers ────────────────────────────────────────────────────────────────────

function maColExists(\PDO $pdo, string $table, string $col): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->execute([$table, $col]);
    return (int) $stmt->fetchColumn() > 0;
}

function maTableExists(\PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

// ── Check migration H3 ─────────────────────────────────────────────────────────

$nfHasScope  = maColExists($pdo, 'biomie_notas_fiscais_canonicas', 'operation_scope');
$ordHasScope = maColExists($pdo, 'biomie_pedidos_canonicos', 'operation_scope');

if (!$nfHasScope && !$ordHasScope) {
    $msg = 'Migration H3 nao aplicada: colunas operation_scope ausentes. Execute backfill_operation_scope.php --migrate primeiro.';
    if ($isJson) {
        echo json_encode(['error' => $msg, 'migration_h3_applied' => false]) . "\n";
    } else {
        echo "ERRO: $msg\n";
    }
    exit(1);
}

// ── Audit definitions ──────────────────────────────────────────────────────────
// Each entry: label, mart (table name), custom_sql (returns rows with scope+n columns).

$audits = [];

// mart_cliente_periodo ← biomie_pedidos_canonicos
if (maTableExists($pdo, 'mart_cliente_periodo') && $ordHasScope) {
    $audits[] = [
        'mart'       => 'mart_cliente_periodo',
        'label'      => 'mart_cliente_periodo ← pedidos (remittance/unknown)',
        'custom_sql' => "SELECT s.operation_scope AS scope, COUNT(*) AS n
                         FROM mart_cliente_periodo mc
                         JOIN biomie_pedidos_canonicos s ON s.codigo_cliente_omie = mc.codigo_cliente_omie
                         WHERE s.operation_scope IN ('remittance','unknown')
                           AND s.status_comercial = 'faturado'" .
                        ($competencia !== null ? " AND mc.competencia = " . $pdo->quote($competencia) : '') .
                        " GROUP BY s.operation_scope",
    ];
}

// mart_cliente_periodo ← biomie_notas_fiscais_canonicas
if (maTableExists($pdo, 'mart_cliente_periodo') && $nfHasScope) {
    $audits[] = [
        'mart'       => 'mart_cliente_periodo',
        'label'      => 'mart_cliente_periodo ← NFs (remittance/unknown)',
        'custom_sql' => "SELECT s.operation_scope AS scope, COUNT(*) AS n
                         FROM mart_cliente_periodo mc
                         JOIN biomie_notas_fiscais_canonicas s ON s.codigo_cliente_omie = mc.codigo_cliente_omie
                         WHERE s.operation_scope IN ('remittance','unknown')
                           AND s.status_fiscal = 'autorizada'" .
                        ($competencia !== null ? " AND mc.competencia = " . $pdo->quote($competencia) : '') .
                        " GROUP BY s.operation_scope",
    ];
}

// mart_produto_periodo — joined via items+NFs (no direct nota_key in mart)
if (maTableExists($pdo, 'mart_produto_periodo') && $nfHasScope) {
    $audits[] = [
        'mart'       => 'mart_produto_periodo',
        'label'      => 'mart_produto_periodo ← NF itens (remittance/unknown)',
        'custom_sql' => "SELECT n.operation_scope AS scope, COUNT(DISTINCT mp.codigo_produto) AS n
                         FROM mart_produto_periodo mp
                         JOIN biomie_notas_fiscais_itens_canonicos i ON i.codigo_produto = mp.codigo_produto
                         JOIN biomie_notas_fiscais_canonicas n ON n.nota_key = i.nota_key
                         WHERE n.operation_scope IN ('remittance','unknown')
                           AND n.status_fiscal = 'autorizada'" .
                        ($competencia !== null ? " AND mp.competencia = " . $pdo->quote($competencia) : '') .
                        " GROUP BY n.operation_scope",
    ];
}

// mart_compradores_por_geo ← biomie_notas_fiscais_canonicas (joined via cliente)
if (maTableExists($pdo, 'mart_compradores_por_geo') && $nfHasScope) {
    $audits[] = [
        'mart'       => 'mart_compradores_por_geo',
        'label'      => 'mart_compradores_por_geo ← NFs (remittance/unknown)',
        'custom_sql' => "SELECT n.operation_scope AS scope, COUNT(*) AS n
                         FROM mart_compradores_por_geo g
                         JOIN biomie_notas_fiscais_canonicas n ON n.codigo_cliente_omie = g.codigo_cliente_omie
                         WHERE n.operation_scope IN ('remittance','unknown')
                           AND n.status_fiscal = 'autorizada'" .
                        ($competencia !== null ? " AND g.competencia = " . $pdo->quote($competencia) : '') .
                        " GROUP BY n.operation_scope",
    ];
}

// mart_vendas_por_cnae_cnpj ← biomie_notas_fiscais_canonicas (joined via cliente)
if (maTableExists($pdo, 'mart_vendas_por_cnae_cnpj') && $nfHasScope) {
    $audits[] = [
        'mart'       => 'mart_vendas_por_cnae_cnpj',
        'label'      => 'mart_vendas_por_cnae_cnpj ← NFs (remittance/unknown)',
        'custom_sql' => "SELECT n.operation_scope AS scope, COUNT(*) AS n
                         FROM mart_vendas_por_cnae_cnpj cv
                         JOIN biomie_notas_fiscais_canonicas n ON n.codigo_cliente_omie = cv.codigo_cliente_omie
                         WHERE n.operation_scope IN ('remittance','unknown')
                           AND n.status_fiscal = 'autorizada'" .
                        ($competencia !== null ? " AND cv.competencia = " . $pdo->quote($competencia) : '') .
                        " GROUP BY n.operation_scope",
    ];
}

// mart_vendedor_dia ← biomie_pedidos_canonicos (commercial part — faturado only)
// Only 'faturado' orders affect revenue KPIs; cancelled/autorizado are legitimately unknown.
if (maTableExists($pdo, 'mart_vendedor_dia') && $ordHasScope) {
    $audits[] = [
        'mart'       => 'mart_vendedor_dia',
        'label'      => 'mart_vendedor_dia ← pedidos faturados (remittance/unknown)',
        'custom_sql' => "SELECT p.operation_scope AS scope, COUNT(*) AS n
                         FROM mart_vendedor_dia vd
                         JOIN biomie_pedidos_canonicos p ON p.codigo_vendedor_omie = vd.codigo_vendedor_omie
                         WHERE p.operation_scope IN ('remittance','unknown')
                           AND p.status_comercial = 'faturado'" .
                        ($competencia !== null ? " AND vd.competencia = " . $pdo->quote($competencia) : '') .
                        " GROUP BY p.operation_scope",
    ];
}

// mart_vendedor_dia ← biomie_notas_fiscais_canonicas (fiscal part — matched by competencia)
// Join on vendor + competencia to avoid false positives from remittance NFs in other periods.
if (maTableExists($pdo, 'mart_vendedor_dia') && $nfHasScope) {
    $audits[] = [
        'mart'       => 'mart_vendedor_dia',
        'label'      => 'mart_vendedor_dia ← NFs (remittance/unknown)',
        'custom_sql' => "SELECT n.operation_scope AS scope, COUNT(DISTINCT n.nota_key) AS n
                         FROM mart_vendedor_dia vd
                         JOIN biomie_notas_fiscais_canonicas n
                              ON n.codigo_vendedor_omie = vd.codigo_vendedor_omie
                              AND DATE_FORMAT(COALESCE(n.data_competencia, n.data_emissao), '%Y-%m') = vd.competencia
                         WHERE n.operation_scope IN ('remittance','unknown')
                           AND n.status_fiscal = 'autorizada'" .
                        ($competencia !== null ? " AND vd.competencia = " . $pdo->quote($competencia) : '') .
                        " GROUP BY n.operation_scope",
    ];
}

// ── Run audits ─────────────────────────────────────────────────────────────────

$results      = [];
$contaminated = 0;
$errors       = 0;

foreach ($audits as $audit) {
    try {
        $rows = $pdo->query($audit['custom_sql'])->fetchAll(\PDO::FETCH_ASSOC);

        $contamCount = array_sum(array_column($rows, 'n'));
        if ($contamCount > 0) {
            $contaminated++;
        }

        $results[] = [
            'label'         => $audit['label'],
            'mart'          => $audit['mart'],
            'contaminated'  => $contamCount > 0,
            'contam_rows'   => $contamCount,
            'breakdown'     => $rows,
            'status'        => $contamCount > 0 ? 'CONTAMINADO' : 'OK',
        ];
    } catch (\Throwable $e) {
        $errors++;
        $results[] = [
            'label'        => $audit['label'] ?? ($audit['mart'] ?? '?'),
            'mart'         => $audit['mart'] ?? '?',
            'contaminated' => null,
            'contam_rows'  => null,
            'breakdown'    => [],
            'status'       => 'ERRO',
            'error'        => $e->getMessage(),
        ];
    }
}

// ── Output ─────────────────────────────────────────────────────────────────────

if ($isJson) {
    echo json_encode([
        'migration_h3_applied' => $nfHasScope || $ordHasScope,
        'nf_scope_column'      => $nfHasScope,
        'order_scope_column'   => $ordHasScope,
        'competencia_filter'   => $competencia,
        'contaminated_marts'   => $contaminated,
        'error_count'          => $errors,
        'results'              => $results,
        'clean'                => ($contaminated === 0 && $errors === 0),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit($contaminated > 0 || $errors > 0 ? 2 : 0);
}

// Text output
echo "\n=== operation_scope_mart_audit ===\n";
if ($competencia !== null) {
    echo "Filtro competencia: $competencia\n";
}
echo "Migration H3: nf_scope=" . ($nfHasScope ? 'SIM' : 'NAO') . "  order_scope=" . ($ordHasScope ? 'SIM' : 'NAO') . "\n\n";

foreach ($results as $r) {
    $icon = $r['status'] === 'OK' ? '[OK]' : ($r['status'] === 'ERRO' ? '[ERRO]' : '[!!!]');
    printf("%s %s\n", $icon, $r['label']);
    if ($r['status'] === 'ERRO') {
        echo "    Erro: " . ($r['error'] ?? '?') . "\n";
    } elseif ($r['contaminated']) {
        echo "    Linhas contaminadas: {$r['contam_rows']}\n";
        foreach ($r['breakdown'] as $b) {
            echo "      scope={$b['scope']}  n={$b['n']}\n";
        }
        if ($fixHint) {
            echo "    HINT: Rematerialize o mart com GoldRefreshCoordinator ou api/geo_materialize.php\n";
        }
    }
}

echo "\n";
if ($contaminated === 0 && $errors === 0) {
    echo "Resultado: LIMPO — nenhum mart contaminado por remessa/unknown.\n\n";
    exit(0);
}

if ($contaminated > 0) {
    echo "ATENCAO: $contaminated mart(s) com linhas de remessa/unknown.\n";
    echo "Acao: rematerialize os marts afetados para expurgar os dados contaminados.\n";
    if ($fixHint) {
        echo "Exemplo:\n";
        echo "  php api/geo_materialize.php  (geo mart)\n";
        echo "  Ou acione GoldRefreshCoordinator via api/omie_materialize_commercial.php\n";
    }
}
if ($errors > 0) {
    echo "ERROS: $errors auditoria(s) falharam (ver detalhes acima).\n";
}
echo "\n";
exit($contaminated > 0 || $errors > 0 ? 2 : 0);

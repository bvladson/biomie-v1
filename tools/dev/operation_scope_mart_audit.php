<?php

declare(strict_types=1);

/**
 * Fase J — Auditoria de escopo dos gold marts (reescrita com aggregate_reconciliation).
 *
 * Verifica se algum mart gold contém receita oriunda de NFs com
 * operation_scope != 'commercial' (remessa ou unknown).
 *
 * Métodos de auditoria:
 *   aggregate_reconciliation    — compara SUM(mart) vs SUM(canonical_commercial)
 *                                 delta ≤ R$ 0,01 → OK; delta > R$ 0,01 → CRITICAL
 *   customer_join_inconclusive  — JOIN por codigo_cliente_omie; informativo (WARNING/OK),
 *                                 nunca prova contaminação (cliente pode ter NFs de ambos os tipos)
 *
 * Campos de saída por resultado:
 *   audit_method          — aggregate_reconciliation | customer_join_inconclusive
 *   contamination_proven  — true somente quando delta financeiro prova contaminação
 *   financial_delta       — diferença monetária absoluta (aggregate); null para join
 *   status                — OK | WARNING | CRITICAL | ERROR
 *
 * Uso:
 *   php tools/dev/operation_scope_mart_audit.php
 *   php tools/dev/operation_scope_mart_audit.php --json
 *   php tools/dev/operation_scope_mart_audit.php --competencia=2025-04
 *   php tools/dev/operation_scope_mart_audit.php --fix-hint
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

function maFmt(float $val): string
{
    return 'R$ ' . number_format($val, 2, ',', '.');
}

const MA_DELTA_TOLERANCE = 0.01;

// ── Migration guard ────────────────────────────────────────────────────────────

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

// ── Date filters ───────────────────────────────────────────────────────────────

$martFilter = '';
$martParams = [];
$nfFilter   = '';
$nfParams   = [];

if ($competencia !== null) {
    $martFilter           = ' AND competencia = :competencia';
    $martParams[':competencia'] = $competencia;
    $nfFilter             = " AND DATE_FORMAT(COALESCE(data_competencia, data_emissao), '%Y-%m') = :competencia";
    $nfParams[':competencia']   = $competencia;
}

// ── Canonical commercial ground truth ─────────────────────────────────────────

$canonRevenue     = 0.0;
$canonMerchandise = 0.0;

if ($nfHasScope) {
    try {
        $stmtCanon = $pdo->prepare("
            SELECT
                COALESCE(SUM(valor_total),       0) AS revenue,
                COALESCE(SUM(valor_mercadorias), 0) AS merchandise
            FROM biomie_notas_fiscais_canonicas
            WHERE status_fiscal = 'autorizada'
              AND operation_scope = 'commercial'
              {$nfFilter}
        ");
        $stmtCanon->execute($nfParams);
        $canon            = $stmtCanon->fetch(\PDO::FETCH_ASSOC);
        $canonRevenue     = (float) ($canon['revenue']     ?? 0);
        $canonMerchandise = (float) ($canon['merchandise'] ?? 0);
    } catch (\Throwable $e) {
        fwrite(STDERR, "AVISO: Nao foi possivel calcular receita canonical: " . $e->getMessage() . "\n");
    }
}

// ── Audit state ────────────────────────────────────────────────────────────────

/** @var list<array<string,mixed>> */
$results   = [];
$criticals = 0;
$warnings  = 0;
$errors    = 0;

// ── Aggregate reconciliation helper ───────────────────────────────────────────

/**
 * Compare mart revenue total vs canonical commercial total.
 *
 * delta ≤ 0.01            → OK (clean)
 * mart > canonical + 0.01 → CRITICAL: contamination_proven=true (extra revenue = possible remittance)
 * mart < canonical - 0.01 → WARNING:  incomplete materialization (missing data, NOT contamination)
 *
 * @param array<string,mixed> $martParams
 */
function maRunAggregate(
    \PDO $pdo,
    string $label,
    string $mart,
    string $martSql,
    array $martParams,
    float $canonValue,
    string $revenueField,
    array &$results,
    int &$criticals,
    int &$warnings,
    int &$errors
): void {
    try {
        $stmt = $pdo->prepare($martSql);
        $stmt->execute($martParams);
        $martValue   = (float) $stmt->fetchColumn();
        $signedDelta = round($martValue - $canonValue, 2);
        $absDelta    = round(abs($signedDelta), 2);
        $contaminated = $signedDelta > MA_DELTA_TOLERANCE;   // mart EXCEEDS canonical
        $incomplete   = $signedDelta < -MA_DELTA_TOLERANCE;  // mart is LESS than canonical

        if ($contaminated) {
            $criticals++;
            $status = 'CRITICAL';
        } elseif ($incomplete) {
            $warnings++;
            $status = 'WARNING';
        } else {
            $status = 'OK';
        }

        $results[] = [
            'label'                => $label,
            'mart'                 => $mart,
            'audit_method'         => 'aggregate_reconciliation',
            'contamination_proven' => $contaminated,
            'incomplete'           => $incomplete,
            'financial_delta'      => $absDelta,
            'signed_delta'         => $signedDelta,
            'mart_value'           => round($martValue, 2),
            'canonical_value'      => round($canonValue, 2),
            'revenue_field'        => $revenueField,
            'status'               => $status,
        ];
    } catch (\Throwable $e) {
        $errors++;
        $results[] = [
            'label'                => $label,
            'mart'                 => $mart,
            'audit_method'         => 'aggregate_reconciliation',
            'contamination_proven' => null,
            'incomplete'           => null,
            'financial_delta'      => null,
            'signed_delta'         => null,
            'mart_value'           => null,
            'canonical_value'      => round($canonValue, 2),
            'revenue_field'        => $revenueField,
            'status'               => 'ERROR',
            'error'                => $e->getMessage(),
        ];
    }
}

// ── Customer-join informational helper ────────────────────────────────────────

/**
 * JOIN by codigo_cliente_omie — informational only.
 * Matches > 0 → WARNING (never CRITICAL).
 * This does not prove financial contamination; use aggregate_reconciliation for that.
 *
 * @param array<string,mixed> $params
 */
function maRunJoin(
    \PDO $pdo,
    string $label,
    string $mart,
    string $sql,
    array $params,
    array &$results,
    int &$warnings,
    int &$errors
): void {
    try {
        $stmt  = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows  = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $count = (int) array_sum(array_column($rows, 'n'));

        if ($count > 0) {
            $warnings++;
        }

        $results[] = [
            'label'                => $label,
            'mart'                 => $mart,
            'audit_method'         => 'customer_join_inconclusive',
            'contamination_proven' => false,
            'financial_delta'      => null,
            'join_match_count'     => $count,
            'breakdown'            => $rows,
            'status'               => $count > 0 ? 'WARNING' : 'OK',
            'note'                 => 'JOIN por codigo_cliente_omie: um cliente pode ter NFs de ambos os escopos. '
                . 'Este resultado nao prova contaminacao financeira dos marts. '
                . 'Veja aggregate_reconciliation para delta financeiro.',
        ];
    } catch (\Throwable $e) {
        $errors++;
        $results[] = [
            'label'                => $label,
            'mart'                 => $mart,
            'audit_method'         => 'customer_join_inconclusive',
            'contamination_proven' => null,
            'financial_delta'      => null,
            'join_match_count'     => null,
            'breakdown'            => [],
            'status'               => 'ERROR',
            'error'                => $e->getMessage(),
        ];
    }
}

// ── Primary: aggregate_reconciliation ─────────────────────────────────────────

// mart_cliente_periodo.valor_nfe vs canonical commercial revenue
if (maTableExists($pdo, 'mart_cliente_periodo') && $nfHasScope) {
    maRunAggregate(
        $pdo,
        'mart_cliente_periodo.valor_nfe vs canonical commercial',
        'mart_cliente_periodo',
        "SELECT COALESCE(SUM(valor_nfe), 0) FROM mart_cliente_periodo WHERE 1=1{$martFilter}",
        $martParams,
        $canonRevenue,
        'valor_nfe',
        $results, $criticals, $warnings, $errors
    );
}

// mart_vendedor_dia.valor_nfe vs canonical commercial revenue
if (maTableExists($pdo, 'mart_vendedor_dia') && $nfHasScope) {
    maRunAggregate(
        $pdo,
        'mart_vendedor_dia.valor_nfe vs canonical commercial',
        'mart_vendedor_dia',
        "SELECT COALESCE(SUM(valor_nfe), 0) FROM mart_vendedor_dia WHERE 1=1{$martFilter}",
        $martParams,
        $canonRevenue,
        'valor_nfe',
        $results, $criticals, $warnings, $errors
    );
}

// mart_produto_periodo.valor_total vs canonical commercial mercadoria
if (maTableExists($pdo, 'mart_produto_periodo') && $nfHasScope) {
    maRunAggregate(
        $pdo,
        'mart_produto_periodo.valor_total vs canonical commercial mercadoria',
        'mart_produto_periodo',
        "SELECT COALESCE(SUM(valor_total), 0) FROM mart_produto_periodo WHERE 1=1{$martFilter}",
        $martParams,
        $canonMerchandise,
        'valor_total',
        $results, $criticals, $warnings, $errors
    );
}

// mart_compradores_por_geo.faturamento vs canonical commercial revenue
if (maTableExists($pdo, 'mart_compradores_por_geo') && $nfHasScope) {
    maRunAggregate(
        $pdo,
        'mart_compradores_por_geo.faturamento vs canonical commercial',
        'mart_compradores_por_geo',
        "SELECT COALESCE(SUM(faturamento), 0) FROM mart_compradores_por_geo WHERE 1=1{$martFilter}",
        $martParams,
        $canonRevenue,
        'faturamento',
        $results, $criticals, $warnings, $errors
    );
}

// mart_vendas_por_cnae_cnpj.faturamento vs canonical commercial revenue
if (maTableExists($pdo, 'mart_vendas_por_cnae_cnpj') && $nfHasScope) {
    maRunAggregate(
        $pdo,
        'mart_vendas_por_cnae_cnpj.faturamento vs canonical commercial',
        'mart_vendas_por_cnae_cnpj',
        "SELECT COALESCE(SUM(faturamento), 0) FROM mart_vendas_por_cnae_cnpj WHERE 1=1{$martFilter}",
        $martParams,
        $canonRevenue,
        'faturamento',
        $results, $criticals, $warnings, $errors
    );
}

// ── Informational: customer_join_inconclusive ──────────────────────────────────

if (maTableExists($pdo, 'mart_cliente_periodo') && $nfHasScope) {
    $sql = "SELECT s.operation_scope AS scope, COUNT(*) AS n
            FROM mart_cliente_periodo mc
            JOIN biomie_notas_fiscais_canonicas s ON s.codigo_cliente_omie = mc.codigo_cliente_omie
            WHERE s.operation_scope IN ('remittance','unknown')
              AND s.status_fiscal = 'autorizada'"
        . ($competencia !== null ? " AND mc.competencia = :competencia" : '')
        . ' GROUP BY s.operation_scope';
    maRunJoin($pdo, '[info] mart_cliente_periodo: clientes com NF remessa/unknown (JOIN por cliente)', 'mart_cliente_periodo',
        $sql, $competencia !== null ? [':competencia' => $competencia] : [], $results, $warnings, $errors);
}

if (maTableExists($pdo, 'mart_compradores_por_geo') && $nfHasScope) {
    $sql = "SELECT n.operation_scope AS scope, COUNT(*) AS n
            FROM mart_compradores_por_geo g
            JOIN biomie_notas_fiscais_canonicas n ON n.codigo_cliente_omie = g.codigo_cliente_omie
            WHERE n.operation_scope IN ('remittance','unknown')
              AND n.status_fiscal = 'autorizada'"
        . ($competencia !== null ? " AND g.competencia = :competencia" : '')
        . ' GROUP BY n.operation_scope';
    maRunJoin($pdo, '[info] mart_compradores_por_geo: clientes com NF remessa/unknown (JOIN por cliente)', 'mart_compradores_por_geo',
        $sql, $competencia !== null ? [':competencia' => $competencia] : [], $results, $warnings, $errors);
}

if (maTableExists($pdo, 'mart_vendas_por_cnae_cnpj') && $nfHasScope) {
    $sql = "SELECT n.operation_scope AS scope, COUNT(*) AS n
            FROM mart_vendas_por_cnae_cnpj cv
            JOIN biomie_notas_fiscais_canonicas n ON n.codigo_cliente_omie = cv.codigo_cliente_omie
            WHERE n.operation_scope IN ('remittance','unknown')
              AND n.status_fiscal = 'autorizada'"
        . ($competencia !== null ? " AND cv.competencia = :competencia" : '')
        . ' GROUP BY n.operation_scope';
    maRunJoin($pdo, '[info] mart_vendas_por_cnae_cnpj: clientes com NF remessa/unknown (JOIN por cliente)', 'mart_vendas_por_cnae_cnpj',
        $sql, $competencia !== null ? [':competencia' => $competencia] : [], $results, $warnings, $errors);
}

// ── Output ─────────────────────────────────────────────────────────────────────

$clean = ($criticals === 0 && $errors === 0);

if ($isJson) {
    echo json_encode([
        'migration_h3_applied' => $nfHasScope || $ordHasScope,
        'nf_scope_column'      => $nfHasScope,
        'order_scope_column'   => $ordHasScope,
        'competencia_filter'   => $competencia,
        'canonical_commercial' => [
            'revenue'     => round($canonRevenue,     2),
            'merchandise' => round($canonMerchandise, 2),
        ],
        'critical_count' => $criticals,
        'warning_count'  => $warnings,
        'error_count'    => $errors,
        'clean'          => $clean,
        'results'        => $results,
        'legend'         => [
            'aggregate_reconciliation'   => 'Compara SUM(mart) vs SUM(canonical_commercial). mart > canonical = CRITICAL (contaminacao). mart < canonical = WARNING (incompleto, nao contaminacao).',
            'customer_join_inconclusive' => 'JOIN por codigo_cliente_omie. Informativo — nao prova contaminacao financeira. WARNING apenas quando ha matches.',
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(!$clean ? 2 : 0);
}

// ── Text output ────────────────────────────────────────────────────────────────

echo "\n=== operation_scope_mart_audit (Fase J) ===\n";
if ($competencia !== null) {
    echo "Filtro competencia: $competencia\n";
}
echo "Migration H3: nf_scope=" . ($nfHasScope ? 'SIM' : 'NAO') . "  order_scope=" . ($ordHasScope ? 'SIM' : 'NAO') . "\n";
echo "Canonical commercial: receita=" . maFmt($canonRevenue) . "  mercadoria=" . maFmt($canonMerchandise) . "\n\n";

echo "VERIFICACOES PRIMARIAS (aggregate_reconciliation):\n";
foreach ($results as $r) {
    if ($r['audit_method'] !== 'aggregate_reconciliation') {
        continue;
    }
    if ($r['status'] === 'ERROR') {
        printf("[ERRO    ] %s\n    Erro: %s\n", $r['label'], $r['error'] ?? '?');
        continue;
    }
    $icon = match ($r['status']) {
        'CRITICAL' => '[CRITICO]',
        'WARNING'  => '[AVISO  ]',
        default    => '[OK     ]',
    };
    $sign = ($r['signed_delta'] ?? 0) >= 0 ? '+' : '-';
    printf(
        "%s %s\n    mart=%s  canonical=%s  delta=%s%s\n",
        $icon, $r['label'],
        maFmt((float) $r['mart_value']),
        maFmt((float) $r['canonical_value']),
        $sign,
        maFmt((float) $r['financial_delta'])
    );
    if ($r['status'] === 'CRITICAL') {
        echo "    *** CONTAMINACAO COMPROVADA: mart excede canonical commercial — remessa pode ter entrado\n";
        if ($fixHint) {
            echo "    HINT: Rematerialize o mart com GoldRefreshCoordinator ou api/geo_materialize.php\n";
        }
    } elseif ($r['status'] === 'WARNING') {
        echo "    (!) COBERTURA INCOMPLETA: mart tem menos receita que canonical — dados faltantes, nao contaminacao\n";
        if ($fixHint) {
            echo "    HINT: Rematerialize o mart para cobrir todo o periodo com GoldRefreshCoordinator\n";
        }
    }
}

echo "\nVERIFICAÇÕES INFORMATIVAS (customer_join_inconclusive):\n";
foreach ($results as $r) {
    if ($r['audit_method'] !== 'customer_join_inconclusive') {
        continue;
    }
    if ($r['status'] === 'ERROR') {
        printf("[ERRO   ] %s\n    Erro: %s\n", $r['label'], $r['error'] ?? '?');
        continue;
    }
    $icon = $r['status'] === 'WARNING' ? '[AVISO ]' : '[OK    ]';
    printf(
        "%s %s\n    Matches JOIN: %d%s\n",
        $icon, $r['label'],
        (int) ($r['join_match_count'] ?? 0),
        $r['status'] === 'WARNING' ? ' (nao prova contaminacao — clientes com multiplos escopos)' : ''
    );
    foreach ($r['breakdown'] ?? [] as $b) {
        echo "      scope={$b['scope']}  n={$b['n']}\n";
    }
}

echo "\nLEGENDA:\n";
echo "  aggregate_reconciliation (mart > canonical) = CRITICO — contaminacao comprovada\n";
echo "  aggregate_reconciliation (mart < canonical) = AVISO  — cobertura incompleta, nao contaminacao\n";
echo "  customer_join_inconclusive                  = AVISO  — informativo; JOIN nao prova contaminacao\n\n";

if ($clean) {
    echo "Resultado: LIMPO — nenhuma contaminacao financeira comprovada nos marts.\n";
    if ($warnings > 0) {
        echo "  ($warnings aviso(s) — cobertura incompleta ou clientes com escopo misto — nao bloqueante)\n";
    }
    echo "\n";
    exit(0);
}

if ($criticals > 0) {
    printf("CRITICO: %d mart(s) com receita acima do canonical commercial — contaminacao por remessa/unknown.\n", $criticals);
    echo "Acao: rematerialize os marts afetados (GoldRefreshCoordinator ou api/omie_materialize_commercial.php)\n";
}
if ($errors > 0) {
    printf("ERROS: %d verificacao(es) falharam (ver detalhes acima).\n", $errors);
}
echo "\n";
exit(2);

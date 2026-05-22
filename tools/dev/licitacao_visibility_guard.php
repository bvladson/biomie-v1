<?php

/**
 * tools/dev/licitacao_visibility_guard.php
 *
 * Fase L — Guard de visibilidade comercial da Licitação.
 *
 * Runs 10 PASS/FAIL checks to verify that licitação data is properly excluded
 * from all commercial views and marts, while raw/bronze data is preserved.
 *
 * Usage:
 *   php tools/dev/licitacao_visibility_guard.php
 *   php tools/dev/licitacao_visibility_guard.php --json
 *   php tools/dev/licitacao_visibility_guard.php --pretty
 *   php tools/dev/licitacao_visibility_guard.php --check=5    (run single check)
 *   php tools/dev/licitacao_visibility_guard.php --fail-fast  (stop on first failure)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$rootDir = dirname(__DIR__, 2);
require $rootDir . '/api/bootstrap.php';

// ── CLI args ──────────────────────────────────────────────────────────────────

$opts = getopt('', ['json', 'pretty', 'check:', 'fail-fast', 'help']);

$asJson    = isset($opts['json']);
$pretty    = isset($opts['pretty']);
$failFast  = isset($opts['fail-fast']);
$onlyCheck = isset($opts['check']) ? (int) $opts['check'] : null;

if (isset($opts['help'])) {
    echo <<<HELP
Fase L — Guard de visibilidade comercial

Usage:
  php tools/dev/licitacao_visibility_guard.php [options]

Options:
  --json        Output as JSON
  --pretty      Indent JSON output
  --check=N     Run only check N (1-10)
  --fail-fast   Stop on first failure
  --help        Show this help

Checks:
  1  mart_vendedor_dia          — licitação NFs absent
  2  mart_cliente_periodo       — licitação absent
  3  mart_produto_periodo       — licitação absent
  4  mart_compradores_por_geo   — licitação absent
  5  mart_vendas_por_cnae_cnpj  — licitação absent
  6  orders_search canonical    — NF not returned by default
  7  vendors_search canonical   — vendors not returned by default
  8  Base Comercial canonical   — NF valor not included in totals
  9  Raw/bronze preserved       — data untouched in omie_bronze
  10 Migration columns          — canonical tables have visibility columns

HELP;
    exit(0);
}

// ── DB ────────────────────────────────────────────────────────────────────────

$conn = \BIomie\Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
if (!\BIomie\Database::isConfigured($config, $conn)) {
    fwrite(STDERR, "ERRO: banco de dados não configurado.\n");
    exit(1);
}

try {
    $pdo = \BIomie\Database::connect($config, $conn);
} catch (Throwable $e) {
    fwrite(STDERR, "ERRO ao conectar: " . $e->getMessage() . "\n");
    exit(1);
}

// ── Shared helpers ────────────────────────────────────────────────────────────

function colExists(PDO $pdo, string $table, string $col): bool
{
    static $cache = [];
    $key = "{$table}:{$col}";
    if (!isset($cache[$key])) {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
        );
        $stmt->execute([$table, $col]);
        $cache[$key] = (int) $stmt->fetchColumn() > 0;
    }
    return $cache[$key];
}

function tableExists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (!isset($cache[$table])) {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
        );
        $stmt->execute([$table]);
        $cache[$table] = (int) $stmt->fetchColumn() > 0;
    }
    return $cache[$table];
}

function normalizeName(string $name): string
{
    $n   = mb_strtolower(trim($name));
    $map = [
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n',
    ];
    return mb_strtoupper(strtr($n, $map));
}

/**
 * Detect licitação vendor codes from vendor table or NF fallback.
 * Returns list of codigo_vendedor_omie strings (may be empty).
 */
function detectLicitacaoCodes(PDO $pdo): array
{
    $codes = [];

    if (tableExists($pdo, 'biomie_vendor_scope_rules')) {
        $stmt = $pdo->query(
            "SELECT codigo_vendedor_omie FROM biomie_vendor_scope_rules
             WHERE active = 1 AND is_biomie_commercial_visible = 0"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $c) {
            if ((string) $c !== '') $codes[] = (string) $c;
        }
    }

    if (empty($codes) && tableExists($pdo, 'biomie_vendedores_omie')) {
        $stmt = $pdo->query("SELECT codigo_omie, nome FROM biomie_vendedores_omie");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $nome = (string) ($row['nome'] ?? '');
            if (str_contains(normalizeName($nome), 'LICITAC')) {
                $code = (string) ($row['codigo_omie'] ?? '');
                if ($code !== '') $codes[] = $code;
            }
        }
    }

    if (empty($codes) && tableExists($pdo, 'biomie_notas_fiscais_canonicas')) {
        $stmt = $pdo->query(
            "SELECT DISTINCT codigo_vendedor_omie FROM biomie_notas_fiscais_canonicas
             WHERE UPPER(nome_vendedor) LIKE '%LICITAC%'
               AND codigo_vendedor_omie IS NOT NULL AND codigo_vendedor_omie != ''
             LIMIT 20"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $c) {
            if ((string) $c !== '') $codes[] = (string) $c;
        }
    }

    return array_unique($codes);
}

/**
 * Check a mart table for licitação vendor codes.
 * Returns ['pass' => bool, 'count' => int, 'detail' => string].
 */
function checkMartExclusion(PDO $pdo, string $table, string $vendorCol, array $licitacaoCodes): array
{
    if (!tableExists($pdo, $table)) {
        return ['pass' => true, 'count' => 0, 'detail' => "Tabela {$table} ausente — check ignorado.", 'skipped' => true];
    }

    if (!colExists($pdo, $table, $vendorCol)) {
        return ['pass' => true, 'count' => 0, 'detail' => "Coluna {$vendorCol} ausente em {$table} — check ignorado.", 'skipped' => true];
    }

    if (empty($licitacaoCodes)) {
        // Fallback: check by nome pattern if table has a nome column
        $nomeCol = null;
        foreach (['nome_vendedor', 'vendedor_nome', 'nome'] as $candidate) {
            if (colExists($pdo, $table, $candidate)) {
                $nomeCol = $candidate;
                break;
            }
        }
        if ($nomeCol === null) {
            return ['pass' => true, 'count' => 0, 'detail' => "Nenhum código licitação detectado e sem coluna nome — check não conclusivo.", 'skipped' => true];
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE UPPER({$nomeCol}) LIKE '%LICITAC%'");
        $stmt->execute();
        $count = (int) $stmt->fetchColumn();
        $pass  = $count === 0;
        return [
            'pass'   => $pass,
            'count'  => $count,
            'detail' => $pass
                ? "Nenhuma linha licitação em {$table} (via nome LIKE)."
                : "ENCONTRADAS {$count} linhas com nome licitação em {$table} — rematerializar.",
        ];
    }

    $ph    = implode(',', array_fill(0, count($licitacaoCodes), '?'));
    $stmt  = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$vendorCol} IN ({$ph})");
    $stmt->execute(array_values($licitacaoCodes));
    $count = (int) $stmt->fetchColumn();
    $pass  = $count === 0;
    return [
        'pass'   => $pass,
        'count'  => $count,
        'detail' => $pass
            ? "Nenhuma linha licitação em {$table}."
            : "ENCONTRADAS {$count} linhas de licitação em {$table} — rematerializar.",
    ];
}

// ── The 10 checks ─────────────────────────────────────────────────────────────

$licitacaoCodes = detectLicitacaoCodes($pdo);

$checks = [];

// Check 1: mart_vendedor_dia
$checks[1] = static function () use ($pdo, $licitacaoCodes): array {
    $r = checkMartExclusion($pdo, 'mart_vendedor_dia', 'codigo_vendedor_omie', $licitacaoCodes);
    return [
        'id'     => 1,
        'name'   => 'mart_vendedor_dia — licitação ausente',
        'pass'   => $r['pass'],
        'detail' => $r['detail'],
        'count'  => $r['count'],
        'skipped' => $r['skipped'] ?? false,
    ];
};

// Check 2: mart_cliente_periodo
$checks[2] = static function () use ($pdo, $licitacaoCodes): array {
    $r = checkMartExclusion($pdo, 'mart_cliente_periodo', 'codigo_vendedor_omie', $licitacaoCodes);
    return [
        'id'     => 2,
        'name'   => 'mart_cliente_periodo — licitação ausente',
        'pass'   => $r['pass'],
        'detail' => $r['detail'],
        'count'  => $r['count'],
        'skipped' => $r['skipped'] ?? false,
    ];
};

// Check 3: mart_produto_periodo
$checks[3] = static function () use ($pdo, $licitacaoCodes): array {
    $r = checkMartExclusion($pdo, 'mart_produto_periodo', 'codigo_vendedor_omie', $licitacaoCodes);
    return [
        'id'     => 3,
        'name'   => 'mart_produto_periodo — licitação ausente',
        'pass'   => $r['pass'],
        'detail' => $r['detail'],
        'count'  => $r['count'],
        'skipped' => $r['skipped'] ?? false,
    ];
};

// Check 4: mart_compradores_por_geo
$checks[4] = static function () use ($pdo, $licitacaoCodes): array {
    $r = checkMartExclusion($pdo, 'mart_compradores_por_geo', 'codigo_vendedor_omie', $licitacaoCodes);
    return [
        'id'     => 4,
        'name'   => 'mart_compradores_por_geo — licitação ausente',
        'pass'   => $r['pass'],
        'detail' => $r['detail'],
        'count'  => $r['count'],
        'skipped' => $r['skipped'] ?? false,
    ];
};

// Check 5: mart_vendas_por_cnae_cnpj
$checks[5] = static function () use ($pdo, $licitacaoCodes): array {
    $r = checkMartExclusion($pdo, 'mart_vendas_por_cnae_cnpj', 'codigo_vendedor_omie', $licitacaoCodes);
    return [
        'id'     => 5,
        'name'   => 'mart_vendas_por_cnae_cnpj — licitação ausente',
        'pass'   => $r['pass'],
        'detail' => $r['detail'],
        'count'  => $r['count'],
        'skipped' => $r['skipped'] ?? false,
    ];
};

// Check 6: orders_search — NF not returned by default
$checks[6] = static function () use ($pdo, $licitacaoCodes): array {
    if (!tableExists($pdo, 'biomie_notas_fiscais_canonicas')) {
        return ['id' => 6, 'name' => 'orders_search canonical — licitação ausente', 'pass' => true, 'detail' => 'Tabela ausente.', 'skipped' => true];
    }

    $hasVis = colExists($pdo, 'biomie_notas_fiscais_canonicas', 'is_biomie_commercial_visible');

    if (!$hasVis && empty($licitacaoCodes)) {
        return [
            'id'     => 6,
            'name'   => 'orders_search canonical — licitação ausente',
            'pass'   => false,
            'detail' => 'Coluna is_biomie_commercial_visible ausente E sem códigos licitação detectados — backfill pendente.',
            'skipped' => false,
        ];
    }

    if ($hasVis) {
        // Check that is_biomie_commercial_visible=0 rows exist AND backfill was applied
        $stmt = $pdo->query("SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas WHERE is_biomie_commercial_visible = 0");
        $count = (int) $stmt->fetchColumn();
        $pass  = $count > 0 || empty($licitacaoCodes);
        return [
            'id'     => 6,
            'name'   => 'orders_search canonical — licitação ausente',
            'pass'   => $pass,
            'count'  => $count,
            'detail' => $pass
                ? "Backfill aplicado: {$count} NFs marcadas como is_biomie_commercial_visible=0."
                : 'Backfill NÃO aplicado: nenhuma NF com is_biomie_commercial_visible=0. Execute --apply.',
            'skipped' => false,
        ];
    }

    // Fallback: verify by codes
    $ph    = implode(',', array_fill(0, count($licitacaoCodes), '?'));
    $stmt  = $pdo->prepare(
        "SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas
         WHERE codigo_vendedor_omie IN ({$ph})
           AND status_fiscal = 'autorizada'"
    );
    $stmt->execute(array_values($licitacaoCodes));
    $count = (int) $stmt->fetchColumn();
    return [
        'id'     => 6,
        'name'   => 'orders_search canonical — licitação ausente',
        'pass'   => false,
        'count'  => $count,
        'detail' => "Coluna is_biomie_commercial_visible ausente. {$count} NFs licitação sem proteção — migração pendente.",
        'skipped' => false,
    ];
};

// Check 7: vendors_search canonical — vendors not returned by default
$checks[7] = static function () use ($pdo, $licitacaoCodes): array {
    if (!tableExists($pdo, 'biomie_vendedores_omie')) {
        return ['id' => 7, 'name' => 'vendors_search canonical — licitação ausente', 'pass' => true, 'detail' => 'Tabela ausente.', 'skipped' => true];
    }

    $hasVis = colExists($pdo, 'biomie_vendedores_omie', 'is_biomie_commercial_visible');

    if ($hasVis) {
        $stmt  = $pdo->query("SELECT COUNT(*) FROM biomie_vendedores_omie WHERE is_biomie_commercial_visible = 0");
        $count = (int) $stmt->fetchColumn();
        $pass  = $count > 0 || empty($licitacaoCodes);
        return [
            'id'     => 7,
            'name'   => 'vendors_search canonical — licitação ausente',
            'pass'   => $pass,
            'count'  => $count,
            'detail' => $pass
                ? "Backfill aplicado em biomie_vendedores_omie: {$count} vendors excluídos."
                : 'Backfill NÃO aplicado em biomie_vendedores_omie. Execute --apply.',
            'skipped' => false,
        ];
    }

    if (empty($licitacaoCodes)) {
        return [
            'id'     => 7,
            'name'   => 'vendors_search canonical — licitação ausente',
            'pass'   => false,
            'detail' => 'Coluna is_biomie_commercial_visible ausente E sem códigos licitação — migração + backfill pendentes.',
            'skipped' => false,
        ];
    }

    return [
        'id'     => 7,
        'name'   => 'vendors_search canonical — licitação ausente',
        'pass'   => false,
        'count'  => count($licitacaoCodes),
        'detail' => "Coluna is_biomie_commercial_visible ausente em biomie_vendedores_omie — migração pendente.",
        'skipped' => false,
    ];
};

// Check 8: Base Comercial canonical — NF valor not included in totals
$checks[8] = static function () use ($pdo, $licitacaoCodes): array {
    if (!tableExists($pdo, 'biomie_notas_fiscais_canonicas')) {
        return ['id' => 8, 'name' => 'Base Comercial canonical — licitação excluída', 'pass' => true, 'detail' => 'Tabela ausente.', 'skipped' => true];
    }

    $hasVis = colExists($pdo, 'biomie_notas_fiscais_canonicas', 'is_biomie_commercial_visible');
    $hasScope = colExists($pdo, 'biomie_notas_fiscais_canonicas', 'operation_scope');

    $detail = '';
    $pass   = true;

    if ($hasVis) {
        // Verify licitação NFs are visible=0 (meaning they won't be counted)
        if (empty($licitacaoCodes)) {
            $detail = 'is_biomie_commercial_visible coluna presente. Nenhum código licitação para verificar.';
        } else {
            $ph   = implode(',', array_fill(0, count($licitacaoCodes), '?'));
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) AS cnt, COALESCE(SUM(valor_total), 0) AS valor
                 FROM biomie_notas_fiscais_canonicas
                 WHERE codigo_vendedor_omie IN ({$ph})
                   AND is_biomie_commercial_visible = 1
                   AND status_fiscal = 'autorizada'"
            );
            $stmt->execute(array_values($licitacaoCodes));
            $row   = $stmt->fetch(PDO::FETCH_ASSOC);
            $cnt   = (int) ($row['cnt'] ?? 0);
            $valor = (float) ($row['valor'] ?? 0);
            $pass  = $cnt === 0;
            $detail = $pass
                ? 'Nenhuma NF de licitação com visible=1 na Base Comercial.'
                : "ATENÇÃO: {$cnt} NFs de licitação com visible=1 ainda contabilizadas na Base Comercial (R$ " . number_format($valor, 2, ',', '.') . "). Execute backfill --apply.";
        }
    } else {
        $pass   = false;
        $detail = 'Coluna is_biomie_commercial_visible ausente — Base Comercial inclui Licitação sem filtro. Migração pendente.';
    }

    return [
        'id'     => 8,
        'name'   => 'Base Comercial canonical — licitação excluída',
        'pass'   => $pass,
        'detail' => $detail,
        'skipped' => false,
    ];
};

// Check 9: Raw/bronze preserved — data untouched in biomie_omie_bronze
$checks[9] = static function () use ($pdo, $licitacaoCodes): array {
    if (!tableExists($pdo, 'biomie_omie_bronze')) {
        return ['id' => 9, 'name' => 'Raw/bronze preservado', 'pass' => true, 'detail' => 'Tabela biomie_omie_bronze ausente — não aplicável.', 'skipped' => true];
    }

    // Bronze must NOT have is_biomie_commercial_visible column (it should be untouched)
    $hasBronzeVis = colExists($pdo, 'biomie_omie_bronze', 'is_biomie_commercial_visible');
    if ($hasBronzeVis) {
        return [
            'id'     => 9,
            'name'   => 'Raw/bronze preservado',
            'pass'   => false,
            'detail' => 'VIOLAÇÃO: coluna is_biomie_commercial_visible encontrada em biomie_omie_bronze — tabela bronze não deve ser alterada.',
            'skipped' => false,
        ];
    }

    // Verify bronze still has licitação data (it should be there, untouched)
    if (!empty($licitacaoCodes)) {
        $ph   = implode(',', array_fill(0, count($licitacaoCodes), '?'));
        $hasCodeCol = colExists($pdo, 'biomie_omie_bronze', 'codigo_vendedor');
        if ($hasCodeCol) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM biomie_omie_bronze WHERE codigo_vendedor IN ({$ph})");
            $stmt->execute(array_values($licitacaoCodes));
            $count = (int) $stmt->fetchColumn();
            return [
                'id'     => 9,
                'name'   => 'Raw/bronze preservado',
                'pass'   => true,
                'count'  => $count,
                'detail' => "Bronze intacto: {$count} registros licitação preservados em biomie_omie_bronze (sem coluna visibility).",
                'skipped' => false,
            ];
        }
    }

    return [
        'id'     => 9,
        'name'   => 'Raw/bronze preservado',
        'pass'   => true,
        'detail' => 'biomie_omie_bronze sem coluna visibility — bronze intacto conforme esperado.',
        'skipped' => false,
    ];
};

// Check 10: Migration columns — canonical tables have visibility columns
$checks[10] = static function () use ($pdo): array {
    $required = [
        'biomie_notas_fiscais_canonicas'       => ['is_biomie_commercial_visible', 'business_unit_scope'],
        'biomie_pedidos_canonicos'             => ['is_biomie_commercial_visible', 'business_unit_scope'],
        'biomie_titulos_financeiros_canonicos' => ['is_biomie_commercial_visible'],
        'biomie_vendedores_omie'               => ['is_biomie_commercial_visible', 'business_unit_scope'],
    ];

    $missing = [];
    $present = [];
    foreach ($required as $table => $cols) {
        if (!tableExists($pdo, $table)) {
            $missing[] = "{$table} (tabela ausente)";
            continue;
        }
        foreach ($cols as $col) {
            if (colExists($pdo, $table, $col)) {
                $present[] = "{$table}.{$col}";
            } else {
                $missing[] = "{$table}.{$col}";
            }
        }
    }

    $pass = empty($missing);
    return [
        'id'     => 10,
        'name'   => 'Migration columns — colunas de visibilidade presentes',
        'pass'   => $pass,
        'detail' => $pass
            ? 'Todas as colunas de visibilidade presentes em tabelas canônicas.'
            : 'Colunas ausentes: ' . implode(', ', $missing) . '. Execute as migrações Version20260522020000 e Version20260522030000.',
        'present' => $present,
        'missing' => $missing,
        'skipped' => false,
    ];
};

// ── Run checks ────────────────────────────────────────────────────────────────

$results   = [];
$passed    = 0;
$failed    = 0;
$skipped   = 0;
$startedAt = microtime(true);

$checksToRun = $onlyCheck !== null
    ? (isset($checks[$onlyCheck]) ? [$onlyCheck => $checks[$onlyCheck]] : [])
    : $checks;

foreach ($checksToRun as $id => $checkFn) {
    try {
        $result = $checkFn();
    } catch (Throwable $e) {
        $result = [
            'id'     => $id,
            'name'   => "Check #{$id}",
            'pass'   => false,
            'detail' => 'Exceção: ' . $e->getMessage(),
            'skipped' => false,
        ];
    }

    $results[] = $result;

    if ($result['skipped'] ?? false) {
        $skipped++;
    } elseif ($result['pass']) {
        $passed++;
    } else {
        $failed++;
        if ($failFast) {
            break;
        }
    }
}

$elapsedMs = round((microtime(true) - $startedAt) * 1000, 1);

$output = [
    'summary' => [
        'total'      => count($results),
        'passed'     => $passed,
        'failed'     => $failed,
        'skipped'    => $skipped,
        'elapsed_ms' => $elapsedMs,
        'licitacao_vendors_detected' => count($licitacaoCodes),
        'licitacao_codes'            => $licitacaoCodes,
        'executed_at'                => date('Y-m-d H:i:s'),
        'overall'    => $failed === 0 ? 'PASS' : 'FAIL',
    ],
    'checks' => $results,
];

// ── Output ────────────────────────────────────────────────────────────────────

if ($asJson) {
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if ($pretty) {
        $flags |= JSON_PRETTY_PRINT;
    }
    echo json_encode($output, $flags) . "\n";
    exit($failed > 0 ? 1 : 0);
}

// Human-readable
$overall = $output['summary']['overall'];
$line    = str_repeat('─', 60);

echo "\n{$line}\n";
echo " Fase L — Guard de Visibilidade Comercial\n";
echo "{$line}\n";
echo " Vendors licitação detectados : " . count($licitacaoCodes) . "\n";
if (!empty($licitacaoCodes)) {
    echo " Códigos : " . implode(', ', $licitacaoCodes) . "\n";
}
echo "{$line}\n\n";

foreach ($results as $r) {
    $id      = str_pad((string) ($r['id'] ?? '?'), 2, ' ', STR_PAD_LEFT);
    $skp     = $r['skipped'] ?? false;
    $icon    = $skp ? 'SKIP' : ($r['pass'] ? 'PASS' : 'FAIL');
    $padIcon = str_pad($icon, 4);
    $name    = $r['name'] ?? '';
    $count   = isset($r['count']) ? " [{$r['count']} linhas]" : '';
    echo "  #{$id}  [{$padIcon}]  {$name}{$count}\n";
    if (!$r['pass'] || $skp) {
        echo "         {$r['detail']}\n";
    }
}

echo "\n{$line}\n";
echo " Resultado : {$overall}   ({$passed} pass, {$failed} fail, {$skipped} skip)   {$elapsedMs}ms\n";
echo "{$line}\n\n";

if ($failed > 0) {
    echo "Ações recomendadas:\n";
    foreach ($results as $r) {
        if (!($r['skipped'] ?? false) && !$r['pass']) {
            echo "  [{$r['id']}] " . ($r['detail'] ?? '') . "\n";
        }
    }
    echo "\n";
}

exit($failed > 0 ? 1 : 0);

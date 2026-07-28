<?php

/**
 * tools/dev/backfill_visibility_scope.php
 *
 * Fase L — Backfill/classificação de visibilidade comercial.
 *
 * Reads biomie_vendor_scope_rules (or auto-detects licitação vendors) and
 * updates is_biomie_commercial_visible + business_unit_scope on canonical tables.
 *
 * Does NOT touch: operation_scope, Base Comercial NF, bronze/raw tables.
 *
 * Usage:
 *   php tools/dev/backfill_visibility_scope.php --status
 *   php tools/dev/backfill_visibility_scope.php --dry-run
 *   php tools/dev/backfill_visibility_scope.php --apply
 *   php tools/dev/backfill_visibility_scope.php --apply --start=2024-01-01 --end=2026-12-31
 *   php tools/dev/backfill_visibility_scope.php --apply --json
 *   php tools/dev/backfill_visibility_scope.php --status --pretty
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

// ── CLI args ──────────────────────────────────────────────────────────────────

$opts = getopt('', [
    'status',
    'dry-run',
    'apply',
    'start:',
    'end:',
    'json',
    'pretty',
    'help',
]);

$modeStatus = isset($opts['status']);
$modeDry    = isset($opts['dry-run']);
$modeApply  = isset($opts['apply']);
$asJson     = isset($opts['json']);
$pretty     = isset($opts['pretty']);
$helpFlag   = isset($opts['help']);

$filterStart = isset($opts['start']) ? (string) $opts['start'] : null;
$filterEnd   = isset($opts['end'])   ? (string) $opts['end']   : null;

if ($helpFlag || (!$modeStatus && !$modeDry && !$modeApply)) {
    echo <<<HELP
Fase L — Backfill de visibilidade comercial

Usage:
  php tools/dev/backfill_visibility_scope.php --status
  php tools/dev/backfill_visibility_scope.php --dry-run
  php tools/dev/backfill_visibility_scope.php --apply [--start=YYYY-MM-DD] [--end=YYYY-MM-DD]
  php tools/dev/backfill_visibility_scope.php --apply --json

Modes:
  --status    Show current state: how many rows are classified, pending, etc.
  --dry-run   Show what WOULD be updated without touching the DB.
  --apply     Execute updates on canonical tables.

Options:
  --start     Restrict to data_competencia >= YYYY-MM-DD (optional, default: all time)
  --end       Restrict to data_competencia <= YYYY-MM-DD (optional, default: all time)
  --json      Output as JSON
  --pretty    Indent JSON output

HELP;
    exit(0);
}

// ── DB connection ─────────────────────────────────────────────────────────────

$config = new \BIomie\Config();
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

// ── Helpers ───────────────────────────────────────────────────────────────────

function colExists(PDO $pdo, string $table, string $col): bool
{
    static $cache = [];
    $key = "{$table}:{$col}";
    if (!isset($cache[$key])) {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?"
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
    $n = mb_strtolower(trim($name));
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

function isLicitacaoName(string $name): bool
{
    return str_contains(normalizeName($name), 'LICITAC');
}

// ── Discover licitação vendors ────────────────────────────────────────────────

/**
 * Returns array of ['codigo_omie' => ..., 'nome' => ..., 'source' => ...].
 */
function discoverLicitacaoVendors(PDO $pdo): array
{
    $vendors = [];

    // Priority 1: biomie_vendor_scope_rules (manually classified)
    if (tableExists($pdo, 'biomie_vendor_scope_rules')) {
        $stmt = $pdo->query(
            "SELECT codigo_vendedor_omie AS codigo_omie,
                    nome_vendedor        AS nome,
                    business_unit_scope,
                    is_biomie_commercial_visible
             FROM biomie_vendor_scope_rules
             WHERE active = 1
               AND (is_biomie_commercial_visible = 0 OR business_unit_scope != 'comercial')"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $vendors[$row['codigo_omie']] = [
                'codigo_omie'                 => (string) ($row['codigo_omie'] ?? ''),
                'nome'                        => (string) ($row['nome'] ?? ''),
                'business_unit_scope'         => (string) ($row['business_unit_scope'] ?? 'licitacao'),
                'is_biomie_commercial_visible' => (int) ($row['is_biomie_commercial_visible'] ?? 0),
                'source'                      => 'vendor_scope_rules',
            ];
        }
    }

    // Priority 2: biomie_vendedores_omie name detection (auto-detect)
    if (tableExists($pdo, 'biomie_vendedores_omie')) {
        $stmt = $pdo->query(
            "SELECT codigo_omie, nome FROM biomie_vendedores_omie WHERE ativo = 1 OR ativo IS NULL"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $code = (string) ($row['codigo_omie'] ?? '');
            if ($code === '' || isset($vendors[$code])) {
                continue;
            }
            if (isLicitacaoName((string) ($row['nome'] ?? ''))) {
                $vendors[$code] = [
                    'codigo_omie'                 => $code,
                    'nome'                        => (string) ($row['nome'] ?? ''),
                    'business_unit_scope'         => 'licitacao',
                    'is_biomie_commercial_visible' => 0,
                    'source'                      => 'auto_name_detection',
                ];
            }
        }
    }

    // Priority 3: NF nome_vendedor detection (fallback when no vendor dimension)
    if (empty($vendors) && tableExists($pdo, 'biomie_notas_fiscais_canonicas')) {
        $stmt = $pdo->query(
            "SELECT DISTINCT codigo_vendedor_omie, nome_vendedor
             FROM biomie_notas_fiscais_canonicas
             WHERE UPPER(nome_vendedor) LIKE '%LICITAC%'
               AND codigo_vendedor_omie IS NOT NULL
               AND codigo_vendedor_omie != ''
             LIMIT 50"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $code = (string) ($row['codigo_vendedor_omie'] ?? '');
            if ($code === '' || isset($vendors[$code])) {
                continue;
            }
            $vendors[$code] = [
                'codigo_omie'                 => $code,
                'nome'                        => (string) ($row['nome_vendedor'] ?? ''),
                'business_unit_scope'         => 'licitacao',
                'is_biomie_commercial_visible' => 0,
                'source'                      => 'nf_name_fallback',
            ];
        }
    }

    return array_values($vendors);
}

// ── Status mode ───────────────────────────────────────────────────────────────

function runStatus(PDO $pdo): array
{
    $result = [
        'mode'    => 'status',
        'tables'  => [],
        'vendors' => [],
    ];

    $licitacaoVendors = discoverLicitacaoVendors($pdo);
    $result['vendors'] = array_map(fn($v) => [
        'codigo_omie'  => $v['codigo_omie'],
        'nome'         => $v['nome'],
        'scope'        => $v['business_unit_scope'],
        'visible'      => $v['is_biomie_commercial_visible'],
        'source'       => $v['source'],
    ], $licitacaoVendors);

    $codes = array_filter(array_column($licitacaoVendors, 'codigo_omie'));

    $tables = [
        'biomie_notas_fiscais_canonicas'       => ['date_col' => 'COALESCE(data_competencia, data_emissao)'],
        'biomie_pedidos_canonicos'             => ['date_col' => 'COALESCE(data_competencia, data_emissao)'],
        'biomie_titulos_financeiros_canonicos' => ['date_col' => 'data_vencimento'],
        'biomie_vendedores_omie'               => ['date_col' => null],
    ];

    foreach ($tables as $table => $meta) {
        $entry = [
            'table'              => $table,
            'has_visibility_col' => colExists($pdo, $table, 'is_biomie_commercial_visible'),
            'has_scope_col'      => colExists($pdo, $table, 'business_unit_scope'),
        ];

        if (!tableExists($pdo, $table)) {
            $entry['exists'] = false;
            $result['tables'][] = $entry;
            continue;
        }
        $entry['exists'] = true;

        if ($entry['has_visibility_col']) {
            try {
                $stmt = $pdo->query("SELECT COUNT(*) FROM {$table} WHERE is_biomie_commercial_visible = 0");
                $entry['rows_excluded'] = (int) $stmt->fetchColumn();

                $stmt = $pdo->query("SELECT COUNT(*) FROM {$table} WHERE is_biomie_commercial_visible IS NULL");
                $entry['rows_null'] = (int) $stmt->fetchColumn();

                $stmt = $pdo->query("SELECT COUNT(*) FROM {$table}");
                $entry['rows_total'] = (int) $stmt->fetchColumn();
            } catch (Throwable) {}
        }

        if (!empty($codes) && colExists($pdo, $table, 'codigo_vendedor_omie')) {
            $in = implode(',', array_fill(0, count($codes), '?'));
            try {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE codigo_vendedor_omie IN ({$in})");
                $stmt->execute(array_values($codes));
                $entry['rows_licitacao_by_code'] = (int) $stmt->fetchColumn();
            } catch (Throwable) {}
        }

        $result['tables'][] = $entry;
    }

    return $result;
}

// ── Dry-run / Apply mode ──────────────────────────────────────────────────────

function runBackfill(PDO $pdo, bool $apply, ?string $filterStart, ?string $filterEnd): array
{
    $licitacaoVendors = discoverLicitacaoVendors($pdo);

    $result = [
        'mode'    => $apply ? 'apply' : 'dry-run',
        'vendors' => array_map(fn($v) => [
            'codigo_omie' => $v['codigo_omie'],
            'nome'        => $v['nome'],
            'scope'       => $v['business_unit_scope'],
            'visible'     => $v['is_biomie_commercial_visible'],
            'source'      => $v['source'],
        ], $licitacaoVendors),
        'updates' => [],
    ];

    if (empty($licitacaoVendors)) {
        $result['warning'] = 'Nenhum vendedor de licitacao detectado. Nada a classificar.';
        return $result;
    }

    $codes = array_filter(array_column($licitacaoVendors, 'codigo_omie'));
    $codePlaceholders = implode(',', array_fill(0, count($codes), '?'));

    // Group by (scope, visible) to handle mixed rules
    $byRule = [];
    foreach ($licitacaoVendors as $v) {
        $key = $v['business_unit_scope'] . ':' . $v['is_biomie_commercial_visible'];
        $byRule[$key][] = $v['codigo_omie'];
    }

    // ── NF canonicas ─────────────────────────────────────────────────────────
    if (tableExists($pdo, 'biomie_notas_fiscais_canonicas')) {
        $entry = ['table' => 'biomie_notas_fiscais_canonicas'];
        $hasVis   = colExists($pdo, 'biomie_notas_fiscais_canonicas', 'is_biomie_commercial_visible');
        $hasScope = colExists($pdo, 'biomie_notas_fiscais_canonicas', 'business_unit_scope');
        $hasRuleSource = colExists($pdo, 'biomie_notas_fiscais_canonicas', 'visibility_rule_source');
        $hasChecked    = colExists($pdo, 'biomie_notas_fiscais_canonicas', 'visibility_checked_at');
        $hasCode  = colExists($pdo, 'biomie_notas_fiscais_canonicas', 'codigo_vendedor_omie');

        if (!$hasVis && !$hasScope) {
            $entry['skipped'] = 'Colunas ausentes — migração pendente.';
        } elseif (!$hasCode) {
            $entry['skipped'] = 'Coluna codigo_vendedor_omie ausente.';
        } else {
            // Build SET clause
            $sets = [];
            if ($hasVis)        $sets[] = 'is_biomie_commercial_visible = ?';
            if ($hasScope)      $sets[] = 'business_unit_scope = ?';
            if ($hasRuleSource) $sets[] = "visibility_rule_source = 'licitacao_backfill'";
            if ($hasChecked)    $sets[] = 'visibility_checked_at = NOW()';
            $setClause = implode(', ', $sets);

            // Date range filter
            $dateFilter = '';
            $dateParams = [];
            if ($filterStart !== null) {
                $dateFilter  .= ' AND COALESCE(data_competencia, data_emissao) >= ?';
                $dateParams[] = $filterStart;
            }
            if ($filterEnd !== null) {
                $dateFilter  .= ' AND COALESCE(data_competencia, data_emissao) <= ?';
                $dateParams[] = $filterEnd;
            }

            $totalAffected = 0;
            foreach ($byRule as $ruleKey => $ruleCodes) {
                [$scope, $visible] = explode(':', $ruleKey);
                $visible = (int) $visible;
                $ph = implode(',', array_fill(0, count($ruleCodes), '?'));

                // Count first
                $countParams = array_merge($ruleCodes, $dateParams);
                $countSql    = "SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas WHERE codigo_vendedor_omie IN ({$ph}){$dateFilter}";
                try {
                    $countStmt = $pdo->prepare($countSql);
                    $countStmt->execute($countParams);
                    $count = (int) $countStmt->fetchColumn();
                } catch (Throwable $e) {
                    $entry['error'] = $e->getMessage();
                    $count = 0;
                }

                $entry['rows_to_update'][] = ['scope' => $scope, 'visible' => $visible, 'codes' => $ruleCodes, 'count' => $count];
                $totalAffected += $count;

                if ($apply && $count > 0 && $setClause !== '') {
                    $bindParams = [];
                    if ($hasVis)   $bindParams[] = $visible;
                    if ($hasScope) $bindParams[] = $scope;
                    $bindParams = array_merge($bindParams, $ruleCodes, $dateParams);
                    $updateSql = "UPDATE biomie_notas_fiscais_canonicas SET {$setClause} WHERE codigo_vendedor_omie IN ({$ph}){$dateFilter}";
                    try {
                        $stmt = $pdo->prepare($updateSql);
                        $stmt->execute($bindParams);
                        $entry['rows_updated'] = ($entry['rows_updated'] ?? 0) + $stmt->rowCount();
                    } catch (Throwable $e) {
                        $entry['error'] = $e->getMessage();
                    }
                }
            }
            $entry['rows_total_affected'] = $totalAffected;
        }
        $result['updates'][] = $entry;
    }

    // ── Pedidos canonicos ─────────────────────────────────────────────────────
    if (tableExists($pdo, 'biomie_pedidos_canonicos')) {
        $entry = ['table' => 'biomie_pedidos_canonicos'];
        $hasVis   = colExists($pdo, 'biomie_pedidos_canonicos', 'is_biomie_commercial_visible');
        $hasScope = colExists($pdo, 'biomie_pedidos_canonicos', 'business_unit_scope');
        $hasCode  = colExists($pdo, 'biomie_pedidos_canonicos', 'codigo_vendedor_omie');

        if (!$hasVis && !$hasScope) {
            $entry['skipped'] = 'Colunas ausentes — migração pendente.';
        } elseif (!$hasCode) {
            $entry['skipped'] = 'Coluna codigo_vendedor_omie ausente.';
        } else {
            $sets = [];
            if ($hasVis)   $sets[] = 'is_biomie_commercial_visible = ?';
            if ($hasScope) $sets[] = 'business_unit_scope = ?';
            if (colExists($pdo, 'biomie_pedidos_canonicos', 'visibility_rule_source'))
                $sets[] = "visibility_rule_source = 'licitacao_backfill'";
            if (colExists($pdo, 'biomie_pedidos_canonicos', 'visibility_checked_at'))
                $sets[] = 'visibility_checked_at = NOW()';
            $setClause = implode(', ', $sets);

            $dateFilter = '';
            $dateParams = [];
            if ($filterStart !== null) {
                $dateFilter  .= ' AND COALESCE(data_competencia, data_emissao) >= ?';
                $dateParams[] = $filterStart;
            }
            if ($filterEnd !== null) {
                $dateFilter  .= ' AND COALESCE(data_competencia, data_emissao) <= ?';
                $dateParams[] = $filterEnd;
            }

            $totalAffected = 0;
            foreach ($byRule as $ruleKey => $ruleCodes) {
                [$scope, $visible] = explode(':', $ruleKey);
                $visible = (int) $visible;
                $ph = implode(',', array_fill(0, count($ruleCodes), '?'));

                $countParams = array_merge($ruleCodes, $dateParams);
                $countSql    = "SELECT COUNT(*) FROM biomie_pedidos_canonicos WHERE codigo_vendedor_omie IN ({$ph}){$dateFilter}";
                try {
                    $countStmt = $pdo->prepare($countSql);
                    $countStmt->execute($countParams);
                    $count = (int) $countStmt->fetchColumn();
                } catch (Throwable $e) {
                    $entry['error'] = $e->getMessage();
                    $count = 0;
                }

                $entry['rows_to_update'][] = ['scope' => $scope, 'visible' => $visible, 'count' => $count];
                $totalAffected += $count;

                if ($apply && $count > 0 && $setClause !== '') {
                    $bindParams = [];
                    if ($hasVis)   $bindParams[] = $visible;
                    if ($hasScope) $bindParams[] = $scope;
                    $bindParams = array_merge($bindParams, $ruleCodes, $dateParams);
                    $updateSql = "UPDATE biomie_pedidos_canonicos SET {$setClause} WHERE codigo_vendedor_omie IN ({$ph}){$dateFilter}";
                    try {
                        $stmt = $pdo->prepare($updateSql);
                        $stmt->execute($bindParams);
                        $entry['rows_updated'] = ($entry['rows_updated'] ?? 0) + $stmt->rowCount();
                    } catch (Throwable $e) {
                        $entry['error'] = $e->getMessage();
                    }
                }
            }
            $entry['rows_total_affected'] = $totalAffected;
        }
        $result['updates'][] = $entry;
    }

    // ── Títulos financeiros ───────────────────────────────────────────────────
    if (tableExists($pdo, 'biomie_titulos_financeiros_canonicos')) {
        $entry = ['table' => 'biomie_titulos_financeiros_canonicos'];
        $hasVis  = colExists($pdo, 'biomie_titulos_financeiros_canonicos', 'is_biomie_commercial_visible');
        $hasCode = colExists($pdo, 'biomie_titulos_financeiros_canonicos', 'codigo_vendedor_omie');

        if (!$hasVis) {
            $entry['skipped'] = 'Coluna is_biomie_commercial_visible ausente — migração pendente.';
        } elseif (!$hasCode) {
            $entry['skipped'] = 'Coluna codigo_vendedor_omie ausente.';
        } else {
            $sets = ['is_biomie_commercial_visible = ?'];
            if (colExists($pdo, 'biomie_titulos_financeiros_canonicos', 'business_unit_scope'))
                $sets[] = 'business_unit_scope = ?';
            if (colExists($pdo, 'biomie_titulos_financeiros_canonicos', 'visibility_rule_source'))
                $sets[] = "visibility_rule_source = 'licitacao_backfill'";
            $setClause = implode(', ', $sets);

            $dateFilter = '';
            $dateParams = [];
            if ($filterStart !== null) {
                $dateFilter  .= ' AND data_vencimento >= ?';
                $dateParams[] = $filterStart;
            }
            if ($filterEnd !== null) {
                $dateFilter  .= ' AND data_vencimento <= ?';
                $dateParams[] = $filterEnd;
            }

            $totalAffected = 0;
            foreach ($byRule as $ruleKey => $ruleCodes) {
                [$scope, $visible] = explode(':', $ruleKey);
                $visible = (int) $visible;
                $ph = implode(',', array_fill(0, count($ruleCodes), '?'));

                $countParams = array_merge($ruleCodes, $dateParams);
                $countSql    = "SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos WHERE codigo_vendedor_omie IN ({$ph}){$dateFilter}";
                try {
                    $countStmt = $pdo->prepare($countSql);
                    $countStmt->execute($countParams);
                    $count = (int) $countStmt->fetchColumn();
                } catch (Throwable $e) {
                    $entry['error'] = $e->getMessage();
                    $count = 0;
                }

                $entry['rows_to_update'][] = ['scope' => $scope, 'visible' => $visible, 'count' => $count];
                $totalAffected += $count;

                if ($apply && $count > 0) {
                    $bindParams = [$visible];
                    if (colExists($pdo, 'biomie_titulos_financeiros_canonicos', 'business_unit_scope'))
                        $bindParams[] = $scope;
                    $bindParams = array_merge($bindParams, $ruleCodes, $dateParams);
                    $updateSql = "UPDATE biomie_titulos_financeiros_canonicos SET {$setClause} WHERE codigo_vendedor_omie IN ({$ph}){$dateFilter}";
                    try {
                        $stmt = $pdo->prepare($updateSql);
                        $stmt->execute($bindParams);
                        $entry['rows_updated'] = ($entry['rows_updated'] ?? 0) + $stmt->rowCount();
                    } catch (Throwable $e) {
                        $entry['error'] = $e->getMessage();
                    }
                }
            }
            $entry['rows_total_affected'] = $totalAffected;
        }
        $result['updates'][] = $entry;
    }

    // ── biomie_vendedores_omie ────────────────────────────────────────────────
    if (tableExists($pdo, 'biomie_vendedores_omie')) {
        $entry = ['table' => 'biomie_vendedores_omie'];
        $hasVis   = colExists($pdo, 'biomie_vendedores_omie', 'is_biomie_commercial_visible');
        $hasScope = colExists($pdo, 'biomie_vendedores_omie', 'business_unit_scope');

        if (!$hasVis && !$hasScope) {
            $entry['skipped'] = 'Colunas ausentes — migração pendente.';
        } else {
            $sets = [];
            if ($hasVis)   $sets[] = 'is_biomie_commercial_visible = ?';
            if ($hasScope) $sets[] = 'business_unit_scope = ?';
            $setClause = implode(', ', $sets);

            $totalAffected = 0;
            foreach ($byRule as $ruleKey => $ruleCodes) {
                [$scope, $visible] = explode(':', $ruleKey);
                $visible = (int) $visible;
                $ph = implode(',', array_fill(0, count($ruleCodes), '?'));

                try {
                    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM biomie_vendedores_omie WHERE codigo_omie IN ({$ph})");
                    $countStmt->execute(array_values($ruleCodes));
                    $count = (int) $countStmt->fetchColumn();
                } catch (Throwable $e) {
                    $entry['error'] = $e->getMessage();
                    $count = 0;
                }

                $entry['rows_to_update'][] = ['scope' => $scope, 'visible' => $visible, 'count' => $count];
                $totalAffected += $count;

                if ($apply && $count > 0 && $setClause !== '') {
                    $bindParams = [];
                    if ($hasVis)   $bindParams[] = $visible;
                    if ($hasScope) $bindParams[] = $scope;
                    $bindParams = array_merge($bindParams, array_values($ruleCodes));
                    $updateSql = "UPDATE biomie_vendedores_omie SET {$setClause} WHERE codigo_omie IN ({$ph})";
                    try {
                        $stmt = $pdo->prepare($updateSql);
                        $stmt->execute($bindParams);
                        $entry['rows_updated'] = ($entry['rows_updated'] ?? 0) + $stmt->rowCount();
                    } catch (Throwable $e) {
                        $entry['error'] = $e->getMessage();
                    }
                }
            }
            $entry['rows_total_affected'] = $totalAffected;
        }
        $result['updates'][] = $entry;
    }

    // Summary
    $result['summary'] = [
        'vendors_licitacao' => count($licitacaoVendors),
        'tables_processed'  => count($result['updates']),
        'filter_start'      => $filterStart,
        'filter_end'        => $filterEnd,
        'executed_at'       => date('Y-m-d H:i:s'),
    ];

    return $result;
}

// ── Render ────────────────────────────────────────────────────────────────────

function renderText(array $data): void
{
    $mode = $data['mode'] ?? 'unknown';
    echo "=== Fase L — Backfill de Visibilidade [{$mode}] ===\n\n";

    if (!empty($data['vendors'])) {
        echo "Vendedores Licitação detectados (" . count($data['vendors']) . "):\n";
        foreach ($data['vendors'] as $v) {
            $vis   = $v['visible'] === 0 ? 'INVISÍVEL' : 'visível';
            echo "  [{$v['codigo_omie']}] {$v['nome']} → scope={$v['scope']} visible={$vis} (via {$v['source']})\n";
        }
        echo "\n";
    } else {
        echo "Nenhum vendedor de licitação detectado.\n\n";
    }

    if ($mode === 'status') {
        foreach ($data['tables'] ?? [] as $t) {
            $exists = ($t['exists'] ?? true) ? 'OK' : 'AUSENTE';
            $visCol = ($t['has_visibility_col'] ?? false) ? 'OK' : 'PENDENTE';
            $scpCol = ($t['has_scope_col'] ?? false) ? 'OK' : 'PENDENTE';
            echo "Tabela {$t['table']}: exists={$exists}, is_biomie_commercial_visible={$visCol}, business_unit_scope={$scpCol}\n";
            if (isset($t['rows_excluded']))     echo "  Excluídas (visible=0): {$t['rows_excluded']}\n";
            if (isset($t['rows_null']))         echo "  Null visible: {$t['rows_null']}\n";
            if (isset($t['rows_total']))        echo "  Total: {$t['rows_total']}\n";
            if (isset($t['rows_licitacao_by_code'])) echo "  Por código licitação: {$t['rows_licitacao_by_code']}\n";
        }
    } else {
        foreach ($data['updates'] ?? [] as $u) {
            echo "Tabela {$u['table']}:\n";
            if (isset($u['skipped'])) {
                echo "  SKIP: {$u['skipped']}\n";
            } elseif (isset($u['error'])) {
                echo "  ERRO: {$u['error']}\n";
            } else {
                $total = $u['rows_total_affected'] ?? 0;
                $updated = $u['rows_updated'] ?? null;
                echo "  Linhas a atualizar: {$total}\n";
                if ($updated !== null) echo "  Linhas atualizadas: {$updated}\n";
            }
        }
        if (isset($data['summary'])) {
            echo "\nSummary:\n";
            echo "  Vendors licitação: {$data['summary']['vendors_licitacao']}\n";
            if ($data['summary']['filter_start']) echo "  Período: {$data['summary']['filter_start']} → {$data['summary']['filter_end']}\n";
        }
    }

    if (isset($data['warning'])) {
        echo "\nAVISO: {$data['warning']}\n";
    }

    echo "\nDone.\n";
}

// ── Main ──────────────────────────────────────────────────────────────────────

try {
    if ($modeStatus) {
        $data = runStatus($pdo);
    } else {
        $data = runBackfill($pdo, $modeApply, $filterStart, $filterEnd);
    }

    if ($asJson) {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }
        echo json_encode($data, $flags) . "\n";
    } else {
        renderText($data);
    }

} catch (Throwable $e) {
    fwrite(STDERR, "ERRO FATAL: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}

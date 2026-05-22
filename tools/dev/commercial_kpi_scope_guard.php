<?php

declare(strict_types=1);

/**
 * Fase H.5 — T6: Commercial KPI Scope Guard
 *
 * Verifica por módulo se cada superfície de KPI comercial está corretamente
 * restrita a operation_scope = 'commercial'. Cobre todos os módulos:
 * Dashboard, Vendor 360, Plano de Voo, Mix, Geo, Ranking,
 * Comparação de Meses, Top Notas Fiscais, Geo × CNAE.
 *
 * Para auditoria de contaminação nos marts:
 *   php tools/dev/operation_scope_mart_audit.php
 *
 * Para cross-check de receita entre marts:
 *   php tools/dev/commercial_recalibration_audit.php
 *
 * Uso:
 *   php tools/dev/commercial_kpi_scope_guard.php
 *   php tools/dev/commercial_kpi_scope_guard.php --json
 *   php tools/dev/commercial_kpi_scope_guard.php --module=geo
 *   php tools/dev/commercial_kpi_scope_guard.php --verbose
 *
 * Saída:
 *   0 — todos os módulos OK
 *   1 — um ou mais módulos com falha
 *   2 — erro de configuração
 */

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$opts      = getopt('', ['json', 'verbose', 'module:']);
$isJson    = isset($opts['json']);
$isVerbose = isset($opts['verbose']);
$onlyMod   = isset($opts['module']) ? strtolower(trim((string) $opts['module'])) : null;

$root = dirname(__DIR__, 2);

// ── DB setup ────────────────────────────────────────────────────────────────────

$config     = Config::fromEnvironment();
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo = null;

if (Database::isConfigured($config, $connection)) {
    try {
        $pdo = Database::connect($config, $connection);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    } catch (\Throwable $e) {
        if (!$isJson) {
            fwrite(STDERR, "AVISO: Banco indisponivel — apenas verificacoes de codigo serao executadas. " . $e->getMessage() . "\n");
        }
    }
}

// ── Helpers ────────────────────────────────────────────────────────────────────

function ksgTableExists(?\PDO $pdo, string $table): bool
{
    if ($pdo === null) {
        return false;
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Returns patterns NOT found in the file (empty array = all patterns present = PASS).
 */
function ksgCodeCheck(string $root, string $relPath, array $patterns): array
{
    $fullPath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
    if (!file_exists($fullPath)) {
        return ["FILE_NOT_FOUND: {$relPath}"];
    }
    $content = file_get_contents($fullPath);
    $missing = [];
    foreach ($patterns as $p) {
        if (!str_contains($content, $p)) {
            $missing[] = $p;
        }
    }
    return $missing;
}

/**
 * Run a scalar SQL check returning float. null = skipped (table missing or DB unavailable).
 */
function ksgQuery(?\PDO $pdo, string $label, string $sql): ?float
{
    if ($pdo === null) {
        return null;
    }
    try {
        $val = $pdo->query($sql)->fetchColumn();
        return $val === false ? null : (float) $val;
    } catch (\Throwable $e) {
        return null;
    }
}

// ── Module definitions ─────────────────────────────────────────────────────────
//
// code_checks: each entry is [relPath => [patterns…]] — ALL patterns must be
//              present in the file for the check to pass.
//
// db_checks:   each entry is [label, table_required, sql, expected_max].
//              actual <= expected_max → PASS.  null actual → SKIP (DB unavailable).

$modules = [
    [
        'id'    => 'dashboard',
        'name'  => 'Dashboard (visão geral)',
        'note'  => 'HomeBootstrapService delega para CanonicalOverviewPayloadBuilder que usa commercialScopeFilter()',
        'code_checks' => [
            'app/DataPlatform/Silver/Readers/CanonicalReadSupport.php' => [
                "operation_scope = 'commercial'",
            ],
            'app/Modules/Sales/Application/Overview/CanonicalOverviewPayloadBuilder.php' => [
                'summarizeInvoicesCommercial',
                'aggregateByVendorCommercial',
            ],
        ],
        'db_checks' => [
            [
                'label'          => 'mart_cliente_periodo: valor_nfe = canonical commercial ≤ R$0,01 delta',
                'table_required' => 'mart_cliente_periodo',
                'sql'            => "SELECT ABS(
                                      COALESCE((SELECT SUM(valor_nfe) FROM mart_cliente_periodo), 0)
                                    - COALESCE((SELECT SUM(valor_total) FROM biomie_notas_fiscais_canonicas
                                                WHERE status_fiscal='autorizada' AND operation_scope='commercial'), 0)
                                    )",
                'expected_max'   => 0.01,
            ],
        ],
    ],
    [
        'id'    => 'vendor360',
        'name'  => 'Vendor 360',
        'note'  => 'CanonicalVendorPayloadBuilder usa métodos *Commercial (summarizeCommercial, aggregateByVendorCommercial, etc.) da CanonicalRepository',
        'code_checks' => [
            'app/Modules/Sales/Application/Vendors/CanonicalVendorPayloadBuilder.php' => [
                'summarizeCommercial',
                'aggregateByVendorCommercial',
            ],
        ],
        'db_checks' => [
            [
                'label'          => 'mart_vendedor_dia: delta vs canonical commercial ≤ R$0,01',
                'table_required' => 'mart_vendedor_dia',
                'sql'            => "SELECT ABS(
                                      COALESCE((SELECT SUM(valor_nfe) FROM mart_vendedor_dia), 0)
                                    - COALESCE((SELECT SUM(valor_total) FROM biomie_notas_fiscais_canonicas
                                                WHERE status_fiscal='autorizada' AND operation_scope='commercial'), 0)
                                    )",
                'expected_max'   => 0.01,
            ],
        ],
    ],
    [
        'id'    => 'flight_plan',
        'name'  => 'Plano de Voo',
        'note'  => 'FlightPlanV2Service usa métodos *Commercial da CanonicalRepository — mart_vendedor_dia filtra por commercial',
        'code_checks' => [
            'app/FlightPlanV2Service.php' => [
                'aggregateInvoicedByVendorCommercial',
                'listCommercialVendorRoster',
            ],
        ],
        'db_checks' => [],
    ],
    [
        'id'    => 'mix',
        'name'  => 'Mix de Produtos',
        'note'  => 'ProductPeriodMartBuilder usa commercialScopeFragment() — remessa excluída do mix',
        'code_checks' => [
            'app/DataPlatform/Gold/Builders/ProductPeriodMartBuilder.php' => [
                'commercialScopeFragment',
            ],
        ],
        'db_checks' => [
            [
                'label'          => 'mart_produto_periodo: delta vs canonical merchandise ≤ R$0,01',
                'table_required' => 'mart_produto_periodo',
                'sql'            => "SELECT ABS(
                                      COALESCE((SELECT SUM(valor_total) FROM mart_produto_periodo), 0)
                                    - COALESCE((SELECT SUM(valor_mercadorias) FROM biomie_notas_fiscais_canonicas
                                                WHERE status_fiscal='autorizada' AND operation_scope='commercial'), 0)
                                    )",
                'expected_max'   => 0.01,
            ],
        ],
    ],
    [
        'id'    => 'geo',
        'name'  => 'Geo / Mapa',
        'note'  => "GeoBuyerMartBuilder aplica AND nf.operation_scope = 'commercial' inline — remessa não entra no mapa",
        'code_checks' => [
            'app/DataPlatform/Gold/Builders/GeoBuyerMartBuilder.php' => [
                "operation_scope = 'commercial'",
            ],
        ],
        'db_checks' => [
            [
                'label'          => 'mart_compradores_por_geo: delta vs canonical commercial ≤ R$0,01',
                'table_required' => 'mart_compradores_por_geo',
                'sql'            => "SELECT ABS(
                                      COALESCE((SELECT SUM(faturamento) FROM mart_compradores_por_geo), 0)
                                    - COALESCE((SELECT SUM(valor_total) FROM biomie_notas_fiscais_canonicas
                                                WHERE status_fiscal='autorizada' AND operation_scope='commercial'), 0)
                                    )",
                'expected_max'   => 0.01,
            ],
        ],
    ],
    [
        'id'    => 'ranking',
        'name'  => 'Ranking Comercial',
        'note'  => 'Ranking é calculado a partir de mart_vendedor_dia (commercial only) — subtítulo "Orç-PD" no front-end',
        'code_checks' => [
            'app/Modules/Sales/Application/Overview/OverviewPayloadBuilder.php' => [
                'Commercial',
            ],
        ],
        'db_checks' => [
            [
                'label'          => 'mart_vendedor_dia: delta vs canonical commercial ≤ R$0,01',
                'table_required' => 'mart_vendedor_dia',
                'sql'            => "SELECT ABS(
                                      COALESCE((SELECT SUM(valor_nfe) FROM mart_vendedor_dia), 0)
                                    - COALESCE((SELECT SUM(valor_total) FROM biomie_notas_fiscais_canonicas
                                                WHERE status_fiscal='autorizada' AND operation_scope='commercial'), 0)
                                    )",
                'expected_max'   => 0.01,
            ],
        ],
    ],
    [
        'id'    => 'month_comparison',
        'name'  => 'Comparação de Meses',
        'note'  => 'OverviewMonthComparisonBuilder usa métodos *Commercial da CanonicalRepository',
        'code_checks' => [
            'app/Modules/Sales/Application/Overview/OverviewMonthComparisonBuilder.php' => [
                'commercialScopeFilter',
            ],
        ],
        'db_checks' => [],
    ],
    [
        'id'    => 'top_invoices',
        'name'  => 'Top Notas Fiscais',
        'note'  => 'OverviewDetailBuilder usa CanonicalRepository — drawer exibe individualmente sem agregar KPIs',
        'code_checks' => [
            'app/Modules/Sales/Application/Overview/OverviewDetailBuilder.php' => [
                'CanonicalRepository',
            ],
        ],
        'db_checks' => [
            [
                'label'          => 'canonical_nf: zero receita de operation_scope=unknown',
                'table_required' => 'biomie_notas_fiscais_canonicas',
                'sql'            => "SELECT COALESCE(SUM(valor_total),0)
                                    FROM biomie_notas_fiscais_canonicas
                                    WHERE status_fiscal='autorizada' AND operation_scope='unknown'",
                'expected_max'   => 0.0,
            ],
        ],
    ],
    [
        'id'    => 'cnae_geo',
        'name'  => 'Geo × CNAE',
        'note'  => 'CustomerPeriodMartBuilder usa commercialScopeFragment() — clientes remessa-only excluídos',
        'code_checks' => [
            'app/DataPlatform/Gold/Builders/CustomerPeriodMartBuilder.php' => [
                'commercialScopeFragment',
            ],
        ],
        'db_checks' => [
            [
                'label'          => 'mart_vendas_por_cnae_cnpj: zero clientes com apenas NF remessa',
                'table_required' => 'mart_vendas_por_cnae_cnpj',
                'sql'            => "SELECT COUNT(*)
                                    FROM mart_vendas_por_cnae_cnpj cv
                                    WHERE cv.codigo_cliente_omie IN (
                                        SELECT codigo_cliente_omie FROM biomie_notas_fiscais_canonicas
                                        WHERE operation_scope='remittance' AND status_fiscal='autorizada'
                                    )
                                    AND cv.codigo_cliente_omie NOT IN (
                                        SELECT codigo_cliente_omie FROM biomie_notas_fiscais_canonicas
                                        WHERE operation_scope='commercial' AND status_fiscal='autorizada'
                                    )",
                'expected_max'   => 0.0,
            ],
        ],
    ],
];

// ── Filter by --module ─────────────────────────────────────────────────────────

if ($onlyMod !== null) {
    $modules = array_values(array_filter($modules, static fn ($m) => $m['id'] === $onlyMod));
    if (empty($modules)) {
        $ids = implode(', ', array_column(
            $modules === [] ? array_column($GLOBALS['modules'] ?? [], null) : [],
            'id'
        ));
        fwrite(STDERR, "ERRO: Módulo '{$onlyMod}' não encontrado.\n");
        exit(2);
    }
}

// ── Run checks ─────────────────────────────────────────────────────────────────

$results  = [];
$failures = 0;

foreach ($modules as $mod) {
    $modResult = [
        'id'          => $mod['id'],
        'name'        => $mod['name'],
        'note'        => $mod['note'] ?? null,
        'code_checks' => [],
        'db_checks'   => [],
        'status'      => 'OK',
    ];

    // Code checks
    foreach ($mod['code_checks'] as $relPath => $patterns) {
        $missing = ksgCodeCheck($root, $relPath, $patterns);
        $ok      = empty($missing);
        $modResult['code_checks'][] = [
            'file'    => $relPath,
            'ok'      => $ok,
            'missing' => $missing,
        ];
        if (!$ok) {
            $modResult['status'] = 'FAIL';
            $failures++;
        }
    }

    // DB checks
    foreach ($mod['db_checks'] as $dbc) {
        $tableOk = ksgTableExists($pdo, $dbc['table_required']);
        if ($pdo === null) {
            $checkResult = [
                'label'    => $dbc['label'],
                'status'   => 'SKIP',
                'actual'   => null,
                'expected' => $dbc['expected_max'],
                'reason'   => 'DB unavailable',
            ];
        } elseif (!$tableOk) {
            $checkResult = [
                'label'    => $dbc['label'],
                'status'   => 'SKIP',
                'actual'   => null,
                'expected' => $dbc['expected_max'],
                'reason'   => "table {$dbc['table_required']} not found",
            ];
        } else {
            $actual = ksgQuery($pdo, $dbc['label'], $dbc['sql']);
            if ($actual === null) {
                $checkResult = [
                    'label'    => $dbc['label'],
                    'status'   => 'ERROR',
                    'actual'   => null,
                    'expected' => $dbc['expected_max'],
                    'reason'   => 'query failed',
                ];
                $modResult['status'] = 'FAIL';
                $failures++;
            } else {
                $pass        = $actual <= $dbc['expected_max'];
                $checkResult = [
                    'label'    => $dbc['label'],
                    'status'   => $pass ? 'OK' : 'FAIL',
                    'actual'   => round($actual, 2),
                    'expected' => $dbc['expected_max'],
                    'reason'   => null,
                ];
                if (!$pass) {
                    $modResult['status'] = 'FAIL';
                    $failures++;
                }
            }
        }
        $modResult['db_checks'][] = $checkResult;
    }

    $results[] = $modResult;
}

// ── Output ─────────────────────────────────────────────────────────────────────

$summary = [
    'modules_checked' => count($results),
    'failures'        => $failures,
    'clean'           => $failures === 0,
    'remittance_rules' => [
        'excluded_from' => ['Dashboard', 'Vendor 360', 'Plano de Voo', 'Mix', 'Geo', 'Ranking'],
        'visible_in'    => ['NF do Período — aba Remessa', 'Dashboard — card Remessa (informativo)'],
        'enforce_mode'  => getenv('BIOMIE_OPERATION_SCOPE_ENFORCED') === '1' ? 'ACTIVE' : 'INACTIVE',
    ],
    'modules' => $results,
];

if ($isJson) {
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit($failures > 0 ? 1 : 0);
}

// Text output
echo "\n=== commercial_kpi_scope_guard (Fase H.5 — T6) ===\n";
echo "Modulos verificados: " . count($results) . "\n";
echo "Enforce mode: " . ($summary['remittance_rules']['enforce_mode'] === 'ACTIVE' ? 'ATIVO' : 'INATIVO') . "\n\n";

foreach ($results as $mod) {
    $icon = $mod['status'] === 'OK' ? '[OK  ]' : '[FAIL]';
    echo "{$icon} {$mod['name']}\n";

    if ($isVerbose || $mod['status'] !== 'OK') {
        foreach ($mod['code_checks'] as $cc) {
            $cIcon = $cc['ok'] ? '  [CODE OK ]' : '  [CODE ERR]';
            echo "{$cIcon} {$cc['file']}\n";
            foreach ($cc['missing'] as $m) {
                echo "             Padrao ausente: {$m}\n";
            }
        }
        foreach ($mod['db_checks'] as $dc) {
            $dIcon = match ($dc['status']) {
                'OK'    => '  [DB   OK ]',
                'SKIP'  => '  [DB  SKIP]',
                'ERROR' => '  [DB  ERRO]',
                'FAIL'  => '  [DB  FAIL]',
                default => '  [DB     ?]',
            };
            echo "{$dIcon} {$dc['label']}\n";
            if ($dc['status'] === 'FAIL') {
                echo "             Esperado <= {$dc['expected']}  Real: {$dc['actual']}\n";
            }
            if ($dc['reason'] !== null) {
                echo "             Motivo: {$dc['reason']}\n";
            }
        }
        if ($isVerbose && $mod['note'] !== null) {
            echo "  Nota: {$mod['note']}\n";
        }
    }
}

echo "\n";

if ($failures === 0) {
    echo "Resultado: LIMPO — todos os modulos comerciais filtrados corretamente.\n";
    echo "Remessa: visivel apenas em NF do Periodo (aba) e card informativo do Dashboard.\n\n";
    exit(0);
}

echo "ATENCAO: {$failures} verificacao(es) falharam — execute com --verbose para detalhes.\n";
echo "Referencia: php tools/dev/commercial_recalibration_audit.php (cross-check de receita)\n\n";
exit(1);

<?php

declare(strict_types=1);

/**
 * Guard D50 — Consolidado SQL Safety
 *
 * Varre todos os arquivos PHP de API e cron em busca de padrões que
 * constroem SQL com company_id diretamente a partir de TenantContext::companyId()
 * em vez de usar TenantContext::sqlFilter() ou allowedCompanyIds().
 *
 * Em modo consolidado, sqlFilter() retorna "IN (1,2,3,4)" automaticamente.
 * Qualquer código que faça company_id = N diretamente quebrará nesse modo.
 *
 * Uso:
 *   php bin/d50_consolidado_guard.php
 *   php bin/d50_consolidado_guard.php --verbose
 *   php bin/d50_consolidado_guard.php --json
 *
 * Exit codes:
 *   0 = nenhum risco crítico detectado
 *   1 = padrões de risco encontrados
 */

$roots = [
    dirname(__DIR__) . '/api',
    dirname(__DIR__) . '/cron',
];
$verbose = in_array('--verbose', $argv ?? [], true);
$json    = in_array('--json', $argv ?? [], true);

// ── Padrões de risco (SQL com company_id fixo) ────────────────────────────────
$riskPatterns = [
    // companyId() direto em string SQL
    'direct_companyid_sql' => [
        'regex'  => '/company_id\s*=\s*["\']?\s*\.\s*TenantContext::companyId\(\)/i',
        'level'  => 'FAIL',
        'msg'    => 'company_id literal com ::companyId() — use ::sqlFilter()',
    ],
    // $cid = companyId() depois usado em SQL inline
    'cid_var_in_sql' => [
        'regex'  => '/\$cid\s*=.*?companyId\(\)/i',
        'level'  => 'WARN',
        'msg'    => '$cid = companyId() — verificar se $cid é usado em SQL (use sqlFilter())',
    ],
    // companyId() sem sqlFilter nas strings SELECT/WHERE/UPDATE
    'companyid_in_where' => [
        'regex'  => '/["\'].*company_id\s*=\s*\{?\$cid\}?.*["\']/i',
        'level'  => 'FAIL',
        'msg'    => 'company_id = $cid em SQL literal — use TenantContext::sqlFilter()',
    ],
    // PDO bind de company_id com valor fixo (pode ser legítimo em contextos não-tenant)
    'pdo_bind_company_id' => [
        'regex'  => '/execute\(\s*\[.*company_id.*companyId\(\)/is',
        'level'  => 'WARN',
        'msg'    => 'PDO bind de company_id via companyId() — OK se não em modo consolidado',
    ],
];

// ── Padrões seguros (indicam que o arquivo já usa abstrações corretas) ─────────
$safePatterns = [
    'uses_sqlfilter'         => '/TenantContext::sqlFilter\(/i',
    'uses_allowedids'        => '/TenantContext::allowedCompanyIds\(/i',
    'uses_isconsolidado'     => '/TenantContext::isConsolidado\(/i',
    'uses_in_sql'            => '/company_id\s+IN\s*\(/i',
];

// ── Varredura ─────────────────────────────────────────────────────────────────
$results  = [];
$failCnt  = 0;
$warnCnt  = 0;
$passCnt  = 0;

foreach ($roots as $root) {
    if (!is_dir($root)) {
        continue;
    }
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    /** @var SplFileInfo $file */
    foreach ($iter as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $path    = $file->getRealPath();
        $content = file_get_contents($path);
        if (!is_string($content)) {
            continue;
        }

        $relPath = str_replace(dirname(__DIR__) . DIRECTORY_SEPARATOR, '', $path);
        $relPath = str_replace('\\', '/', $relPath);

        $hasSafe  = false;
        $fileFail = [];
        $fileWarn = [];

        // Verifica padrões seguros
        foreach ($safePatterns as $key => $regex) {
            if (preg_match($regex, $content)) {
                $hasSafe = true;
                break;
            }
        }

        // Verifica padrões de risco
        foreach ($riskPatterns as $key => $def) {
            if (preg_match($def['regex'], $content)) {
                if ($def['level'] === 'FAIL') {
                    $fileFail[] = $def['msg'];
                } else {
                    $fileWarn[] = $def['msg'];
                }
            }
        }

        if ($fileFail !== [] || $fileWarn !== []) {
            $status = $fileFail !== [] ? 'FAIL' : 'WARN';
            if ($status === 'FAIL') {
                $failCnt++;
            } else {
                $warnCnt++;
            }
            $results[] = [
                'file'    => $relPath,
                'status'  => $status,
                'has_safe_pattern' => $hasSafe,
                'issues'  => array_merge($fileFail, $fileWarn),
            ];
        } else {
            $passCnt++;
        }
    }
}

// ── Relatório ─────────────────────────────────────────────────────────────────
$summary = [
    'guard'          => 'D50',
    'description'    => 'Consolidado SQL Safety',
    'total_files'    => $failCnt + $warnCnt + $passCnt,
    'fail'           => $failCnt,
    'warn'           => $warnCnt,
    'pass'           => $passCnt,
    'overall_status' => $failCnt > 0 ? 'FAIL' : ($warnCnt > 0 ? 'WARN' : 'PASS'),
    'results'        => $results,
];

if ($json) {
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($failCnt > 0 ? 1 : 0);
}

$statusIcon = ['PASS' => '✓', 'WARN' => '⚠', 'FAIL' => '✗'];

echo "═══════════════════════════════════════════════════════\n";
echo " Guard D50 — Consolidado SQL Safety\n";
echo "═══════════════════════════════════════════════════════\n";
echo sprintf(
    " Arquivos: %d | FAIL: %d | WARN: %d | PASS: %d\n",
    $summary['total_files'],
    $failCnt,
    $warnCnt,
    $passCnt
);
echo sprintf(" Status geral: %s\n", $summary['overall_status']);
echo "═══════════════════════════════════════════════════════\n\n";

foreach ($results as $r) {
    $icon = $r['status'] === 'FAIL' ? '✗' : '⚠';
    $safe = $r['has_safe_pattern'] ? ' [tem sqlFilter()]' : '';
    echo sprintf(" %s %s%s\n", $icon, $r['file'], $safe);
    if ($verbose) {
        foreach ($r['issues'] as $issue) {
            echo "     → {$issue}\n";
        }
    }
}

if ($results === []) {
    echo " Nenhum padrão de risco encontrado. APIs prontas para modo consolidado.\n";
}

echo "\n";
echo " sqlFilter() já retorna IN (...) em modo consolidado automaticamente.\n";
echo " Foco de revisão: código que usa TenantContext::companyId() em SQL.\n";

exit($failCnt > 0 ? 1 : 0);

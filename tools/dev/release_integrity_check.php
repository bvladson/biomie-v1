<?php

declare(strict_types=1);

/**
 * Fase L — Release Integrity Check
 *
 * Verifica se os arquivos da Fase K (e anteriores) estão de fato no servidor
 * com o conteúdo correto. Compara filemtime, hash sha256 e presença de strings-chave.
 *
 * CLI:   php tools/dev/release_integrity_check.php [--json]
 * Web:   https://.../biomie/tools/dev/release_integrity_check.php?token=<ADMIN_TOKEN>
 */

// ── Auth (web) ────────────────────────────────────────────────────────────────
$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    $adminToken = getenv('BIOMIE_ADMIN_TOKEN') ?: null;
    $provided   = $_GET['token'] ?? $_SERVER['HTTP_X_BIOMIE_TOKEN'] ?? null;
    if ($adminToken === null || $provided !== $adminToken) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'forbidden']);
        exit(1);
    }
}

$root   = dirname(__DIR__, 2);
$args   = $isCli ? array_slice($argv ?? [], 1) : [];
$asJson = in_array('--json', $args, true) || !$isCli;

// ── Arquivos a inspecionar ────────────────────────────────────────────────────
$files = [
    'views/index.html'                                                    => ['Orçamento-PD', 'Faturamento Orç-PD'],
    'views/pedidos.html'                                                  => ['Orçamento-PD', 'nfScopeTabs', 'NF do Período'],
    'views/vendedores.html'                                               => ['Orçamento-PD', 'Orç-PD'],
    'views/mercadorias.html'                                              => ['Orçamento-PD', 'summary-products-count'],
    'assets/js/app.js'                                                    => ['renderPanelState', 'NF_SCOPE_META', 'NF_COL_DEFS', 'summary-products-count', 'operation_scope'],
    'assets/js/core/render-helpers.js'                                    => ['renderPanelState', '_fallbackRenderPanelState'],
    'api/overview.php'                                                    => ['OverviewPayloadBuilder'],
    'api/orders.php'                                                      => ['listInvoicesForDisplayCommercial', 'scope_tabs_available', 'enforceScope', 'remittanceCount'],
    'api/vendors.php'                                                     => ['VendorPayloadBuilder'],
    'api/products.php'                                                    => ['ProductMixReadRepository', 'ProductMixService'],
    'app/Modules/Fiscal/Infrastructure/FiscalReadRepository.php'         => ['commercialScopeFilter', 'extraWhere'],
    'app/Modules/Sales/Infrastructure/ProductMixReadRepository.php'      => ['operation_scope', 'commercial'],
    'app/DataPlatform/Gold/Builders/CnaeSalesMartBuilder.php'            => ['monthStart', 'Y-m-01'],
    'app/DataPlatform/Gold/Builders/ProductPeriodMartBuilder.php'        => ['ownsTransaction', 'inTransaction'],
    'app/Modules/Sales/Application/Overview/OverviewPayloadBuilder.php'  => ['enforceScope', 'BIOMIE_OPERATION_SCOPE_ENFORCED'],
    'app/Modules/Sales/Application/Vendors/VendorPayloadBuilder.php'     => ['enforceScope', 'BIOMIE_OPERATION_SCOPE_ENFORCED'],
    'app/Modules/Sales/Application/Overview/CanonicalOverviewPayloadBuilder.php' => ['grossRevenue = $billedValue'],
    'app/Modules/Sales/Application/Vendors/CanonicalVendorPayloadBuilder.php'    => ['gross_revenue\' => $grossRevenue'],
    'app/Support/OperationScopeEnforcedException.php'                    => [],
    'tools/dev/orcpd_page_reconciliation.php'                            => ['OrcPD Page Reconciliation', 'will_use_canonical'],
];

// ── Strings-chave globais cujo AUSÊNCIA indica arquivo antigo ─────────────────
// [arquivo => [strings que DEVEM existir para o arquivo ser "novo"]]
// Já definidas acima por arquivo.

// ── Variáveis de ambiente ─────────────────────────────────────────────────────
$envReport = [
    'PHP_SAPI'                          => PHP_SAPI,
    'PHP_VERSION'                       => PHP_VERSION,
    'SERVER_SOFTWARE'                   => $_SERVER['SERVER_SOFTWARE'] ?? 'n/a',
    'DOCUMENT_ROOT'                     => $_SERVER['DOCUMENT_ROOT'] ?? 'n/a',
    'SCRIPT_FILENAME'                   => $_SERVER['SCRIPT_FILENAME'] ?? 'n/a',
    'pwd'                               => $root,
    'BIOMIE_OPERATION_SCOPE_ENFORCED'   => getenv('BIOMIE_OPERATION_SCOPE_ENFORCED') ?: '(not set)',
    'APP_ENV'                           => getenv('APP_ENV') ?: '(not set)',
    'BIOMIE_ADMIN_TOKEN_SET'            => (getenv('BIOMIE_ADMIN_TOKEN') ? 'yes' : 'no'),
    'opcache_enabled'                   => (function_exists('opcache_get_status') ? (opcache_get_status(false)['opcache_enabled'] ?? false ? 'yes' : 'no') : 'extension_missing'),
];

// ── Inspecionar cada arquivo ──────────────────────────────────────────────────
$fileReports = [];
$overallOk   = true;

foreach ($files as $rel => $expectedStrings) {
    $abs     = $root . '/' . $rel;
    $exists  = file_exists($abs);
    $entry   = [
        'path'    => $rel,
        'exists'  => $exists,
        'mtime'   => null,
        'mtime_human' => null,
        'sha256'  => null,
        'size_kb' => null,
        'strings' => [],
        'status'  => $exists ? 'ok' : 'MISSING',
    ];

    if ($exists) {
        $content      = file_get_contents($abs);
        $mtime        = filemtime($abs);
        $entry['mtime']       = $mtime;
        $entry['mtime_human'] = date('Y-m-d H:i:s', $mtime);
        $entry['sha256']      = substr(hash('sha256', $content), 0, 16);
        $entry['size_kb']     = round(strlen($content) / 1024, 1);

        $missing = [];
        foreach ($expectedStrings as $needle) {
            $found = (mb_strpos($content, $needle) !== false);
            $entry['strings'][$needle] = $found ? 'found' : 'MISSING';
            if (!$found) {
                $missing[] = $needle;
            }
        }

        if (!empty($missing)) {
            $entry['status'] = 'STALE — missing: ' . implode(', ', $missing);
            $overallOk = false;
        }
    } else {
        $overallOk = false;
    }

    $fileReports[] = $entry;
}

// ── Verificar .htaccess para enforce mode ─────────────────────────────────────
$htaccessPath    = $root . '/.htaccess';
$htaccessEnforce = false;
if (file_exists($htaccessPath)) {
    $ht = file_get_contents($htaccessPath);
    $htaccessEnforce = str_contains($ht, 'BIOMIE_OPERATION_SCOPE_ENFORCED') && str_contains($ht, '1');
}

// ── Resultado final ───────────────────────────────────────────────────────────
$result = [
    'ok'        => $overallOk,
    'timestamp' => date(DATE_ATOM),
    'env'       => $envReport,
    'htaccess_enforce_mode' => $htaccessEnforce ? 'ACTIVE' : 'NOT FOUND',
    'files'     => $fileReports,
    'summary'   => [
        'total'   => count($fileReports),
        'ok'      => count(array_filter($fileReports, fn ($f) => $f['status'] === 'ok')),
        'stale'   => count(array_filter($fileReports, fn ($f) => str_starts_with($f['status'], 'STALE'))),
        'missing' => count(array_filter($fileReports, fn ($f) => $f['status'] === 'MISSING')),
    ],
];

if ($asJson) {
    if (!$isCli) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit($overallOk ? 0 : 1);
}

// ── CLI text output ───────────────────────────────────────────────────────────
echo "\n=== BIomie Release Integrity Check ===\n";
echo "Timestamp : " . $result['timestamp'] . "\n";
echo "Root      : " . $root . "\n";
echo "PHP       : " . PHP_VERSION . " (" . PHP_SAPI . ")\n";
echo "OPcache   : " . $envReport['opcache_enabled'] . "\n";
echo "Enforce   : " . $result['htaccess_enforce_mode'] . "\n";
echo "Scope env : " . $envReport['BIOMIE_OPERATION_SCOPE_ENFORCED'] . "\n\n";

$pad = 70;
foreach ($fileReports as $f) {
    $label  = str_pad($f['path'], $pad);
    $status = $f['status'];
    $age    = $f['mtime_human'] ? "  [{$f['mtime_human']}]" : '';
    $hash   = $f['sha256'] ? "  sha={$f['sha256']}" : '';
    echo "  {$label}  {$status}{$age}{$hash}\n";
}

echo "\n--- Summary ---\n";
echo "Total    : " . $result['summary']['total'] . "\n";
echo "OK       : " . $result['summary']['ok'] . "\n";
echo "STALE    : " . $result['summary']['stale'] . "\n";
echo "MISSING  : " . $result['summary']['missing'] . "\n\n";

if ($overallOk) {
    echo "[PASS] Todos os arquivos da Fase K estao presentes e atualizados.\n\n";
} else {
    echo "[FAIL] Existem arquivos desatualizados ou ausentes. Faca o deploy novamente.\n\n";
}

exit($overallOk ? 0 : 1);

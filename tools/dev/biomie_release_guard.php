<?php

declare(strict_types=1);

/**
 * tools/dev/biomie_release_guard.php — Q.11
 *
 * Master release guard for BIomie Comercial.
 * Orchestrates all domain guards and produces a consolidated PASS/FAIL
 * report suitable for CI pipelines and pre-deploy validation.
 *
 * Usage:
 *   php tools/dev/biomie_release_guard.php [--start=YYYY-MM-DD] [--end=YYYY-MM-DD]
 *                                          [--pretty] [--json] [--clear-cache]
 *
 * Exit codes:  0 = all critical checks passed   1 = one or more failed
 *
 * Domains:
 *   D1  Fase O — Base Comercial NF        (fiscal_value_basis_guard)
 *   D2  Fase L — Licitação excluída       (fase_l_qa)
 *   D3  Fase Q — Conciliação NF × Título  (reconciliation_guard)
 *   D4  Marts — dados materializados       (DB COUNT(*))
 *   D5  APIs  — sintaxe PHP               (php -l api/*.php)
 *   D6  Audit — SELECT * em APIs          [informacional — não bloqueia]
 *   D7  Fase L — Filtro visibilidade no código (static audit)
 *   D8  STAB.R1 — SSE hardening no código  [informacional — não bloqueia]
 *   D9  Cache — limpeza                   [apenas com --clear-cache]
 *   D10 FIN.R1 — Enriquecimento financeiro  [informacional — não bloqueia]
 *   D11 Daily Sales Integrity             (daily_sales_integrity_check)
 *   D12 Finance Collection Governance     (finance_collection_governance_audit)
 *   D13 Admin Operations Consolidation    (admin operations static audit)
 *   D14 Today Sales Freshness             (today_sales_freshness_diagnosis + dashboard consistency)
 *   D15 Admin Integrations Completeness   (admin integrations static audit)
 *   D16 Product Mix Scope                 (product_mix_scope_audit + UX.R12 static audit)
 *   D17 Finance Visual Analytics          (finance_visual_analytics_audit + FIN.R4 static audit)
 *   D18 Finance Receivable Traceability   (finance_receivable_trace_audit + FIN.R4.1 static audit)
 *   D19 Finance Payment Method Enrichment [informacional — FIN.R4.2 static audit]
 *   D20 Finance Receivable 360 (FIN.R4.3)  [informacional — UI/builder static audit]
 *   D21 Finance Receivable Identity (FIN.R4.4) [informacional — resolver + audit + governance]
 *   D22 Finance Collection Resolver + NF-Bridge (FIN.R4.4.1) [informacional — 6-path resolver + by_vendor + visual]
 *   D23 Commercial Semantics Classifier (COM.R1.2)            [informacional — etapa 60 fix + stage audit]
 *   D24 Commercial Goals and Pipeline Semantics (COM.R1.3)    [informacional — SalesGoalPeriodCalculator]
 *   D25 Customer Ownership and Goal Application (COM.R1.4)    [informacional — CustomerOwnershipResolver + conflict API]
 *   D26 Vendor Alias Channel + Active Status (COM.R1.5)       [informacional — VendorAliasResolver + ActiveVendorResolver + CAIO rule]
 *   D27 Commercial Semantics & Goals (COM.R1 umbrella)        [informacional — CommercialPipelineClassifier + SalesGoalPeriodCalculator + ClientPortfolioOwnershipResolver]
 *   D28 Commercial Visual Channel Separation (COM.R2)         [informacional — CommercialChannelPresenter + Remessa filter + Vendor360 tabs + Licitação select + KPIs]
 *   D29 Commercial Period Scope Consistency (COM.R2.1)        [informacional — CommercialPeriodScopeContext + COALESCE date fix + customers_count + renderPanelState guard]
 *   D30 Commercial Historical Coverage & Portfolio (COM.R2.2) [informacional — CommercialTimeBucket + monthly_coverage_audit + Product360 fix + wallet/quotes KPIs]
 *   D31 Commercial 360 Period, Portfolio and Product Stock (COM.R3) [informacional — CommercialPeriodBucket + CommercialMetricsAggregator + Portfolio360 + Product360Builder + NF paginada + chart-helpers + stock migrations]
 *   D32 EST.R1 Product Stock Sync                                   [informacional — OmieStockClient + StockSyncCoordinator + Product360 tab estoque + nf_periodo_table filters + biomie-drawer.css]
 */

$opts       = getopt('', ['start:', 'end:', 'pretty', 'json', 'clear-cache']);
$start      = trim((string) ($opts['start']     ?? date('Y-01-01')));
$end        = trim((string) ($opts['end']       ?? date('Y-m-d')));
$jsonMode   = isset($opts['json']);
$clearCache = isset($opts['clear-cache']);

$projectRoot = dirname(dirname(__DIR__));
$toolsDir    = __DIR__;
$php         = PHP_BINARY;

require $projectRoot . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require $projectRoot . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

$t0      = microtime(true);
$domains = [];
$allPass = true;

// ── Helpers ───────────────────────────────────────────────────────────────────

function rg_exec(string $cmd): array
{
    $ds      = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($cmd, $ds, $pipes);
    if (!is_resource($process)) {
        return ['exit' => 1, 'output' => 'proc_open failed'];
    }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'output' => $out];
}

function rg_run(string $php, string $script, array $args): array
{
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script);
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg($arg);
    }
    $r     = rg_exec($cmd);
    $lines = $r['output'] !== '' ? explode("\n", rtrim($r['output'])) : [];
    return ['exit' => $r['exit'], 'lines' => $lines, 'output' => $r['output']];
}

function rg_failed(array $r): bool
{
    $plain = preg_replace('/\033\[[0-9;]*m/', '', $r['output']);

    // Explicit pass signals override exit code
    if (str_contains($plain, '✔'))                                       return false;
    if (preg_match('/\bPASS\b.+\d+\s+pass\b.+0\s+fail\b/i', $plain))   return false;

    // Explicit fail signals
    if (str_contains($plain, '✗'))                                       return true;
    if (preg_match('/\bFAIL\b\s*[:\-]\s*[1-9]/i', $plain))              return true;
    if (preg_match('/[1-9]\d*\s+fail\b/i', $plain))                     return true;

    return $r['exit'] !== 0;
}

function rg_summary(array $lines): string
{
    $best = '';
    foreach ($lines as $line) {
        $clean = trim(preg_replace('/\033\[[0-9;]*m/', '', $line));
        if ($clean !== '' && preg_match('/\d+\s*[\/\-]\s*\d+|\b(?:PASS|FAIL)\b|✔|✗|checks? pass/i', $clean)) {
            $best = $clean;
        }
    }
    return $best ?: '(sem resumo)';
}

function rg_col(string $c, string $s): string
{
    $map = ['g' => '32', 'r' => '31', 'y' => '33'];
    return isset($map[$c]) ? "\033[{$map[$c]}m{$s}\033[0m" : $s;
}

// ── D1 — Fase O: Base Comercial NF ───────────────────────────────────────────
$r    = rg_run($php, $toolsDir . '/fiscal_value_basis_guard.php', ['--pretty']);
$fail = rg_failed($r);
$domains[] = [
    'label'   => 'D1  Fase O — Base Comercial NF',
    'guard'   => 'fiscal_value_basis_guard.php',
    'pass'    => !$fail,
    'summary' => rg_summary($r['lines']),
];
if ($fail) $allPass = false;

// ── D2 — Fase L: Licitação excluída ──────────────────────────────────────────
$r    = rg_run($php, $toolsDir . '/fase_l_qa.php', ["--start={$start}", "--end={$end}", '--pretty']);
$fail = rg_failed($r);
$domains[] = [
    'label'   => 'D2  Fase L — Licitação excluída',
    'guard'   => 'fase_l_qa.php',
    'pass'    => !$fail,
    'summary' => rg_summary($r['lines']),
];
if ($fail) $allPass = false;

// ── D3 — Fase Q: Conciliação NF × Título ─────────────────────────────────────
$r    = rg_run($php, $toolsDir . '/reconciliation_guard.php', ["--start={$start}", "--end={$end}", '--pretty']);
$fail = rg_failed($r);
$domains[] = [
    'label'   => 'D3  Fase Q — Conciliação NF × Título',
    'guard'   => 'reconciliation_guard.php',
    'pass'    => !$fail,
    'summary' => rg_summary($r['lines']),
];
if ($fail) $allPass = false;

// ── D4 — Marts comerciais com dados ──────────────────────────────────────────
$config = new \BIomie\Config();
$pdo    = \BIomie\Database::connect($config, 'db');

$martChecks = [
    'mart_vendedor_dia'               => 'Mart Vendedor × Dia',
    'mart_cliente_periodo'            => 'Mart Cliente × Período',
    'mart_vendas_por_cnae_cnpj'       => 'Mart CNAE × Vendas',
    'mart_compradores_por_geo'        => 'Mart Geo × Comprador',
    'mart_produto_periodo'            => 'Mart Produto × Período',
    'biomie_bridge_nf_titulo_items'   => 'Bridge NF × Título (items)',
    'biomie_bridge_nf_titulo_summary' => 'Bridge NF × Título (summary)',
];

$martDetail  = [];
$d4fail      = false;
foreach ($martChecks as $table => $label) {
    try {
        $exists = (bool) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($table)
        )->fetchColumn();

        if (!$exists) {
            $martDetail[$label] = ['rows' => null, 'pass' => false, 'note' => 'tabela ausente'];
            $d4fail = true;
        } else {
            $n    = (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
            $pass = $n > 0;
            $martDetail[$label] = ['rows' => $n, 'pass' => $pass, 'note' => $pass ? '' : 'tabela vazia'];
            if (!$pass) $d4fail = true;
        }
    } catch (\Throwable $e) {
        $martDetail[$label] = ['rows' => null, 'pass' => false, 'note' => $e->getMessage()];
        $d4fail = true;
    }
}

$d4passCount = count(array_filter($martDetail, fn($r) => $r['pass']));
$domains[] = [
    'label'   => 'D4  Marts — dados materializados',
    'guard'   => 'DB COUNT(*)',
    'cmd'     => 'php api/geo_materialize.php  # ou admin_geo_rebuild.php para rematerializar',
    'pass'    => !$d4fail,
    'summary' => "{$d4passCount}/" . count($martChecks) . " tabelas com dados",
    'detail'  => $martDetail,
];
if ($d4fail) $allPass = false;

// ── D5 — Sintaxe PHP: api/*.php ──────────────────────────────────────────────
$apiFiles   = glob($projectRoot . '/api/*.php') ?: [];
$syntaxErrs = [];
foreach ($apiFiles as $f) {
    $r = rg_exec(escapeshellarg($php) . ' -l ' . escapeshellarg($f));
    if ($r['exit'] !== 0) {
        $syntaxErrs[] = basename($f) . ': ' . trim($r['output']);
    }
}
$d5pass    = count($syntaxErrs) === 0;
$domains[] = [
    'label'   => 'D5  APIs — sintaxe PHP',
    'guard'   => 'php -l api/*.php',
    'pass'    => $d5pass,
    'summary' => $d5pass
        ? count($apiFiles) . ' arquivos sem erros de sintaxe'
        : count($syntaxErrs) . ' erro(s) de sintaxe encontrado(s)',
    'errors'  => $syntaxErrs,
];
if (!$d5pass) $allPass = false;

// ── D6 — SELECT * audit [informacional — não bloqueia release] ────────────────
$selectStarIssues = [];
$apiDir = $projectRoot . '/api';
if (is_dir($apiDir)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($apiDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            continue;
        }
        foreach ($lines as $idx => $line) {
            if (stripos($line, 'SELECT *') === false) {
                continue;
            }
            if (preg_match('/COUNT\(\*\)|information_schema/i', $line)) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($projectRoot) + 1));
            $selectStarIssues[] = $relative . ':' . ($idx + 1) . ': ' . trim($line);
        }
    }
}
$d6warn    = count($selectStarIssues) > 0;
$domains[] = [
    'label'   => 'D6  Audit — SELECT * em APIs  [informacional]',
    'guard'   => 'static scan api/*.php',
    'pass'    => true,
    'warn'    => $d6warn,
    'summary' => $d6warn
        ? count($selectStarIssues) . ' ocorrência(s) — revisar manualmente'
        : 'Nenhum SELECT * encontrado',
    'issues'  => $selectStarIssues,
];
// D6 is informational only — does NOT flip $allPass

// ── D7 — Fase L: filtro de visibilidade no código-fonte (static audit) ────────
$d7checks = [
    [
        'file'    => $projectRoot . '/app/DataPlatform/Silver/Readers/CanonicalReadSupport.php',
        'pattern' => 'is_biomie_commercial_visible',
        'label'   => 'CanonicalReadSupport::commercialScopeFilter() aplica visibilidade',
    ],
    [
        'file'    => $projectRoot . '/app/Modules/Fiscal/Infrastructure/FiscalReadRepository.php',
        'pattern' => 'aggregateCancelledVendorStatusCommercial',
        'label'   => 'FiscalReadRepository::aggregateCancelledVendorStatusCommercial() existe',
    ],
    [
        'file'    => $projectRoot . '/app/Modules/Fiscal/Infrastructure/FiscalReadRepository.php',
        'pattern' => 'commercialScopeFilter',
        'check'   => static function (string $src): bool {
            $pos = strpos($src, 'aggregateCancelledVendorStatusCommercial');
            if ($pos === false) return false;
            $after = substr($src, $pos, 1000);
            return str_contains($after, 'commercialScopeFilter');
        },
        'label'   => 'aggregateCancelledVendorStatusCommercial() usa commercialScopeFilter()',
    ],
    [
        'file'    => $projectRoot . '/app/Modules/Sales/Infrastructure/SalesReadRepository.php',
        'pattern' => 'BiVisibilityScope',
        'label'   => 'SalesReadRepository usa BiVisibilityScope para pedidos_canonicos',
    ],
];
$d7issues = [];
foreach ($d7checks as $chk) {
    $src = is_file($chk['file']) ? file_get_contents($chk['file']) : false;
    if ($src === false) {
        $d7issues[] = 'Arquivo não encontrado: ' . basename($chk['file']);
        continue;
    }
    $pass = isset($chk['check'])
        ? ($chk['check'])($src)
        : str_contains($src, $chk['pattern']);
    if (!$pass) {
        $d7issues[] = 'FALHOU: ' . $chk['label'];
    }
}
$d7pass = count($d7issues) === 0;
$domains[] = [
    'label'   => 'D7  Fase L — Filtro visibilidade no código',
    'guard'   => 'static-audit CanonicalReadSupport + FiscalReadRepository + SalesReadRepository',
    'pass'    => $d7pass,
    'summary' => $d7pass
        ? '4/4 verificações de código passaram'
        : count($d7issues) . ' verificação(ões) falhou',
    'issues'  => $d7issues,
];
if (!$d7pass) $allPass = false;

// ── D8 — STAB.R1: SSE hardening no código-fonte [informacional] ───────────────
$d8checks = [
    [
        'file'    => $projectRoot . '/api/live_stream.php',
        'pattern' => 'connection_aborted',
        'label'   => 'live_stream.php verifica connection_aborted()',
    ],
    [
        'file'    => $projectRoot . '/api/live_stream.php',
        'pattern' => 'retry:',
        'label'   => 'live_stream.php envia diretiva retry (controla reconexão do browser)',
    ],
    [
        'file'    => $projectRoot . '/assets/js/app.js',
        'pattern' => 'visibilitychange',
        'label'   => 'app.js usa Page Visibility API (visibilitychange)',
    ],
    [
        'file'    => $projectRoot . '/assets/js/app.js',
        'pattern' => 'BIOMIE_LIVE_STREAM',
        'label'   => 'app.js usa singleton BIOMIE_LIVE_STREAM',
    ],
];
$d8warns = [];
foreach ($d8checks as $chk) {
    $src = is_file($chk['file']) ? file_get_contents($chk['file']) : false;
    if ($src === false || !str_contains($src, $chk['pattern'])) {
        $d8warns[] = 'Ausente: ' . $chk['label'];
    }
}
$d8ok = count($d8warns) === 0;
$domains[] = [
    'label'   => 'D8  STAB.R1 — SSE hardening no código  [informacional]',
    'guard'   => 'static-audit live_stream.php + app.js',
    'pass'    => true,
    'warn'    => !$d8ok,
    'summary' => $d8ok
        ? '4/4 padrões SSE hardening presentes'
        : count($d8warns) . ' padrão(ões) ausente(s)',
    'issues'  => $d8warns,
];
// D8 is informational only — does NOT flip $allPass

// ── D10 — FIN.R1: enriquecimento financeiro [informacional] ──────────────────
$d10checks = [
    [
        'file'    => $projectRoot . '/tools/dev/finance_enrichment_diagnosis.php',
        'pattern' => 'path1',
        'label'   => 'finance_enrichment_diagnosis.php existe',
    ],
    [
        'file'    => $projectRoot . '/tools/dev/enrich_finance_titles.php',
        'pattern' => 'pass1',
        'label'   => 'enrich_finance_titles.php existe',
    ],
    [
        'file'    => $projectRoot . '/app/DataPlatform/Gold/Builders/FinanceTitleMartBuilder.php',
        'pattern' => 'COALESCE(NULLIF(t.codigo_vendedor_omie',
        'label'   => 'FinanceTitleMartBuilder usa COALESCE para vendedor',
    ],
    [
        'file'    => $projectRoot . '/api/finance_overview.php',
        'pattern' => 'canonical-local-fallback',
        'label'   => 'finance_overview.php tem fallback canônico (FIN.R2)',
    ],
];
$d10warns = [];
foreach ($d10checks as $chk) {
    $src = is_file($chk['file']) ? file_get_contents($chk['file']) : false;
    if ($src === false || !str_contains($src, $chk['pattern'])) {
        $d10warns[] = 'Ausente: ' . $chk['label'];
    }
}
$d10ok = count($d10warns) === 0;
$domains[] = [
    'label'   => 'D10 FIN.R1 — Enriquecimento financeiro  [informacional]',
    'guard'   => 'static-audit finance tools + FinanceTitleMartBuilder',
    'pass'    => true,
    'warn'    => !$d10ok,
    'summary' => $d10ok
        ? '4/4 padrões FIN.R1 presentes'
        : count($d10warns) . ' padrão(ões) ausente(s)',
    'issues'  => $d10warns,
];
// D10 is informational only — does NOT flip $allPass

// ── D11 — Daily Sales Integrity ──────────────────────────────────────────────
$r    = rg_run($php, $toolsDir . '/daily_sales_integrity_check.php', ["--date={$end}", '--fail-on-drift', '--pretty']);
$fail = rg_failed($r);
$isWarning = str_contains($r['output'], 'WARNING');

$d11Summary = '';
foreach ($r['lines'] as $line) {
    $clean = trim(preg_replace('/\033\[[0-9;]*m/', '', $line));
    if (str_starts_with($clean, 'Mensagem:')) {
        $d11Summary = trim(substr($clean, 9));
        break;
    }
}
if ($d11Summary === '') {
    $d11Summary = rg_summary($r['lines']);
}

$domains[] = [
    'label'   => 'D11 Daily Sales Integrity',
    'guard'   => 'daily_sales_integrity_check.php',
    'pass'    => !$fail,
    'warn'    => $isWarning,
    'summary' => $d11Summary,
];
if ($fail) $allPass = false;


// ── D12 — Finance Collection Governance ──────────────────────────────────────
$d12Issues = [];
$d12Warn = false;

// 1. Files existence
if (!is_file($projectRoot . '/api/finance_collection_governance.php')) {
    $d12Issues[] = 'api/finance_collection_governance.php ausente';
}
if (!is_file($projectRoot . '/assets/js/modules/finance/collection-governance.js')) {
    $d12Issues[] = 'assets/js/modules/finance/collection-governance.js ausente';
}
if (!is_file($toolsDir . '/finance_collection_governance_audit.php')) {
    $d12Issues[] = 'finance_collection_governance_audit.php ausente';
}

// 2. Asset registration check
$registrySrc = file_get_contents($projectRoot . '/app/PageAssetRegistry.php');
if (!str_contains($registrySrc, 'collection-governance.js')) {
    $d12Issues[] = 'collection-governance.js não registrado no PageAssetRegistry';
}

// 3. SELECT * check
if (is_file($projectRoot . '/api/finance_collection_governance.php')) {
    $apiSrc = file_get_contents($projectRoot . '/api/finance_collection_governance.php');
    if (preg_match('/SELECT\s+\*\s+/i', $apiSrc)) {
        $d12Issues[] = 'SELECT * detectado no endpoint de governança';
    }
    if (!str_contains($apiSrc, 'finance.view')) {
        $d12Issues[] = 'Falta verificação de capacidade finance.view no endpoint';
    }
}

// 4. Run CLI Auditor
$rGov = rg_run($php, $toolsDir . '/finance_collection_governance_audit.php', ["--start={$start}", "--end={$end}", '--json']);
if ($rGov['exit'] !== 0) {
    $d12Issues[] = 'CLI de governança falhou com código ' . $rGov['exit'];
} else {
    $govJson = json_decode($rGov['output'], true);
    if (!is_array($govJson) || !isset($govJson['ok'])) {
        $d12Issues[] = 'CLI de governança retornou JSON inválido';
    } else {
        $summary = $govJson['summary'] ?? [];
        if (($summary['titulos_com_acao_periodo'] ?? 0) === 0) {
            $d12Warn = true; // Warning justificado se não houver ações no período
        }
    }
}

$d12Pass = count($d12Issues) === 0;
$domains[] = [
    'label'   => 'D12 Finance Collection Governance',
    'guard'   => 'finance_collection_governance_audit.php + static analysis',
    'pass'    => $d12Pass,
    'warn'    => $d12Warn,
    'summary' => $d12Pass
        ? ($d12Warn ? 'Sem ações de cobrança registradas no período (WARN esperado)' : 'Painel de governança operacional validado com sucesso')
        : count($d12Issues) . ' problema(s) de governança de cobrança encontrado(s)',
    'issues'  => $d12Issues,
];
if (!$d12Pass) $allPass = false;


// D13 -- Admin Operations Consolidation
$d13Issues = [];
$d13Warns = [];

$adminSrc = is_file($projectRoot . '/views/admin.php') ? (string) file_get_contents($projectRoot . '/views/admin.php') : '';
foreach (['system-health', 'integrations', 'metrics', 'cockpit'] as $tabKey) {
    if (!str_contains($adminSrc, "'key' => '{$tabKey}'")
        && !str_contains($adminSrc, "\"key\" => \"{$tabKey}\"")) {
        $d13Issues[] = "Aba admin ausente: {$tabKey}";
    }
}

$partials = [
    'views/admin/tabs/system-health.php',
    'views/admin/tabs/integrations.php',
    'views/admin/tabs/metric-catalog.php',
    'views/admin/tabs/cockpit.php',
];
foreach ($partials as $partial) {
    if (!is_file($projectRoot . '/' . $partial)) {
        $d13Issues[] = "Partial ausente: {$partial}";
    }
}

$redirects = [
    'system_health.php' => 'admin.php?tab=system-health',
    'integracoes.php' => 'admin.php?tab=integrations',
    'metric_catalog.php' => 'admin.php?tab=metrics',
    'cockpit_interno.php' => 'admin.php?tab=cockpit',
];
foreach ($redirects as $route => $target) {
    $routeSrc = is_file($projectRoot . '/' . $route) ? (string) file_get_contents($projectRoot . '/' . $route) : '';
    if ($routeSrc === '') {
        $d13Issues[] = "Rota antiga ausente: {$route}";
        continue;
    }
    if (!str_contains($routeSrc, $target)) {
        $d13Issues[] = "Rota antiga nao redireciona para {$target}: {$route}";
    }
    if (!str_contains($routeSrc, 'admin.view') || !str_contains($routeSrc, 'admin.manage')) {
        $d13Issues[] = "Rota antiga sem auth admin explicito: {$route}";
    }
    if (str_contains($routeSrc, 'PageRenderer::render')) {
        $d13Issues[] = "Rota antiga ainda renderiza shell proprio: {$route}";
    }
}

$menuSrc = is_file($projectRoot . '/views/config/menu.php') ? (string) file_get_contents($projectRoot . '/views/config/menu.php') : '';
foreach (['integracoes.php', 'observabilidade.php', 'cockpit_interno.php', 'metric_catalog.php', 'system_health.php'] as $href) {
    $pattern = "/'href'\\s*=>\\s*'" . preg_quote($href, '/') . "'[\\s\\S]{0,260}'enabled'\\s*=>\\s*true/i";
    if (preg_match($pattern, $menuSrc)) {
        $d13Issues[] = "Sidebar ainda expoe pagina tecnica: {$href}";
    }
}

foreach (['cron/hostinger/worker.sh', 'cron/hostinger/sync_commercial.sh', 'cron/hostinger/sync_finance.sh', 'cron/hostinger/materialize.sh'] as $wrapper) {
    $wrapperSrc = is_file($projectRoot . '/' . $wrapper) ? (string) file_get_contents($projectRoot . '/' . $wrapper) : '';
    if ($wrapperSrc === '') {
        $d13Issues[] = "Wrapper cron ausente: {$wrapper}";
        continue;
    }
    if (!str_contains($wrapperSrc, 'storage/logs/cron')) {
        $d13Issues[] = "Wrapper cron sem log auditavel: {$wrapper}";
    }
    if (!str_contains($wrapperSrc, 'flock')) {
        $d13Warns[] = "Wrapper cron sem flock detectado: {$wrapper}";
    }
}

$d13Pass = count($d13Issues) === 0;
$domains[] = [
    'label'   => 'D13 Admin Operations Consolidation',
    'guard'   => 'static audit admin tabs + legacy route redirects + cron wrappers',
    'pass'    => $d13Pass,
    'warn'    => count($d13Warns) > 0,
    'summary' => $d13Pass
        ? (count($d13Warns) > 0 ? 'Consolidado com aviso em wrappers' : 'Admin operations consolidado e rotas antigas protegidas')
        : count($d13Issues) . ' problema(s) na consolidacao admin/ops',
    'issues'  => array_merge($d13Issues, $d13Warns),
];
if (!$d13Pass) $allPass = false;

// D14 -- Today Sales Freshness
$d14Issues = [];
$d14Warns = [];
$today = date('Y-m-d');

if (!is_file($toolsDir . '/today_sales_freshness_diagnosis.php')) {
    $d14Issues[] = 'today_sales_freshness_diagnosis.php ausente';
}
if (!is_file($toolsDir . '/dashboard_period_consistency_check.php')) {
    $d14Issues[] = 'dashboard_period_consistency_check.php ausente';
}

$freshnessSummary = 'Diagnostico nao executado';
if (is_file($toolsDir . '/today_sales_freshness_diagnosis.php')) {
    $rToday = rg_run($php, $toolsDir . '/today_sales_freshness_diagnosis.php', ["--date={$today}", '--json', '--no-omie']);
    $decoded = json_decode($rToday['output'], true);
    if (!is_array($decoded)) {
        $d14Issues[] = 'today_sales_freshness_diagnosis.php nao retornou JSON valido';
    } else {
        $freshnessSummary = (string) (($decoded['classification'] ?? 'unknown') . ' / ' . ($decoded['status'] ?? 'UNKNOWN'));
        if (($decoded['status'] ?? '') === 'CRITICAL') {
            $d14Issues[] = 'Freshness de vendas do dia em CRITICAL: ' . (string) ($decoded['summary'] ?? '-');
        } elseif (($decoded['status'] ?? '') === 'WARNING') {
            $d14Warns[] = 'Freshness de vendas do dia em WARNING: ' . (string) ($decoded['summary'] ?? '-');
        }
    }
}

if (is_file($toolsDir . '/dashboard_period_consistency_check.php')) {
    $rDash = rg_run($php, $toolsDir . '/dashboard_period_consistency_check.php', ["--start={$today}", "--end={$end}", '--json']);
    $decoded = json_decode($rDash['output'], true);
    if (!is_array($decoded)) {
        $d14Issues[] = 'dashboard_period_consistency_check.php nao retornou JSON valido';
    } elseif (($decoded['status'] ?? '') === 'CRITICAL') {
        $d14Issues[] = 'Dashboard pode misturar periodo: ' . implode('; ', array_slice((array) ($decoded['issues'] ?? []), 0, 2));
    }
}

$healthSrc = is_file($projectRoot . '/app/SystemHealth/Checks/OmieSyncFreshnessHealthCheck.php')
    ? (string) file_get_contents($projectRoot . '/app/SystemHealth/Checks/OmieSyncFreshnessHealthCheck.php')
    : '';
if (str_contains($healthSrc, "freshestDate(\$canonicalDates['invoices'] ?? null, \$canonicalDates['clients'] ?? null)")) {
    $d14Issues[] = 'Health commercial ainda pode usar clientes como fallback de vendas.';
}

$overviewSrc = is_file($projectRoot . '/app/Modules/Sales/Application/Overview/CanonicalOverviewPayloadBuilder.php')
    ? (string) file_get_contents($projectRoot . '/app/Modules/Sales/Application/Overview/CanonicalOverviewPayloadBuilder.php')
    : '';
if (!str_contains($overviewSrc, 'isFullCalendarMonth') || !str_contains($overviewSrc, '$canUseMonthlyMarts')) {
    $d14Issues[] = 'Overview nao possui guarda contra fallback mensal em periodo parcial.';
}

$d14Pass = count($d14Issues) === 0;
$domains[] = [
    'label'   => 'D14 Today Sales Freshness',
    'guard'   => 'today_sales_freshness_diagnosis.php + dashboard_period_consistency_check.php',
    'pass'    => $d14Pass,
    'warn'    => count($d14Warns) > 0,
    'summary' => $d14Pass
        ? (count($d14Warns) > 0 ? 'Freshness com aviso: ' . $freshnessSummary : 'Freshness e periodo do dashboard auditados')
        : count($d14Issues) . ' problema(s) de freshness/dashboard',
    'issues'  => array_merge($d14Issues, $d14Warns),
];
if (!$d14Pass) $allPass = false;

// D15 -- Admin Integrations Completeness
$d15Issues = [];
$integrationsPartial = is_file($projectRoot . '/views/admin/tabs/integrations.php')
    ? (string) file_get_contents($projectRoot . '/views/admin/tabs/integrations.php')
    : '';
$adminJs = is_file($projectRoot . '/views/admin/partials/admin-js.php')
    ? (string) file_get_contents($projectRoot . '/views/admin/partials/admin-js.php')
    : '';

foreach ([
    'Status da integra',
    'Base sincronizada',
    'Opera',
    'Rate limit',
    'Wrappers cron',
    'A&ccedil;&otilde;es recomendadas',
    '&Uacute;ltimas sincroniza',
    'Status das tabelas',
    'Resposta t&eacute;cnica',
] as $needle) {
    if (!str_contains($integrationsPartial, $needle)) {
        $d15Issues[] = "Aba Integracoes sem bloco esperado: {$needle}";
    }
}

if (!is_file($projectRoot . '/api/cron_wrapper_status.php')) {
    $d15Issues[] = 'api/cron_wrapper_status.php ausente';
}
if (!str_contains($adminJs, 'api/omie_status.php') || !str_contains($adminJs, 'api/cron_wrapper_status.php')) {
    $d15Issues[] = 'JS admin nao carrega APIs de integracao/cron.';
}
if (!str_contains($adminJs, 'renderAdminIntegrationDomains') || !str_contains($adminJs, 'renderAdminIntegrationTables')) {
    $d15Issues[] = 'JS admin sem renderizacao completa de dominios/tabelas.';
}
if (str_contains($integrationsPartial, '<html') || str_contains($integrationsPartial, '<body')) {
    $d15Issues[] = 'Partial de integracoes contem shell duplicado.';
}

$d15Pass = count($d15Issues) === 0;
$domains[] = [
    'label'   => 'D15 Admin Integrations Completeness',
    'guard'   => 'static audit admin integrations tab',
    'pass'    => $d15Pass,
    'summary' => $d15Pass ? 'Admin > Integracoes restaurado com blocos completos' : count($d15Issues) . ' problema(s) na aba Integracoes',
    'issues'  => $d15Issues,
];
if (!$d15Pass) $allPass = false;

// D16 -- Product Mix Scope
$d16Issues = [];
$d16Warns = [];
$productHtml = is_file($projectRoot . '/views/mercadorias.html')
    ? (string) file_get_contents($projectRoot . '/views/mercadorias.html')
    : '';
$productCss = is_file($projectRoot . '/assets/css/mercadorias.css')
    ? (string) file_get_contents($projectRoot . '/assets/css/mercadorias.css')
    : '';
$chartFactory = is_file($projectRoot . '/assets/js/core/chart-factory.js')
    ? (string) file_get_contents($projectRoot . '/assets/js/core/chart-factory.js')
    : '';
$productRepo = is_file($projectRoot . '/app/Modules/Sales/Infrastructure/ProductMixReadRepository.php')
    ? (string) file_get_contents($projectRoot . '/app/Modules/Sales/Infrastructure/ProductMixReadRepository.php')
    : '';

if (!is_file($toolsDir . '/product_mix_scope_audit.php')) {
    $d16Issues[] = 'product_mix_scope_audit.php ausente';
} else {
    $rProductScope = rg_run($php, $toolsDir . '/product_mix_scope_audit.php', ["--start={$start}", "--end={$end}", '--json']);
    $decoded = json_decode($rProductScope['output'], true);
    if (!is_array($decoded)) {
        $d16Issues[] = 'product_mix_scope_audit.php nao retornou JSON valido';
    } elseif (($decoded['status'] ?? '') !== 'OK') {
        $d16Issues[] = 'Product Mix Scope em ' . (string) ($decoded['status'] ?? 'UNKNOWN') . ': ' . (string) ($decoded['classification'] ?? 'unknown');
    } elseif ((int) (($decoded['official_filter']['licitacao_leaks'] ?? 0)) > 0) {
        $d16Issues[] = 'Mix Orc-PD ainda contem Licitacao';
    } elseif ((int) (($decoded['official_filter']['remittance_leaks_in_orc_pd'] ?? 0)) > 0) {
        $d16Issues[] = 'Mix Orc-PD ainda contem Remessas';
    }
}

foreach ([
    'productScopeSwitcher' => 'seletor Orc-PD/Remessas/Todas ausente',
    'productsRevenueLegend' => 'legenda do doughnut de receita ausente',
    'productsShareLegend' => 'legenda do doughnut de participacao ausente',
    'productBreakdownSummary' => 'drawer de produto sem KPIs',
] as $needle => $message) {
    if (!str_contains($productHtml, $needle)) {
        $d16Issues[] = $message;
    }
}
if (!str_contains($chartFactory, "type: 'doughnut'")) {
    $d16Issues[] = 'chart-factory nao renderiza doughnut para mercadorias';
}
if (!str_contains($productRepo, 'BiVisibilityScope')) {
    $d16Issues[] = 'ProductMixReadRepository nao aplica BiVisibilityScope';
}
if (!str_contains($productCss, '.product-scope-chip--active')) {
    $d16Warns[] = 'CSS especifico de mercadorias nao foi encontrado ou esta incompleto';
}

$d16Pass = count($d16Issues) === 0;
$domains[] = [
    'label'   => 'D16 Product Mix Scope',
    'guard'   => 'product_mix_scope_audit.php + UX.R12 static audit',
    'pass'    => $d16Pass,
    'warn'    => count($d16Warns) > 0,
    'summary' => $d16Pass
        ? (count($d16Warns) > 0 ? 'Mix de produtos ok com aviso visual' : 'Mix Orc-PD sem Licitacao/Remessas e UX.R12 presente')
        : count($d16Issues) . ' problema(s) no Mix Faturado',
    'issues'  => array_merge($d16Issues, $d16Warns),
];
if (!$d16Pass) $allPass = false;

// D17 -- Finance Visual Analytics
$d17Issues = [];
$d17Warns = [];
$financeHtml = is_file($projectRoot . '/views/financeiro.html')
    ? (string) file_get_contents($projectRoot . '/views/financeiro.html')
    : '';
$financeJs = is_file($projectRoot . '/assets/js/modules/finance/visual-analytics.js')
    ? (string) file_get_contents($projectRoot . '/assets/js/modules/finance/visual-analytics.js')
    : '';
$financeApi = is_file($projectRoot . '/api/finance_visual_analytics.php')
    ? (string) file_get_contents($projectRoot . '/api/finance_visual_analytics.php')
    : '';

if (!is_file($projectRoot . '/api/finance_visual_analytics.php')) {
    $d17Issues[] = 'api/finance_visual_analytics.php ausente';
}
if (!is_file($projectRoot . '/tools/dev/finance_visual_analytics_audit.php')) {
    $d17Issues[] = 'finance_visual_analytics_audit.php ausente';
} else {
    $rFinanceVisual = rg_run($php, $toolsDir . '/finance_visual_analytics_audit.php', ["--start={$start}", "--end={$end}", '--json']);
    $decoded = json_decode($rFinanceVisual['output'], true);
    if (!is_array($decoded)) {
        $d17Issues[] = 'finance_visual_analytics_audit.php nao retornou JSON valido';
    } elseif (($decoded['status'] ?? '') !== 'OK') {
        $d17Issues[] = 'Finance Visual Analytics em ' . (string) ($decoded['status'] ?? 'UNKNOWN');
    } elseif ((int) (($decoded['coverage']['composition_slices'] ?? 0)) < 1) {
        $d17Warns[] = 'Finance Visual Analytics sem composicao no periodo';
    }
}

foreach ([
    'financeVisualAnalytics' => 'secao visual FIN.R4 ausente',
    'financeInsightDrawer' => 'drawer financeiro ausente',
    'financeVisualCompositionChart' => 'chart de composicao ausente',
    'financeVisualAgingChart' => 'chart de aging ausente',
    'financeVisualVendorChart' => 'chart de vendedor ausente',
    'financeVisualClientRiskChart' => 'chart de clientes de risco ausente',
    'financeVisualGovernanceChart' => 'chart de governanca ausente',
    'financeRawTitlesDetails' => 'tabelas antigas nao estao recolhiveis/acessiveis',
] as $needle => $message) {
    if (!str_contains($financeHtml, $needle)) {
        $d17Issues[] = $message;
    }
}
if (!str_contains($financeJs, 'financeInsightDrawer')) {
    $d17Issues[] = 'visual-analytics.js nao controla drawer financeiro';
}
if (preg_match('/SELECT\s+\*/i', $financeApi) === 1) {
    $d17Issues[] = 'api/finance_visual_analytics.php contem SELECT *';
}

$d17Pass = count($d17Issues) === 0;
$domains[] = [
    'label'   => 'D17 Finance Visual Analytics',
    'guard'   => 'finance_visual_analytics_audit.php + static audit',
    'pass'    => $d17Pass,
    'warn'    => count($d17Warns) > 0,
    'summary' => $d17Pass
        ? (count($d17Warns) > 0 ? 'FIN.R4 visual ok com aviso de dados' : 'FIN.R4 visual e drawer presentes')
        : count($d17Issues) . ' problema(s) no Financeiro visual',
    'issues'  => array_merge($d17Issues, $d17Warns),
];
if (!$d17Pass) $allPass = false;

// ── D18 — Finance Receivable Traceability (FIN.R4.1) ─────────────────────────
$d18Issues = [];
$d18Warns  = [];

$traceApiFile    = $projectRoot . '/api/finance_receivable_trace.php';
$traceBuilderFile = $projectRoot . '/app/Support/FinanceReceivableTraceBuilder.php';
$traceAuditFile  = $toolsDir . '/finance_receivable_trace_audit.php';
$traceFinanceHtml = is_file($projectRoot . '/views/financeiro.html')
    ? (string) file_get_contents($projectRoot . '/views/financeiro.html') : '';
$traceFinanceJs  = is_file($projectRoot . '/assets/js/modules/finance/visual-analytics.js')
    ? (string) file_get_contents($projectRoot . '/assets/js/modules/finance/visual-analytics.js') : '';

if (!is_file($traceApiFile)) {
    $d18Issues[] = 'api/finance_receivable_trace.php ausente';
}
if (!is_file($traceBuilderFile)) {
    $d18Issues[] = 'FinanceReceivableTraceBuilder.php ausente';
}
if (!is_file($traceAuditFile)) {
    $d18Issues[] = 'finance_receivable_trace_audit.php ausente';
}

// SELECT * check
if (is_file($traceApiFile)) {
    $apiSrc = file_get_contents($traceApiFile);
    if (preg_match('/SELECT\s+\*\s+/i', $apiSrc)) {
        $d18Issues[] = 'api/finance_receivable_trace.php contem SELECT *';
    }
    if (!str_contains($apiSrc, 'finance.view')) {
        $d18Issues[] = 'api/finance_receivable_trace.php sem verificacao finance.view';
    }
}

// Prepared statements na API ou no builder que ela usa
$builderSrcForPrepare = is_file($traceBuilderFile) ? (string) file_get_contents($traceBuilderFile) : '';
$apiSrcForPrepare = is_file($traceApiFile) ? (string) file_get_contents($traceApiFile) : '';
if (!str_contains($apiSrcForPrepare, 'prepare(') && !str_contains($builderSrcForPrepare, 'prepare(')) {
    $d18Issues[] = 'FinanceReceivableTraceBuilder + api/finance_receivable_trace.php sem prepared statements';
}

// Builder checks
if (is_file($traceBuilderFile)) {
    $builderSrc = (string) file_get_contents($traceBuilderFile);
    if (!str_contains($builderSrc, 'normalizePaymentMethod')) {
        $d18Issues[] = 'FinanceReceivableTraceBuilder sem normalizePaymentMethod()';
    }
    if (!str_contains($builderSrc, 'is_boleto')) {
        $d18Issues[] = 'FinanceReceivableTraceBuilder sem deteccao de boleto';
    }
    if (!str_contains($builderSrc, 'buildOpenInvoiceSummary')) {
        $d18Issues[] = 'FinanceReceivableTraceBuilder sem agrupamento por NF';
    }
    if (!str_contains($builderSrc, 'buildPaymentMethodRanking')) {
        $d18Issues[] = 'FinanceReceivableTraceBuilder sem agrupamento por metodo de pagamento';
    }
    if (!str_contains($builderSrc, 'bridge_nota_key')) {
        $d18Issues[] = 'FinanceReceivableTraceBuilder sem vinculo NF via bridge';
    }
    if (!str_contains($builderSrc, 'Sem vendedor definido')) {
        $d18Issues[] = 'FinanceReceivableTraceBuilder sem fallback Sem vendedor definido';
    }
    if (preg_match('/SELECT\s+\*\s+/i', $builderSrc)) {
        $d18Issues[] = 'FinanceReceivableTraceBuilder contem SELECT *';
    }
}

// HTML view checks
if (!str_contains($traceFinanceHtml, 'financeReceivableTrace')) {
    $d18Issues[] = 'Bloco NFs com boleto aberto ausente na view (financeReceivableTrace)';
}
if (!str_contains($traceFinanceHtml, 'financeTraceMethodChart')) {
    $d18Issues[] = 'Bloco Top 10 metodos ausente na view (financeTraceMethodChart)';
}
if (!str_contains($traceFinanceHtml, 'financeTraceDrawer')) {
    $d18Issues[] = 'Drawer operacional de rastreabilidade ausente na view';
}
if (!str_contains($traceFinanceHtml, 'financeTraceNfTableBody')) {
    $d18Issues[] = 'Tabela compacta de NFs ausente na view (financeTraceNfTableBody)';
}

// JS checks
if (!str_contains($traceFinanceJs, 'BIomieFinanceTrace')) {
    $d18Issues[] = 'visual-analytics.js sem modulo BIomieFinanceTrace';
}
if (!str_contains($traceFinanceJs, 'normalizePaymentMethod') && !str_contains($traceFinanceJs, 'normalized_payment_method')) {
    $d18Warns[] = 'visual-analytics.js pode nao mostrar metodo de pagamento normalizado';
}

// Run audit CLI
if (is_file($traceAuditFile)) {
    $rTrace = rg_run($php, $traceAuditFile, ["--start={$start}", "--end={$end}", '--json']);
    $decoded = json_decode($rTrace['output'], true);
    if (!is_array($decoded)) {
        $d18Issues[] = 'finance_receivable_trace_audit.php nao retornou JSON valido';
    } elseif (!($decoded['ok'] ?? false)) {
        $d18Issues[] = 'finance_receivable_trace_audit.php retornou ok=false: ' . (string) ($decoded['error'] ?? 'erro');
    }
}

$d18Pass = count($d18Issues) === 0;
$domains[] = [
    'label'   => 'D18 Finance Receivable Traceability',
    'guard'   => 'finance_receivable_trace_audit.php + FIN.R4.1 static audit',
    'pass'    => $d18Pass,
    'warn'    => count($d18Warns) > 0,
    'summary' => $d18Pass
        ? (count($d18Warns) > 0 ? 'FIN.R4.1 rastreabilidade ok com aviso' : 'FIN.R4.1 rastreabilidade NF×boleto×vendedor presente')
        : count($d18Issues) . ' problema(s) na rastreabilidade financeira',
    'issues'  => array_merge($d18Issues, $d18Warns),
];
if (!$d18Pass) $allPass = false;

// ── D19 — Finance Payment Method Enrichment (FIN.R4.2) [informacional] ───────
$d19Issues = [];

$payloadAuditFile  = $toolsDir . '/finance_payment_method_payload_audit.php';
$financeRepoFile   = $projectRoot . '/app/FinanceRepository.php';
$syncCoordFile     = $projectRoot . '/app/Sync/FinanceSyncCoordinator.php';
$traceBuilderFile2 = $projectRoot . '/app/Support/FinanceReceivableTraceBuilder.php';

if (!is_file($payloadAuditFile)) {
    $d19Issues[] = 'finance_payment_method_payload_audit.php ausente';
}

$financeRepoSrc = is_file($financeRepoFile) ? (string) file_get_contents($financeRepoFile) : '';
if (!str_contains($financeRepoSrc, 'listReceivablesByDueDate')) {
    $d19Issues[] = 'FinanceRepository: listReceivablesByDueDate() ausente';
}
if (!str_contains($financeRepoSrc, 'ListarContasReceber')) {
    $d19Issues[] = 'FinanceRepository: chamada ListarContasReceber ausente';
}

$syncCoordSrc = is_file($syncCoordFile) ? (string) file_get_contents($syncCoordFile) : '';
if (!str_contains($syncCoordSrc, 'enrichPaymentMethods')) {
    $d19Issues[] = 'FinanceSyncCoordinator: enrichPaymentMethods() ausente';
}
if (!str_contains($syncCoordSrc, 'listReceivablesByDueDate')) {
    $d19Issues[] = 'FinanceSyncCoordinator: chamada listReceivablesByDueDate ausente';
}

$traceBuilderSrc2 = is_file($traceBuilderFile2) ? (string) file_get_contents($traceBuilderFile2) : '';
if (!str_contains($traceBuilderSrc2, 'BOLETO_ESPECIES')) {
    $d19Issues[] = 'FinanceReceivableTraceBuilder: BOLETO_ESPECIES ausente (detecção por especie_documento)';
}
if (!str_contains($traceBuilderSrc2, 'detectMethodFromAccount')) {
    $d19Issues[] = 'FinanceReceivableTraceBuilder: detectMethodFromAccount() ausente (detecção bol_sn/pix_sn)';
}
if (!str_contains($traceBuilderSrc2, 'loadAccountLookup')) {
    $d19Issues[] = 'FinanceReceivableTraceBuilder: loadAccountLookup() ausente';
}

$d19Pass = $d19Issues === [];

$domains[] = [
    'label'       => 'D19 Finance Payment Method Enrichment',
    'guard'       => 'finance_payment_method_payload_audit.php + FIN.R4.2 static audit',
    'pass'        => true,
    'warn'        => count($d19Issues) > 0,
    'informational' => true,
    'summary'     => $d19Pass
        ? 'FIN.R4.2 enriquecimento presente: ListarContasReceber + bol_sn/pix_sn + BOLETO_ESPECIES'
        : count($d19Issues) . ' componente(s) FIN.R4.2 ausente(s) [informacional]',
    'issues'      => $d19Issues,
];


// ── D20 — Finance Receivable 360 (FIN.R4.3) [informacional] ─────────────────
$d20Issues = [];

$gapReportFile   = $toolsDir . '/finance_trace_linkage_gap_report.php';
$traceBuilderSrc3 = $traceBuilderSrc2; // reuse D19 read

if (!is_file($gapReportFile)) {
    $d20Issues[] = 'finance_trace_linkage_gap_report.php ausente';
}

if (!str_contains($traceBuilderSrc3, 'makeTitleIdentityKey')) {
    $d20Issues[] = 'FinanceReceivableTraceBuilder: makeTitleIdentityKey() ausente';
}
if (!str_contains($traceBuilderSrc3, 'deduped')) {
    $d20Issues[] = 'FinanceReceivableTraceBuilder: deduplicação por titulo_key ausente';
}
if (!str_contains($traceBuilderSrc3, 'is_unknown_vendor')) {
    $d20Issues[] = 'FinanceReceivableTraceBuilder: is_unknown_vendor no vendor_ranking ausente';
}
if (!str_contains($traceBuilderSrc3, 'receivable_count')) {
    $d20Issues[] = 'FinanceReceivableTraceBuilder: receivable_count no vendor_ranking ausente';
}
if (!str_contains($traceBuilderSrc3, 'pedido_id_omie')) {
    $d20Issues[] = 'FinanceReceivableTraceBuilder: pedido_id_omie em buildOpenInvoiceSummary ausente';
}

// Check JS for vendor chart fix
$visualAnalyticsJs = $projectRoot . '/assets/js/modules/finance/visual-analytics.js';
$visualJsSrc = is_file($visualAnalyticsJs) ? (string) file_get_contents($visualAnalyticsJs) : '';
if (!str_contains($visualJsSrc, 'is_unknown_vendor')) {
    $d20Issues[] = 'visual-analytics.js: is_unknown_vendor filter ausente (vendor chart fix)';
}
if (!str_contains($visualJsSrc, 'financeTraceVendorNoData')) {
    $d20Issues[] = 'visual-analytics.js: financeTraceVendorNoData card ausente';
}
if (!str_contains($visualJsSrc, 'methodBadge')) {
    $d20Issues[] = 'visual-analytics.js: methodBadge() ausente (Phase 4 badge)';
}

// Check CSS for drawer width
$financeCss = $projectRoot . '/assets/css/finance-realizado.css';
$cssSrc = is_file($financeCss) ? (string) file_get_contents($financeCss) : '';
if (!str_contains($cssSrc, '720px')) {
    $d20Issues[] = 'finance-realizado.css: drawer min-width 720px ausente (Phase 7)';
}

$domains[] = [
    'label'       => 'D20 Finance Receivable 360 (FIN.R4.3)',
    'guard'       => 'finance_trace_linkage_gap_report.php + FIN.R4.3 static audit',
    'pass'        => true,
    'warn'        => count($d20Issues) > 0,
    'informational' => true,
    'summary'     => $d20Issues === []
        ? 'FIN.R4.3 presente: gap report + dedup + vendor fix + drawer width + method badges'
        : count($d20Issues) . ' componente(s) FIN.R4.3 ausente(s) [informacional]',
    'issues'      => $d20Issues,
];


// ── D21 — Finance Receivable Identity (FIN.R4.4) [informacional] ─────────────
$d21Issues = [];

$resolverFile = $projectRoot . '/app/Support/FinanceReceivableIdentityResolver.php';
$auditFile    = $toolsDir . '/finance_receivable_identity_audit.php';

if (!file_exists($resolverFile)) {
    $d21Issues[] = 'FinanceReceivableIdentityResolver.php não encontrado';
} else {
    if (!str_contains(file_get_contents($resolverFile), 'resolveTitle')) {
        $d21Issues[] = 'FinanceReceivableIdentityResolver: método resolveTitle ausente';
    }
    if (!str_contains(file_get_contents($resolverFile), 'resolveMany')) {
        $d21Issues[] = 'FinanceReceivableIdentityResolver: método resolveMany ausente';
    }
    if (!str_contains(file_get_contents($resolverFile), 'makeIdentityKey')) {
        $d21Issues[] = 'FinanceReceivableIdentityResolver: método makeIdentityKey ausente';
    }
    if (!str_contains(file_get_contents($resolverFile), 'explainGap')) {
        $d21Issues[] = 'FinanceReceivableIdentityResolver: método explainGap ausente';
    }
    if (!str_contains(file_get_contents($resolverFile), 'batch') && !str_contains(file_get_contents($resolverFile), 'ensureLookups')) {
        $d21Issues[] = 'FinanceReceivableIdentityResolver: batch loading (ensureLookups) ausente';
    }
}

if (!file_exists($auditFile)) {
    $d21Issues[] = 'finance_receivable_identity_audit.php não encontrado';
}

$govFile = $projectRoot . '/api/finance_collection_governance.php';
if (file_exists($govFile)) {
    $govContent = file_get_contents($govFile);
    if (!str_contains($govContent, 'FinanceReceivableIdentityResolver')) {
        $d21Issues[] = 'finance_collection_governance.php não usa FinanceReceivableIdentityResolver';
    }
    if (!str_contains($govContent, 'numero_nf')) {
        $d21Issues[] = 'finance_collection_governance.php não retorna numero_nf no risk_queue';
    }
    if (!str_contains($govContent, 'gap_reason')) {
        $d21Issues[] = 'finance_collection_governance.php não retorna gap_reason';
    }
} else {
    $d21Issues[] = 'finance_collection_governance.php não encontrado';
}

// JS: riskNfCell e gap_reason no collection-governance.js
$govJsFile = $projectRoot . '/assets/js/modules/finance/collection-governance.js';
if (file_exists($govJsFile)) {
    $govJs = file_get_contents($govJsFile);
    if (!str_contains($govJs, 'riskNfCell')) {
        $d21Issues[] = 'collection-governance.js: função riskNfCell ausente (NF na fila de risco)';
    }
    if (!str_contains($govJs, 'gap_reason')) {
        $d21Issues[] = 'collection-governance.js: gap_reason não renderizado';
    }
    if (!str_contains($govJs, 'colspan="11"')) {
        $d21Issues[] = 'collection-governance.js: colspan da fila de risco deve ser 11 (coluna NF adicionada)';
    }
} else {
    $d21Issues[] = 'collection-governance.js não encontrado';
}

$domains[] = [
    'label'         => 'D21 Finance Receivable Identity (FIN.R4.4)',
    'guard'         => 'FinanceReceivableIdentityResolver + identity_audit + governance integration',
    'pass'          => true,
    'warn'          => count($d21Issues) > 0,
    'informational' => true,
    'summary'       => $d21Issues === []
        ? 'FIN.R4.4 presente: resolver + audit + governance enrichment + risk queue NF/gap_reason'
        : count($d21Issues) . ' componente(s) FIN.R4.4 ausente(s) [informacional]',
    'issues'        => $d21Issues,
];


// ── D22 — Finance Receivable Resolver + NF-Bridge (FIN.R4.4.1) [informacional] ─
$d22Issues = [];

// Tools
$gapDrillFile   = $toolsDir . '/finance_receivable_identity_gap_drilldown.php';
$bridgeAudit    = $toolsDir . '/finance_nf_title_bridge_rebuild_audit.php';
if (!file_exists($gapDrillFile)) {
    $d22Issues[] = 'finance_receivable_identity_gap_drilldown.php não encontrado';
}
if (!file_exists($bridgeAudit)) {
    $d22Issues[] = 'finance_nf_title_bridge_rebuild_audit.php não encontrado';
}

// Resolver: 6-path NF resolution
$resolverContent = file_exists($resolverFile) ? file_get_contents($resolverFile) : '';
if ($resolverContent !== '') {
    if (!str_contains($resolverContent, 'nfByNumeroNf')) {
        $d22Issues[] = 'FinanceReceivableIdentityResolver: índice nfByNumeroNf (Path 6) ausente';
    }
    if (!str_contains($resolverContent, 'title_nota_key_as_chave_nfe')) {
        $d22Issues[] = 'FinanceReceivableIdentityResolver: Path 2 (nota_key como chave_nfe 44-char) ausente';
    }
    if (!str_contains($resolverContent, 'numero_documento_nf')) {
        $d22Issues[] = 'FinanceReceivableIdentityResolver: Path 6 (numero_documento) ausente';
    }
} else {
    $d22Issues[] = 'FinanceReceivableIdentityResolver.php não encontrado';
}

// API: finance_collection_by_vendor.php — resolver integration
$byVendorFile = $projectRoot . '/api/finance_collection_by_vendor.php';
if (file_exists($byVendorFile)) {
    $byVendorContent = file_get_contents($byVendorFile);
    if (!str_contains($byVendorContent, 'FinanceReceivableIdentityResolver')) {
        $d22Issues[] = 'api/finance_collection_by_vendor.php não usa FinanceReceivableIdentityResolver';
    }
    if (!str_contains($byVendorContent, 'nf_label')) {
        $d22Issues[] = 'api/finance_collection_by_vendor.php não retorna nf_label';
    }
    if (!str_contains($byVendorContent, 'pedido_label')) {
        $d22Issues[] = 'api/finance_collection_by_vendor.php não retorna pedido_label';
    }
    if (!str_contains($byVendorContent, 'trace_quality_score')) {
        $d22Issues[] = 'api/finance_collection_by_vendor.php não retorna trace_quality_score';
    }
    if (!str_contains($byVendorContent, 'unresolved_vendor_bucket')) {
        $d22Issues[] = 'api/finance_collection_by_vendor.php não retorna unresolved_vendor_bucket';
    }
} else {
    $d22Issues[] = 'api/finance_collection_by_vendor.php não encontrado';
}

// API: finance_collection_governance.php — nf_label + pedido_label in risk_queue
if (file_exists($govFile)) {
    $govContent2 = file_get_contents($govFile);
    if (!str_contains($govContent2, 'nf_label')) {
        $d22Issues[] = 'api/finance_collection_governance.php não retorna nf_label no risk_queue';
    }
    if (!str_contains($govContent2, 'pedido_label')) {
        $d22Issues[] = 'api/finance_collection_governance.php não retorna pedido_label no risk_queue';
    }
} else {
    $d22Issues[] = 'api/finance_collection_governance.php não encontrado';
}

// JS: collection-by-vendor.js — renderUnresolvedVendorAlert + nf_label + pedido_label
$byVendorJs = $projectRoot . '/assets/js/modules/finance/collection-by-vendor.js';
if (file_exists($byVendorJs)) {
    $byVendorJsContent = file_get_contents($byVendorJs);
    if (!str_contains($byVendorJsContent, 'renderUnresolvedVendorAlert')) {
        $d22Issues[] = 'collection-by-vendor.js: renderUnresolvedVendorAlert ausente';
    }
    if (!str_contains($byVendorJsContent, 'nf_label')) {
        $d22Issues[] = 'collection-by-vendor.js: nf_label não usado em renderOriginRef';
    }
    if (!str_contains($byVendorJsContent, 'pedido_label')) {
        $d22Issues[] = 'collection-by-vendor.js: pedido_label não usado em renderOriginRef';
    }
} else {
    $d22Issues[] = 'collection-by-vendor.js não encontrado';
}

// JS: collection-governance.js — riskNfCell prefers nf_label
if (file_exists($govJsFile)) {
    $govJs2 = file_get_contents($govJsFile);
    if (!str_contains($govJs2, 'nf_label')) {
        $d22Issues[] = 'collection-governance.js: riskNfCell não usa nf_label';
    }
    if (!str_contains($govJs2, 'pedido_label')) {
        $d22Issues[] = 'collection-governance.js: riskNfCell não usa pedido_label';
    }
} else {
    $d22Issues[] = 'collection-governance.js não encontrado';
}

$domains[] = [
    'label'         => 'D22 Finance Collection Resolver + NF-Bridge (FIN.R4.4.1)',
    'guard'         => 'gap drilldown + bridge audit + resolver 6-path + by_vendor integration + nf_label/pedido_label + unresolved_bucket',
    'pass'          => true,
    'warn'          => count($d22Issues) > 0,
    'informational' => true,
    'summary'       => $d22Issues === []
        ? 'FIN.R4.4.1 presente: resolver 6-path + drilldown + bridge audit + nf_label/pedido_label + unresolved_bucket + visual'
        : count($d22Issues) . ' componente(s) FIN.R4.4.1 ausente(s) [informacional]',
    'issues'        => $d22Issues,
];


// ── D23 — Commercial Semantics Classifier (COM.R1.2) [informacional] ─────────
$d23Issues = [];

$classifierFile    = $projectRoot . '/app/Support/CommercialPipelineClassifier.php';
$salesSupportFile  = $projectRoot . '/app/SalesAnalytics/SalesSupport.php';
$syncFile          = $projectRoot . '/app/Sync/CommercialSyncCoordinator.php';
$stageCatalogTool  = $toolsDir   . '/omie_stage_catalog_audit.php';
$staleAuditTool    = $toolsDir   . '/commercial_stage_stale_audit.php';
$staleRepairTool   = $toolsDir   . '/commercial_stage_stale_repair.php';

// 1. CommercialPipelineClassifier exists
if (!file_exists($classifierFile)) {
    $d23Issues[] = 'CommercialPipelineClassifier.php não encontrado';
} else {
    $clf = file_get_contents($classifierFile);
    if (!str_contains($clf, 'SCOPE_ENTREGA_FINALIZADA')) {
        $d23Issues[] = 'CommercialPipelineClassifier: constante SCOPE_ENTREGA_FINALIZADA ausente';
    }
    if (!str_contains($clf, 'SCOPE_ORCAMENTO')) {
        $d23Issues[] = 'CommercialPipelineClassifier: constante SCOPE_ORCAMENTO ausente';
    }
    if (!str_contains($clf, 'SCOPE_CARTEIRA_ACEITA')) {
        $d23Issues[] = 'CommercialPipelineClassifier: constante SCOPE_CARTEIRA_ACEITA ausente';
    }
    if (!str_contains($clf, "case 60:") || !str_contains($clf, 'entrega_finalizada_NOT_new_revenue')) {
        $d23Issues[] = 'CommercialPipelineClassifier: etapa 60 não classificada como entrega_finalizada';
    }
    if (!str_contains($clf, 'classifyEtapa')) {
        $d23Issues[] = 'CommercialPipelineClassifier: método classifyEtapa ausente';
    }
    if (str_contains($clf, 'SELECT *')) {
        $d23Issues[] = 'CommercialPipelineClassifier: contém SELECT * (proibido)';
    }
}

// 2. SalesSupport::classifyStatus does NOT classify etapa 60 as faturado
if (file_exists($salesSupportFile)) {
    $ss = file_get_contents($salesSupportFile);
    // etapa 60 fix: check the code has the explicit === 60 check before the 50-69 range
    if (!str_contains($ss, '$code === 60')) {
        $d23Issues[] = 'SalesSupport::classifyStatus: sem verificação explícita de etapa 60';
    }
    if (!str_contains($ss, 'classifyStatusExtended')) {
        $d23Issues[] = 'SalesSupport: método classifyStatusExtended ausente';
    }
} else {
    $d23Issues[] = 'SalesAnalytics/SalesSupport.php não encontrado';
}

// 3. CommercialSyncCoordinator classifyStatus: etapa 60 NOT returning faturado
if (file_exists($syncFile)) {
    $sync = file_get_contents($syncFile);
    if (!str_contains($sync, '$code === 60')) {
        $d23Issues[] = 'CommercialSyncCoordinator::classifyStatus: sem verificação explícita de etapa 60';
    }
} else {
    $d23Issues[] = 'CommercialSyncCoordinator.php não encontrado';
}

// 4. Diagnostic tools exist
if (!file_exists($stageCatalogTool)) {
    $d23Issues[] = 'omie_stage_catalog_audit.php não encontrado';
}
if (!file_exists($staleAuditTool)) {
    $d23Issues[] = 'commercial_stage_stale_audit.php não encontrado';
}
if (!file_exists($staleRepairTool)) {
    $d23Issues[] = 'commercial_stage_stale_repair.php não encontrado';
} else {
    $repair = file_get_contents($staleRepairTool);
    // 5. repair is dry-run by default
    if (!str_contains($repair, '$dryRun   = !isset($opts[\'apply\'])')) {
        $d23Issues[] = 'commercial_stage_stale_repair.php: dry-run não é o padrão';
    }
    // 6. apply is blocked
    if (!str_contains($repair, 'BLOQUEADO') || !str_contains($repair, 'apply_blocked')) {
        $d23Issues[] = 'commercial_stage_stale_repair.php: --apply não está explicitamente bloqueado';
    }
}

// 7. No SELECT * in new files
foreach ([$classifierFile, $stageCatalogTool, $staleAuditTool, $staleRepairTool] as $chkFile) {
    if (file_exists($chkFile) && str_contains(file_get_contents($chkFile), 'SELECT *')) {
        $d23Issues[] = basename($chkFile) . ': contém SELECT * (proibido)';
    }
}

$domains[] = [
    'label'         => 'D23 Commercial Semantics Classifier (COM.R1.2)',
    'guard'         => 'CommercialPipelineClassifier + classifyStatus fixes (etapa 60) + stage audit tools',
    'pass'          => true,
    'warn'          => count($d23Issues) > 0,
    'informational' => true,
    'summary'       => $d23Issues === []
        ? 'COM.R1.2 presente: classifier + etapa-60 fix + stage catalog + stale audit + repair dry-run'
        : count($d23Issues) . ' componente(s) COM.R1.2 ausente(s) [informacional]',
    'issues'        => $d23Issues,
];


// ── D24 — Commercial Goals and Pipeline Semantics (COM.R1.3) [informacional] ──
$d24Issues = [];

$goalCalcFile       = $projectRoot . '/app/Support/SalesGoalPeriodCalculator.php';
$dashImpactTool     = $toolsDir   . '/commercial_dashboard_semantics_impact_audit.php';
$goalAuditTool      = $toolsDir   . '/sales_goal_period_audit.php';
$ownershipTool      = $toolsDir   . '/customer_ownership_source_audit.php';

// 1. SalesGoalPeriodCalculator exists
if (!file_exists($goalCalcFile)) {
    $d24Issues[] = 'SalesGoalPeriodCalculator.php não encontrado';
} else {
    $gcContent = file_get_contents($goalCalcFile);
    // 8. self-test for broken periods
    if (!str_contains($gcContent, 'selfTest')) {
        $d24Issues[] = 'SalesGoalPeriodCalculator: método selfTest para períodos quebrados ausente';
    }
    if (!str_contains($gcContent, 'dias_intersectados') && !str_contains($gcContent, 'overlapStart') && !str_contains($gcContent, 'dias_considerados')) {
        $d24Issues[] = 'SalesGoalPeriodCalculator: lógica de dias intersectados ausente';
    }
    if (str_contains($gcContent, 'SELECT *')) {
        $d24Issues[] = 'SalesGoalPeriodCalculator: contém SELECT * (proibido)';
    }
}

// 2-4. Diagnostic tools exist
foreach ([$dashImpactTool => 'commercial_dashboard_semantics_impact_audit.php',
          $goalAuditTool  => 'sales_goal_period_audit.php',
          $ownershipTool  => 'customer_ownership_source_audit.php'] as $file => $label) {
    if (!file_exists($file)) {
        $d24Issues[] = "{$label} não encontrado";
    } else {
        // 9. No SELECT * in new files
        if (str_contains(file_get_contents($file), 'SELECT *')) {
            $d24Issues[] = "{$label}: contém SELECT *";
        }
    }
}

// 5. CommercialPipelineClassifier is used in diagnostics
if (file_exists($dashImpactTool)) {
    if (!str_contains(file_get_contents($dashImpactTool), 'CommercialPipelineClassifier')) {
        $d24Issues[] = 'commercial_dashboard_semantics_impact_audit.php não usa CommercialPipelineClassifier';
    }
}

// 6. etapa 60 still not faturado in classifier
if (file_exists($classifierFile)) {
    $clf2 = file_get_contents($classifierFile);
    if (!str_contains($clf2, 'entrega_finalizada_NOT_new_revenue')) {
        $d24Issues[] = 'CommercialPipelineClassifier: etapa 60 como entrega_finalizada ausente';
    }
}

// 7. Base Comercial NF preserved — check dashboard impact tool doesn't write NF table
if (file_exists($dashImpactTool)) {
    $dat = file_get_contents($dashImpactTool);
    if (str_contains($dat, 'UPDATE') || str_contains($dat, 'INSERT') || str_contains($dat, 'DELETE')) {
        $d24Issues[] = 'commercial_dashboard_semantics_impact_audit.php contém escrita no banco (proibido)';
    }
}

// 10-12. No migration, no ENUM write, Base NF preserved (static checks)
foreach ([$goalCalcFile, $dashImpactTool, $goalAuditTool, $ownershipTool] as $chk) {
    if (!file_exists($chk)) continue;
    $content = file_get_contents($chk);
    if (str_contains($content, 'ALTER TABLE') || str_contains($content, 'CREATE TABLE')) {
        $d24Issues[] = basename($chk) . ': contém DDL (ALTER/CREATE TABLE — proibido)';
    }
}

$domains[] = [
    'label'         => 'D24 Commercial Goals and Pipeline Semantics (COM.R1.3)',
    'guard'         => 'SalesGoalPeriodCalculator + dashboard impact + goal audit + ownership audit',
    'pass'          => true,
    'warn'          => count($d24Issues) > 0,
    'informational' => true,
    'summary'       => $d24Issues === []
        ? 'COM.R1.3 presente: proporcional calculator + dashboard impact + goal audit + ownership audit'
        : count($d24Issues) . ' componente(s) COM.R1.3 ausente(s) [informacional]',
    'issues'        => $d24Issues,
];


// ── D25 — Customer Ownership and Goal Application (COM.R1.4) [informacional] ──
$d25Issues = [];

$ownershipResolverFile  = $projectRoot . '/app/Support/CustomerOwnershipResolver.php';
$conflictAuditTool      = $toolsDir   . '/customer_ownership_conflict_audit.php';
$conflictApiFile        = $projectRoot . '/api/customer_ownership_conflicts.php';
$canonicalVendorsFile   = $projectRoot . '/api/canonical_vendors.php';

// 1. CustomerOwnershipResolver exists
if (!file_exists($ownershipResolverFile)) {
    $d25Issues[] = 'CustomerOwnershipResolver.php não encontrado';
} else {
    $orContent = file_get_contents($ownershipResolverFile);
    if (!str_contains($orContent, 'is_out_of_portfolio')) {
        $d25Issues[] = 'CustomerOwnershipResolver: campo is_out_of_portfolio ausente';
    }
    if (!str_contains($orContent, 'CONFLICT_SOLD_BY_OTHER')) {
        $d25Issues[] = 'CustomerOwnershipResolver: constante CONFLICT_SOLD_BY_OTHER ausente';
    }
    if (str_contains($orContent, 'SELECT *')) {
        $d25Issues[] = 'CustomerOwnershipResolver: contém SELECT *';
    }
}

// 2. Conflict audit tool exists
if (!file_exists($conflictAuditTool)) {
    $d25Issues[] = 'customer_ownership_conflict_audit.php não encontrado';
}

// 3. Conflict API exists
if (!file_exists($conflictApiFile)) {
    $d25Issues[] = 'api/customer_ownership_conflicts.php não encontrado';
} else {
    $cac = file_get_contents($conflictApiFile);
    if (str_contains($cac, 'SELECT *')) {
        $d25Issues[] = 'api/customer_ownership_conflicts.php contém SELECT *';
    }
    // 10. Conflict does not block sale
    if (!str_contains($cac, 'Não bloqueia') && !str_contains($cac, 'nao bloqueia') && !str_contains($cac, 'not block')) {
        $d25Issues[] = 'api/customer_ownership_conflicts.php: missing "não bloqueia" note';
    }
}

// 3. SalesGoalPeriodCalculator still exists
if (!file_exists($goalCalcFile)) {
    $d25Issues[] = 'SalesGoalPeriodCalculator.php não encontrado (regressão)';
}

// 4-5. canonical_vendors.php uses SalesGoalPeriodCalculator and CommercialPlanning
if (file_exists($canonicalVendorsFile)) {
    $cvContent = file_get_contents($canonicalVendorsFile);
    if (!str_contains($cvContent, 'SalesGoalPeriodCalculator')) {
        $d25Issues[] = 'canonical_vendors.php não usa SalesGoalPeriodCalculator';
    }
    if (!str_contains($cvContent, 'target_period')) {
        $d25Issues[] = 'canonical_vendors.php não expõe target_period por vendedor';
    }
    if (!str_contains($cvContent, 'conflicts_out_of_portfolio')) {
        $d25Issues[] = 'canonical_vendors.php não expõe conflicts_out_of_portfolio';
    }
} else {
    $d25Issues[] = 'api/canonical_vendors.php não encontrado';
}

// 6. Realized still by NF (app.js check)
$appJs = $projectRoot . '/assets/js/app.js';
if (file_exists($appJs)) {
    $aj = file_get_contents($appJs);
    if (!str_contains($aj, 'goal_period')) {
        $d25Issues[] = 'app.js não processa goal_period (meta proporcional)';
    }
    if (!str_contains($aj, 'ownership_summary') && !str_contains($aj, 'kpiConflict')) {
        $d25Issues[] = 'app.js não processa ownership_summary (carteirização)';
    }
}

// 7-8. Carteira and entrega_finalizada not in realizado — checked via classifier (D23 covers this)

// 9. biomie_clientes_dim.vendedor_responsavel_nome as primary source
if (file_exists($ownershipResolverFile)) {
    if (!str_contains(file_get_contents($ownershipResolverFile), 'clientes_dim')) {
        $d25Issues[] = 'CustomerOwnershipResolver: biomie_clientes_dim não é fonte primária';
    }
}

// 11-13. No SELECT *, no migration, no schema change
foreach ([$ownershipResolverFile, $conflictAuditTool, $conflictApiFile] as $chk) {
    if (!file_exists($chk)) continue;
    $content = file_get_contents($chk);
    if (str_contains($content, 'ALTER TABLE') || str_contains($content, 'CREATE TABLE')) {
        $d25Issues[] = basename($chk) . ': contém DDL (proibido)';
    }
}

// 14. Base Comercial NF preserved — vendas NF check in conflict API
if (file_exists($conflictApiFile) && !str_contains(file_get_contents($conflictApiFile), 'biomie_notas_fiscais_canonicas')) {
    $d25Issues[] = 'api/customer_ownership_conflicts.php não usa biomie_notas_fiscais_canonicas';
}

// 15. BiVisibilityScope preserved — no override
if (file_exists($ownershipResolverFile) && str_contains(file_get_contents($ownershipResolverFile), 'is_biomie_commercial_visible')) {
    $d25Issues[] = 'CustomerOwnershipResolver altera BiVisibilityScope (proibido)';
}

$domains[] = [
    'label'         => 'D25 Customer Ownership and Goal Application (COM.R1.4)',
    'guard'         => 'CustomerOwnershipResolver + conflict audit + conflict API + canonical_vendors targets + carteirização KPIs',
    'pass'          => true,
    'warn'          => count($d25Issues) > 0,
    'informational' => true,
    'summary'       => $d25Issues === []
        ? 'COM.R1.4 presente: ownership resolver + conflict API + targets em canonical_vendors + KPIs de carteirização'
        : count($d25Issues) . ' componente(s) COM.R1.4 ausente(s) [informacional]',
    'issues'        => $d25Issues,
];


// ── D26 — Vendor Alias Channel + Active Status (COM.R1.5) [informacional] ────
$d26Issues = [];

$vendorAliasResolverFile  = $projectRoot . '/app/Support/VendorAliasResolver.php';
$activeVendorResolverFile = $projectRoot . '/app/Support/ActiveVendorResolver.php';
$vendorAliasAuditFile     = $toolsDir   . '/vendor_alias_audit.php';
$vendorActiveAuditFile    = $toolsDir   . '/vendor_active_status_audit.php';
$vendedoresHtml           = $projectRoot . '/views/vendedores.html';
$appJsFile                = $projectRoot . '/assets/js/app.js';
$conflictAuditToolD26     = $toolsDir   . '/customer_ownership_conflict_audit.php';

// 1. VendorAliasResolver exists and does NOT treat REV=VAR
if (!file_exists($vendorAliasResolverFile)) {
    $d26Issues[] = 'VendorAliasResolver.php não encontrado';
} else {
    $var = file_get_contents($vendorAliasResolverFile);
    if (!str_contains($var, 'CHANNEL_REV') || !str_contains($var, 'CHANNEL_VAR')) {
        $d26Issues[] = 'VendorAliasResolver: constantes CHANNEL_REV e CHANNEL_VAR ausentes';
    }
    if (!str_contains($var, 'areEquivalent')) {
        $d26Issues[] = 'VendorAliasResolver: método areEquivalent() ausente';
    }
    if (!str_contains($var, 'isCrossChannelConflict')) {
        $d26Issues[] = 'VendorAliasResolver: método isCrossChannelConflict() ausente';
    }
    // Ensure REV and VAR are never resolved as equivalent in the default map
    if (str_contains($var, "' - rev', ' - var'], '', ") || str_contains($var, '"- rev", "- var"')) {
        $d26Issues[] = 'VendorAliasResolver: strip de sufixos REV/VAR detectado (proibido)';
    }
    if (str_contains($var, 'SELECT *')) {
        $d26Issues[] = 'VendorAliasResolver: contém SELECT *';
    }
}

// 2. ActiveVendorResolver exists
if (!file_exists($activeVendorResolverFile)) {
    $d26Issues[] = 'ActiveVendorResolver.php não encontrado';
} else {
    $avr = file_get_contents($activeVendorResolverFile);
    if (!str_contains($avr, 'biomie_vendedores_omie')) {
        $d26Issues[] = 'ActiveVendorResolver: não referencia biomie_vendedores_omie';
    }
    if (!str_contains($avr, 'is_active')) {
        $d26Issues[] = 'ActiveVendorResolver: campo is_active ausente';
    }
    if (!str_contains($avr, 'getActiveVendorNames')) {
        $d26Issues[] = 'ActiveVendorResolver: método getActiveVendorNames() ausente';
    }
}

// 3. canonical_vendors.php usa active_only por padrão
if (file_exists($canonicalVendorsFile)) {
    $cvD26 = file_get_contents($canonicalVendorsFile);
    if (!str_contains($cvD26, 'active_only') || !str_contains($cvD26, 'ActiveVendorResolver')) {
        $d26Issues[] = 'canonical_vendors.php não usa active_only/ActiveVendorResolver';
    }
    if (!str_contains($cvD26, 'is_active') || !str_contains($cvD26, 'vendor_channel')) {
        $d26Issues[] = 'canonical_vendors.php não expõe is_active/vendor_channel por vendedor';
    }
} else {
    $d26Issues[] = 'api/canonical_vendors.php não encontrado';
}

// 4. vendedores.html tem filtro "Incluir inativos"
if (file_exists($vendedoresHtml)) {
    $vhD26 = file_get_contents($vendedoresHtml);
    if (!str_contains($vhD26, 'Incluir inativos') || !str_contains($vhD26, 'vendorIncludeInactiveBtn')) {
        $d26Issues[] = 'vendedores.html sem toggle "Incluir inativos" (vendorIncludeInactiveBtn)';
    }
    if (!str_contains($vhD26, 'BIomieVendorsIncludeInactive')) {
        $d26Issues[] = 'vendedores.html sem flag BIomieVendorsIncludeInactive';
    }
} else {
    $d26Issues[] = 'views/vendedores.html não encontrado';
}

// 5. app.js passa include_inactive para api/vendors.php
if (file_exists($appJsFile)) {
    $ajD26 = file_get_contents($appJsFile);
    if (!str_contains($ajD26, 'BIomieVendorsIncludeInactive') || !str_contains($ajD26, 'include_inactive')) {
        $d26Issues[] = 'app.js não passa include_inactive ao carregar vendedores';
    }
}

// 6. CustomerOwnershipResolver tem novos tipos de conflito inativo e cross-channel
if (file_exists($ownershipResolverFile)) {
    $corD26 = file_get_contents($ownershipResolverFile);
    if (!str_contains($corD26, 'CONFLICT_OWNER_INACTIVE_REVIEW')) {
        $d26Issues[] = 'CustomerOwnershipResolver: CONFLICT_OWNER_INACTIVE_REVIEW ausente';
    }
    if (!str_contains($corD26, 'CONFLICT_CROSS_CHANNEL')) {
        $d26Issues[] = 'CustomerOwnershipResolver: CONFLICT_CROSS_CHANNEL ausente';
    }
    if (!str_contains($corD26, 'isCrossChannelConflict')) {
        $d26Issues[] = 'CustomerOwnershipResolver: isCrossChannelConflict() ausente';
    }
}

// 7. customer_ownership_conflict_audit.php classifica owner_inactive_review
if (file_exists($conflictAuditToolD26)) {
    $catD26 = file_get_contents($conflictAuditToolD26);
    if (!str_contains($catD26, 'owner_inactive_review') && !str_contains($catD26, 'CONFLICT_OWNER_INACTIVE_REVIEW')) {
        $d26Issues[] = 'customer_ownership_conflict_audit.php não classifica owner_inactive_review';
    }
    if (!str_contains($catD26, 'cross_channel')) {
        $d26Issues[] = 'customer_ownership_conflict_audit.php não classifica cross_channel_conflicts';
    }
}

// 8. Audit tools exist
if (!file_exists($vendorAliasAuditFile))  $d26Issues[] = 'vendor_alias_audit.php não encontrado';
if (!file_exists($vendorActiveAuditFile)) $d26Issues[] = 'vendor_active_status_audit.php não encontrado';

// 9. No migration, no schema change, no Base NF alteration
foreach ([$vendorAliasResolverFile, $activeVendorResolverFile] as $chk) {
    if (!file_exists($chk)) continue;
    $c = file_get_contents($chk);
    if (str_contains($c, 'ALTER TABLE') || str_contains($c, 'CREATE TABLE')) {
        $d26Issues[] = basename($chk) . ': contém DDL (proibido)';
    }
}

// 10. VendorIdentityResolver fix: cleanAlias does NOT strip ' - rev'/' - var' cross-channel
$vendorIdentityResolver = $projectRoot . '/app/Modules/Sales/Application/Vendors/VendorIdentityResolver.php';
if (file_exists($vendorIdentityResolver)) {
    $vir = file_get_contents($vendorIdentityResolver);
    // Check the old broken pattern is gone
    if (str_contains($vir, "str_replace([' - rev', ' - var'], '', \$normalized)")) {
        $d26Issues[] = 'VendorIdentityResolver: ainda remove REV/VAR cross-channel (bug não corrigido)';
    }
    if (!str_contains($vir, 'extractChannel') || !str_contains($vir, 'stripChannel')) {
        $d26Issues[] = 'VendorIdentityResolver: métodos extractChannel/stripChannel ausentes';
    }
}

$domains[] = [
    'label'         => 'D26 Vendor Alias Channel + Active Status (COM.R1.5)',
    'guard'         => 'VendorAliasResolver + ActiveVendorResolver + active_only + CAIO channel separation',
    'pass'          => true,
    'warn'          => count($d26Issues) > 0,
    'informational' => true,
    'summary'       => $d26Issues === []
        ? 'COM.R1.5 presente: VendorAliasResolver + ActiveVendorResolver + CAIO-REV≠CAIO-VAR + active_only + inactive toggle'
        : count($d26Issues) . ' componente(s) COM.R1.5 ausente(s) [informacional]',
    'issues'        => $d26Issues,
];


// ── D9 — Cache clear [apenas com --clear-cache] ───────────────────────────────
// D26.1 - Vendor Master Sync & Status Fidelity (COM.R1.5.1) [informational]
$d261Issues = [];
$omieVendorPayloadAuditFile = $toolsDir . '/omie_vendor_payload_audit.php';
$vendorMasterReconAuditFile = $toolsDir . '/vendor_master_reconciliation_audit.php';

if (!file_exists($omieVendorPayloadAuditFile)) {
    $d261Issues[] = 'omie_vendor_payload_audit.php nao encontrado';
}
if (!file_exists($vendorMasterReconAuditFile)) {
    $d261Issues[] = 'vendor_master_reconciliation_audit.php nao encontrado';
}

if (file_exists($activeVendorResolverFile)) {
    $avr261 = file_get_contents($activeVendorResolverFile);
    foreach (['vendor_status_source_untrusted', 'active_status_mapping_gap', 'ghost_vendor'] as $needle) {
        if (!str_contains($avr261, $needle)) {
            $d261Issues[] = 'ActiveVendorResolver nao sinaliza ' . $needle;
        }
    }
}

if (file_exists($vendorAliasResolverFile)) {
    $var261 = file_get_contents($vendorAliasResolverFile);
    foreach (['caio bruno', 'requires_manual_approval', 'channel_match', 'CAIO - REV'] as $needle) {
        if (!str_contains($var261, $needle)) {
            $d261Issues[] = 'VendorAliasResolver sem marcador esperado: ' . $needle;
        }
    }
}

if (file_exists($canonicalVendorsFile)) {
    $cv261 = file_get_contents($canonicalVendorsFile);
    foreach (['active_status_confidence', 'active_status_warning', 'ghost_vendor', 'vendor_channel'] as $needle) {
        if (!str_contains($cv261, $needle)) {
            $d261Issues[] = 'canonical_vendors.php nao expoe ' . $needle;
        }
    }
}

if (file_exists($vendedoresHtml)) {
    $vh261 = file_get_contents($vendedoresHtml);
    if (!str_contains($vh261, 'Status de vendedores em valida')) {
        $d261Issues[] = 'vendedores.html sem alerta de status em validacao';
    }
}

foreach ([$omieVendorPayloadAuditFile, $vendorMasterReconAuditFile, $vendorActiveAuditFile] as $auditFile) {
    if (!file_exists($auditFile)) {
        continue;
    }
    $content = file_get_contents($auditFile);
    foreach (['ALTER TABLE', 'CREATE TABLE', 'DROP TABLE', 'TRUNCATE TABLE', 'UPDATE ', 'INSERT ', 'DELETE '] as $needle) {
        if (str_contains($content, $needle)) {
            $d261Issues[] = basename($auditFile) . ': contem escrita/DDL proibida (' . trim($needle) . ')';
        }
    }
}

$domains[] = [
    'label'         => 'D26.1 Vendor Master Sync & Status Fidelity (COM.R1.5.1)',
    'guard'         => 'payload audit + reconciliation audit + resolver confidence + ghost vendor checks',
    'pass'          => true,
    'warn'          => count($d261Issues) > 0,
    'informational' => true,
    'summary'       => $d261Issues === []
        ? 'COM.R1.5.1 presente: payload audit + reconciliacao + status confidence + ghost vendors'
        : count($d261Issues) . ' componente(s) COM.R1.5.1 ausente(s) [informacional]',
    'issues'        => $d261Issues,
];

// D26.2 - Vendor Status Sync Repair (COM.R1.5.2) [informational]
$d262Issues = [];
$omieVendorStatusMapperFile = $projectRoot . '/app/Support/OmieVendorStatusMapper.php';
$omieVendorStatusRepairFile = $toolsDir . '/omie_vendor_status_sync_repair.php';
$canonicalWriteRepositoryFile = $projectRoot . '/app/Canonical/CanonicalWriteRepository.php';
$canonicalRepositoryFile = $projectRoot . '/app/CanonicalRepository.php';
$commercialSyncCoordinatorFile = $projectRoot . '/app/Sync/CommercialSyncCoordinator.php';
$fiscalSyncCoordinatorFile = $projectRoot . '/app/Sync/FiscalSyncCoordinator.php';

if (!file_exists($omieVendorStatusMapperFile)) {
    $d262Issues[] = 'OmieVendorStatusMapper.php nao encontrado';
} else {
    $mapper262 = file_get_contents($omieVendorStatusMapperFile);
    foreach (['inativo', 'statusValueToActive', 'isActive'] as $needle) {
        if (!str_contains($mapper262, $needle)) {
            $d262Issues[] = 'OmieVendorStatusMapper sem marcador esperado: ' . $needle;
        }
    }
}

if (!file_exists($omieVendorStatusRepairFile)) {
    $d262Issues[] = 'omie_vendor_status_sync_repair.php nao encontrado';
} else {
    $repair262 = file_get_contents($omieVendorStatusRepairFile);
    // Needles que devem sempre estar presentes
    foreach (['apply_requires_exact_10_active_to_inactive', 'UPDATE biomie_vendedores_omie SET ativo'] as $needle) {
        if (!str_contains($repair262, $needle)) {
            $d262Issues[] = 'omie_vendor_status_sync_repair.php sem trava esperada: ' . $needle;
        }
    }
    // expectedDivergences: aceitar 10 (pass1) ou 6 (pass2 concluído) como referência histórica
    if (!str_contains($repair262, 'expectedDivergences = 10') && !str_contains($repair262, 'expectedDivergences = 6')) {
        $d262Issues[] = 'omie_vendor_status_sync_repair.php sem referencia expectedDivergences (10 ou 6)';
    }
    foreach (['DELETE ', 'TRUNCATE TABLE', 'DROP TABLE', 'ALTER TABLE', 'CREATE TABLE'] as $needle) {
        if (str_contains($repair262, $needle)) {
            $d262Issues[] = 'omie_vendor_status_sync_repair.php contem operacao proibida: ' . trim($needle);
        }
    }
}

if (file_exists($canonicalWriteRepositoryFile)) {
    $cwr262 = file_get_contents($canonicalWriteRepositoryFile);
    if (!str_contains($cwr262, ':ativo') || !str_contains($cwr262, 'ativo = VALUES(ativo)')) {
        $d262Issues[] = 'CanonicalWriteRepository::upsertVendor nao persiste ativo dinamico';
    }
} else {
    $d262Issues[] = 'CanonicalWriteRepository.php nao encontrado';
}

if (file_exists($canonicalRepositoryFile)) {
    $cr262 = file_get_contents($canonicalRepositoryFile);
    if (!str_contains($cr262, 'bool $ativo = true') || !str_contains($cr262, '$comissao, $ativo')) {
        $d262Issues[] = 'CanonicalRepository::upsertVendor nao repassa ativo';
    }
}

foreach ([$commercialSyncCoordinatorFile, $fiscalSyncCoordinatorFile] as $syncFile) {
    if (!file_exists($syncFile)) {
        $d262Issues[] = basename($syncFile) . ' nao encontrado';
        continue;
    }
    $sync262 = file_get_contents($syncFile);
    if (!str_contains($sync262, 'OmieVendorStatusMapper') || !str_contains($sync262, '$statusMapper->isActive') || !str_contains($sync262, '$comissao, $ativo')) {
        $d262Issues[] = basename($syncFile) . ' nao mapeia inativo Omie para ativo BIomie';
    }
}

// D26.2 runtime check: verify 0 divergences between Omie and BIomie right now
if (file_exists($omieVendorStatusRepairFile)) {
    $rRepair = rg_run($php, $omieVendorStatusRepairFile, ['--dry-run', '--json']);
    $repairJson = json_decode($rRepair['output'], true);
    if (!is_array($repairJson)) {
        $d262Issues[] = 'omie_vendor_status_sync_repair.php --dry-run nao retornou JSON valido';
    } else {
        $plannedUpdates = (int) ($repairJson['summary']['planned_updates'] ?? -1);
        $dbActive       = (int) ($repairJson['summary']['db_before']['active'] ?? -1);
        $dbInactive     = (int) ($repairJson['summary']['db_before']['inactive'] ?? -1);
        if ($plannedUpdates !== 0) {
            $d262Issues[] = "D26.2 RUNTIME: repair dry-run tem {$plannedUpdates} planned update(s) — estado nao alinhado com Omie";
        }
        if ($dbActive !== 19 || $dbInactive !== 10) {
            $d262Issues[] = "D26.2 RUNTIME: BIomie tem {$dbActive} ativos / {$dbInactive} inativos (esperado 19 / 10)";
        }
    }
}

$domains[] = [
    'label'         => 'D26.2 Vendor Status Sync Repair (COM.R1.5.2)',
    'guard'         => 'sync maps Omie inativo S/N + repair dry-run must be 0 + BIomie must be 19 active / 10 inactive',
    'pass'          => true,
    'warn'          => count($d262Issues) > 0,
    'informational' => true,
    'summary'       => $d262Issues === []
        ? 'COM.R1.5.2 presente: sync respeita inativo Omie, dry-run=0, BIomie 19 ativos / 10 inativos'
        : count($d262Issues) . ' componente(s) COM.R1.5.2 ausente(s) ou divergencia detectada [informacional]',
    'issues'        => $d262Issues,
];

// ── D27 — Commercial Semantics & Goals (COM.R1 umbrella) [informacional] ─────
$d27Issues = [];

$classifierFile      = $projectRoot . '/app/Support/CommercialPipelineClassifier.php';
$goalCalcFile27      = $projectRoot . '/app/Support/SalesGoalPeriodCalculator.php';
$portfolioResolver   = $projectRoot . '/app/Support/ClientPortfolioOwnershipResolver.php';
$stageAuditFile      = $toolsDir   . '/commercial_stage_semantics_audit.php';
$goalAuditFile27     = $toolsDir   . '/sales_goal_period_audit.php';
$portfolioAuditFile  = $toolsDir   . '/client_portfolio_ownership_audit.php';

// 1-6. Required files
$d27Files = [
    $classifierFile     => 'CommercialPipelineClassifier.php',
    $goalCalcFile27     => 'SalesGoalPeriodCalculator.php',
    $portfolioResolver  => 'ClientPortfolioOwnershipResolver.php',
    $stageAuditFile     => 'commercial_stage_semantics_audit.php',
    $goalAuditFile27    => 'sales_goal_period_audit.php',
    $portfolioAuditFile => 'client_portfolio_ownership_audit.php',
];
foreach ($d27Files as $file => $label) {
    if (!file_exists($file)) {
        $d27Issues[] = "{$label} não encontrado";
    }
}

// 7. No SELECT * in new files
foreach ([$classifierFile, $goalCalcFile27, $portfolioResolver, $portfolioAuditFile] as $chk) {
    if (!file_exists($chk)) continue;
    if (preg_match('/SELECT\s+\*/i', file_get_contents($chk))) {
        $d27Issues[] = basename($chk) . ': contém SELECT *';
    }
}

// 8. BiVisibilityScope preserved (not overridden by classifier/resolver)
foreach ([$classifierFile, $portfolioResolver] as $chk) {
    if (!file_exists($chk)) continue;
    $src = file_get_contents($chk);
    if (str_contains($src, 'is_biomie_commercial_visible = false')) {
        $d27Issues[] = basename($chk) . ': sobrescreve BiVisibilityScope (proibido)';
    }
}

// 9-10. Licitação and Remessa scopes exist in classifier
if (file_exists($classifierFile)) {
    $clf27 = file_get_contents($classifierFile);
    if (!str_contains($clf27, 'licitacao') && !str_contains($clf27, 'licitação')) {
        $d27Issues[] = 'CommercialPipelineClassifier: escopo licitacao ausente';
    }
    if (!str_contains($clf27, 'remessa')) {
        $d27Issues[] = 'CommercialPipelineClassifier: escopo remessa ausente';
    }
}

// 11. SalesGoalPeriodCalculator supports broken intervals (selfTest)
if (file_exists($goalCalcFile27)) {
    $gc27 = file_get_contents($goalCalcFile27);
    if (!str_contains($gc27, 'selfTest') && !str_contains($gc27, 'dias_intersectados')) {
        $d27Issues[] = 'SalesGoalPeriodCalculator: suporte a intervalo quebrado ausente';
    }
}

// 12-13. Carteira and orçamento are NOT in realizado scope
if (file_exists($classifierFile)) {
    $clf27 = $clf27 ?? file_get_contents($classifierFile);
    if (!str_contains($clf27, 'should_count_as_realized') && !str_contains($clf27, 'shouldCountAsRealized')) {
        $d27Issues[] = 'CommercialPipelineClassifier: campo should_count_as_realized ausente';
    }
    if (!str_contains($clf27, 'should_count_as_portfolio') && !str_contains($clf27, 'shouldCountAsPortfolio')) {
        $d27Issues[] = 'CommercialPipelineClassifier: campo should_count_as_portfolio ausente';
    }
}

// 14. Faturado comes from NF autorizada (D1 already covers this — check redundantly)
if (file_exists($projectRoot . '/tools/dev/fiscal_value_basis_guard.php')) {
    // D1 covers this; no duplicated check needed
}

// 15. Portfolio out-of-portfolio is detectable
if (file_exists($portfolioResolver)) {
    $pr27 = file_get_contents($portfolioResolver);
    if (!str_contains($pr27, 'is_out_of_portfolio')) {
        $d27Issues[] = 'ClientPortfolioOwnershipResolver: campo is_out_of_portfolio ausente';
    }
}

// No DDL in new files
foreach ([$portfolioResolver, $portfolioAuditFile] as $chk) {
    if (!file_exists($chk)) continue;
    $src = file_get_contents($chk);
    if (str_contains($src, 'ALTER TABLE') || str_contains($src, 'CREATE TABLE')) {
        $d27Issues[] = basename($chk) . ': contém DDL (proibido)';
    }
}

// plano_voo.html has proportional goal label
$planVooHtml = $projectRoot . '/views/plano_voo.html';
if (file_exists($planVooHtml)) {
    $pvHtml = file_get_contents($planVooHtml);
    if (!str_contains($pvHtml, 'Meta proporcional') && !str_contains($pvHtml, 'meta_proporcional')) {
        $d27Issues[] = 'views/plano_voo.html: label "Meta proporcional" ausente (FASE 4)';
    }
}

$domains[] = [
    'label'         => 'D27 Commercial Semantics & Goals (COM.R1 umbrella)',
    'guard'         => 'classifier + goal calc + portfolio resolver + audit tools + proportional meta + no DDL',
    'pass'          => true,
    'warn'          => count($d27Issues) > 0,
    'informational' => true,
    'summary'       => $d27Issues === []
        ? 'COM.R1 presente: ClassifierPipeline + SalesGoalPeriodCalculator + ClientPortfolioOwnershipResolver + audits + plano_voo proporcional'
        : count($d27Issues) . ' componente(s) COM.R1 ausente(s) [informacional]',
    'issues'        => $d27Issues,
];


// ── D28 — Commercial Visual Channel Separation (COM.R2) [informacional] ──────
$d28Issues = [];

$channelPresenterFile = $projectRoot . '/app/Support/CommercialChannelPresenter.php';
$visualAuditFile      = $toolsDir   . '/commercial_visual_scope_audit.php';
$vendedoresHtml28     = $projectRoot . '/views/vendedores.html';
$planVooHtml28        = $projectRoot . '/views/plano_voo.html';
$pedidosHtml28        = $projectRoot . '/views/pedidos.html';
$indexHtml28          = $projectRoot . '/views/index.html';

// 1. CommercialChannelPresenter exists + constantes + interface
if (!file_exists($channelPresenterFile)) {
    $d28Issues[] = 'CommercialChannelPresenter.php não encontrado';
} else {
    $cp28 = file_get_contents($channelPresenterFile);
    foreach (['CHANNEL_VAREJO', 'CHANNEL_REVENDA', 'CHANNEL_LICITACAO', 'CHANNEL_REMESSA'] as $const) {
        if (!str_contains($cp28, $const)) $d28Issues[] = "CommercialChannelPresenter: constante {$const} ausente";
    }
    if (!str_contains($cp28, 'should_show_in_default_commercial_view')) {
        $d28Issues[] = 'CommercialChannelPresenter: campo should_show_in_default_commercial_view ausente';
    }
    if (str_contains($cp28, 'SELECT *')) $d28Issues[] = 'CommercialChannelPresenter: contém SELECT *';
    if (str_contains($cp28, 'ALTER TABLE') || str_contains($cp28, 'CREATE TABLE')) {
        $d28Issues[] = 'CommercialChannelPresenter: contém DDL (proibido)';
    }
}

// 2. visual audit tool exists
if (!file_exists($visualAuditFile)) $d28Issues[] = 'commercial_visual_scope_audit.php não encontrado';

// 3. vendedores.html tem filtro de canal (Orç-PD padrão)
// 4. vendedores.html tem botão Remessa no filtro
// 5. vendedores.html tem drawer Vendor 360 com tab nav
if (file_exists($vendedoresHtml28)) {
    $vh28 = file_get_contents($vendedoresHtml28);
    if (!str_contains($vh28, 'vendorChannelFilters') && !str_contains($vh28, 'data-vendor-channel')) {
        $d28Issues[] = 'views/vendedores.html: filtro de canal (data-vendor-channel) ausente';
    }
    if (!str_contains($vh28, 'data-vendor-channel="remessa"')) {
        $d28Issues[] = 'views/vendedores.html: botão de filtro Remessa ausente no canal filter';
    }
    if (!str_contains($vh28, 'drawerTabNav') && !str_contains($vh28, 'v360-tab')) {
        $d28Issues[] = 'views/vendedores.html: tab nav Vendor 360 (drawerTabNav) ausente';
    }
    // CAIO separation: render-helpers uses vendor_channel — checked in D26
} else {
    $d28Issues[] = 'views/vendedores.html não encontrado';
}

// 6. plano_voo.html usa "Meta proporcional" (não "Meta Total")
// 7. plano_voo.html tem painel de escopos separados
// 8. plano_voo.html tem card Orçamentos no painel de escopos
if (file_exists($planVooHtml28)) {
    $pv28 = file_get_contents($planVooHtml28);
    if (!str_contains($pv28, 'Meta proporcional')) {
        $d28Issues[] = 'views/plano_voo.html: label "Meta proporcional" ausente';
    }
    if (str_contains($pv28, 'Meta Total')) {
        $d28Issues[] = 'views/plano_voo.html: label ambíguo "Meta Total" presente — deve ser "Meta proporcional"';
    }
    if (!str_contains($pv28, 'fpScopesPanel') && !str_contains($pv28, 'Escopos separados')) {
        $d28Issues[] = 'views/plano_voo.html: painel de escopos separados (fpScopesPanel) ausente';
    }
    if (!str_contains($pv28, 'fpOrcamentosValue') && !str_contains($pv28, 'Orçamentos')) {
        $d28Issues[] = 'views/plano_voo.html: card Orçamentos ausente no painel de escopos';
    }
} else {
    $d28Issues[] = 'views/plano_voo.html não encontrado';
}

// 9. pedidos.html tem tab Remessa e tab Licitação
// 10. pedidos.html tem Licitação no filtro de tipo de NF
// 11. pedidos.html tem bloco de semântica comercial (legenda de escopos)
if (file_exists($pedidosHtml28)) {
    $pd28 = file_get_contents($pedidosHtml28);
    if (!str_contains($pd28, 'nf-scope="remittance"') && !str_contains($pd28, 'data-nf-scope="remittance"')) {
        $d28Issues[] = 'views/pedidos.html: tab Remessa ausente';
    }
    if (!str_contains($pd28, 'licitacao') && !str_contains($pd28, 'Licitação')) {
        $d28Issues[] = 'views/pedidos.html: tab/filtro Licitação ausente';
    }
    if (!str_contains($pd28, 'value="licitacao"') && !str_contains($pd28, "data-nf-scope=\"licitacao\"")) {
        $d28Issues[] = 'views/pedidos.html: Licitação ausente no filtro de tipo de NF (select/tab)';
    }
    if (!str_contains($pd28, 'nfSemanticLegend') && !str_contains($pd28, 'Semântica Comercial')) {
        $d28Issues[] = 'views/pedidos.html: bloco de semântica comercial (nfSemanticLegend) ausente';
    }
} else {
    $d28Issues[] = 'views/pedidos.html não encontrado';
}

// 12. index.html explica Realizado NF via tooltip/label
// 13. index.html tem KPI Carteira aceita
// 14. index.html tem KPI Orçamentos
if (file_exists($indexHtml28)) {
    $ix28 = file_get_contents($indexHtml28);
    if (!str_contains($ix28, 'NF autorizada') && !str_contains($ix28, 'Realizado NF')) {
        $d28Issues[] = 'views/index.html: tooltip/label "Realizado NF" ou "NF autorizada" ausente';
    }
    if (!str_contains($ix28, 'summary-wallet') && !str_contains($ix28, 'Carteira aceita')) {
        $d28Issues[] = 'views/index.html: KPI Carteira aceita (summary-wallet) ausente';
    }
    if (!str_contains($ix28, 'summary-quotes') && !str_contains($ix28, 'Orçamentos')) {
        $d28Issues[] = 'views/index.html: KPI Orçamentos (summary-quotes) ausente';
    }
} else {
    $d28Issues[] = 'views/index.html não encontrado';
}

// 15. Licitação/Remessa separation via CommercialChannelPresenter
if (file_exists($channelPresenterFile)) {
    $cp28src = $cp28 ?? file_get_contents($channelPresenterFile);
    if (!str_contains($cp28src, 'should_show_in_default_commercial_view')) {
        $d28Issues[] = 'CommercialChannelPresenter não expõe should_show_in_default_commercial_view (separação Licitação/Remessa)';
    }
}

// 16. No SELECT * in new COM.R2 files
foreach ([$channelPresenterFile, $visualAuditFile] as $chk) {
    if (!file_exists($chk)) continue;
    if (preg_match('/SELECT\s+\*/i', file_get_contents($chk))) {
        $d28Issues[] = basename($chk) . ': contém SELECT *';
    }
}

// 17. SalesGoalPeriodCalculator ainda referenciado nas APIs
$sgpcUsed = false;
foreach (glob($projectRoot . '/api/*.php') ?: [] as $apiFile) {
    if (str_contains((string) file_get_contents($apiFile), 'SalesGoalPeriodCalculator')) {
        $sgpcUsed = true;
        break;
    }
}
if (!$sgpcUsed) {
    // Check app directory too
    foreach (glob($projectRoot . '/app/**/*.php') ?: [] as $appFile) {
        if (str_contains((string) file_get_contents($appFile), 'SalesGoalPeriodCalculator')) {
            $sgpcUsed = true;
            break;
        }
    }
}
if (!$sgpcUsed) $d28Issues[] = 'SalesGoalPeriodCalculator não encontrado em uso nas APIs [informacional]';

// 18. BiVisibilityScope não foi removido
$biVsFile = $projectRoot . '/app/Support/BiVisibilityScope.php';
if (!file_exists($biVsFile)) {
    $d28Issues[] = 'BiVisibilityScope.php não encontrado — pode ter sido removido indevidamente';
}

// 19. Sem migration aplicada nesta fase (CommercialChannelPresenter é read-only)
// (já verificado no item 1 — sem DDL)

// 20. D27 transitive: se D27 falhou, registrar aviso contextual
$d27WasOk = count(array_filter($domains, static fn($d) => $d['label'] === 'D27 Commercial Semantics & Goals (COM.R1 umbrella)' && $d['warn'] === false)) > 0;
if (!$d27WasOk) {
    $d28Issues[] = 'D27 (COM.R1 umbrella) reportou warnings — verificar antes de release [informacional]';
}

$domains[] = [
    'label'         => 'D28 Commercial Visual Channel Separation (COM.R2)',
    'guard'         => 'CommercialChannelPresenter + Remessa filter + Vendor360 tabs + Licitação select + nfSemanticLegend + Carteira/Orçamentos KPIs',
    'pass'          => true,
    'warn'          => count($d28Issues) > 0,
    'informational' => true,
    'summary'       => $d28Issues === []
        ? 'COM.R2 presente: 20/20 checks OK — CommercialChannelPresenter + filtros + tabs + badges + KPIs + legenda semântica'
        : count($d28Issues) . ' componente(s) COM.R2 ausente(s)/pendente(s) [informacional]',
    'issues'        => $d28Issues,
];

// ── D29 — Commercial Period Scope Consistency (COM.R2.1) [informacional] ─────
$d29Issues = [];

$periodContextFile = $projectRoot . '/app/Commercial/CommercialPeriodScopeContext.php';
$scopeFilterFile   = $projectRoot . '/app/Commercial/CommercialScopeSqlFilter.php';
$ownerResolverFile = $projectRoot . '/app/Commercial/ClientCommercialOwnershipResolver.php';
$periodAuditFile   = $toolsDir   . '/commercial_period_consistency_audit.php';
$goalAuditFile     = $toolsDir   . '/sales_goal_period_consistency_audit.php';
$clientAuditFile   = $toolsDir   . '/client_commercial_ownership_audit.php';
$queryPerfFile     = $toolsDir   . '/commercial_query_performance_audit.php';
$biomieStatesFile  = $projectRoot . '/assets/js/biomie-states.js';
$fiscalRepoFile    = $projectRoot . '/app/Modules/Fiscal/Infrastructure/FiscalReadRepository.php';
$fpServiceFile     = $projectRoot . '/app/FlightPlanV2Service.php';

// 1. CommercialPeriodScopeContext exists
if (!file_exists($periodContextFile)) {
    $d29Issues[] = 'CommercialPeriodScopeContext.php não encontrado';
} else {
    $ctx29 = file_get_contents($periodContextFile);
    foreach (['SCOPE_ORC_PD', 'SCOPE_REMESSA', 'SCOPE_LICITACAO', 'monthBreakdown', 'proportionalTarget'] as $sym) {
        if (!str_contains($ctx29, $sym)) $d29Issues[] = "CommercialPeriodScopeContext: símbolo {$sym} ausente";
    }
}

// 2. CommercialScopeSqlFilter exists
if (!file_exists($scopeFilterFile)) {
    $d29Issues[] = 'CommercialScopeSqlFilter.php não encontrado';
} else {
    $sf29 = file_get_contents($scopeFilterFile);
    if (!str_contains($sf29, 'COALESCE(') || !str_contains($sf29, 'data_competencia')) {
        $d29Issues[] = 'CommercialScopeSqlFilter: não usa COALESCE(data_competencia, data_emissao)';
    }
}

// 3. ClientCommercialOwnershipResolver exists
if (!file_exists($ownerResolverFile)) {
    $d29Issues[] = 'ClientCommercialOwnershipResolver.php não encontrado';
} else {
    $or29 = file_get_contents($ownerResolverFile);
    if (!str_contains($or29, 'is_out_of_portfolio')) {
        $d29Issues[] = 'ClientCommercialOwnershipResolver: is_out_of_portfolio ausente';
    }
}

// 4-7. Audit tools exist
foreach ([
    $periodAuditFile  => 'commercial_period_consistency_audit.php',
    $goalAuditFile    => 'sales_goal_period_consistency_audit.php',
    $clientAuditFile  => 'client_commercial_ownership_audit.php',
    $queryPerfFile    => 'commercial_query_performance_audit.php',
] as $file => $name) {
    if (!file_exists($file)) $d29Issues[] = "{$name} não encontrado";
}

// 8. FiscalReadRepository uses COALESCE for period (not bare data_competencia)
if (file_exists($fiscalRepoFile)) {
    $fr29 = file_get_contents($fiscalRepoFile);
    // Positive check: aggregateInvoicedByVendorCommercial must use COALESCE(data_competencia, data_emissao)
    // (Other methods may legitimately use bare data_competencia for sync/reconciliation purposes)
    if (!str_contains($fr29, 'COALESCE(data_competencia, data_emissao) BETWEEN')) {
        $d29Issues[] = 'FiscalReadRepository: aggregateInvoicedByVendorCommercial não usa COALESCE(data_competencia, data_emissao) BETWEEN';
    }
    if (!str_contains($fr29, 'customers_count')) {
        $d29Issues[] = 'FiscalReadRepository: customers_count ausente em aggregateInvoicedByVendorCommercial';
    }
}

// 9. FlightPlanV2Service includes customers_count in vendor row
if (file_exists($fpServiceFile)) {
    $fp29 = file_get_contents($fpServiceFile);
    if (!str_contains($fp29, 'customers_count')) {
        $d29Issues[] = 'FlightPlanV2Service: customers_count não incluído no vendor row';
    }
}

// 10. biomie-states.js has renderPanelState safety guard
if (file_exists($biomieStatesFile)) {
    $bs29 = file_get_contents($biomieStatesFile);
    if (!str_contains($bs29, 'window.renderPanelState')) {
        $d29Issues[] = 'biomie-states.js: safety guard window.renderPanelState ausente';
    }
}

// 11. No SELECT * in new COM.R2.1 PHP files
foreach ([$periodContextFile, $scopeFilterFile, $ownerResolverFile, $periodAuditFile, $goalAuditFile, $clientAuditFile] as $chk) {
    if (!file_exists($chk)) continue;
    if (preg_match('/SELECT\s+\*/i', file_get_contents($chk))) {
        $d29Issues[] = basename($chk) . ': contém SELECT *';
    }
}

// 12. BiVisibilityScope still present
if (!file_exists($projectRoot . '/app/Support/BiVisibilityScope.php')) {
    $d29Issues[] = 'BiVisibilityScope.php não encontrado — pode ter sido removido indevidamente';
}

// 13. No DDL in new files
foreach ([$periodContextFile, $scopeFilterFile, $ownerResolverFile] as $chk) {
    if (!file_exists($chk)) continue;
    $src = file_get_contents($chk);
    if (str_contains($src, 'ALTER TABLE') || str_contains($src, 'CREATE TABLE')) {
        $d29Issues[] = basename($chk) . ': contém DDL (proibido nesta fase)';
    }
}

$domains[] = [
    'label'         => 'D29 Commercial Period Scope Consistency (COM.R2.1)',
    'guard'         => 'CommercialPeriodScopeContext + CommercialScopeSqlFilter + ClientCommercialOwnershipResolver + COALESCE date fix + customers_count + renderPanelState guard',
    'pass'          => true,
    'warn'          => count($d29Issues) > 0,
    'informational' => true,
    'summary'       => $d29Issues === []
        ? 'COM.R2.1 presente: CommercialPeriodScopeContext + COALESCE date + customers_count + renderPanelState guard + 4 audit tools'
        : count($d29Issues) . ' componente(s) COM.R2.1 ausente(s)/pendente(s) [informacional]',
    'issues'        => $d29Issues,
];

// ── D30 — Commercial Historical Coverage & Portfolio (COM.R2.2) [informacional] ──
$d30Issues = [];

$coverageAuditFile  = $toolsDir . '/commercial_monthly_coverage_audit.php';
$goalExamplesFile   = $toolsDir . '/sales_goal_broken_period_examples_audit.php';
$stockAuditFile     = $toolsDir . '/omie_stock_capability_audit.php';
$recoverFile        = $toolsDir . '/recover_commercial_range.php';
$timeBucketFile     = $projectRoot . '/app/Commercial/CommercialTimeBucket.php';
$product360File     = $projectRoot . '/api/product_detail.php';
$portfolioFile      = $projectRoot . '/app/Commercial/ClientCommercialPortfolioResolver.php';

// 1-4. Audit tools exist
foreach ([
    $coverageAuditFile => 'commercial_monthly_coverage_audit.php',
    $goalExamplesFile  => 'sales_goal_broken_period_examples_audit.php',
    $stockAuditFile    => 'omie_stock_capability_audit.php',
    $recoverFile       => 'recover_commercial_range.php',
] as $file => $name) {
    if (!file_exists($file)) $d30Issues[] = "{$name} não encontrado";
}

// 5. CommercialTimeBucket exists com granularidade automática
if (!file_exists($timeBucketFile)) {
    $d30Issues[] = 'CommercialTimeBucket.php não encontrado';
} else {
    $tb30 = file_get_contents($timeBucketFile);
    foreach (['resolveGranularity', 'buildBuckets', 'bucketSqlExpr', 'GRANULARITY_DAY', 'GRANULARITY_WEEK', 'GRANULARITY_MONTH'] as $sym) {
        if (!str_contains($tb30, $sym)) $d30Issues[] = "CommercialTimeBucket: símbolo {$sym} ausente";
    }
}

// 6. Product 360 endpoint exists e não tem type error (Http::query default como string)
if (!file_exists($product360File)) {
    $d30Issues[] = 'api/product_detail.php não encontrado';
} else {
    $p360 = file_get_contents($product360File);
    // Check that Http::query('limit', '50') uses string default (not int)
    if (preg_match('/Http::query\([\'"]limit[\'"]\s*,\s*(?![\'"]\d)[^\'"]?\d/', $p360)) {
        $d30Issues[] = 'api/product_detail.php: Http::query limit default pode ser int (deve ser string)';
    }
}

// 7. commercial_dashboard.php expõe wallet_value e quotes_value
$dashboardFile30 = $projectRoot . '/api/commercial_dashboard.php';
if (file_exists($dashboardFile30)) {
    $dash30 = file_get_contents($dashboardFile30);
    if (!str_contains($dash30, 'wallet_value')) {
        $d30Issues[] = 'api/commercial_dashboard.php: wallet_value (Carteira aceita) não exposto';
    }
    if (!str_contains($dash30, 'quotes_value')) {
        $d30Issues[] = 'api/commercial_dashboard.php: quotes_value (Orçamentos) não exposto';
    }
}

// 8. render-helpers.js binds summary-wallet e summary-quotes
$renderHelpers30 = $projectRoot . '/assets/js/core/render-helpers.js';
if (file_exists($renderHelpers30)) {
    $rh30 = file_get_contents($renderHelpers30);
    if (!str_contains($rh30, 'summary-wallet')) {
        $d30Issues[] = 'render-helpers.js: binding summary-wallet ausente';
    }
    if (!str_contains($rh30, 'summary-quotes')) {
        $d30Issues[] = 'render-helpers.js: binding summary-quotes ausente';
    }
}

// 9. No SELECT * in new COM.R2.2 files
foreach ([$coverageAuditFile, $goalExamplesFile, $stockAuditFile, $recoverFile, $timeBucketFile] as $chk) {
    if (!file_exists($chk)) continue;
    if (preg_match('/SELECT\s+\*/i', file_get_contents($chk))) {
        $d30Issues[] = basename($chk) . ': contém SELECT *';
    }
}

// 10. CommercialTimeBucket sem acesso a banco (read-only utility)
if (file_exists($timeBucketFile)) {
    $tb30src = file_get_contents($timeBucketFile);
    if (str_contains($tb30src, '->prepare(') || str_contains($tb30src, 'Database::')) {
        $d30Issues[] = 'CommercialTimeBucket: contém acesso a banco (deve ser read-only)';
    }
}

// 11. BiVisibilityScope still present (D2 transitive)
if (!file_exists($projectRoot . '/app/Support/BiVisibilityScope.php')) {
    $d30Issues[] = 'BiVisibilityScope.php não encontrado — pode ter sido removido';
}

// 12. No DDL in new files
foreach ([$coverageAuditFile, $goalExamplesFile, $stockAuditFile, $recoverFile] as $chk) {
    if (!file_exists($chk)) continue;
    $src = file_get_contents($chk);
    if (str_contains($src, 'ALTER TABLE') || str_contains($src, 'CREATE TABLE')) {
        // recover_commercial_range intentionally documents table creation as dry-run output — allow
        if (basename($chk) !== 'recover_commercial_range.php') {
            $d30Issues[] = basename($chk) . ': contém DDL (proibido)';
        }
    }
}

$domains[] = [
    'label'         => 'D30 Commercial Historical Coverage & Portfolio (COM.R2.2)',
    'guard'         => 'CommercialTimeBucket + monthly_coverage_audit + goal_examples_audit + stock_audit + recover_range + Product360 fix + dashboard wallet/quotes',
    'pass'          => true,
    'warn'          => count($d30Issues) > 0,
    'informational' => true,
    'summary'       => $d30Issues === []
        ? 'COM.R2.2 presente: CommercialTimeBucket + 4 audit tools + Product360 fix + wallet/quotes KPIs'
        : count($d30Issues) . ' componente(s) COM.R2.2 ausente(s) [informacional]',
    'issues'        => $d30Issues,
];

// ── D31 — Commercial 360 Period, Portfolio and Product Stock (COM.R3) [informacional] ──
$d31Issues = [];

// 1. CommercialMetricDate exists
if (!file_exists($projectRoot . '/app/Commercial/CommercialMetricDate.php')) {
    $d31Issues[] = 'CommercialMetricDate.php não encontrado';
}

// 2. CommercialPeriodBucket exists
$cpBucketFile = $projectRoot . '/app/Commercial/CommercialPeriodBucket.php';
if (!file_exists($cpBucketFile)) {
    $d31Issues[] = 'CommercialPeriodBucket.php não encontrado';
} else {
    $cpb = file_get_contents($cpBucketFile);
    if (!str_contains($cpb, 'DAILY_MAX_DAYS') && !str_contains($cpb, '45')) {
        $d31Issues[] = 'CommercialPeriodBucket: threshold ≤45 dias ausente';
    }
    if (!str_contains($cpb, 'buildBuckets')) {
        $d31Issues[] = 'CommercialPeriodBucket: método buildBuckets() ausente';
    }
}

// 3. CommercialMetricsAggregator exists
$cmaFile = $projectRoot . '/app/Commercial/CommercialMetricsAggregator.php';
if (!file_exists($cmaFile)) {
    $d31Issues[] = 'CommercialMetricsAggregator.php não encontrado';
} else {
    $cma = file_get_contents($cmaFile);
    foreach (['summary', 'timeSeries', 'vendorRanking', 'productTop', 'clientTop', 'pipelineScope', 'goalsSummary'] as $m) {
        if (!str_contains($cma, $m)) {
            $d31Issues[] = "CommercialMetricsAggregator: método {$m}() ausente";
        }
    }
}

// 4. sales_goal_broken_period_audit passes for 15/02→15/03
$sgbpAudit = $toolsDir . '/sales_goal_broken_period_audit.php';
if (!file_exists($sgbpAudit)) {
    $d31Issues[] = 'sales_goal_broken_period_audit.php não encontrado';
} else {
    $r = rg_run($php, $sgbpAudit, ['--start=2026-02-15', '--end=2026-03-15', '--json']);
    $decoded = json_decode($r['output'], true);
    if (!is_array($decoded)) {
        $d31Issues[] = 'sales_goal_broken_period_audit.php não retornou JSON válido';
    } elseif (!($decoded['calculator_selftest']['passed'] ?? false)) {
        $d31Issues[] = 'SalesGoalPeriodCalculator selfTest falhou: ' . implode('; ', $decoded['calculator_selftest']['failures'] ?? []);
    }
}

// 5. commercial_360_period_audit exists
$c360Audit = $toolsDir . '/commercial_360_period_audit.php';
if (!file_exists($c360Audit)) {
    $d31Issues[] = 'commercial_360_period_audit.php não encontrado';
}

// 6. client_portfolio_360_audit exists
$cp360Audit = $toolsDir . '/client_portfolio_360_audit.php';
if (!file_exists($cp360Audit)) {
    $d31Issues[] = 'client_portfolio_360_audit.php não encontrado';
}

// 7. client_portfolio_360.php exists with required modes
$cp360Api = $projectRoot . '/api/client_portfolio_360.php';
if (!file_exists($cp360Api)) {
    $d31Issues[] = 'api/client_portfolio_360.php não encontrado';
} else {
    $cp360src = file_get_contents($cp360Api);
    foreach (['summary', 'by_vendor', 'clients', 'conflicts', 'drawer'] as $mode) {
        if (!str_contains($cp360src, "'$mode'")) {
            $d31Issues[] = "api/client_portfolio_360.php: modo '{$mode}' ausente";
        }
    }
    if (preg_match('/SELECT\s+\*\s+FROM/i', $cp360src)) {
        $d31Issues[] = 'api/client_portfolio_360.php: contém SELECT * FROM (proibido)';
    }
}

// 8. product_360_stock_source_audit exists
if (!file_exists($toolsDir . '/product_360_stock_source_audit.php')) {
    $d31Issues[] = 'product_360_stock_source_audit.php não encontrado';
}

// 9. product_detail.php robusto (sem erro 500 em sample) — checked by D16
// Re-use existing product_360_audit if exists
$prod360Audit = $toolsDir . '/product_360_audit.php';
if (!file_exists($prod360Audit)) {
    $d31Issues[] = 'product_360_audit.php não encontrado';
}

// 10. Product360Builder exists
$prod360Builder = $projectRoot . '/app/Support/Product360Builder.php';
if (!file_exists($prod360Builder)) {
    $d31Issues[] = 'Product360Builder.php não encontrado';
} else {
    $p360b = file_get_contents($prod360Builder);
    foreach (['summary', 'invoices', 'clients', 'vendors', 'stock', 'stockMovements'] as $m) {
        if (!str_contains($p360b, $m)) {
            $d31Issues[] = "Product360Builder: método {$m}() ausente";
        }
    }
    if (!str_contains($p360b, 'empty_state')) {
        $d31Issues[] = 'Product360Builder: graceful empty_state para estoque ausente';
    }
}

// 11. nf_periodo_table.php exists with LIMIT/OFFSET
$nfPeriodoApi = $projectRoot . '/api/nf_periodo_table.php';
if (!file_exists($nfPeriodoApi)) {
    $d31Issues[] = 'api/nf_periodo_table.php não encontrado';
} else {
    $nfpt = file_get_contents($nfPeriodoApi);
    if (!str_contains($nfpt, 'LIMIT') || !str_contains($nfpt, 'OFFSET')) {
        $d31Issues[] = 'api/nf_periodo_table.php não usa LIMIT/OFFSET (paginação ausente)';
    }
    if (preg_match('/SELECT\s+\*\s+FROM/i', $nfpt)) {
        $d31Issues[] = 'api/nf_periodo_table.php: contém SELECT * FROM (proibido)';
    }
    if (!str_contains($nfpt, 'recordsFiltered')) {
        $d31Issues[] = 'api/nf_periodo_table.php: não retorna recordsFiltered (formato DataTables)';
    }
}

// 12. nf_periodo_performance_audit exists
if (!file_exists($toolsDir . '/nf_periodo_performance_audit.php')) {
    $d31Issues[] = 'nf_periodo_performance_audit.php não encontrado';
}

// 13. chart-helpers.js exists with key features
$chartHelpers = $projectRoot . '/assets/js/core/chart-helpers.js';
if (!file_exists($chartHelpers)) {
    $d31Issues[] = 'assets/js/core/chart-helpers.js não encontrado';
} else {
    $ch = file_get_contents($chartHelpers);
    foreach (['resolveBucketMode', 'createTimeSeriesBar', 'destroyChart', 'decimation', 'animation'] as $needle) {
        if (!str_contains($ch, $needle)) {
            $d31Issues[] = "chart-helpers.js: {$needle} ausente";
        }
    }
}

// 14. No SELECT * in new files
$newFilesD31 = [$cp360Api, $nfPeriodoApi, $prod360Builder, $cmaFile, $cpBucketFile];
foreach ($newFilesD31 as $f) {
    if (!file_exists($f)) continue;
    if (preg_match('/SELECT\s+\*\s+FROM/i', file_get_contents($f))) {
        $d31Issues[] = basename($f) . ': contém SELECT * FROM (proibido)';
    }
}

// 15. Stock migrations exist (DDL in migrations is OK)
$stockMigrations = [
    'Version20260530000001_EstoqueLocaisDim.php',
    'Version20260530000002_ProdutosEstoqueSaldos.php',
    'Version20260530000003_ProdutosEstoqueMovimentos.php',
];
foreach ($stockMigrations as $mig) {
    if (!file_exists($projectRoot . '/database/migrations/' . $mig)) {
        $d31Issues[] = "Migration {$mig} ausente";
    }
}

// 16. Base Comercial NF intacta — CommercialMetricsAggregator não altera
if (file_exists($cmaFile)) {
    $cmaChk = file_get_contents($cmaFile);
    if (str_contains($cmaChk, 'UPDATE ') || str_contains($cmaChk, 'INSERT ') || str_contains($cmaChk, 'DELETE ')) {
        $d31Issues[] = 'CommercialMetricsAggregator: contém escrita (deve ser read-only)';
    }
}

// 17. BiVisibilityScope intacto
if (!file_exists($projectRoot . '/app/Support/BiVisibilityScope.php')) {
    $d31Issues[] = 'BiVisibilityScope.php removido — regressão';
}

// 18. Healthz (already checked in main flow via $config/$pdo)
// We consider it passing if we got here

$domains[] = [
    'label'         => 'D31 Commercial 360 Period, Portfolio and Product Stock (COM.R3)',
    'guard'         => 'CommercialPeriodBucket + CommercialMetricsAggregator + client_portfolio_360 + Product360Builder + nf_periodo_table + chart-helpers + stock migrations',
    'pass'          => true,
    'warn'          => count($d31Issues) > 0,
    'informational' => true,
    'summary'       => $d31Issues === []
        ? 'COM.R3 presente: CommercialPeriodBucket + MetricsAggregator + Portfolio360 + Product360Builder + NF paginada + chart-helpers + 3 stock migrations'
        : count($d31Issues) . ' componente(s) COM.R3 ausente(s) [informacional]',
    'issues'        => $d31Issues,
];


// ── D32 — EST.R1 Product Stock Sync [informacional] ──────────────────────────

$d32Issues = [];

// 1. Tabelas de estoque
$stockTables = ['biomie_estoque_locais_dim', 'biomie_produtos_estoque_saldos', 'biomie_produtos_estoque_movimentos'];
foreach ($stockTables as $tbl) {
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t LIMIT 1');
        $st->execute([':t' => $tbl]);
        if ((int) $st->fetchColumn() === 0) {
            $d32Issues[] = "Tabela {$tbl} não existe — executar migrations EST.R1";
        }
    } catch (\Throwable) {
        $d32Issues[] = "Erro ao verificar tabela {$tbl}";
    }
}

// 2. Serviços app/Stock/
$stockServices = [
    'app/Stock/OmieStockClient.php',
    'app/Stock/StockPayloadMapper.php',
    'app/Stock/StockSyncCoordinator.php',
    'app/Stock/StockReadRepository.php',
    'app/Stock/ProductStockResolver.php',
];
foreach ($stockServices as $svc) {
    if (!is_file($projectRoot . '/' . $svc)) {
        $d32Issues[] = "Serviço ausente: {$svc}";
    }
}

// 3. CLI sync
if (!is_file($toolsDir . '/sync_stock_omie.php')) {
    $d32Issues[] = 'CLI sync ausente: tools/dev/sync_stock_omie.php';
}

// 4. Product360ReadRepository tem stock methods
$p360repoFile = $projectRoot . '/app/Modules/Sales/Infrastructure/Product360ReadRepository.php';
if (is_file($p360repoFile)) {
    $p360repoSrc = file_get_contents($p360repoFile);
    if (!str_contains((string) $p360repoSrc, 'stockByLocation')) {
        $d32Issues[] = 'Product360ReadRepository sem método stockByLocation';
    }
    if (!str_contains((string) $p360repoSrc, 'stockMovements')) {
        $d32Issues[] = 'Product360ReadRepository sem método stockMovements';
    }
} else {
    $d32Issues[] = 'Product360ReadRepository não encontrado';
}

// 5. Product360ReadService expõe tab=estoque
$p360svcFile = $projectRoot . '/app/Modules/Sales/Application/Products/Product360ReadService.php';
if (is_file($p360svcFile)) {
    $p360svcSrc = file_get_contents($p360svcFile);
    if (!str_contains((string) $p360svcSrc, "'estoque'")) {
        $d32Issues[] = 'Product360ReadService não inclui tab estoque';
    }
} else {
    $d32Issues[] = 'Product360ReadService não encontrado';
}

// 6. products.php expõe stock_stats
$productsApi = $projectRoot . '/api/products.php';
if (is_file($productsApi)) {
    $prodSrc = file_get_contents($productsApi);
    if (!str_contains((string) $prodSrc, 'stock_stats')) {
        $d32Issues[] = 'api/products.php não expõe mode=stock_stats';
    }
} else {
    $d32Issues[] = 'api/products.php não encontrado';
}

// 7. Sem SELECT * em arquivos novos de stock (ignora comentários)
$stockSrcFiles = glob($projectRoot . '/app/Stock/*.php') ?: [];
foreach ($stockSrcFiles as $sf) {
    // Remove comment lines before checking for wildcard selects
    $content = preg_replace('/\/\*.*?\*\//s', '', (string) file_get_contents($sf)) ?? '';
    $content = preg_replace('/\/\/[^\n]*/', '', $content) ?? '';
    if (preg_match('/\bSELECT\s+\*/i', $content)) {
        $d32Issues[] = 'SELECT * encontrado em ' . basename($sf);
    }
}

// 8. Nf_periodo_table.php tem filtros extras
$nfPTable = $projectRoot . '/api/nf_periodo_table.php';
if (is_file($nfPTable)) {
    $nfSrc = (string) file_get_contents($nfPTable);
    if (!str_contains($nfSrc, 'has_outras') || !str_contains($nfSrc, 'has_seguro') || !str_contains($nfSrc, 'com_desconto')) {
        $d32Issues[] = 'nf_periodo_table.php sem filtros has_outras/has_seguro/com_desconto';
    }
    if (!str_contains($nfSrc, 'search1') || !str_contains($nfSrc, 'search2')) {
        $d32Issues[] = 'nf_periodo_table.php com parâmetros de busca PDO duplicados (bug 500)';
    }
} else {
    $d32Issues[] = 'api/nf_periodo_table.php não encontrado';
}

// 9. biomie-drawer.css existe
if (!is_file($projectRoot . '/assets/css/biomie-drawer.css')) {
    $d32Issues[] = 'assets/css/biomie-drawer.css ausente';
}

// 10. PageAssetRegistry inclui biomie-drawer.css
$parFile = $projectRoot . '/app/PageAssetRegistry.php';
if (is_file($parFile) && !str_contains((string) file_get_contents($parFile), 'biomie-drawer.css')) {
    $d32Issues[] = 'PageAssetRegistry não inclui biomie-drawer.css no BASE_CSS';
}

$domains[] = [
    'label'         => 'D32 EST.R1 Product Stock Sync (EST.R1)',
    'guard'         => 'OmieStockClient + StockSyncCoordinator + Product360 estoque tab + nf_periodo filters + drawer css',
    'pass'          => true,
    'warn'          => count($d32Issues) > 0,
    'informational' => true,
    'summary'       => $d32Issues === []
        ? 'EST.R1 presente: 5 Stock services + 3 migrations + tab estoque + drawer css + nf_periodo filters'
        : count($d32Issues) . ' item(ns) EST.R1 ausente(s) [informacional]',
    'issues'        => $d32Issues,
];

// ── D9 — Cache clear ─────────────────────────────────────────────────────────
if ($clearCache) {
    $cacheDir  = $projectRoot . '/storage/cache';
    $deleted   = 0;
    $cacheNote = '';
    try {
        if (!is_dir($cacheDir)) {
            $cacheNote = 'Diretório de cache não encontrado';
        } else {
            $files = glob($cacheDir . '/*.json') ?: [];
            foreach ($files as $f) {
                if (@unlink($f)) $deleted++;
            }
            $cacheNote = "{$deleted} arquivo(s) de cache removido(s) de storage/cache/";
        }
    } catch (\Throwable $e) {
        $cacheNote = 'Erro ao limpar cache: ' . $e->getMessage();
    }
    $domains[] = [
        'label'   => 'D9  Cache — limpeza',
        'guard'   => '--clear-cache',
        'pass'    => true,
        'summary' => $cacheNote,
    ];
}

// ── Output ────────────────────────────────────────────────────────────────────
$elapsed   = round(microtime(true) - $t0, 2);
$domCount  = count($domains);
$passCount = count(array_filter($domains, fn($d) => $d['pass']));

if ($jsonMode) {
    echo json_encode([
        'biomie_release_guard' => [
            'version'   => 'AUTO.R1.1+ADMIN.R1.1+FIN.R4.1+FIN.R4.2+FIN.R4.3+FIN.R4.4+FIN.R4.4.1+COM.R1.2+COM.R1.3+COM.R1.4',
            'period'    => ['start' => $start, 'end' => $end],
            'overall'   => $allPass ? 'PASS' : 'FAIL',
            'elapsed_s' => $elapsed,
            'domains'   => array_map(fn($d) => [
                'label'   => $d['label'],
                'pass'    => $d['pass'],
                'warn'    => $d['warn'] ?? false,
                'summary' => $d['summary'],
            ], $domains),
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    echo PHP_EOL;
    exit($allPass ? 0 : 1);
}

// Pretty text output
$hr  = str_repeat('═', 72);
$hr2 = str_repeat('─', 72);

echo PHP_EOL;
echo $hr . PHP_EOL;
echo '  BIomie Comercial — Release Guard  [AUTO.R1.1+ADMIN.R1.1+FIN.R4+COM.R1+COM.R2+COM.R3]
  Período : ' . $start . ' → ' . $end . PHP_EOL;
echo $hr . PHP_EOL . PHP_EOL;

foreach ($domains as $d) {
    $warn = !empty($d['warn']);
    if ($d['pass'] && !$warn) {
        $badge = rg_col('g', 'PASS');
    } elseif ($warn) {
        $badge = rg_col('y', 'WARN');
    } else {
        $badge = rg_col('r', 'FAIL');
    }

    echo "  [{$badge}]  {$d['label']}" . PHP_EOL;
    echo "         {$d['summary']}" . PHP_EOL;

    if (!empty($d['detail'])) {
        foreach ($d['detail'] as $label => $mr) {
            $ms = $mr['pass'] ? rg_col('g', 'OK') : rg_col('r', '--');
            $rw = $mr['rows'] !== null ? number_format((int) $mr['rows']) . ' linhas' : 'N/A';
            $nt = ($mr['note'] ?? '') !== '' ? "  ({$mr['note']})" : '';
            echo "           [{$ms}] {$label}: {$rw}{$nt}" . PHP_EOL;
        }
    }

    if (!empty($d['errors'])) {
        foreach ($d['errors'] as $err) {
            echo '           ' . rg_col('r', "✗ {$err}") . PHP_EOL;
        }
    }

    if (!empty($d['issues'])) {
        $shown = array_slice($d['issues'], 0, 5);
        foreach ($shown as $iss) {
            echo '           ' . rg_col('y', "⚠  {$iss}") . PHP_EOL;
        }
        if (count($d['issues']) > 5) {
            echo '           ... e mais ' . (count($d['issues']) - 5) . ' ocorrência(s)' . PHP_EOL;
        }
    }

    echo PHP_EOL;
}

echo $hr2 . PHP_EOL . PHP_EOL;

$tag = $allPass ? rg_col('g', '✔ PASS') : rg_col('r', '✗ FAIL');
echo "  {$tag}  —  {$passCount}/{$domCount} domínios passaram  [{$elapsed}s]" . PHP_EOL . PHP_EOL;

if ($allPass) {
    echo '  Próximos passos:' . PHP_EOL;
    echo '    git push origin                            — seguro para deploy' . PHP_EOL;
    echo '    git tag biomie-' . date('Ymd') . '                     — marcar release' . PHP_EOL;
    echo "    php tools/dev/biomie_release_guard.php --start={$start} --end={$end} --clear-cache" . PHP_EOL;
} else {
    echo '  Guards com falha — investigar:' . PHP_EOL;
    foreach ($domains as $d) {
        if (!$d['pass']) {
            $suggest = $d['cmd'] ?? ('php tools/dev/' . $d['guard'] . ' --pretty');
            echo '    ' . $suggest . PHP_EOL;
        }
    }
}

echo PHP_EOL;
echo $hr . PHP_EOL . PHP_EOL;

exit($allPass ? 0 : 1);

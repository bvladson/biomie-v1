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


// ── D9 — Cache clear [apenas com --clear-cache] ───────────────────────────────
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
            'version'   => 'AUTO.R1.1+ADMIN.R1.1+FIN.R4.1+FIN.R4.2',
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
echo '  BIomie Comercial — Release Guard  [AUTO.R1.1+ADMIN.R1.1+FIN.R4.1+FIN.R4.2]
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

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
$grepR    = rg_exec('grep -rn -F "SELECT *" ' . escapeshellarg($projectRoot . '/api/'));
$grepLines = $grepR['output'] !== '' ? explode("\n", rtrim($grepR['output'])) : [];
$selectStarIssues = array_values(array_filter($grepLines, function (string $line): bool {
    return $line !== '' && !preg_match('/COUNT\(\*\)|information_schema/i', $line);
}));
$d6warn    = count($selectStarIssues) > 0;
$domains[] = [
    'label'   => 'D6  Audit — SELECT * em APIs  [informacional]',
    'guard'   => 'grep -F "SELECT *" api/',
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
            'version'   => 'Q.11',
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
echo '  BIomie Comercial — Release Guard  [Q.11]' . PHP_EOL;
echo '  Período : ' . $start . ' → ' . $end . PHP_EOL;
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

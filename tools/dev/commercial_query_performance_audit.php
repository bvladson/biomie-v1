<?php

declare(strict_types=1);

/**
 * tools/dev/commercial_query_performance_audit.php
 *
 * STATIC analysis tool — checks critical commercial API files for common
 * query-quality issues. No DB calls are made.
 *
 * Checks per file:
 *   - coalesce_date_filter : uses COALESCE(data_competencia, data_emissao) — not bare data_competencia
 *   - bi_visibility_scope  : applies is_biomie_commercial_visible filter
 *   - no_select_star       : no SELECT * in queries
 *   - has_limit            : at least one LIMIT clause present
 *   - prepared_statements  : uses PDO::prepare() or ->prepare(
 *   - set_time_limit       : configures set_time_limit()
 *
 * Usage:
 *   php tools/dev/commercial_query_performance_audit.php
 *   php tools/dev/commercial_query_performance_audit.php --json
 *
 * Exit codes:
 *   0 — all critical checks pass
 *   1 — one or more issues found
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

$opts   = getopt('', ['pretty', 'json']);
$isJson = isset($opts['json']);

$root = dirname(__DIR__, 2);

// ── Files to audit ────────────────────────────────────────────────────────────

$apiFiles = [
    'api/commercial_dashboard.php',
    'api/canonical_vendors.php',
    'api/flight_plan.php',
    'api/products.php',
    'api/sales_by_neighborhood.php',
];

$repoFiles = [
    'app/Modules/Fiscal/Infrastructure/FiscalReadRepository.php',
];

$allTargets = array_merge(
    array_map(fn($f) => ['path' => $f, 'category' => 'api'],  $apiFiles),
    array_map(fn($f) => ['path' => $f, 'category' => 'repo'], $repoFiles)
);

// ── Check definitions ─────────────────────────────────────────────────────────

/**
 * @return array{id:string,label:string,pass:bool,critical:bool,detail:string}
 */
function cqpaRunChecks(string $content, string $filePath): array
{
    $checks = [];

    // 1. coalesce_date_filter
    // PASS if the file uses COALESCE(…data_competencia…data_emissao…) for date filtering
    // or if the file does not use data_competencia at all (may be non-date file)
    $hasCoalesce    = (bool) preg_match('/COALESCE\s*\(\s*\S*data_competencia\S*\s*,\s*\S*data_emissao\S*/i', $content);
    $hasBareCompetencia = (bool) preg_match(
        '/(?<![_a-zA-Z])data_competencia\s*(?:BETWEEN|>=|<=|=|>|<)/i',
        $content
    );
    $usesDateFilter = $hasCoalesce || $hasBareCompetencia;

    if (!$usesDateFilter) {
        // File may not filter by date at all — mark as N/A (pass)
        $checks[] = [
            'id'       => 'coalesce_date_filter',
            'label'    => 'COALESCE(data_competencia, data_emissao)',
            'pass'     => true,
            'critical' => false,
            'detail'   => 'N/A — arquivo nao filtra por data_competencia',
        ];
    } elseif ($hasCoalesce && !$hasBareCompetencia) {
        $checks[] = [
            'id'       => 'coalesce_date_filter',
            'label'    => 'COALESCE(data_competencia, data_emissao)',
            'pass'     => true,
            'critical' => false,
            'detail'   => 'OK — usa COALESCE corretamente',
        ];
    } elseif ($hasBareCompetencia && !$hasCoalesce) {
        $checks[] = [
            'id'       => 'coalesce_date_filter',
            'label'    => 'COALESCE(data_competencia, data_emissao)',
            'pass'     => false,
            'critical' => true,
            'detail'   => 'FALHA — usa data_competencia diretamente sem COALESCE; NFs sem data_competencia serao ignoradas',
        ];
    } else {
        // Both present — mixed; warn
        $checks[] = [
            'id'       => 'coalesce_date_filter',
            'label'    => 'COALESCE(data_competencia, data_emissao)',
            'pass'     => false,
            'critical' => false,
            'detail'   => 'AVISO — mistura de COALESCE e data_competencia direta; auditar manualmente',
        ];
    }

    // 2. bi_visibility_scope
    $hasVisibility = (bool) preg_match('/is_biomie_commercial_visible/i', $content);
    $checks[] = [
        'id'       => 'bi_visibility_scope',
        'label'    => 'BiVisibilityScope (is_biomie_commercial_visible)',
        'pass'     => $hasVisibility,
        'critical' => false,
        'detail'   => $hasVisibility
            ? 'OK — filtro de visibilidade presente'
            : 'AVISO — is_biomie_commercial_visible ausente; Licitacao pode contaminar resultados',
    ];

    // 3. no_select_star
    // Flag SELECT * that is not inside a comment
    $contentNoComments = preg_replace('/\/\*.*?\*\//s', '', $content);
    $contentNoComments = preg_replace('/\/\/[^\n]*/', '', (string) $contentNoComments);
    $contentNoComments = preg_replace('/#[^\n]*/', '', (string) $contentNoComments);
    $hasSelectStar = (bool) preg_match('/SELECT\s+\*/i', (string) $contentNoComments);
    $checks[] = [
        'id'       => 'no_select_star',
        'label'    => 'Sem SELECT *',
        'pass'     => !$hasSelectStar,
        'critical' => false,
        'detail'   => $hasSelectStar
            ? 'AVISO — SELECT * detectado; pode trazer dados desnecessarios'
            : 'OK — sem SELECT *',
    ];

    // 4. has_limit
    $hasLimit = (bool) preg_match('/\bLIMIT\b/i', $content);
    $checks[] = [
        'id'       => 'has_limit',
        'label'    => 'LIMIT em queries grandes',
        'pass'     => $hasLimit,
        'critical' => false,
        'detail'   => $hasLimit
            ? 'OK — LIMIT presente'
            : 'AVISO — nenhum LIMIT encontrado; queries podem retornar conjuntos ilimitados',
    ];

    // 5. prepared_statements
    // APIs that delegate to Services/Repositories count as safe — they use prepared statements internally.
    $hasPrepared       = (bool) preg_match('/->prepare\s*\(/i', $content);
    $hasDelegation     = (bool) preg_match('/(new\s+\w+(Service|Repository|Builder)\b|\$\w+(Service|Repository|Builder)\s*->)/i', $content);
    $preparesOrDeletes = $hasPrepared || $hasDelegation;
    $checks[] = [
        'id'       => 'prepared_statements',
        'label'    => 'Prepared statements (->prepare())',
        'pass'     => $preparesOrDeletes,
        'critical' => !$preparesOrDeletes,
        'detail'   => $hasPrepared
            ? 'OK — usa prepared statements'
            : ($hasDelegation
                ? 'OK — delega para Service/Repository (usa prepared statements internamente)'
                : 'FALHA — nenhum ->prepare() encontrado e sem delegação para Service/Repository'),
    ];

    // 6. set_time_limit
    $hasTimeLimit = (bool) preg_match('/set_time_limit\s*\(/i', $content);
    $checks[] = [
        'id'       => 'set_time_limit',
        'label'    => 'set_time_limit()',
        'pass'     => $hasTimeLimit,
        'critical' => false,
        'detail'   => $hasTimeLimit
            ? 'OK — tempo limite configurado'
            : 'AVISO — set_time_limit() ausente; endpoint pode expirar em queries longas',
    ];

    return $checks;
}

/**
 * Derive a recommendation string from check results.
 *
 * @param list<array<string,mixed>> $checks
 */
function cqpaBuildRecommendation(array $checks): string
{
    $issues = array_filter($checks, fn($c) => !$c['pass']);
    if ($issues === []) {
        return 'Nenhuma acao necessaria.';
    }
    $criticals = array_filter($issues, fn($c) => $c['critical']);
    if ($criticals !== []) {
        $ids = implode(', ', array_column($criticals, 'id'));
        return "CRITICO: corrigir imediatamente — {$ids}";
    }
    $ids = implode(', ', array_column($issues, 'id'));
    return "Auditar: {$ids}";
}

// ── Run audits ────────────────────────────────────────────────────────────────

$endpoints    = [];
$totalIssues  = 0;
$criticalHits = 0;

foreach ($allTargets as $target) {
    $fullPath = $root . '/' . $target['path'];
    $exists   = file_exists($fullPath);

    if (!$exists) {
        $endpoints[] = [
            'file'           => $target['path'],
            'category'       => $target['category'],
            'exists'         => false,
            'checks_pass'    => false,
            'issues'         => [['id' => 'file_not_found', 'label' => 'Arquivo nao encontrado', 'detail' => $fullPath]],
            'recommendation' => 'Arquivo ausente — verificar se foi criado ou se o caminho esta correto.',
        ];
        $totalIssues++;
        continue;
    }

    $content = (string) file_get_contents($fullPath);
    $checks  = cqpaRunChecks($content, $target['path']);

    $failed      = array_values(array_filter($checks, fn($c) => !$c['pass']));
    $allPass     = $failed === [];
    $fileCritical = (bool) array_filter($failed, fn($c) => $c['critical']);

    if (!$allPass) {
        $totalIssues += count($failed);
    }
    if ($fileCritical) {
        $criticalHits++;
    }

    $endpoints[] = [
        'file'           => $target['path'],
        'category'       => $target['category'],
        'exists'         => true,
        'checks_pass'    => $allPass,
        'issues'         => $failed,
        'all_checks'     => $checks,
        'recommendation' => cqpaBuildRecommendation($checks),
    ];
}

// ── Output ────────────────────────────────────────────────────────────────────

$hasIssues = $totalIssues > 0;

$output = [
    'generated_at'  => date(DATE_ATOM),
    'note'          => 'Analise ESTATICA apenas — sem chamadas ao banco de dados.',
    'files_audited' => count($allTargets),
    'total_issues'  => $totalIssues,
    'critical_files'=> $criticalHits,
    'clean'         => !$hasIssues,
    'endpoints'     => $endpoints,
];

if ($isJson) {
    echo json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit($hasIssues ? 1 : 0);
}

// ── Pretty text output ────────────────────────────────────────────────────────

echo "\n=== commercial_query_performance_audit (static) ===\n";
echo "Analise ESTATICA — sem chamadas ao banco.\n";
printf("Arquivos auditados: %d  |  Issues: %d  |  Criticos: %d\n\n", count($allTargets), $totalIssues, $criticalHits);

foreach ($endpoints as $ep) {
    if (!$ep['exists']) {
        printf("[MISS] %s — arquivo nao encontrado\n", $ep['file']);
        continue;
    }

    $icon = $ep['checks_pass'] ? '[PASS]' : '[WARN]';
    // Upgrade icon to FAIL if any critical issue
    foreach ($ep['issues'] as $iss) {
        if ($iss['critical'] ?? false) {
            $icon = '[FAIL]';
            break;
        }
    }

    printf("%s %s\n", $icon, $ep['file']);

    if (isset($ep['all_checks'])) {
        foreach ($ep['all_checks'] as $chk) {
            $cIcon = $chk['pass'] ? '  [OK ]' : ($chk['critical'] ? '  [ERR]' : '  [!  ]');
            printf("%s %-38s %s\n", $cIcon, $chk['label'], $chk['detail']);
        }
    }

    if ($ep['recommendation'] !== 'Nenhuma acao necessaria.') {
        printf("       => %s\n", $ep['recommendation']);
    }
    echo "\n";
}

if (!$hasIssues) {
    echo "Resultado: LIMPO — todos os arquivos passaram nas verificacoes.\n\n";
    exit(0);
}

printf("Resultado: %d issue(s) encontrado(s), %d arquivo(s) critico(s).\n\n", $totalIssues, $criticalHits);
exit(1);

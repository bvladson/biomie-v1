<?php

declare(strict_types=1);

/**
 * COM.R2 FASE 0 — Diagnóstico Visual de Escopos Comerciais por Página
 *
 * Audita estáticamente HTML/JS para identificar:
 *  - páginas com/sem filtro de canal
 *  - páginas com/sem badges de canal
 *  - separação ou mistura de Licitação/Remessa no padrão comercial
 *  - textos ambíguos vs textos semânticos
 *  - riscos visuais de confusão entre escopos
 *
 * Read-only. Sem escrita. Sem acesso ao banco.
 *
 * Uso:
 *   php tools/dev/commercial_visual_scope_audit.php [--pretty] [--json]
 *       [--page=vendedores|plano_voo|pedidos|nf_periodo|index|all]
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

$opts    = getopt('', ['pretty', 'json', 'page:']);
$pretty  = isset($opts['pretty']) || !isset($opts['json']);
$json    = isset($opts['json']);
$pageFilter = strtolower(trim((string) ($opts['page'] ?? 'all')));

$root = dirname(__DIR__, 2);

// ── Helpers ───────────────────────────────────────────────────────────────────

function cvsaRead(string $path): string {
    return is_file($path) ? (string) file_get_contents($path) : '';
}

function cvsaContains(string $content, string $needle): bool {
    return $needle !== '' && str_contains($content, $needle);
}

function cvsaAnyContains(string $content, array $needles): bool {
    foreach ($needles as $n) { if (cvsaContains($content, $n)) return true; }
    return false;
}

/**
 * @param array<array{check:string,found:bool,risk:string,rec:string}> $checks
 * @return array{score:int,max:int,gaps:list<string>,risks:list<string>}
 */
function cvsaAnalyze(array $checks): array {
    $score  = 0; $max = 0;
    $gaps   = []; $risks = [];
    foreach ($checks as $c) {
        $max++;
        if ($c['found']) { $score++; } else {
            $gaps[] = $c['check'];
            if ($c['risk'] !== 'low') $risks[] = '[' . strtoupper($c['risk']) . '] ' . $c['rec'];
        }
    }
    return ['score' => $score, 'max' => $max, 'gaps' => $gaps, 'risks' => $risks];
}

// ── Page definitions ──────────────────────────────────────────────────────────

$pages = [];

// ── vendedores ────────────────────────────────────────────────────────────────
if (in_array($pageFilter, ['vendedores', 'all'], true)) {
    $html = cvsaRead("{$root}/views/vendedores.html");
    $js1  = cvsaRead("{$root}/assets/js/modules/vendors/page-drilldown.js");
    $api  = cvsaRead("{$root}/api/canonical_vendors.php");

    $pages['vendedores'] = [
        'file'   => 'views/vendedores.html',
        'checks' => cvsaAnalyze([
            ['check' => 'Filtro de canal (varejo/revenda/licitacao) na UI', 'found' => cvsaAnyContains($html, ['channel-filter', 'data-channel', 'filtro-canal', 'varejo', 'revenda']), 'risk' => 'high', 'rec' => 'Adicionar chips de canal acima da tabela de vendedores'],
            ['check' => 'Badge de canal na tabela', 'found' => cvsaAnyContains($html, ['channel_badge', 'channel-badge', 'vendor_channel', 'REV', 'VAR']), 'risk' => 'high', 'rec' => 'Exibir badge REV/VAR/REP/LIC em cada linha de vendedor'],
            ['check' => 'CAIO - REV e CAIO - VAR separados (render-helpers)', 'found' => cvsaAnyContains(cvsaRead("{$root}/assets/js/core/render-helpers.js"), ['vendor_channel', 'channel_badge']), 'risk' => 'high', 'rec' => 'render-helpers.js deve usar vendor_channel para badge visual'],
            ['check' => 'Meta proporcional (não "Meta Total")', 'found' => cvsaContains($html, 'Meta proporcional'), 'risk' => 'medium', 'rec' => 'Substituir "Meta Total" por "Meta proporcional" nos cards'],
            ['check' => 'API suporta filtro de canal', 'found' => cvsaContains($api, 'channel'), 'risk' => 'low', 'rec' => 'API já suporta; integrar na UI'],
            ['check' => 'Licitação não aparece por padrão no Orç-PD', 'found' => cvsaAnyContains($html, ['licitação excluída', 'remessas excluídas', 'Remessas excluídas']), 'risk' => 'medium', 'rec' => 'Indicar que Licitação/Remessa estão fora da visão padrão'],
            ['check' => 'Toggle inativos presente', 'found' => cvsaContains($html, 'Incluir inativos'), 'risk' => 'low', 'rec' => 'Já implementado — OK'],
            ['check' => 'Conflito de carteira exibido (fora da carteira badge)', 'found' => cvsaContains($html, 'kpiConflictCount'), 'risk' => 'low', 'rec' => 'Já implementado — OK'],
        ]),
        'meta_label' => cvsaContains($html, 'Meta proporcional') ? 'Meta proporcional ✓' : 'Meta Total (ambíguo)',
        'channel_filter' => cvsaAnyContains($html, ['varejo', 'revenda', 'channel']) ? 'parcial' : 'ausente',
    ];
}

// ── plano_voo ─────────────────────────────────────────────────────────────────
if (in_array($pageFilter, ['plano_voo', 'all'], true)) {
    $html = cvsaRead("{$root}/views/plano_voo.html");
    $tbl  = cvsaRead("{$root}/assets/js/modules/flight-plan/vendor-table.js");
    $kpi  = cvsaRead("{$root}/assets/js/modules/flight-plan/kpis.js");

    $pages['plano_voo'] = [
        'file'   => 'views/plano_voo.html',
        'checks' => cvsaAnalyze([
            ['check' => 'Label "Meta proporcional" no card KPI', 'found' => cvsaContains($html, 'Meta proporcional'), 'risk' => 'medium', 'rec' => 'Trocar "Meta Total" por "Meta proporcional do período"'],
            ['check' => 'Tooltip proporcional no card', 'found' => cvsaAnyContains($html, ['Proporcional ao período', 'Meta calculada proporcional']), 'risk' => 'medium', 'rec' => 'Adicionar tooltip explicando fórmula proporcional'],
            ['check' => 'Realizado etiquetado como "por NF" ou "NF autorizada"', 'found' => cvsaAnyContains($html, ['NF autorizada', 'Realizado por NF', 'Realizado Oficial']), 'risk' => 'medium', 'rec' => 'Esclarecer que realizado = NF autorizada'],
            ['check' => 'Carteira separada do realizado nos cards', 'found' => cvsaAnyContains($html, ['fp-pedidos-carteira', 'Carteira']), 'risk' => 'low', 'rec' => 'Já presente — OK'],
            ['check' => 'Badge de canal na tabela de vendedores do plano', 'found' => cvsaAnyContains($tbl, ['vendor_channel', 'channel_badge', 'REV', 'VAR']), 'risk' => 'high', 'rec' => 'Adicionar badge de canal na coluna Vendedor da tabela do plano de voo'],
            ['check' => 'Filtro de canal no plano de voo', 'found' => cvsaAnyContains($html, ['channel-filter', 'data-channel', 'fpChannelFilter']), 'risk' => 'medium', 'rec' => 'Adicionar filtro de canal no painel de filtros do plano de voo'],
            ['check' => 'Licitação separada nos KPIs', 'found' => cvsaAnyContains($html, ['licitação', 'Licitação', 'licitacao']), 'risk' => 'medium', 'rec' => 'Adicionar card compacto de escopos separados (Licitação, Remessa)'],
            ['check' => 'Orçamento separado nos KPIs', 'found' => cvsaAnyContains($html, ['Orçamento', 'orcamento', 'fp-orcamento']), 'risk' => 'medium', 'rec' => 'Separar Orçamentos em card próprio'],
        ]),
        'meta_label' => cvsaContains($html, 'Meta proporcional') ? 'Meta proporcional ✓' : 'Meta Total (ambíguo)',
        'channel_filter' => 'ausente',
    ];
}

// ── pedidos ───────────────────────────────────────────────────────────────────
if (in_array($pageFilter, ['pedidos', 'all'], true)) {
    $html = cvsaRead("{$root}/views/pedidos.html");

    $pages['pedidos'] = [
        'file'   => 'views/pedidos.html',
        'checks' => cvsaAnalyze([
            ['check' => 'Tab Remessa presente', 'found' => cvsaAnyContains($html, ['nf-scope="remittance"', 'data-nf-scope="remittance"']), 'risk' => 'low', 'rec' => 'Já implementado — OK'],
            ['check' => 'Tab Licitação presente', 'found' => cvsaAnyContains($html, ['licitacao', 'licitação', 'nf-scope="licitacao"']), 'risk' => 'high', 'rec' => 'Adicionar tab Licitação nas abas de escopo'],
            ['check' => 'Badge de canal na tabela de NFs', 'found' => cvsaAnyContains($html, ['vendor_channel', 'channel-badge', 'scope-badge', 'REV', 'VAR']), 'risk' => 'medium', 'rec' => 'Adicionar badge de canal do vendedor em cada linha de NF'],
            ['check' => 'Aviso "Visão combinada" no tab Todas', 'found' => cvsaAnyContains($html, ['Visão combinada', 'combinada', 'todas as NFs']), 'risk' => 'low', 'rec' => 'Adicionar tooltip/aviso no tab Todas'],
            ['check' => 'Coluna Canal ou tipo de NF', 'found' => cvsaAnyContains($html, ['Canal', 'Tipo', 'scope-pill', 'NF_SCOPE_PILL']), 'risk' => 'medium', 'rec' => 'Coluna Tipo já existe — garantir que mostre canal além de escopo'],
            ['check' => 'Remessa visualmente separada do Orç-PD', 'found' => cvsaAnyContains($html, ['remittance', 'Remessa']), 'risk' => 'low', 'rec' => 'Já separado por tab — OK'],
        ]),
        'channel_filter' => cvsaAnyContains($html, ['nf-scope', 'scope']) ? 'parcial (Remessa)' : 'ausente',
    ];
}

// ── index ─────────────────────────────────────────────────────────────────────
if (in_array($pageFilter, ['index', 'all'], true)) {
    $html = cvsaRead("{$root}/views/index.html");

    $pages['index'] = [
        'file'   => 'views/index.html',
        'checks' => cvsaAnalyze([
            ['check' => 'Tooltip "Realizado = NF autorizada" presente', 'found' => cvsaAnyContains($html, ['NF autorizada', 'notas fiscais autorizadas', 'Base Comercial NF']), 'risk' => 'medium', 'rec' => 'Adicionar tooltip no card de Realizado explicando a fonte'],
            ['check' => 'Carteira separada do realizado no dashboard', 'found' => cvsaAnyContains($html, ['Em aberto', 'Carteira', 'pending']), 'risk' => 'low', 'rec' => 'Já separado — OK'],
            ['check' => 'Card separado para Orçamento', 'found' => cvsaAnyContains($html, ['Orçamento', 'orcamento', 'quote']), 'risk' => 'medium', 'rec' => 'Adicionar card de Orçamentos separado de Carteira'],
            ['check' => 'Remessa card/aviso presente', 'found' => cvsaAnyContains($html, ['Remessa', 'remittance']), 'risk' => 'low', 'rec' => 'Card de Remessa já existe — OK'],
            ['check' => 'Entrega finalizada NÃO entra no realizado (tooltip)', 'found' => cvsaAnyContains($html, ['Entrega', 'entrega finalizada', 'entregue']), 'risk' => 'low', 'rec' => 'Sem menção — risco baixo pois etapa 60 não entra no realizado'],
            ['check' => 'Filtro de canal disponível', 'found' => cvsaAnyContains($html, ['channel', 'Canal', 'varejo', 'revenda']), 'risk' => 'medium', 'rec' => 'Adicionar filtro de canal nos extras do dashboard'],
        ]),
        'channel_filter' => 'ausente',
    ];
}

// ── Sumário ───────────────────────────────────────────────────────────────────
$allGaps  = [];
$allRisks = [];
$criticalCount = 0;
foreach ($pages as $pg => $info) {
    foreach ($info['checks']['gaps'] as $g) $allGaps[] = "{$pg}: {$g}";
    foreach ($info['checks']['risks'] as $r) {
        $allRisks[] = "{$pg}: {$r}";
        if (str_starts_with($r, '[HIGH]')) $criticalCount++;
    }
}

$report = [
    'commercial_visual_scope_audit' => [
        'version'         => 'COM.R2 FASE 0',
        'generated_at'    => date('Y-m-d H:i:s'),
        'page_filter'     => $pageFilter,
        'pages'           => $pages,
        'summary'         => [
            'total_gaps'       => count($allGaps),
            'critical_gaps'    => $criticalCount,
            'all_gaps'         => $allGaps,
            'all_risks'        => $allRisks,
        ],
    ],
];

if ($json) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

// ── Pretty output ─────────────────────────────────────────────────────────────
$hr  = str_repeat('═', 72);
$hr2 = str_repeat('─', 72);
$ok  = "\033[32m✓\033[0m";
$nok = "\033[31m✗\033[0m";
$wrn = "\033[33m⚠\033[0m";

echo PHP_EOL . $hr . PHP_EOL;
echo '  COM.R2 FASE 0 — Diagnóstico Visual de Escopos Comerciais' . PHP_EOL;
echo $hr . PHP_EOL . PHP_EOL;

foreach ($report['commercial_visual_scope_audit']['pages'] as $pg => $info) {
    $c = $info['checks'];
    echo $hr2 . PHP_EOL . "  Página: {$pg}  [{$c['score']}/{$c['max']}]" . PHP_EOL . $hr2 . PHP_EOL;
    // re-run checks for display
    foreach (array_keys(array_fill(0, $c['max'], null)) as $i) {
        // can't iterate original checks here directly; use gaps
    }
    echo "  Meta label    : " . ($info['meta_label'] ?? '—') . PHP_EOL;
    echo "  Canal filter  : " . ($info['channel_filter'] ?? '—') . PHP_EOL;
    if ($c['gaps']) {
        foreach ($c['gaps'] as $g) echo "  {$nok} {$g}" . PHP_EOL;
    } else {
        echo "  {$ok} Todos os checks passaram." . PHP_EOL;
    }
    if ($c['risks']) {
        echo PHP_EOL;
        foreach ($c['risks'] as $r) echo "  {$wrn} {$r}" . PHP_EOL;
    }
    echo PHP_EOL;
}

$s = $report['commercial_visual_scope_audit']['summary'];
echo $hr2 . PHP_EOL . '  Resumo' . PHP_EOL . $hr2 . PHP_EOL . PHP_EOL;
echo "  Total de gaps    : {$s['total_gaps']}" . PHP_EOL;
echo "  Gaps críticos    : {$s['critical_gaps']}" . PHP_EOL;
echo PHP_EOL . $hr . PHP_EOL . PHP_EOL;

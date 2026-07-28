<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';

use BIomie\Support\CliGuard;

CliGuard::requireCli();

$root = dirname(__DIR__, 2);
$json = in_array('--json', $argv ?? [], true);

$jsPath = $root . '/assets/js/commercial-card-help.js';
$loaderPath = $root . '/assets/js/commercial-card-help-loader.js';
$cssPath = $root . '/assets/css/commercial-card-help.css';
$registryPath = $root . '/app/PageAssetRegistry.php';
$languageJsPath = $root . '/assets/js/commercial-language.js';
$languageCssPath = $root . '/assets/css/commercial-language.css';

$js = readAuditFile($jsPath);
$loader = readAuditFile($loaderPath);
$css = readAuditFile($cssPath);
$registry = readAuditFile($registryPath);
$languageJs = readAuditFile($languageJsPath);
$languageCss = readAuditFile($languageCssPath);

$checks = [
    auditCheck('js_exists', $js !== '', 'Componente global de ajuda existe.'),
    auditCheck('css_exists', $css !== '', 'Estilos globais de ajuda existem.'),
    auditCheck('js_registered', str_contains($registry, 'assets/js/commercial-card-help-loader.js'), 'Loader da ajuda esta registrado no PageAssetRegistry.'),
    auditCheck('lazy_loader', str_contains($loader, 'requestIdleCallback') && str_contains($loader, 'BIomieLoadCardHelp'), 'Catalogo completo carrega no primeiro uso ou em tempo ocioso.'),
    auditCheck('css_registered', str_contains($registry, 'assets/css/commercial-card-help.css'), 'CSS esta registrado no PageAssetRegistry.'),
    auditCheck('kpi_coverage', str_contains($js, ".biomie-kpi-card"), 'Cards KPI fazem parte da cobertura.'),
    auditCheck('panel_coverage', str_contains($js, "'.chart-card'") && str_contains($js, "'.table-card'") && str_contains($js, "'.fin-panel'"), 'Graficos, tabelas e paineis fazem parte da cobertura.'),
    auditCheck('extended_card_coverage', str_contains($js, "'.mag-card'") && str_contains($js, "'.omie-card'"), 'Cards compartilhados e de integracao fazem parte da cobertura.'),
    auditCheck('dynamic_coverage', str_contains($js, 'new MutationObserver'), 'Cards dinamicos recebem ajuda automaticamente.'),
    auditCheck('contextual_fallback', str_contains($js, 'genericDefinition'), 'Existe explicacao contextual para cards sem definicao especifica.'),
    auditCheck('topic_catalog', str_contains($js, 'topicCatalog'), 'Graficos e tabelas possuem explicacoes por assunto.'),
    auditCheck('keyboard_escape', str_contains($js, "event.key === 'Escape'"), 'Ajuda fecha por teclado com Escape.'),
    auditCheck('aria_expanded', str_contains($js, "aria-expanded"), 'Gatilhos comunicam estado aberto para leitores de tela.'),
    auditCheck('aria_describedby', str_contains($js, "aria-describedby"), 'Popover e gatilho possuem relacionamento acessivel.'),
    auditCheck('touch_click', str_contains($js, "button.addEventListener('click'"), 'Ajuda pode ser fixada por clique ou toque.'),
    auditCheck('outside_close', str_contains($js, "document.addEventListener('click'"), 'Ajuda fixada fecha por clique externo.'),
    auditCheck('deep_dive_action', str_contains($js, 'Ver leitura comercial'), 'Popover oferece leitura comercial completa sob demanda.'),
    auditCheck('deep_dive_drawer', str_contains($js, 'business-help-drawer') && str_contains($css, '.business-help-drawer'), 'Leitura completa usa drawer global responsivo.'),
    auditCheck('knowledge_catalog', str_contains($js, 'knowledgeCatalog') && str_contains($js, 'knowledgeTopics'), 'Existe catalogo aprofundado por indicador e assunto.'),
    auditCheck('current_value', str_contains($js, 'Valor exibido agora') && str_contains($js, 'snapshotFor'), 'Drawer apresenta o valor atual do card.'),
    auditCheck('calculation_rules', str_contains($js, 'De onde vem o resultado') && str_contains($js, 'formula:'), 'Drawer explica a formacao dos resultados.'),
    auditCheck('active_filters', str_contains($js, 'activeFilters') && str_contains($js, 'Filtros ativos'), 'Drawer informa filtros ativos da pagina.'),
    auditCheck('data_context', str_contains($js, 'sync-coverage') && str_contains($js, 'sync-reconciled-until'), 'Drawer informa origem, cobertura e conferencia disponiveis.'),
    auditCheck('decision_questions', str_contains($js, 'Perguntas para agir'), 'Drawer transforma leitura em perguntas de acao.'),
    auditCheck('copy_explanation', str_contains($js, 'Copiar leitura') && str_contains($js, 'copyDeepDive'), 'Leitura comercial completa pode ser copiada.'),
    auditCheck('drawer_focus_return', str_contains($js, 'drawerTrigger') && str_contains($js, 'trigger.focus()'), 'Foco retorna ao gatilho ao fechar o drawer.'),
    auditCheck('reduced_motion', str_contains($css, 'prefers-reduced-motion'), 'Animacoes respeitam movimento reduzido.'),
    auditCheck('mobile_layout', str_contains($css, '@media (max-width: 640px)'), 'Componente possui ajuste para celular.'),
    auditCheck('semantic_colors', substr_count($css, 'data-business-category=') >= 7, 'Popover usa as sete cores comerciais.'),
    auditCheck('real_help_button', str_contains($js, "button.type = 'button'"), 'Ajuda usa botao real e focavel.'),
    auditCheck('no_legacy_native_title', !str_contains($languageJs, "card.setAttribute('title', helpText)"), 'Title nativo antigo nao e mais a ajuda principal.'),
    auditCheck('no_legacy_fake_button', !str_contains($languageCss, '.business-card__label--help::after'), 'Indicador visual antigo sem interacao foi removido.'),
];

$viewFiles = glob($root . '/views/*.{html,php}', GLOB_BRACE) ?: [];
$staticTargets = 0;
foreach ($viewFiles as $file) {
    $content = readAuditFile($file);
    $staticTargets += substr_count($content, 'biomie-kpi-card');
    $staticTargets += substr_count($content, 'chart-card');
    $staticTargets += substr_count($content, 'table-card');
    $staticTargets += substr_count($content, 'fin-panel');
    $staticTargets += substr_count($content, 'dashboard-panel');
}

$summary = [
    'ok' => !in_array(false, array_column($checks, 'ok'), true),
    'checks_passed' => count(array_filter($checks, static fn (array $check): bool => $check['ok'])),
    'checks_total' => count($checks),
    'specific_explanations' => substr_count($js, 'summary:'),
    'deep_explanations' => substr_count($js, 'formula:'),
    'topic_explanations' => substr_count($js, 'pattern: /'),
    'static_card_references_scanned' => $staticTargets,
    'coverage_strategy' => 'specific + topic + contextual fallback',
];

$result = ['summary' => $summary, 'checks' => $checks];

if ($json) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo "Commercial Card Help Audit\n";
    echo str_repeat('-', 72) . "\n";
    foreach ($checks as $check) {
        printf("[%s] %-28s %s\n", $check['ok'] ? 'PASS' : 'FAIL', $check['id'], $check['message']);
    }
    echo str_repeat('-', 72) . "\n";
    printf(
        "%d/%d checks | %d explicacoes | %d regras por assunto | %d referencias estaticas\n",
        $summary['checks_passed'],
        $summary['checks_total'],
        $summary['specific_explanations'],
        $summary['topic_explanations'],
        $summary['static_card_references_scanned']
    );
}

exit($summary['ok'] ? 0 : 1);

function readAuditFile(string $path): string
{
    if (!is_file($path)) {
        return '';
    }
    return (string) file_get_contents($path);
}

/**
 * @return array{id:string,ok:bool,message:string}
 */
function auditCheck(string $id, bool $ok, string $message): array
{
    return ['id' => $id, 'ok' => $ok, 'message' => $message];
}

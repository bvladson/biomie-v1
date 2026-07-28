<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

$root = dirname(__DIR__, 2);
$files = [
    'view' => visualReadFile($root . '/views/mapa_vendas.html'),
    'js' => visualReadFile($root . '/assets/js/biomie-map.js'),
    'css' => visualReadFile($root . '/assets/css/biomie-map.css'),
    'registry' => visualReadFile($root . '/app/Geo/GeoMapLayerRegistry.php'),
    'api' => visualReadFile($root . '/api/geo_sales_layer.php'),
];

$checks = [
    visualCheck('strategic_default_js', $files['js'], "const DEFAULT_LAYER = 'ceara_regioes'", 'Visao executiva inicia pelas regioes IPECE.'),
    visualCheck('strategic_default_view', $files['view'], 'value="ceara_regioes" selected', 'Seletor visual inicia pelas regioes IPECE.'),
    visualCheck('commercial_scope_only', $files['view'], 'value="remessa" disabled', 'Escopos sem suporte geografico nao prometem resultado.'),
    visualCheck('commercial_scope_api_guard', $files['api'], 'unsupported_geo_scope', 'API rejeita escopos sem separacao geografica confiavel.'),
    visualCheck('metric_contract', $files['js'], 'function getRowMetric(', 'Mapa possui contrato unico para a metrica ativa.'),
    visualCheck('metric_share_contract', $files['js'], 'function activeMetricShare(', 'Participacao do tooltip acompanha a metrica ativa.'),
    visualCheck('metric_refresh', $files['js'], 'refreshMetricVisuals()', 'Troca de metrica atualiza o mapa e o ranking.'),
    visualCheck('geometry_ranking_filter', $files['js'], 'filterRowsToActiveGeometry(', 'Ranking e limitado aos territorios desenhados.'),
    visualCheck('single_comparable_scale', $files['js'], 'state.compositeMetricMax', 'Visao multiescala usa uma escala global comparavel.'),
    visualCheck('truthful_heat_legend', $files['css'], '.biomie-map-legend__gradient--blue', 'Legenda de calor usa o mesmo gradiente azul do mapa.'),
    visualCheck('context_strip', $files['view'], 'id="geoTerritoryBreadcrumb"', 'Mapa explica territorio, metrica, periodo e escopo ativos.'),
    visualCheck('floating_legend', $files['css'], '.biomie-map-legend__notice', 'Legenda comporta orientacao comercial e aviso multiescala.'),
    visualCheck('contextual_clear', $files['view'], 'id="clearSelection" type="button" class="btn-secondary" hidden', 'Limpar selecao inicia oculto.'),
    visualCheck('strategic_drawer_guard', $files['js'], 'function setDrawerTabAvailability(', 'Drawer regional limita detalhes que exigem granularidade municipal.'),
    visualCheck('mode_persistence', $files['js'], "await applyMapMode(state._mapMode)", 'Modo visual ativo e reaplicado apos atualizar os dados.'),
    visualCheck('registry_default', $files['registry'], "return 'ceara_regioes';", 'Registro backend adota regioes como camada padrao.'),
];

$failures = array_values(array_filter($checks, static fn(array $check): bool => !$check['ok']));
$payload = [
    'ok' => $failures === [],
    'summary' => [
        'pass' => count($checks) - count($failures),
        'fail' => count($failures),
    ],
    'checks' => $checks,
];

if (in_array('--json', $argv, true)) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo 'Geo map visual contract' . PHP_EOL;
    echo str_repeat('-', 64) . PHP_EOL;
    foreach ($checks as $check) {
        echo sprintf('[%s] %s - %s', $check['ok'] ? 'PASS' : 'FAIL', $check['id'], $check['message']) . PHP_EOL;
    }
    echo sprintf('Resultado: %d PASS / %d FAIL', $payload['summary']['pass'], $payload['summary']['fail']) . PHP_EOL;
}

exit($failures === [] ? 0 : 1);

function visualReadFile(string $path): string
{
    $content = @file_get_contents($path);
    return is_string($content) ? $content : '';
}

/** @return array{id:string,ok:bool,message:string} */
function visualCheck(string $id, string $content, string $needle, string $message): array
{
    return [
        'id' => $id,
        'ok' => str_contains($content, $needle),
        'message' => $message,
    ];
}

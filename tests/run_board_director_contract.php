<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$checks = [];
$check = static function (string $id, bool $ok, string $detail) use (&$checks): void {
    $checks[] = ['id' => $id, 'ok' => $ok, 'detail' => $detail];
};
$source = static function (string $relative) use ($root): string {
    $content = @file_get_contents($root . '/' . $relative);
    return is_string($content) ? $content : '';
};

$page = $source('tv.php');
$frontend = $source('assets/js/tv-board.js');
$service = $source('app/Modules/Sales/Application/Board/BoardConsolidatedService.php');
$fiscal = $source('app/Modules/Fiscal/Infrastructure/FiscalReadRepository.php');
$alerts = $source('app/Ops/ManagementAlerts.php');

$check(
    'page_exports_explicit_context',
    str_contains($page, 'TenantContext::contextId()')
        && str_contains($page, 'data-context='),
    'A tela standalone expõe o contexto opaco já validado pelo bootstrap.',
);
$check(
    'fetch_propagates_context_header',
    str_contains($frontend, "'X-BIOMIE-Context': CONTEXT_ID"),
    'O fetch do board envia o contexto da aba no header.',
);
$check(
    'frontend_distinguishes_context_and_permission_errors',
    str_contains($frontend, "response.headers.get('X-BIOMIE-Context-Invalid')")
        && str_contains($frontend, "response.status === 401")
        && str_contains($frontend, "response.status === 403")
        && str_contains($frontend, "'forbidden'"),
    'Contexto expirado, sessão e acesso negado não são rotulados como falha de rede.',
);
$check(
    'frontend_has_timeout_retry_and_last_snapshot',
    str_contains($frontend, 'AbortController')
        && str_contains($frontend, 'RETRY_DELAYS')
        && str_contains($frontend, 'último resultado foi preservado'),
    'Atualização possui timeout, backoff e preserva o último snapshot.',
);
$check(
    'trend_is_real_period_comparison',
    str_contains($frontend, 'revenue_delta_pct')
        && !str_contains($frontend, 'function trendIcon('),
    'Seta/variação usa período anterior, não percentual da meta.',
);
$check(
    'service_builds_per_company_channel_scope',
    str_contains($service, "\$company['can_varejo']")
        && str_contains($service, "\$company['can_licitacao']")
        && str_contains($service, '$channelMap'),
    'O escopo vem dos grants de canal de cada empresa.',
);
$check(
    'repository_filters_business_channel',
    str_contains($fiscal, "business_channel = :")
        && str_contains($fiscal, "\$channel === 'varejo'")
        && str_contains($fiscal, "is_biomie_commercial_visible")
        && str_contains($fiscal, "Licitação usa sua classificação de canal"),
    'Varejo e licitação usam regras explícitas; visible não substitui o canal.',
);
$check(
    'group_target_uses_paired_population',
    str_contains($service, '$groupComparableRevenue += $targetComparableRevenue')
        && str_contains($service, "'paired_attainment_pct'")
        && str_contains($service, "'target_coverage'"),
    'A meta do grupo usa somente receita pareada e expõe cobertura.',
);
$check(
    'seller_target_grain_is_explicit',
    str_contains($service, "'target_grain'")
        && str_contains($service, "'target_sellers_count'")
        && str_contains($service, "'revenue_without_target'")
        && str_contains($service, "'months_configured'"),
    'Metas por vendedor declaram grão, cobertura e meses cadastrados.',
);
$check(
    'source_failure_is_not_rendered_as_zero',
    str_contains($service, "'data_status' => \$sourceAvailable ? 'available' : 'unavailable'")
        && str_contains($service, "'sales_status' => \$salesStatus")
        && str_contains($frontend, "dataStatus === 'unavailable'"),
    'Falha fiscal possui estado indisponível e o front mostra travessão.',
);
$check(
    'management_alerts_ignore_partial_targets',
    str_contains($alerts, "\$c['target_status'] ?? '') !== 'ok'")
        && str_contains($alerts, "\$targetCoverage['complete'] ?? false"),
    'Alertas de meta não disparam com cobertura parcial.',
);

$failed = array_values(array_filter(
    $checks,
    static fn (array $row): bool => !$row['ok'],
));

echo json_encode([
    'ok' => $failed === [],
    'total' => count($checks),
    'passed' => count($checks) - count($failed),
    'failed' => count($failed),
    'checks' => $checks,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failed === [] ? 0 : 1);

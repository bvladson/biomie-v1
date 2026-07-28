<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $relative) use ($root): string {
    $value = @file_get_contents($root . '/' . $relative);
    return is_string($value) ? $value : '';
};
$service = $read('app/Modules/Sales/Application/Board/BoardConsolidatedService.php');
$repository = $read('app/Modules/Fiscal/Infrastructure/FiscalReadRepository.php');
$frontend = $read('assets/js/tv-board.js');
$page = $read('tv.php');
$css = $read('assets/css/tv-board.css');

$checks = [
    'single_fiscal_board_query' =>
        substr_count($service, '->summarizeBoardPeriodsByCompanies(') === 1
        && !str_contains($service, '->summarizeInvoicesByCompanies('),
    'single_target_period_load' =>
        substr_count($service, '$this->loadTargetPeriodsByCompany(') === 1,
    'schema_capabilities_are_warmed_together' =>
        str_contains($service, 'SchemaCapabilities::warm($canonicalPdo'),
    'board_periods_use_conditional_aggregation' =>
        str_contains($repository, 'public function summarizeBoardPeriodsByCompanies(')
        && str_contains($repository, 'targeted_current_total')
        && str_contains($repository, 'comparison_total'),
    'etag_avoids_unchanged_render' =>
        str_contains($frontend, "'If-None-Match'")
        && str_contains($frontend, 'response.status === 304')
        && str_contains($frontend, 'result.etag !== state.renderedEtag'),
    'initial_snapshot_uses_canonical_service' =>
        str_contains($page, 'new BoardConsolidatedService(')
        && str_contains($page, ')->build($currentUserId, $monthStart, $today, $annualStart)')
        && str_contains($page, "Auth::currentUser()['id']")
        && str_contains($page, 'session_write_close()')
        && !str_contains($page, 'board_consolidated_v8_u'),
    'embedded_snapshot_is_script_safe' =>
        str_contains($page, 'type="application/json"')
        && str_contains($page, 'id="tvbInitialData"')
        && str_contains($page, 'JSON_HEX_TAG')
        && str_contains($page, 'JSON_HEX_AMP')
        && str_contains($page, 'JSON_HEX_APOS')
        && str_contains($page, 'JSON_HEX_QUOT'),
    'hydration_validates_context_and_skips_roundtrip' =>
        str_contains($frontend, 'function hydrateInitialSnapshot()')
        && str_contains($frontend, "String(data.meta.context_id || '') !== CONTEXT_ID")
        && str_contains($frontend, 'var hydrated = hydrateInitialSnapshot();')
        && str_contains($frontend, 'if (!hydrated && navigator.onLine && !document.hidden)'),
    'deferred_script_is_discovered_in_head' =>
        ($scriptPosition = strpos($page, '<script src="<?php echo htmlspecialchars($jsUrl')) !== false
        && ($headEndPosition = strpos($page, '</head>')) !== false
        && $scriptPosition < $headEndPosition
        && str_contains($page, 'defer fetchpriority="high"'),
    'unchanged_company_markup_preserves_dom' =>
        str_contains($frontend, 'gridMarkup')
        && str_contains($frontend, 'if (state.gridMarkup !== markup)'),
    'formatters_are_reused' =>
        str_contains($frontend, "new Intl.NumberFormat('pt-BR')")
        && str_contains($frontend, "new Intl.DateTimeFormat('pt-BR'")
        && !str_contains($frontend, ".toLocaleDateString('pt-BR'"),
    'source_failure_uses_real_backoff' =>
        str_contains($frontend, 'SOURCE_RETRY_DELAYS = [15, 60, 300, 900]')
        && !str_contains($frontend, 'schedule(RETRY_DELAYS[0])'),
    'hidden_and_offline_polling_are_paused' =>
        str_contains($frontend, 'if (document.hidden) return;')
        && str_contains($frontend, 'if (!navigator.onLine)')
        && str_contains($frontend, "document.addEventListener('visibilitychange'"),
    'one_adaptive_heartbeat_instead_of_intervals' =>
        !str_contains($frontend, 'setInterval(')
        && str_contains($frontend, 'function heartbeat()'),
    'external_font_roundtrips_removed' =>
        !str_contains($page, 'fonts.googleapis.com')
        && !str_contains($page, 'fonts.gstatic.com'),
    'tv_uses_small_logo_thumbnails' =>
        str_contains($service, "'images/logos/tv/' . basename(\$logoPath)")
        && is_file($root . '/images/logos/tv/expert.png')
        && filesize($root . '/images/logos/tv/expert.png') < 10_000,
    'continuous_expensive_paints_disabled' =>
        str_contains($css, 'body.tvb::before,')
        && str_contains($css, 'display: none;')
        && !str_contains($css, 'backdrop-filter:')
        && str_contains($css, '.tvb-card--leader .tvb-bar__fill {')
        && !str_contains($css, '@keyframes tvb-bar-shimmer'),
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
echo json_encode([
    'ok' => $failed === [],
    'total' => count($checks),
    'passed' => count($checks) - count($failed),
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failed === [] ? 0 : 1);

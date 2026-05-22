<?php

/**
 * mobile_smoke_check.php
 *
 * Verifica se todos os requisitos do pacote mobile estão presentes:
 *  - mag-mobile.css registrado no BASE_CSS
 *  - mobile-shell.js registrado no BASE_JS
 *  - views/partials/mobile_topbar.php existe
 *  - views/partials/mobile_bottom_nav.php existe
 *  - views/layouts/app.php inclui os dois partials
 *  - viewport-fit=cover em todos os views/*.html
 *  - PageRenderer injeta viewport-fit=cover
 *
 * Uso: php tools/dev/mobile_smoke_check.php [--json]
 */

declare(strict_types=1);

$root   = dirname(__DIR__, 2);
$asJson = in_array('--json', $argv ?? [], true);

$checks  = [];
$allOk   = true;

function check(array &$checks, bool &$allOk, string $id, string $label, bool $ok, string $detail = ''): void
{
    $checks[$id] = ['ok' => $ok, 'label' => $label, 'detail' => $detail];
    if (!$ok) {
        $allOk = false;
    }
}

// ── 1. mag-mobile.css no BASE_CSS ─────────────────────────────────────────
$registryFile = $root . '/app/PageAssetRegistry.php';
$registryCode = is_file($registryFile) ? (string) file_get_contents($registryFile) : '';
check(
    $checks, $allOk,
    'base_css_mobile',
    'mag-mobile.css no BASE_CSS',
    str_contains($registryCode, "'assets/css/mag-mobile.css'"),
    is_file($registryFile) ? '' : 'Arquivo PageAssetRegistry.php nao encontrado.'
);

// ── 2. mobile-shell.js no BASE_JS ─────────────────────────────────────────
check(
    $checks, $allOk,
    'base_js_mobile',
    'mobile-shell.js no BASE_JS',
    str_contains($registryCode, "'assets/js/mobile-shell.js'"),
    ''
);

// ── 3. Arquivo mag-mobile.css existe ──────────────────────────────────────
$mobileCss = $root . '/assets/css/mag-mobile.css';
check(
    $checks, $allOk,
    'file_mag_mobile_css',
    'assets/css/mag-mobile.css existe',
    is_file($mobileCss),
    is_file($mobileCss) ? 'size=' . filesize($mobileCss) . 'B' : 'Arquivo nao criado.'
);

// ── 4. Arquivo mobile-shell.js existe ────────────────────────────────────
$mobileJs = $root . '/assets/js/mobile-shell.js';
check(
    $checks, $allOk,
    'file_mobile_shell_js',
    'assets/js/mobile-shell.js existe',
    is_file($mobileJs),
    is_file($mobileJs) ? 'size=' . filesize($mobileJs) . 'B' : 'Arquivo nao criado.'
);

// ── 5. Partial mobile_topbar.php existe ───────────────────────────────────
$topbarPartial = $root . '/views/partials/mobile_topbar.php';
check(
    $checks, $allOk,
    'file_mobile_topbar',
    'views/partials/mobile_topbar.php existe',
    is_file($topbarPartial),
    is_file($topbarPartial) ? '' : 'Arquivo nao criado.'
);

// ── 6. Partial mobile_bottom_nav.php existe ───────────────────────────────
$bottomNavPartial = $root . '/views/partials/mobile_bottom_nav.php';
check(
    $checks, $allOk,
    'file_mobile_bottom_nav',
    'views/partials/mobile_bottom_nav.php existe',
    is_file($bottomNavPartial),
    is_file($bottomNavPartial) ? '' : 'Arquivo nao criado.'
);

// ── 7. views/layouts/app.php inclui os partials mobile ───────────────────
$layoutFile = $root . '/views/layouts/app.php';
$layoutCode = is_file($layoutFile) ? (string) file_get_contents($layoutFile) : '';
check(
    $checks, $allOk,
    'layout_includes_topbar',
    'app.php inclui mobile_topbar.php',
    str_contains($layoutCode, 'mobile_topbar.php'),
    ''
);
check(
    $checks, $allOk,
    'layout_includes_bottom_nav',
    'app.php inclui mobile_bottom_nav.php',
    str_contains($layoutCode, 'mobile_bottom_nav.php'),
    ''
);

// ── 8. PageRenderer injeta viewport-fit=cover ─────────────────────────────
$rendererFile = $root . '/app/PageRenderer.php';
$rendererCode = is_file($rendererFile) ? (string) file_get_contents($rendererFile) : '';
check(
    $checks, $allOk,
    'renderer_viewport',
    'PageRenderer injeta viewport-fit=cover',
    str_contains($rendererCode, 'viewport-fit=cover'),
    ''
);

// ── 9. viewport-fit=cover nos views/*.html ────────────────────────────────
$htmlViews = glob($root . '/views/*.html') ?: [];
$htmlOk    = true;
$htmlBad   = [];
foreach ($htmlViews as $htmlFile) {
    $content = (string) file_get_contents($htmlFile);
    if (!str_contains($content, 'viewport-fit=cover')) {
        $htmlOk  = false;
        $htmlBad[] = basename($htmlFile);
    }
}
check(
    $checks, $allOk,
    'html_viewports',
    'viewport-fit=cover em todos os views/*.html (' . count($htmlViews) . ' arquivos)',
    $htmlOk,
    $htmlBad !== [] ? 'Faltando em: ' . implode(', ', $htmlBad) : ''
);

// ── 10. mag-mobile.css tem media query mobile ─────────────────────────────
$cssContent = is_file($mobileCss) ? (string) file_get_contents($mobileCss) : '';
check(
    $checks, $allOk,
    'css_has_media_mobile',
    'mag-mobile.css tem @media (max-width: 767px)',
    str_contains($cssContent, 'max-width: 767px'),
    ''
);

// ── 11. mobile-shell.js tem BIomieMobileShell ─────────────────────────────
$jsContent = is_file($mobileJs) ? (string) file_get_contents($mobileJs) : '';
check(
    $checks, $allOk,
    'js_has_namespace',
    'mobile-shell.js expõe window.BIomieMobileShell',
    str_contains($jsContent, 'BIomieMobileShell'),
    ''
);

// ── 12. Topbar tem data-mobile-sidebar-toggle ─────────────────────────────
$topbarCode = is_file($topbarPartial) ? (string) file_get_contents($topbarPartial) : '';
check(
    $checks, $allOk,
    'topbar_has_sidebar_toggle',
    'mobile_topbar.php tem data-mobile-sidebar-toggle',
    str_contains($topbarCode, 'data-mobile-sidebar-toggle'),
    is_file($topbarPartial) ? '' : 'Arquivo nao encontrado.'
);

// ── 13. Bottom nav tem 5 links ────────────────────────────────────────────
$bottomNavCode = is_file($bottomNavPartial) ? (string) file_get_contents($bottomNavPartial) : '';
$navLinkCount  = substr_count($bottomNavCode, "'href'");
check(
    $checks, $allOk,
    'bottom_nav_has_5_links',
    'mobile_bottom_nav.php define 5 destinos',
    $navLinkCount >= 5,
    is_file($bottomNavPartial) ? 'destinos encontrados: ' . $navLinkCount : 'Arquivo nao encontrado.'
);

// ── 14. CSS possui @media (max-width: 400px) ──────────────────────────────
check(
    $checks, $allOk,
    'css_has_media_400',
    'mag-mobile.css tem @media (max-width: 400px)',
    str_contains($cssContent, 'max-width: 400px'),
    ''
);

// ── 15. CSS possui touch-action (mapa/scroll) ────────────────────────────
check(
    $checks, $allOk,
    'css_has_touch_action',
    'mag-mobile.css tem touch-action (scroll seguro no mapa)',
    str_contains($cssContent, 'touch-action'),
    ''
);

// ── 16. JS possui MutationObserver ───────────────────────────────────────
check(
    $checks, $allOk,
    'js_has_mutation_observer',
    'mobile-shell.js usa MutationObserver para drawers dinâmicos',
    str_contains($jsContent, 'MutationObserver'),
    ''
);

// ── 17. JS detecta body[data-page] ───────────────────────────────────────
check(
    $checks, $allOk,
    'js_has_data_page_detection',
    'mobile-shell.js detecta body[data-page] para bottom nav ativo',
    str_contains($jsContent, 'dataset.page') || str_contains($jsContent, 'data-page'),
    ''
);

// ── 18. JS tem fallback seguro para BIomieVendorDetail ───────────────────
check(
    $checks, $allOk,
    'js_has_vendor_detail_fallback',
    'mobile-shell.js acessa BIomieVendorDetail com guarda (nao chama diretamente)',
    str_contains($jsContent, 'BIomieVendorDetail') &&
        (str_contains($jsContent, 'typeof api.close') || str_contains($jsContent, 'api &&')),
    ''
);

// ── Saída ─────────────────────────────────────────────────────────────────
if ($asJson) {
    header('Content-Type: application/json');
    echo json_encode([
        'ok'     => $allOk,
        'checks' => $checks,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit($allOk ? 0 : 2);
}

// Saída humana
$pass = "\033[32m✓\033[0m";
$fail = "\033[31m✗\033[0m";

echo "\n=== BIomie Mobile Smoke Check ===\n\n";
foreach ($checks as $id => $c) {
    $icon   = $c['ok'] ? $pass : $fail;
    $detail = $c['detail'] !== '' ? '  → ' . $c['detail'] : '';
    printf("  %s  %s%s\n", $icon, $c['label'], $detail);
}

echo "\n";
if ($allOk) {
    echo "\033[32mTodos os checks passaram.\033[0m\n\n";
    exit(0);
} else {
    $failed = count(array_filter($checks, fn ($c) => !$c['ok']));
    echo "\033[31m{$failed} check(s) falharam.\033[0m\n\n";
    exit(2);
}

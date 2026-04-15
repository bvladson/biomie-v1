<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$pages = [
    [
        'id' => 'index',
        'path' => 'views/index.html',
        'type' => 'shell',
        'require_manifest' => true,
        'require_shell_js' => true,
        'require_data_page' => true,
    ],
    [
        'id' => 'vendors',
        'path' => 'views/vendedores.html',
        'type' => 'shell',
        'require_manifest' => true,
        'require_shell_js' => true,
        'require_data_page' => true,
    ],
    [
        'id' => 'orders',
        'path' => 'views/pedidos.html',
        'type' => 'shell',
        'require_manifest' => true,
        'require_shell_js' => true,
        'require_data_page' => true,
    ],
    [
        'id' => 'products',
        'path' => 'views/mercadorias.html',
        'type' => 'shell',
        'require_manifest' => true,
        'require_shell_js' => true,
        'require_data_page' => true,
    ],
    [
        'id' => 'finance',
        'path' => 'views/financeiro.html',
        'type' => 'shell',
        'require_manifest' => true,
        'require_shell_js' => true,
        'require_data_page' => true,
    ],
    [
        'id' => 'flight_plan',
        'path' => 'views/plano_voo.html',
        'type' => 'shell',
        'require_manifest' => true,
        'require_shell_js' => true,
        'require_data_page' => true,
    ],
    [
        'id' => 'integrations',
        'path' => 'views/integracoes.html',
        'type' => 'shell',
        'require_manifest' => true,
        'require_shell_js' => true,
        'require_data_page' => true,
    ],
    [
        'id' => 'reconciliation',
        'path' => 'reconciliacao.php',
        'type' => 'internal',
        'require_manifest' => false,
        'require_shell_js' => false,
        'require_data_page' => false,
    ],
];

$assetChecks = [
    ['path' => 'assets/js/theme-init.js', 'label' => 'theme_init_js'],
    ['path' => 'assets/js/shell.js', 'label' => 'shell_js'],
    ['path' => 'assets/js/app.js', 'label' => 'app_js'],
    ['path' => 'assets/js/flight-plan-v2.js', 'label' => 'flight_plan_v2_js'],
    ['path' => 'assets/css/theme-tokens.css', 'label' => 'theme_tokens_css'],
    ['path' => 'assets/css/shell-index.css', 'label' => 'shell_index_css'],
    ['path' => 'assets/css/module-pages.css', 'label' => 'module_pages_css'],
    ['path' => 'assets/css/finance-realizado.css', 'label' => 'finance_realizado_css'],
    ['path' => 'offline.html', 'label' => 'offline_html'],
    ['path' => 'site.webmanifest', 'label' => 'site_webmanifest'],
];

$checks = [];

foreach ($assetChecks as $assetCheck) {
    $absolute = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $assetCheck['path']);
    $checks[] = [
        'name' => 'asset:' . $assetCheck['label'],
        'ok' => is_file($absolute),
        'details' => $assetCheck['path'],
    ];
}

$themeInit = loadText($root . '/assets/js/theme-init.js');
$shellJs = loadText($root . '/assets/js/shell.js');
$shellCss = loadText($root . '/assets/css/shell-index.css');
$offlineHtml = loadText($root . '/offline.html');
$manifestRaw = loadText($root . '/site.webmanifest');

$checks[] = [
    'name' => 'theme_init_has_system_detection',
    'ok' => str_contains($themeInit, 'prefers-color-scheme') && str_contains($themeInit, 'dataset.theme'),
    'details' => 'theme-init.js',
];

$checks[] = [
    'name' => 'shell_has_theme_switch',
    'ok' => str_contains($shellJs, 'biomie:theme') && str_contains($shellJs, 'theme-switch') && str_contains($shellJs, 'syncChartDefaults'),
    'details' => 'shell.js',
];

$checks[] = [
    'name' => 'shell_css_has_no_legacy_root_tokens',
    'ok' => !preg_match('/:root\s*\{[^}]*--bg-base\s*:/s', $shellCss),
    'details' => 'shell-index.css',
];

$checks[] = [
    'name' => 'offline_has_theme_support',
    'ok' => str_contains($offlineHtml, 'color-scheme') && str_contains($offlineHtml, 'html[data-theme="dark"]'),
    'details' => 'offline.html',
];

$manifest = json_decode($manifestRaw, true);
$checks[] = [
    'name' => 'manifest_is_valid_json',
    'ok' => is_array($manifest),
    'details' => 'site.webmanifest',
];

if (is_array($manifest)) {
    $checks[] = [
        'name' => 'manifest_theme_background_present',
        'ok' => !empty($manifest['theme_color']) && !empty($manifest['background_color']),
        'details' => [
            'theme_color' => $manifest['theme_color'] ?? null,
            'background_color' => $manifest['background_color'] ?? null,
        ],
    ];
    $checks[] = [
        'name' => 'manifest_icons_present',
        'ok' => is_array($manifest['icons'] ?? null) && count($manifest['icons']) >= 2,
        'details' => count($manifest['icons'] ?? []),
    ];
}

$pageResults = [];
foreach ($pages as $page) {
    $absolute = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $page['path']);
    $html = loadText($absolute);
    $pageChecks = [];

    $pageChecks[] = [
        'name' => 'file_exists',
        'ok' => is_file($absolute),
        'details' => $page['path'],
    ];
    $pageChecks[] = [
        'name' => 'theme_init',
        'ok' => preg_match('/<script[^>]+assets\/js\/theme-init\.js\?v=/i', $html) === 1,
        'details' => 'theme-init.js?v=...',
    ];
    $pageChecks[] = [
        'name' => 'color_scheme_meta',
        'ok' => preg_match('/<meta[^>]+name=["\']color-scheme["\'][^>]+content=["\']light dark["\']/i', $html) === 1,
        'details' => 'meta color-scheme',
    ];
    $pageChecks[] = [
        'name' => 'theme_color_light',
        'ok' => preg_match('/<meta[^>]+name=["\']theme-color["\'][^>]+prefers-color-scheme:\s*light/i', $html) === 1,
        'details' => 'theme-color light',
    ];
    $pageChecks[] = [
        'name' => 'theme_color_dark',
        'ok' => preg_match('/<meta[^>]+name=["\']theme-color["\'][^>]+prefers-color-scheme:\s*dark/i', $html) === 1,
        'details' => 'theme-color dark',
    ];
    $pageChecks[] = [
        'name' => 'theme_tokens_stylesheet',
        'ok' => preg_match('/assets\/css\/theme-tokens\.css\?v=/i', $html) === 1,
        'details' => 'theme-tokens.css?v=...',
    ];

    if ($page['require_shell_js']) {
        $pageChecks[] = [
            'name' => 'shell_js_versioned',
            'ok' => preg_match('/assets\/js\/shell\.js\?v=/i', $html) === 1,
            'details' => 'shell.js?v=...',
        ];
    }

    if ($page['require_manifest']) {
        $pageChecks[] = [
            'name' => 'manifest_link',
            'ok' => preg_match('/<link[^>]+rel=["\']manifest["\'][^>]+site\.webmanifest/i', $html) === 1,
            'details' => 'site.webmanifest',
        ];
    }

    if ($page['require_data_page']) {
        $pageChecks[] = [
            'name' => 'body_data_page',
            'ok' => preg_match('/<body[^>]+data-page=["\'][^"\']+["\']/i', $html) === 1,
            'details' => 'body[data-page]',
        ];
    }

    $pageChecks[] = [
        'name' => 'theme_init_before_shell_css',
        'ok' => position($html, 'assets/js/theme-init.js') < position($html, 'assets/css/shell-index.css'),
        'details' => 'theme-init before shell CSS',
    ];

    $warnings = [];
    $warnings = array_merge($warnings, suspiciousStyleWarnings($page['path'], $html));

    $pageResults[] = [
        'id' => $page['id'],
        'path' => $page['path'],
        'ok' => count(array_filter($pageChecks, static fn (array $check): bool => $check['ok'] !== true)) === 0,
        'checks' => $pageChecks,
        'warnings' => $warnings,
    ];
}

$summary = [
    'ok' => count(array_filter($checks, static fn (array $check): bool => $check['ok'] !== true)) === 0
        && count(array_filter($pageResults, static fn (array $page): bool => $page['ok'] !== true)) === 0,
    'generated_at' => date(DATE_ATOM),
    'checks' => $checks,
    'pages' => $pageResults,
    'manual_follow_up' => [
        'Confirmar contraste visual em light, dark e system nas telas prioritarias.',
        'Testar hover, focus, disabled, loading, erro, vazio, modal e drawer com navegacao real.',
        'Validar graficos, tooltip, legenda e linhas finas em dark mode.',
        'Revisar mobile, TV mode e PWA instalada com troca de tema e persistencia.',
        'Confirmar que offline.html e splash/theme-color acompanham o tema apos instalacao.',
    ],
];

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($summary['ok'] ? 0 : 1);

function loadText(string $absolutePath): string
{
    if (!is_file($absolutePath)) {
        return '';
    }

    $contents = file_get_contents($absolutePath);

    return $contents === false ? '' : $contents;
}

function position(string $haystack, string $needle): int
{
    $position = strpos($haystack, $needle);

    return $position === false ? PHP_INT_MAX : $position;
}

/**
 * @return array<int, array{name:string,count:int}>
 */
function suspiciousStyleWarnings(string $path, string $contents): array
{
    $patterns = [
        'inline_mouseover_handlers' => '/onmouseover\s*=/i',
        'hardcoded_light_backgrounds' => '/background\s*:\s*#(?:fff|ffffff|fafafa|f9f9f9|f0f0f0)\b/i',
        'hardcoded_light_surfaces' => '/border\s*:\s*1px\s+solid\s+#eaeaea\b/i',
        'hardcoded_dark_text' => '/color\s*:\s*#222\b/i',
    ];

    $warnings = [];
    foreach ($patterns as $name => $pattern) {
        $count = preg_match_all($pattern, $contents, $matches);
        if (is_int($count) && $count > 0) {
            $warnings[] = [
                'name' => $name,
                'count' => $count,
                'path' => $path,
            ];
        }
    }

    return $warnings;
}

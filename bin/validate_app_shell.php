<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\PageRenderer;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require dirname(__DIR__) . '/app/Bootstrap.php';

Bootstrap::init();

$user = ['full_name' => 'Validador BIomie', 'username' => 'validator', 'role' => 'admin'];
$pages = [
    ['template' => 'index.html', 'page_key' => 'dashboard', 'php' => false],
    ['template' => 'vendedores.html', 'page_key' => 'time-comercial', 'php' => false],
    ['template' => 'mapa_vendas.html', 'page_key' => 'geografia', 'php' => false],
    ['template' => 'pedidos.html', 'page_key' => 'nf-periodo', 'php' => false],
    ['template' => 'mercadorias.html', 'page_key' => 'mix-faturado', 'php' => false],
    ['template' => 'financeiro.html', 'page_key' => 'financeiro', 'php' => false],
    ['template' => 'plano_voo.html', 'page_key' => 'rota-comercial', 'php' => false],
    ['template' => 'plano_voo_metas.html', 'page_key' => 'rota-comercial-metas', 'php' => false],
    ['template' => 'integracoes.html', 'page_key' => 'omie', 'php' => false],
    ['template' => 'reconciliacao.html', 'page_key' => 'conciliacao', 'php' => true],
    ['template' => 'observabilidade.html', 'page_key' => 'observabilidade', 'php' => false],
    ['template' => 'admin.php', 'page_key' => 'admin', 'php' => true],
];

$failures = [];
foreach ($pages as $page) {
    $context = [
        'page_key' => $page['page_key'],
        'user' => $user,
        'is_admin' => true,
        'csrf' => 'test-csrf',
        'message' => null,
        'messageType' => 'success',
        'report' => null,
        'recentRuns' => [],
        'selectedRunId' => 0,
        'formStart' => '2026-05-01',
        'formEnd' => '2026-05-31',
    ];
    $html = $page['php']
        ? PageRenderer::renderPhp((string) $page['template'], $context)
        : PageRenderer::render((string) $page['template'], $context);

    $sidebarCount = preg_match_all('/<aside\b(?=[^>]*\bid=["\']sidebar["\'])/i', $html);
    $sidebarMenuCount = preg_match_all('/<nav\b(?=[^>]*\bid=["\']sidebar-menu["\'])/i', $html);
    $topbarCount = preg_match_all('/<header\b(?=[^>]*\bclass=["\'][^"\']*\btopbar\b)/i', $html);
    $activeCount = preg_match_all('/aria-current=["\']page["\']/i', $html);
    $hasGlobalSidebarClass = preg_match('/<aside\b(?=[^>]*\bid=["\']sidebar["\'])(?=[^>]*\bclass=["\'][^"\']*\bmag-sidebar\b)/i', $html) === 1;
    $hasDataPage = preg_match('/<body\b[^>]*\bdata-page=["\']' . preg_quote((string) $page['page_key'], '/') . '["\']/i', $html) === 1;
    $hasLegacyLayoutBodyClass = preg_match('/<body\b[^>]*\bclass=["\'][^"\']*\b(?:nav-md|nav-sm|sidebar-mini|sidebar-collapse|sidebar-collapsed|sidebar-open|menu-open)\b/i', $html) === 1;
    $hasThemeToggle = preg_match('/theme-switch|theme-option|theme-init|data-theme|light dark|prefers-color-scheme/i', $html) === 1;
    $hasRawPhp = str_contains($html, '<?php');

    if ($sidebarCount !== 1) {
        $failures[] = "{$page['template']}: sidebar={$sidebarCount}";
    }
    if ($sidebarMenuCount !== 1) {
        $failures[] = "{$page['template']}: sidebar-menu={$sidebarMenuCount}";
    }
    if (!$hasGlobalSidebarClass) {
        $failures[] = "{$page['template']}: sidebar global mag-sidebar ausente";
    }
    if ($topbarCount !== 1) {
        $failures[] = "{$page['template']}: topbar={$topbarCount}";
    }
    if (!$hasDataPage) {
        $failures[] = "{$page['template']}: data-page ausente ou divergente";
    }
    if ($hasLegacyLayoutBodyClass) {
        $failures[] = "{$page['template']}: classe antiga de layout no body";
    }
    if ($hasThemeToggle) {
        $failures[] = "{$page['template']}: marcador de tema dark/toggle no HTML";
    }
    if ($hasRawPhp) {
        $failures[] = "{$page['template']}: PHP cru no HTML";
    }
    if ($activeCount !== 1) {
        $failures[] = "{$page['template']}: item ativo={$activeCount}";
    }
}

$nonAdminHtml = PageRenderer::render('index.html', [
    'page_key' => 'dashboard',
    'user' => ['full_name' => 'Usuario comum', 'username' => 'user', 'role' => 'viewer'],
    'is_admin' => false,
]);
foreach (['admin.php', 'reconciliacao.php', 'observabilidade.php'] as $restrictedHref) {
    if (str_contains($nonAdminHtml, 'href="' . $restrictedHref . '"')) {
        $failures[] = "usuario comum ve item restrito {$restrictedHref}";
    }
}

if ($failures !== []) {
    echo "FAIL\n";
    foreach ($failures as $failure) {
        echo "- {$failure}\n";
    }
    exit(1);
}

echo "OK: AppShell renderiza sidebar global unica, uma topbar, body[data-page], light-only e um item ativo por pagina.\n";

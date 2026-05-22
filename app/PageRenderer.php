<?php

declare(strict_types=1);

namespace BIomie;

use BIomie\Support\Asset;
use BIomie\Support\HttpCache;

final class PageRenderer
{
    /**
     * @param array<string,mixed> $context
     */
    public static function render(string $templateFile, array $context = []): string
    {
        $path = Bootstrap::rootPath('views/' . ltrim($templateFile, '/'));
        if (!is_file($path)) {
            throw new \RuntimeException(
                'Template nao encontrado: views/' . ltrim($templateFile, '/') . '. '
                . 'Verifique se a pasta views/ foi enviada ao servidor. '
                . 'Acesse /biomie/check.php para diagnostico completo.'
            );
        }

        $html = (string) file_get_contents($path);
        return self::renderDocument(self::normalizeLegacyLinks($html), $templateFile, $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    public static function renderPhp(string $templateFile, array $context = []): string
    {
        $path = Bootstrap::rootPath('views/' . ltrim($templateFile, '/'));
        if (!is_file($path)) {
            throw new \RuntimeException(
                'Template nao encontrado: views/' . ltrim($templateFile, '/') . '. '
                . 'Verifique se a pasta views/ foi enviada ao servidor. '
                . 'Acesse /biomie/check.php para diagnostico completo.'
            );
        }

        extract($context, EXTR_SKIP);

        ob_start();
        try {
            require $path;
            $html = (string) ob_get_clean();
            return self::renderDocument(self::normalizeLegacyLinks($html), $templateFile, $context);
        } catch (\Throwable $throwable) {
            ob_end_clean();
            throw $throwable;
        }
    }

    /**
     * @param array<string,mixed> $context
     */
    private static function renderDocument(string $html, string $templateFile, array $context): string
    {
        HttpCache::noStoreHtml();
        $user = $context['user'] ?? null;
        $isAdmin = self::resolveIsAdmin($context);
        $bootPayloads = is_array($context['boot_payloads'] ?? null) ? $context['boot_payloads'] : null;

        $html = self::injectMetaTags($html);
        $activePage = self::canonicalPage(self::resolveActivePage($html, $templateFile, $context));
        $meta = self::pageMeta($activePage, $context);

        $parts = self::extractDocumentParts($html);

        // Inject page-specific CSS/JS from registry into head and body extra
        $parts['head']  = self::injectRegistryHead($activePage, $parts['head']);
        $parts['extra'] = self::injectRegistryBody($activePage, $parts['extra']);

        $pageActions = is_array($context['pageActions'] ?? null) ? $context['pageActions'] : [];
        $contentHtml = self::ensureMainHasPageHeader($parts['main'], $meta, [
            'user'        => $user,
            'isAdmin'     => $isAdmin,
            'pageActions' => $pageActions,
        ]);

        if ($bootPayloads !== null) {
            $parts['extra'] = self::bootPayloadScript($bootPayloads) . "\n" . $parts['extra'];
        }

        $layout = self::renderLayout('app.php', [
            'headHtml' => $parts['head'],
            'bodyClass' => $parts['body_class'],
            'pageKey' => $activePage,
            'contentHtml' => $contentHtml,
            'bodyExtraHtml' => $parts['extra'],
            'layoutContext' => [
                'activePage' => $activePage,
                'currentPageKey' => $activePage,
                'pageKey' => $activePage,
                'pageTitle' => $meta['title'],
                'pageSubtitle' => $meta['description'],
                'pageBadge' => $meta['badge'],
                'pageIcon' => $meta['icon'],
                'pageActions' => $context['pageActions'] ?? [],
                'topbarActions' => $context['topbarActions'] ?? [],
                'topbarMeta' => $context['topbarMeta'] ?? [],
                'menuItems' => self::menuItems(),
                'user' => is_array($user) ? $user : null,
                'isAdmin' => $isAdmin,
                'appName' => (string) ($context['appName'] ?? 'BIomie'),
                'appVersion' => (string) ($context['appVersion'] ?? ''),
                'environment' => (string) ($context['environment'] ?? getenv('APP_ENV') ?: 'production'),
            ],
        ]);

        return self::withMagDesignSystem($layout);
    }

    private static function normalizeLegacyLinks(string $html): string
    {
        $replacements = [
            'index.html' => 'index.php',
            'vendedores.html' => 'vendedores.php',
            'pedidos.html' => 'pedidos.php',
            'mercadorias.html' => 'mercadorias.php',
            'financeiro.html' => 'financeiro.php',
            'plano_voo.html' => 'plano_voo.php',
            'plano_voo_metas.html' => 'plano_voo_metas.php',
            'integracoes.html' => 'integracoes.php',
            'observabilidade.html' => 'observabilidade.php',
            'mapa_vendas.html' => 'mapa_vendas.php',
            'reconciliacao.html' => 'reconciliacao.php',
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $html);
    }

    private static function injectMetaTags(string $html): string
    {
        $metaTags = sprintf(
            '<meta name="biomie-csrf" content="%s" />'
            . '<meta name="biomie-request-id" content="%s" />',
            htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars(RequestContext::id(), ENT_QUOTES, 'UTF-8')
        ) . "\n" . Asset::tagsMeta();

        if (preg_match('/<head[^>]*>/i', $html) === 1) {
            $html = preg_replace('/<head([^>]*)>/i', '<head$1>' . $metaTags, $html, 1) ?: $html;
        } else {
            $html = preg_replace('/<\/head>/i', $metaTags . '</head>', $html, 1) ?: $html;
        }

        // Garantir viewport correto com viewport-fit=cover para Chrome Mobile / iPhone
        $correctViewport = '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />';
        if (preg_match('/<meta[^>]+name=["\']viewport["\'][^>]*>/i', $html) === 1) {
            // Substituir viewport existente pelo correto
            $html = preg_replace(
                '/<meta[^>]+name=["\']viewport["\'][^>]*>/i',
                $correctViewport,
                $html,
                1
            ) ?: $html;
        } else {
            // Inserir após <head>
            $html = preg_replace('/<head([^>]*)>/i', '<head$1>' . "\n" . $correctViewport, $html, 1) ?: $html;
        }

        return $html;
    }

    /**
     * @param array<string,mixed> $context
     */
    private static function resolveIsAdmin(array $context): bool
    {
        if (array_key_exists('is_admin', $context)) {
            return (bool) $context['is_admin'];
        }
        if (array_key_exists('isAdmin', $context)) {
            return (bool) $context['isAdmin'];
        }

        try {
            return Auth::canAccessAdminArea();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string,mixed> $context
     */
    private static function resolveActivePage(string $html, string $templateFile, array $context): string
    {
        $fromContext = (string) ($context['activePage'] ?? $context['page_key'] ?? $context['pageKey'] ?? '');
        if ($fromContext !== '') {
            return $fromContext;
        }

        if (preg_match('/<body[^>]*\bdata-page=["\']([^"\']+)["\']/i', $html, $matches) === 1) {
            return (string) $matches[1];
        }

        $pageByTemplate = [
            'index.html' => 'dashboard',
            'vendedores.html' => 'time-comercial',
            'mapa_vendas.html' => 'geografia',
            'pedidos.html' => 'nf-periodo',
            'mercadorias.html' => 'mix-faturado',
            'financeiro.html' => 'financeiro',
            'plano_voo.html' => 'rota-comercial',
            'plano_voo_metas.html' => 'rota-comercial',
            'integracoes.html' => 'omie',
            'reconciliacao.html' => 'conciliacao',
            'observabilidade.html' => 'observabilidade',
            'admin.php' => 'admin',
        ];

        return $pageByTemplate[ltrim($templateFile, '/')] ?? 'dashboard';
    }

    private static function canonicalPage(string $page): string
    {
        $normalized = strtolower(trim($page));
        $aliases = [
            'index' => 'dashboard',
            'home' => 'dashboard',
            'vendors' => 'time-comercial',
            'vendedores' => 'time-comercial',
            'mapa_vendas' => 'geografia',
            'mapa-vendas' => 'geografia',
            'geo' => 'geografia',
            'orders' => 'nf-periodo',
            'pedidos' => 'nf-periodo',
            'nf_periodo' => 'nf-periodo',
            'products' => 'mix-faturado',
            'mercadorias' => 'mix-faturado',
            'mix_faturado' => 'mix-faturado',
            'finance' => 'financeiro',
            'flight-plan' => 'rota-comercial',
            'flight-plan-targets' => 'rota-comercial',
            'plano_voo' => 'rota-comercial',
            'plano_voo_metas' => 'rota-comercial',
            'integrations' => 'omie',
            'integracoes' => 'omie',
            'analytics' => 'analises',
            'indicators' => 'indicadores',
            'desk' => 'mesa',
            'reconciliation' => 'conciliacao',
            'reconciliacao' => 'conciliacao',
            'observability' => 'observabilidade',
            'cockpit_interno' => 'cockpit-interno',
            'metric_catalog' => 'catalogo-kpis',
            'catalogo_kpis' => 'catalogo-kpis',
            'system_health' => 'system-health',
            'saude-sistema' => 'system-health',
        ];

        return $aliases[$normalized] ?? $normalized;
    }

    /**
     * @return array{head:string,body_class:string,main:string,extra:string}
     */
    private static function extractDocumentParts(string $html): array
    {
        $head = '';
        if (preg_match('/<head[^>]*>(.*?)<\/head>/is', $html, $matches) === 1) {
            $head = trim((string) $matches[1]);
        }

        $bodyClass = 'mag-page mag-clean-page';
        if (preg_match('/<body\b([^>]*)>/i', $html, $bodyMatches) === 1
            && preg_match('/\bclass=["\']([^"\']*)["\']/i', (string) $bodyMatches[1], $classMatches) === 1
        ) {
            $bodyClass = (string) $classMatches[1];
        }

        $body = $html;
        if (preg_match('/<body\b[^>]*>(.*)<\/body>/is', $html, $bodyMatches) === 1) {
            $body = (string) $bodyMatches[1];
        }

        $scripts = '';
        if (preg_match_all('/<script\b[^>]*>.*?<\/script>/is', $body, $scriptMatches) > 0) {
            $scripts = implode("\n", $scriptMatches[0]);
            $body = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $body) ?: $body;
        }

        $body = preg_replace('/<div\b(?=[^>]*\bid=["\']overlay["\'])[^>]*>.*?<\/div>/is', '', $body) ?: $body;
        $body = preg_replace('/<aside\b(?=[^>]*\bid=["\']sidebar["\'])(?=[^>]*\bclass=["\'][^"\']*\bsidebar\b[^"\']*["\'])[^>]*>.*?<\/aside>/is', '', $body) ?: $body;
        $body = preg_replace('/<header\b(?=[^>]*\bclass=["\'][^"\']*\btopbar\b[^"\']*["\'])[^>]*>.*?<\/header>/is', '', $body) ?: $body;

        $main = '';
        $extra = '';
        if (preg_match('/<main\b[^>]*>.*<\/main>/is', $body, $mainMatches, PREG_OFFSET_CAPTURE) === 1) {
            $main = (string) $mainMatches[0][0];
            $offset = (int) $mainMatches[0][1];
            $extra = substr($body, $offset + strlen($main));
            $extra = preg_replace('/^\s*(?:<\/div>\s*){0,4}/i', '', $extra) ?: $extra;
        } else {
            $main = '<main class="page">' . trim($body) . '</main>';
        }

        $extra = trim($extra);
        $scripts = trim($scripts);
        if ($scripts !== '') {
            $extra = trim($extra . "\n" . $scripts);
        }

        return [
            'head' => $head,
            'body_class' => $bodyClass,
            'main' => $main,
            'extra' => $extra,
        ];
    }

    /**
     * @param array<string,string> $meta
     * @param array<string,mixed> $context
     */
    private static function ensureMainHasPageHeader(string $mainHtml, array $meta, array $context): string
    {
        if (stripos($mainHtml, 'mag-page-header') !== false || stripos($mainHtml, 'mag-clean-header') !== false) {
            return $mainHtml;
        }

        $header = self::renderPartial('page-header.php', [
            'pageIcon'     => $meta['icon'],
            'pageTitle'    => $meta['title'],
            'pageSubtitle' => $meta['description'],
            'pageBadge'    => $meta['badge'],
            'pageActions'  => $context['pageActions'] ?? [],
            'user'         => $context['user'] ?? null,
            'isAdmin'      => $context['isAdmin'] ?? false,
        ]);

        return preg_replace('/(<main\b[^>]*>)/i', '$1' . "\n" . $header, $mainHtml, 1) ?: ($header . $mainHtml);
    }

    /**
     * @param array<string,mixed> $bootPayloads
     */
    private static function bootPayloadScript(array $bootPayloads): string
    {
        $bootJson = json_encode(
            $bootPayloads,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_INVALID_UTF8_SUBSTITUTE
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
        );

        return is_string($bootJson) && $bootJson !== ''
            ? '<script>window.__BIOMIE_BOOTSTRAP__=' . $bootJson . ';</script>'
            : '';
    }

    /**
     * @param array<string,mixed> $context
     */
    private static function renderLayout(string $layoutFile, array $context): string
    {
        $path = Bootstrap::rootPath('views/layouts/' . ltrim($layoutFile, '/'));
        if (!is_file($path)) {
            throw new \RuntimeException('Layout nao encontrado: views/layouts/' . ltrim($layoutFile, '/'));
        }

        extract($context, EXTR_SKIP);

        ob_start();
        try {
            require $path;
            return (string) ob_get_clean();
        } catch (\Throwable $throwable) {
            ob_end_clean();
            throw $throwable;
        }
    }

    /**
     * @param array<string,mixed> $context
     */
    private static function renderPartial(string $partialFile, array $context): string
    {
        $path = Bootstrap::rootPath('views/partials/' . ltrim($partialFile, '/'));
        if (!is_file($path)) {
            return '';
        }

        extract($context, EXTR_SKIP);

        ob_start();
        try {
            require $path;
            return (string) ob_get_clean();
        } catch (\Throwable $throwable) {
            ob_end_clean();
            throw $throwable;
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private static function menuItems(): array
    {
        $path = Bootstrap::rootPath('views/config/menu.php');
        $items = is_file($path) ? require $path : [];
        return is_array($items) ? array_values($items) : [];
    }

    /**
     * @param array<string,mixed> $context
     * @return array{title:string,description:string,badge:string,icon:string}
     */
    private static function pageMeta(string $pageKey, array $context): array
    {
        $definitions = [
            'dashboard' => ['Dashboard Comercial', 'Visão rápida dos principais indicadores da operação.', 'Visão geral', 'space_dashboard'],
            'time-comercial' => ['Time Comercial', 'Acompanhe performance, carteira e ritmo dos vendedores.', 'Comercial', 'groups'],
            'geografia' => ['Vendas por Região', 'Entenda onde estão concentradas as vendas por bairro, cidade e região.', 'Geografia', 'map'],
            'nf-periodo' => ['NF do Período', 'Acompanhe notas, faturamento e status fiscais do período.', 'Operação', 'receipt_long'],
            'mix-faturado' => ['Mix Faturado', 'Analise produtos, categorias e composição do faturamento.', 'Operação', 'inventory_2'],
            'financeiro' => ['Financeiro', 'Acompanhe recebíveis, vencimentos, inadimplência e previsão financeira.', 'Financeiro', 'account_balance_wallet'],
            'rota-comercial' => ['Rota Comercial', 'Planeje metas, carteira e leitura comercial por período.', 'Operação', 'flight'],
            'omie' => ['Conexão Omie', 'Monitore integrações, sincronizações e saúde dos dados de origem.', 'Sistema', 'hub'],
            'indicadores' => ['Indicadores', 'Principais sinais comerciais consolidados.', 'Inteligência', 'query_stats'],
            'analises' => ['Análises', 'Gráficos e leituras de apoio à decisão.', 'Inteligência', 'monitoring'],
            'mesa' => ['Mesa', 'Tabelas operacionais para leitura e comparação rápida.', 'Inteligência', 'table_view'],
            'admin' => ['Administração', 'Gerencie configurações, usuários, diagnóstico e manutenção do sistema.', 'Admin', 'admin_panel_settings'],
            'conciliacao' => ['Conciliação', 'Revise consistência fiscal e financeira dos dados.', 'Sistema', 'rule'],
            'observabilidade' => ['Observabilidade', 'Acompanhe saúde técnica, logs e sinais operacionais.', 'Sistema', 'monitoring'],
            'cockpit-interno' => ['Cockpit Interno MAG', 'Visão executiva dos KPIs oficiais, freshness e saúde semântica.', 'Sistema', 'dashboard_customize'],
            'catalogo-kpis'   => ['Catálogo de KPIs', 'Lista oficial dos KPIs, fonte de verdade, status, domínio e permissão.', 'Sistema', 'hub'],
            'system-health'   => ['Saúde do Sistema', 'Status em tempo real dos checks de infraestrutura, dados e jobs.', 'Sistema', 'monitor_heart'],
        ];

        $row = $definitions[$pageKey] ?? $definitions['dashboard'];

        return [
            'title' => (string) ($context['title'] ?? $context['pageTitle'] ?? $row[0]),
            'description' => (string) ($context['description'] ?? $context['pageSubtitle'] ?? $row[1]),
            'badge' => (string) ($context['badge'] ?? $context['pageBadge'] ?? $row[2]),
            'icon' => (string) ($context['icon'] ?? $context['pageIcon'] ?? $row[3]),
        ];
    }

    /**
     * Injeta no head os CSS e JS-head do registro que ainda não estejam declarados.
     */
    private static function injectRegistryHead(string $pageKey, string $headHtml): string
    {
        $inject = [];

        foreach (PageAssetRegistry::cssForPage($pageKey) as $path) {
            if (!PageAssetRegistry::alreadyPresent($headHtml, $path)) {
                $inject[] = sprintf(
                    '<link rel="stylesheet" href="%s" />',
                    htmlspecialchars(Asset::url($path), ENT_QUOTES, 'UTF-8')
                );
            }
        }

        foreach (PageAssetRegistry::jsHeadForPage($pageKey) as $path) {
            if (!PageAssetRegistry::alreadyPresent($headHtml, $path)) {
                $inject[] = sprintf(
                    '<script src="%s"></script>',
                    htmlspecialchars(Asset::url($path), ENT_QUOTES, 'UTF-8')
                );
            }
        }

        if ($inject === []) {
            return $headHtml;
        }

        return $headHtml . "\n" . implode("\n", $inject);
    }

    /**
     * Injeta antes do conteúdo extra os JS-body do registro que ainda não estejam declarados.
     */
    private static function injectRegistryBody(string $pageKey, string $extraHtml): string
    {
        $inject = [];

        foreach (PageAssetRegistry::jsBodyForPage($pageKey) as $path) {
            if (!PageAssetRegistry::alreadyPresent($extraHtml, $path)) {
                $inject[] = sprintf(
                    '<script src="%s" defer></script>',
                    htmlspecialchars(Asset::url($path), ENT_QUOTES, 'UTF-8')
                );
            }
        }

        if ($inject === []) {
            return $extraHtml;
        }

        return implode("\n", $inject) . ($extraHtml !== '' ? "\n" . $extraHtml : '');
    }

    private static function withMagDesignSystem(string $html): string
    {
        $root = dirname(__DIR__);

        $assetPaths = [
            'assets/css/theme-tokens.css',
            'assets/css/mag-design-system.css',
            'assets/js/performance-monitor.js',
            'assets/js/shell.js',
            'assets/js/chart-helpers.js',
            'assets/js/mag-ui.js',
            'assets/js/cache-refresh.js',
        ];

        // Per-file versions derived from filemtime; fallback to compile-time constant.
        $av = static function (string $rel) use ($root): string {
            $mt = @filemtime($root . '/' . $rel);
            return $mt !== false ? (string) $mt : '20260501';
        };

        $cssVer1 = $av('assets/css/theme-tokens.css');
        $cssVer2 = $av('assets/css/mag-design-system.css');

        $assets = [
            'assets/css/theme-tokens.css' => "\n  <link rel=\"stylesheet\" href=\"assets/css/theme-tokens.css?v={$cssVer1}\" />",
            'assets/css/mag-design-system.css' => "\n  <link rel=\"stylesheet\" href=\"assets/css/mag-design-system.css?v={$cssVer2}\" />",
        ];

        foreach ($assets as $needle => $tag) {
            if (stripos($html, $needle) === false) {
                $html = preg_replace('/<\/head>/i', $tag . "\n</head>", $html, 1) ?: ($tag . "\n" . $html);
            }
        }

        if (stripos($html, 'assets/js/performance-monitor.js') === false) {
            $v = $av('assets/js/performance-monitor.js');
            $script = "\n<script src=\"assets/js/performance-monitor.js?v={$v}\"></script>";
            $html = preg_replace('/<\/head>/i', $script . "\n</head>", $html, 1) ?: ($script . "\n" . $html);
        }

        if (stripos($html, 'assets/js/shell.js') === false) {
            $v = $av('assets/js/shell.js');
            $script = "\n<script src=\"assets/js/shell.js?v={$v}\"></script>";
            $html = preg_replace('/<\/body>/i', $script . "\n</body>", $html, 1) ?: ($html . $script);
        }

        if (stripos($html, 'assets/js/chart-helpers.js') === false) {
            $v = $av('assets/js/chart-helpers.js');
            $script = "\n<script src=\"assets/js/chart-helpers.js?v={$v}\"></script>";
            $html = preg_replace('/<\/head>/i', $script . "\n</head>", $html, 1) ?: ($script . "\n" . $html);
        }

        if (stripos($html, 'assets/js/mag-ui.js') === false) {
            $v = $av('assets/js/mag-ui.js');
            $script = "\n<script src=\"assets/js/mag-ui.js?v={$v}\" defer></script>";
            $html = preg_replace('/<\/body>/i', $script . "\n</body>", $html, 1) ?: ($html . $script);
        }

        foreach ($assetPaths as $assetPath) {
            $v = $av($assetPath);
            $pattern = '~' . preg_quote($assetPath, '~') . '(?:\?v=[^"\']*)?~i';
            $html = preg_replace($pattern, $assetPath . '?v=' . $v, $html) ?: $html;
        }

        return $html;
    }
}

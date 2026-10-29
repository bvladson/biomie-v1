<?php

declare(strict_types=1);

namespace BIomie;

use BIomie\Support\Asset;

/**
 * Registro canônico de assets por página.
 *
 * Cada page-key declara apenas o que precisa além do stack base do layout.
 * O PageRenderer usa este registro para injetar CSS/JS sem duplicação.
 *
 * Stack base (injetado pelo layout via withMagDesignSystem):
 *   CSS: theme-tokens, mag-design-system
 *   JS:  shell.js, chart-helpers.js, mag-ui.js, performance-monitor.js
 */
final class PageAssetRegistry
{
    /** CSS carregado em todas as páginas (além do base do layout) */
    private const BASE_CSS = [
        'assets/css/shell-index.css',
        'assets/css/module-pages.css',
        'assets/css/mag-clean.css',
        'assets/css/mag-adminlte-clean.css',
        'assets/css/mag-mobile.css',
        'assets/css/mag-mobile-premium.css', // Fase R — visual premium mobile
        'assets/css/filters.css',          // Fase Q — motor global de filtros
        'assets/css/biomie-drawer.css',    // EST.R1 Fase 0.3 — padrão global de drawers
        'assets/css/biomie-floating-scroll.css', // Rolagem horizontal acessível em tabelas extensas
    ];

    /** JS utilitário core — carregado em todas as páginas antes do </body> */
    private const BASE_JS = [
        'assets/js/biomie-formatters.js',
        'assets/js/biomie-states.js',
        'assets/js/core/utils.js',
        'assets/js/core/render-helpers.js',
        // Fase Q — motor global de filtros (ordem de dependência)
        'assets/js/core/request-controller.js',
        'assets/js/core/filter-state.js',
        'assets/js/core/filter-url.js',
        'assets/js/core/filter-chips.js',
        'assets/js/core/server-table.js',
        'assets/js/pwa.js',
        'assets/js/cache-refresh.js',
        'assets/js/mobile-shell.js',
        'assets/js/biomie-floating-scroll.js',
    ];

    /**
     * Assets específicos por page-key.
     *
     * 'css'      → <link rel="stylesheet"> injetados no <head>
     * 'js_head'  → <script> injetados no <head> (sem defer — ex: chart.umd.js)
     * 'js_body'  → <script defer> injetados antes do </body>
     */
    private const PAGE_ASSETS = [
        'dashboard' => [
            'css'     => [],
            'js_head' => ['assets/js/chart.umd.js'],
            'js_body' => [
                'assets/js/mag-chart-defaults.js',
                'assets/js/core/chart-factory.js',
                'assets/js/client_dossier.js',
                'assets/js/index_geo.js',
                'assets/js/modules/overview/overview-detail-drawer.js',
                'assets/js/modules/drilldown-router.js',
                'assets/js/modules/vendor-detail/tabs.js',
                'assets/js/modules/vendor-detail/composition.js',
                'assets/js/modules/vendor-detail/charts.js',
                'assets/js/modules/vendor-detail/index.js',
                'assets/js/modules/invoice-detail/index.js',
                'assets/js/modules/order-detail/index.js',
                'assets/js/modules/customer-detail/index.js',
                'assets/js/modules/dashboard/page-drilldown.js', // Q.7 — extra filters
                'assets/js/app.js',
            ],
        ],

        'time-comercial' => [
            'css'     => [],
            'js_head' => ['assets/js/chart.umd.js'],
            'js_body' => [
                'assets/js/mag-chart-defaults.js',
                'assets/js/core/chart-factory.js',
                'assets/js/app.js',
                'assets/js/modules/drilldown-router.js',
                'assets/js/modules/vendor-detail/tabs.js',
                'assets/js/modules/vendor-detail/composition.js',
                'assets/js/modules/vendor-detail/charts.js',
                'assets/js/modules/vendor-detail/index.js',
                'assets/js/modules/order-detail/index.js',
                'assets/js/modules/invoice-detail/index.js',
                'assets/js/modules/customer-detail/index.js',
                'assets/js/modules/vendors/page-drilldown.js',
            ],
        ],

        'nf-periodo' => [
            'css'     => [],
            'js_head' => ['assets/js/chart.umd.js'],
            'js_body' => [
                'assets/js/mag-chart-defaults.js',
                'assets/js/core/chart-factory.js',
                'assets/js/app.js',
                'assets/js/modules/drilldown-router.js',
                'assets/js/modules/vendor-detail/tabs.js',
                'assets/js/modules/vendor-detail/composition.js',
                'assets/js/modules/vendor-detail/charts.js',
                'assets/js/modules/vendor-detail/index.js',
                'assets/js/modules/order-detail/index.js',
                'assets/js/modules/invoice-detail/index.js',
                'assets/js/modules/customer-detail/index.js',
                'assets/js/modules/orders/page-drilldown.js',
                'assets/js/modules/invoices/page-drilldown.js',
            ],
        ],

        'mix-faturado' => [
            'css'     => [],
            'js_head' => ['assets/js/chart.umd.js'],
            'js_body' => [
                'assets/js/mag-chart-defaults.js',
                'assets/js/core/chart-factory.js',
                'assets/js/app.js',
                'assets/js/modules/drilldown-router.js',
                'assets/js/modules/invoice-detail/index.js',
                'assets/js/modules/order-detail/index.js',
                'assets/js/modules/customer-detail/index.js',
                'assets/js/modules/vendor-detail/tabs.js',
                'assets/js/modules/vendor-detail/composition.js',
                'assets/js/modules/vendor-detail/charts.js',
                'assets/js/modules/vendor-detail/index.js',
                'assets/js/modules/product-detail/tabs.js',
                'assets/js/modules/product-detail/charts.js',
                'assets/js/modules/product-detail/index.js',
                'assets/js/modules/products/page-drilldown.js',
            ],
        ],

        'financeiro' => [
            'css'     => ['assets/css/main.css', 'assets/css/finance-realizado.css'],
            'js_head' => ['assets/js/chart.umd.js'],
            'js_body' => [
                'assets/js/mag-chart-defaults.js',
                'assets/js/core/chart-factory.js',
                'assets/js/modules/finance/charts.js',
                'assets/js/finance.js',
                'assets/js/modules/finance/page-drilldown.js',
                'assets/js/modules/finance/visual-analytics.js',
                'assets/js/modules/finance/collection-by-vendor.js',
                'assets/js/modules/finance/collection-governance.js',
            ],
        ],

        'rota-comercial' => [
            'css'     => ['assets/css/flight-plan-clean.css'],
            'js_head' => ['assets/js/chart.umd.js'],
            'js_body' => [
                'assets/js/modules/drilldown-router.js',
                'assets/js/modules/flight-plan/formatters.js',
                'assets/js/modules/flight-plan/dom.js',
                'assets/js/modules/flight-plan/state.js',
                'assets/js/modules/flight-plan/api.js',
                'assets/js/modules/flight-plan/filters.js',
                'assets/js/modules/flight-plan/kpis.js',
                'assets/js/modules/flight-plan/charts.js',
                'assets/js/modules/flight-plan/vendor-table.js',
                'assets/js/modules/flight-plan/alerts.js',
                'assets/js/modules/flight-plan/index.js',
                'assets/js/modules/vendor-detail/tabs.js',
                'assets/js/modules/vendor-detail/composition.js',
                'assets/js/modules/vendor-detail/charts.js',
                'assets/js/modules/vendor-detail/index.js',
                'assets/js/flight-plan-v2.js',
            ],
        ],

        'geografia' => [
            'css'     => ['assets/css/biomie-map.css'],
            'js_head' => ['assets/js/chart.umd.js'],
            'js_body' => [
                'assets/js/geo-normalizer.js',
                'assets/js/biomie-map.js',
                'assets/js/biomie-map-panel.js',
                'assets/js/modules/drilldown-router.js',
                'assets/js/modules/invoice-detail/index.js',
                'assets/js/modules/order-detail/index.js',
                'assets/js/modules/customer-detail/index.js',
                'assets/js/modules/vendor-detail/tabs.js',
                'assets/js/modules/vendor-detail/composition.js',
                'assets/js/modules/vendor-detail/charts.js',
                'assets/js/modules/vendor-detail/index.js',
                'assets/js/modules/product-detail/tabs.js',
                'assets/js/modules/product-detail/charts.js',
                'assets/js/modules/product-detail/index.js',
                'assets/js/modules/geo-detail/tabs.js',
                'assets/js/modules/geo-detail/charts.js',
                'assets/js/modules/geo-detail/index.js',
            ],
        ],

        'omie' => [
            'css'     => [],
            'js_head' => [],
            'js_body' => ['assets/js/omie-status.js'],
        ],

        'conciliacao' => [
            'css'     => [],
            'js_head' => [],
            'js_body' => ['assets/js/reconciliacao.js'],
        ],

        'observabilidade' => [
            'css'     => [],
            'js_head' => [],
            'js_body' => ['assets/js/observability.js'],
        ],

        'admin' => [
            'css'     => ['assets/css/admin-insights.css'],
            'js_head' => [],
            'js_body' => [],
        ],
    ];

    /**
     * Retorna a lista completa de CSS para uma página.
     * Base + específico da página.
     *
     * @return list<string>
     */
    public static function cssForPage(string $pageKey): array
    {
        $specific = self::PAGE_ASSETS[$pageKey]['css'] ?? [];
        return array_values(array_unique(array_merge(self::BASE_CSS, $specific)));
    }

    /**
     * Retorna os JS de <head> (bloqueantes) para a página.
     * Apenas o que precisa estar antes do DOM (ex: Chart.js).
     *
     * @return list<string>
     */
    public static function jsHeadForPage(string $pageKey): array
    {
        return array_values(array_unique(self::PAGE_ASSETS[$pageKey]['js_head'] ?? []));
    }

    /**
     * Retorna os JS para injetar antes de </body> (defer automático).
     * Base + específico da página.
     *
     * @return list<string>
     */
    public static function jsBodyForPage(string $pageKey): array
    {
        $specific = self::PAGE_ASSETS[$pageKey]['js_body'] ?? [];
        return array_values(array_unique(array_merge(self::BASE_JS, $specific)));
    }

    /**
     * Gera a string de versão de um asset baseada em filemtime.
     * Fallback para timestamp fixo se o arquivo não existir.
     */
    public static function version(string $rel): string
    {
        return Asset::version($rel);
    }

    /**
     * Gera tags <link rel="stylesheet"> para o CSS de uma página.
     */
    public static function renderCssTags(string $pageKey): string
    {
        $tags = [];
        foreach (self::cssForPage($pageKey) as $path) {
            $tags[] = sprintf('<link rel="stylesheet" href="%s" />', htmlspecialchars(Asset::url($path), ENT_QUOTES, 'UTF-8')); 
        }
        return implode("\n  ", $tags);
    }

    /**
     * Gera tags <script> para o JS de <head> (sem defer).
     */
    public static function renderJsHeadTags(string $pageKey): string
    {
        $tags = [];
        foreach (self::jsHeadForPage($pageKey) as $path) {
            $tags[] = sprintf('<script src="%s"></script>', htmlspecialchars(Asset::url($path), ENT_QUOTES, 'UTF-8')); 
        }
        return implode("\n  ", $tags);
    }

    /**
     * Gera tags <script defer> para o JS de body.
     */
    public static function renderJsBodyTags(string $pageKey): string
    {
        $tags = [];
        foreach (self::jsBodyForPage($pageKey) as $path) {
            $tags[] = sprintf('<script src="%s" defer></script>', htmlspecialchars(Asset::url($path), ENT_QUOTES, 'UTF-8')); 
        }
        return implode("\n  ", $tags);
    }

    /**
     * Verifica se um determinado asset já está declarado em uma string HTML.
     */
    public static function alreadyPresent(string $html, string $assetPath): bool
    {
        return stripos($html, $assetPath) !== false;
    }
}

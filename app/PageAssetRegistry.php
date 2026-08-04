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
    /** Runtime de contexto: precisa carregar antes de qualquer script inline/fetch. */
    private const BASE_JS_HEAD = [
        'assets/js/tenant-tab-context.js',
    ];

    /** CSS carregado em todas as páginas (além do base do layout) */
    private const BASE_CSS = [
        'assets/css/shell-index.css',
        'assets/css/module-pages.css',
        'assets/css/mag-clean.css',
        'assets/css/mag-adminlte-clean.css',
        'assets/css/mag-mobile.css',
        'assets/css/mag-mobile-premium.css', // Fase R — visual premium mobile
        'assets/css/filters.css',          // Fase Q — motor global de filtros
        'assets/css/context-filters.css',  // filtros compactos do header
        'assets/css/global-search.css',    // Busca global (omnibox do topbar)
        'assets/css/biomie-drawer.css',    // EST.R1 Fase 0.3 — padrão global de drawers
        'assets/css/commercial-documents.css',
        'assets/css/commercial-language.css',
        'assets/css/commercial-card-help.css',
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
        'assets/js/commercial-language.js',
        'assets/js/commercial-card-help-loader.js',
        'assets/js/vendor-filter.js', // seletor global de vendedor (por empresa)
        'assets/js/context-filters.js', // periodo + filtros contextuais no header
        'assets/js/global-search.js', // busca global (omnibox do topbar)
        'assets/js/modules/drilldown-router.js', // drawers ricos disponiveis a partir de qualquer pagina
    ];

    /** Drawers carregados pelo drilldown-router apenas quando o usuario abre um detalhe. */
    private const LAZY_DRILLDOWN_ASSETS = [
        'assets/js/modules/vendor-detail/tabs.js',
        'assets/js/modules/vendor-detail/composition.js',
        'assets/js/modules/vendor-detail/charts.js',
        'assets/js/modules/vendor-detail/index.js',
        'assets/js/modules/order-detail/index.js',
        'assets/js/modules/invoice-detail/index.js',
        'assets/js/modules/customer-detail/index.js',
        'assets/js/modules/product-detail/tabs.js',
        'assets/js/modules/product-detail/charts.js',
        'assets/js/modules/product-detail/index.js',
        'assets/js/modules/geo-detail/tabs.js',
        'assets/js/modules/geo-detail/charts.js',
        'assets/js/modules/geo-detail/index.js',
    ];

    /** Recursos financeiros carregados somente quando a respectiva area entra em uso. */
    private const LAZY_FINANCE_ASSETS = [
        'background' => [
            'assets/js/pwa.js',
            'assets/js/cache-refresh.js',
            'assets/js/commercial-language.js',
        ],
        'charts' => [
            'assets/js/chart.umd.js',
            'assets/js/mag-chart-defaults.js',
            'assets/js/core/chart-factory.js',
            'assets/js/modules/finance/charts.js',
            'assets/js/modules/finance/visual-analytics.js',
        ],
        'receivables' => [
            'assets/js/core/request-controller.js',
            'assets/js/core/filter-state.js',
            'assets/js/core/filter-url.js',
            'assets/js/core/filter-chips.js',
            'assets/js/core/server-table.js',
            'assets/js/modules/finance/page-drilldown.js',
            'assets/js/modules/finance/product-payment-methods.js',
        ],
        'collection' => [
            'assets/js/modules/finance/collection-by-vendor.js',
            'assets/js/modules/finance/collection-governance.js',
        ],
    ];

    /** Recursos do mapa carregados quando o canvas se aproxima da viewport. */
    private const LAZY_GEO_ASSETS = [
        'core' => [
            'assets/js/chart.umd.js',
            'assets/js/mag-chart-defaults.js',
            'assets/js/core/chart-factory.js',   // define window.BIomieCharts: paridade com demais paginas (charts do mapa)
            'assets/js/geo-normalizer.js',
            'assets/js/biomie-map-advanced.js',
            'assets/js/biomie-map-panel.js',
            'assets/js/biomie-map.js', // Asset::url serve o .min quando atualizado
        ],
        'background' => [
            'assets/js/pwa.js',
            'assets/js/cache-refresh.js',
            'assets/js/commercial-language.js',
        ],
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
            'css'     => [
                'assets/css/quote-calendar-widget.css',
                'assets/css/quote-priority.css',
            ],
            'js_head' => ['assets/js/chart.umd.js'],
            'js_body' => [
                'assets/js/modules/quote-priority-panel.js',      // "Ação de hoje"
                'assets/js/mag-chart-defaults.js',
                'assets/js/core/chart-factory.js',
                'assets/js/client_dossier.js',
                'assets/js/index_geo.js',
                'assets/js/modules/overview/overview-detail-drawer.js',
                'assets/js/modules/drilldown-router.js',
                'assets/js/modules/dashboard/page-drilldown.js', // Q.7 — extra filters
                'assets/js/modules/quote-calendar-widget.js',     // calendário Agenda na home
                'assets/js/app.js',
            ],
        ],

        'time-comercial' => [
            'css'     => ['assets/css/quote-calendar-widget.css'],
            'js_head' => ['assets/js/chart.umd.js'],
            'js_body' => [
                'assets/js/mag-chart-defaults.js',
                'assets/js/core/chart-factory.js',
                'assets/js/modules/quote-calendar-widget.js',     // calendário no drawer do vendedor
                'assets/js/app.js',
                'assets/js/modules/drilldown-router.js',
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
                'assets/js/modules/invoices/page-drilldown.js',
                'assets/js/commercial-channel-toggle.js', // toggle de operação (mixed)
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
                'assets/js/modules/products/page-drilldown.js',
            ],
        ],

        'fiscal' => [
            'css'     => ['assets/css/fiscal.css'],
            'js_head' => ['assets/js/chart.umd.js'],
            'js_body' => [
                'assets/js/mag-chart-defaults.js',
                'assets/js/fiscal.js',
            ],
        ],

        'financeiro' => [
            'css'     => ['assets/css/finance-legacy-utilities.css', 'assets/css/finance-realizado.css'],
            'js_head' => [],
            'js_body' => [
                'assets/js/finance.js',
                'assets/js/modules/finance/information-architecture.js',
                'assets/js/finance-feature-loader.js',
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
                'assets/js/flight-plan-v2.js',
                'assets/js/commercial-channel-toggle.js', // toggle de operação (mixed)
            ],
        ],

        'agenda-orcamentos' => [
            'css'     => ['assets/css/quote-agenda.css'],
            'js_head' => [],
            'js_body' => [
                'assets/js/modules/quote-agenda/index.js',
                'assets/js/commercial-channel-toggle.js', // toggle de operação (mixed)
            ],
        ],

        // Flag desligada: NENHUM asset é injetado — nem o JS inútil, nem o CSS.
        'agenda-orcamentos-inativa' => [
            'css'     => [],
            'js_head' => [],
            'js_body' => [],
        ],

        'rota-comercial-metas' => [
            'css'     => ['assets/css/flight-plan-clean.css'],
            'js_head' => [],
            'js_body' => ['assets/js/flight-plan-targets.js'],
        ],

        'geografia' => [
            'css'     => ['assets/css/biomie-map.css'],
            'js_head' => [],
            'js_body' => [
                'assets/js/geo-feature-loader.js',
                'assets/js/modules/drilldown-router.js',
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
            'css'     => ['assets/css/observability.css'],
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
     * @return list<string>
     */
    public static function pageKeys(): array
    {
        return array_keys(self::PAGE_ASSETS);
    }

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
        return array_values(array_unique(array_merge(
            self::BASE_JS_HEAD,
            self::PAGE_ASSETS[$pageKey]['js_head'] ?? [],
        )));
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
        $base = self::BASE_JS;
        if ($pageKey === 'financeiro') {
            $lazyOnFinance = array_flip([
                'assets/js/core/request-controller.js',
                'assets/js/core/filter-state.js',
                'assets/js/core/filter-url.js',
                'assets/js/core/filter-chips.js',
                'assets/js/core/server-table.js',
                'assets/js/pwa.js',
                'assets/js/cache-refresh.js',
                'assets/js/commercial-language.js',
            ]);
            $base = array_values(array_filter($base, static fn (string $path): bool => !isset($lazyOnFinance[$path])));
        }
        if ($pageKey === 'geografia') {
            $lazyOnGeo = array_flip([
                'assets/js/core/request-controller.js',
                'assets/js/core/filter-state.js',
                'assets/js/core/filter-url.js',
                'assets/js/core/filter-chips.js',
                'assets/js/core/server-table.js',
                'assets/js/pwa.js',
                'assets/js/cache-refresh.js',
                'assets/js/commercial-language.js',
            ]);
            $base = array_values(array_filter($base, static fn (string $path): bool => !isset($lazyOnGeo[$path])));
        }
        return array_values(array_unique(array_merge($base, $specific)));
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
     * Versao conjunta dos drawers sob demanda.
     *
     * Qualquer alteracao em um drawer muda a versao usada pelo roteador e
     * evita servir um modulo antigo pelo cache do navegador.
     */
    public static function lazyDrilldownVersion(): string
    {
        $versions = array_map(
            static fn (string $path): string => $path . ':' . Asset::version($path),
            self::LAZY_DRILLDOWN_ASSETS,
        );

        return substr(hash('sha256', implode('|', $versions)), 0, 16);
    }

    /**
     * @return array<string,list<string>>
     */
    public static function lazyFinanceAssets(): array
    {
        return self::LAZY_FINANCE_ASSETS;
    }

    public static function lazyFinanceVersion(): string
    {
        $parts = [];
        foreach (self::LAZY_FINANCE_ASSETS as $paths) {
            foreach ($paths as $path) {
                $absolute = Bootstrap::rootPath($path);
                $parts[] = $path . ':' . (is_file($absolute) ? (string) filemtime($absolute) : 'missing');
            }
        }

        return substr(hash('sha256', implode('|', $parts)), 0, 16);
    }

    /** @return array<string,list<string>> */
    public static function lazyGeoAssets(): array
    {
        return self::LAZY_GEO_ASSETS;
    }

    public static function lazyGeoVersion(): string
    {
        $parts = [];
        foreach (self::LAZY_GEO_ASSETS as $paths) {
            foreach ($paths as $path) {
                $parts[] = $path . ':' . Asset::version($path);
            }
        }
        return substr(hash('sha256', implode('|', $parts)), 0, 16);
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
            $lazyVersionAttribute = $path === 'assets/js/modules/drilldown-router.js'
                ? sprintf(' data-biomie-lazy-version="%s"', self::lazyDrilldownVersion())
                : '';
            $tags[] = sprintf(
                '<script src="%s" defer%s></script>',
                htmlspecialchars(Asset::url($path), ENT_QUOTES, 'UTF-8'),
                $lazyVersionAttribute,
            );
        }
        return implode("\n  ", $tags);
    }

    /**
     * Verifica se um determinado asset já está declarado em uma string HTML.
     */
    public static function alreadyPresent(string $html, string $assetPath): bool
    {
        // Casa pelo caminho completo (e pela variante .min) — matching por
        // substring do nome ("state", "charts"…) gerava falsos positivos e
        // podia tanto pular injeções necessárias quanto permitir duplicatas.
        $plain = preg_replace('/\.min\.(js|css)$/', '.$1', $assetPath) ?? $assetPath;
        $min   = preg_replace('/\.(js|css)$/', '.min.$1', $plain) ?? $plain;
        return stripos($html, $plain) !== false || stripos($html, $min) !== false;
    }
}

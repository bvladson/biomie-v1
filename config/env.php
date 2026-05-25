<?php

// ──────────────────────────────────────────────────────────────────────────────
// CONFIGURAÇÃO - BIomie
// ──────────────────────────────────────────────────────────────────────────────
// Segredos devem vir EXCLUSIVAMENTE de:
//   1. Variáveis de ambiente do sistema (Hostinger > PHP > Environment Variables)
//   2. Arquivo local config/.env.local.php (ignorado pelo git)
//
// Para configurar via arquivo local:
//   Copie config/env.example.php para config/.env.local.php e preencha os valores.
//
// NUNCA coloque credenciais reais neste arquivo — ele é versionado.
// ──────────────────────────────────────────────────────────────────────────────

$secrets = [];
$localEnvFile = __DIR__ . '/.env.local.php';
if (is_file($localEnvFile)) {
    $secrets = (array) (include $localEnvFile);
}

if (!function_exists('env')) {
    function env(string $key, string $fallback, array $secrets): string
    {
        // 1. Variável de ambiente do sistema
        $val = getenv($key);
        if ($val !== false && $val !== '') return $val;
        // 2. $_ENV / $_SERVER
        if (!empty($_ENV[$key])) return (string) $_ENV[$key];
        if (!empty($_SERVER[$key])) return (string) $_SERVER[$key];
        // 3. Arquivo local (.env.local.php)
        if (isset($secrets[$key]) && $secrets[$key] !== '') return (string) $secrets[$key];
        // 4. Fallback seguro (apenas valores não sensíveis)
        return $fallback;
    }
}

return [
    'app' => [
        'name'      => 'BIomie Comercial MAG',
        'version'   => env('APP_VERSION', 'dev', $secrets),
        'env'       => env('APP_ENV', 'production', $secrets),
        'timezone'  => env('APP_TIMEZONE', 'America/Fortaleza', $secrets),
        'debug'     => (bool) env('APP_DEBUG', '0', $secrets),
        'allow_sync_web' => (bool) env('BIOMIE_ALLOW_SYNC_WEB', '0', $secrets),
    ],
    'omie' => [
        'app_key'          => env('OMIE_APP_KEY', '', $secrets),
        'app_secret'       => env('OMIE_APP_SECRET', '', $secrets),
        'webhook_token'    => env('OMIE_WEBHOOK_TOKEN', '', $secrets),
        'webhook_allow_insecure_local' => (bool) env('BIOMIE_ALLOW_INSECURE_WEBHOOK_LOCAL', '0', $secrets),
        'default_page_size' => 100,
        'request_timeout'  => 20,
        'method_policy'    => [
            // Overrides opcionais por metodo Omie.
            // Exemplo:
            // 'ListarPedidos' => ['page_size_target' => 300],
        ],
        'endpoints'        => [
            'orders'              => 'https://app.omie.com.br/api/v1/produtos/pedido/',
            'invoices'            => 'https://app.omie.com.br/api/v1/produtos/nfconsultar/',
            'vendors'             => 'https://app.omie.com.br/api/v1/geral/vendedores/',
            'clients'             => 'https://app.omie.com.br/api/v1/geral/clientes/',
            'cities'              => 'https://app.omie.com.br/api/v1/geral/cidades/',
            'order_stages'        => 'https://app.omie.com.br/api/v1/produtos/pedidoetapas/',
            'orders_fat'          => 'https://app.omie.com.br/api/v1/produtos/pedidovendafat/',
            'accounts_receivable' => 'https://app.omie.com.br/api/v1/financas/contareceber/',
            'accounts'            => 'https://app.omie.com.br/api/v1/geral/contacorrente/',
            'fin_movements'       => 'https://app.omie.com.br/api/v1/financas/mf/',
            'fin_titles_search'   => 'https://app.omie.com.br/api/v1/financas/pesquisartitulos/',
            'fin_summary'         => 'https://app.omie.com.br/api/v1/financas/resumo/',
            'cash_budget'         => 'https://app.omie.com.br/api/v1/financas/caixa/',
        ],
    ],
    'analytics' => [
        'monthly_target'              => 350000.00,
        'cache_ttl_minutes'           => 60,
        'demo_mode_when_unconfigured' => true,
    ],
    'weather' => [
        'label' => env('WEATHER_LABEL', 'Fortaleza, CE', $secrets),
        'latitude' => (float) env('WEATHER_LATITUDE', '-3.7319', $secrets),
        'longitude' => (float) env('WEATHER_LONGITUDE', '-38.5267', $secrets),
        'timezone' => env('WEATHER_TIMEZONE', 'America/Fortaleza', $secrets),
        'cache_ttl_seconds' => (int) env('WEATHER_CACHE_TTL', '600', $secrets),
    ],
    'db' => [
        'driver'  => 'mysql',
        'host'    => env('DB_HOST', 'localhost', $secrets),
        'port'    => (int) env('DB_PORT', '3306', $secrets),
        'name'    => env('DB_NAME', '', $secrets),
        'user'    => env('DB_USER', '', $secrets),
        'pass'    => env('DB_PASS', '', $secrets),
        'charset' => 'utf8mb4',
        'timeout' => (int) env('DB_TIMEOUT', '5', $secrets),
    ],
    'flight_plan_db' => [
        'driver'  => 'mysql',
        'host'    => env('DB_HOST', 'localhost', $secrets),
        'port'    => (int) env('DB_PORT', '3306', $secrets),
        'name'    => env('DB_NAME', '', $secrets),
        'user'    => env('DB_USER', '', $secrets),
        'pass'    => env('DB_PASS', '', $secrets),
        'charset' => 'utf8mb4',
        'timeout' => (int) env('DB_TIMEOUT', '5', $secrets),
    ],
    'features' => [
        'flight_plan_v2' => true,
        // Camada canônica: ligar por módulo após primeiro sync bem-sucedido
        'canonical_reads_vendors' => (bool) env('CANONICAL_READS_VENDORS', '1', $secrets),
        'canonical_reads_orders' => (bool) env('CANONICAL_READS_ORDERS', '1', $secrets),
        'canonical_reads_overview' => (bool) env('CANONICAL_READS_OVERVIEW', '1', $secrets),
        'canonical_fiscal_ready' => (bool) env('CANONICAL_FISCAL_READY', '1', $secrets),
        'canonical_reads_finance_overview' => true,
        'operation_scope_enforced' => true,
    ],
    'flight_plan' => [
        'saturday_weight' => 0.33,
    ],
    'sync_pipeline' => [
        'webhook_dedupe_window_seconds' => (int) env('SYNC_WEBHOOK_DEDUPE_WINDOW_SECONDS', '300', $secrets),
        'hot_window_minutes' => [
            'commercial' => 120,
            'finance' => 1440,
        ],
        'delta_cadence_minutes' => [
            'commercial' => 5,
            'finance' => 15,
        ],
        'safety_window_minutes' => [
            'commercial' => 15,
            'finance' => 60,
        ],
        'reconcile_days' => (int) env('SYNC_RECONCILE_DAYS', '30', $secrets),
    ],
    'data_contract' => [
        'stale_after_minutes' => [
            'overview' => 180,
            'orders' => 180,
            'vendors' => 180,
            'finance_overview' => 360,
            'finance_titles' => 360,
            'reconciliation' => 720,
        ],
    ],
    'shadow_validation' => [
        'enabled' => (bool) env('SHADOW_VALIDATION_ENABLED', '1', $secrets),
        'sample_rate' => (float) env('SHADOW_VALIDATION_SAMPLE_RATE', '5', $secrets),
        'persist_matches' => (bool) env('SHADOW_VALIDATION_PERSIST_MATCHES', '1', $secrets),
        'endpoints' => [
            'overview' => [
                'enabled' => (bool) env('SHADOW_VALIDATION_OVERVIEW', '1', $secrets),
                'sample_rate' => (float) env('SHADOW_VALIDATION_OVERVIEW_RATE', '5', $secrets),
            ],
            'orders' => [
                'enabled' => (bool) env('SHADOW_VALIDATION_ORDERS', '1', $secrets),
                'sample_rate' => (float) env('SHADOW_VALIDATION_ORDERS_RATE', '5', $secrets),
            ],
            'vendors' => [
                'enabled' => (bool) env('SHADOW_VALIDATION_VENDORS', '1', $secrets),
                'sample_rate' => (float) env('SHADOW_VALIDATION_VENDORS_RATE', '5', $secrets),
            ],
            'finance_overview' => [
                'enabled' => (bool) env('SHADOW_VALIDATION_FINANCE_OVERVIEW', '1', $secrets),
                'sample_rate' => (float) env('SHADOW_VALIDATION_FINANCE_OVERVIEW_RATE', '5', $secrets),
            ],
            'finance_titles' => [
                'enabled' => (bool) env('SHADOW_VALIDATION_FINANCE_TITLES', '1', $secrets),
                'sample_rate' => (float) env('SHADOW_VALIDATION_FINANCE_TITLES_RATE', '5', $secrets),
            ],
            'reconciliation' => [
                'enabled' => (bool) env('SHADOW_VALIDATION_RECONCILIATION', '1', $secrets),
                'sample_rate' => (float) env('SHADOW_VALIDATION_RECONCILIATION_RATE', '5', $secrets),
            ],
        ],
    ],
    'unsplash' => [
        // Access key NOT stored here — read directly via getenv() in LoginUnsplashWallpaperService
        // to prevent it from appearing in config dumps or debug pages.
        'enabled'         => (bool) env('UNSPLASH_ENABLED', '1', $secrets),
        'utm_source'      => env('UNSPLASH_UTM_SOURCE', 'biomie_mag', $secrets),
        'query'           => env('UNSPLASH_QUERY', 'modern office,business workspace,corporate interior,analytics dashboard', $secrets),
        'cache_ttl_hours' => (int) env('UNSPLASH_CACHE_TTL_HOURS', '12', $secrets),
    ],
    'migrations' => [
        'enabled' => (bool) env('MIGRATIONS_ENABLED', '1', $secrets),
        'path' => env('MIGRATIONS_PATH', 'database/migrations', $secrets),
        'table' => env('MIGRATIONS_TABLE', 'biomie_schema_migrations', $secrets),
        'app_version' => env('APP_VERSION', 'dev', $secrets),
    ],
    'observability' => [
        'enabled' => (bool) env('OBSERVABILITY_ENABLED', '1', $secrets),
        'dashboard_default_hours' => (int) env('OBSERVABILITY_DASHBOARD_HOURS', '24', $secrets),
    ],
];

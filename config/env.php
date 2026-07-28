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

$quoteAgendaEnabledRaw = trim(env('BIOMIE_QUOTE_AGENDA_ENABLED', '', $secrets));
$quoteAgendaEnabled = in_array(
    strtolower($quoteAgendaEnabledRaw),
    ['1', 'true', 'yes', 'y', 'sim', 'on'],
    true
);

// BASE PATH do app (ex.: /biomie) — derivado do APP_URL para ficar SEMPRE
// consistente com ele. Sem isto, Auth::basePath cai na auto-detecção por
// dirname(SCRIPT_NAME), que num script de API vira "/biomie/api": aí o
// redirect pós-troca de contexto aponta para /biomie/api/index.php → 404 →
// loop de context_reset ao selecionar empresa (P0 2026-07-25). APP_BASE_PATH
// pode sobrepor para deploys em outro caminho.
$appUrlPath = (string) (parse_url(
    env('APP_URL', 'https://magmoveiscorporativos.com.br/biomie', $secrets),
    PHP_URL_PATH
) ?? '');
// Normaliza SEMPRE e trata o deploy na RAIZ: parse_url pode devolver '', '/',
// '/biomie' ou '/biomie/'. Depois do trim, um path que era só a barra vira '/',
// e '/' precisa virar '' — senão Auth::url('index.php') montaria '//index.php'.
$appUrlPath = '/' . trim($appUrlPath, '/');
if ($appUrlPath === '/') {
    $appUrlPath = '';
}
$appBasePath = env('APP_BASE_PATH', $appUrlPath, $secrets);

return [
    'app' => [
        'name'      => 'BIomie Comercial MAG',
        'version'   => env('APP_VERSION', 'dev', $secrets),
        'env'       => env('APP_ENV', 'production', $secrets),
        'timezone'  => env('APP_TIMEZONE', 'America/Fortaleza', $secrets),
        'debug'     => (bool) env('APP_DEBUG', '0', $secrets),
        'url'       => rtrim(env('APP_URL', 'https://magmoveiscorporativos.com.br/biomie', $secrets), '/'),
        'base_path' => $appBasePath,
        'allow_sync_web' => (bool) env('BIOMIE_ALLOW_SYNC_WEB', '0', $secrets),
        'secret_key' => env('BIOMIE_SECRET_KEY', '', $secrets),
    ],
    'omie' => [
        'app_key'          => env('OMIE_APP_KEY', '', $secrets),
        'app_secret'       => env('OMIE_APP_SECRET', '', $secrets),
        'webhook_token'    => env('OMIE_WEBHOOK_TOKEN', '', $secrets),
        'webhook_allow_insecure_local' => (bool) env('BIOMIE_ALLOW_INSECURE_WEBHOOK_LOCAL', '0', $secrets),
        'default_page_size' => 100,
        'request_timeout'  => 20,
        // Rate limit global: 1 chamada a cada 20s entre TODOS os métodos combinados.
        // A Omie tem janela cumulativa de ~20 chamadas por ~5 min (independente do método).
        // Com global=3/min: máximo 12 chamadas em 4min — bem abaixo do threshold.
        // Métodos lentos (ListarPedidos ~60s/resp) não sofrem atraso extra; apenas bursts de
        // métodos rápidos são controlados.
        'global_rate_limit_per_minute' => 3,
        'method_policy'    => [
            // Per-method throttle adicional para métodos de alto volume.
            'ListarPedidos'          => ['rate_limit_per_minute' => 5],
            'ListarNF'               => ['rate_limit_per_minute' => 5],
            'ListarEtapasPedido'     => ['rate_limit_per_minute' => 5],
            'ListarVendedores'       => ['rate_limit_per_minute' => 5],
            'ListarClientesResumido' => ['rate_limit_per_minute' => 5],
            'ListarClientes'         => ['rate_limit_per_minute' => 5],
            'ListarNotaEnt'          => ['rate_limit_per_minute' => 5],
            'ListarMovimentos'       => ['rate_limit_per_minute' => 5],
            'PesquisarLancamentos'   => ['rate_limit_per_minute' => 5],
            'ListarLancamentos'      => ['rate_limit_per_minute' => 5],
        ],
        'endpoints'        => [
            'orders'              => 'https://app.omie.com.br/api/v1/produtos/pedido/',
            'invoices'            => 'https://app.omie.com.br/api/v1/produtos/nfconsultar/',
            'purchase_entries'    => 'https://app.omie.com.br/api/v1/produtos/notaentrada/',
            'purchase_orders'     => 'https://app.omie.com.br/api/v1/produtos/pedidocompra/',
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
        // wait_timeout da SESSAO. O host gerenciado usa wait_timeout=20s, baixo demais
        // para jobs que intercalam chamadas Omie lentas com escritas no banco (causa
        // "2006 MySQL server has gone away"). 0 = usar default por contexto no Database
        // (CLI 300s, web 120s). Defina DB_SESSION_WAIT_TIMEOUT para sobrescrever ambos.
        'session_wait_timeout_seconds' => (int) env('DB_SESSION_WAIT_TIMEOUT', '0', $secrets),
        // Persistent connections are safe on PHP-FPM + local MySQL (Hostinger).
        // Disable with DB_PERSISTENT=0 in .env only if you see connection-state bugs.
        'persistent' => in_array(strtolower(env('DB_PERSISTENT', '1', $secrets)), ['1', 'true', 'yes', 'on'], true),
        'probe_on_pool_reuse' => in_array(strtolower(env('DB_POOL_PROBE_ON_REUSE', '1', $secrets)), ['1', 'true', 'yes', 'on'], true),
        'ssl_enabled' => in_array(strtolower(env('DB_SSL_ENABLED', '0', $secrets)), ['1', 'true', 'yes', 'on'], true),
        'ssl_ca' => env('DB_SSL_CA', '', $secrets),
        'ssl_verify_server_cert' => in_array(strtolower(env('DB_SSL_VERIFY_SERVER_CERT', '1', $secrets)), ['1', 'true', 'yes', 'on'], true),
    ],
    'ops' => [
        // Alerta operacional do ops_sentinel. Todos opcionais — sem config, o alerta
        // fica apenas no log + status file. Configure no .env.local.php para receber.
        'alert_webhook_url'         => env('OPS_ALERT_WEBHOOK_URL', '', $secrets),
        'alert_telegram_bot_token'  => env('OPS_ALERT_TELEGRAM_BOT_TOKEN', '', $secrets),
        'alert_telegram_chat_id'    => env('OPS_ALERT_TELEGRAM_CHAT_ID', '', $secrets),
        'dead_pipeline_hours'       => (int) env('OPS_DEAD_PIPELINE_HOURS', '3', $secrets),
        'stuck_run_minutes'         => (int) env('OPS_STUCK_RUN_MINUTES', '30', $secrets),
        'alert_cooldown_minutes'    => (int) env('OPS_ALERT_COOLDOWN_MINUTES', '120', $secrets),
        // Alertas de negocio (diretoria) — management_alerts.php.
        'board_user_id'             => (int) env('OPS_BOARD_USER_ID', '1', $secrets),   // usuario com acesso a todas as empresas
        'target_hit_pct'            => (float) env('OPS_TARGET_HIT_PCT', '100', $secrets),   // "meta batida"
        'target_gap_pct'            => (float) env('OPS_TARGET_GAP_PCT', '85', $secrets),    // projecao abaixo disso = gap
        'target_gap_min_day'        => (int) env('OPS_TARGET_GAP_MIN_DAY', '10', $secrets),  // so avalia gap apos esse dia do mes
        'overdue_alert_value'       => (float) env('OPS_OVERDUE_ALERT_VALUE', '50000', $secrets), // R$ vencidos p/ alertar
        'overdue_cooldown_days'     => (int) env('OPS_OVERDUE_COOLDOWN_DAYS', '7', $secrets),
        // Guard de isolamento multi-empresa rodado pelo OpsSentinel a cada ciclo (1=on).
        'isolation_guard_enabled'   => (int) env('OPS_ISOLATION_GUARD_ENABLED', '1', $secrets),
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
        // Persistent connections are safe on PHP-FPM + local MySQL (Hostinger).
        // Disable with DB_PERSISTENT=0 in .env only if you see connection-state bugs.
        'persistent' => in_array(strtolower(env('DB_PERSISTENT', '1', $secrets)), ['1', 'true', 'yes', 'on'], true),
        'probe_on_pool_reuse' => in_array(strtolower(env('DB_POOL_PROBE_ON_REUSE', '1', $secrets)), ['1', 'true', 'yes', 'on'], true),
        'ssl_enabled' => in_array(strtolower(env('DB_SSL_ENABLED', '0', $secrets)), ['1', 'true', 'yes', 'on'], true),
        'ssl_ca' => env('DB_SSL_CA', '', $secrets),
        'ssl_verify_server_cert' => in_array(strtolower(env('DB_SSL_VERIFY_SERVER_CERT', '1', $secrets)), ['1', 'true', 'yes', 'on'], true),
    ],
    // Banco de TESTES — usado APENAS pelas suítes quando APP_ENV=test. Herda host
    // e SSL do banco principal (mesma instância MySQL); só o nome/usuário/senha
    // mudam. O nome DEVE terminar em _test/_testing/_ci — o TestDatabaseGuard
    // recusa qualquer outra coisa. NUNCA aponte isto para o banco de produção.
    'test_db' => [
        'driver'  => 'mysql',
        'host'    => env('DB_TEST_HOST', env('DB_HOST', 'localhost', $secrets), $secrets),
        'port'    => (int) env('DB_TEST_PORT', env('DB_PORT', '3306', $secrets), $secrets),
        'name'    => env('DB_TEST_NAME', '', $secrets),
        'user'    => env('DB_TEST_USER', '', $secrets),
        'pass'    => env('DB_TEST_PASS', '', $secrets),
        'charset' => 'utf8mb4',
        'timeout' => (int) env('DB_TIMEOUT', '5', $secrets),
        'persistent' => false,   // testes: conexão limpa, sem pool persistente
        'probe_on_pool_reuse' => false,
        'ssl_enabled' => in_array(strtolower(env('DB_SSL_ENABLED', '0', $secrets)), ['1', 'true', 'yes', 'on'], true),
        'ssl_ca' => env('DB_SSL_CA', '', $secrets),
        'ssl_verify_server_cert' => in_array(strtolower(env('DB_SSL_VERIFY_SERVER_CERT', '1', $secrets)), ['1', 'true', 'yes', 'on'], true),
    ],
    'features' => [
        'flight_plan_v2' => true,
        // Contexto "Todas as operações" (varejo+licitação numa mesma empresa).
        // OFF por padrão: o código é compatível e a opção fica INVISÍVEL até o
        // dashboard consumir mixed corretamente (Fase D, Incremento 1B). Com OFF,
        // criar contexto mixed é RECUSADO — contextos varejo/licitação intactos.
        'mixed_company_context_enabled' => in_array(strtolower(env('BIOMIE_MIXED_COMPANY_CONTEXT_ENABLED', '0', $secrets)), ['1', 'true', 'yes', 'y', 'sim', 'on'], true),
        // Camada canônica: ligar por módulo após primeiro sync bem-sucedido
        'canonical_reads_vendors' => (bool) env('CANONICAL_READS_VENDORS', '1', $secrets),
        'canonical_reads_orders' => (bool) env('CANONICAL_READS_ORDERS', '1', $secrets),
        'canonical_reads_overview' => (bool) env('CANONICAL_READS_OVERVIEW', '1', $secrets),
        'canonical_fiscal_ready' => (bool) env('CANONICAL_FISCAL_READY', '1', $secrets),
        'canonical_reads_finance_overview' => true,
        'operation_scope_enforced' => true,
        'geo_readmodel_v2' => in_array(strtolower(env('BIOMIE_GEO_READMODEL_V2', '1', $secrets)), ['1', 'true', 'yes', 'y', 'sim', 'on'], true),
        'geo_single_payload' => in_array(strtolower(env('BIOMIE_GEO_SINGLE_PAYLOAD', '1', $secrets)), ['1', 'true', 'yes', 'y', 'sim', 'on'], true),
        'geo_performance_log' => in_array(strtolower(env('BIOMIE_GEO_PERFORMANCE_LOG', '1', $secrets)), ['1', 'true', 'yes', 'y', 'sim', 'on'], true),
        // Agenda de Orçamentos — plano de LEITURA separado das automações.
        //
        // BIOMIE_QUOTE_AGENDA_ENABLED ausente: a disponibilidade da tela/API é
        // decidida pela política `enabled` de CADA empresa. Isso preserva o
        // rollout já aprovado mesmo se um deploy não copiar o arquivo local.
        // Valor explícito 0: kill switch global (nega todas as empresas).
        // Valor explícito 1: permite somente empresas com política habilitada.
        //
        // Scanner, notificações, webhook e desativação automática continuam
        // opt-in e independentes abaixo; este fallback jamais inicia jobs.
        'quote_agenda_enabled' => $quoteAgendaEnabled,
        'quote_agenda_enabled_explicit' => $quoteAgendaEnabledRaw !== '',
        'quote_agenda_scanner_enabled' => in_array(strtolower(env('BIOMIE_QUOTE_AGENDA_SCANNER_ENABLED', '0', $secrets)), ['1', 'true', 'yes', 'y', 'sim', 'on'], true),
        'quote_agenda_notifications_enabled' => in_array(strtolower(env('BIOMIE_QUOTE_AGENDA_NOTIFICATIONS_ENABLED', '0', $secrets)), ['1', 'true', 'yes', 'y', 'sim', 'on'], true),
        // Fase 2 — webhook como GATILHO do refresh direcionado por pedido.
        // Desligada: o webhook segue só validando/persistindo/enfileirando o que
        // já fazia; nenhum job de refresh da Agenda é criado enquanto for '0'.
        'quote_agenda_webhook_refresh_enabled' => in_array(strtolower(env('BIOMIE_QUOTE_AGENDA_WEBHOOK_REFRESH_ENABLED', '0', $secrets)), ['1', 'true', 'yes', 'y', 'sim', 'on'], true),
        // Empresa piloto (slug) do refresh por webhook. Vazio = nenhuma.
        'quote_agenda_webhook_refresh_pilot' => trim(env('BIOMIE_QUOTE_AGENDA_WEBHOOK_REFRESH_PILOT', '', $secrets)),
        // Liberação da DESATIVAÇÃO AUTOMÁTICA (resolve/vanished). Desligada
        // (padrão): keep/retry seguem automáticos, mas resolve/vanished vindo de
        // QUALQUER origem automática (webhook, scanner OU manual) é RETIDO
        // (decisão real, applied=0) para validação manual — fecha a corrida
        // webhook×scanner. `cli --apply` é o único caminho deliberado e não é
        // retido. Ligar SÓ depois de comprovar a 1ª desativação aplicando o ID à
        // mão. Não bloqueia consulta, keep/retry, auditoria nem notificações.
        // Nome novo (comportamento não é mais só do webhook); aceita o antigo
        // BIOMIE_QUOTE_AGENDA_WEBHOOK_AUTO_APPLY como fallback compatível.
        'quote_agenda_auto_deactivation_enabled' => in_array(strtolower(env('BIOMIE_QUOTE_AGENDA_AUTO_DEACTIVATION_ENABLED', env('BIOMIE_QUOTE_AGENDA_WEBHOOK_AUTO_APPLY', '0', $secrets), $secrets)), ['1', 'true', 'yes', 'y', 'sim', 'on'], true),
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
        'retention_days' => [
            'spans' => (int) env('OBSERVABILITY_RETENTION_SPANS_DAYS', '30', $secrets),
            'metrics' => (int) env('OBSERVABILITY_RETENTION_METRICS_DAYS', '90', $secrets),
            'events' => (int) env('OBSERVABILITY_RETENTION_EVENTS_DAYS', '90', $secrets),
        ],
    ],
    'performance' => [
        // observe: registra chamadas Omie feitas em GET; enforce: bloqueia antes da rede.
        'omie_get_guard_mode' => env('BIOMIE_OMIE_GET_GUARD_MODE', 'observe', $secrets),
        'endpoint_slo_min_samples' => (int) env('BIOMIE_ENDPOINT_SLO_MIN_SAMPLES', '10', $secrets),
    ],
];

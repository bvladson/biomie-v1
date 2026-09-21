<?php
// Copie para .env.local.php e preencha com seus valores reais.
// Este arquivo NÃO deve conter credenciais reais.
return [
    // Em producao, APP_ENV deve ser "production" e OMIE_WEBHOOK_TOKEN e obrigatorio.
    // BIOMIE_ALLOW_INSECURE_WEBHOOK_LOCAL so pode ser "1" em ambiente local/dev controlado.
    'APP_ENV'        => 'local',
    'OMIE_APP_KEY'    => '',
    'OMIE_APP_SECRET' => '',
    'OMIE_WEBHOOK_TOKEN' => '',
    'BIOMIE_ALLOW_INSECURE_WEBHOOK_LOCAL' => '0',
    // Permite sync Omie direto em request web apenas em ambiente local/dev controlado.
    'BIOMIE_ALLOW_SYNC_WEB' => '0',
    // Comece com "observe"; use "enforce" apos eliminar chamadas Omie dos GETs.
    'BIOMIE_OMIE_GET_GUARD_MODE' => 'observe',
    'BIOMIE_ENDPOINT_SLO_MIN_SAMPLES' => '10',
    // Token administrativo usado somente por probes operacionais via header X-BIomie-Token.
    'BIOMIE_ADMIN_TOKEN' => '',
    // Chave exclusiva para criptografar credenciais por empresa.
    // Gere com: php -r "echo 'base64:'.base64_encode(random_bytes(32)), PHP_EOL;"
    'BIOMIE_SECRET_KEY' => '',
    'OBSERVABILITY_RETENTION_SPANS_DAYS' => '30',
    'OBSERVABILITY_RETENTION_METRICS_DAYS' => '90',
    'OBSERVABILITY_RETENTION_EVENTS_DAYS' => '90',
    // Opcional: caminho de um CA bundle PEM para PHP CLI/jobs.
    'OMIE_CA_BUNDLE' => '',
    'DB_HOST'         => 'localhost',
    'DB_PORT'         => '3306',
    'DB_NAME'         => '',
    'DB_USER'         => '',
    'DB_PASS'         => '',
    // Alvo esperado por ESTA máquina: production | legacy | other. Na máquina de
    // desenvolvimento, que lê a produção por túnel (tools/dev/db_tunnel.sh) com
    // usuário somente-leitura, use 'production'. bin/biomie aborta se não bater.
    'DB_EXPECTED_TARGET' => '',
    'DB_TIMEOUT'      => '5',
    // Ative somente apos medir estabilidade do provedor; o pool ja reutiliza a conexao no request.
    'DB_PERSISTENT'   => '0',
    // Probe SELECT 1 em toda reutilizacao dentro do mesmo request; normalmente desnecessario.
    'DB_POOL_PROBE_ON_REUSE' => '0',
    'DB_SSL_ENABLED'  => '0',
    'DB_SSL_CA'       => '',
    'DB_SSL_VERIFY_SERVER_CERT' => '1',
    'APP_DEBUG'       => '0',
    // Feature flag: imagens de produto via Omie (desabilitado por padrão)
    'BIOMIE_PRODUCT_IMAGES_ENABLED' => 'false',
    // Feature flags Geo ReadModel v2: mantenha desligado ate validar shadow/probes.
    'BIOMIE_GEO_READMODEL_V2' => '1',
    'BIOMIE_GEO_SINGLE_PAYLOAD' => '1',
    'BIOMIE_GEO_PERFORMANCE_LOG' => '1',

    // Agenda de Orcamentos (plano de leitura):
    //   ''/ausente = respeita somente a politica habilitada por empresa;
    //   '0'         = kill switch global explicito;
    //   '1'         = permite leitura apenas nas empresas com politica habilitada.
    // Scanner/notificacoes/webhook continuam desligados ate opt-in separado.
    'BIOMIE_QUOTE_AGENDA_ENABLED' => '',
    'BIOMIE_QUOTE_AGENDA_SCANNER_ENABLED' => '0',
    'BIOMIE_QUOTE_AGENDA_NOTIFICATIONS_ENABLED' => '0',
    'BIOMIE_QUOTE_AGENDA_WEBHOOK_REFRESH_ENABLED' => '0',

    // Unsplash — wallpaper dinâmico do login.
    // Obtenha a Access Key em https://unsplash.com/developers (nunca use a Secret Key aqui).
    'UNSPLASH_ENABLED'           => '1',
    'UNSPLASH_ACCESS_KEY'        => '',
    'UNSPLASH_UTM_SOURCE'        => 'biomie_mag',
    'UNSPLASH_QUERY'             => 'modern office,business workspace,corporate interior,office furniture,analytics dashboard',
    'UNSPLASH_CACHE_TTL_HOURS'   => '12',
];

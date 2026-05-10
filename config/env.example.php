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
    'DB_HOST'         => 'localhost',
    'DB_PORT'         => '3306',
    'DB_NAME'         => '',
    'DB_USER'         => '',
    'DB_PASS'         => '',
    'DB_TIMEOUT'      => '5',
    'APP_DEBUG'       => '0',
    // Feature flag: imagens de produto via Omie (desabilitado por padrão)
    'BIOMIE_PRODUCT_IMAGES_ENABLED' => 'false',
];

<?php

declare(strict_types=1);

/**
 * GET api/client_cache_reset.php
 *
 * Retorna instruções para o browser limpar seus caches locais.
 * Não executa nada no servidor — apenas informa o cliente do que fazer.
 * Deve ser chamado apenas por botão admin ou quando build for detectado como stale.
 */

use BIomie\Auth;
use BIomie\Http;
use BIomie\Support\Asset;

require __DIR__ . '/bootstrap.php';

Auth::requireModule('admin', true);

Http::noStoreJson();

$build = Asset::build();

echo json_encode([
    'ok'    => true,
    'build' => $build['version'] ?? '',
    'instructions' => [
        'clear_local_storage'    => true,
        'clear_session_storage'  => true,
        'clear_caches'           => true,
        'unregister_service_workers' => true,
    ],
    'note' => 'Execute window.BIomie.clearClientCacheAndReload() no console ou via botão admin para aplicar.',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

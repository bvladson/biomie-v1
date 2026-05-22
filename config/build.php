<?php

declare(strict_types=1);

return [
    // Atualize no deploy para invalidar Service Worker, caches e assets versionados.
    'version' => getenv('BIOMIE_BUILD_VERSION') ?: '20260518-geo-fix-cache1',
    'deployed_at' => getenv('BIOMIE_DEPLOYED_AT') ?: date('c', @filemtime(__FILE__) ?: time()),
    'cache_strategy' => 'html-api-no-store-assets-versioned-sw-assets-only',
];

<?php

declare(strict_types=1);

use BIomie\Http;

require __DIR__ . '/bootstrap.php';

Http::json([
    'ok' => true,
    'name' => $config->get('app.name'),
    'timestamp' => date(DATE_ATOM),
    'php' => PHP_VERSION,
]);

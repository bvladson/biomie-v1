<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Install\InstallController;
use BIomie\Install\InstallLogger;
use BIomie\Install\InstallService;

if ((string) getenv('BIOMIE_INSTALL_ENABLED') !== '1') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow', true);
    echo "Not found.\n";
    exit;
}

require_once dirname(__DIR__) . '/app/Bootstrap.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'BIomie\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = dirname(__DIR__) . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

date_default_timezone_set('America/Fortaleza');

try {
    $logger = new InstallLogger();
    $controller = new InstallController(new InstallService($logger), $logger);
    $controller->handle();
} catch (Throwable $exception) {
    try {
        (new InstallLogger())->error('installer_fatal_error', ['message' => $exception->getMessage()]);
    } catch (Throwable) {
        // Last-resort fallback: never expose technical details on screen.
    }

    http_response_code(500);
    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Instalador BIomie</title><link rel="stylesheet" href="assets/install.css"></head><body class="install-page"><main class="install-shell"><section class="install-card"><div class="install-alert error">Não foi possível abrir o instalador agora. Revise o log sanitizado em storage/logs/install.log.</div></section></main></body></html>';
}

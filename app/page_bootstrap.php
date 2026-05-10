<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Http\ErrorBoundary;
use BIomie\Logger;

require_once __DIR__ . '/Bootstrap.php';
Bootstrap::init();

$config = new Config();
$logger = new Logger(dirname(__DIR__) . '/storage/logs/biomie.log');

ErrorBoundary::registerPageHandlers([
    'route' => basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'page')),
    'handler' => 'app/page_bootstrap.php',
]);

Auth::boot($config, $logger);

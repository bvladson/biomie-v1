<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Http\ErrorBoundary;
use BIomie\Logger;
use BIomie\Support\TenantContext;
use BIomie\Support\TenantContextUnavailableException;

require_once __DIR__ . '/Bootstrap.php';
Bootstrap::init();

$config = new Config();
$logger = new Logger(dirname(__DIR__) . '/storage/logs/biomie.log');

ErrorBoundary::registerPageHandlers([
    'route' => basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'page')),
    'handler' => 'app/page_bootstrap.php',
]);
// Preserve privacy while allowing third-party assets (for example OSM tiles)
// to receive the origin required by their usage policy.
header('Referrer-Policy: strict-origin-when-cross-origin');

Auth::boot($config, $logger);

$pageScript = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));

// Únicas páginas autenticadas sem contexto: login/logout e a seleção inicial.
// Elas não consultam dados operacionais.
if ($pageScript === 'select_empresa.php') {
    Auth::requireLogin(false);
    TenantContext::bootContextSelector(Database::connect($config, 'db'));
    return;
}
if (in_array($pageScript, ['login.php', 'logout.php'], true)) {
    return;
}

// Páginas de negócio autenticam antes de resolver o tenant. Sem isto, uma
// visita anônima poderia cair num 403 de capacidade em vez de ir ao login.
Auth::requireLogin(false);

// Fail closed: uma página de negócio nunca cai silenciosamente na empresa padrão.
try {
    TenantContext::boot(Database::connect($config, 'db'));
    header('X-BIOMIE-Context: ' . TenantContext::contextId());
    header('Vary: Accept, X-BIOMIE-Context');
    header('Cache-Control: private, no-cache, must-revalidate, max-age=0');
} catch (TenantContextUnavailableException $exception) {
    if (Auth::isAuthenticated() && !headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('X-BIOMIE-Context-Invalid: 1');
        header('X-BIOMIE-Context-Reason: ' . $exception->getReasonCode());
        header('Location: ' . Auth::url('select_empresa.php?context_reset=1'), true, 303);
        exit;
    }
    throw $exception;
}

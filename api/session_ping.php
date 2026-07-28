<?php

declare(strict_types=1);

/**
 * Ping de sessao ultraleve.
 *
 * Usado pelo guard de bfcache (shell.js): quando uma pagina e restaurada pelo
 * botao voltar/avancar, o navegador nao reexecuta a hidratacao, entao este
 * endpoint confirma se a sessao ainda e valida. Retorna 401 quando deslogado,
 * para que o cliente forcao um reload e caia no login.
 *
 * Nao usa api/bootstrap.php de proposito: nao precisa de Omie/TenantContext/
 * analytics, apenas do boot de autenticacao.
 */

use BIomie\Auth;
use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Http;
use BIomie\Logger;
use BIomie\Support\TenantContext;
use BIomie\Support\TenantContextUnavailableException;

require_once dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$config = new Config();
$logger = new Logger(dirname(__DIR__) . '/storage/logs/biomie.log');

Auth::boot($config, $logger);

if (!Auth::isAuthenticated()) {
    Http::json(['ok' => false, 'authenticated' => false], 401);
}

try {
    TenantContext::boot(Database::connect($config, 'db'));
} catch (TenantContextUnavailableException $exception) {
    Http::json([
        'ok' => false,
        'authenticated' => true,
        'error' => $exception->getReasonCode(),
        'message' => $exception->getMessage(),
        'redirect' => Auth::url('select_empresa.php?context_reset=1'),
    ], $exception->getCode() > 0 ? $exception->getCode() : 403, [
        'X-BIOMIE-Context-Invalid' => '1',
        'X-BIOMIE-Context-Reason' => $exception->getReasonCode(),
        'X-BIOMIE-Context-Redirect' => Auth::url('select_empresa.php?context_reset=1'),
    ]);
}

Http::json(['ok' => true, 'authenticated' => true]);

<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Logger;

require_once __DIR__ . '/Bootstrap.php';
Bootstrap::init();

$config = new Config();
$logger = new Logger(dirname(__DIR__) . '/storage/logs/biomie.log');

// Error handler — em produção esconde detalhes; em debug mostra tudo
set_exception_handler(function (Throwable $e) use ($config) {
    $debug = (bool) ($config->get('app.debug', false));
    error_log('BIomie FATAL: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Erro | BIomie</title>';
    echo '<style>body{font-family:system-ui,sans-serif;display:grid;place-items:center;min-height:100vh;margin:0;background:#f6f3f2}';
    echo '.box{max-width:500px;padding:32px;background:#fff;border-radius:20px;border:1px solid #e5e1e1;text-align:center}';
    echo 'h1{color:#96140a;font-size:1.3rem}a{color:#96140a}pre{text-align:left;font-size:11px;color:#828282;background:#f6f3f2;padding:12px;border-radius:8px;overflow-x:auto;white-space:pre-wrap}</style>';
    echo '</head><body><div class="box">';
    echo '<h1>Erro interno</h1>';
    if ($debug) {
        echo '<p><strong>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</strong></p>';
        echo '<pre>' . htmlspecialchars($e->getFile() . ':' . $e->getLine(), ENT_QUOTES, 'UTF-8') . '</pre>';
    } else {
        echo '<p>Algo deu errado. Tente novamente em alguns segundos.</p>';
    }
    echo '<p><a href="login.php">Ir para o login</a></p>';
    echo '</div></body></html>';
    exit;
});

Auth::boot($config, $logger);

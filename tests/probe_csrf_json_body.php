<?php

declare(strict_types=1);

/**
 * Sonda HTTP do guard de CSRF — servida por `php -S` a partir de
 * {@see run_csrf_json_body_contract.php}.
 *
 * NÃO é um endpoint da aplicação: não há rota para ela, não toca banco, sessão
 * real nem tenant. Existe por uma razão só — `php://input` NÃO é populado no
 * SAPI de linha de comando, então a única forma honesta de provar que um token
 * enviado no CORPO JSON é aceito (e que um multipart não tem o corpo consumido)
 * é fazer uma requisição HTTP de verdade.
 *
 * O token que a "sessão" deveria ter vem por query (?expected=…) e é plantado
 * direto em $_SESSION, que é o que Auth::verifyCsrf lê.
 *
 * Saída: "ACEITOU" (200) quando o guard deixa passar; o JSON de erro do próprio
 * Http::json (419) quando recusa.
 */

require_once dirname(__DIR__) . '/app/Bootstrap.php';

\BIomie\Bootstrap::init();

$_SESSION = ['biomie_csrf' => (string) ($_GET['expected'] ?? '')];

\BIomie\Http::enforceCsrf(null);

header('Content-Type: text/plain; charset=utf-8');
echo 'ACEITOU';

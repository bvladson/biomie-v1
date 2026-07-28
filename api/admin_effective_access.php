<?php

declare(strict_types=1);

/**
 * ACESSO EFETIVO — o que um usuário realmente enxerga, já resolvido.
 *
 *   GET ?user_id=N
 *
 * Somente admin (users.manage). SOMENTE LEITURA: não concede nem revoga nada —
 * apenas calcula e explica a permissão resultante das quatro camadas (papel
 * global, papel por empresa, canais, visibilidade de vendedores).
 */

use BIomie\Admin\EffectiveAccessService;
use BIomie\Auth;
use BIomie\Config;
use BIomie\Database;
use BIomie\Http;
use BIomie\RequestContext;

require __DIR__ . '/bootstrap.php';

Auth::requireCapability('users.manage', true);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    Http::methodNotAllowed(['GET']);
}

$userId = (int) Http::query('user_id', '0');
if ($userId <= 0) {
    Http::validationError('Parâmetro "user_id" é obrigatório.');
}

try {
    $config = new Config();
    $conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo  = Database::connect($config, $conn);

    $result = (new EffectiveAccessService($pdo))->forUser($userId);

    if (($result['ok'] ?? false) === false) {
        Http::json([
            'ok' => false,
            'error' => $result['error'] ?? 'unknown',
            'message' => 'Usuário não encontrado.',
            'request_id' => RequestContext::id(),
        ], 404);
    }

    Http::json($result + ['request_id' => RequestContext::id()]);
} catch (Throwable $e) {
    Http::json([
        'ok' => false,
        'error' => 'internal',
        'message' => 'Não foi possível calcular o acesso efetivo.',
        'request_id' => RequestContext::id(),
    ], 500);
}

<?php

/**
 * api/user_company_role.php — Gerencia role_override por empresa para um usuário.
 *
 * Permite que admins definam um role específico por empresa, sobrescrevendo
 * o role global do usuário nas verificações de capability.
 *
 * Operações:
 *   GET  ?user_id=N             — lista vínculos usuário×empresa com role_override
 *   POST { action:"set",   user_id, company_id, role }   — define/atualiza override
 *   POST { action:"clear", user_id, company_id }          — remove override
 *
 * Permissão: users.manage
 */

declare(strict_types=1);

use BIomie\Acl\Roles;
use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\RequestContext;

require __DIR__ . '/bootstrap.php';

Auth::requireCapability('users.manage', true);

try {
    $conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo  = Database::connect($config, $conn);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        handleGet($pdo);
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        Http::json(['ok' => false, 'message' => 'Método não permitido. Use GET ou POST.', 'request_id' => RequestContext::id()], 405);
    }

    $body = (array) (json_decode((string) file_get_contents('php://input'), true) ?? []);

    if (!Auth::verifyCsrf((string) ($body['csrf_token'] ?? ''))) {
        Http::json(['ok' => false, 'message' => 'Token CSRF inválido.', 'request_id' => RequestContext::id()], 403);
    }

    $action    = strtolower(trim((string) ($body['action'] ?? '')));
    $userId    = (int) ($body['user_id']    ?? 0);
    $companyId = (int) ($body['company_id'] ?? 0);

    if ($userId <= 0 || $companyId <= 0) {
        Http::json(['ok' => false, 'message' => 'user_id e company_id são obrigatórios.', 'request_id' => RequestContext::id()], 400);
    }

    // Verify the user-company link exists
    $linkCheck = $pdo->prepare(
        'SELECT 1 FROM biomie_user_companies uc
         JOIN biomie_users u ON u.id = uc.user_id
         JOIN biomie_companies c ON c.id = uc.company_id
         WHERE uc.user_id = :uid AND uc.company_id = :cid AND u.is_active = 1 AND c.ativo = 1
         LIMIT 1'
    );
    $linkCheck->execute(['uid' => $userId, 'cid' => $companyId]);
    if (!$linkCheck->fetch()) {
        Http::json(['ok' => false, 'message' => 'Vínculo usuário×empresa não encontrado ou inativo.', 'request_id' => RequestContext::id()], 404);
    }

    // Prevent modifying your own role to avoid privilege escalation
    $actorId = (int) (Auth::currentUser()['id'] ?? 0);
    if ($actorId === $userId) {
        Http::json(['ok' => false, 'message' => 'Não é permitido alterar o próprio role_override.', 'request_id' => RequestContext::id()], 403);
    }

    if ($action === 'set') {
        $role = strtolower(trim((string) ($body['role'] ?? '')));
        if (!in_array($role, Roles::VALID_ROLES, true)) {
            Http::json(['ok' => false, 'message' => 'role inválido. Valores aceitos: ' . implode(', ', Roles::VALID_ROLES), 'request_id' => RequestContext::id()], 400);
        }

        $stmt = $pdo->prepare(
            'UPDATE biomie_user_companies SET role_override = :role
             WHERE user_id = :uid AND company_id = :cid'
        );
        $stmt->execute(['role' => $role, 'uid' => $userId, 'cid' => $companyId]);

        Auth::repository()->recordLog(
            'user_company_role_set',
            $actorId,
            (string) (Auth::currentUser()['username'] ?? ''),
            (string) ($_SERVER['REQUEST_URI'] ?? 'api/user_company_role.php'),
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            ['user_id' => $userId, 'company_id' => $companyId, 'role_override' => $role],
        );

        Http::json([
            'ok'         => true,
            'message'    => "role_override definido como '{$role}'.",
            'user_id'    => $userId,
            'company_id' => $companyId,
            'role'       => $role,
            'request_id' => RequestContext::id(),
        ]);
    } elseif ($action === 'clear') {
        $stmt = $pdo->prepare(
            'UPDATE biomie_user_companies SET role_override = NULL
             WHERE user_id = :uid AND company_id = :cid'
        );
        $stmt->execute(['uid' => $userId, 'cid' => $companyId]);

        Auth::repository()->recordLog(
            'user_company_role_cleared',
            $actorId,
            (string) (Auth::currentUser()['username'] ?? ''),
            (string) ($_SERVER['REQUEST_URI'] ?? 'api/user_company_role.php'),
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            ['user_id' => $userId, 'company_id' => $companyId],
        );

        Http::json([
            'ok'         => true,
            'message'    => 'role_override removido — usuário voltará a usar o role global.',
            'user_id'    => $userId,
            'company_id' => $companyId,
            'request_id' => RequestContext::id(),
        ]);
    } else {
        Http::json(['ok' => false, 'message' => "action inválida: '{$action}'. Use 'set' ou 'clear'.", 'request_id' => RequestContext::id()], 400);
    }
} catch (\Throwable $e) {
    $logger->error('user_company_role falhou.', ['message' => $e->getMessage()]);
    Http::json(['ok' => false, 'message' => 'Falha ao processar operação.', 'request_id' => RequestContext::id()], 500);
}

function handleGet(PDO $pdo): void
{
    $userId = (int) Http::query('user_id', '0');

    $sql = 'SELECT u.id AS user_id, u.username, u.full_name, u.role AS global_role,
                   c.id AS company_id, c.slug, c.nome_fantasia,
                   uc.can_varejo, uc.can_licitacao, uc.is_default,
                   uc.role_override,
                   COALESCE(uc.role_override, u.role) AS effective_role
            FROM biomie_user_companies uc
            JOIN biomie_users u ON u.id = uc.user_id
            JOIN biomie_companies c ON c.id = uc.company_id
            WHERE u.is_active = 1 AND c.ativo = 1';

    if ($userId > 0) {
        $stmt = $pdo->prepare($sql . ' AND uc.user_id = :uid ORDER BY c.id');
        $stmt->execute(['uid' => $userId]);
    } else {
        $stmt = $pdo->query($sql . ' ORDER BY u.username, c.id');
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    Http::json([
        'ok'         => true,
        'rows'       => array_map(static function (array $row): array {
            return [
                'user_id'       => (int) $row['user_id'],
                'username'      => (string) $row['username'],
                'full_name'     => (string) $row['full_name'],
                'global_role'   => (string) $row['global_role'],
                'company_id'    => (int) $row['company_id'],
                'slug'          => (string) $row['slug'],
                'name'          => (string) $row['nome_fantasia'],
                'can_varejo'    => (bool) $row['can_varejo'],
                'can_licitacao' => (bool) $row['can_licitacao'],
                'is_default'    => (bool) $row['is_default'],
                'role_override' => $row['role_override'] !== null ? (string) $row['role_override'] : null,
                'effective_role'=> (string) $row['effective_role'],
            ];
        }, $rows),
        'total'      => count($rows),
        'request_id' => RequestContext::id(),
    ]);
}

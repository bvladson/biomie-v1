<?php

/**
 * api/company_access.php — Matriz de acesso usuário × empresa (RBAC por empresa).
 *
 * Permite a admins controlar quais usuários acessam quais empresas, com canais
 * (varejo/licitação), papel por empresa (role_override) e empresa padrão.
 *
 *   GET                                   — matriz completa (usuários, empresas, vínculos)
 *   POST { action:"grant",  user_id, company_id, can_varejo, can_licitacao, role?, is_default? }
 *   POST { action:"revoke", user_id, company_id }
 *
 * Segurança: capability users.manage. No PRÓPRIO acesso o admin só ajusta os
 * canais (varejo/licitação) de empresas que já acessa, com confirm_self e log
 * company_access_self_channels; não remove o próprio acesso, não se dá empresa
 * nova, não muda o próprio papel nem a empresa padrão (anti-escalada).
 * Isolamento garantido pela própria natureza do vínculo.
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

/** @var \BIomie\Config $config definido em api/bootstrap.php */
/** @var \BIomie\Logger $logger definido em api/bootstrap.php */

Auth::requireCapability('users.manage', true);

try {
    $conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo  = Database::connect($config, $conn);
    $actorId = (int) (Auth::currentUser()['id'] ?? 0);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $companies = $pdo->query(
            'SELECT id, slug, nome_fantasia, canal_padrao FROM biomie_companies WHERE ativo = 1 ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $users = $pdo->query(
            'SELECT id, username, full_name, role, is_active FROM biomie_users WHERE is_active = 1 ORDER BY full_name, username'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $links = $pdo->query(
            'SELECT user_id, company_id, can_varejo, can_licitacao, is_default, role_override, default_canal
               FROM biomie_user_companies'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $matrix = [];
        foreach ($links as $l) {
            $matrix[(int) $l['user_id']][(int) $l['company_id']] = [
                'can_varejo'    => (bool) $l['can_varejo'],
                'can_licitacao' => (bool) $l['can_licitacao'],
                'is_default'    => (bool) $l['is_default'],
                'role_override' => $l['role_override'] !== null ? (string) $l['role_override'] : null,
            ];
        }

        Http::json([
            'ok' => true,
            'companies' => $companies,
            'users' => $users,
            'matrix' => $matrix,
            'roles' => Roles::VALID_ROLES,
            'actor_id' => $actorId,
            'request_id' => RequestContext::id(),
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        Http::json(['ok' => false, 'message' => 'Método não permitido.', 'request_id' => RequestContext::id()], 405);
    }

    $body = (array) (json_decode((string) file_get_contents('php://input'), true) ?? []);
    if (!Auth::verifyCsrf((string) ($body['csrf_token'] ?? ''))) {
        Http::json(['ok' => false, 'message' => 'Token CSRF inválido.', 'request_id' => RequestContext::id()], 403);
    }

    $action    = strtolower(trim((string) ($body['action'] ?? '')));
    $userId    = (int) ($body['user_id'] ?? 0);
    $companyId = (int) ($body['company_id'] ?? 0);

    if ($userId <= 0 || $companyId <= 0) {
        Http::json(['ok' => false, 'message' => 'user_id e company_id são obrigatórios.', 'request_id' => RequestContext::id()], 400);
    }
    // Próprio acesso: o bloqueio total deixava o ÚNICO admin sem como corrigir o
    // próprio escopo (caso Uni Design, 29/09/2026: preso em "Licitação" com as
    // vendas em varejo). Regra: pode ajustar só os CANAIS (varejo/licitação) de
    // empresas que JÁ acessa, com confirmação explícita e log próprio. Continua
    // proibido: remover o próprio acesso, se dar uma empresa nova, mudar o próprio
    // papel ou a empresa padrão (anti-escalada).
    $selfLink = null;
    if ($userId === $actorId) {
        if ($action !== 'grant') {
            Http::json(['ok' => false, 'message' => 'Por segurança, você não pode remover o seu próprio acesso.', 'request_id' => RequestContext::id()], 403);
        }
        $own = $pdo->prepare(
            'SELECT can_varejo, can_licitacao, role_override, is_default
               FROM biomie_user_companies WHERE user_id = :uid AND company_id = :cid'
        );
        $own->execute([':uid' => $actorId, ':cid' => $companyId]);
        $selfLink = $own->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($selfLink === null) {
            Http::json(['ok' => false, 'message' => 'Você não pode se dar acesso a uma empresa nova; peça a outro administrador.', 'request_id' => RequestContext::id()], 403);
        }
        if (empty($body['confirm_self'])) {
            Http::json(['ok' => false, 'error' => 'self_confirmation_required',
                'message' => 'Confirme que está alterando os seus próprios canais.', 'request_id' => RequestContext::id()], 409);
        }
    }

    // Valida que user e empresa existem/ativos
    $chk = $pdo->prepare(
        'SELECT (SELECT COUNT(*) FROM biomie_users WHERE id = :uid AND is_active = 1) AS u,
                (SELECT COUNT(*) FROM biomie_companies WHERE id = :cid AND ativo = 1) AS c'
    );
    $chk->execute([':uid' => $userId, ':cid' => $companyId]);
    $exists = $chk->fetch(PDO::FETCH_ASSOC) ?: ['u' => 0, 'c' => 0];
    if ((int) $exists['u'] === 0 || (int) $exists['c'] === 0) {
        Http::json(['ok' => false, 'message' => 'Usuário ou empresa inexistente/inativo.', 'request_id' => RequestContext::id()], 404);
    }

    if ($action === 'grant') {
        $canVarejo    = !empty($body['can_varejo']) ? 1 : 0;
        $canLicitacao = !empty($body['can_licitacao']) ? 1 : 0;
        if ($canVarejo === 0 && $canLicitacao === 0) {
            $canVarejo = 1; // ao menos um canal
        }
        $isDefault = !empty($body['is_default']) ? 1 : 0;
        $role = strtolower(trim((string) ($body['role'] ?? '')));
        $roleOverride = ($role !== '' && in_array($role, Roles::VALID_ROLES, true)) ? $role : null;
        if ($selfLink !== null) {
            // No próprio acesso só os canais mudam: papel e empresa padrão ficam
            // exatamente como estão, o que vier no corpo é ignorado.
            $roleOverride = $selfLink['role_override'] !== null ? (string) $selfLink['role_override'] : null;
            $isDefault = (int) $selfLink['is_default'];
        }
        $defaultCanal = $canVarejo === 1 ? 'varejo' : 'licitacao';

        // Upsert do vínculo
        $stmt = $pdo->prepare(
            'INSERT INTO biomie_user_companies
                (user_id, company_id, can_varejo, can_licitacao, default_canal, role_override, is_default)
             VALUES (:uid, :cid, :cv, :cl, :dc, :ro, :isd)
             ON DUPLICATE KEY UPDATE
                can_varejo = VALUES(can_varejo),
                can_licitacao = VALUES(can_licitacao),
                default_canal = VALUES(default_canal),
                role_override = VALUES(role_override),
                is_default = VALUES(is_default)'
        );
        $stmt->execute([
            ':uid' => $userId, ':cid' => $companyId,
            ':cv' => $canVarejo, ':cl' => $canLicitacao,
            ':dc' => $defaultCanal, ':ro' => $roleOverride, ':isd' => $isDefault,
        ]);

        // Empresa padrão é exclusiva por usuário
        if ($isDefault === 1) {
            $pdo->prepare('UPDATE biomie_user_companies SET is_default = 0 WHERE user_id = :uid AND company_id <> :cid')
                ->execute([':uid' => $userId, ':cid' => $companyId]);
        }

        recordAccessLog($actorId, $selfLink !== null ? 'company_access_self_channels' : 'company_access_grant', $userId, $companyId, [
            'can_varejo' => $canVarejo, 'can_licitacao' => $canLicitacao,
            'role_override' => $roleOverride, 'is_default' => $isDefault,
        ] + ($selfLink !== null ? ['antes' => [
            'can_varejo' => (int) $selfLink['can_varejo'], 'can_licitacao' => (int) $selfLink['can_licitacao'],
        ]] : []));

        Http::json(['ok' => true, 'message' => 'Acesso concedido/atualizado.', 'request_id' => RequestContext::id()]);
    }

    if ($action === 'revoke') {
        $pdo->prepare('DELETE FROM biomie_user_companies WHERE user_id = :uid AND company_id = :cid')
            ->execute([':uid' => $userId, ':cid' => $companyId]);

        recordAccessLog($actorId, 'company_access_revoke', $userId, $companyId, []);

        Http::json(['ok' => true, 'message' => 'Acesso revogado.', 'request_id' => RequestContext::id()]);
    }

    Http::json(['ok' => false, 'message' => "Ação inválida: '{$action}'.", 'request_id' => RequestContext::id()], 400);
} catch (\Throwable $e) {
    $logger->error('company_access falhou.', ['message' => $e->getMessage()]);
    Http::json(['ok' => false, 'message' => 'Falha ao processar acesso.', 'request_id' => RequestContext::id()], 500);
}

/**
 * @param array<string,mixed> $details
 */
function recordAccessLog(int $actorId, string $event, int $userId, int $companyId, array $details): void
{
    try {
        Auth::repository()->recordLog(
            $event,
            $actorId,
            (string) (Auth::currentUser()['username'] ?? ''),
            (string) ($_SERVER['REQUEST_URI'] ?? 'api/company_access.php'),
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            ['user_id' => $userId, 'company_id' => $companyId] + $details,
        );
    } catch (\Throwable) {
    }
}

<?php

declare(strict_types=1);

/**
 * AGENDA DE ORÇAMENTOS — mapeamento manual usuário ↔ vendedor Omie (Fase 10).
 *
 *   GET                                          — usuários, empresas, vendedores
 *                                                  (com nº de orçamentos em aberto)
 *                                                  e vínculos existentes
 *   POST { action:"bind",   user_id, company_id, seller_id }
 *   POST { action:"unbind", user_id, company_id, seller_id }
 *
 * Só admin (users.manage). O vínculo por si NÃO dá acesso — o vendedor precisa
 * também da capability sales.quotes.followup.self (papéis de vendedor).
 *
 * Regra: um código Omie pertence a UM usuário por empresa (UNIQUE). Um usuário
 * pode ter vários códigos.
 */

use BIomie\Auth;
use BIomie\Config;
use BIomie\Database;
use BIomie\Http;
use BIomie\RequestContext;

require __DIR__ . '/bootstrap.php';

Auth::requireCapability('users.manage', true);

$config = new Config();
$conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo  = Database::connect($config, $conn);

const T_BIND = 'biomie_quote_seller_binding';

/** A tabela pode ainda não existir (migração pendente): degrada com aviso. */
function bindingTableReady(PDO $pdo): bool
{
    $st = $pdo->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . T_BIND . "'"
    );

    return (int) $st->fetchColumn() > 0;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        if (!bindingTableReady($pdo)) {
            Http::json([
                'ok' => false,
                'error' => 'migration_pending',
                'message' => 'A tabela de vínculos ainda não foi criada. Aplique a migração da Agenda (Fase 10).',
                'request_id' => RequestContext::id(),
            ], 503);
        }

        $companies = $pdo->query(
            'SELECT id, slug, nome_fantasia FROM biomie_companies WHERE ativo = 1 ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $users = $pdo->query(
            "SELECT id, username, full_name, role FROM biomie_users
              WHERE is_active = 1 ORDER BY full_name, username"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Vendedores por empresa + nº de orçamentos EM ABERTO (destaca quem tem
        // carteira parada — os códigos que de fato importam mapear).
        $sellers = $pdo->query(
            "SELECT v.company_id, v.codigo_omie AS seller_id, v.nome,
                    COALESCE(f.abertos, 0) AS open_quotes
               FROM biomie_vendedores_omie v
          LEFT JOIN (
                    SELECT company_id, seller_id, COUNT(*) AS abertos
                      FROM " . 'biomie_sales_quote_followup_schedule' . "
                     WHERE resolved_at IS NULL
                  GROUP BY company_id, seller_id
                 ) f ON f.company_id = v.company_id AND f.seller_id = v.codigo_omie
              WHERE v.ativo = 1 AND v.codigo_omie <> '' AND v.codigo_omie <> '0'
           ORDER BY v.company_id, open_quotes DESC, v.nome"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $bindings = $pdo->query(
            'SELECT user_id, company_id, seller_id FROM ' . T_BIND . ' ORDER BY company_id, seller_id'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        Http::json([
            'ok' => true,
            'companies' => $companies,
            'users' => $users,
            'sellers' => $sellers,
            'bindings' => $bindings,
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
    if (!bindingTableReady($pdo)) {
        Http::json(['ok' => false, 'error' => 'migration_pending',
            'message' => 'Tabela de vínculos ausente. Aplique a migração da Fase 10.',
            'request_id' => RequestContext::id()], 503);
    }

    $action    = strtolower(trim((string) ($body['action'] ?? '')));
    $userId    = (int) ($body['user_id'] ?? 0);
    $companyId = (int) ($body['company_id'] ?? 0);
    $sellerId  = trim((string) ($body['seller_id'] ?? ''));

    if ($userId <= 0 || $companyId <= 0 || $sellerId === '' || $sellerId === '0') {
        Http::json(['ok' => false, 'message' => 'user_id, company_id e seller_id são obrigatórios.',
            'request_id' => RequestContext::id()], 400);
    }

    // user, empresa e vendedor precisam existir/ativos.
    $chk = $pdo->prepare(
        "SELECT (SELECT COUNT(*) FROM biomie_users WHERE id = :uid AND is_active = 1) AS u,
                (SELECT COUNT(*) FROM biomie_companies WHERE id = :cid AND ativo = 1) AS c,
                (SELECT COUNT(*) FROM biomie_vendedores_omie
                   WHERE company_id = :cid2 AND codigo_omie = :sid) AS v"
    );
    $chk->execute([':uid' => $userId, ':cid' => $companyId, ':cid2' => $companyId, ':sid' => $sellerId]);
    $ex = $chk->fetch(PDO::FETCH_ASSOC) ?: ['u' => 0, 'c' => 0, 'v' => 0];
    if ((int) $ex['u'] === 0 || (int) $ex['c'] === 0) {
        Http::json(['ok' => false, 'message' => 'Usuário ou empresa inexistente/inativo.',
            'request_id' => RequestContext::id()], 404);
    }
    if ((int) $ex['v'] === 0) {
        Http::json(['ok' => false, 'message' => 'Código de vendedor não encontrado nesta empresa.',
            'request_id' => RequestContext::id()], 404);
    }
    $actorId = (int) (Auth::currentUser()['id'] ?? 0);

    if ($action === 'bind') {
        // O código já pertence a OUTRO usuário? UNIQUE (company_id, seller_id).
        $owner = $pdo->prepare('SELECT user_id FROM ' . T_BIND . ' WHERE company_id = :c AND seller_id = :s');
        $owner->execute([':c' => $companyId, ':s' => $sellerId]);
        $existingUser = $owner->fetchColumn();
        if ($existingUser !== false && (int) $existingUser !== $userId) {
            Http::json([
                'ok' => false,
                'error' => 'seller_taken',
                'message' => 'Este código já está vinculado a outro usuário. Remova o vínculo anterior primeiro.',
                'request_id' => RequestContext::id(),
            ], 409);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO ' . T_BIND . ' (user_id, company_id, seller_id, created_by, created_at, updated_at)
             VALUES (:u, :c, :s, :by, NOW(), NOW())
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), updated_at = NOW()'
        );
        $stmt->execute([':u' => $userId, ':c' => $companyId, ':s' => $sellerId, ':by' => $actorId ?: null]);

        Http::json(['ok' => true, 'action' => 'bind',
            'binding' => ['user_id' => $userId, 'company_id' => $companyId, 'seller_id' => $sellerId],
            'request_id' => RequestContext::id()]);
    }

    if ($action === 'unbind') {
        $stmt = $pdo->prepare('DELETE FROM ' . T_BIND . ' WHERE user_id = :u AND company_id = :c AND seller_id = :s');
        $stmt->execute([':u' => $userId, ':c' => $companyId, ':s' => $sellerId]);
        Http::json(['ok' => true, 'action' => 'unbind', 'removed' => $stmt->rowCount(),
            'request_id' => RequestContext::id()]);
    }

    Http::json(['ok' => false, 'message' => 'Ação inválida (use bind ou unbind).',
        'request_id' => RequestContext::id()], 400);
} catch (Throwable $e) {
    Http::json(['ok' => false, 'error' => 'internal',
        'message' => 'Falha ao processar o vínculo.', 'request_id' => RequestContext::id()], 500);
}

<?php

declare(strict_types=1);

/**
 * VISIBILIDADE COMERCIAL — configuração usuário × empresa (Fase B).
 *
 *   GET                                   — empresas, usuários, vendedores por
 *                                           empresa e a config atual (modo +
 *                                           escolhidos) de cada par.
 *   POST { action:"save", user_id, company_id, mode, seller_ids?[] }
 *
 * Só admin (users.manage). Espelha a governança de company_access.php: o admin
 * NÃO pode alterar a PRÓPRIA visibilidade (anti-escalada — senão um admin
 * rebaixado a "selected" se auto-liberaria).
 *
 * `mode`:
 *   · all       — vê todos os vendedores da empresa; seller_ids é ignorado.
 *   · own        — vê a própria carteira (vínculo biomie_quote_seller_binding).
 *   · selected   — vê só os seller_ids informados (subconjunto do cadastro).
 *
 * Salvar é ATÔMICO: a linha de modo e a lista de escolhidos mudam juntas, dentro
 * de uma transação — nunca um estado onde o modo diz "selected" mas a lista
 * ficou do valor antigo.
 */

use BIomie\Auth;
use BIomie\Config;
use BIomie\Database;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\Support\SellerVisibilityScope;

require __DIR__ . '/bootstrap.php';

Auth::requireCapability('users.manage', true);

const T_SCOPE   = 'biomie_user_seller_scope';
const T_VISIBLE = 'biomie_user_visible_sellers';

/** @var Config $config */
$config = new Config();
$conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo  = Database::connect($config, $conn);

/** Tabelas podem não existir (migração Fase B pendente): degrada com aviso claro. */
function svTablesReady(PDO $pdo): bool
{
    $st = $pdo->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME IN ('" . T_SCOPE . "','" . T_VISIBLE . "')"
    );

    return (int) $st->fetchColumn() === 2;
}

try {
    $actorId = (int) (Auth::currentUser()['id'] ?? 0);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        if (!svTablesReady($pdo)) {
            Http::json(['ok' => false, 'error' => 'migration_pending',
                'message' => 'Tabelas de visibilidade ausentes. Aplique a migração da Fase B.',
                'request_id' => RequestContext::id()], 503);
        }

        $companies = $pdo->query(
            'SELECT id, slug, nome_fantasia FROM biomie_companies WHERE ativo = 1 ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $users = $pdo->query(
            'SELECT id, username, full_name, role FROM biomie_users
              WHERE is_active = 1 ORDER BY full_name, username'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Vendedores por empresa (cadastro canônico) — as opções do modo selected.
        $sellers = $pdo->query(
            "SELECT company_id, codigo_omie AS seller_id, nome
               FROM biomie_vendedores_omie
              WHERE ativo = 1 AND codigo_omie <> '' AND codigo_omie <> '0'
           ORDER BY company_id, nome"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Config existente: modo por (empresa, usuário).
        $modes = $pdo->query(
            'SELECT company_id, user_id, visibility_mode FROM ' . T_SCOPE
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Escolhidos (modo selected): (empresa, usuário) => [seller_id,...]
        $picked = $pdo->query(
            'SELECT company_id, user_id, seller_id FROM ' . T_VISIBLE . ' ORDER BY seller_id'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Vínculos de identidade — para a UI mostrar "own = tais códigos".
        $bindings = $pdo->query(
            'SELECT company_id, user_id, seller_id FROM biomie_quote_seller_binding ORDER BY seller_id'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        Http::json([
            'ok' => true,
            'companies' => $companies,
            'users' => $users,
            'sellers' => $sellers,
            'modes' => $modes,
            'picked' => $picked,
            'bindings' => $bindings,
            'default_mode_note' => 'Sem configuração: quem tem vínculo de vendedor vê a própria carteira; os demais veem todos.',
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
    if (!svTablesReady($pdo)) {
        Http::json(['ok' => false, 'error' => 'migration_pending',
            'message' => 'Tabelas de visibilidade ausentes. Aplique a migração da Fase B.',
            'request_id' => RequestContext::id()], 503);
    }

    $action    = strtolower(trim((string) ($body['action'] ?? 'save')));
    $userId    = (int) ($body['user_id'] ?? 0);
    $companyId = (int) ($body['company_id'] ?? 0);
    $mode      = strtolower(trim((string) ($body['mode'] ?? '')));

    if ($action !== 'save') {
        Http::json(['ok' => false, 'message' => 'Ação inválida (use save).', 'request_id' => RequestContext::id()], 400);
    }
    if ($userId <= 0 || $companyId <= 0) {
        Http::json(['ok' => false, 'message' => 'user_id e company_id são obrigatórios.', 'request_id' => RequestContext::id()], 400);
    }
    // Anti-escalada: ninguém edita a própria visibilidade.
    if ($userId === $actorId) {
        Http::json(['ok' => false, 'error' => 'self_edit_forbidden',
            'message' => 'Você não pode alterar a própria visibilidade comercial.',
            'request_id' => RequestContext::id()], 403);
    }
    $validModes = [SellerVisibilityScope::MODE_ALL, SellerVisibilityScope::MODE_OWN, SellerVisibilityScope::MODE_SELECTED];
    if (!in_array($mode, $validModes, true)) {
        Http::json(['ok' => false, 'message' => 'mode deve ser all, own ou selected.', 'request_id' => RequestContext::id()], 400);
    }

    // user e empresa precisam existir/ativos.
    $chk = $pdo->prepare(
        'SELECT (SELECT COUNT(*) FROM biomie_users WHERE id = :uid AND is_active = 1) AS u,
                (SELECT COUNT(*) FROM biomie_companies WHERE id = :cid AND ativo = 1) AS c'
    );
    $chk->execute([':uid' => $userId, ':cid' => $companyId]);
    $ex = $chk->fetch(PDO::FETCH_ASSOC) ?: ['u' => 0, 'c' => 0];
    if ((int) $ex['u'] === 0 || (int) $ex['c'] === 0) {
        Http::json(['ok' => false, 'message' => 'Usuário ou empresa inexistente/inativo.', 'request_id' => RequestContext::id()], 404);
    }

    // No modo selected, os códigos precisam existir NAQUELA empresa. Um código
    // inventado ou de outra empresa é rejeitado — a lista tem que ser um
    // subconjunto real do cadastro, senão a UI mostraria escolhas fantasma.
    $sellerIds = [];
    if ($mode === SellerVisibilityScope::MODE_SELECTED) {
        $raw = array_values(array_unique(array_filter(
            array_map(static fn ($s): string => trim((string) $s), (array) ($body['seller_ids'] ?? [])),
            static fn (string $s): bool => $s !== '' && $s !== '0'
        )));
        if ($raw === []) {
            Http::json(['ok' => false, 'error' => 'empty_selection',
                'message' => 'No modo Personalizado, selecione ao menos um vendedor (ou use "Somente o próprio").',
                'request_id' => RequestContext::id()], 422);
        }
        $ph = implode(',', array_fill(0, count($raw), '?'));
        $vst = $pdo->prepare(
            "SELECT codigo_omie FROM biomie_vendedores_omie
              WHERE company_id = ? AND codigo_omie IN ($ph)"
        );
        $vst->execute(array_merge([$companyId], $raw));
        $valid = array_map('strval', $vst->fetchAll(PDO::FETCH_COLUMN));
        $unknown = array_values(array_diff($raw, $valid));
        if ($unknown !== []) {
            Http::json(['ok' => false, 'error' => 'unknown_seller',
                'message' => 'Há vendedores fora do cadastro desta empresa: ' . implode(', ', $unknown),
                'request_id' => RequestContext::id()], 422);
        }
        $sellerIds = $valid;
    }

    // ── Escrita ATÔMICA: modo + lista mudam juntos ──────────────────────────
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO ' . T_SCOPE . ' (company_id, user_id, visibility_mode, created_by, updated_by)
             VALUES (:c, :u, :m, :by, :by)
             ON DUPLICATE KEY UPDATE visibility_mode = VALUES(visibility_mode),
                                     updated_by = VALUES(updated_by), updated_at = NOW()'
        )->execute([':c' => $companyId, ':u' => $userId, ':m' => $mode, ':by' => $actorId ?: null]);

        // A lista de escolhidos é substituída por completo (idempotente).
        $pdo->prepare('DELETE FROM ' . T_VISIBLE . ' WHERE company_id = :c AND user_id = :u')
            ->execute([':c' => $companyId, ':u' => $userId]);

        if ($mode === SellerVisibilityScope::MODE_SELECTED && $sellerIds !== []) {
            $pick = $pdo->prepare(
                'INSERT INTO ' . T_VISIBLE . ' (company_id, user_id, seller_id, created_by)
                 VALUES (:c, :u, :s, :by)'
            );
            foreach ($sellerIds as $sid) {
                $pick->execute([':c' => $companyId, ':u' => $userId, ':s' => $sid, ':by' => $actorId ?: null]);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    Http::json([
        'ok' => true,
        'action' => 'save',
        'saved' => ['user_id' => $userId, 'company_id' => $companyId, 'mode' => $mode, 'seller_ids' => $sellerIds],
        'request_id' => RequestContext::id(),
    ]);
} catch (Throwable $e) {
    Http::json(['ok' => false, 'error' => 'internal',
        'message' => 'Falha ao salvar a visibilidade comercial.', 'request_id' => RequestContext::id()], 500);
}

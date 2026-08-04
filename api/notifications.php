<?php

/**
 * api/notifications.php — Central de notificações do usuário (sininho in-app).
 *
 *   GET  ?action=count                  — só a contagem de não-lidas (polling leve)
 *   GET  ?action=list[&unread=1][&limit=30] — lista + contagem
 *   POST { action:"read", id, csrf_token }      — marca uma como lida
 *   POST { action:"read_all", csrf_token }      — marca todas (visíveis) como lidas
 *
 * Sempre escopado às empresas que o usuário acessa (TenantContext) — mesmo
 * isolamento multi-empresa do resto do sistema.
 */

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\Notifications\NotificationService;
use BIomie\RequestContext;
use BIomie\Support\TenantContext;

require __DIR__ . '/bootstrap.php'; // Auth::requireLogin(true) + TenantContext::boot()

/** @var \BIomie\Config $config definido em api/bootstrap.php */

try {
    $conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo  = Database::connect($config, $conn);
    $service = new NotificationService($pdo);

    $userId  = (int) (Auth::currentUser()['id'] ?? 0);
    // ESCOPO DO CONTEXTO ATIVO, não "tudo que o usuário poderia acessar".
    // accessibleCompanyIds() lista TODAS as empresas do usuário — com ele, quem
    // estava na MAG Filial via notificação da Uni Design e da Expert no sininho.
    // allowedCompanyIds() vem do contexto vigente: uma empresa quando há uma
    // selecionada, e o conjunto do grupo em contexto consolidado. É o mesmo
    // isolamento que TenantContext::sqlFilter() aplica no resto do sistema.
    $allowed = TenantContext::allowedCompanyIds();
    if ($userId <= 0) {
        Http::json(['ok' => false, 'message' => 'Sessao invalida.', 'request_id' => RequestContext::id()], 401);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = strtolower((string) (Http::query('action', 'list') ?? 'list'));
        if ($action === 'count') {
            Http::json([
                'ok' => true,
                'unread' => $service->unreadCountForUser($userId, $allowed),
                'request_id' => RequestContext::id(),
            ], 200, [], true);
        }

        $unreadOnly = (string) (Http::query('unread', '') ?? '') === '1';
        $limit = (int) (Http::query('limit', '30') ?? '30');
        $items = $service->listForUser($userId, $allowed, $limit, $unreadOnly);

        // Identidade da empresa por item (logo + cor + rótulo). Resolvida UMA vez
        // por request — no máximo 5 empresas — e não por item: a lista pode ter
        // 30 notificações e o custo tem que ficar constante.
        $companyBrand = [];
        foreach (TenantContext::cachedAccessible() as $company) {
            $cid  = (int) ($company['id'] ?? 0);
            $slug = (string) ($company['slug'] ?? '');
            $brand = [
                'label' => (string) ($company['nome_fantasia'] ?? $slug),
                'logo'  => '',
                'color' => '',
                'initials' => mb_strtoupper(mb_substr((string) ($company['nome_fantasia'] ?? $slug), 0, 2)),
            ];
            try {
                $manifest = \BIomie\Company\CompanyPackageLoader::load($slug);
                $brand['label'] = $manifest->name() ?: $brand['label'];
                $brand['color'] = $manifest->brandColor();
                $brand['initials'] = $manifest->initials() ?: $brand['initials'];
                $logoPath = $manifest->logoIconPath();
                if ($logoPath !== '' && is_file(\BIomie\Bootstrap::rootPath($logoPath))) {
                    $brand['logo'] = $logoPath;
                }
            } catch (\Throwable) {
                // sem pacote da empresa: segue com rótulo/iniciais do banco
            }
            $companyBrand[$cid] = $brand;
        }

        foreach ($items as &$item) {
            $cid = $item['company_id'] ?? null;
            $brand = ($cid !== null && isset($companyBrand[(int) $cid])) ? $companyBrand[(int) $cid] : null;
            $item['company_label']    = $brand['label'] ?? '';
            $item['company_logo']     = $brand['logo'] ?? '';
            $item['company_color']    = $brand['color'] ?? '';
            $item['company_initials'] = $brand['initials'] ?? '';
        }
        unset($item);

        Http::json([
            'ok' => true,
            'unread' => $service->unreadCountForUser($userId, $allowed),
            'items' => $items,
            'request_id' => RequestContext::id(),
        ], 200, [], true);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        Http::json(['ok' => false, 'message' => 'Metodo nao permitido.', 'request_id' => RequestContext::id()], 405);
    }

    $body = (array) (json_decode((string) file_get_contents('php://input'), true) ?? []);
    if (!Auth::verifyCsrf((string) ($body['csrf_token'] ?? ''))) {
        Http::json(['ok' => false, 'message' => 'Token CSRF invalido.', 'request_id' => RequestContext::id()], 403);
    }

    $action = strtolower(trim((string) ($body['action'] ?? '')));
    if ($action === 'read') {
        $id = (int) ($body['id'] ?? 0);
        if ($id > 0) {
            $service->markRead($userId, $id);
        }
        Http::json([
            'ok' => true,
            'unread' => $service->unreadCountForUser($userId, $allowed),
            'request_id' => RequestContext::id(),
        ]);
    }

    if ($action === 'read_all') {
        $marked = $service->markAllRead($userId, $allowed);
        // RECONTA de verdade em vez de devolver 0 fixo.
        //
        // Com 0 fixo, uma marcação que falhou parcialmente (ou não marcou nada)
        // devolvia "ok, zero não lidas": o sininho zerava e voltava a 99+ no
        // polling seguinte. O usuário via "cliquei e não concluiu" sem nenhuma
        // pista do motivo — e o log também não registrava nada.
        $restantes = $service->unreadCountForUser($userId, $allowed);
        Http::json([
            'ok' => true,
            'marked' => $marked,
            'unread' => $restantes,
            // Quando sobra não-lida depois de "marcar todas", quase sempre é
            // ESCOPO: a marcação só alcança o contexto ativo. A tela precisa
            // poder dizer isso em vez de fingir sucesso.
            'partial' => $restantes > 0,
            'request_id' => RequestContext::id(),
        ]);
    }

    Http::json(['ok' => false, 'message' => 'Acao desconhecida.', 'request_id' => RequestContext::id()], 400);
} catch (Throwable $exception) {
    Http::json([
        'ok' => false,
        'message' => 'Erro ao carregar notificacoes.',
        'request_id' => RequestContext::id(),
    ], 500);
}

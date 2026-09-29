<?php

declare(strict_types=1);

/**
 * VENDEDOR ATRIBUÍDO NO BIOMIE — NF/pedido que veio do Omie sem vendedor.
 *
 *   GET  ?company_id=N                   — empresas, vendedores do cadastro Omie
 *                                          da empresa e operações sem vendedor
 *                                          (com a atribuição ativa, se houver)
 *   POST { action:"assign", company_id, tipo:"nf"|"pedido", chave, seller_id, motivo }
 *   POST { action:"remove", company_id, tipo, chave }
 *
 * Muda SÓ o BIomie; o Omie não é tocado. O canal segue a regra oficial
 * (vendedor "Licitação" => licitação; demais => varejo) — ver LicitacaoSeller e
 * SellerAssignmentService. Após gravar, agenda a rematerialização comercial do
 * mês da operação para os marts refletirem o vendedor.
 *
 * Só admin (users.manage), como o Vínculo de Vendedores.
 */

use BIomie\Auth;
use BIomie\Canonical\SellerAssignmentService;
use BIomie\Config;
use BIomie\Database;
use BIomie\Http;
use BIomie\Queue\FileJobQueue;
use BIomie\RequestContext;
use BIomie\Support\SecretMask;

require __DIR__ . '/bootstrap.php';

Auth::requireCapability('users.manage', true);

$config = new Config();
$conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo  = Database::connect($config, $conn);

try {
    $companies = $pdo->query(
        'SELECT id, slug, nome_fantasia FROM biomie_companies WHERE ativo = 1 ORDER BY id'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $companyIds = array_map(static fn (array $c): int => (int) $c['id'], $companies);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $companyId = (int) ($_GET['company_id'] ?? ($companyIds[0] ?? 0));
        if (!in_array($companyId, $companyIds, true)) {
            Http::json(['ok' => false, 'message' => 'Empresa inválida.', 'request_id' => RequestContext::id()], 400);
        }
        $service = new SellerAssignmentService($pdo, $companyId);
        Http::json([
            'ok' => true,
            'ready' => SellerAssignmentService::tableReady($pdo),
            'company_id' => $companyId,
            'companies' => $companies,
            'sellers' => $service->sellers(),
            'rows' => $service->pending(),
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

    $action = strtolower(trim((string) ($body['action'] ?? '')));
    $companyId = (int) ($body['company_id'] ?? 0);
    if (!in_array($companyId, $companyIds, true)) {
        Http::json(['ok' => false, 'message' => 'Empresa inválida.', 'request_id' => RequestContext::id()], 400);
    }
    $tipo = (string) ($body['tipo'] ?? '');
    $chave = (string) ($body['chave'] ?? '');
    $actor = Auth::currentUser();
    $actorId = (int) ($actor['id'] ?? 0) ?: null;
    $service = new SellerAssignmentService($pdo, $companyId);

    try {
        $result = match ($action) {
            'assign' => $service->assign($tipo, $chave, (string) ($body['seller_id'] ?? ''), (string) ($body['motivo'] ?? ''), $actorId),
            'remove' => $service->remove($tipo, $chave, $actorId),
            default => throw new InvalidArgumentException('Ação inválida (use assign ou remove).'),
        };
    } catch (InvalidArgumentException $invalid) {
        Http::json(['ok' => false, 'message' => $invalid->getMessage(), 'request_id' => RequestContext::id()], 422);
    }

    try {
        Auth::repository()->recordLog(
            $action === 'assign' ? 'seller_assignment_assign' : 'seller_assignment_remove',
            (int) $actorId,
            (string) ($actor['username'] ?? ''),
            (string) ($_SERVER['REQUEST_URI'] ?? 'api/seller_assignment.php'),
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            ['company_id' => $companyId] + $result + ['motivo' => (string) ($body['motivo'] ?? '')],
        );
    } catch (Throwable) {
    }

    // Marts (vendedor/dia, cliente, produto...) do mês da operação.
    $queue = null;
    $emissao = $result['data_emissao'] ?? null;
    if (is_string($emissao) && preg_match('/^(\d{4})-(\d{2})/', $emissao, $m) === 1) {
        $start = sprintf('%s-%s-01', $m[1], $m[2]);
        $end = date('Y-m-t', (int) strtotime($start));
        try {
            $queue = (new FileJobQueue(dirname(__DIR__) . '/storage/cache', $logger))->enqueue(
                'default',
                'commercial.materialize',
                ['company_id' => $companyId, 'canal' => 'mixed', 'start' => $start, 'end' => $end, 'actor_user_id' => $actorId],
                [
                    'idempotency_key' => sprintf('commercial.materialize|company:%d|%s|%s', $companyId, $start, $end),
                    'max_attempts' => 2,
                    'created_by' => $actorId,
                ],
            );
        } catch (Throwable $queueError) {
            $queue = ['queued' => false, 'error' => SecretMask::sanitizeErrorMessage($queueError->getMessage(), 160)];
        }
    }

    Http::json([
        'ok' => true,
        'action' => $action,
        'result' => $result,
        'rematerializacao' => $queue,
        'request_id' => RequestContext::id(),
    ]);
} catch (Throwable $e) {
    $logger->error('seller_assignment falhou.', [
        'message' => SecretMask::sanitizeErrorMessage($e->getMessage(), 300),
        'request_id' => RequestContext::id(),
    ]);
    Http::json(['ok' => false, 'error' => 'internal',
        'message' => 'Falha ao processar a atribuição de vendedor.', 'request_id' => RequestContext::id()], 500);
}

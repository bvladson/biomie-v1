<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\Admin\AdminLogService;

require __DIR__ . '/bootstrap.php';

Auth::requireCapability('finance.view', true);

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

$config = new \BIomie\Config();
$conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo = Database::connect($config, $conn);

if ($method === 'GET') {
    $tituloKey = trim((string) ($_GET['titulo_key'] ?? ''));
    $clienteId = trim((string) ($_GET['cliente_codigo_omie'] ?? ''));
    $vendedorId = trim((string) ($_GET['vendedor_codigo_omie'] ?? ''));

    $sql = "
        SELECT l.id, l.actor_user_id, l.details_json, l.created_at, u.username, u.full_name
        FROM biomie_admin_logs l
        LEFT JOIN biomie_users u ON u.id = l.actor_user_id
        WHERE l.event_type = 'finance.collection_action'
    ";
    
    $where = [];
    $params = [];

    if ($tituloKey !== '') {
        $where[] = "l.details_json LIKE :titulo_like";
        $params['titulo_like'] = '%"titulo_key":"' . $tituloKey . '"%';
    }
    if ($clienteId !== '') {
        $where[] = "l.details_json LIKE :cliente_like";
        $params['cliente_like'] = '%"cliente_codigo_omie":"' . $clienteId . '"%';
    }
    if ($vendedorId !== '') {
        $where[] = "l.details_json LIKE :vendedor_like";
        $params['vendedor_like'] = '%"vendedor_codigo_omie":"' . $vendedorId . '"%';
    }

    if ($where !== []) {
        $sql .= " AND " . implode(" AND ", $where);
    }

    $sql .= " ORDER BY l.id DESC LIMIT 100";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $data = [];
    foreach ($rows as $row) {
        $details = json_decode((string) ($row['details_json'] ?? '{}'), true) ?: [];
        $data[] = [
            'id' => (int) $row['id'],
            'actor_user_id' => $row['actor_user_id'] !== null ? (int) $row['actor_user_id'] : null,
            'actor_username' => (string) ($row['username'] ?? 'Sistema'),
            'actor_full_name' => (string) ($row['full_name'] ?? 'Sistema'),
            'created_at' => $row['created_at'],
            'titulo_key' => (string) ($details['titulo_key'] ?? ''),
            'cliente_codigo_omie' => (string) ($details['cliente_codigo_omie'] ?? ''),
            'vendedor_codigo_omie' => (string) ($details['vendedor_codigo_omie'] ?? ''),
            'action_type' => (string) ($details['action_type'] ?? ''),
            'note' => (string) ($details['note'] ?? ''),
            'next_action_date' => (string) ($details['next_action_date'] ?? ''),
        ];
    }

    Http::json([
        'ok' => true,
        'data' => $data,
    ]);
} elseif ($method === 'POST') {
    $input = json_decode((string) file_get_contents('php://input'), true) ?: [];

    $tituloKey = trim((string) ($input['titulo_key'] ?? ''));
    $clienteId = trim((string) ($input['cliente_codigo_omie'] ?? ''));
    $vendedorId = trim((string) ($input['vendedor_codigo_omie'] ?? ''));
    $actionType = trim((string) ($input['action_type'] ?? ''));
    $note = trim((string) ($input['note'] ?? ''));
    $nextActionDate = trim((string) ($input['next_action_date'] ?? ''));

    if ($tituloKey === '') {
        Http::json(['ok' => false, 'error' => ['message' => 'O campo titulo_key é obrigatório.']], 400);
    }

    $allowedActions = [
        'cobrado_hoje',
        'whatsapp_enviado',
        'ligacao_realizada',
        'email_enviado',
        'aguardando_retorno',
        'promessa_pagamento',
        'validar_baixa_caixa',
        'revisar_vinculo_financeiro',
    ];

    if (!in_array($actionType, $allowedActions, true)) {
        Http::json(['ok' => false, 'error' => ['message' => 'Tipo de ação inválido.']], 400);
    }

    if ($nextActionDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $nextActionDate) !== 1) {
        Http::json(['ok' => false, 'error' => ['message' => 'A data da próxima ação deve ser YYYY-MM-DD.']], 400);
    }

    $titleStmt = $pdo->prepare("SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos WHERE titulo_key = :key");
    $titleStmt->execute(['key' => $tituloKey]);
    if ((int) $titleStmt->fetchColumn() === 0) {
        Http::json(['ok' => false, 'error' => ['message' => 'Título não encontrado.']], 404);
    }

    $details = [
        'titulo_key' => $tituloKey,
        'cliente_codigo_omie' => $clienteId,
        'vendedor_codigo_omie' => $vendedorId,
        'action_type' => $actionType,
        'note' => mb_substr($note, 0, 1000),
        'next_action_date' => $nextActionDate !== '' ? $nextActionDate : null,
    ];

    $logService = new AdminLogService($pdo);
    $logService->log('finance.collection_action', null, $details);

    // Clear caches
    $cacheDir = dirname(__DIR__) . '/storage/cache';
    $files = glob($cacheDir . '/*.json') ?: [];
    foreach ($files as $f) {
        if (str_contains(basename($f), 'finance_collection') || str_contains(basename($f), 'finr3')) {
            @unlink($f);
        }
    }

    Http::json([
        'ok' => true,
        'message' => 'Ação de cobrança registrada com sucesso.',
    ]);
} else {
    Http::json(['ok' => false, 'error' => ['message' => 'Método HTTP não permitido.']], 405);
}

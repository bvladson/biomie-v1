<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Config;
use BIomie\Database;
use BIomie\Notifications\NotificationService;
use BIomie\PageRenderer;
use BIomie\Support\TenantContext;

require __DIR__ . '/app/page_bootstrap.php';
Auth::requireLogin(false);

$config = new Config();
$conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo = Database::connect($config, $conn);

$service = new NotificationService($pdo);
$userId  = (int) (Auth::currentUser()['id'] ?? 0);
// Escopo do CONTEXTO ATIVO (mesma regra do sininho em api/notifications.php):
// accessibleCompanyIds() traria as notificações de TODAS as empresas do usuário,
// misturando Uni Design/Expert na visão de quem está na MAG Filial.
$allowed = TenantContext::isBooted() ? TenantContext::allowedCompanyIds() : [];
$items   = $userId > 0 ? $service->listForUser($userId, $allowed, 100, false) : [];

$companyLabels = [];
if (TenantContext::isBooted()) {
    foreach (TenantContext::cachedAccessible() as $company) {
        $companyLabels[(int) ($company['id'] ?? 0)] = (string) ($company['nome_fantasia'] ?? $company['slug'] ?? '');
    }
}

echo PageRenderer::renderPhp('notificacoes.php', [
    'page_key' => 'notificacoes',
    'user' => Auth::currentUser(),
    'is_admin' => Auth::isAdmin(),
    'pageTitle' => 'Notificações',
    'pageIcon' => 'notifications',
    'notif_items' => $items,
    'notif_company_labels' => $companyLabels,
]);

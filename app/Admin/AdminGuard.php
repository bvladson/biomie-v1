<?php

declare(strict_types=1);

namespace BIomie\Admin;

use BIomie\Auth;

final class AdminGuard
{
    public function __construct(
        private readonly RbacService $rbac,
        private readonly AdminLogService $logs,
    ) {
    }

    /**
     * @param list<string> $permissions
     */
    public function requireAny(array $permissions): void
    {
        Auth::requireLogin(false);

        $user = Auth::currentUser();
        if ($user !== null && $this->rbac->userHasAnyPermission((int) $user['id'], $permissions, (string) ($user['role'] ?? 'viewer'))) {
            return;
        }

        $this->logs->log('admin_access_denied', null, [
            'permissions' => $permissions,
        ]);
        $this->renderForbidden();
    }

    public function can(string $permission): bool
    {
        $user = Auth::currentUser();
        return $user !== null
            && $this->rbac->userHasPermission((int) $user['id'], $permission, (string) ($user['role'] ?? 'viewer'));
    }

    public function assert(string $permission): ?string
    {
        if ($this->can($permission)) {
            return null;
        }

        $this->logs->log('admin_access_denied', null, [
            'permission' => $permission,
            'action' => (string) ($_POST['action'] ?? ''),
        ]);

        return 'Seu perfil nao tem permissao para executar esta acao.';
    }

    private function renderForbidden(): never
    {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8" />';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1" />';
        echo '<title>Acesso negado | BIomie MAG</title>';
        echo '<link rel="stylesheet" href="assets/css/theme-tokens.css" />';
        echo '<link rel="stylesheet" href="assets/css/mag-design-system.css" />';
        echo '<style>body{min-height:100vh;display:grid;place-items:center;margin:0;background:var(--mag-bg);font-family:system-ui,sans-serif}.box{width:min(520px,calc(100% - 32px));background:var(--mag-surface);border:1px solid var(--mag-border);border-radius:16px;padding:28px;box-shadow:var(--mag-shadow-soft)}h1{margin:0 0 8px;color:var(--mag-text);font-size:24px}p{color:var(--mag-muted);line-height:1.55}.btn{display:inline-flex;margin-top:10px;padding:10px 14px;border-radius:12px;background:var(--page-accent,#96140a);color:#fff;text-decoration:none;font-weight:700}</style>';
        echo '</head><body><main class="box">';
        echo '<h1>Acesso negado</h1>';
        echo '<p>Seu perfil nao possui permissao administrativa para esta area.</p>';
        echo '<a class="btn" href="index.php">Voltar ao painel</a>';
        echo '</main></body></html>';
        exit;
    }
}

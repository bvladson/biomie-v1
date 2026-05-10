<?php

declare(strict_types=1);

namespace BIomie\Admin;

use BIomie\Auth;

final class CsrfService
{
    public function token(): string
    {
        return Auth::csrfToken();
    }

    public function verify(?string $token): bool
    {
        return Auth::verifyCsrf($token);
    }

    public function validatePost(?AdminLogService $logs = null): ?string
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return 'Acao administrativa invalida.';
        }

        $token = is_string($_POST['csrf_token'] ?? null) ? (string) $_POST['csrf_token'] : null;
        if ($this->verify($token)) {
            return null;
        }

        $logs?->log('csrf_failed', null, [
            'action' => (string) ($_POST['action'] ?? ''),
        ]);

        return 'Sessao expirada. Atualize a pagina e tente novamente.';
    }
}

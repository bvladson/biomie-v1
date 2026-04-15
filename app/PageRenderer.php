<?php

declare(strict_types=1);

namespace BIomie;

final class PageRenderer
{
    public static function render(string $templateFile, array $context = []): string
    {
        $path = Bootstrap::rootPath('views/' . ltrim($templateFile, '/'));
        if (!is_file($path)) {
            throw new \RuntimeException(
                'Template nao encontrado: views/' . ltrim($templateFile, '/') . '. '
                . 'Verifique se a pasta views/ foi enviada ao servidor. '
                . 'Acesse /biomie/check.php para diagnostico completo.'
            );
        }

        $html = (string) file_get_contents($path);
        $user = $context['user'] ?? null;
        $isAdmin = (bool) ($context['is_admin'] ?? false);

        $replacements = [
            'index.html' => 'index.php',
            'vendedores.html' => 'vendedores.php',
            'pedidos.html' => 'pedidos.php',
            'mercadorias.html' => 'mercadorias.php',
            'financeiro.html' => 'financeiro.php',
            'plano_voo.html' => 'plano_voo.php',
            'plano_voo_metas.html' => 'plano_voo_metas.php',
            'integracoes.html' => 'integracoes.php',
            'observabilidade.html' => 'observabilidade.php',
        ];
        $html = str_replace(array_keys($replacements), array_values($replacements), $html);

        $metaTags = sprintf(
            '<meta name="biomie-csrf" content="%s" />'
            . '<meta name="biomie-request-id" content="%s" />',
            htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars(RequestContext::id(), ENT_QUOTES, 'UTF-8')
        );
        if (preg_match('/<head[^>]*>/i', $html) === 1) {
            $html = preg_replace('/<head([^>]*)>/i', '<head$1>' . $metaTags, $html, 1) ?: $html;
        } else {
            $html = preg_replace('/<\/head>/', $metaTags . '</head>', $html, 1) ?: $html;
        }

        if (is_array($user)) {
            $initial = strtoupper(mb_substr(trim((string) ($user['full_name'] ?? 'U')), 0, 1));
            $safeName = htmlspecialchars((string) ($user['full_name'] ?? 'Usuario'), ENT_QUOTES, 'UTF-8');
            $safeRole = htmlspecialchars(
                strtolower((string) ($user['role'] ?? 'user')) === 'admin' ? 'Administrador' : 'Usuario',
                ENT_QUOTES,
                'UTF-8'
            );
            $html = preg_replace(
                '/<div class="sidebar-profile">.*?<\/div>\s*<\/div>/s',
                '<div class="sidebar-profile"><div class="profile-avatar">' . $initial . '</div><div class="profile-info"><span>' . $safeRole . '</span><strong>' . $safeName . '</strong></div></div>',
                $html,
                1
            ) ?: $html;
        }

        if ($isAdmin) {
            $adminNav = '<a href="admin.php" class="nav-item"><span class="material-symbols-rounded menu-icon">admin_panel_settings</span>Administracao</a>'
                . '<a href="reconciliacao.php" class="nav-item"><span class="material-symbols-rounded menu-icon">rule</span>Conciliacao</a>'
                . '<a href="observabilidade.php" class="nav-item"><span class="material-symbols-rounded menu-icon">monitoring</span>Observabilidade</a>';
            $html = preg_replace('/<\/nav>/', $adminNav . '</nav>', $html, 1) ?: $html;
        }

        $actionButtons = '<a href="logout.php" class="btn-secondary" style="text-decoration:none;display:inline-flex;align-items:center;gap:6px;padding:10px 14px;border-radius:999px"><span class="material-symbols-rounded">logout</span>Sair</a>';
        if ($isAdmin) {
            $actionButtons = '<a href="admin.php" class="btn-secondary" style="text-decoration:none;display:inline-flex;align-items:center;gap:6px;padding:10px 14px;border-radius:999px"><span class="material-symbols-rounded">admin_panel_settings</span>Admin</a>' . $actionButtons;
        }
        $html = preg_replace('/<div class="topbar-actions">/', '<div class="topbar-actions">' . $actionButtons, $html, 1) ?: $html;

        return $html;
    }
}

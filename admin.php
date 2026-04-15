<?php

declare(strict_types=1);

use BIomie\Auth;

require __DIR__ . '/app/page_bootstrap.php';
Auth::requireAdmin(false);

$message = null;
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf($_POST['csrf_token'] ?? null)) {
        $message = 'Sessão do formulário expirada. Atualize a página e tente novamente.';
        $messageType = 'error';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'create_user') {
            $result = Auth::createUser(
                (string) ($_POST['username'] ?? ''),
                (string) ($_POST['full_name'] ?? ''),
                trim((string) ($_POST['email'] ?? '')) !== '' ? (string) $_POST['email'] : null,
                (string) ($_POST['password'] ?? ''),
                (string) ($_POST['role'] ?? 'user'),
                isset($_POST['is_active'])
            );
            $message = $result['message'];
            $messageType = $result['ok'] ? 'success' : 'error';
        } elseif ($action === 'update_user') {
            $result = Auth::updateUser(
                (int) ($_POST['user_id'] ?? 0),
                (string) ($_POST['full_name'] ?? ''),
                trim((string) ($_POST['email'] ?? '')) !== '' ? (string) $_POST['email'] : null,
                (string) ($_POST['role'] ?? 'user'),
                isset($_POST['is_active'])
            );
            $message = $result['message'];
            $messageType = $result['ok'] ? 'success' : 'error';
        } elseif ($action === 'reset_password') {
            $result = Auth::resetUserPassword((int) ($_POST['user_id'] ?? 0), (string) ($_POST['password'] ?? ''));
            $message = $result['message'];
            $messageType = $result['ok'] ? 'success' : 'error';
        }
    }
}

$repo = Auth::repository();
$users = $repo->listUsers();
$logs = $repo->listLogs(120);
$csrf = Auth::csrfToken();
$currentUser = Auth::currentUser();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <script src="assets/js/theme-init.js?v=20260413dark1"></script>
  <meta name="color-scheme" content="light dark" />
  <meta name="theme-color" content="#f6f3f2" media="(prefers-color-scheme: light)" />
  <meta name="theme-color" content="#12151b" media="(prefers-color-scheme: dark)" />
  <title>Administração | BIomie</title>
  <link rel="stylesheet" href="assets/css/theme-tokens.css?v=20260413dark2" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <style>
    :root{--bg:var(--bg-base);--card:var(--bg-card);--text:var(--text-1);--muted:var(--text-2);--border:var(--border);--brand:var(--red);--brand-dark:var(--page-chart-2);--ok:var(--success-strong);--error:var(--danger-strong)}
    *{box-sizing:border-box}body{margin:0;font-family:var(--font-body);background:var(--bg);color:var(--text)}
    .wrap{padding:24px;max-width:1400px;margin:0 auto}.top{display:flex;justify-content:space-between;gap:16px;align-items:center;margin-bottom:24px;flex-wrap:wrap}
    .top h1{margin:0;font-size:2rem}.muted{color:var(--muted)}.actions{display:flex;gap:12px;flex-wrap:wrap}.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:12px 16px;border-radius:16px;text-decoration:none;border:1px solid var(--border);background:var(--bg-surface);color:var(--text);font-weight:700}
    .btn.primary{background:var(--brand);border-color:var(--brand);color:#fff}.btn:hover{opacity:.96}.grid{display:grid;grid-template-columns:360px 1fr;gap:20px}.panel{background:var(--card);border:1px solid var(--border);border-radius:24px;padding:20px;box-shadow:var(--shadow-lg)}
    .panel h2{margin:0 0 16px;font-size:1.2rem}.field{margin-bottom:12px}label{display:block;margin-bottom:8px;font-size:.9rem;font-weight:700}input,select{width:100%;padding:12px 14px;border:1px solid var(--border);border-radius:14px;font:inherit;background:var(--bg-surface);color:var(--text)}
    .row{display:grid;grid-template-columns:1fr 1fr;gap:10px}.msg{margin-bottom:18px;padding:14px 16px;border-radius:16px}.msg.success{background:var(--success-soft);color:var(--ok)}.msg.error{background:var(--danger-soft);color:var(--error)}
    table{width:100%;border-collapse:collapse}th,td{padding:12px 10px;border-bottom:1px solid var(--border);text-align:left;vertical-align:top;font-size:.93rem}th{color:var(--muted);font-size:.82rem;text-transform:uppercase;letter-spacing:.04em}
    .tag{display:inline-flex;padding:6px 10px;border-radius:999px;font-size:.8rem;font-weight:800}.tag.admin{background:var(--red-dim);color:var(--brand)}.tag.user{background:var(--info-soft);color:var(--info-strong)}.tag.active{background:var(--success-soft);color:var(--ok)}.tag.inactive{background:var(--danger-soft);color:var(--error)}
    .mini-form{display:grid;gap:8px}.mini-form .row{grid-template-columns:1.3fr 1fr 1fr}.logs{max-height:760px;overflow:auto}.small{font-size:.84rem;color:var(--muted)}
    @media (max-width:1100px){.grid{grid-template-columns:1fr}} @media (max-width:800px){.row,.mini-form .row{grid-template-columns:1fr}}
    @media (max-width:600px){.wrap{padding:14px}.top h1{font-size:1.5rem}.panel{padding:16px;border-radius:18px}table{display:block;overflow-x:auto;-webkit-overflow-scrolling:touch}th,td{font-size:.85rem;padding:10px 8px}.btn{padding:10px 14px;font-size:.9rem}}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="top">
      <div>
        <h1>Administração de usuários</h1>
        <div class="muted">Logado como <?php echo htmlspecialchars((string) ($currentUser['full_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars((string) ($currentUser['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
      </div>
      <div class="actions">
        <a class="btn" href="observabilidade.php">Observabilidade</a>
        <a class="btn" href="reconciliacao.php">Conciliação fiscal</a>
        <a class="btn" href="index.php">Voltar ao cockpit</a>
        <a class="btn" href="logout.php">Sair</a>
      </div>
    </div>

    <?php if ($message !== null): ?>
      <div class="msg <?php echo htmlspecialchars($messageType, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <div class="grid">
      <div class="panel">
        <h2>Novo usuário</h2>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>" />
          <input type="hidden" name="action" value="create_user" />
          <div class="field"><label>Nome completo</label><input name="full_name" required /></div>
          <div class="field"><label>Usuário</label><input name="username" required /></div>
          <div class="field"><label>E-mail</label><input name="email" type="email" /></div>
          <div class="field"><label>Senha inicial</label><input name="password" type="password" required /></div>
          <div class="row">
            <div class="field"><label>Perfil</label><select name="role"><option value="user">Usuário</option><option value="admin">Administrador</option></select></div>
            <div class="field"><label>Status</label><select name="is_active"><option value="1">Ativo</option></select></div>
          </div>
          <button class="btn primary" type="submit">Criar usuário</button>
        </form>
        <div class="small" style="margin-top:14px">Os acessos ficam gravados em <strong>biomie_access_logs</strong> e os usuários em <strong>biomie_users</strong>.</div>
      </div>

      <div class="panel">
        <h2>Usuários cadastrados</h2>
        <table>
          <thead>
            <tr><th>Usuário</th><th>Perfil</th><th>Status</th><th>Último login</th><th>Ações</th></tr>
          </thead>
          <tbody>
          <?php foreach ($users as $user): ?>
            <tr>
              <td>
                <strong><?php echo htmlspecialchars((string) $user['full_name'], ENT_QUOTES, 'UTF-8'); ?></strong><br />
                <span class="small"><?php echo htmlspecialchars((string) $user['username'], ENT_QUOTES, 'UTF-8'); ?><?php echo !empty($user['email']) ? ' · ' . htmlspecialchars((string) $user['email'], ENT_QUOTES, 'UTF-8') : ''; ?></span>
              </td>
              <td><span class="tag <?php echo htmlspecialchars((string) $user['role'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) $user['role'], ENT_QUOTES, 'UTF-8'); ?></span></td>
              <td><span class="tag <?php echo (int) $user['is_active'] === 1 ? 'active' : 'inactive'; ?>"><?php echo (int) $user['is_active'] === 1 ? 'ativo' : 'inativo'; ?></span></td>
              <td><?php echo $user['last_login_at'] ? htmlspecialchars((string) $user['last_login_at'], ENT_QUOTES, 'UTF-8') : '—'; ?></td>
              <td>
                <form method="post" class="mini-form" style="margin-bottom:12px">
                  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>" />
                  <input type="hidden" name="action" value="update_user" />
                  <input type="hidden" name="user_id" value="<?php echo (int) $user['id']; ?>" />
                  <div class="row">
                    <input name="full_name" value="<?php echo htmlspecialchars((string) $user['full_name'], ENT_QUOTES, 'UTF-8'); ?>" required />
                    <select name="role">
                      <option value="user" <?php echo $user['role'] === 'user' ? 'selected' : ''; ?>>Usuário</option>
                      <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>>Administrador</option>
                    </select>
                    <label style="display:flex;align-items:center;gap:8px;margin:0"><input type="checkbox" name="is_active" <?php echo (int) $user['is_active'] === 1 ? 'checked' : ''; ?> style="width:auto" /> Ativo</label>
                  </div>
                  <input name="email" type="email" value="<?php echo htmlspecialchars((string) ($user['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="E-mail" />
                  <button class="btn" type="submit">Salvar perfil</button>
                </form>
                <form method="post" class="mini-form">
                  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>" />
                  <input type="hidden" name="action" value="reset_password" />
                  <input type="hidden" name="user_id" value="<?php echo (int) $user['id']; ?>" />
                  <input name="password" type="password" placeholder="Nova senha" required />
                  <button class="btn" type="submit">Redefinir senha</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="panel" style="margin-top:20px">
      <h2>Últimos logs de acesso</h2>
      <div class="logs">
        <table>
          <thead><tr><th>Quando</th><th>Evento</th><th>Usuário</th><th>Origem</th><th>Rota</th><th>Detalhes</th></tr></thead>
          <tbody>
          <?php foreach ($logs as $log): ?>
            <tr>
              <td><?php echo htmlspecialchars((string) $log['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?php echo htmlspecialchars((string) $log['event_type'], ENT_QUOTES, 'UTF-8'); ?></td>
              <td>
                <?php echo htmlspecialchars((string) ($log['full_name'] ?: $log['username_attempted'] ?: '—'), ENT_QUOTES, 'UTF-8'); ?>
                <?php if (!empty($log['username'])): ?><div class="small"><?php echo htmlspecialchars((string) $log['username'], ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
              </td>
              <td>
                <div><?php echo htmlspecialchars((string) ($log['ip_address'] ?: '—'), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="small"><?php echo htmlspecialchars((string) ($log['user_agent'] ?: '—'), ENT_QUOTES, 'UTF-8'); ?></div>
              </td>
              <td><?php echo htmlspecialchars((string) ($log['route'] ?: '—'), ENT_QUOTES, 'UTF-8'); ?></td>
              <td><div class="small"><?php echo htmlspecialchars((string) ($log['details_json'] ?: '—'), ENT_QUOTES, 'UTF-8'); ?></div></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</body>
</html>

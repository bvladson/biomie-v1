<?php

declare(strict_types=1);

use BIomie\Auth;

require __DIR__ . '/app/page_bootstrap.php';

if (Auth::isAuthenticated()) {
    header('Location: ' . Auth::redirectAfterLogin($_GET['redirect'] ?? null));
    exit;
}

$hasUsers = Auth::userCount() > 0;
$message = null;
$messageType = 'info';
$redirect = (string) ($_GET['redirect'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf($_POST['csrf_token'] ?? null)) {
        $message = 'Sessão do formulário expirada. Atualize a página e tente novamente.';
        $messageType = 'error';
    } elseif (!$hasUsers && ($_POST['action'] ?? '') === 'setup_first_admin') {
        $result = Auth::createFirstAdmin(
            (string) ($_POST['username'] ?? ''),
            (string) ($_POST['full_name'] ?? ''),
            trim((string) ($_POST['email'] ?? '')) !== '' ? (string) $_POST['email'] : null,
            (string) ($_POST['password'] ?? '')
        );
        if (!empty($result['auto_login'])) {
            header('Location: ' . Auth::url('index.php'));
            exit;
        }
        $message = $result['message'];
        $messageType = $result['ok'] ? 'success' : 'error';
        $hasUsers = Auth::userCount() > 0;
    } elseif ($hasUsers && ($_POST['action'] ?? '') === 'login') {
        $result = Auth::attemptLogin((string) ($_POST['identifier'] ?? ''), (string) ($_POST['password'] ?? ''));
        if ($result['ok']) {
            header('Location: ' . Auth::redirectAfterLogin((string) ($_POST['redirect'] ?? $redirect)));
            exit;
        }
        $message = $result['message'];
        $messageType = 'error';
    }
}

$csrf = Auth::csrfToken();

// CSP for login page.
// - img-src allows Unsplash CDN (images served hot-linked from the browser).
// - connect-src 'self' only — browser never calls api.unsplash.com directly (PHP proxy handles it).
// - font-src allows Google Fonts glyphs.
header(
    "Content-Security-Policy: " .
    "default-src 'self'; " .
    "img-src 'self' data: https://images.unsplash.com https://plus.unsplash.com; " .
    "connect-src 'self'; " .
    "script-src 'self'; " .
    "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; " .
    "font-src 'self' https://fonts.gstatic.com data:; " .
    "base-uri 'self'; " .
    "frame-ancestors 'none'; " .
    "form-action 'self'"
);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Login | BIomie</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <style>
    :root{--bg:#f6f3f2;--card:#fff;--text:#303030;--muted:#828282;--border:#e5e1e1;--brand:#96140A;--brand-dark:#7C0600;--ok:#107c41;--error:#b42318}
    *{box-sizing:border-box}body{margin:0;font-family:'Plus Jakarta Sans',system-ui,sans-serif;background:radial-gradient(circle at top,#fff 0,#f6f3f2 52%,#ece7e5 100%);color:var(--text)}
    .page{min-height:100vh;display:grid;place-items:center;padding:24px}.card{width:min(100%,460px);background:var(--card);border:1px solid var(--border);border-radius:28px;padding:32px;box-shadow:0 18px 60px rgba(48,48,48,.08)}
    .logo{display:flex;align-items:center;gap:14px;margin-bottom:18px}.logo img{height:42px}.eyebrow{display:inline-flex;padding:8px 12px;border-radius:999px;background:#f9eceb;color:var(--brand);font-weight:700;font-size:.85rem;margin-bottom:14px}
    h1{font-size:1.75rem;margin:0 0 8px}p{margin:0 0 22px;color:var(--muted);line-height:1.6}.field{margin-bottom:14px}label{display:block;font-size:.92rem;font-weight:700;margin-bottom:8px}
    input,select{width:100%;padding:14px 16px;border-radius:16px;border:1px solid var(--border);background:#fff;font:inherit}input:focus,select:focus{outline:2px solid rgba(150,20,10,.16);border-color:var(--brand)}
    .btn{width:100%;border:0;border-radius:16px;padding:15px 18px;background:var(--brand);color:#fff;font:inherit;font-weight:800;cursor:pointer}.btn:hover{background:var(--brand-dark)}
    .msg{margin-bottom:18px;padding:14px 16px;border-radius:16px;font-size:.95rem}.msg.success{background:#ecfdf3;color:var(--ok)}.msg.error{background:#fef3f2;color:var(--error)}
    .meta{margin-top:18px;padding-top:18px;border-top:1px solid var(--border);font-size:.82rem;color:var(--muted);text-align:center}
    .small{font-size:.88rem;color:var(--muted)}
    @media (max-width:500px){.card{padding:24px 20px;border-radius:20px}h1{font-size:1.4rem}}
  </style>
  <link rel="stylesheet" href="assets/css/login-wallpaper.css" />
  <link rel="preload" href="assets/img/login-fallback.svg" as="image" type="image/svg+xml" />
</head>
<body>
  <div id="lw-backdrop" class="lw-backdrop" aria-hidden="true"></div>
  <div class="lw-orb lw-orb-1" aria-hidden="true"></div>
  <div class="lw-orb lw-orb-2" aria-hidden="true"></div>
  <div class="lw-orb lw-orb-3" aria-hidden="true"></div>

  <div class="page">
    <aside class="lw-panel-left" aria-hidden="true">
      <div class="lw-brand-tag">BIOMIE MAG</div>
      <h2 class="lw-headline">Gestão comercial<br>em uma só visão.</h2>
      <p class="lw-sub">Acompanhe vendas, metas, clientes e resultados com clareza.</p>

      <div class="lw-floating-cards">
        <div class="lw-floating-card lw-fc-sales">
          <div class="lw-fc-icon">↗</div>
          <div>
            <strong>Vendas</strong>
            <small>Visão clara do mês</small>
          </div>
        </div>
        <div class="lw-floating-card lw-fc-targets">
          <div class="lw-fc-icon">◎</div>
          <div>
            <strong>Metas</strong>
            <small>Progresso por vendedor</small>
          </div>
        </div>
        <div class="lw-floating-card lw-fc-clients">
          <div class="lw-fc-icon">♦</div>
          <div>
            <strong>Clientes</strong>
            <small>Carteira e histórico</small>
          </div>
        </div>
        <div class="lw-floating-card lw-fc-products">
          <div class="lw-fc-icon">▤</div>
          <div>
            <strong>Produtos</strong>
            <small>Mix e performance</small>
          </div>
        </div>
      </div>

      <div class="lw-flow-line" aria-hidden="true">
        <span class="lw-flow-node">Omie</span>
        <span class="lw-flow-arrow">→</span>
        <span class="lw-flow-node">BIomie</span>
        <span class="lw-flow-arrow">→</span>
        <span class="lw-flow-node">Decisão</span>
      </div>
    </aside>

    <div class="card">
      <div class="logo">
        <img src="images/logo-mag-wide.png" alt="MAG" id="lw-logo" />
      </div>
      <div class="eyebrow"><?php echo $hasUsers ? 'Acesso protegido' : 'Configuração inicial'; ?></div>
      <h1><?php echo $hasUsers ? 'Entrar no BIomie' : 'Criar o primeiro administrador'; ?></h1>
      <p><?php echo $hasUsers
          ? 'Acesse o painel da MAG e acompanhe sua operação com segurança.'
          : 'Na primeira execução, crie um usuário administrador para liberar o acesso ao painel.'; ?></p>

      <?php if ($message !== null): ?>
        <div class="msg <?php echo htmlspecialchars($messageType, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
      <?php endif; ?>

      <?php if ($hasUsers): ?>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>" />
          <input type="hidden" name="action" value="login" />
          <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8'); ?>" />
          <div class="field">
            <label for="identifier">Usuário ou e-mail</label>
            <input id="identifier" name="identifier" autocomplete="username" required />
          </div>
          <div class="field">
            <label for="password">Senha</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required />
          </div>
          <button class="btn" type="submit">Entrar no painel</button>
        </form>
      <?php else: ?>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>" />
          <input type="hidden" name="action" value="setup_first_admin" />
          <div class="field">
            <label for="full_name">Nome completo</label>
            <input id="full_name" name="full_name" autocomplete="name" required />
          </div>
          <div class="field">
            <label for="username">Usuário</label>
            <input id="username" name="username" autocomplete="username" required />
          </div>
          <div class="field">
            <label for="email">E-mail</label>
            <input id="email" name="email" type="email" autocomplete="email" />
          </div>
          <div class="field">
            <label for="password">Senha inicial</label>
            <input id="password" name="password" type="password" autocomplete="new-password" required />
            <div class="small">Use no mínimo 8 caracteres com letras e números.</div>
          </div>
          <button class="btn" type="submit">Criar administrador</button>
        </form>
      <?php endif; ?>

      <div class="meta">Painel comercial &bull; MAG Móveis Corporativos</div>
    </div>
  </div>

  <!-- Unsplash attribution — built via DOM in login-wallpaper.js (no innerHTML with external data) -->
  <div id="lw-credit" class="lw-credit" aria-label="Crédito da imagem"></div>

  <!-- Remove [hidden] temporarily to inspect source: unsplash | cache | session | fallback | fetch_error | … -->
  <div id="lw-debug" hidden class="lw-debug"></div>

  <script defer src="assets/js/login-wallpaper.js"></script>
</body>
</html>

<?php

declare(strict_types=1);

use BIomie\Auth;

require __DIR__ . '/app/page_bootstrap.php';

if (Auth::isAuthenticated()) {
    header('Location: ' . Auth::url('select_empresa.php'));
    exit;
}

$hasUsers    = Auth::userCount() > 0;
$message     = null;
$messageType = 'info';
$redirect    = (string) ($_GET['redirect'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf($_POST['csrf_token'] ?? null)) {
        $message     = 'Sessão do formulário expirada. Atualize a página e tente novamente.';
        $messageType = 'error';
    } elseif (!$hasUsers && ($_POST['action'] ?? '') === 'setup_first_admin') {
        $result = Auth::createFirstAdmin(
            (string) ($_POST['username'] ?? ''),
            (string) ($_POST['full_name'] ?? ''),
            trim((string) ($_POST['email'] ?? '')) !== '' ? (string) $_POST['email'] : null,
            (string) ($_POST['password'] ?? '')
        );
        if (!empty($result['auto_login'])) {
            header('Location: ' . Auth::url('select_empresa.php'));
            exit;
        }
        $message     = $result['message'];
        $messageType = $result['ok'] ? 'success' : 'error';
        $hasUsers    = Auth::userCount() > 0;
    } elseif ($hasUsers && ($_POST['action'] ?? '') === 'login') {
        $result = Auth::attemptLogin((string) ($_POST['identifier'] ?? ''), (string) ($_POST['password'] ?? ''));
        if ($result['ok']) {
            // Always go through company selector after login.
            // If a specific redirect was requested (e.g. from a protected page),
            // carry it as query param so selector can forward after company pick.
            $postLoginRedirect = (string) ($_POST['redirect'] ?? $redirect);
            $selectorUrl = Auth::url('select_empresa.php');
            if ($postLoginRedirect !== '' && !str_ends_with($postLoginRedirect, 'login.php') && !str_ends_with($postLoginRedirect, 'select_empresa.php')) {
                $selectorUrl .= '?redirect=' . urlencode($postLoginRedirect);
            }
            header('Location: ' . $selectorUrl);
            exit;
        }
        $message     = $result['message'];
        $messageType = 'error';
    }
}

$csrf     = Auth::csrfToken();
$hasError = $message !== null && $messageType === 'error';

// CSP — script-src 'self' covers wallpaper.js + login.js (no inline scripts needed)
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
    :root {
      --bg: #f4f1ef;
      --card: #ffffff;
      --text: #1c1c1e;
      --muted: #78787a;
      --border: #e2ddd9;
      --border-focus: #96140A;
      --brand: #96140A;
      --brand-dark: #7C0600;
      --ok: #107c41;
      --error: #b42318;
      --icon-color: #b4ada9;
    }
    *, *::before, *::after { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
      background: radial-gradient(ellipse 120% 80% at 50% 0%, #fff 0%, #f4f1ef 55%, #ede8e4 100%);
      color: var(--text);
      -webkit-font-smoothing: antialiased;
    }

    /* ── Page + card ── */
    .page { min-height: 100vh; display: grid; place-items: center; padding: 24px; }
    .card {
      width: min(100%, 460px);
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 24px;
      padding: 36px 38px;
      box-shadow: 0 1px 2px rgba(0,0,0,.04), 0 8px 24px rgba(0,0,0,.06), 0 24px 64px rgba(0,0,0,.06);
    }

    /* ── Logo + eyebrow ── */
    .logo { display: flex; align-items: center; gap: 14px; margin-bottom: 22px; }
    .logo img { height: 40px; }
    .eyebrow {
      display: inline-flex; align-items: center; gap: 7px;
      padding: 5px 13px; border-radius: 999px;
      background: #f6ebe9; color: var(--brand);
      font-weight: 700; font-size: .78rem; letter-spacing: .02em;
      margin-bottom: 14px;
    }
    .eyebrow-dot {
      width: 6px; height: 6px; border-radius: 50%;
      background: var(--brand); flex-shrink: 0;
    }

    /* ── Typography ── */
    h1 {
      font-size: 1.65rem; font-weight: 800; margin: 0 0 7px;
      letter-spacing: -.025em; line-height: 1.2;
    }
    .sub { margin: 0 0 22px; color: var(--muted); line-height: 1.65; font-size: .93rem; }

    /* ── Fields + icons ── */
    .field { margin-bottom: 14px; }
    label { display: block; font-size: .86rem; font-weight: 700; margin-bottom: 7px; color: #38383a; }
    .field-wrap { position: relative; }
    .field-icon {
      position: absolute; left: 15px; top: 50%; transform: translateY(-50%);
      color: var(--icon-color); pointer-events: none; display: flex; align-items: center;
    }
    .field-wrap input { padding-left: 44px; padding-right: 16px; }
    .field-wrap input[type="password"] { padding-right: 44px; }

    /* ── Inputs ── */
    input, select {
      width: 100%; padding: 13px 16px;
      border-radius: 13px; border: 1.5px solid var(--border);
      background: #faf8f7; font: inherit; font-size: .95rem; color: var(--text);
      transition: border-color .15s, box-shadow .15s, background .15s;
    }
    input:focus, select:focus {
      outline: none; border-color: var(--brand); background: #fff;
      box-shadow: 0 0 0 3px rgba(150,20,10,.1);
    }
    input::placeholder { color: #c0b8b5; }

    /* ── Password toggle ── */
    .pw-toggle {
      position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
      border: none; background: none; cursor: pointer; color: var(--icon-color);
      padding: 5px; border-radius: 8px; line-height: 1;
      display: flex; align-items: center; transition: color .15s;
    }
    .pw-toggle:hover { color: var(--text); }
    .pw-toggle.is-showing { color: var(--brand); }

    /* ── Caps lock ── */
    .caps-warn {
      margin: 5px 0 0; font-size: .78rem; font-weight: 700; color: var(--brand);
      display: flex; align-items: center; gap: 4px; line-height: 1.3;
    }

    /* ── Submit button ── */
    .btn {
      width: 100%; border: 0; border-radius: 13px; padding: 14px 18px;
      background: var(--brand); color: #fff;
      font: inherit; font-size: .97rem; font-weight: 800; cursor: pointer;
      letter-spacing: .01em;
      box-shadow: 0 1px 2px rgba(0,0,0,.1), 0 3px 10px rgba(150,20,10,.28);
      transition: background .18s, box-shadow .18s, transform .1s;
    }
    .btn:hover { background: var(--brand-dark); box-shadow: 0 2px 4px rgba(0,0,0,.12), 0 6px 18px rgba(150,20,10,.34); }
    .btn:active { transform: scale(.985); }
    .btn--loading { opacity: .74; pointer-events: none; cursor: wait; }
    .btn--loading::after {
      content: ''; display: inline-block;
      width: 14px; height: 14px;
      border: 2px solid rgba(255,255,255,.32); border-top-color: #fff;
      border-radius: 50%; animation: spin .65s linear infinite;
      margin-left: 9px; vertical-align: middle;
    }
    @keyframes spin { to { transform: rotate(360deg); } }

    /* ── Messages ── */
    .msg {
      margin-bottom: 18px; padding: 12px 15px; border-radius: 13px;
      font-size: .88rem; font-weight: 600; line-height: 1.45;
      display: flex; align-items: flex-start; gap: 9px;
    }
    .msg-icon { flex-shrink: 0; font-style: normal; font-size: .95rem; line-height: 1.45; }
    .msg.success { background: #f0fdf6; color: var(--ok); border-left: 3px solid var(--ok); }
    .msg.error   { background: #fff2f1; color: var(--error); border-left: 3px solid var(--error); }

    /* ── Shake on error ── */
    @keyframes shake {
      0%,100% { transform: translateX(0); }
      14%     { transform: translateX(-7px); }
      28%     { transform: translateX(6px); }
      42%     { transform: translateX(-5px); }
      56%     { transform: translateX(4px); }
      70%     { transform: translateX(-2px); }
      84%     { transform: translateX(1px); }
    }
    .card--shake { animation: shake .52s ease-in-out; }

    /* ── Forgot / footer ── */
    .forgot-link {
      display: block; text-align: center; margin-top: 14px;
      font-size: .84rem; color: var(--muted); text-decoration: none;
      transition: color .15s;
    }
    .forgot-link:hover { color: var(--brand); }
    .forgot-tip {
      text-align: center; font-size: .79rem; color: var(--muted);
      margin-top: 8px; line-height: 1.5;
      animation: fade-up .22s ease both;
    }
    @keyframes fade-up { from { opacity:0; transform:translateY(4px); } to { opacity:1; transform:none; } }

    .meta {
      margin-top: 22px; padding-top: 16px; border-top: 1px solid #f0ebe7;
      font-size: .76rem; color: #c8c0bc; text-align: center; letter-spacing: .01em;
    }
    .small { font-size: .81rem; color: var(--muted); margin-top: 5px; line-height: 1.4; }
    .opt-label { font-weight: 400; color: var(--muted); font-size: .82em; }

    /* ── Responsive ── */
    @media (max-width: 500px) {
      .card { padding: 26px 22px; border-radius: 20px; }
      h1 { font-size: 1.42rem; }
    }
    @media (max-width: 380px) {
      .card { padding: 22px 18px; border-radius: 18px; }
    }
  </style>
  <link rel="stylesheet" href="assets/css/login-wallpaper.css" />
  <link rel="preload" href="assets/img/login-fallback.svg" as="image" type="image/svg+xml" />
</head>
<body<?php echo $hasError ? ' data-login-error="1"' : ''; ?>>
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
          <div class="lw-fc-icon">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,.92)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>
          </div>
          <div>
            <strong>Vendas</strong>
            <small>Visão clara do mês</small>
          </div>
        </div>
        <div class="lw-floating-card lw-fc-targets">
          <div class="lw-fc-icon">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,.92)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
          </div>
          <div>
            <strong>Metas</strong>
            <small>Progresso por vendedor</small>
          </div>
        </div>
        <div class="lw-floating-card lw-fc-clients">
          <div class="lw-fc-icon">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,.92)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          </div>
          <div>
            <strong>Clientes</strong>
            <small>Carteira e histórico</small>
          </div>
        </div>
        <div class="lw-floating-card lw-fc-products">
          <div class="lw-fc-icon">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,.92)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="3" width="7" height="7" rx="1"/><rect x="15" y="3" width="7" height="7" rx="1"/><rect x="15" y="14" width="7" height="7" rx="1"/><rect x="2" y="14" width="7" height="7" rx="1"/></svg>
          </div>
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

      <div class="eyebrow">
        <span class="eyebrow-dot" aria-hidden="true"></span>
        <?php echo $hasUsers ? 'Acesso protegido' : 'Configuração inicial'; ?>
      </div>

      <h1><?php echo $hasUsers ? 'Entrar no BIomie' : 'Criar o primeiro administrador'; ?></h1>
      <p class="sub"><?php echo $hasUsers
          ? 'Acesse o painel da MAG e acompanhe sua operação com segurança.'
          : 'Na primeira execução, crie um usuário administrador para liberar o acesso ao painel.'; ?></p>

      <?php if ($message !== null): ?>
        <div class="msg <?php echo htmlspecialchars($messageType, ENT_QUOTES, 'UTF-8'); ?>" role="alert">
          <i class="msg-icon" aria-hidden="true"><?php echo $messageType === 'error' ? '⚠' : '✓'; ?></i>
          <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
        </div>
      <?php endif; ?>

      <?php if ($hasUsers): ?>
        <form method="post" id="login-form" novalidate>
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>" />
          <input type="hidden" name="action" value="login" />
          <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8'); ?>" />

          <div class="field">
            <label for="identifier">Usuário ou e-mail</label>
            <div class="field-wrap">
              <span class="field-icon" aria-hidden="true">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              </span>
              <input id="identifier" name="identifier" autocomplete="username" autofocus required enterkeyhint="next" />
            </div>
          </div>

          <div class="field">
            <label for="password">Senha</label>
            <div class="field-wrap">
              <span class="field-icon" aria-hidden="true">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              </span>
              <input id="password" name="password" type="password" autocomplete="current-password" required enterkeyhint="go" />
            </div>
            <p class="caps-warn" id="caps-warn" hidden aria-live="polite">⇪ Caps Lock ativo</p>
          </div>

          <button class="btn" type="submit">Entrar no painel</button>
          <a href="#" id="forgot-link" class="forgot-link">Esqueceu a senha?</a>
        </form>

      <?php else: ?>
        <form method="post" id="login-form" novalidate>
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>" />
          <input type="hidden" name="action" value="setup_first_admin" />

          <div class="field">
            <label for="full_name">Nome completo</label>
            <div class="field-wrap">
              <span class="field-icon" aria-hidden="true">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              </span>
              <input id="full_name" name="full_name" autocomplete="name" autofocus required />
            </div>
          </div>

          <div class="field">
            <label for="username">Usuário</label>
            <div class="field-wrap">
              <span class="field-icon" aria-hidden="true">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M16 8v5a3 3 0 0 0 6 0v-1a10 10 0 1 0-3.92 7.94"/></svg>
              </span>
              <input id="username" name="username" autocomplete="username" required />
            </div>
          </div>

          <div class="field">
            <label for="email">E-mail <span class="opt-label">(opcional)</span></label>
            <div class="field-wrap">
              <span class="field-icon" aria-hidden="true">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
              </span>
              <input id="email" name="email" type="email" autocomplete="email" />
            </div>
          </div>

          <div class="field">
            <label for="password">Senha inicial</label>
            <div class="field-wrap">
              <span class="field-icon" aria-hidden="true">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              </span>
              <input id="password" name="password" type="password" autocomplete="new-password" required enterkeyhint="go" />
            </div>
            <p class="caps-warn" id="caps-warn" hidden aria-live="polite">⇪ Caps Lock ativo</p>
            <div class="small">Use no mínimo 8 caracteres com letras e números.</div>
          </div>

          <button class="btn" type="submit">Criar administrador</button>
        </form>
      <?php endif; ?>

      <div class="meta">Painel comercial &bull; MAG Móveis Corporativos</div>
    </div>
  </div>

  <div id="lw-credit" class="lw-credit" aria-label="Crédito da imagem"></div>
  <div id="lw-debug" hidden class="lw-debug"></div>

  <script defer src="assets/js/login-wallpaper.js"></script>
  <script defer src="assets/js/login.js"></script>
</body>
</html>

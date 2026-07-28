<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Company\CompanyPackageLoader;
use BIomie\Support\Asset;
use BIomie\Support\TenantContext;

require __DIR__ . '/app/page_bootstrap.php';

// Auth guard — redirect to login if not authenticated
if (!Auth::isAuthenticated()) {
    $redirect = $_SERVER['REQUEST_URI'] ?? '';
    header('Location: ' . Auth::url('login.php') . ($redirect !== '' ? '?redirect=' . urlencode($redirect) : ''));
    exit;
}

// Load accessible companies (already cached by TenantContext::boot)
$seCompanies      = TenantContext::cachedAccessible();
// Flag do contexto "Todas as operações" (Fase D, Incremento 1B). Só quando ON a
// opção mixed aparece — e apenas para quem tem os DOIS canais na empresa.
$seMixedEnabled   = (bool) (new \BIomie\Config())->get('features.mixed_company_context_enabled', false);
// R1: o contexto de grupo permanece oculto até que os leitores consolidados
// estejam allowlisted e provem empresa + canal em todas as consultas.
$seHasConsolidado = false;

$seUser      = Auth::currentUser();
$seFullName  = (string) ($seUser['full_name'] ?? $seUser['username'] ?? 'Usuário');
$seFirstName = explode(' ', trim($seFullName))[0];

// After company selection, where to go
$seRedirect = Auth::redirectAfterLogin((string) ($_GET['redirect'] ?? ''));
// Prevent redirect loops — if redirect points back here, go to dashboard
if (str_contains($seRedirect, 'select_empresa')) {
    $seRedirect = Auth::url('index.php');
}

$seCsrf = Auth::csrfToken();

$seFormatCnpj = static function (string $value): string {
    $digits = preg_replace('/\D+/', '', $value) ?? '';
    if (strlen($digits) !== 14) {
        return trim($value);
    }
    return substr($digits, 0, 2) . '.' . substr($digits, 2, 3) . '.'
        . substr($digits, 5, 3) . '/' . substr($digits, 8, 4) . '-'
        . substr($digits, 12, 2);
};

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
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Selecionar Empresa | BIomie</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="<?= Auth::url(Asset::url('assets/css/login-wallpaper.css')) ?>" />
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
      background: #111827;
      color: #1c1c1e;
      -webkit-font-smoothing: antialiased;
      min-height: 100vh;
    }

    /* ── Page shell ── */
    .se-page {
      position: relative;
      z-index: 1;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      padding: 40px 24px 60px;
    }

    /* ── Header ── */
    .se-header {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 20px;
      margin-bottom: 36px;
      text-align: center;
    }

    .se-logo {
      height: 36px;
      filter: brightness(0) invert(1);
      opacity: .9;
    }

    .se-greeting-hi {
      font-size: clamp(1.5rem, 3vw, 2.2rem);
      font-weight: 800;
      color: #fff;
      letter-spacing: -.025em;
      line-height: 1.1;
      text-shadow: 0 2px 24px rgba(0,0,0,.5);
    }

    .se-greeting-sub {
      font-size: .95rem;
      color: rgba(255,255,255,.62);
      font-weight: 500;
      margin-top: 6px;
    }

    /* ── Consolidado hero card ── */
    .se-hero {
      width: 100%;
      max-width: 860px;
      margin-bottom: 28px;
    }

    .se-hero-card {
      border-radius: 20px;
      background: rgba(255,255,255,.08);
      backdrop-filter: blur(18px);
      -webkit-backdrop-filter: blur(18px);
      border: 1.5px solid rgba(255,255,255,.22);
      box-shadow: 0 8px 40px rgba(0,0,0,.35), 0 1px 0 rgba(255,255,255,.12) inset;
      display: flex;
      align-items: center;
      gap: 20px;
      padding: 22px 28px;
      cursor: default;
      transition: background .2s, border-color .2s, transform .2s, box-shadow .2s;
      text-align: left;
      width: 100%;
    }

    .se-hero-card:hover {
      background: rgba(255,255,255,.13);
      border-color: rgba(255,255,255,.42);
      transform: translateY(-3px);
      box-shadow: 0 16px 48px rgba(0,0,0,.4), 0 1px 0 rgba(255,255,255,.15) inset;
    }

    .se-hero-icon {
      width: 56px;
      height: 56px;
      border-radius: 14px;
      background: rgba(255,255,255,.12);
      border: 1px solid rgba(255,255,255,.2);
      display: flex;
      align-items: center;
      justify-content: center;
      color: rgba(255,255,255,.85);
      flex-shrink: 0;
    }

    .se-hero-info {
      flex: 1;
      min-width: 0;
    }

    .se-hero-title {
      font-size: 1.08rem;
      font-weight: 800;
      color: #fff;
      letter-spacing: -.02em;
    }

    .se-hero-sub {
      font-size: .82rem;
      color: rgba(255,255,255,.55);
      margin-top: 3px;
      font-weight: 500;
    }

    .se-hero-arrow {
      color: rgba(255,255,255,.45);
      flex-shrink: 0;
      transition: color .15s, transform .15s;
    }

    .se-hero-card:hover .se-hero-arrow {
      color: rgba(255,255,255,.8);
      transform: translateX(3px);
    }

    /* ── Section label ── */
    .se-section-label {
      width: 100%;
      max-width: 860px;
      font-size: .72rem;
      font-weight: 700;
      letter-spacing: .1em;
      text-transform: uppercase;
      color: rgba(255,255,255,.38);
      margin-bottom: 14px;
      padding-left: 2px;
    }

    /* ── Company grid ── */
    .se-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 18px;
      width: 100%;
      max-width: 860px;
    }

    @media (max-width: 820px) {
      .se-grid { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 540px) {
      .se-grid { grid-template-columns: 1fr; }
      .se-hero-card { flex-wrap: wrap; }
    }

    /* ── Card ── */
    .se-card {
      border-radius: 18px;
      overflow: hidden;
      background: rgba(255,255,255,.93);
      backdrop-filter: blur(18px);
      -webkit-backdrop-filter: blur(18px);
      border: 1px solid rgba(255,255,255,.55);
      box-shadow: 0 6px 24px rgba(0,0,0,.24), 0 1px 0 rgba(255,255,255,.5) inset;
      display: flex;
      flex-direction: column;
      cursor: pointer;
      transition: transform .22s cubic-bezier(.34,1.56,.64,1), box-shadow .22s ease;
      position: relative;
    }

    .se-card:hover {
      transform: translateY(-5px) scale(1.01);
      box-shadow:
        0 18px 48px rgba(0,0,0,.32),
        0 0 0 2px var(--card-color),
        0 1px 0 rgba(255,255,255,.5) inset;
    }

    .se-hero-card:focus-visible,
    .se-btn-enter:focus-visible,
    .se-canal-btn:focus-visible,
    .se-logout a:focus-visible {
      outline: 3px solid #fff;
      outline-offset: 3px;
    }

    .se-btn-enter:focus-visible,
    .se-canal-btn:focus-visible {
      outline-color: var(--card-color);
    }

    .se-card--inactive {
      opacity: .78;
      filter: saturate(.5);
    }

    .se-card--inactive:hover {
      transform: none;
      box-shadow: 0 6px 24px rgba(0,0,0,.24), 0 1px 0 rgba(255,255,255,.5) inset;
      cursor: default;
    }

    .se-card.is-loading {
      pointer-events: none;
    }

    .se-card.is-loading::after {
      content: '';
      position: absolute;
      inset: 0;
      background: rgba(255,255,255,.55);
      backdrop-filter: blur(4px);
      border-radius: 18px;
      z-index: 10;
    }

    /* ── Card head (brand color zone) ── */
    .se-card-head {
      height: 120px;
      background: var(--card-color);
      background-image: linear-gradient(135deg, var(--card-color) 0%, var(--card-color-dark, var(--card-color)) 100%);
      display: flex;
      align-items: center;
      justify-content: center;
      position: relative;
      overflow: hidden;
    }

    .se-card-head::before {
      content: '';
      position: absolute;
      inset: 0;
      background:
        radial-gradient(circle at 80% 20%, rgba(255,255,255,.18) 0%, transparent 60%),
        radial-gradient(circle at 20% 80%, rgba(0,0,0,.15) 0%, transparent 50%);
    }

    /* Status badge in card head */
    .se-status-badge {
      position: absolute;
      top: 10px;
      right: 10px;
      font-size: .6rem;
      font-weight: 800;
      letter-spacing: .06em;
      text-transform: uppercase;
      padding: 3px 7px;
      border-radius: 5px;
      z-index: 2;
      line-height: 1.4;
    }

    .se-status-badge--ativa {
      background: rgba(16,185,129,.18);
      color: #d1fae5;
      border: 1px solid rgba(16,185,129,.35);
    }

    .se-status-badge--pendente {
      background: rgba(245,158,11,.18);
      color: #fef3c7;
      border: 1px solid rgba(245,158,11,.35);
    }

    .se-status-badge--inativa {
      background: rgba(0,0,0,.22);
      color: rgba(255,255,255,.6);
      border: 1px solid rgba(255,255,255,.15);
    }

    .se-logo-wrap {
      width: 72px;
      height: 72px;
      border-radius: 16px;
      background: #fff;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 4px 20px rgba(0,0,0,.22);
      position: relative;
      z-index: 1;
    }

    .se-logo-img {
      width: 54px;
      height: 54px;
      object-fit: contain;
      mix-blend-mode: multiply;
    }

    .se-initials {
      font-size: 1.4rem;
      font-weight: 800;
      color: var(--card-color);
      letter-spacing: -.02em;
    }

    /* ── Card body ── */
    .se-card-body {
      padding: 16px 18px 10px;
      flex: 1;
      display: flex;
      flex-direction: column;
      gap: 10px;
    }

    .se-card-name {
      font-size: .96rem;
      font-weight: 800;
      color: #1c1c1e;
      letter-spacing: -.015em;
      display: flex;
      align-items: center;
      gap: 6px;
      flex-wrap: wrap;
    }

    .se-type-badge {
      font-size: .57rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .06em;
      padding: 2px 6px;
      border-radius: 4px;
      line-height: 1.5;
      flex-shrink: 0;
    }
    .se-type-badge--matriz {
      background: color-mix(in srgb, var(--card-color) 12%, transparent);
      color: var(--card-color);
      border: 1px solid color-mix(in srgb, var(--card-color) 20%, transparent);
    }
    .se-type-badge--filial {
      background: rgba(107,114,128,.10);
      color: #6b7280;
      border: 1px solid rgba(107,114,128,.15);
    }

    /* ── Canal toggle ── */
    .se-canal-row {
      display: flex;
      gap: 6px;
    }

    .se-canal-btn {
      flex: 1;
      padding: 5px 8px;
      border-radius: 8px;
      border: 1.5px solid #e0dbd7;
      background: #faf8f7;
      font: inherit;
      font-size: .76rem;
      font-weight: 700;
      color: #78787a;
      cursor: pointer;
      transition: border-color .15s, background .15s, color .15s;
    }

    .se-canal-btn.active,
    .se-canal-btn:hover {
      border-color: var(--card-color);
      background: color-mix(in srgb, var(--card-color) 10%, #fff);
      color: var(--card-color);
    }

    .se-canal-label {
      font-size: .78rem;
      font-weight: 600;
      color: #9ca3af;
      padding: 4px 0 2px;
    }

    .se-card-cnpj {
      color: #78787a;
      font-size: .72rem;
      line-height: 1.4;
      font-variant-numeric: tabular-nums;
    }

    .se-empty {
      grid-column: 1 / -1;
      padding: 34px 24px;
      border: 1px solid rgba(255,255,255,.28);
      border-radius: 18px;
      background: rgba(17,24,39,.72);
      color: #fff;
      text-align: center;
      backdrop-filter: blur(14px);
    }

    .se-empty p {
      color: rgba(255,255,255,.68);
      margin-top: 8px;
      font-size: .88rem;
    }

    /* ── Card footer ── */
    .se-card-foot {
      padding: 0 18px 18px;
    }

    .se-btn-enter {
      width: 100%;
      padding: 11px 14px;
      border-radius: 11px;
      border: none;
      background: var(--card-color);
      color: #fff;
      font: inherit;
      font-size: .88rem;
      font-weight: 800;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      transition: background .15s, box-shadow .15s, transform .1s;
      box-shadow: 0 2px 10px color-mix(in srgb, var(--card-color) 50%, transparent);
    }

    .se-btn-enter:hover {
      background: var(--card-color-dark, var(--card-color));
      filter: brightness(1.08);
      box-shadow: 0 4px 18px color-mix(in srgb, var(--card-color) 60%, transparent);
    }

    .se-btn-enter:active { transform: scale(.97); }

    .se-btn-enter--disabled {
      background: #d1d5db;
      color: #9ca3af;
      cursor: not-allowed;
      box-shadow: none;
      pointer-events: none;
    }

    .se-spinner {
      display: none;
      width: 16px; height: 16px;
      border: 2px solid rgba(255,255,255,.4);
      border-top-color: #fff;
      border-radius: 50%;
      animation: se-spin .65s linear infinite;
    }

    .is-loading .se-spinner { display: block; }
    .is-loading .se-arrow   { display: none; }

    @keyframes se-spin { to { transform: rotate(360deg); } }

    /* ── Error toast ── */
    .se-toast {
      position: fixed;
      bottom: 28px;
      left: 50%;
      transform: translateX(-50%) translateY(80px);
      background: #1c1c1e;
      color: #fff;
      padding: 12px 22px;
      border-radius: 12px;
      font-size: .88rem;
      font-weight: 600;
      box-shadow: 0 8px 32px rgba(0,0,0,.4);
      transition: transform .3s cubic-bezier(.34,1.56,.64,1);
      z-index: 100;
      pointer-events: none;
      white-space: nowrap;
    }

    .se-toast.visible { transform: translateX(-50%) translateY(0); }

    .se-context-reset {
      width: min(100%, 880px);
      margin: -18px auto 24px;
      padding: 12px 16px;
      border: 1px solid rgba(245, 158, 11, .42);
      border-radius: 12px;
      background: rgba(255, 251, 235, .95);
      color: #92400e;
      font-size: 13px;
      font-weight: 650;
      text-align: center;
      box-shadow: 0 8px 24px rgba(15, 23, 42, .08);
    }

    /* ── Logout link ── */
    .se-logout {
      margin-top: 32px;
      font-size: .8rem;
      color: rgba(255,255,255,.42);
      text-align: center;
    }

    .se-session-note {
      display: block;
      max-width: 520px;
      margin-bottom: 12px;
      color: rgba(255,255,255,.58);
      line-height: 1.5;
    }

    .se-logout a {
      color: rgba(255,255,255,.55);
      text-decoration: underline;
      text-underline-offset: 3px;
      transition: color .15s;
    }

    .se-logout a:hover { color: rgba(255,255,255,.85); }

    /* ── Mobile ── */
    @media (max-width: 500px) {
      .se-page { padding: 32px 16px 48px; }
      .se-header { margin-bottom: 24px; }
    }
  </style>
</head>
<body>

<!-- Wallpaper backdrop (reuses login system) -->
<div class="lw-backdrop" id="lw-backdrop" aria-hidden="true"></div>
<div class="lw-orb lw-orb-1" aria-hidden="true"></div>
<div class="lw-orb lw-orb-2" aria-hidden="true"></div>
<div class="lw-orb lw-orb-3" aria-hidden="true"></div>

<!-- Page -->
<div class="se-page">

  <!-- Header -->
  <header class="se-header">
    <img src="<?= Auth::url(Asset::url('images/logo-mag-wide.png')) ?>" alt="BIomie" class="se-logo" id="lw-logo" />
    <div>
      <div class="se-greeting-hi">Olá, <?= htmlspecialchars($seFirstName, ENT_QUOTES, 'UTF-8') ?>!</div>
      <div class="se-greeting-sub">Selecione a empresa para continuar</div>
    </div>
  </header>

  <?php if ((string) ($_GET['context_reset'] ?? '') === '1'): ?>
  <div class="se-context-reset" role="status">
    O contexto desta aba expirou ou foi encerrado. Selecione novamente a empresa para continuar com segurança.
  </div>
  <?php endif; ?>

  <?php if ($seHasConsolidado): ?>
  <!-- Consolidado hero card -->
  <div class="se-hero">
    <button class="se-hero-card" id="se-btn-consolidado" type="button">
      <div class="se-hero-icon">
        <svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
      </div>
      <div class="se-hero-info">
        <div class="se-hero-title">Visão Consolidada — Grupo</div>
        <div class="se-hero-sub">Todas as empresas agregadas em um único painel</div>
      </div>
      <svg class="se-hero-arrow" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
    </button>
  </div>
  <?php endif; ?>

  <?php if (!empty($seCompanies)): ?>
  <div class="se-section-label">Empresas</div>
  <?php endif; ?>

  <!-- Company cards -->
  <div class="se-grid" id="se-grid">
<?php foreach ($seCompanies as $company):
    $slug        = strtolower((string) $company['slug']);
    $cid         = (int) $company['id'];
    $displayName = (string) $company['nome_fantasia'];
    $canVarejo   = (bool) $company['can_varejo'];
    $canLicitacao= (bool) $company['can_licitacao'];
    $hasBoth     = $canVarejo && $canLicitacao;
    $preferredCanal = strtolower((string) ($company['default_canal'] ?: $company['canal_padrao'] ?: ''));
    $defaultCanal = match (true) {
        $preferredCanal === 'varejo' && $canVarejo => 'varejo',
        $preferredCanal === 'licitacao' && $canLicitacao => 'licitacao',
        $canVarejo => 'varejo',
        default => 'licitacao',
    };
    // Com os dois canais e a flag ON, "Todas as operações" (mixed) é a seleção
    // INICIAL — o gestor vê varejo+licitação juntos por padrão. Sem a flag, a
    // opção nem existe e o padrão single-channel é preservado.
    $mixedOffered = $hasBoth && $seMixedEnabled;
    $initialScope = $mixedOffered ? 'mixed' : $defaultCanal;

    $logoUrl   = '';
    $initials  = strtoupper(substr($displayName, 0, 2));
    $seType    = '';
    $seCnpj    = '';
    $cardColor = '#2D4A7A';
    $cardDark  = '#1e3357';
    $manifestValid = false;

    try {
        $manifest  = CompanyPackageLoader::load($slug);
        $manifestValid = $manifest->matchesIdentity($slug, $cid);
        $logoPath  = $manifestValid ? $manifest->logoIconPath() : '';
        if ($logoPath !== '' && is_file(__DIR__ . '/' . ltrim($logoPath, '/'))) {
            $logoUrl = Auth::url(Asset::url($logoPath));
        }
        $initials  = $manifestValid ? $manifest->initials() : $initials;
        $seType    = $manifestValid ? $manifest->companyType() : '';
        $seCnpj    = $manifestValid ? $manifest->cnpj() : '';
        $c         = $manifestValid ? $manifest->brandColor() : '';
        $cd        = $manifestValid ? $manifest->brandColorDark() : '';
        if ($c !== '') { $cardColor = $c; }
        if ($cd !== '') { $cardDark = $cd; }
    } catch (\Throwable) {}

    if ($seCnpj === '') {
        $seCnpj = $seFormatCnpj((string) ($company['cnpj'] ?? ''));
    }

    $canAccess = $canVarejo || $canLicitacao;
    $integrationActive = (bool) ($company['integration_active'] ?? false);
    $integrationConfigured = (bool) ($company['integration_configured'] ?? false);

    // Status real da integração; não bloqueia leitura de dados já sincronizados.
    if ($integrationActive && $integrationConfigured && $manifestValid) {
        $statusClass = 'se-status-badge--ativa';
        $statusLabel = 'Ativa';
    } elseif ($canAccess) {
        $statusClass = 'se-status-badge--pendente';
        $statusLabel = $manifestValid ? 'Sync pendente' : 'Pacote inválido';
    } else {
        $statusClass = 'se-status-badge--inativa';
        $statusLabel = 'Inativa';
    }

    $cardClass = 'se-card' . ($canAccess ? '' : ' se-card--inactive');
?>
    <article class="<?= $cardClass ?>"
             data-cid="<?= $cid ?>"
             data-canal="<?= htmlspecialchars($initialScope, ENT_QUOTES, 'UTF-8') ?>"
             style="--card-color:<?= htmlspecialchars($cardColor, ENT_QUOTES, 'UTF-8') ?>; --card-color-dark:<?= htmlspecialchars($cardDark, ENT_QUOTES, 'UTF-8') ?>;">

      <div class="se-card-head">
        <span class="se-status-badge <?= $statusClass ?>"><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></span>
        <div class="se-logo-wrap"<?= $seCnpj !== '' ? ' title="CNPJ ' . htmlspecialchars($seCnpj, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
<?php if ($logoUrl !== ''): ?>
          <img src="<?= htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') ?>"
               alt="<?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?>"
               class="se-logo-img"
               loading="eager" />
<?php else: ?>
          <span class="se-initials"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></span>
<?php endif; ?>
        </div>
      </div>

      <div class="se-card-body">
        <h3 class="se-card-name">
          <?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?>
          <?php if ($seType !== ''): ?>
          <span class="se-type-badge se-type-badge--<?= htmlspecialchars($seType, ENT_QUOTES, 'UTF-8') ?>"><?= $seType === 'filial' ? 'Filial' : 'Matriz' ?></span>
          <?php endif; ?>
        </h3>
<?php if ($seCnpj !== ''): ?>
        <div class="se-card-cnpj">CNPJ <?= htmlspecialchars($seCnpj, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php if ($hasBoth): ?>
        <div class="se-canal-row">
<?php if ($mixedOffered): ?>
          <button class="se-canal-btn<?= $initialScope === 'mixed' ? ' active' : '' ?>" data-canal="mixed" type="button" aria-pressed="<?= $initialScope === 'mixed' ? 'true' : 'false' ?>">Todas</button>
<?php endif; ?>
          <button class="se-canal-btn<?= $initialScope === 'varejo' ? ' active' : '' ?>" data-canal="varejo" type="button" aria-pressed="<?= $initialScope === 'varejo' ? 'true' : 'false' ?>">Varejo</button>
          <button class="se-canal-btn<?= $initialScope === 'licitacao' ? ' active' : '' ?>" data-canal="licitacao" type="button" aria-pressed="<?= $initialScope === 'licitacao' ? 'true' : 'false' ?>">Licitação</button>
        </div>
<?php elseif ($canAccess): ?>
        <div class="se-canal-label"><?= $canLicitacao ? 'Licitação' : 'Varejo' ?></div>
<?php else: ?>
        <div class="se-canal-label">Sem permissão de acesso</div>
<?php endif; ?>
      </div>

      <div class="se-card-foot">
<?php if ($canAccess): ?>
        <button class="se-btn-enter" type="button" aria-label="Acessar <?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?>">
          <span class="se-spinner" aria-hidden="true"></span>
          <span class="se-arrow">Acessar
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
          </span>
        </button>
<?php else: ?>
        <button class="se-btn-enter se-btn-enter--disabled" type="button" disabled>
          Em configuração
        </button>
<?php endif; ?>
      </div>

    </article>
<?php endforeach; ?>
<?php if (empty($seCompanies)): ?>
    <div class="se-empty">
      <strong>Nenhuma empresa disponível</strong>
      <p>Seu usuário está ativo, mas ainda não possui vínculo com uma empresa. Solicite acesso ao administrador.</p>
    </div>
<?php endif; ?>
  </div>

  <div class="se-logout">
    <span class="se-session-note">Cada aba mantém seu próprio contexto de empresa, sem alterar as demais.</span>
    <a href="<?= Auth::url('logout.php') ?>">Sair da conta</a>
  </div>

</div>

<!-- Toast notification -->
<div class="se-toast" id="se-toast" role="alert" aria-live="polite"></div>
<div id="lw-credit" class="lw-credit" aria-label="Crédito da imagem"></div>

<!-- Runtime data (server-rendered, read by JS) -->
<script id="se-data" type="application/json">
<?= json_encode([
    'csrf'     => $seCsrf,
    'redirect' => $seRedirect,
    'base'     => Auth::basePath(),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
</script>

<script src="<?= Auth::url(Asset::url('assets/js/login-wallpaper.js')) ?>"></script>
<script src="<?= Auth::url(Asset::url('assets/js/select-empresa.js')) ?>"></script>
</body>
</html>

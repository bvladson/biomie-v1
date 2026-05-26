<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="color-scheme" content="light" />
  <meta name="theme-color" content="#f6f3f2" />
  <title>Administra&ccedil;&atilde;o | BIomie MAG</title>
  <link rel="icon" href="images/favicon.svg" type="image/svg+xml" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet" />
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet" />
  <link rel="stylesheet" href="assets/css/theme-tokens.css?v=20260425-ui1" />
  <link rel="stylesheet" href="assets/css/shell-index.css?v=20260415sk5" />
  <link rel="stylesheet" href="assets/css/module-pages.css?v=20260415sk1" />
  <style>
    .admin-wrap{padding:24px;max-width:1480px;margin:0 auto}
    .admin-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:18px 0 20px}
    .admin-tab{border:1px solid var(--mag-border,var(--border));background:var(--mag-surface,var(--bg-card));color:var(--mag-muted,var(--text-2));border-radius:12px;padding:10px 12px;font-weight:800;display:inline-flex;align-items:center;gap:8px}
    .admin-tab.active{background:var(--page-accent-soft);border-color:color-mix(in srgb,var(--page-accent) 28%,var(--mag-border));color:var(--page-accent)}
    .admin-section{display:none}.admin-section.active{display:block}
    .admin-panel{background:var(--mag-surface,var(--bg-card));border:1px solid var(--mag-border,var(--border));border-radius:16px;padding:18px;box-shadow:var(--mag-shadow-soft,var(--shadow-lg))}
    .admin-panel + .admin-panel{margin-top:16px}
    .admin-panel h2{margin:0 0 14px;font-size:1.08rem;color:var(--mag-text,var(--text-1))}
    .admin-grid{display:grid;grid-template-columns:360px 1fr;gap:18px}
    .admin-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
    .admin-field{display:grid;gap:7px;margin-bottom:12px}.admin-field label{font-size:.82rem;font-weight:800;color:var(--mag-muted,var(--text-2))}
    .admin-input,.admin-select{width:100%;border:1px solid var(--mag-border,var(--border));border-radius:12px;background:var(--mag-bg,var(--bg-surface));color:var(--mag-text,var(--text-1));padding:11px 12px;font:inherit}
    .admin-check{display:flex;align-items:center;gap:8px;color:var(--mag-text,var(--text-1));font-weight:700}.admin-check input{width:auto}
    .admin-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
    .admin-table-wrap{overflow-x:auto}.admin-table{width:100%;border-collapse:collapse}
    .admin-table th,.admin-table td{border-bottom:1px solid var(--mag-border,var(--border));padding:11px 10px;text-align:left;vertical-align:top;color:var(--mag-text,var(--text-1));font-size:.9rem}
    .admin-table th{background:var(--mag-table-head,#f7f7f8);color:var(--mag-muted,var(--text-2));font-size:.76rem;text-transform:uppercase;letter-spacing:.04em}
    .admin-tag{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:5px 9px;font-size:.76rem;font-weight:800;background:var(--page-accent-soft);color:var(--page-accent)}
    .admin-tag.neutral{background:var(--mag-bg);color:var(--mag-muted);border:1px solid var(--mag-border)}
    .admin-tag.ok{background:var(--success-soft,#e8f7ef);color:var(--success-strong,#147a42)}
    .admin-tag.warn{background:var(--warning-soft,#fff4de);color:var(--warning-strong,#925500)}
    .admin-tag.danger{background:var(--danger-soft,#fde8e6);color:var(--danger-strong,#96140a)}
    .admin-msg{margin:0 0 16px;border-radius:14px;padding:12px 14px;font-weight:800}
    .admin-msg.success{background:var(--success-soft,#e8f7ef);color:var(--success-strong,#147a42)}
    .admin-msg.error{background:var(--danger-soft,#fde8e6);color:var(--danger-strong,#96140a)}
    .admin-msg.warning{background:var(--warning-soft,#fff4de);color:var(--warning-strong,#925500)}
    .admin-msg.info{background:var(--page-accent-soft);color:var(--page-accent)}
    .admin-small{font-size:.82rem;color:var(--mag-muted,var(--text-2));line-height:1.45}
    .admin-roles-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
    .admin-permission-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
    .admin-permission-item{border:1px solid var(--mag-border);border-radius:12px;padding:10px;background:var(--mag-bg)}
    .admin-permission-item label{display:flex;align-items:flex-start;gap:8px;font-weight:800;color:var(--mag-text)}
    .admin-diagnostics-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
    .admin-diagnostic-card{border:1px solid var(--mag-border);border-radius:14px;padding:14px;background:var(--mag-bg)}
    .admin-diagnostic-card span{display:block;font-size:.76rem;font-weight:800;text-transform:uppercase;color:var(--mag-muted);letter-spacing:.04em}
    .admin-diagnostic-card strong{display:block;margin-top:6px;color:var(--mag-text);font-size:1.15rem}
    .admin-inline-form{display:grid;gap:8px;min-width:300px}
    .admin-user-actions{display:grid;gap:8px}
    .admin-table-compact td,.admin-table-compact th{padding:8px 10px;vertical-align:middle}
    .admin-ops-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:14px}
    .admin-ops-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:14px}
    .admin-ops-grid.two{grid-template-columns:1.35fr .95fr}
    .admin-ops-card{border:1px solid var(--mag-border,var(--border));border-radius:14px;background:var(--mag-bg,var(--bg-surface));padding:14px;min-height:96px}
    .admin-ops-card span{display:block;font-size:.74rem;text-transform:uppercase;font-weight:800;letter-spacing:.04em;color:var(--mag-muted,var(--text-2))}
    .admin-ops-card strong{display:block;margin-top:7px;color:var(--mag-text,var(--text-1));font-size:1.2rem;line-height:1.2}
    .admin-ops-card p{margin:6px 0 0}
    .admin-ops-card.ok{border-left:4px solid var(--success-strong,#147a42)}
    .admin-ops-card.warning,.admin-ops-card.warn{border-left:4px solid var(--warning-strong,#925500)}
    .admin-ops-card.critical,.admin-ops-card.danger,.admin-ops-card.error{border-left:4px solid var(--danger-strong,#96140a)}
    .admin-ops-toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px}
    .admin-ops-search{max-width:320px}
    .admin-ops-details{border:1px solid var(--mag-border,var(--border));border-radius:12px;background:var(--mag-bg,var(--bg-surface));padding:10px 12px;margin-top:8px}
    .admin-ops-details summary{cursor:pointer;font-weight:800;color:var(--mag-text,var(--text-1))}
    .admin-ops-pre{white-space:pre-wrap;word-break:break-word;font-size:.78rem;background:#0f172a;color:#e5e7eb;border-radius:12px;padding:12px;max-height:320px;overflow:auto}
    .admin-ops-link-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
    .admin-ops-link{display:flex;align-items:flex-start;gap:10px;text-decoration:none;color:inherit;border:1px solid var(--mag-border,var(--border));border-radius:14px;background:var(--mag-bg,var(--bg-surface));padding:14px}
    .admin-ops-link .material-symbols-rounded{color:var(--page-accent);font-size:22px}
    .admin-user-cell{display:flex;flex-direction:column;gap:2px}
    .admin-row-actions{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
    .admin-row-btn{padding:4px 8px;border-radius:8px;font-size:.78rem;font-weight:800;border:1px solid var(--mag-border,var(--border));background:var(--mag-bg,var(--bg-surface));color:var(--mag-text,var(--text-1));cursor:pointer;display:inline-flex;align-items:center;gap:4px;line-height:1.4;white-space:nowrap}
    .admin-row-btn:hover{background:var(--page-accent-soft);color:var(--page-accent);border-color:color-mix(in srgb,var(--page-accent) 28%,var(--mag-border))}
    .admin-row-btn.danger{color:var(--danger-strong,#96140a)}
    .admin-row-btn.danger:hover{background:var(--danger-soft,#fde8e6);border-color:var(--danger-soft,#fde8e6)}
    .admin-modal{position:fixed;inset:0;background:rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center;z-index:999;padding:16px}
    .admin-modal[hidden]{display:none}
    .admin-modal-box{background:var(--mag-surface,var(--bg-card));border:1px solid var(--mag-border,var(--border));border-radius:20px;padding:24px;width:100%;max-width:440px;box-shadow:0 8px 32px rgba(0,0,0,.18);position:relative;max-height:90vh;overflow-y:auto}
    .admin-modal-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px}
    .admin-modal-head h3{margin:0;font-size:1rem;color:var(--mag-text,var(--text-1));display:flex;align-items:center;gap:6px}
    .admin-modal-close{background:none;border:none;cursor:pointer;color:var(--mag-muted,var(--text-2));padding:4px;border-radius:8px;line-height:1;display:flex;align-items:center}
    .admin-modal-close:hover{background:var(--mag-bg,var(--bg-surface));color:var(--mag-text,var(--text-1))}
    @media(max-width:1180px){.admin-grid{grid-template-columns:1fr}.admin-roles-grid,.admin-diagnostics-grid,.admin-ops-grid,.admin-ops-link-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.admin-ops-grid.two{grid-template-columns:1fr}.admin-permission-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media(max-width:720px){.admin-wrap{padding:16px}.admin-form-grid,.admin-roles-grid,.admin-diagnostics-grid,.admin-ops-grid,.admin-ops-link-grid,.admin-permission-grid{grid-template-columns:1fr}.admin-tab{flex:1 1 auto;justify-content:center}.admin-inline-form{min-width:0}.admin-modal-box{padding:18px;border-radius:14px}.admin-ops-head{display:block}.admin-ops-head .admin-actions{margin-top:10px}}
  </style>
  <script src="assets/js/pwa.js?v=20260415sk1" defer></script>
</head>
<?php
$h = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$roles            = is_array($roles            ?? null) ? $roles            : [];
$permissions      = is_array($permissions      ?? null) ? $permissions      : [];
$permissionMatrix = is_array($permissionMatrix ?? null) ? $permissionMatrix : [];
$can              = is_array($can              ?? null) ? $can              : ['users' => false, 'logs' => false, 'migrations' => false, 'console' => false, 'operations' => false];
$tabAccess = [
    'users'       => (bool) ($can['users']      ?? false),
    'profiles'    => (bool) ($can['users']      ?? false),
    'permissions' => (bool) ($can['users']      ?? false),
    'logs'        => (bool) ($can['logs']       ?? false),
    'diagnostics' => (bool) ($can['logs']       ?? false),
    'migrations'  => (bool) ($can['migrations'] ?? false),
    'console'     => (bool) ($can['console']    ?? false),
    'system-health' => (bool) ($can['operations'] ?? false),
    'integrations'  => (bool) ($can['operations'] ?? false),
    'metrics'       => (bool) ($can['operations'] ?? false),
    'cockpit'       => (bool) ($can['operations'] ?? false),
];
$tabs = [
    ['key' => 'users',       'label' => 'Usu&aacute;rios',          'icon' => 'group'],
    ['key' => 'profiles',    'label' => 'Perfis',                   'icon' => 'badge'],
    ['key' => 'permissions', 'label' => 'Permiss&otilde;es',        'icon' => 'key'],
    ['key' => 'logs',        'label' => 'Logs',                     'icon' => 'receipt_long'],
    ['key' => 'diagnostics', 'label' => 'Diagn&oacute;stico',       'icon' => 'monitor_heart'],
    ['key' => 'migrations',  'label' => 'Migrations',               'icon' => 'database'],
    ['key' => 'console',     'label' => 'Console',                  'icon' => 'terminal'],
    ['key' => 'system-health', 'label' => 'Sa&uacute;de',            'icon' => 'monitor_heart'],
    ['key' => 'integrations',  'label' => 'Integra&ccedil;&otilde;es', 'icon' => 'hub'],
    ['key' => 'metrics',       'label' => 'KPIs',                   'icon' => 'query_stats'],
    ['key' => 'cockpit',       'label' => 'Cockpit',                'icon' => 'dashboard_customize'],
];
$consoleCommandList = is_array($consoleCommandList ?? null) ? $consoleCommandList : [];
$activeTab          = (string) ($activeTab ?? 'users');
if (!isset($tabAccess[$activeTab]) || !$tabAccess[$activeTab]) {
    $activeTab = array_key_first(array_filter($tabAccess)) ?: 'users';
}
$roleByKey = [];
foreach ($roles as $roleRow) {
    $roleByKey[(string) ($roleRow['role_key'] ?? '')] = $roleRow;
}
$permissionByKey = [];
foreach ($permissions as $permissionRow) {
    $permissionByKey[(string) ($permissionRow['permission_key'] ?? '')] = $permissionRow;
}
$roleLabel = static function (string $roleKey) use ($roleByKey): string {
    return (string) ($roleByKey[$roleKey]['name'] ?? \BIomie\Acl\Roles::label($roleKey));
};
$statusTag   = static fn (bool $active): string => $active ? '<span class="admin-tag ok">ativo</span>' : '<span class="admin-tag danger">inativo</span>';
$statusLabel = static fn (string $status): string => match ($status) {
    'applied', 'ok'                                                          => 'ok',
    'pending', 'warning'                                                     => 'warn',
    'rolled_back', 'missing', 'connection_error', 'checksum_mismatch'       => 'danger',
    default                                                                  => 'neutral',
};
$csrf            = (string) ($csrf            ?? '');
$flash           = is_array($flash           ?? null) ? $flash           : null;
$users           = is_array($users           ?? null) ? $users           : [];
$adminLogs       = is_array($adminLogs       ?? null) ? $adminLogs       : [];
$rbacDiagnostics = is_array($rbacDiagnostics ?? null) ? $rbacDiagnostics : [];
$systemHealth    = is_array($systemHealth    ?? null) ? $systemHealth    : [];
$migrationStatus = is_array($migrationStatus ?? null) ? $migrationStatus : [];
?>
<body class="nav-md mag-page" data-page="admin">
<div class="overlay" id="overlay"></div>
<div class="shell">
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-logo">
      <img src="images/logo-mag-wide.png" alt="MAG Moveis Corporativos" onerror="this.style.display='none'" />
      <span class="logo-pill">BIomie</span>
    </div>
  </aside>

  <div class="main">
    <header class="topbar">
      <div class="topbar-left">
        <button class="menu-btn btn-icon" id="menu_toggle" aria-label="Menu"><span class="material-symbols-rounded">menu</span></button>
        <div class="topbar-badge"><span class="material-symbols-rounded">admin_panel_settings</span>Administra&ccedil;&atilde;o</div>
      </div>
      <div class="topbar-actions">
        <a class="btn-secondary" href="logout.php"><span class="material-symbols-rounded">logout</span>Sair</a>
      </div>
    </header>

    <main class="page">
      <div class="admin-wrap">
        <section class="mag-page-header mag-page-header-shell mag-page-title anim">
          <div class="mag-page-title__copy">
            <div class="mag-kicker"><span class="material-symbols-rounded">admin_panel_settings</span>Admin</div>
            <h1>Administra&ccedil;&atilde;o</h1>
            <p>Gerencie usu&aacute;rios, permiss&otilde;es, logs e opera&ccedil;&atilde;o t&eacute;cnica em uma &aacute;rea administrativa &uacute;nica.</p>
          </div>
          <div class="mag-page-title__actions">
            <a class="btn-secondary" href="admin.php?tab=system-health"><span class="material-symbols-rounded">monitoring</span>Sa&uacute;de</a>
            <a class="btn-secondary" href="index.php"><span class="material-symbols-rounded">space_dashboard</span>Painel</a>
          </div>
        </section>

        <?php if ($flash !== null): ?>
          <div class="admin-msg <?php echo $h($flash['type'] ?? 'info'); ?>"><?php echo $h($flash['message'] ?? ''); ?></div>
        <?php endif; ?>

        <nav class="admin-tabs" aria-label="Abas administrativas">
          <?php foreach ($tabs as $tab): ?>
            <?php if (!($tabAccess[$tab['key']] ?? false)) { continue; } ?>
            <button class="admin-tab <?php echo $activeTab === $tab['key'] ? 'active' : ''; ?>" type="button" data-admin-tab-trigger="<?php echo $h($tab['key']); ?>">
              <span class="material-symbols-rounded"><?php echo $h($tab['icon']); ?></span><?php echo $tab['label']; ?>
            </button>
          <?php endforeach; ?>
        </nav>

        <?php include __DIR__ . '/admin/tabs/users.php'; ?>
        <?php include __DIR__ . '/admin/tabs/profiles.php'; ?>
        <?php include __DIR__ . '/admin/tabs/permissions.php'; ?>
        <?php include __DIR__ . '/admin/tabs/logs.php'; ?>
        <?php include __DIR__ . '/admin/tabs/diagnostics.php'; ?>
        <?php include __DIR__ . '/admin/tabs/migrations.php'; ?>
        <?php include __DIR__ . '/admin/tabs/console.php'; ?>
        <?php include __DIR__ . '/admin/tabs/system-health.php'; ?>
        <?php include __DIR__ . '/admin/tabs/integrations.php'; ?>
        <?php include __DIR__ . '/admin/tabs/metric-catalog.php'; ?>
        <?php include __DIR__ . '/admin/tabs/cockpit.php'; ?>

      </div>
    </main>
  </div>
</div>
<script src="assets/js/shell.js?v=20260425-ui1"></script>
<?php include __DIR__ . '/admin/partials/admin-js.php'; ?>
<?php include __DIR__ . '/admin/partials/console-js.php'; ?>
</body>

<?php

declare(strict_types=1);

$magTopbarIcon = (string) ($pageIcon ?? 'space_dashboard');
$magTopbarLabel = (string) ($pageBadge ?? $pageTitle ?? 'BIomie');
$magTopbarMeta = is_array($topbarMeta ?? null) ? $topbarMeta : [];
$magTopbarActions = is_array($topbarActions ?? null) ? $topbarActions : [];
$magUser = is_array($user ?? null) ? $user : [];
$magUserName = trim((string) ($magUser['full_name'] ?? $magUser['username'] ?? 'Usuario')) ?: 'Usuario';
$magEnvironment = trim((string) ($environment ?? getenv('APP_ENV') ?: 'production'));
?>
<header class="topbar mag-topbar">
  <div class="topbar-left">
    <button
      class="menu-btn btn-icon"
      id="menu_toggle"
      type="button"
      aria-label="Abrir ou recolher menu"
      aria-controls="sidebar"
      aria-expanded="true"
    >
      <span class="material-symbols-rounded" aria-hidden="true">menu</span>
    </button>

    <div class="topbar-badge">
      <span class="material-symbols-rounded" aria-hidden="true"><?php echo htmlspecialchars($magTopbarIcon, ENT_QUOTES, 'UTF-8'); ?></span>
      <?php echo htmlspecialchars($magTopbarLabel, ENT_QUOTES, 'UTF-8'); ?>
    </div>

    <?php if ($magTopbarMeta !== []): ?>
      <div class="topbar-meta mag-data-diagnostics mag-diagnostics-collapsed">
        <?php foreach ($magTopbarMeta as $magMetaLabel => $magMetaValue): ?>
          <span>
            <?php echo htmlspecialchars((string) $magMetaLabel, ENT_QUOTES, 'UTF-8'); ?>:
            <strong><?php echo htmlspecialchars((string) $magMetaValue, ENT_QUOTES, 'UTF-8'); ?></strong>
          </span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="topbar-actions">
    <?php if ($magEnvironment !== '' && strtolower($magEnvironment) !== 'production'): ?>
      <span class="mag-env-badge"><?php echo htmlspecialchars($magEnvironment, ENT_QUOTES, 'UTF-8'); ?></span>
    <?php endif; ?>

    <?php foreach ($magTopbarActions as $magAction): ?>
      <a
        class="<?php echo htmlspecialchars((string) ($magAction['class'] ?? 'btn-secondary'), ENT_QUOTES, 'UTF-8'); ?>"
        href="<?php echo htmlspecialchars((string) ($magAction['href'] ?? '#'), ENT_QUOTES, 'UTF-8'); ?>"
      >
        <?php if (!empty($magAction['icon'])): ?>
          <span class="material-symbols-rounded" aria-hidden="true"><?php echo htmlspecialchars((string) $magAction['icon'], ENT_QUOTES, 'UTF-8'); ?></span>
        <?php endif; ?>
        <?php echo htmlspecialchars((string) ($magAction['label'] ?? 'Acao'), ENT_QUOTES, 'UTF-8'); ?>
      </a>
    <?php endforeach; ?>

    <span class="mag-topbar-user" title="<?php echo htmlspecialchars($magUserName, ENT_QUOTES, 'UTF-8'); ?>">
      <span class="material-symbols-rounded" aria-hidden="true">account_circle</span>
      <span><?php echo htmlspecialchars($magUserName, ENT_QUOTES, 'UTF-8'); ?></span>
    </span>

    <a class="btn-secondary mag-logout-link" href="logout.php">
      <span class="material-symbols-rounded" aria-hidden="true">logout</span>
      Sair
    </a>
  </div>
</header>

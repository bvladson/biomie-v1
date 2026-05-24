<?php

declare(strict_types=1);

$mobileUser    = is_array($user ?? null) ? $user : [];
$mobileName    = trim((string) ($mobileUser['full_name'] ?? $mobileUser['username'] ?? 'U')) ?: 'U';
$mobileInitial = strtoupper(mb_substr($mobileName, 0, 1, 'UTF-8'));
?>
<div
  class="mag-mobile-topbar"
  role="banner"
  aria-label="Barra de navegação mobile"
>
  <button
    type="button"
    class="mag-mobile-topbar__hamburger"
    data-mobile-sidebar-toggle
    aria-label="Abrir menu de navegação"
    aria-controls="sidebar"
    aria-expanded="false"
  >
    <span class="material-symbols-rounded" aria-hidden="true">menu</span>
  </button>

  <div class="mag-mobile-topbar__title" aria-live="polite">BIomie</div>

  <button
    type="button"
    class="mag-mobile-topbar__install-btn"
    id="mobileInstallButton"
    hidden
    aria-label="Instalar BIomie no dispositivo"
    title="Instalar app BIomie"
  >
    <span class="material-symbols-rounded" aria-hidden="true">download</span>
  </button>

  <div
    class="mag-mobile-topbar__user"
    aria-hidden="true"
    title="<?php echo htmlspecialchars($mobileName, ENT_QUOTES, 'UTF-8'); ?>"
  ><?php echo htmlspecialchars($mobileInitial, ENT_QUOTES, 'UTF-8'); ?></div>
</div>

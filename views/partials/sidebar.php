<?php

declare(strict_types=1);

$magActivePage = (string) ($currentPageKey ?? $activePage ?? $pageKey ?? '');
$magIsAdmin = (bool) ($isAdmin ?? $is_admin ?? false);
$magUser = is_array($user ?? null) ? $user : [];
$magName = trim((string) ($magUser['full_name'] ?? 'Comercial MAG')) ?: 'Comercial MAG';
$magRole = $magIsAdmin ? 'Administrador' : 'Usuario';
$magInitial = strtoupper(mb_substr($magName, 0, 1, 'UTF-8'));
$magAppName = trim((string) ($appName ?? 'BIomie')) ?: 'BIomie';
$magAppVersion = trim((string) ($appVersion ?? ''));
$magNavItems = is_array($menuItems ?? null) ? $menuItems : [];

if ($magNavItems === []) {
    $menuFile = dirname(__DIR__) . '/config/menu.php';
    $loaded = is_file($menuFile) ? require $menuFile : [];
    $magNavItems = is_array($loaded) ? $loaded : [];
}

$magGroups = ['Comercial', 'Operacao', 'Inteligencia', 'Sistema'];
$magGroupLabels = [
    'Comercial' => 'Comercial',
    'Operacao' => 'Operação',
    'Inteligencia' => 'Inteligência',
    'Sistema' => 'Sistema',
];

$magNormalize = static fn (string $value): string => strtolower(trim($value));
$magMatchesItem = static function (array $item, string $activePage) use ($magNormalize): bool {
    $matches = [(string) ($item['page_key'] ?? '')];
    foreach ((array) ($item['aliases'] ?? []) as $alias) {
        $matches[] = (string) $alias;
    }

    $normalizedActive = $magNormalize($activePage);
    foreach ($matches as $match) {
        if ($magNormalize((string) $match) === $normalizedActive) {
            return true;
        }
    }

    return false;
};

$magVisibleItems = array_values(array_filter($magNavItems, static function (array $item) use ($magIsAdmin): bool {
    if (($item['enabled'] ?? true) === false) {
        return false;
    }

    if (!empty($item['requires_admin']) && !$magIsAdmin) {
        return false;
    }

    // permission_any: item visible only when user holds at least one listed capability
    // permission_any: item visible only when user holds at least one listed capability
    $permAny = $item['permission_any'] ?? null;
    if (is_array($permAny) && $permAny !== []) {
        $hasAny = false;
        foreach ($permAny as $cap) {
            $cap = trim((string) $cap);
            if ($cap === '') {
                continue;
            }
            try {
                if (\BIomie\Auth::hasCapability($cap)) {
                    $hasAny = true;
                    break;
                }
            } catch (\Throwable) {
                // Auth not booted in this context — skip check
            }
        }
        if (!$hasAny) {
            return false;
        }
    }

    return true;
}));

$magAccentSoft = static function (string $hex): string {
    $clean = ltrim($hex, '#');
    if (strlen($clean) !== 6) {
        return 'rgba(37, 99, 235, 0.12)';
    }

    $red = hexdec(substr($clean, 0, 2));
    $green = hexdec(substr($clean, 2, 2));
    $blue = hexdec(substr($clean, 4, 2));

    return sprintf('rgba(%d, %d, %d, 0.12)', $red, $green, $blue);
};
?>
<aside class="sidebar mag-sidebar" id="sidebar" aria-label="Navegação principal">
  <div class="sidebar-logo">
    <img src="images/logo-mag-wide.png" alt="MAG Moveis Corporativos" onerror="this.style.display='none'" />
    <span class="logo-pill"><?php echo htmlspecialchars($magAppName, ENT_QUOTES, 'UTF-8'); ?></span>
  </div>

  <div class="sidebar-profile">
    <div class="profile-avatar"><?php echo htmlspecialchars($magInitial, ENT_QUOTES, 'UTF-8'); ?></div>
    <div class="profile-info">
      <span><?php echo htmlspecialchars($magRole, ENT_QUOTES, 'UTF-8'); ?></span>
      <strong><?php echo htmlspecialchars($magName, ENT_QUOTES, 'UTF-8'); ?></strong>
    </div>
  </div>

  <nav class="sidebar-nav mag-sidebar-nav" id="sidebar-menu">
    <?php foreach ($magGroups as $magGroup): ?>
      <?php
        $magGroupItems = array_values(array_filter(
            $magVisibleItems,
            static fn (array $item): bool => (string) ($item['group'] ?? '') === $magGroup
        ));
        if ($magGroupItems === []) {
            continue;
        }
        $magGroupId = 'mag-sidebar-group-' . preg_replace('/[^a-z0-9-]+/', '-', strtolower($magGroup));
        $magGroupHasActive = false;
        foreach ($magGroupItems as $magGroupItem) {
            if ($magMatchesItem($magGroupItem, $magActivePage)) {
                $magGroupHasActive = true;
                break;
            }
        }
      ?>
      <section class="mag-nav-group<?php echo $magGroup === 'Sistema' ? ' mag-nav-group--system' : ''; ?>" data-sidebar-group="<?php echo htmlspecialchars($magGroup, ENT_QUOTES, 'UTF-8'); ?>">
        <button
          class="nav-section-label mag-nav-group__button"
          type="button"
          aria-expanded="true"
          aria-controls="<?php echo htmlspecialchars($magGroupId, ENT_QUOTES, 'UTF-8'); ?>"
        >
          <span><?php echo htmlspecialchars($magGroupLabels[$magGroup] ?? $magGroup, ENT_QUOTES, 'UTF-8'); ?></span>
          <span class="material-symbols-rounded mag-nav-group__chevron" aria-hidden="true">expand_more</span>
        </button>
        <div class="mag-nav-group__items" id="<?php echo htmlspecialchars($magGroupId, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $magGroupHasActive ? 'data-has-active="1"' : ''; ?>>
          <?php foreach ($magGroupItems as $magItem): ?>
            <?php
              $magPageKey = (string) ($magItem['page_key'] ?? '');
              $magAliases = array_values(array_map('strval', (array) ($magItem['aliases'] ?? [])));
              $magMatches = array_values(array_filter(array_merge([$magPageKey], $magAliases)));
              $magIsActive = $magMatchesItem($magItem, $magActivePage);
              $magAccent = (string) ($magItem['accent'] ?? '#2563eb');
              $magMatchAttr = implode(' ', $magMatches);
            ?>
            <a
              href="<?php echo htmlspecialchars((string) ($magItem['href'] ?? '#'), ENT_QUOTES, 'UTF-8'); ?>"
              class="nav-item<?php echo $magIsActive ? ' active current-page' : ''; ?>"
              data-page-key="<?php echo htmlspecialchars($magPageKey, ENT_QUOTES, 'UTF-8'); ?>"
              data-page-match="<?php echo htmlspecialchars($magMatchAttr, ENT_QUOTES, 'UTF-8'); ?>"
              data-accent="<?php echo htmlspecialchars($magAccent, ENT_QUOTES, 'UTF-8'); ?>"
              title="<?php echo htmlspecialchars((string) ($magItem['label'] ?? 'Menu'), ENT_QUOTES, 'UTF-8'); ?>"
              style="--item-accent: <?php echo htmlspecialchars($magAccent, ENT_QUOTES, 'UTF-8'); ?>; --item-accent-soft: <?php echo htmlspecialchars($magAccentSoft($magAccent), ENT_QUOTES, 'UTF-8'); ?>;"
              <?php echo $magIsActive ? 'aria-current="page"' : ''; ?>
            >
              <span class="material-symbols-rounded menu-icon" aria-hidden="true"><?php echo htmlspecialchars((string) ($magItem['icon'] ?? 'circle'), ENT_QUOTES, 'UTF-8'); ?></span>
              <span class="nav-label"><?php echo htmlspecialchars((string) ($magItem['label'] ?? 'Menu'), ENT_QUOTES, 'UTF-8'); ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endforeach; ?>
  </nav>

  <?php if ($magAppVersion !== ''): ?>
    <div class="mag-sidebar-version"><?php echo htmlspecialchars($magAppVersion, ENT_QUOTES, 'UTF-8'); ?></div>
  <?php endif; ?>
</aside>

<?php

declare(strict_types=1);

$magActivePage = (string) ($currentPageKey ?? $activePage ?? $pageKey ?? '');
$magIsAdmin = (bool) ($isAdmin ?? $is_admin ?? false);
$magUser = is_array($user ?? null) ? $user : [];
$magName = trim((string) ($magUser['full_name'] ?? 'Comercial MAG')) ?: 'Comercial MAG';
$magRole = $magIsAdmin ? 'Administrador' : 'Usuário';
$magInitial    = strtoupper(mb_substr($magName, 0, 1, 'UTF-8'));
$magUserId     = (int) ($magUser['id'] ?? 0);
$magAvatarPath = $magUserId > 0 ? \BIomie\Bootstrap::rootPath() . '/storage/users/avatars/' . $magUserId . '.jpg' : '';
$magHasAvatar  = $magAvatarPath !== '' && is_file($magAvatarPath);
$magAvatarUrl  = $magHasAvatar
    ? \BIomie\Auth::url(
        'api/avatar.php?uid=' . $magUserId
        . '&v=' . (string) filemtime($magAvatarPath)
        . '&ctx=' . rawurlencode(\BIomie\Support\TenantContext::contextId())
    )
    : '';
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
    'Comercial'    => 'Comercial',
    'Operacao'     => 'Operação',
    'Inteligencia' => 'Inteligência',
    'Sistema'      => 'Sistema',
];

// Load company manifest early — needed by the feature-flag filter below
$sidebarManifest = \BIomie\Company\CompanyPackageLoader::forTenant();

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

$magVisibleItems = array_values(array_filter($magNavItems, static function (array $item) use ($magIsAdmin, $sidebarManifest): bool {
    if (($item['enabled'] ?? true) === false) {
        return false;
    }

    if (!empty($item['requires_admin']) && !$magIsAdmin) {
        return false;
    }

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

    // Feature-flag: hide nav items the company manifest disables (safe default = show all)
    $feature = trim((string) ($item['feature'] ?? ''));
    if ($feature !== '' && !$sidebarManifest->isFeatureEnabled($feature)) {
        return false;
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

// Check if any badge-key items exist (only load API if admin)
$magHasBadges = $magIsAdmin && (bool) count(array_filter($magVisibleItems, static fn ($i) => !empty($i['badge_key'])));

// Tenant context for company selector inside user menu
$sidebarTenantBooted    = \BIomie\Support\TenantContext::isBooted();
$sidebarTenantCompanies = $sidebarTenantBooted
    ? \BIomie\Support\TenantContext::cachedAccessible()
    : [];
$sidebarTenantCompanyId = $sidebarTenantBooted
    ? \BIomie\Support\TenantContext::companyId()
    : 0;
$sidebarTenantCanal     = $sidebarTenantBooted
    ? \BIomie\Support\TenantContext::canal()
    : 'varejo';
$sidebarIsConsolidado   = $sidebarTenantBooted
    && \BIomie\Support\TenantContext::isConsolidado();
// R1: não oferecemos contexto de grupo até que cada leitor consolidado esteja
// explicitamente allowlisted e aplique empresa + canal. A troca entre empresas
// individuais e entre canais autorizados continua disponível.
$sidebarHasConsolidado = false;
$sidebarHasTenantChoice = count($sidebarTenantCompanies) > 1
                       || (bool) count(array_filter(
                           $sidebarTenantCompanies,
                           static fn (array $company): bool =>
                               (bool) ($company['can_licitacao'] ?? false)
                               && (bool) ($company['can_varejo'] ?? false),
                       ));

// Company identity strings
$sidebarCompanyName     = $sidebarIsConsolidado ? 'Consolidado' : ($sidebarManifest->name() ?: $magAppName);
$sidebarCompanyInitials = $sidebarIsConsolidado ? 'GR' : $sidebarManifest->initials();
$sidebarLogoIconPath    = $sidebarIsConsolidado ? '' : $sidebarManifest->logoIconPath();
$sidebarLogoIcon        = $sidebarLogoIconPath !== '' ? \BIomie\Auth::url($sidebarLogoIconPath) : '';
$sidebarActiveCanalLabel = match (mb_strtolower($sidebarTenantCanal)) {
    'licitacao'   => 'Licitação',
    'consolidado' => 'Grupo',
    default       => 'Varejo',
};
?>
<aside class="sidebar mag-sidebar" id="sidebar" aria-label="Navegação principal"<?php echo $magHasBadges ? ' data-sidebar-badges="1"' : ''; ?>>
  <div class="sidebar-logo">
    <div class="sidebar-company-mark<?php echo $sidebarLogoIcon !== '' ? ' sidebar-company-mark--has-logo' : ''; ?>">
      <?php if ($sidebarLogoIcon !== ''): ?>
      <div class="sidebar-company-initials sidebar-company-logo-wrap" aria-hidden="true">
        <img src="<?php echo htmlspecialchars($sidebarLogoIcon, ENT_QUOTES, 'UTF-8'); ?>"
             alt="<?php echo htmlspecialchars($sidebarCompanyName, ENT_QUOTES, 'UTF-8'); ?>"
             class="sidebar-company-logo-img">
      </div>
      <?php endif; ?>
      <!-- Initials badge: always rendered; hidden in expanded+logo, shown in collapsed -->
      <div class="sidebar-company-initials sidebar-company-initials-text<?php echo $sidebarLogoIcon !== '' ? ' sidebar-company-initials-text--hidden' : ''; ?>" aria-hidden="true"><?php echo htmlspecialchars($sidebarCompanyInitials, ENT_QUOTES, 'UTF-8'); ?></div>
      <div class="sidebar-company-wordmark">
        <span class="sidebar-company-name"><?php echo htmlspecialchars($sidebarCompanyName, ENT_QUOTES, 'UTF-8'); ?></span>
        <span class="logo-pill"><?php echo htmlspecialchars($magAppName, ENT_QUOTES, 'UTF-8'); ?></span>
      </div>
    </div>
  </div>

  <!-- Profile dropdown — user info + company context picker -->
  <div class="sidebar-profile" id="sidebarProfileWrap">
    <button
      class="sidebar-profile-btn"
      id="sidebarProfileBtn"
      type="button"
      aria-haspopup="menu"
      aria-expanded="false"
      aria-controls="sidebarProfileDropdown"
    >
      <div class="profile-avatar-wrap" aria-hidden="true">
        <?php if ($magHasAvatar): ?>
        <img src="<?php echo htmlspecialchars($magAvatarUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="Foto de perfil" class="profile-avatar profile-avatar-img">
        <?php else: ?>
        <div class="profile-avatar"><?php echo htmlspecialchars($magInitial, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
      </div>
      <div class="profile-info">
        <strong><?php echo htmlspecialchars($magName, ENT_QUOTES, 'UTF-8'); ?></strong>
        <span><?php echo htmlspecialchars($sidebarCompanyName, ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars($sidebarActiveCanalLabel, ENT_QUOTES, 'UTF-8'); ?></span>
      </div>
      <span class="material-symbols-rounded profile-chevron" aria-hidden="true">unfold_more</span>
    </button>
    <div
      class="sidebar-profile-dropdown sdp-dropdown"
      id="sidebarProfileDropdown"
      role="menu"
      aria-label="Menu do usuário"
      hidden
    >
      <!-- ── 1. User identity ── -->
      <div class="sdp-user-row">
        <div class="profile-avatar-wrap sdp-avatar-wrap">
          <?php if ($magHasAvatar): ?>
          <img src="<?php echo htmlspecialchars($magAvatarUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="Foto de perfil" class="profile-avatar sdp-avatar profile-avatar-img">
          <?php else: ?>
          <div class="profile-avatar sdp-avatar"><?php echo htmlspecialchars($magInitial, ENT_QUOTES, 'UTF-8'); ?></div>
          <?php endif; ?>
          <button class="profile-avatar-camera" type="button" id="sidebarAvatarCamBtn" title="Alterar foto de perfil" aria-label="Alterar foto de perfil">
            <span class="material-symbols-rounded" aria-hidden="true">photo_camera</span>
          </button>
        </div>
        <div class="sdp-user-info">
          <div class="sdp-user-name">
            <?php echo htmlspecialchars($magName, ENT_QUOTES, 'UTF-8'); ?>
            <?php if ($magIsAdmin): ?><span class="sdp-admin-badge">Admin</span><?php endif; ?>
          </div>
          <div class="sdp-user-role"><?php echo htmlspecialchars($magRole, ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
        <a href="logout.php" class="sdp-logout-btn" role="menuitem" title="Sair da conta">
          <span class="material-symbols-rounded" aria-hidden="true">logout</span>
        </a>
      </div>

      <?php if ($sidebarHasTenantChoice): ?>
      <?php
      // Compute context card values
      if ($sidebarIsConsolidado) {
          $sdpCtxName   = 'Consolidado Grupo';
          $sdpCtxMeta   = 'Todas as empresas · ' . count($sidebarTenantCompanies) . ' disponíveis';
          $sdpCtxGroup  = true;
      } else {
          $sdpCtxName  = $sidebarCompanyName;
          $sdpCtxMeta  = $sidebarActiveCanalLabel;
          $sdpCtxGroup = false;
      }
      ?>

      <!-- ── 2. Current context card ── -->
      <div class="sdp-divider"></div>
      <div class="sdp-section-label">Contexto atual</div>
      <div class="sdp-ctx-card">
        <div class="sdp-logo-wrap<?php echo $sdpCtxGroup ? ' sdp-logo-wrap--group' : ''; ?>">
          <?php if ($sdpCtxGroup): ?>
          <span class="material-symbols-rounded" aria-hidden="true">hub</span>
          <?php elseif ($sidebarLogoIcon !== ''): ?>
          <img src="<?php echo htmlspecialchars($sidebarLogoIcon, ENT_QUOTES, 'UTF-8'); ?>"
               alt="<?php echo htmlspecialchars($sdpCtxName, ENT_QUOTES, 'UTF-8'); ?>"
               class="sdp-logo-img">
          <?php else: ?>
          <div class="sdp-logo-initials"><?php echo htmlspecialchars($sidebarCompanyInitials, ENT_QUOTES, 'UTF-8'); ?></div>
          <?php endif; ?>
        </div>
        <div class="sdp-ctx-info">
          <div class="sdp-ctx-name"><?php echo htmlspecialchars($sdpCtxName, ENT_QUOTES, 'UTF-8'); ?></div>
          <div class="sdp-ctx-meta"><?php echo htmlspecialchars($sdpCtxMeta, ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
        <span class="material-symbols-rounded sdp-active-check" aria-hidden="true">check_circle</span>
      </div>

      <?php if (\BIomie\Auth::hasCapability('sales.view')): ?>
      <!-- ── Painel da Diretoria (modo TV) ── -->
      <div class="sdp-divider"></div>
      <a href="<?php echo htmlspecialchars(\BIomie\Auth::url('tv.php'), ENT_QUOTES, 'UTF-8'); ?>"
         target="_blank" rel="noopener"
         class="sdp-tv-link" role="menuitem"
         title="Abrir o painel consolidado da diretoria em tela cheia">
        <span class="sdp-tv-link__icon material-symbols-rounded" aria-hidden="true">live_tv</span>
        <span class="sdp-tv-link__text">
          <span class="sdp-tv-link__title">Painel da Diretoria</span>
          <span class="sdp-tv-link__sub">Resumo do grupo em tela cheia (TV)</span>
        </span>
        <span class="sdp-tv-link__go material-symbols-rounded" aria-hidden="true">open_in_new</span>
      </a>
      <?php endif; ?>

      <!-- ── 3. Context switcher ── -->
      <div class="sdp-divider"></div>
      <div class="sdp-section-label">Trocar contexto</div>

      <?php if ($sidebarHasConsolidado): ?>
      <button
        class="sdp-company-row<?php echo $sidebarIsConsolidado ? ' sdp-company-row--active' : ''; ?>"
        type="button"
        role="menuitem"
        data-sidebar-tenant="1"
        data-context-switch="1"
        data-company-id="0"
        data-canal="consolidado"
      >
        <div class="sdp-logo-wrap sdp-logo-wrap--group">
          <span class="material-symbols-rounded" aria-hidden="true">hub</span>
        </div>
        <div class="sdp-company-info">
          <div class="sdp-company-name">
            <span class="sdp-company-name-text">Consolidado Grupo</span>
          </div>
          <div class="sdp-company-channels">Todas as empresas</div>
        </div>
        <?php if ($sidebarIsConsolidado): ?><span class="material-symbols-rounded sdp-active-check" aria-hidden="true">check_circle</span><?php endif; ?>
      </button>
      <?php endif; ?>

      <?php foreach ($sidebarTenantCompanies as $stc): ?>
        <?php
        $stcId         = (int) $stc['id'];
        $stcSlug       = (string) ($stc['slug'] ?? '');
        $stcMfst       = \BIomie\Company\CompanyPackageLoader::load($stcSlug);
        $stcName       = $stcMfst->name() ?: (string) ($stc['nome_fantasia'] ?: $stc['slug']);
        $stcLogoPath   = $stcMfst->logoIconPath();
        $stcLogo       = $stcLogoPath !== '' ? \BIomie\Auth::url($stcLogoPath) : '';
        $stcInit       = $stcMfst->initials();
        $stcCnpj       = $stcMfst->cnpjLabel();
        $stcVar        = (bool) ($stc['can_varejo'] ?? false);
        $stcLic        = (bool) ($stc['can_licitacao'] ?? false);
        $stcDefaultCanal = $stcVar ? 'varejo' : 'licitacao';
        $stcIsSelected = !$sidebarIsConsolidado && $stcId === $sidebarTenantCompanyId;
        $stcIsActive   = $stcMfst->isActive();

        if ($stcVar && $stcLic)  $stcChannels = 'Varejo / Licitação';
        elseif ($stcVar)          $stcChannels = 'Varejo';
        elseif ($stcLic)          $stcChannels = 'Licitação';
        else                      $stcChannels = 'Inativa · Sem credencial';

        $stcDotClass  = $stcIsActive ? 'sdp-status-dot--active' : 'sdp-status-dot--inactive';
        $stcRowClass  = 'sdp-company-row'
                      . ($stcIsSelected ? ' sdp-company-row--active' : '')
                      . ($stcIsActive   ? '' : ' sdp-company-row--inactive');
        ?>
        <button
          class="<?php echo $stcRowClass; ?>"
          type="button"
          role="menuitem"
          title="<?php echo htmlspecialchars($stcCnpj, ENT_QUOTES, 'UTF-8'); ?>"
          data-sidebar-tenant="1"
          data-context-switch="1"
          data-company-id="<?php echo $stcId; ?>"
          data-canal="<?php echo htmlspecialchars($stcDefaultCanal, ENT_QUOTES, 'UTF-8'); ?>"
        >
          <div class="sdp-logo-wrap">
            <?php if ($stcLogo !== ''): ?>
            <img src="<?php echo htmlspecialchars($stcLogo, ENT_QUOTES, 'UTF-8'); ?>"
                 alt="<?php echo htmlspecialchars($stcName, ENT_QUOTES, 'UTF-8'); ?>"
                 class="sdp-logo-img">
            <?php else: ?>
            <div class="sdp-logo-initials"><?php echo htmlspecialchars($stcInit, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
          </div>
          <div class="sdp-company-info">
            <div class="sdp-company-name">
              <span class="sdp-status-dot <?php echo $stcDotClass; ?>" aria-hidden="true"></span>
              <span class="sdp-company-name-text"><?php echo htmlspecialchars($stcName, ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <div class="sdp-company-channels"><?php echo htmlspecialchars($stcChannels, ENT_QUOTES, 'UTF-8'); ?></div>
          </div>
          <?php if ($stcIsSelected): ?><span class="material-symbols-rounded sdp-active-check" aria-hidden="true">check_circle</span><?php endif; ?>
        </button>
      <?php endforeach; ?>
      <?php endif; ?>

      <?php if ($magIsAdmin): ?>
      <!-- ── 4. Admin quick actions ── -->
      <div class="sdp-divider"></div>
      <div class="sdp-admin-footer">
        <a href="admin.php" class="sdp-admin-link" role="menuitem">
          <span class="material-symbols-rounded" aria-hidden="true">admin_panel_settings</span>
          Administração
        </a>
        <a href="admin.php?tab=integrations" class="sdp-admin-link" role="menuitem">
          <span class="material-symbols-rounded" aria-hidden="true">cable</span>
          Integrações
        </a>
        <a href="admin.php?tab=webhooks" class="sdp-admin-link" role="menuitem">
          <span class="material-symbols-rounded" aria-hidden="true">webhook</span>
          Webhooks
        </a>
      </div>
      <?php endif; ?>

    </div>
  </div>

  <nav class="sidebar-nav mag-sidebar-nav" id="sidebar-menu" role="navigation" aria-label="Menu principal">
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
      <section
        class="mag-nav-group<?php echo $magGroup === 'Sistema' ? ' mag-nav-group--system' : ''; ?><?php echo $magGroupHasActive ? ' has-active' : ''; ?>"
        data-sidebar-group="<?php echo htmlspecialchars($magGroup, ENT_QUOTES, 'UTF-8'); ?>"
      >
        <button
          class="nav-section-label mag-nav-group__button"
          type="button"
          aria-expanded="true"
          aria-controls="<?php echo htmlspecialchars($magGroupId, ENT_QUOTES, 'UTF-8'); ?>"
        >
          <span><?php echo htmlspecialchars($magGroupLabels[$magGroup] ?? $magGroup, ENT_QUOTES, 'UTF-8'); ?></span>
          <span class="material-symbols-rounded mag-nav-group__chevron" aria-hidden="true">expand_more</span>
        </button>
        <div
          class="mag-nav-group__items"
          id="<?php echo htmlspecialchars($magGroupId, ENT_QUOTES, 'UTF-8'); ?>"
          role="list"
          <?php echo $magGroupHasActive ? 'data-has-active="1"' : ''; ?>
        >
          <?php foreach ($magGroupItems as $magItem): ?>
            <?php
              $magPageKey   = (string) ($magItem['page_key'] ?? '');
              $magAliases   = array_values(array_map('strval', (array) ($magItem['aliases'] ?? [])));
              $magMatches   = array_values(array_filter(array_merge([$magPageKey], $magAliases)));
              $magIsActive  = $magMatchesItem($magItem, $magActivePage);
              $magAccent    = (string) ($magItem['accent'] ?? '#2563eb');
              $magMatchAttr = implode(' ', $magMatches);
              $magBadgeKey  = (string) ($magItem['badge_key'] ?? '');
              $magChildren  = is_array($magItem['children'] ?? null) ? $magItem['children'] : [];
            ?>
            <a
              href="<?php echo htmlspecialchars((string) ($magItem['href'] ?? '#'), ENT_QUOTES, 'UTF-8'); ?>"
              class="nav-item<?php echo $magIsActive ? ' active current-page' : ''; ?>"
              role="listitem"
              data-page-key="<?php echo htmlspecialchars($magPageKey, ENT_QUOTES, 'UTF-8'); ?>"
              data-page-match="<?php echo htmlspecialchars($magMatchAttr, ENT_QUOTES, 'UTF-8'); ?>"
              data-accent="<?php echo htmlspecialchars($magAccent, ENT_QUOTES, 'UTF-8'); ?>"
              title="<?php echo htmlspecialchars((string) ($magItem['label'] ?? 'Menu'), ENT_QUOTES, 'UTF-8'); ?>"
              style="--item-accent: <?php echo htmlspecialchars($magAccent, ENT_QUOTES, 'UTF-8'); ?>; --item-accent-soft: <?php echo htmlspecialchars($magAccentSoft($magAccent), ENT_QUOTES, 'UTF-8'); ?>;"
              <?php echo $magIsActive ? 'aria-current="page"' : ''; ?>
            >
              <span class="material-symbols-rounded menu-icon" aria-hidden="true"><?php echo htmlspecialchars((string) ($magItem['icon'] ?? 'circle'), ENT_QUOTES, 'UTF-8'); ?></span>
              <span class="nav-label"><?php echo htmlspecialchars((string) ($magItem['label'] ?? 'Menu'), ENT_QUOTES, 'UTF-8'); ?></span>
              <?php if ($magBadgeKey !== '' && $magIsAdmin): ?>
              <span
                class="nav-badge"
                data-badge-key="<?php echo htmlspecialchars($magBadgeKey, ENT_QUOTES, 'UTF-8'); ?>"
                aria-label="Alertas"
                hidden
              ></span>
              <?php endif; ?>
            </a>
            <?php if ($magChildren !== []): ?>
            <div
              class="nav-sub-items<?php echo $magIsActive ? '' : ' is-hidden'; ?>"
              id="subitems-<?php echo htmlspecialchars($magPageKey, ENT_QUOTES, 'UTF-8'); ?>"
              style="--item-accent: <?php echo htmlspecialchars($magAccent, ENT_QUOTES, 'UTF-8'); ?>;"
            >
              <?php foreach ($magChildren as $magChild): ?>
                <?php
                  $childAccent = (string) ($magChild['accent'] ?? $magAccent);
                ?>
                <a
                  href="<?php echo htmlspecialchars((string) ($magChild['href'] ?? '#'), ENT_QUOTES, 'UTF-8'); ?>"
                  class="nav-item nav-item--sub"
                  title="<?php echo htmlspecialchars((string) ($magChild['label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                  style="--item-accent: <?php echo htmlspecialchars($childAccent, ENT_QUOTES, 'UTF-8'); ?>; --item-accent-soft: <?php echo htmlspecialchars($magAccentSoft($childAccent), ENT_QUOTES, 'UTF-8'); ?>;"
                >
                  <span class="material-symbols-rounded menu-icon" aria-hidden="true"><?php echo htmlspecialchars((string) ($magChild['icon'] ?? 'circle'), ENT_QUOTES, 'UTF-8'); ?></span>
                  <span class="nav-label"><?php echo htmlspecialchars((string) ($magChild['label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                </a>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endforeach; ?>
  </nav>

  <button
    class="sidebar-collapse-toggle"
    id="sidebarCollapseToggle"
    type="button"
    aria-label="Recolher menu"
    title="Recolher menu (Ctrl+B)"
  >
    <span class="material-symbols-rounded" aria-hidden="true">keyboard_double_arrow_left</span>
    <span class="nav-label">Recolher menu</span>
    <kbd class="kb-hint">Ctrl+B</kbd>
  </button>

  <?php if ($magAppVersion !== ''): ?>
    <div class="mag-sidebar-version"><?php echo htmlspecialchars($magAppVersion, ENT_QUOTES, 'UTF-8'); ?></div>
  <?php endif; ?>
  <input type="file" id="sidebarAvatarInput" accept="image/jpeg,image/png,image/webp,image/gif" hidden aria-hidden="true">
</aside>
<script>
(function () {
  'use strict';
  var sidebar     = document.getElementById('sidebar');
  var mainToggle  = document.getElementById('menu_toggle');
  var activeTip   = null;

  /* ── 1. Collapse toggle + Ctrl+B ─────────────────────────────────────────── */
  var collapseBtn = document.getElementById('sidebarCollapseToggle');
  if (collapseBtn) {
    collapseBtn.addEventListener('click', function () { if (mainToggle) mainToggle.click(); });
  }
  document.addEventListener('keydown', function (e) {
    if (e.ctrlKey && !e.altKey && !e.shiftKey && e.key === 'b') {
      var tag = (document.activeElement || {}).tagName || '';
      if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
      if (document.activeElement && document.activeElement.isContentEditable) return;
      e.preventDefault();
      if (mainToggle) mainToggle.click();
    }
  });

  /* ── 2. Tooltip on collapsed icons (position:fixed, immune to overflow) ──── */
  function showTip(navItem) {
    if (!sidebar || !sidebar.classList.contains('collapsed')) return;
    var label = navItem.querySelector('.nav-label');
    var text  = (label && label.textContent.trim()) || navItem.getAttribute('title') || '';
    if (!text) return;
    hideTip();
    var tip = document.createElement('div');
    tip.className = 'sidebar-nav-tooltip';
    tip.textContent = text;
    document.body.appendChild(tip);
    var rect = navItem.getBoundingClientRect();
    tip.style.top  = Math.round(rect.top + (rect.height - tip.offsetHeight) / 2) + 'px';
    tip.style.left = Math.round(rect.right + 10) + 'px';
    activeTip = tip;
  }
  function hideTip() { if (activeTip) { activeTip.remove(); activeTip = null; } }

  document.querySelectorAll('#sidebar-menu .nav-item').forEach(function (el) {
    el.addEventListener('mouseenter', function () { showTip(el); });
    el.addEventListener('mouseleave', hideTip);
    el.addEventListener('focus',      function () { showTip(el); });
    el.addEventListener('blur',       hideTip);
  });
  if (sidebar) {
    new MutationObserver(function () {
      if (!sidebar.classList.contains('collapsed')) hideTip();
    }).observe(sidebar, { attributes: true, attributeFilter: ['class'] });
  }

  /* ── 3. Group collapse persistence (localStorage per group) ──────────────── */
  function saveGroup(name, collapsed) {
    try { localStorage.setItem('biomie.nav.group.' + name, collapsed ? '1' : '0'); } catch (e) {}
  }
  function loadGroups() {
    document.querySelectorAll('[data-sidebar-group]').forEach(function (group) {
      var name = group.dataset.sidebarGroup;
      var saved;
      try { saved = localStorage.getItem('biomie.nav.group.' + name); } catch (e) {}
      if (saved === null) return; // respect default (expanded)
      var btn = group.querySelector('.mag-nav-group__button');
      if (saved === '1') {
        group.classList.add('is-collapsed');
        if (btn) btn.setAttribute('aria-expanded', 'false');
      } else {
        group.classList.remove('is-collapsed');
        if (btn) btn.setAttribute('aria-expanded', 'true');
      }
    });
  }
  document.querySelectorAll('.mag-nav-group__button').forEach(function (btn) {
    btn.addEventListener('click', function () {
      setTimeout(function () {
        var group = btn.closest('[data-sidebar-group]');
        if (group) saveGroup(group.dataset.sidebarGroup, group.classList.contains('is-collapsed'));
      }, 60);
    });
  });
  loadGroups();

  /* ── 4. Profile dropdown (position:fixed anchored via JS) ───────────────── */
  var profileWrap     = document.getElementById('sidebarProfileWrap');
  var profileBtn      = document.getElementById('sidebarProfileBtn');
  var profileDropdown = document.getElementById('sidebarProfileDropdown');
  var sidebarCsrf     = (document.querySelector('meta[name="biomie-csrf"]') || {}).content || '';

  function positionProfileDropdown() {
    var rect  = profileWrap.getBoundingClientRect();
    var dpW   = Math.max(rect.width, 260);
    profileDropdown.style.top   = (rect.bottom + 6) + 'px';
    profileDropdown.style.left  = rect.left + 'px';
    profileDropdown.style.width = dpW + 'px';
  }

  function doTenantSwitch(cid, canal, disableEl) {
    var runtime = window.BIOMIE_CONTEXT;
    if (!runtime || typeof runtime.switchContext !== 'function') {
      alert('O seletor de contexto ainda não está pronto. Recarregue a página.');
      return;
    }
    var nameEl = disableEl && disableEl.querySelector('.sdp-company-name');
    var targetName = nameEl ? nameEl.textContent.trim() : '';
    var target = canal === 'consolidado'
      ? { target_type: 'group' }
      : {
          target_type: 'company',
          company_id: cid,
          business_scope: canal
        };
    runtime.switchContext(target, {
      selectedElement: disableEl,
      targetName: targetName
    }).catch(function (error) {
      alert(
        'Não foi possível mudar para ' + (targetName || 'o contexto selecionado') + '.\n'
        + 'O contexto anterior foi preservado.\n\n'
        + (error && error.message ? error.message : 'Tente novamente.')
      );
    });
  }

  if (profileBtn && profileDropdown && profileWrap) {
    // Move dropdown to body so position:fixed escapes sidebar stacking context
    document.body.appendChild(profileDropdown);

    profileBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      var opening = profileDropdown.hidden;
      if (opening) positionProfileDropdown();
      profileDropdown.hidden = !opening;
      profileWrap.classList.toggle('is-open', opening);
      profileBtn.setAttribute('aria-expanded', opening ? 'true' : 'false');
    });
    document.addEventListener('click', function (e) {
      if (!profileWrap.contains(e.target) && !profileDropdown.contains(e.target)) {
        profileDropdown.hidden = true;
        profileWrap.classList.remove('is-open');
        profileBtn.setAttribute('aria-expanded', 'false');
      }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !profileDropdown.hidden) {
        profileDropdown.hidden = true;
        profileWrap.classList.remove('is-open');
        profileBtn.focus();
      }
    });
    window.addEventListener('resize', function () {
      if (!profileDropdown.hidden) positionProfileDropdown();
    });

    // Company/canal tenant buttons inside the dropdown
    profileDropdown.querySelectorAll('[data-sidebar-tenant]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        doTenantSwitch(parseInt(this.dataset.companyId, 10) || 0, this.dataset.canal, this);
      });
    });
  }

  /* ── 5. Keyboard navigation (↑ ↓ Home End within sidebar) ───────────────── */
  var allNavItems = Array.from(document.querySelectorAll('#sidebar-menu .nav-item'));
  allNavItems.forEach(function (item, i) {
    item.addEventListener('keydown', function (e) {
      var next;
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        next = allNavItems[i + 1]; if (next) next.focus();
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        next = allNavItems[i - 1]; if (next) next.focus();
      } else if (e.key === 'Home') {
        e.preventDefault();
        allNavItems[0].focus();
      } else if (e.key === 'End') {
        e.preventDefault();
        allNavItems[allNavItems.length - 1].focus();
      }
    });
  });

  /* ── 6. Sub-items: show only when parent page is active ──────────────────── */
  document.querySelectorAll('.nav-sub-items').forEach(function (sub) {
    var parentKey  = sub.id.replace('subitems-', '');
    var parentItem = document.querySelector('[data-page-key="' + parentKey + '"]');
    if (!parentItem) return;
    var isActive = parentItem.classList.contains('active') || parentItem.classList.contains('current-page');
    sub.classList.toggle('is-hidden', !isActive);
  });

  /* ── 7. Status badges (async, delayed, admin-only) ───────────────────────── */
  if (document.querySelector('[data-sidebar-badges]') && document.querySelector('[data-badge-key]')) {
    setTimeout(function () {
      var healthP = fetch('api/system_health.php', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { return r.json(); }).catch(function () { return {}; });
      var omieP = fetch('api/omie_status.php', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { return r.json(); }).catch(function () { return {}; });

      Promise.all([healthP, omieP]).then(function (res) {
        var health = res[0] || {};
        var omie   = res[1] || {};

        /* Health badge */
        var checks = Array.isArray(health.checks) ? health.checks : Object.values(health.checks || {});
        var crit   = checks.filter(function (c) { var s = String(c.status || '').toLowerCase(); return s === 'critical' || s === 'danger' || s === 'error'; }).length;
        var warn   = checks.filter(function (c) { var s = String(c.status || '').toLowerCase(); return s === 'warning' || s === 'warn'; }).length;
        document.querySelectorAll('[data-badge-key="health"]').forEach(function (b) {
          if (crit > 0) { b.textContent = crit; b.className = 'nav-badge danger'; b.hidden = false; }
          else if (warn > 0) { b.textContent = warn; b.className = 'nav-badge warn'; b.hidden = false; }
          else { b.hidden = true; }
        });

        /* Omie badge */
        var api      = omie.api || {};
        var lastErr  = omie.last_error || {};
        var syncSt   = omie.source_sync_status || {};
        var omieDown = api.status && String(api.status).toLowerCase() !== 'ok';
        var hasErr   = !!(lastErr && lastErr.message);
        var syncOld  = syncSt.age_minutes && Number(syncSt.age_minutes) > 120;
        document.querySelectorAll('[data-badge-key="omie"]').forEach(function (b) {
          if (omieDown || hasErr) { b.textContent = '!'; b.className = 'nav-badge danger'; b.hidden = false; }
          else if (syncOld) { b.textContent = '!'; b.className = 'nav-badge warn'; b.hidden = false; }
          else { b.hidden = true; }
        });
      });
    }, 2000); // Non-critical — load after main content
  }

  /* ── 8. Mark has-active on groups (for CSS highlight) ───────────────────── */
  document.querySelectorAll('[data-sidebar-group]').forEach(function (group) {
    var hasActive = group.querySelector('.nav-item.active, .nav-item.current-page');
    if (hasActive) group.classList.add('has-active');
  });

  /* ── 9. Avatar upload ────────────────────────────────────────────────────── */
  var avatarCamBtn   = document.getElementById('sidebarAvatarCamBtn');
  var avatarInput    = document.getElementById('sidebarAvatarInput');
  var avatarEndpoint = <?php echo json_encode(\BIomie\Auth::url('api/upload_avatar.php')); ?>;

  if (avatarCamBtn && avatarInput) {
    avatarCamBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      avatarInput.click();
    });
    avatarInput.addEventListener('change', function () {
      var file = avatarInput.files && avatarInput.files[0];
      if (!file) return;
      var fd = new FormData();
      fd.append('avatar', file);
      avatarCamBtn.disabled = true;
      fetch(avatarEndpoint, {
        method: 'POST',
        headers: { 'X-CSRF-Token': sidebarCsrf },
        body: fd,
      })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.ok && data.url) {
          document.querySelectorAll('.profile-avatar-img').forEach(function (img) {
            img.src = data.url;
          });
          // If no photo existed before, swap initials div → img
          document.querySelectorAll('.profile-avatar-wrap .profile-avatar:not(.profile-avatar-img), .sdp-avatar-wrap .profile-avatar:not(.profile-avatar-img)').forEach(function (el) {
            var img = document.createElement('img');
            img.src = data.url;
            img.alt = 'Foto de perfil';
            img.className = el.className + ' profile-avatar-img';
            el.replaceWith(img);
          });
        } else {
          alert('Erro ao salvar foto: ' + (data.message || 'Tente novamente.'));
        }
        avatarCamBtn.disabled = false;
        avatarInput.value = '';
      })
      .catch(function () {
        alert('Falha de rede. Tente novamente.');
        avatarCamBtn.disabled = false;
        avatarInput.value = '';
      });
    });
  }

})();
</script>

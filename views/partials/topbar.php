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

    <?php if (\BIomie\Support\TenantContext::isBooted()): ?>
      <div class="biomie-omnisearch" data-omnisearch>
        <span class="material-symbols-rounded biomie-omnisearch__icon" aria-hidden="true">search</span>
        <input
          type="text"
          class="biomie-omnisearch__input"
          id="biomieOmniSearch"
          placeholder="Buscar NF, pedido, cliente, valor…"
          title="Atalhos: / ou Ctrl+K"
          autocomplete="off"
          spellcheck="false"
          role="combobox"
          aria-expanded="false"
          aria-controls="biomieOmniResults"
          aria-autocomplete="list"
        />
        <button type="button" class="biomie-omnisearch__clear" data-omnisearch-clear hidden aria-label="Limpar busca">
          <span class="material-symbols-rounded" aria-hidden="true">close</span>
        </button>
        <kbd class="biomie-omnisearch__kbd" aria-hidden="true">/</kbd>
        <div class="biomie-omnisearch__panel" id="biomieOmniResults" role="listbox" hidden></div>
      </div>
    <?php endif; ?>

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

    <?php if (\BIomie\Support\TenantContext::isBooted()): ?>
      <div class="biomie-vendor-filter" data-vendor-filter>
        <button
          type="button"
          class="biomie-vendor-filter__trigger"
          data-vendor-filter-toggle
          aria-label="Selecionar vendedores"
          aria-controls="biomieVendorPanelDesktop"
          aria-expanded="false"
          aria-haspopup="dialog"
          title="Filtrar por um ou mais vendedores"
        >
          <span class="material-symbols-rounded biomie-vendor-filter__icon" aria-hidden="true">group</span>
          <span class="biomie-vendor-filter__label" data-vendor-filter-label>Todos os vendedores</span>
          <span class="biomie-vendor-filter__count" data-vendor-filter-count hidden>0</span>
          <span class="material-symbols-rounded biomie-vendor-filter__chevron" aria-hidden="true">expand_more</span>
        </button>
        <div
          class="biomie-vendor-filter__panel"
          id="biomieVendorPanelDesktop"
          data-vendor-filter-panel
          role="dialog"
          aria-label="Selecionar vendedores"
          hidden
        >
          <div class="biomie-vendor-filter__panel-head">
            <strong>Vendedores</strong>
            <span data-vendor-filter-summary>Todos</span>
          </div>
          <label class="biomie-vendor-filter__search">
            <span class="material-symbols-rounded" aria-hidden="true">search</span>
            <input
              type="search"
              data-vendor-filter-search
              placeholder="Buscar vendedor..."
              autocomplete="off"
              aria-label="Buscar vendedor"
            />
          </label>
          <div class="biomie-vendor-filter__status" data-vendor-filter-status>Carregando vendedores...</div>
          <div class="biomie-vendor-filter__options" data-vendor-filter-list role="group" aria-label="Vendedores disponíveis"></div>
          <div class="biomie-vendor-filter__footer">
            <button type="button" class="biomie-vendor-filter__clear" data-vendor-filter-clear>Limpar</button>
            <button type="button" class="biomie-vendor-filter__apply" data-vendor-filter-apply>Aplicar</button>
          </div>
        </div>
      </div>
      <?php if (($magContextFiltersEnabled ?? true) === true): ?>
        <button
          type="button"
          class="biomie-context-filter-trigger"
          data-context-filters-open
          aria-label="Mais filtros"
          aria-controls="biomieContextFilters"
          aria-expanded="false"
          title="Mais filtros"
        >
          <span class="material-symbols-rounded" aria-hidden="true">tune</span>
          <span class="biomie-context-filter-trigger__badge" data-context-filters-count hidden>0</span>
        </button>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="topbar-actions">
    <?php if ($magEnvironment !== '' && strtolower($magEnvironment) !== 'production'): ?>
      <span class="mag-env-badge"><?php echo htmlspecialchars($magEnvironment, ENT_QUOTES, 'UTF-8'); ?></span>
    <?php endif; ?>

    <?php
    // ── Company / Canal selector ─────────────────────────────────────────────
    $tenantBooted     = \BIomie\Support\TenantContext::isBooted();
    $tenantCompanies  = $tenantBooted
        ? \BIomie\Support\TenantContext::cachedAccessible()
        : [];
    $tenantCompanyId  = $tenantBooted
        ? \BIomie\Support\TenantContext::companyId()
        : 0;
    $tenantCanal      = $tenantBooted
        ? \BIomie\Support\TenantContext::canal()
        : 'varejo';
    $tenantSlug       = $tenantBooted
        ? \BIomie\Support\TenantContext::slug()
        : 'MAG_FILIAL';
    $tenantIsConsolidado = $tenantBooted
        && \BIomie\Support\TenantContext::isConsolidado();
    $tenantManifest   = \BIomie\Company\CompanyPackageLoader::forTenant();
    $tenantDisplayName = $tenantIsConsolidado
        ? 'Consolidado'
        : ($tenantManifest->name() ?: $tenantSlug);
    $tenantLabel      = match (mb_strtolower($tenantCanal)) {
        'licitacao'    => 'Licitação',
        'consolidado'  => 'Grupo',
        default        => 'Varejo',
    };
    $tenantIcon = $tenantIsConsolidado ? 'hub' : 'business';
    ?>
    <span class="mag-tenant-badge" title="Empresa ativa — use o menu do usuário para trocar">
      <span class="material-symbols-rounded" aria-hidden="true"><?php echo $tenantIcon; ?></span>
      <?php echo htmlspecialchars($tenantDisplayName, ENT_QUOTES, 'UTF-8'); ?>
      <span class="mag-tenant-badge__canal"><?php echo htmlspecialchars($tenantLabel, ENT_QUOTES, 'UTF-8'); ?></span>
    </span>

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

    <?php if (\BIomie\Support\TenantContext::isBooted()): ?>
    <div class="biomie-notif" data-notif-root>
      <button
        type="button"
        class="btn-icon biomie-notif__bell"
        data-notif-bell
        aria-label="Notificações"
        aria-haspopup="dialog"
        aria-expanded="false"
      >
        <span class="material-symbols-rounded" aria-hidden="true">notifications</span>
        <span class="biomie-notif__badge" data-notif-badge hidden>0</span>
      </button>
      <div class="biomie-notif__panel" data-notif-panel hidden role="dialog" aria-label="Central de notificações">
        <div class="biomie-notif__head">
          <strong>Notificações</strong>
          <div class="biomie-notif__head-actions">
            <button type="button" class="biomie-notif__filter is-active" data-notif-filter="all">Todas</button>
            <button type="button" class="biomie-notif__filter" data-notif-filter="unread">Não lidas</button>
            <button type="button" class="biomie-notif__markall" data-notif-markall title="Marcar todas como lidas">
              <span class="material-symbols-rounded" aria-hidden="true">done_all</span>
            </button>
          </div>
        </div>
        <div class="biomie-notif__list" data-notif-list aria-live="polite">
          <div class="biomie-notif__state" data-notif-empty hidden>Sem notificações por aqui. ✨</div>
          <div class="biomie-notif__state" data-notif-loading>Carregando…</div>
        </div>
        <a class="biomie-notif__footer" href="notificacoes.php">Ver todas as notificações</a>
      </div>
    </div>
    <?php endif; ?>

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

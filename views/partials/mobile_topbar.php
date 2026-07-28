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

  <?php if (\BIomie\Support\TenantContext::isBooted()): ?>
  <div class="biomie-vendor-filter biomie-vendor-filter--mobile-topbar" data-vendor-filter>
    <button
      type="button"
      class="mag-mobile-topbar__vendor-btn biomie-vendor-filter__trigger"
      data-vendor-filter-toggle
      aria-label="Selecionar vendedores"
      aria-controls="biomieVendorPanelMobile"
      aria-expanded="false"
      aria-haspopup="dialog"
      title="Filtrar por vendedores"
    >
      <span class="material-symbols-rounded biomie-vendor-filter__icon" aria-hidden="true">group</span>
      <span class="biomie-vendor-filter__label mag-visually-hidden" data-vendor-filter-label>Todos os vendedores</span>
      <span class="biomie-vendor-filter__count" data-vendor-filter-count hidden>0</span>
    </button>
    <div class="biomie-vendor-filter__backdrop" data-vendor-filter-backdrop hidden></div>
    <div
      class="biomie-vendor-filter__panel"
      id="biomieVendorPanelMobile"
      data-vendor-filter-panel
      role="dialog"
      aria-modal="true"
      aria-label="Selecionar vendedores"
      hidden
    >
      <div class="biomie-vendor-filter__panel-head">
        <strong>Vendedores</strong>
        <span data-vendor-filter-summary>Todos</span>
        <button type="button" class="biomie-vendor-filter__panel-close" data-vendor-filter-close aria-label="Fechar seleção">
          <span class="material-symbols-rounded" aria-hidden="true">close</span>
        </button>
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
    class="mag-mobile-topbar__filter-btn biomie-context-filter-trigger"
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
  <button
    type="button"
    class="mag-mobile-topbar__search-btn"
    data-mobile-search-open
    aria-label="Buscar notas, pedidos, clientes, produtos e vendedores"
    aria-controls="biomieMobileSearchOverlay"
    aria-expanded="false"
  >
    <span class="material-symbols-rounded" aria-hidden="true">search</span>
  </button>
  <?php endif; ?>

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

<?php if (\BIomie\Support\TenantContext::isBooted()): ?>
<!-- Overlay de busca global mobile. O vendedor fica no atalho próprio do header. -->
<div class="biomie-mobile-search" id="biomieMobileSearchOverlay" role="dialog" aria-modal="true" aria-label="Busca" hidden>
  <div class="biomie-mobile-search__bar">
    <span class="material-symbols-rounded" aria-hidden="true">search</span>
    <input
      type="text"
      class="biomie-mobile-search__input"
      id="biomieOmniSearchMobile"
      placeholder="Buscar notas, pedidos, clientes…"
      autocomplete="off"
      spellcheck="false"
      role="combobox"
      aria-expanded="false"
      aria-controls="biomieOmniResultsMobile"
      aria-autocomplete="list"
    />
    <button type="button" class="biomie-mobile-search__close" data-mobile-search-close aria-label="Fechar busca">
      <span class="material-symbols-rounded" aria-hidden="true">close</span>
    </button>
  </div>
  <div class="biomie-mobile-search__results" id="biomieOmniResultsMobile" role="listbox"></div>
</div>
<?php endif; ?>

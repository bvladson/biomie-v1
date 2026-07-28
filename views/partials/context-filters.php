<?php

declare(strict_types=1);

if (!\BIomie\Support\TenantContext::isBooted()) {
    return;
}
?>
<div class="biomie-context-filters-backdrop" data-context-filters-backdrop hidden></div>
<section
  class="biomie-context-filters"
  id="biomieContextFilters"
  data-context-filters
  role="dialog"
  aria-modal="false"
  aria-labelledby="biomieContextFiltersTitle"
  hidden
>
  <header class="biomie-context-filters__header">
    <div>
      <span class="biomie-context-filters__eyebrow">Leitura do sistema</span>
      <h2 id="biomieContextFiltersTitle">Mais filtros</h2>
    </div>
    <button
      type="button"
      class="biomie-context-filters__close"
      data-context-filters-close
      aria-label="Fechar filtros"
    >
      <span class="material-symbols-rounded" aria-hidden="true">close</span>
    </button>
  </header>

  <div class="biomie-context-filters__body">
    <section class="biomie-context-filters__section" data-context-filters-period>
      <div class="biomie-context-filters__section-title">
        <span class="material-symbols-rounded" aria-hidden="true">date_range</span>
        Per&iacute;odo
      </div>
      <div class="biomie-context-filters__dates">
        <label>
          <span>In&iacute;cio</span>
          <input
            id="biomieContextFilterStart"
            type="date"
            data-context-filter-start
            autocomplete="off"
          />
        </label>
        <label>
          <span>Fim</span>
          <input
            id="biomieContextFilterEnd"
            type="date"
            data-context-filter-end
            autocomplete="off"
          />
        </label>
      </div>
      <div class="biomie-context-filters__presets" aria-label="Atalhos de per&iacute;odo">
        <button type="button" data-context-filter-preset="month">Este m&ecirc;s</button>
        <button type="button" data-context-filter-preset="year">Ano atual</button>
        <button type="button" data-context-filter-preset="rolling12">12 meses</button>
      </div>
    </section>

    <!-- MEGA FUNIL — Vendedores: multissele&ccedil;&atilde;o com busca, no MESMO painel.
         Estado compartilhado com o seletor do topo (fonte &uacute;nica). -->
    <section
      class="biomie-context-filters__section biomie-megafunnel__sellers"
      data-megafunnel-sellers
      hidden
    >
      <div class="biomie-context-filters__section-title">
        <span class="material-symbols-rounded" aria-hidden="true">group</span>
        Vendedores
        <span class="biomie-megafunnel__badge" data-megafunnel-sellers-badge hidden>0</span>
      </div>
      <label class="biomie-megafunnel__search">
        <span class="material-symbols-rounded" aria-hidden="true">search</span>
        <input
          type="search"
          data-megafunnel-sellers-search
          placeholder="Buscar vendedor..."
          autocomplete="off"
          aria-label="Buscar vendedor"
        />
      </label>
      <div
        class="biomie-megafunnel__options"
        data-megafunnel-sellers-list
        role="group"
        aria-label="Vendedores dispon&iacute;veis"
      ></div>
      <div class="biomie-megafunnel__summary">
        <span data-megafunnel-sellers-summary aria-live="polite"></span>
      </div>
      <div class="biomie-megafunnel__actions">
        <button type="button" data-megafunnel-sellers-all>Selecionar todos</button>
        <button type="button" data-megafunnel-sellers-none>Limpar sele&ccedil;&atilde;o</button>
      </div>
    </section>

    <!-- MEGA FUNIL — Opera&ccedil;&atilde;o (canal): s&oacute; aparece em contexto mixed. -->
    <section
      class="biomie-context-filters__section biomie-megafunnel__channel"
      data-megafunnel-channel
      hidden
    >
      <div class="biomie-context-filters__section-title">
        <span class="material-symbols-rounded" aria-hidden="true">storefront</span>
        Opera&ccedil;&atilde;o
      </div>
      <div class="biomie-megafunnel__chips" data-megafunnel-channel-chips role="group" aria-label="Operac&atilde;o"></div>
    </section>

    <section
      class="biomie-context-filters__section biomie-context-filters__context"
      data-context-filter-context
      hidden
    >
      <div class="biomie-context-filters__section-title">
        <span class="material-symbols-rounded" aria-hidden="true">filter_alt</span>
        Filtros desta p&aacute;gina
      </div>
      <div class="biomie-context-filters__slot" data-context-filter-slot></div>
    </section>

    <p class="biomie-context-filters__error" data-context-filters-error role="alert" hidden></p>
  </div>

  <footer class="biomie-context-filters__footer">
    <button type="button" class="biomie-context-filters__clear" data-context-filters-clear>
      Limpar
    </button>
    <button type="button" class="biomie-context-filters__apply" data-context-filters-apply>
      <span class="material-symbols-rounded" aria-hidden="true">filter_alt</span>
      Aplicar filtros
    </button>
  </footer>
</section>

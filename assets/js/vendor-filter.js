/*
 * BIomie — seletor global multiplo de vendedores.
 *
 * `vendor_ids` (CSV) e o estado canonico. Para manter compatibilidade com
 * paginas legadas, `vendor_id`/`vendor_nome` continuam presentes quando a
 * selecao possui exatamente um vendedor.
 */
(function () {
  'use strict';

  if (window.BIomieVendorFilter && window.BIomieVendorFilter.version) return;

  var instances = [];
  var vendors = [];
  var idToVendor = new Map();
  var currentIds = [];
  var nav = null;
  var loaded = false;
  var loadFailed = false;
  var activeInstance = null;
  var STORE_KEY = 'biomie:vendor-filter:company';
  var MAX_SELECTED = 50;

  function uniqueIds(value) {
    var raw = Array.isArray(value) ? value : String(value == null ? '' : value).split(',');
    var seen = new Set();
    return raw.map(function (item) {
      return String(item == null ? '' : item).trim();
    }).filter(function (id) {
      if (!id || id.indexOf(',') !== -1 || seen.has(id)) return false;
      seen.add(id);
      return true;
    }).slice(0, MAX_SELECTED);
  }

  function sameIds(left, right) {
    var a = uniqueIds(left).slice().sort();
    var b = uniqueIds(right).slice().sort();
    return a.length === b.length && a.every(function (id, index) { return id === b[index]; });
  }

  function normalize(value) {
    return String(value == null ? '' : value)
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLowerCase()
      .trim();
  }

  function initials(name) {
    var parts = String(name || '').trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return '?';
    return (parts[0].charAt(0) + (parts.length > 1 ? parts[parts.length - 1].charAt(0) : '')).toUpperCase();
  }

  function selectedVendors(ids) {
    return uniqueIds(ids).map(function (id) {
      return idToVendor.get(id) || { id: id, name: id };
    });
  }

  function selectedNames(ids) {
    return selectedVendors(ids).map(function (vendor) { return vendor.name; });
  }

  function readUrlSelection() {
    try {
      var params = new URLSearchParams(window.location.search || '');
      if (params.has('vendor_ids')) return uniqueIds(params.get('vendor_ids'));
      if (params.has('vendor_id')) return uniqueIds(params.get('vendor_id'));
    } catch (error) {
      // A sessao ainda e suficiente em navegadores com URL restrita.
    }
    return null;
  }

  function readInitialSelection() {
    var fromUrl = readUrlSelection();
    if (fromUrl !== null) return fromUrl;
    if (!nav || typeof nav.get !== 'function') return [];
    var multiple = uniqueIds(nav.get('vendor_ids'));
    return multiple.length ? multiple : uniqueIds(nav.get('vendor_id'));
  }

  function clearCompanyScopedState() {
    if (!nav || typeof nav.merge !== 'function') return;
    nav.merge({
      vendor_ids: null,
      vendor_id: null,
      vendor_nome: null
    });
  }

  function resetWhenCompanyChanges() {
    var company = (document.body && document.body.getAttribute('data-company')) || '';
    try {
      // O contexto e por aba; localStorage faria duas empresas abertas em abas
      // diferentes apagarem a selecao uma da outra a cada recarga.
      var previous = window.sessionStorage.getItem(STORE_KEY);
      if (previous !== null && previous !== company) {
        clearCompanyScopedState();
        currentIds = [];
        updateUrl([], []);
      }
      window.sessionStorage.setItem(STORE_KEY, company);
    } catch (error) {
      // Storage pode estar indisponivel; o servidor continua validando o escopo.
    }
  }

  function updateUrl(ids, names) {
    try {
      var url = new URL(window.location.href);
      var csv = ids.join(',');
      if (csv) url.searchParams.set('vendor_ids', csv);
      else url.searchParams.delete('vendor_ids');

      if (ids.length === 1) {
        url.searchParams.set('vendor_id', ids[0]);
        if (names[0]) url.searchParams.set('vendor_nome', names[0]);
        else url.searchParams.delete('vendor_nome');
      } else {
        url.searchParams.delete('vendor_id');
        url.searchParams.delete('vendor_nome');
      }
      window.history.replaceState(
        window.history.state,
        '',
        url.pathname + (url.searchParams.toString() ? '?' + url.searchParams.toString() : '') + url.hash
      );
    } catch (error) {
      // History API pode ser bloqueada em webviews; NavContext permanece ativo.
    }
  }

  function persist(ids) {
    ids = uniqueIds(ids);
    var names = selectedNames(ids);
    if (nav && typeof nav.merge === 'function') {
      nav.merge({
        vendor_ids: ids.length ? ids.join(',') : null,
        vendor_id: ids.length === 1 ? ids[0] : null,
        vendor_nome: ids.length === 1 ? names[0] : null
      });
    }
    updateUrl(ids, names);
  }

  function appliedLabel() {
    if (!currentIds.length) return 'Todos os vendedores';
    if (currentIds.length === 1) return selectedNames(currentIds)[0] || '1 vendedor';
    return currentIds.length + ' vendedores';
  }

  function updateAppliedUi() {
    var label = appliedLabel();
    instances.forEach(function (instance) {
      instance.root.classList.toggle('biomie-vendor-filter--active', currentIds.length > 0);
      if (instance.label) instance.label.textContent = label;
      if (instance.count) {
        instance.count.textContent = String(currentIds.length);
        instance.count.hidden = currentIds.length === 0;
      }
      instance.toggle.setAttribute(
        'aria-label',
        currentIds.length
          ? ('Vendedores selecionados: ' + label + '. Alterar filtro')
          : 'Selecionar vendedores'
      );
      if (instance !== activeInstance) instance.draft = new Set(currentIds);
    });
  }

  function draftIds(instance) {
    return Array.from(instance.draft || []).filter(Boolean);
  }

  function updateDraftUi(instance) {
    var count = draftIds(instance).length;
    if (instance.summary) {
      instance.summary.textContent = count
        ? (count + ' selecionado' + (count === 1 ? '' : 's'))
        : 'Todos';
    }
    if (instance.clear) instance.clear.disabled = count === 0;
    if (instance.apply) {
      instance.apply.disabled = sameIds(draftIds(instance), currentIds);
      instance.apply.textContent = count ? ('Aplicar (' + count + ')') : 'Aplicar todos';
    }
    if (loaded && instance.list) {
      instance.list.querySelectorAll('[data-vendor-filter-option]').forEach(function (checkbox) {
        var disabled = count >= MAX_SELECTED && !checkbox.checked;
        checkbox.disabled = disabled;
        if (checkbox.parentElement) checkbox.parentElement.setAttribute('aria-disabled', disabled ? 'true' : 'false');
      });
    }
  }

  function setStatus(instance, message) {
    if (!instance.status) return;
    instance.status.textContent = String(message || '');
    instance.status.hidden = !message;
  }

  function renderOptions(instance) {
    if (!instance.list) return;
    instance.list.textContent = '';

    if (!loaded) {
      setStatus(instance, loadFailed ? 'Nao foi possivel carregar os vendedores.' : 'Carregando vendedores...');
      updateDraftUi(instance);
      return;
    }

    var term = normalize(instance.search ? instance.search.value : '');
    var filtered = vendors.filter(function (vendor) {
      return !term || normalize(vendor.name).indexOf(term) !== -1 || normalize(vendor.id).indexOf(term) !== -1;
    });
    if (!filtered.length) {
      setStatus(instance, term ? 'Nenhum vendedor encontrado.' : 'Nenhum vendedor disponivel.');
      updateDraftUi(instance);
      return;
    }
    setStatus(instance, '');

    filtered.forEach(function (vendor) {
      var option = document.createElement('label');
      option.className = 'biomie-vendor-filter__option';

      var checkbox = document.createElement('input');
      checkbox.type = 'checkbox';
      checkbox.value = vendor.id;
      checkbox.checked = instance.draft.has(vendor.id);
      checkbox.setAttribute('data-vendor-filter-option', vendor.id);
      checkbox.setAttribute('aria-label', vendor.name);
      checkbox.addEventListener('change', function () {
        if (checkbox.checked && instance.draft.size >= MAX_SELECTED) {
          checkbox.checked = false;
          setStatus(instance, 'Selecione no maximo ' + MAX_SELECTED + ' vendedores.');
          updateDraftUi(instance);
          return;
        }
        if (checkbox.checked) {
          instance.draft.add(vendor.id);
        } else {
          instance.draft.delete(vendor.id);
          if (instance.draft.size < MAX_SELECTED) setStatus(instance, '');
        }
        updateDraftUi(instance);
      });

      var avatar = document.createElement('span');
      avatar.className = 'biomie-vendor-filter__option-avatar';
      avatar.setAttribute('aria-hidden', 'true');
      avatar.textContent = initials(vendor.name);

      var name = document.createElement('span');
      name.className = 'biomie-vendor-filter__option-name';
      name.textContent = vendor.name;
      name.title = vendor.name;

      option.appendChild(checkbox);
      option.appendChild(avatar);
      option.appendChild(name);
      instance.list.appendChild(option);
    });
    updateDraftUi(instance);
  }

  function close(instance, restoreFocus) {
    if (!instance || instance.panel.hidden) return;
    instance.panel.hidden = true;
    if (instance.backdrop) instance.backdrop.hidden = true;
    instance.toggle.setAttribute('aria-expanded', 'false');
    if (instance.search) instance.search.value = '';
    instance.draft = new Set(currentIds);
    document.body.classList.remove('biomie-vendor-filter-open');
    if (activeInstance === instance) activeInstance = null;
    if (restoreFocus !== false && typeof instance.toggle.focus === 'function') instance.toggle.focus();
  }

  function closeOthers(except) {
    instances.forEach(function (instance) {
      if (instance !== except) close(instance, false);
    });
  }

  function open(instance) {
    if (!instance || !instance.panel.hidden) return;
    if (window.BIomieContextFilters && typeof window.BIomieContextFilters.close === 'function') {
      window.BIomieContextFilters.close(false);
    }
    closeOthers(instance);
    instance.draft = new Set(currentIds);
    instance.panel.hidden = false;
    instance.toggle.setAttribute('aria-expanded', 'true');
    activeInstance = instance;
    renderOptions(instance);
    if (instance.root.classList.contains('biomie-vendor-filter--mobile-topbar')) {
      document.body.classList.add('biomie-vendor-filter-open');
      if (instance.backdrop) instance.backdrop.hidden = false;
    }
    window.requestAnimationFrame(function () {
      if (instance.search) instance.search.focus();
    });
  }

  function toggle(instance) {
    if (instance.panel.hidden) open(instance);
    else close(instance, true);
  }

  function eventDetail(ids, source) {
    var names = selectedNames(ids);
    return {
      source: source || 'header',
      vendorIds: ids.slice(),
      vendorNames: names.slice(),
      vendorId: ids.length === 1 ? ids[0] : '',
      vendorName: ids.length === 1 ? names[0] : '',
      values: {
        vendor_ids: ids.slice(),
        vendor_ids_csv: ids.join(','),
        vendor_names: names.slice(),
        vendor_id: ids.length === 1 ? ids[0] : '',
        vendor_nome: ids.length === 1 ? names[0] : ''
      }
    };
  }

  function commit(instance) {
    var nextIds = draftIds(instance).slice(0, MAX_SELECTED);
    if (loaded) {
      nextIds = nextIds.filter(function (id) { return idToVendor.has(id); });
    }
    var changed = !sameIds(nextIds, currentIds);
    currentIds = nextIds;
    persist(currentIds);
    updateAppliedUi();
    close(instance, false);
    if (!changed) return;

    var detail = eventDetail(currentIds, 'header');
    var applyEvent = new CustomEvent('biomie:vendor-filter-apply', {
      bubbles: true,
      cancelable: true,
      detail: detail
    });
    var shouldReload = document.dispatchEvent(applyEvent);
    document.dispatchEvent(new CustomEvent('biomie:vendor-context-change', {
      bubbles: true,
      detail: detail
    }));
    if (shouldReload) window.location.reload();
  }

  /**
   * Aplica uma seleção programaticamente (ex: remover via chip de filtro ativo),
   * fora do fluxo do painel. Mesma cauda de commit(): persiste, dispara os
   * eventos e recarrega só se ninguém interceptar (mesma semântica de sempre).
   */
  function setSelection(ids, options) {
    options = options || {};
    var nextIds = uniqueIds(ids || []);
    if (loaded) {
      nextIds = nextIds.filter(function (id) { return idToVendor.has(id); });
    }
    var changed = !sameIds(nextIds, currentIds);
    currentIds = nextIds;
    persist(currentIds);
    updateAppliedUi();
    if (!changed) return;

    // Modo silencioso: só atualiza estado/URL/UI. Usado pelo mega funil, que
    // aplica vários eixos de uma vez e dispara UM único evento no final — assim
    // a página faz uma leitura só, em vez de uma por eixo alterado.
    if (options.silent === true) return;

    var detail = eventDetail(currentIds, 'header');
    var applyEvent = new CustomEvent('biomie:vendor-filter-apply', {
      bubbles: true,
      cancelable: true,
      detail: detail
    });
    var shouldReload = document.dispatchEvent(applyEvent);
    document.dispatchEvent(new CustomEvent('biomie:vendor-context-change', {
      bubbles: true,
      detail: detail
    }));
    if (shouldReload) window.location.reload();
  }

  function createInstance(root) {
    var instance = {
      root: root,
      toggle: root.querySelector('[data-vendor-filter-toggle]'),
      panel: root.querySelector('[data-vendor-filter-panel]'),
      label: root.querySelector('[data-vendor-filter-label]'),
      count: root.querySelector('[data-vendor-filter-count]'),
      summary: root.querySelector('[data-vendor-filter-summary]'),
      search: root.querySelector('[data-vendor-filter-search]'),
      status: root.querySelector('[data-vendor-filter-status]'),
      list: root.querySelector('[data-vendor-filter-list]'),
      clear: root.querySelector('[data-vendor-filter-clear]'),
      apply: root.querySelector('[data-vendor-filter-apply]'),
      closeButton: root.querySelector('[data-vendor-filter-close]'),
      backdrop: root.querySelector('[data-vendor-filter-backdrop]'),
      draft: new Set(currentIds)
    };
    if (!instance.toggle || !instance.panel || !instance.list) return null;

    // Efeitos da barra mobile criam um containing block. Um drawer fixed
    // mantido dentro dela ficaria preso a altura do header e poderia abrir
    // fora da viewport. Portaliza as camadas; o gatilho continua no header.
    if (root.classList.contains('biomie-vendor-filter--mobile-topbar')) {
      instance.panel.classList.add('biomie-vendor-filter__panel--mobile');
      document.body.appendChild(instance.panel);
      if (instance.backdrop) {
        instance.backdrop.classList.add('biomie-vendor-filter__backdrop--mobile');
        document.body.insertBefore(instance.backdrop, instance.panel);
      }
    }

    instance.toggle.addEventListener('click', function (event) {
      event.stopPropagation();
      toggle(instance);
    });
    instance.panel.addEventListener('click', function (event) { event.stopPropagation(); });
    if (instance.search) {
      instance.search.addEventListener('input', function () { renderOptions(instance); });
    }
    if (instance.clear) {
      instance.clear.addEventListener('click', function () {
        instance.draft.clear();
        renderOptions(instance);
      });
    }
    if (instance.apply) instance.apply.addEventListener('click', function () { commit(instance); });
    if (instance.closeButton) {
      instance.closeButton.addEventListener('click', function () { close(instance, true); });
    }
    if (instance.backdrop) {
      instance.backdrop.addEventListener('click', function () { close(instance, true); });
    }
    return instance;
  }

  function ingestVendors(payload) {
    vendors = Array.isArray(payload && payload.vendors)
      ? payload.vendors.map(function (vendor) {
        return {
          id: String(vendor && vendor.id != null ? vendor.id : '').trim(),
          name: String(
            vendor && vendor.label != null
              ? vendor.label
              : (vendor && vendor.name != null ? vendor.name : (vendor && vendor.id) || '')
          ).trim(),
          aliases: Array.isArray(vendor && vendor.aliases)
            ? vendor.aliases.map(function (alias) { return String(alias || '').trim(); }).filter(Boolean)
            : []
        };
      }).filter(function (vendor) { return vendor.id && vendor.name; })
      : [];
    vendors.sort(function (left, right) {
      return left.name.localeCompare(right.name, 'pt-BR', { sensitivity: 'base' });
    });
    idToVendor = new Map();
    vendors.forEach(function (vendor) {
      idToVendor.set(vendor.id, vendor);
      vendor.aliases.forEach(function (alias) {
        if (!idToVendor.has(alias)) idToVendor.set(alias, vendor);
      });
    });
    loaded = true;

    var beforeValidation = currentIds.slice();
    var allowed = uniqueIds(currentIds.map(function (id) {
      var vendor = idToVendor.get(id);
      return vendor ? vendor.id : '';
    }));
    currentIds = allowed;
    // Canonicaliza também a seleção válida vinda diretamente da URL, limpando
    // qualquer vendor_id singular obsoleto que tenha ficado na sessão.
    persist(currentIds);
    updateAppliedUi();
    instances.forEach(renderOptions);
    // O mega funil (context-filters) reaproveita ESTA lista — carregada uma única
    // vez por página. Sem o aviso, ele teria que buscar de novo o mesmo roster.
    document.dispatchEvent(new CustomEvent('biomie:vendor-list-ready', {
      bubbles: true,
      detail: { count: vendors.length }
    }));
    if (!sameIds(beforeValidation, currentIds) && beforeValidation.length) {
      window.location.reload();
    }
  }

  function loadVendors() {
    return fetch('api/vendors_list.php', {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'fetch' }
    }).then(function (response) {
      if (!response.ok) throw new Error('Falha ao carregar vendedores.');
      return response.json();
    }).then(function (payload) {
      if (!payload || payload.ok === false || !Array.isArray(payload.vendors)) {
        throw new Error('Resposta de vendedores invalida.');
      }
      ingestVendors(payload);
    }).catch(function () {
      loadFailed = true;
      instances.forEach(renderOptions);
    });
  }

  function syncExternalSelection(detail) {
    detail = detail || {};
    if (detail.source === 'header') return;
    var hasMultiple = Array.isArray(detail.vendorIds)
      || (detail.values && Array.isArray(detail.values.vendor_ids))
      || typeof detail.vendor_ids === 'string';
    var ids = hasMultiple
      ? uniqueIds(detail.vendorIds || (detail.values && detail.values.vendor_ids) || detail.vendor_ids)
      : uniqueIds(detail.vendorId);
    currentIds = (loaded ? ids.filter(function (id) { return idToVendor.has(id); }) : ids)
      .slice(0, MAX_SELECTED);
    updateAppliedUi();
  }

  function bindGlobalEvents() {
    document.addEventListener('click', function () {
      if (activeInstance) close(activeInstance, false);
    });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && activeInstance) {
        event.preventDefault();
        close(activeInstance, true);
        return;
      }
      if (event.key === 'Tab' && activeInstance) {
        var focusable = Array.prototype.slice.call(activeInstance.panel.querySelectorAll(
          'button:not([disabled]), input:not([disabled])'
        )).filter(function (element) {
          return !element.hidden && element.offsetParent !== null;
        });
        if (!focusable.length) return;
        var first = focusable[0];
        var last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
          event.preventDefault();
          last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault();
          first.focus();
        }
      }
    });
    document.addEventListener('biomie:vendor-context-change', function (event) {
      syncExternalSelection(event && event.detail ? event.detail : {});
    });
  }

  function init() {
    nav = window.NavContext || null;
    currentIds = readInitialSelection();
    resetWhenCompanyChanges();
    instances = Array.prototype.map.call(
      document.querySelectorAll('[data-vendor-filter]'),
      createInstance
    ).filter(Boolean);
    if (!instances.length) return;
    updateAppliedUi();
    bindGlobalEvents();
    loadVendors();
  }

  window.BIomieVendorFilter = {
    version: '3.0.0',
    getSelectedIds: function () { return currentIds.slice(); },
    getSelectedVendors: function () { return selectedVendors(currentIds); },
    open: function () {
      if (instances[0]) open(instances[0]);
    },
    close: function (restoreFocus) {
      instances.forEach(function (instance) { close(instance, restoreFocus); });
    },
    /** Aplica uma seleção programaticamente (ex: chip "Limpar" na barra de filtros ativos). */
    setSelection: setSelection,
    /** Atalho para setSelection([]) — remove o filtro de vendedor por completo. */
    clearSelection: function () { setSelection([]); },
    /** Roster completo já carregado (fonte única — o mega funil reaproveita). */
    getAllVendors: function () {
      return vendors.map(function (v) { return { id: v.id, name: v.name }; });
    },
    isLoaded: function () { return loaded; }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
  } else {
    init();
  }
}());

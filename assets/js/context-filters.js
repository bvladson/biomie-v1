/*
 * BIomie compact context filters.
 *
 * Contract for page modules:
 *   document.dispatchEvent(new CustomEvent('biomie:filter-register', {
 *     detail: {
 *       source: 'page-key',
 *       fields: [{ key, label, type, placeholder, options, multiple, persist, targetId }],
 *       values: { client: '...' }
 *     }
 *   }));
 *
 * The header persists state and emits one cancelable `biomie:filter-apply`.
 * A page that owns its refresh calls event.preventDefault() and performs one
 * request. Legacy pages fall back to their existing #applyFilters button.
 */
(function () {
  'use strict';

  if (window.BIomieContextFilters && window.BIomieContextFilters.version) return;

  var root = null;
  var backdrop = null;
  var startInput = null;
  var endInput = null;
  var slot = null;
  var contextSection = null;
  var periodSection = null;
  var errorBox = null;
  var activeTrigger = null;
  var initialized = false;
  var openState = false;
  var applySequence = 0;
  var pendingRegistrations = [];
  var chipBar = null;
  var chipBarAnchorTried = false;
  var registration = {
    source: '',
    period: true,
    fields: [],
    values: {}
  };

  var VALID_TYPES = ['text', 'search', 'select', 'chips'];
  var RESERVED_KEYS = [
    'start', 'end', 'preset',
    'vendor_ids', 'vendor_ids_csv', 'vendor_names', 'vendor_id', 'vendor_nome'
  ];

  function esc(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function pad(value) {
    return String(value).padStart(2, '0');
  }

  function formatDate(date) {
    return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
  }

  function monthRange(reference) {
    var date = reference || new Date();
    return {
      start: formatDate(new Date(date.getFullYear(), date.getMonth(), 1)),
      end: formatDate(new Date(date.getFullYear(), date.getMonth() + 1, 0)),
      preset: 'month'
    };
  }

  function presetRange(name) {
    var now = new Date();
    if (name === 'year') {
      return {
        start: formatDate(new Date(now.getFullYear(), 0, 1)),
        end: formatDate(new Date(now.getFullYear(), 11, 31)),
        preset: 'year'
      };
    }
    if (name === 'rolling12') {
      return {
        start: formatDate(new Date(now.getFullYear(), now.getMonth() - 11, 1)),
        end: formatDate(new Date(now.getFullYear(), now.getMonth() + 1, 0)),
        preset: 'rolling12'
      };
    }
    return monthRange(now);
  }

  function sameRange(left, right) {
    return !!left && !!right && left.start === right.start && left.end === right.end;
  }

  function inferPreset(start, end) {
    var value = { start: start, end: end };
    var names = ['month', 'year', 'rolling12'];
    for (var i = 0; i < names.length; i += 1) {
      if (sameRange(value, presetRange(names[i]))) return names[i];
    }
    return '';
  }

  function safeKey(value) {
    var key = String(value || '').trim();
    return /^[A-Za-z][A-Za-z0-9_-]{0,39}$/.test(key) ? key : '';
  }

  function safeTargetId(value) {
    var target = String(value || '').trim();
    return /^[A-Za-z][A-Za-z0-9_:.-]{0,79}$/.test(target) ? target : '';
  }

  function normalizedOptions(rawOptions) {
    if (!Array.isArray(rawOptions)) return [];
    return rawOptions.map(function (raw) {
      if (raw == null) return null;
      if (typeof raw === 'string' || typeof raw === 'number') {
        return { value: String(raw), label: String(raw) };
      }
      var value = String(raw.value == null ? '' : raw.value);
      var label = String(raw.label == null ? value : raw.label);
      if (!value) return null;
      return { value: value, label: label };
    }).filter(Boolean);
  }

  function normalizeField(raw) {
    raw = raw || {};
    var key = safeKey(raw.key || raw.id);
    if (!key || RESERVED_KEYS.indexOf(key) !== -1) return null;
    var type = String(raw.type || 'text').toLowerCase();
    if (type === 'quick') type = 'chips';
    if (VALID_TYPES.indexOf(type) === -1) type = 'text';
    var navKey = safeKey(raw.navKey) || key;
    var urlKey = safeKey(raw.urlKey) || navKey;
    return {
      key: key,
      label: String(raw.label || key),
      type: type,
      placeholder: String(raw.placeholder || ''),
      options: normalizedOptions(raw.options || raw.quickOptions),
      multiple: type === 'chips' && raw.multiple === true,
      persist: raw.persist !== false,
      navKey: navKey,
      urlKey: urlKey,
      targetId: safeTargetId(raw.targetId)
    };
  }

  function copyValue(value) {
    return Array.isArray(value) ? value.map(String) : String(value == null ? '' : value);
  }

  function valueIsActive(value) {
    if (Array.isArray(value)) return value.some(function (item) { return String(item).trim() !== ''; });
    return String(value == null ? '' : value).trim() !== '';
  }

  function queryValue(key) {
    try {
      return new URL(window.location.href).searchParams.get(key);
    } catch (error) {
      return null;
    }
  }

  function navValue(key) {
    var nav = window.NavContext;
    return nav && typeof nav.get === 'function' ? nav.get(key) : null;
  }

  function restoreFieldValue(field, supplied) {
    var fromUrl = queryValue(field.urlKey);
    var raw = fromUrl !== null
      ? fromUrl
      : (Object.prototype.hasOwnProperty.call(supplied, field.key)
        ? supplied[field.key]
        : navValue(field.navKey));
    if (field.multiple) {
      if (Array.isArray(raw)) return raw.map(String).filter(Boolean);
      return String(raw == null ? '' : raw).split(',').map(function (part) {
        return part.trim();
      }).filter(Boolean);
    }
    return String(raw == null ? '' : raw);
  }

  function readLocalDate(id) {
    var input = document.getElementById(id);
    if (!input || !input.value) return '';
    if (String(input.type || '').toLowerCase() === 'month') return input.value + '-01';
    return String(input.value);
  }

  function readInitialRange() {
    var nav = window.NavContext;
    if (nav && typeof nav.syncFromUrl === 'function') nav.syncFromUrl();
    var navRange = nav && typeof nav.getDateRange === 'function'
      ? nav.getDateRange()
      : { start: '', end: '', preset: '' };
    var start = queryValue('start') || queryValue('date_from') || navRange.start || readLocalDate('dateStart');
    var end = queryValue('end') || queryValue('date_to') || navRange.end || readLocalDate('dateEnd');
    if (!start || !end) return monthRange(new Date());
    return {
      start: String(start),
      end: String(end),
      preset: queryValue('preset') || navRange.preset || inferPreset(String(start), String(end))
    };
  }

  function currentRange() {
    var start = startInput ? String(startInput.value || '') : '';
    var end = endInput ? String(endInput.value || '') : '';
    return {
      start: start,
      end: end,
      preset: inferPreset(start, end)
    };
  }

  function setRange(range) {
    if (startInput) startInput.value = range.start || '';
    if (endInput) endInput.value = range.end || '';
    syncPresetButtons(range.preset || inferPreset(range.start, range.end));
  }

  function syncPresetButtons(preset) {
    if (!root) return;
    root.querySelectorAll('[data-context-filter-preset]').forEach(function (button) {
      var active = button.getAttribute('data-context-filter-preset') === preset;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
  }

  function createLabel(field) {
    var wrapper = document.createElement('label');
    wrapper.className = 'biomie-context-filter-field';
    wrapper.setAttribute('data-context-filter-field', field.key);
    var caption = document.createElement('span');
    caption.textContent = field.label;
    wrapper.appendChild(caption);
    return wrapper;
  }

  function fieldValueFromDom(field) {
    if (!slot) return field.multiple ? [] : '';
    if (field.type === 'chips') {
      var pressed = slot.querySelectorAll(
        '[data-context-filter-key="' + field.key + '"][aria-pressed="true"]'
      );
      var values = Array.prototype.map.call(pressed, function (button) {
        return String(button.getAttribute('data-context-filter-value') || '');
      }).filter(Boolean);
      return field.multiple ? values : (values[0] || '');
    }
    var input = slot.querySelector('[data-context-filter-key="' + field.key + '"]');
    return input ? String(input.value || '') : '';
  }

  function collectExtras() {
    var values = {};
    registration.fields.forEach(function (field) {
      values[field.key] = fieldValueFromDom(field);
    });
    return values;
  }

  function combinedValues() {
    var range = currentRange();
    var values = collectExtras();
    var vendorIds = window.BIomieVendorFilter
      && typeof window.BIomieVendorFilter.getSelectedIds === 'function'
      ? window.BIomieVendorFilter.getSelectedIds()
      : String(navValue('vendor_ids') || navValue('vendor_id') || '')
        .split(',')
        .map(function (id) { return id.trim(); })
        .filter(Boolean);
    var vendorNames = window.BIomieVendorFilter
      && typeof window.BIomieVendorFilter.getSelectedVendors === 'function'
      ? window.BIomieVendorFilter.getSelectedVendors().map(function (vendor) {
        return String(vendor && vendor.name || '');
      }).filter(Boolean)
      : [];
    values.start = range.start;
    values.end = range.end;
    values.preset = range.preset;
    values.vendor_ids = vendorIds;
    values.vendor_ids_csv = vendorIds.join(',');
    values.vendor_names = vendorNames;
    values.vendor_id = vendorIds.length === 1 ? vendorIds[0] : '';
    values.vendor_nome = vendorIds.length === 1
      ? String(vendorNames[0] || navValue('vendor_nome') || '')
      : '';
    return values;
  }

  function eventDetail(reason) {
    var range = currentRange();
    return {
      source: 'header',
      pageSource: registration.source || String((document.body && document.body.dataset.page) || ''),
      reason: reason || 'change',
      range: range,
      values: combinedValues()
    };
  }

  function emitChange(reason) {
    syncPresetButtons(currentRange().preset);
    updateCount();
    renderChipsBar();
    document.dispatchEvent(new CustomEvent('biomie:filter-change', {
      bubbles: true,
      detail: eventDetail(reason || 'change')
    }));
  }

  function renderFields() {
    if (!slot || !contextSection) return;
    slot.textContent = '';
    registration.fields.forEach(function (field) {
      var wrapper = createLabel(field);
      var value = registration.values[field.key];
      if (field.type === 'chips') {
        var chips = document.createElement('div');
        chips.className = 'biomie-context-filter-chips';
        chips.setAttribute('role', 'group');
        chips.setAttribute('aria-label', field.label);
        field.options.forEach(function (option) {
          var button = document.createElement('button');
          button.type = 'button';
          button.className = 'biomie-context-filter-chip';
          button.textContent = option.label;
          button.setAttribute('data-context-filter-key', field.key);
          button.setAttribute('data-context-filter-value', option.value);
          var selected = Array.isArray(value)
            ? value.indexOf(option.value) !== -1
            : String(value || '') === option.value;
          button.setAttribute('aria-pressed', selected ? 'true' : 'false');
          button.addEventListener('click', function () {
            var next = button.getAttribute('aria-pressed') !== 'true';
            if (!field.multiple && next) {
              chips.querySelectorAll('[aria-pressed="true"]').forEach(function (other) {
                other.setAttribute('aria-pressed', 'false');
              });
            }
            button.setAttribute('aria-pressed', next ? 'true' : 'false');
            registration.values[field.key] = fieldValueFromDom(field);
            emitChange('context');
          });
          chips.appendChild(button);
        });
        wrapper.appendChild(chips);
      } else if (field.type === 'select') {
        var select = document.createElement('select');
        select.setAttribute('data-context-filter-key', field.key);
        select.setAttribute('aria-label', field.label);
        var emptyOption = document.createElement('option');
        emptyOption.value = '';
        emptyOption.textContent = field.placeholder || 'Todos';
        select.appendChild(emptyOption);
        field.options.forEach(function (option) {
          var opt = document.createElement('option');
          opt.value = option.value;
          opt.textContent = option.label;
          select.appendChild(opt);
        });
        select.value = String(value || '');
        select.addEventListener('change', function () {
          registration.values[field.key] = select.value;
          emitChange('context');
        });
        wrapper.appendChild(select);
      } else {
        var input = document.createElement('input');
        input.type = field.type === 'search' ? 'search' : 'text';
        input.value = String(value || '');
        input.placeholder = field.placeholder;
        input.autocomplete = 'off';
        input.setAttribute('data-context-filter-key', field.key);
        input.setAttribute('aria-label', field.label);
        input.addEventListener('input', function () {
          registration.values[field.key] = input.value;
          emitChange('context');
        });
        wrapper.appendChild(input);
      }
      slot.appendChild(wrapper);
    });
    contextSection.hidden = registration.fields.length === 0;
    if (periodSection) periodSection.hidden = registration.period === false;
    updateCount();
    renderChipsBar();
  }

  function register(config) {
    config = config || {};
    if (!initialized) {
      pendingRegistrations.push(config);
      return;
    }
    var fields = (Array.isArray(config.fields) ? config.fields : [])
      .map(normalizeField)
      .filter(Boolean);
    var supplied = config.values && typeof config.values === 'object' ? config.values : {};
    registration = {
      source: String(config.source || (document.body && document.body.dataset.page) || ''),
      period: config.period !== false,
      fields: fields,
      values: {}
    };
    fields.forEach(function (field) {
      registration.values[field.key] = restoreFieldValue(field, supplied);
    });
    renderFields();
    document.dispatchEvent(new CustomEvent('biomie:filter-ready', {
      bubbles: true,
      detail: eventDetail('register')
    }));
  }

  function showError(message) {
    if (!errorBox) return;
    errorBox.textContent = String(message || '');
    errorBox.hidden = !message;
  }

  function validateRange() {
    var range = currentRange();
    if (registration.period === false) return true;
    if (!range.start || !range.end) {
      showError('Informe as datas de inicio e fim.');
      return false;
    }
    if (range.start > range.end) {
      showError('A data inicial precisa ser anterior a data final.');
      return false;
    }
    showError('');
    return true;
  }

  function serializeValue(value) {
    if (Array.isArray(value)) return value.map(String).filter(Boolean).join(',');
    return String(value == null ? '' : value).trim();
  }

  function persistState() {
    var range = currentRange();
    var extras = collectExtras();
    var nav = window.NavContext;
    if (nav && typeof nav.setDateRange === 'function' && registration.period !== false) {
      nav.setDateRange(range.start, range.end, range.preset);
    }
    if (nav && typeof nav.merge === 'function') {
      var patch = {};
      registration.fields.forEach(function (field) {
        if (!field.persist) return;
        var serialized = serializeValue(extras[field.key]);
        patch[field.navKey] = serialized || null;
      });
      nav.merge(patch);
    }
    try {
      var url = new URL(window.location.href);
      if (registration.period !== false) {
        if (range.start) url.searchParams.set('start', range.start);
        else url.searchParams.delete('start');
        if (range.end) url.searchParams.set('end', range.end);
        else url.searchParams.delete('end');
        if (range.preset) url.searchParams.set('preset', range.preset);
        else url.searchParams.delete('preset');
        if (url.searchParams.has('date_from')) url.searchParams.set('date_from', range.start);
        if (url.searchParams.has('date_to')) url.searchParams.set('date_to', range.end);
      }
      registration.fields.forEach(function (field) {
        if (!field.persist) return;
        var serialized = serializeValue(extras[field.key]);
        if (serialized) url.searchParams.set(field.urlKey, serialized);
        else url.searchParams.delete(field.urlKey);
      });
      window.history.replaceState(
        window.history.state,
        '',
        url.pathname + (url.searchParams.toString() ? '?' + url.searchParams.toString() : '') + url.hash
      );
    } catch (error) {
      // History/storage can be unavailable in embedded or privacy-restricted views.
    }
  }

  function setLegacyDate(input, value) {
    if (!input) return;
    var type = String(input.type || '').toLowerCase();
    if (type === 'month') {
      input.value = String(value || '').slice(0, 7);
      return;
    }
    input.value = value || '';
  }

  function syncLegacyInputs() {
    var range = currentRange();
    if (registration.period !== false) {
      ['dateStart', 'fisDateStart'].forEach(function (id) {
        setLegacyDate(document.getElementById(id), range.start);
      });
      ['dateEnd', 'fisDateEnd'].forEach(function (id) {
        setLegacyDate(document.getElementById(id), range.end);
      });
    }
    var extras = collectExtras();
    registration.fields.forEach(function (field) {
      if (!field.targetId) return;
      var target = document.getElementById(field.targetId);
      if (!target) return;
      var value = extras[field.key];
      if (target.type === 'checkbox') {
        target.checked = valueIsActive(value);
      } else {
        target.value = serializeValue(value);
      }
    });
  }

  // ═══ MEGA FUNIL — vendedores + operação dentro do MESMO painel ══════════════
  //
  // Unifica num só lugar o que antes vivia espalhado: vendedor (dropdown do
  // topo), operação/canal (barra separada) e período/campos da página. O estado
  // é RASCUNHO enquanto o painel está aberto — nada é aplicado até "Aplicar
  // filtros" —, e a fonte de verdade continua sendo o módulo dono de cada eixo
  // (BIomieVendorFilter para vendedores, a preferência comercial para canal),
  // nunca uma cópia local. Assim o mega funil e o seletor do topo jamais divergem.
  //
  // Custo: a lista de vendedores é REAPROVEITADA do vendor-filter (já carregada
  // na página). O escopo de canal é buscado sob demanda, só na primeira vez que
  // o painel abre — quem nunca abrir não paga nada.

  var mfSellerDraft = null;      // Set de ids (null = ainda não inicializado)
  var mfChannelDraft = '';       // '' = todas · 'varejo' · 'licitacao'
  var mfChannelScope = null;     // { allowed:[], businessScope:'', isMixed:bool }
  var mfChannelPromise = null;
  var mfSellersSection = null;
  var mfSellersList = null;
  var mfSellersSearch = null;
  var mfSellersBadge = null;
  var mfSellersSummary = null;
  var mfChannelSection = null;
  var mfChannelChips = null;

  function vendorApi() {
    return (window.BIomieVendorFilter && typeof window.BIomieVendorFilter.getAllVendors === 'function')
      ? window.BIomieVendorFilter
      : null;
  }

  function mfCurrentSellerIds() {
    var api = vendorApi();
    return api && typeof api.getSelectedIds === 'function' ? api.getSelectedIds() : [];
  }

  function mfSyncDraftFromLive() {
    mfSellerDraft = new Set(mfCurrentSellerIds());
    mfChannelDraft = currentChannelInfo();
  }

  function mfNormalize(value) {
    return String(value == null ? '' : value)
      .normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
  }

  /** Máximo renderizado de uma vez — acima disso, a busca refina (evita DOM gigante). */
  var MF_RENDER_CAP = 150;

  function mfOptionHtml(vendor, selected) {
    return '<label class="biomie-megafunnel__option' + (selected ? ' is-selected' : '') + '">'
      + '<input type="checkbox" data-megafunnel-seller="' + esc(vendor.id) + '"'
      + (selected ? ' checked' : '') + ' />'
      + '<span>' + esc(vendor.name) + '</span></label>';
  }

  function mfRenderSellers() {
    if (!mfSellersSection || !mfSellersList) return;
    var api = vendorApi();
    if (!api) { mfSellersSection.hidden = true; return; }

    var all = api.getAllVendors();
    if (!all.length) {
      // Ainda carregando (ou usuário sem vendedores visíveis): não mostra a seção
      // vazia — o evento biomie:vendor-list-ready re-renderiza quando chegar.
      mfSellersSection.hidden = !api.isLoaded || !api.isLoaded();
      if (!mfSellersSection.hidden) {
        mfSellersList.innerHTML = '<p class="biomie-megafunnel__empty">Nenhum vendedor disponível.</p>';
      }
      return;
    }
    mfSellersSection.hidden = false;
    if (mfSellerDraft === null) mfSyncDraftFromLive();

    var term = mfNormalize(mfSellersSearch ? mfSellersSearch.value : '');
    var filtered = all.filter(function (v) {
      return !term || mfNormalize(v.name).indexOf(term) !== -1 || mfNormalize(v.id).indexOf(term) !== -1;
    });

    if (!filtered.length) {
      mfSellersList.innerHTML = '<p class="biomie-megafunnel__empty">Nenhum vendedor encontrado.</p>';
      mfUpdateSellerBadge(all.length, 0);
      return;
    }

    // SELECIONADOS PRIMEIRO: com 2+ marcados, eles ficam visíveis sem rolar a
    // lista — é o que se quer conferir ao montar um recorte de vários vendedores.
    var chosen = [];
    var rest = [];
    filtered.forEach(function (v) {
      (mfSellerDraft.has(v.id) ? chosen : rest).push(v);
    });

    var html = '';
    chosen.forEach(function (v) { html += mfOptionHtml(v, true); });
    if (chosen.length && rest.length) html += '<div class="biomie-megafunnel__divider"></div>';

    var shown = rest.slice(0, Math.max(0, MF_RENDER_CAP - chosen.length));
    shown.forEach(function (v) { html += mfOptionHtml(v, false); });
    var hidden = rest.length - shown.length;
    if (hidden > 0) {
      html += '<p class="biomie-megafunnel__empty">+' + hidden
        + ' vendedores. Use a busca para refinar.</p>';
    }

    mfSellersList.innerHTML = html;
    mfUpdateSellerBadge(all.length, filtered.length);
  }

  function mfUpdateSellerBadge(total, matching) {
    var count = mfSellerDraft ? mfSellerDraft.size : 0;
    if (mfSellersBadge) {
      mfSellersBadge.textContent = String(count);
      mfSellersBadge.hidden = count === 0;
    }
    if (!mfSellersSummary) return;
    if (total === undefined) {
      var api = vendorApi();
      total = api ? api.getAllVendors().length : 0;
    }
    var parts = [];
    if (count > 0) {
      parts.push(count + ' de ' + total + ' selecionado' + (count === 1 ? '' : 's'));
    } else {
      parts.push('Nenhum selecionado — mostrando todos os ' + total);
    }
    if (matching !== undefined && matching !== total) {
      parts.push(matching + ' na busca');
    }
    mfSellersSummary.textContent = parts.join(' · ');
  }

  /** Escopo de canal — buscado uma única vez, sob demanda (na 1ª abertura). */
  function mfLoadChannelScope() {
    if (mfChannelPromise) return mfChannelPromise;
    // Se o componente de canal já está na página, reaproveita o que ele sabe.
    if (window.BIomieCommercialChannel && typeof window.BIomieCommercialChannel.isMixed === 'function'
        && window.BIomieCommercialChannel.isMixed()) {
      mfChannelScope = { allowed: ['varejo', 'licitacao'], isMixed: true };
      mfChannelPromise = Promise.resolve(mfChannelScope);
      return mfChannelPromise;
    }
    mfChannelPromise = fetch('api/commercial_filter_pref.php', {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'fetch' }
    }).then(function (r) { return r.ok ? r.json() : null; }).then(function (data) {
      if (!data || data.ok === false) { mfChannelScope = { allowed: [], isMixed: false }; return mfChannelScope; }
      var cs = data.channel_scope || {};
      var allowed = Array.isArray(cs.allowed) ? cs.allowed : [];
      mfChannelScope = {
        allowed: allowed,
        businessScope: String(data.business_scope || ''),
        isMixed: String(data.business_scope || '') === 'mixed' && allowed.length >= 2
      };
      return mfChannelScope;
    }).catch(function () {
      mfChannelScope = { allowed: [], isMixed: false };
      return mfChannelScope;
    });
    return mfChannelPromise;
  }

  function mfRenderChannel() {
    if (!mfChannelSection || !mfChannelChips) return;
    if (!mfChannelScope || !mfChannelScope.isMixed) { mfChannelSection.hidden = true; return; }
    mfChannelSection.hidden = false;

    var options = [{ v: '', label: 'Todas as operações' }];
    if (mfChannelScope.allowed.indexOf('varejo') !== -1) options.push({ v: 'varejo', label: 'Varejo' });
    if (mfChannelScope.allowed.indexOf('licitacao') !== -1) options.push({ v: 'licitacao', label: 'Licitação' });

    mfChannelChips.innerHTML = options.map(function (opt) {
      var active = opt.v === mfChannelDraft;
      return '<button type="button" class="biomie-megafunnel__chip'
        + (active ? ' is-active' : '') + '" data-megafunnel-channel-value="' + esc(opt.v) + '"'
        + ' aria-pressed="' + (active ? 'true' : 'false') + '">' + esc(opt.label) + '</button>';
    }).join('');

    Array.prototype.forEach.call(
      mfChannelChips.querySelectorAll('[data-megafunnel-channel-value]'),
      function (btn) {
        btn.addEventListener('click', function () {
          mfChannelDraft = String(btn.getAttribute('data-megafunnel-channel-value') || '');
          mfRenderChannel();
        });
      }
    );
  }

  function mfBind() {
    if (mfSellersSearch) {
      mfSellersSearch.addEventListener('input', function () { mfRenderSellers(); });
    }
    // DELEGAÇÃO: um único listener no container, ligado uma vez. Antes cada
    // re-render (a cada tecla digitada na busca) recriava um listener por
    // vendedor — dezenas de listeners descartados por segundo.
    if (mfSellersList) {
      mfSellersList.addEventListener('change', function (event) {
        var box = event.target;
        if (!box || !box.hasAttribute || !box.hasAttribute('data-megafunnel-seller')) return;
        var id = box.getAttribute('data-megafunnel-seller');
        if (!mfSellerDraft) mfSellerDraft = new Set();
        if (box.checked) mfSellerDraft.add(id);
        else mfSellerDraft.delete(id);
        // Reordenar a lista a cada clique tiraria a linha de baixo do cursor;
        // só marca visualmente e atualiza a contagem. A reordenação acontece na
        // próxima abertura/busca.
        var option = box.closest ? box.closest('.biomie-megafunnel__option') : null;
        if (option) option.classList.toggle('is-selected', box.checked);
        mfUpdateSellerBadge();
      });
    }
    var allBtn = root.querySelector('[data-megafunnel-sellers-all]');
    var noneBtn = root.querySelector('[data-megafunnel-sellers-none]');
    if (allBtn) {
      allBtn.addEventListener('click', function () {
        var api = vendorApi();
        if (!api) return;
        // "Selecionar todos" marca os VISÍVEIS na busca atual (comportamento
        // esperado quando se está filtrando a lista).
        var term = mfNormalize(mfSellersSearch ? mfSellersSearch.value : '');
        api.getAllVendors().forEach(function (v) {
          if (!term || mfNormalize(v.name).indexOf(term) !== -1) mfSellerDraft.add(v.id);
        });
        mfRenderSellers();
      });
    }
    if (noneBtn) {
      noneBtn.addEventListener('click', function () {
        if (mfSellerDraft) mfSellerDraft.clear();
        mfRenderSellers();
      });
    }
    // A lista de vendedores chega de forma assíncrona no primeiro carregamento.
    document.addEventListener('biomie:vendor-list-ready', function () {
      if (mfSellerDraft === null) mfSyncDraftFromLive();
      mfRenderSellers();
    });
  }

  /** Chamado ao abrir o painel: rascunho parte SEMPRE do estado real vigente. */
  function mfOnOpen() {
    mfSyncDraftFromLive();
    mfRenderSellers();
    mfLoadChannelScope().then(function () { mfRenderChannel(); });
  }

  /**
   * Aplica os eixos do mega funil. Devolve o que mudou para o apply() decidir
   * se precisa recarregar (páginas que leem vendor_ids da URL no load).
   */
  function mfCommit() {
    var changed = { sellers: false, channel: false };
    var api = vendorApi();

    if (api && mfSellerDraft !== null && typeof api.setSelection === 'function') {
      var next = Array.from(mfSellerDraft);
      var live = mfCurrentSellerIds();
      var same = next.length === live.length && next.slice().sort().join(',') === live.slice().sort().join(',');
      if (!same) {
        // SILENCIOSO: atualiza URL/NavContext/UI sem disparar evento próprio. O
        // biomie:filter-apply logo abaixo já carrega os vendor_ids novos (via
        // combinedValues), então a página faz UMA leitura só, não duas.
        api.setSelection(next, { silent: true });
        changed.sellers = true;
      }
    }

    if (mfChannelScope && mfChannelScope.isMixed && mfChannelDraft !== currentChannelInfo()) {
      changed.channel = true;
      try {
        var url = new URL(window.location.href);
        if (mfChannelDraft) url.searchParams.set('channel', mfChannelDraft);
        else url.searchParams.delete('channel');
        window.history.replaceState(window.history.state, '', url);
      } catch (error) { /* History pode estar bloqueada */ }
      // Persiste a preferência de canal no servidor (mesma rota do toggle).
      var meta = document.querySelector('meta[name="biomie-csrf"]');
      var token = meta ? String(meta.getAttribute('content') || '') : '';
      if (token) {
        fetch('api/commercial_filter_pref.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'fetch',
            'X-CSRF-Token': token
          },
          body: JSON.stringify({
            action: 'save',
            channels: mfChannelDraft ? [mfChannelDraft] : [],
            csrf_token: token
          })
        }).catch(function () { /* best-effort: o recorte já foi aplicado na URL */ });
      }
    }

    return changed;
  }

  // ═══ Barra de Filtros Ativos — Filtros Inteligentes ═════════════════════════
  //
  // Chips visíveis (não escondidos atrás do ícone "Mais filtros") para cada
  // filtro em vigor nesta página: período (quando != mês atual), cada campo
  // registrado pela página (cliente, produto, situação...), vendedor(es) e a
  // Operação (varejo/licitação, quando o Filtro Comercial Inteligente restringe
  // o canal). Cada chip remove SÓ aquele filtro e reaplica — "Limpar tudo" zera
  // tudo de uma vez. Fonte de verdade para vendedor/canal são os próprios
  // módulos (BIomieVendorFilter / URL ?channel=), nunca uma cópia local — assim
  // o chip nunca diverge do que está realmente filtrando os dados.
  //
  // Custo de render é puramente DOM local (sem rede): seguro chamar a cada
  // tecla digitada nos campos de texto, sem impacto de performance.

  function fieldOptionLabel(field, value) {
    var found = null;
    (field.options || []).some(function (option) {
      if (option.value === value) { found = option; return true; }
      return false;
    });
    return found ? found.label : String(value == null ? '' : value);
  }

  function truncate(text, max) {
    text = String(text == null ? '' : text);
    return text.length > max ? text.slice(0, max) + '…' : text;
  }

  function periodPresetLabel(preset) {
    if (preset === 'year') return 'Ano atual';
    if (preset === 'rolling12') return 'Últimos 12 meses';
    return '';
  }

  function formatChipDate(iso) {
    var s = String(iso || '');
    if (s.length < 10) return s;
    var parts = s.slice(0, 10).split('-');
    return parts.length === 3 ? (parts[2] + '/' + parts[1]) : s;
  }

  function periodChip() {
    if (registration.period === false) return null;
    var range = currentRange();
    if (!range.start || !range.end) return null;
    if (sameRange(range, monthRange(new Date()))) return null; // default: sem ruído
    var label = periodPresetLabel(range.preset)
      || (formatChipDate(range.start) + '–' + formatChipDate(range.end));
    return {
      label: 'Período: ' + label,
      remove: function () {
        setRange(monthRange(new Date()));
        emitChange('chip-remove');
        apply('chip-remove');
      }
    };
  }

  function fieldChip(field) {
    var value = registration.values[field.key];
    if (!valueIsActive(value)) return null;
    var label;
    if (field.type === 'chips') {
      if (Array.isArray(value)) {
        label = value.length === 1
          ? fieldOptionLabel(field, value[0])
          : (value.length + ' ' + field.label.toLowerCase());
      } else {
        label = fieldOptionLabel(field, value);
      }
    } else if (field.type === 'select') {
      label = fieldOptionLabel(field, value);
    } else {
      label = field.label + ': ' + truncate(value, 22);
    }
    return {
      label: label,
      remove: function () {
        registration.values[field.key] = field.multiple ? [] : '';
        renderFields();
        emitChange('chip-remove');
        apply('chip-remove');
      }
    };
  }

  /** Canais só têm sentido além de 'todas' quando o toggle de Operação restringiu. */
  function currentChannelInfo() {
    try {
      var raw = String(new URL(window.location.href).searchParams.get('channel') || '')
        .trim().toLowerCase();
      return (raw === 'varejo' || raw === 'licitacao') ? raw : '';
    } catch (error) {
      return '';
    }
  }

  function vendorChip() {
    if (!window.BIomieVendorFilter || typeof window.BIomieVendorFilter.getSelectedIds !== 'function') {
      return null;
    }
    var ids = window.BIomieVendorFilter.getSelectedIds();
    if (!ids.length) return null;
    var names = typeof window.BIomieVendorFilter.getSelectedVendors === 'function'
      ? window.BIomieVendorFilter.getSelectedVendors().map(function (v) { return v && v.name; }).filter(Boolean)
      : [];
    var label = ids.length === 1
      ? ('Vendedor: ' + truncate(names[0] || ids[0], 24))
      : (ids.length + ' vendedores selecionados');
    return {
      label: label,
      remove: function () {
        if (typeof window.BIomieVendorFilter.clearSelection === 'function') {
          window.BIomieVendorFilter.clearSelection();
        }
      }
    };
  }

  function channelChip() {
    var channel = currentChannelInfo();
    if (!channel) return null;
    var label = 'Operação: ' + (channel === 'varejo' ? 'Varejo' : 'Licitação');
    return {
      label: label,
      remove: function () {
        if (window.BDashboard && typeof window.BDashboard.applyChannel === 'function') {
          window.BDashboard.applyChannel('');
          return;
        }
        if (window.BIomieCommercialChannel && typeof window.BIomieCommercialChannel.setChannel === 'function') {
          window.BIomieCommercialChannel.setChannel('');
        }
      }
    };
  }

  function computeChips() {
    var chips = [];
    var period = periodChip();
    if (period) chips.push(period);
    registration.fields.forEach(function (field) {
      var chip = fieldChip(field);
      if (chip) chips.push(chip);
    });
    var vendor = vendorChip();
    if (vendor) chips.push(vendor);
    var channel = channelChip();
    if (channel) chips.push(channel);
    return chips;
  }

  /**
   * Cria (uma vez) e posiciona a barra logo após o cabeçalho da página. Tenta
   * âncoras conhecidas em ordem; se nenhuma existir, desiste silenciosamente —
   * uma página sem lugar óbvio simplesmente não ganha a barra, nunca quebra.
   */
  function ensureChipBar() {
    if (chipBar) return chipBar;
    if (chipBarAnchorTried) return null;
    chipBarAnchorTried = true;

    var bar = document.createElement('div');
    bar.className = 'biomie-active-filters';
    bar.setAttribute('data-context-filters-chips', '');
    bar.hidden = true;

    var dashboardControls = document.getElementById('dashboardControls');
    var channelBar = document.getElementById('dashboardCommercialFilter');
    var header = document.querySelector('.biomie-page-header') || document.querySelector('.page-head');
    var main = document.querySelector('main');

    if (channelBar && channelBar.parentNode) {
      channelBar.parentNode.insertBefore(bar, channelBar.nextSibling);
    } else if (dashboardControls && dashboardControls.parentNode) {
      dashboardControls.parentNode.insertBefore(bar, dashboardControls.nextSibling);
    } else if (header && header.parentNode) {
      header.parentNode.insertBefore(bar, header.nextSibling);
    } else if (main) {
      main.insertBefore(bar, main.firstChild);
    } else {
      return null;
    }

    chipBar = bar;
    return chipBar;
  }

  function renderChipsBar() {
    var bar = ensureChipBar();
    if (!bar) return;

    var chips = computeChips();
    if (!chips.length) {
      bar.hidden = true;
      bar.innerHTML = '';
      return;
    }

    bar.hidden = false;
    bar.innerHTML = '<span class="biomie-active-filters__label">'
      + '<span class="material-symbols-rounded" aria-hidden="true">filter_alt</span>Filtros ativos</span>'
      + chips.map(function (chip, index) {
          return '<span class="biomie-active-filters__chip">' + esc(chip.label)
            + '<button type="button" class="biomie-active-filters__remove" '
            + 'data-active-filter-remove="' + index + '" aria-label="Remover ' + esc(chip.label) + '">×</button>'
            + '</span>';
        }).join('')
      + '<button type="button" class="biomie-active-filters__clear" data-active-filters-clear-all>Limpar tudo</button>';

    Array.prototype.forEach.call(bar.querySelectorAll('[data-active-filter-remove]'), function (btn) {
      btn.addEventListener('click', function () {
        var idx = Number(btn.getAttribute('data-active-filter-remove'));
        if (chips[idx] && typeof chips[idx].remove === 'function') chips[idx].remove();
      });
    });
    var clearAllBtn = bar.querySelector('[data-active-filters-clear-all]');
    if (clearAllBtn) {
      clearAllBtn.addEventListener('click', function () {
        if (window.BIomieVendorFilter && typeof window.BIomieVendorFilter.clearSelection === 'function') {
          window.BIomieVendorFilter.clearSelection();
        }
        var channel = channelChip();
        if (channel) channel.remove();
        clearAndApply();
      });
    }
  }

  function activeCount() {
    var count = 0;
    var range = currentRange();
    if (registration.period !== false && range.start && range.end) count += 1;
    var extras = collectExtras();
    registration.fields.forEach(function (field) {
      if (valueIsActive(extras[field.key])) count += 1;
    });
    return count;
  }

  function updateCount() {
    var count = activeCount();
    document.querySelectorAll('[data-context-filters-count]').forEach(function (badge) {
      badge.textContent = String(count);
      badge.hidden = count === 0;
    });
    document.querySelectorAll('[data-context-filters-open]').forEach(function (trigger) {
      trigger.setAttribute(
        'aria-label',
        count > 0 ? ('Mais filtros, ' + count + ' ativo' + (count === 1 ? '' : 's')) : 'Mais filtros'
      );
    });
  }

  function isMobile() {
    return window.matchMedia && window.matchMedia('(max-width: 767px)').matches;
  }

  function positionPopover() {
    if (!root || !activeTrigger || isMobile()) {
      if (root) {
        root.style.top = '';
        root.style.left = '';
      }
      return;
    }
    var rect = activeTrigger.getBoundingClientRect();
    var width = root.offsetWidth || 390;
    var height = root.offsetHeight || 500;
    var left = Math.max(12, Math.min(window.innerWidth - width - 12, rect.right - width));
    var top = rect.bottom + 8;
    if (top + height > window.innerHeight - 12) {
      top = Math.max(12, rect.top - height - 8);
    }
    root.style.left = Math.round(left) + 'px';
    root.style.top = Math.round(top) + 'px';
  }

  function focusableElements() {
    if (!root) return [];
    return Array.prototype.slice.call(
      root.querySelectorAll('button:not([disabled]), input:not([disabled]), select:not([disabled])')
    ).filter(function (element) {
      return !element.hidden && element.offsetParent !== null;
    });
  }

  function open(trigger) {
    if (!root) return;
    if (window.BIomieVendorFilter && typeof window.BIomieVendorFilter.close === 'function') {
      window.BIomieVendorFilter.close(false);
    }
    activeTrigger = trigger || document.querySelector('[data-context-filters-open]');
    openState = true;
    root.hidden = false;
    if (backdrop) backdrop.hidden = false;
    root.setAttribute('aria-modal', isMobile() ? 'true' : 'false');
    document.querySelectorAll('[data-context-filters-open]').forEach(function (button) {
      button.setAttribute('aria-expanded', button === activeTrigger ? 'true' : 'false');
    });
    if (isMobile()) document.body.classList.add('biomie-context-filters-open');
    mfOnOpen();
    positionPopover();
    window.requestAnimationFrame(function () {
      var focusable = focusableElements();
      if (focusable.length) focusable[0].focus();
    });
  }

  function close(restoreFocus) {
    if (!root || !openState) return;
    openState = false;
    root.hidden = true;
    if (backdrop) backdrop.hidden = true;
    document.body.classList.remove('biomie-context-filters-open');
    document.querySelectorAll('[data-context-filters-open]').forEach(function (button) {
      button.setAttribute('aria-expanded', 'false');
    });
    if (restoreFocus !== false && activeTrigger && typeof activeTrigger.focus === 'function') {
      activeTrigger.focus();
    }
  }

  function apply(reason) {
    if (!validateRange()) return false;
    registration.values = collectExtras();
    // MEGA FUNIL: vendedores/canal são commitados ANTES do dispatch, para que o
    // evento único já carregue o estado novo (combinedValues lê do módulo dono).
    var megaChanged = mfCommit();
    persistState();
    syncLegacyInputs();
    updateCount();
    renderChipsBar();
    applySequence += 1;
    var detail = eventDetail(reason || 'apply');
    detail.applyId = 'header-' + Date.now() + '-' + applySequence;
    var applyEvent = new CustomEvent('biomie:filter-apply', {
      bubbles: true,
      cancelable: true,
      detail: detail
    });
    var useLegacyFallback = document.dispatchEvent(applyEvent);
    close(false);
    if (useLegacyFallback) {
      // Ninguém assumiu o refresh. Se vendedor/canal mudaram, um clique no botão
      // local NÃO basta: fora do Dashboard essas páginas leem vendor_ids/channel
      // da URL no carregamento. Recarregar é a única forma correta de refletir.
      if (megaChanged.sellers || megaChanged.channel) {
        window.location.reload();
        return true;
      }
      var localApply = document.getElementById('applyFilters')
        || document.getElementById('fisApply');
      if (localApply && localApply !== document.activeElement && !localApply.disabled) {
        localApply.click();
      } else if (
        window.BReconciliation
        && typeof window.BReconciliation.loadFiltered === 'function'
      ) {
        // A conciliacao reage a cada campo isolado; chamamos a leitura uma vez
        // depois de sincronizar o par para evitar duas requisicoes concorrentes.
        window.BReconciliation.loadFiltered(1);
      }
    }
    return true;
  }

  function clearAndApply() {
    setRange(monthRange(new Date()));
    registration.fields.forEach(function (field) {
      registration.values[field.key] = field.multiple ? [] : '';
    });
    // MEGA FUNIL: "Limpar" zera TODOS os eixos, inclusive vendedores e operação.
    if (mfSellerDraft) mfSellerDraft.clear();
    else mfSellerDraft = new Set();
    mfChannelDraft = '';
    mfRenderSellers();
    mfRenderChannel();
    renderFields();
    emitChange('clear');
    apply('clear');
  }

  function handleKeydown(event) {
    if (!openState) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      close(true);
      return;
    }
    if (event.key !== 'Tab') return;
    var focusable = focusableElements();
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

  function bind() {
    document.querySelectorAll('[data-context-filters-open]').forEach(function (trigger) {
      trigger.addEventListener('click', function () {
        if (openState && trigger === activeTrigger) close(true);
        else open(trigger);
      });
    });
    var closeButton = root.querySelector('[data-context-filters-close]');
    var applyButton = root.querySelector('[data-context-filters-apply]');
    var clearButton = root.querySelector('[data-context-filters-clear]');
    if (closeButton) closeButton.addEventListener('click', function () { close(true); });
    if (applyButton) applyButton.addEventListener('click', function () { apply('apply'); });
    if (clearButton) clearButton.addEventListener('click', clearAndApply);
    mfBind();
    if (backdrop) backdrop.addEventListener('click', function () { close(true); });
    root.querySelectorAll('[data-context-filter-preset]').forEach(function (button) {
      button.addEventListener('click', function () {
        setRange(presetRange(button.getAttribute('data-context-filter-preset')));
        emitChange('preset');
      });
    });
    if (startInput) startInput.addEventListener('change', function () {
      syncPresetButtons(currentRange().preset);
      emitChange('period');
    });
    if (endInput) endInput.addEventListener('change', function () {
      syncPresetButtons(currentRange().preset);
      emitChange('period');
    });
    document.addEventListener('keydown', handleKeydown);
    window.addEventListener('resize', function () {
      if (!openState) return;
      root.setAttribute('aria-modal', isMobile() ? 'true' : 'false');
      if (isMobile()) document.body.classList.add('biomie-context-filters-open');
      else document.body.classList.remove('biomie-context-filters-open');
      positionPopover();
    });
  }

  function init() {
    if (initialized) return;
    root = document.querySelector('[data-context-filters]');
    if (!root) return;
    backdrop = document.querySelector('[data-context-filters-backdrop]');
    startInput = root.querySelector('[data-context-filter-start]');
    endInput = root.querySelector('[data-context-filter-end]');
    slot = root.querySelector('[data-context-filter-slot]');
    contextSection = root.querySelector('[data-context-filter-context]');
    periodSection = root.querySelector('[data-context-filters-period]');
    errorBox = root.querySelector('[data-context-filters-error]');
    // MEGA FUNIL — seções de vendedores e operação dentro do mesmo painel.
    mfSellersSection = root.querySelector('[data-megafunnel-sellers]');
    mfSellersList = root.querySelector('[data-megafunnel-sellers-list]');
    mfSellersSearch = root.querySelector('[data-megafunnel-sellers-search]');
    mfSellersBadge = root.querySelector('[data-megafunnel-sellers-badge]');
    mfSellersSummary = root.querySelector('[data-megafunnel-sellers-summary]');
    mfChannelSection = root.querySelector('[data-megafunnel-channel]');
    mfChannelChips = root.querySelector('[data-megafunnel-channel-chips]');
    initialized = true;
    setRange(readInitialRange());
    bind();
    updateCount();
    pendingRegistrations.forEach(register);
    pendingRegistrations = [];
    var queued = Array.isArray(window.BIomieContextFilterQueue)
      ? window.BIomieContextFilterQueue.slice()
      : [];
    queued.forEach(register);
    window.BIomieContextFilterQueue = [];
    renderChipsBar();
  }

  window.BIomieContextFilters = {
    version: '1.1.0',
    register: register,
    open: open,
    close: close,
    apply: apply,
    getState: function () {
      return eventDetail('read');
    },
    /** Recomputa a barra de filtros ativos — chame após mudar vendedor/canal fora do fluxo padrão. */
    refreshChips: renderChipsBar
  };

  document.addEventListener('biomie:filter-register', function (event) {
    register(event && event.detail ? event.detail : {});
  });

  // Vendedor e canal (Filtro Comercial Inteligente) mudam fora do ciclo de
  // registro/apply desta página — cada um dispara seu próprio evento quando a
  // seleção muda (inclusive sem reload, no Dashboard). A barra de chips escuta
  // os dois para nunca divergir do que está realmente filtrando os dados.
  document.addEventListener('biomie:vendor-context-change', function () {
    renderChipsBar();
  });
  document.addEventListener('biomie:dashboard-filtered', function () {
    renderChipsBar();
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      window.setTimeout(init, 0);
    }, { once: true });
  } else {
    init();
  }
}());

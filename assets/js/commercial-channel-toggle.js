/*
 * BIomie — Toggle de Operação (canal comercial) reutilizável.
 *
 * Coloca o mesmo controle Todas/Varejo/Licitação do Dashboard em qualquer página
 * comercial (Agenda, NF do Período, Plano de Voo). Lê/grava a preferência GLOBAL
 * compartilhada (api/commercial_filter_pref.php), então a escolha PROPAGA entre as
 * superfícies. Comporta-se como o seletor de vendedor: ao trocar, recarrega a
 * página (a menos que alguém intercepte 'biomie:commercial-channel-apply').
 *
 * Renderiza SÓ em contexto mixed (2 canais). Single-channel fixa o canal ⇒ oculto.
 * A segurança é do servidor: o endpoint valida (403) e persiste; aqui é só UI.
 *
 * Uso: coloque <div data-commercial-channel-toggle></div> onde o toggle deve
 * aparecer e inclua este script. Nada mais é necessário.
 */
(function () {
  'use strict';

  if (window.BIomieCommercialChannel && window.BIomieCommercialChannel.version) return;

  var PREF_URL = 'api/commercial_filter_pref.php';
  var containers = [];
  var ctx = { allowed: [], businessScope: '', isMixed: false, ready: false };
  var current = '';         // '' = todas · 'varejo' · 'licitacao'
  var sanitized = false;
  var busy = false;

  function csrfToken() {
    var meta = document.querySelector('meta[name="biomie-csrf"]');
    return meta ? String(meta.getAttribute('content') || '') : '';
  }

  function escHtml(value) {
    if (window.BIomie && window.BIomie.escapeHtml) return window.BIomie.escapeHtml(value);
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }

  function readUrlChannel() {
    try {
      var raw = String(new URLSearchParams(window.location.search || '').get('channel') || '')
        .trim().toLowerCase();
      return raw === 'varejo' || raw === 'licitacao' ? raw : '';
    } catch (error) {
      return '';
    }
  }

  function updateUrl() {
    try {
      var url = new URL(window.location.href);
      if (current) url.searchParams.set('channel', current);
      else url.searchParams.delete('channel');
      window.history.replaceState({}, '', url);
    } catch (error) {
      // History API pode estar bloqueada; a preferência salva ainda propaga.
    }
  }

  function optionLabel(value) {
    if (value === 'varejo') return 'Varejo';
    if (value === 'licitacao') return 'Licitação';
    return 'Todas as operações';
  }

  function render() {
    if (!ctx.ready || !ctx.isMixed) {
      containers.forEach(function (el) { el.hidden = true; el.innerHTML = ''; });
      return;
    }
    var options = [''];
    if (ctx.allowed.indexOf('varejo') !== -1) options.push('varejo');
    if (ctx.allowed.indexOf('licitacao') !== -1) options.push('licitacao');

    var buttons = options.map(function (value) {
      var active = value === current;
      return '<button type="button" class="commercial-channel-toggle__btn' + (active ? ' is-active' : '') + '"'
        + ' data-commercial-channel="' + escHtml(value) + '"'
        + ' aria-pressed="' + (active ? 'true' : 'false') + '"'
        + (busy ? ' disabled' : '') + '>' + escHtml(optionLabel(value)) + '</button>';
    }).join('');

    var toggle = '<div class="commercial-channel-toggle" role="group" aria-label="Filtrar por operação">'
      + '<span class="commercial-channel-toggle__label">Operação</span>' + buttons + '</div>';
    var notice = sanitized
      ? '<div class="commercial-filter-notice" role="status">'
        + '<span class="material-symbols-rounded" aria-hidden="true">info</span>'
        + '<span>Parte do seu filtro salvo não está mais autorizada e foi removida.</span></div>'
      : '';

    containers.forEach(function (el) {
      el.hidden = false;
      if (!el.classList.contains('commercial-filter-bar')) el.classList.add('commercial-filter-bar');
      el.innerHTML = toggle + notice;
      Array.prototype.forEach.call(el.querySelectorAll('[data-commercial-channel]'), function (btn) {
        btn.addEventListener('click', function () {
          apply(String(btn.getAttribute('data-commercial-channel') || ''));
        });
      });
    });
  }

  function apply(value) {
    value = (value === 'varejo' || value === 'licitacao') ? value : '';
    if (value && ctx.allowed.indexOf(value) === -1) return;   // não autorizado
    if (value === current || busy) return;

    current = value;
    busy = true;
    updateUrl();
    render();

    var token = csrfToken();
    var body = { action: 'save', channels: current ? [current] : [], csrf_token: token };
    fetch(PREF_URL, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch', 'X-CSRF-Token': token },
      body: JSON.stringify(body)
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (data) {
        return { status: r.status, data: data };
      });
    }).then(function (res) {
      if (res.status === 403 || (res.data && res.data.error === 'scope_denied')) {
        // Não deveria ocorrer pela UI (só oferece canais autorizados).
        busy = false;
        render();
        return;
      }
      // Recarrega a página para o novo recorte — igual ao seletor de vendedor.
      // Cancelável: uma página pode preferir recarregar sozinha (SPA).
      var evt = new CustomEvent('biomie:commercial-channel-apply', {
        bubbles: true, cancelable: true, detail: { channel: current }
      });
      var shouldReload = document.dispatchEvent(evt);
      if (shouldReload) {
        window.location.reload();
      } else {
        busy = false;
        render();
      }
    }).catch(function () {
      // Falha de rede: o recorte não foi salvo; devolve o controle ao usuário.
      busy = false;
      render();
    });
  }

  function hydrate(data) {
    var cs = (data && data.channel_scope) ? data.channel_scope : null;
    var allowed = cs && Array.isArray(cs.allowed) ? cs.allowed.slice() : [];
    ctx = {
      allowed: allowed,
      businessScope: String((data && data.business_scope) || ''),
      isMixed: String((data && data.business_scope) || '') === 'mixed' && allowed.length >= 2,
      ready: true
    };
    sanitized = !!(data && data.sanitized);

    var urlChannel = readUrlChannel();
    if (urlChannel && allowed.indexOf(urlChannel) !== -1) {
      current = urlChannel;
    } else if (cs && Array.isArray(cs.requested) && cs.requested.length === 1
               && allowed.indexOf(cs.requested[0]) !== -1) {
      current = cs.requested[0];   // preferência salva (requested null ⇒ todas)
    } else {
      current = '';
    }
    render();
  }

  function load() {
    fetch(PREF_URL, {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'fetch' }
    }).then(function (r) {
      if (!r.ok) return null;      // 409 (consolidado) / 401 etc. ⇒ oculta
      return r.json();
    }).then(function (data) {
      if (!data || data.ok === false) {
        ctx.ready = true; ctx.isMixed = false; render();
        return;
      }
      hydrate(data);
    }).catch(function () {
      ctx.ready = true; ctx.isMixed = false; render();
    });
  }

  function init() {
    containers = Array.prototype.slice.call(document.querySelectorAll('[data-commercial-channel-toggle]'));
    if (!containers.length) return;   // página sem toggle ⇒ zero custo
    load();
  }

  window.BIomieCommercialChannel = {
    version: '1.0.0',
    getChannel: function () { return current; },
    isMixed: function () { return ctx.isMixed; },
    /** Aplica um canal programaticamente (ex: chip "Limpar" na barra de filtros ativos). */
    setChannel: function (value) { apply(value); }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
  } else {
    init();
  }
}());

/* BIomie — seletor do primeiro contexto da aba. */
(function () {
  'use strict';

  var dataEl = document.getElementById('se-data');
  var runtime = dataEl ? JSON.parse(dataEl.textContent || '{}') : {};
  var CSRF = runtime.csrf || '';
  var REDIRECT = runtime.redirect || (runtime.base || '') + '/index.php';
  var BASE = runtime.base || '';
  var switching = false;

  function apiUrl(path) {
    return BASE + '/' + path;
  }

  function validContextId(value) {
    return typeof value === 'string' && /^[a-f0-9]{32}$/i.test(value);
  }

  function redirectWithContext(url, contextId) {
    if (!contextId) return url;
    try {
      var target = new URL(url, window.location.href);
      target.searchParams.set('ctx', contextId);
      return target.toString();
    } catch (_) {
      return url;
    }
  }

  function establishTabContext(contextId) {
    if (!contextId) return;
    try { sessionStorage.setItem('biomie_tab_context_v1', contextId); } catch (_) {}
  }

  function parseJsonResponse(response) {
    return response.json().catch(function () {
      return { ok: false, message: 'Resposta inválida do servidor.' };
    }).then(function (data) {
      if (!response.ok && !data.message) {
        data.message = 'Não foi possível atualizar o contexto.';
      }
      return data;
    });
  }

  function setBusy(target, busy) {
    if (!target) return;
    target.classList.toggle('is-loading', busy);
    target.setAttribute('aria-busy', busy ? 'true' : 'false');
    var button = target.querySelector
      ? target.querySelector('.se-btn-enter, #se-btn-consolidado')
      : null;
    if (button) button.disabled = busy;
  }

  function setSelectorBusy(target, busy) {
    document.querySelectorAll('.se-card, .se-hero-card').forEach(function (item) {
      item.setAttribute('aria-disabled', busy ? 'true' : 'false');
      item.querySelectorAll('button').forEach(function (button) {
        if (busy) {
          button.dataset.contextWasDisabled = button.disabled ? '1' : '0';
          button.disabled = true;
        } else {
          if (button.dataset.contextWasDisabled === '0') button.disabled = false;
          delete button.dataset.contextWasDisabled;
        }
      });
    });
    setBusy(target, busy);
  }

  var toastTimer;
  function showToast(message) {
    var element = document.getElementById('se-toast');
    if (!element) return;
    element.textContent = message;
    element.classList.add('visible');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () {
      element.classList.remove('visible');
    }, 4000);
  }

  function initCanalToggles() {
    document.querySelectorAll('.se-canal-row').forEach(function (row) {
      row.addEventListener('click', function (event) {
        if (switching) return;
        var button = event.target.closest('.se-canal-btn');
        if (!button) return;
        event.stopPropagation();
        var card = button.closest('.se-card');
        if (!card) return;
        row.querySelectorAll('.se-canal-btn').forEach(function (candidate) {
          var selected = candidate === button;
          candidate.classList.toggle('active', selected);
          candidate.setAttribute('aria-pressed', selected ? 'true' : 'false');
        });
        card.dataset.canal = button.dataset.canal;
      });
    });
  }

  function switchContext(target, card) {
    if (switching) return;
    switching = true;
    setSelectorBusy(card, true);

    fetch(apiUrl('api/context_switch.php'), {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': CSRF
      },
      body: JSON.stringify(Object.assign({}, target, { csrf_token: CSRF }))
    })
      .then(parseJsonResponse)
      .then(function (data) {
        var nextContextId = data && data.context ? data.context.id : '';
      if (!data.ok || !validContextId(nextContextId)) {
          showToast(data.message || 'Erro ao selecionar empresa. Tente novamente.');
          switching = false;
          setSelectorBusy(card, false);
        return;
      }
      nextContextId = nextContextId.toLowerCase();

      // Nenhum estado ativo muda antes de o servidor confirmar o ctx opaco.
        establishTabContext(nextContextId);
        window.location.assign(
          redirectWithContext(data.redirect_url || REDIRECT, nextContextId)
        );
      })
      .catch(function () {
        showToast('Falha de conexão. O contexto anterior foi preservado.');
        switching = false;
        setSelectorBusy(card, false);
      });
  }

  function initCards() {
    document.querySelectorAll('.se-card').forEach(function (card) {
      var enterButton = card.querySelector('.se-btn-enter:not([disabled])');
      if (!enterButton) return;
      enterButton.addEventListener('click', function (event) {
        event.stopPropagation();
        var companyId = parseInt(card.dataset.cid, 10);
        var businessScope = card.dataset.canal || 'varejo';
        switchContext({
          target_type: 'company',
          company_id: companyId,
          business_scope: businessScope
        }, card);
      });
    });
  }

  function initConsolidado() {
    var button = document.getElementById('se-btn-consolidado');
    if (!button) return;
    button.addEventListener('click', function () {
      switchContext(
        { target_type: 'group' },
        button.closest('.se-hero-card') || button
      );
    });
  }

  function animateCards() {
    document.querySelectorAll('.se-card').forEach(function (card, index) {
      card.style.opacity = '0';
      card.style.transform = 'translateY(20px)';
      card.style.transition = 'opacity .4s ease, transform .4s ease, box-shadow .22s cubic-bezier(.34,1.56,.64,1)';
      setTimeout(function () {
        card.style.opacity = '';
        card.style.transform = '';
        setTimeout(function () { card.style.transition = ''; }, 400);
      }, 60 + index * 80);
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    initCanalToggles();
    initCards();
    initConsolidado();
    animateCards();
  });
}());

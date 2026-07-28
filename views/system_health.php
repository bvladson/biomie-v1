<?php /** @var bool $isAdmin */ ?>
<html>
<head>
<style>
  .sh-page    { max-width: 1100px; margin: 0 auto; padding: var(--space-6) var(--space-8); }
  .sh-header  { margin-bottom: var(--space-6); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: var(--space-3); }
  .sh-header h1 { font-size: 1.375rem; font-weight: 700; margin: 0 0 var(--space-1); }
  .sh-header p  { font-size: .875rem; color: var(--color-text-muted); margin: 0; }
  .sh-banner   { border-radius: var(--radius-md); padding: var(--space-4) var(--space-5); font-size: .875rem; margin-bottom: var(--space-5); display: flex; align-items: center; gap: var(--space-3); }
  .sh-banner--ok       { background: #d1fae5; color: #064e3b; }
  .sh-banner--warning  { background: #fef3c7; color: #92400e; }
  .sh-banner--critical { background: #fee2e2; color: #7f1d1d; }
  .sh-banner--unknown  { background: var(--color-surface-2); color: var(--color-text-muted); }
  .sh-banner__icon { font-size: 1.5rem; }
  .sh-grid    { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: var(--space-4); }
  .sh-card    { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-lg); padding: var(--space-5); display: flex; flex-direction: column; gap: var(--space-3); transition: box-shadow .15s; }
  .sh-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.07); }
  .sh-card__head    { display: flex; align-items: center; justify-content: space-between; gap: var(--space-2); }
  .sh-card__label   { font-size: .9375rem; font-weight: 600; }
  .sh-card__badge   { display: inline-flex; align-items: center; gap: 4px; font-size: .7rem; font-weight: 700; padding: 3px 9px; border-radius: 99px; text-transform: uppercase; letter-spacing: .04em; }
  .sh-card__badge--ok       { background: #d1fae5; color: #065f46; }
  .sh-card__badge--warning  { background: #fef3c7; color: #92400e; }
  .sh-card__badge--critical { background: #fee2e2; color: #991b1b; }
  .sh-card__badge--unknown  { background: var(--color-surface-2); color: var(--color-text-muted); }
  .sh-card__summary  { font-size: .8125rem; color: var(--color-text-muted); line-height: 1.5; }
  .sh-card__metrics  { display: flex; flex-direction: column; gap: 4px; }
  .sh-card__metric   { display: flex; justify-content: space-between; font-size: .775rem; }
  .sh-card__metric-k { color: var(--color-text-muted); }
  .sh-card__metric-v { font-weight: 600; font-family: 'DM Mono', monospace; }
  .sh-card__actions  { margin-top: var(--space-1); padding-top: var(--space-2); border-top: 1px solid var(--color-border); }
  .sh-card__action   { font-size: .775rem; color: var(--color-text-muted); display: flex; gap: 6px; margin-top: 4px; }
  .sh-card__action::before { content: '→'; flex-shrink: 0; }
  .sh-card__ts       { font-size: .7rem; color: var(--color-text-muted); font-family: 'DM Mono', monospace; }
  .sh-refresh-btn    { display: inline-flex; align-items: center; gap: var(--space-2); padding: 8px 14px; border-radius: var(--radius-md); border: 1px solid var(--color-border); background: var(--color-surface); font-size: .8125rem; font-weight: 500; cursor: pointer; transition: background .12s; }
  .sh-refresh-btn:hover { background: var(--color-surface-2); }
  .sh-refresh-btn .material-symbols-rounded { font-size: 1rem; }
  .sh-spinner { animation: sh-spin 1s linear infinite; }
  @keyframes sh-spin { to { transform: rotate(360deg); } }
  .sh-loading { color: var(--color-text-muted); font-size: .875rem; padding: var(--space-6) 0; text-align: center; }
  .sh-error   { color: #991b1b; font-size: .875rem; padding: var(--space-4); background: #fee2e2; border-radius: var(--radius-md); }
  .sh-meta    { font-size: .75rem; color: var(--color-text-muted); margin-top: var(--space-4); }
  .sh-admin-hint { font-size: .75rem; color: var(--color-text-muted); margin-top: var(--space-2); text-align: right; }
  .sh-admin-hint a { color: var(--color-text-muted); text-decoration: underline; text-underline-offset: 3px; }
  .sh-cache-section { margin-top: var(--space-6); border-top: 1px solid var(--color-border); padding-top: var(--space-5); }
  .sh-cache-section h2 { font-size: 1rem; font-weight: 700; margin: 0 0 var(--space-3); display: flex; align-items: center; gap: var(--space-2); }
  .sh-cache-btns { display: flex; flex-wrap: wrap; gap: var(--space-2); }
  .sh-cache-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: var(--radius-md); border: 1px solid var(--color-border); background: var(--color-surface); font-size: .8125rem; font-weight: 500; cursor: pointer; transition: background .12s; }
  .sh-cache-btn:hover:not(:disabled) { background: var(--color-surface-2); }
  .sh-cache-btn:disabled { opacity: .5; cursor: not-allowed; }
  .sh-cache-btn .material-symbols-rounded { font-size: 1rem; }
  .sh-cache-btn--danger { border-color: #fca5a5; color: #991b1b; }
  .sh-cache-btn--danger:hover:not(:disabled) { background: #fee2e2; }
  .sh-cache-toast { display: none; margin-top: var(--space-3); padding: 10px 14px; border-radius: var(--radius-md); font-size: .8125rem; font-weight: 500; }
  .sh-cache-toast--ok { background: #d1fae5; color: #065f46; display: block; }
  .sh-cache-toast--err { background: #fee2e2; color: #991b1b; display: block; }
</style>
</head>
<main class="mag-clean-page sh-page" data-is-admin="<?= !empty($isAdmin) ? '1' : '0' ?>">
  <div class="sh-header">
    <div>
      <h1>Saúde do Sistema</h1>
      <p id="sh-subtitle"><?= !empty($isAdmin) ? 'Status em tempo real dos checks de infraestrutura, dados e jobs.' : 'Status operacional dos principais módulos do sistema.' ?></p>
    </div>
    <button type="button" class="sh-refresh-btn" id="sh-refresh-btn">
      <span class="material-symbols-rounded" id="sh-refresh-icon">refresh</span>
      Atualizar
    </button>
  </div>

  <div id="sh-banner"></div>
  <div id="sh-grid" class="sh-grid">
    <div class="sh-loading">Verificando status do sistema...</div>
  </div>
  <div id="sh-meta" class="sh-meta"></div>
  <?php if (empty($isAdmin)): ?>
  <div class="sh-admin-hint">Exibindo status simplificado. Administradores veem o <a href="system_health.php">diagnóstico técnico completo</a>.</div>
  <?php else: ?>
  <div class="sh-cache-section" id="sh-cache-section">
    <h2><span class="material-symbols-rounded" style="font-size:1.1rem">cleaning_services</span>Manutenção de Cache</h2>
    <div class="sh-cache-btns">
      <button type="button" class="sh-cache-btn" data-cache-action="api" title="Limpa storage/cache/api/*.json">
        <span class="material-symbols-rounded">api</span>Limpar cache API
      </button>
      <button type="button" class="sh-cache-btn" data-cache-action="dashboard" title="Limpa storage/cache/dashboard/*.json">
        <span class="material-symbols-rounded">dashboard</span>Limpar cache Dashboard
      </button>
      <button type="button" class="sh-cache-btn" data-cache-action="wallpaper" title="Remove login_wallpaper.json — próximo login busca nova imagem">
        <span class="material-symbols-rounded">wallpaper</span>Limpar wallpaper
      </button>
      <button type="button" class="sh-cache-btn sh-cache-btn--danger" data-cache-action="all" title="Limpa todos os caches internos">
        <span class="material-symbols-rounded">delete_sweep</span>Limpar todos os caches
      </button>
      <button type="button" class="sh-cache-btn" id="sh-opcache-btn" title="Executa opcache_reset() no servidor">
        <span class="material-symbols-rounded">memory</span>Resetar OPcache
      </button>
    </div>
    <div id="sh-cache-toast" class="sh-cache-toast"></div>
  </div>
  <?php endif; ?>
</main>
<script>
(function () {
  'use strict';

  var mainEl  = document.querySelector('main[data-is-admin]');
  var IS_ADMIN = mainEl ? mainEl.getAttribute('data-is-admin') === '1' : false;
  var API_URL  = IS_ADMIN ? 'api/system_health.php' : 'api/health_public.php';

  var STATUS_ICONS   = { ok: 'check_circle', warning: 'warning', critical: 'error', unknown: 'help' };
  var STATUS_LABELS  = { ok: 'OK', warning: 'Atenção', critical: 'Crítico', unknown: 'Desconhecido' };

  function esc(v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }

  function renderBanner(status, message) {
    var icon  = STATUS_ICONS[status]  || 'help';
    var label = STATUS_LABELS[status] || status;
    var cls   = 'sh-banner--' + (STATUS_ICONS[status] ? status : 'unknown');
    var defaultMsgs = {
      ok:       'Todos os sistemas operacionais.',
      warning:  'Um ou mais checks com atenção.',
      critical: 'Um ou mais checks críticos requerem ação.',
      unknown:  'Estado de alguns checks indeterminado.',
    };
    document.getElementById('sh-banner').innerHTML =
      '<div class="sh-banner ' + cls + '">' +
        '<span class="material-symbols-rounded sh-banner__icon">' + esc(icon) + '</span>' +
        '<div><strong>' + esc(label) + '</strong> — ' + esc(message || defaultMsgs[status] || '') + '</div>' +
      '</div>';
  }

  function renderMetrics(metrics) {
    var keys = Object.keys(metrics);
    if (!keys.length) return '';
    return '<div class="sh-card__metrics">' +
      keys.map(function (k) {
        var v = metrics[k];
        return '<div class="sh-card__metric"><span class="sh-card__metric-k">' + esc(k) + '</span><span class="sh-card__metric-v">' + esc(v === null ? '—' : String(v)) + '</span></div>';
      }).join('') +
    '</div>';
  }

  function renderFullCard(check) {
    var badge = '<span class="sh-card__badge sh-card__badge--' + esc(check.status) + '">' +
      '<span class="material-symbols-rounded" style="font-size:.8rem">' + esc(STATUS_ICONS[check.status] || 'help') + '</span>' +
      esc(STATUS_LABELS[check.status] || check.status) + '</span>';
    var actions = (check.actions || []).length
      ? '<div class="sh-card__actions">' + check.actions.map(function (a) { return '<div class="sh-card__action">' + esc(a) + '</div>'; }).join('') + '</div>'
      : '';
    var ts = check.checked_at
      ? '<div class="sh-card__ts">Verificado: ' + esc(check.checked_at) + ' (' + esc(check.duration_ms) + 'ms)</div>'
      : '';
    return '<div class="sh-card">' +
      '<div class="sh-card__head"><span class="sh-card__label">' + esc(check.label) + '</span>' + badge + '</div>' +
      '<div class="sh-card__summary">' + esc(check.summary) + '</div>' +
      renderMetrics(check.metrics || {}) + actions + ts +
    '</div>';
  }

  function renderSimpleCard(domain) {
    var badge = '<span class="sh-card__badge sh-card__badge--' + esc(domain.status) + '">' +
      '<span class="material-symbols-rounded" style="font-size:.8rem">' + esc(STATUS_ICONS[domain.status] || 'help') + '</span>' +
      esc(STATUS_LABELS[domain.status] || domain.status) + '</span>';
    return '<div class="sh-card">' +
      '<div class="sh-card__head"><span class="sh-card__label">' + esc(domain.label) + '</span>' + badge + '</div>' +
      '<div class="sh-card__summary">' + esc(domain.message) + '</div>' +
    '</div>';
  }

  function parseJsonResponse(response) {
    return response.text().then(function (text) {
      var json = null;
      try { json = text ? JSON.parse(text) : null; } catch (e) {}
      if (!response.ok) {
        var err = new Error((json && json.message) || 'Falha HTTP ' + response.status);
        err.requestId = (json && json.request_id) || response.headers.get('X-Request-Id') || '';
        throw err;
      }
      return json || {};
    });
  }

  function load() {
    var grid = document.getElementById('sh-grid');
    var meta = document.getElementById('sh-meta');
    var icon = document.getElementById('sh-refresh-icon');
    var btn  = document.getElementById('sh-refresh-btn');

    if (icon) icon.classList.add('sh-spinner');
    if (btn)  btn.disabled = true;
    grid.innerHTML = '<div class="sh-loading">Verificando status do sistema...</div>';
    document.getElementById('sh-banner').innerHTML = '';
    if (meta) meta.innerHTML = '';

    fetch(API_URL, { credentials: 'same-origin' })
      .then(parseJsonResponse)
      .then(function (json) {
        if (!json.ok) {
          grid.innerHTML = '<div class="sh-error">Erro ao carregar status: ' + esc(json.message || 'desconhecido') + '</div>';
          renderBanner('unknown', null);
          return;
        }
        renderBanner(json.status, IS_ADMIN ? null : (json.message || null));
        if (IS_ADMIN) {
          var checks = json.checks || [];
          grid.innerHTML = checks.length ? checks.map(renderFullCard).join('') : '<div class="sh-loading">Nenhum check disponível.</div>';
        } else {
          var domains = json.domains || [];
          grid.innerHTML = domains.length ? domains.map(renderSimpleCard).join('') : '<div class="sh-loading">Status indisponível.</div>';
        }
        if (meta) {
          meta.textContent = 'Atualizado em: ' + (json.generated_at || (json.meta && json.meta.generated_at) || '—');
        }
      })
      .catch(function () {
        grid.innerHTML = '<div class="sh-error">Falha ao verificar o status do sistema. Tente novamente.</div>';
        renderBanner('unknown', 'Não foi possível verificar o status do sistema agora.');
      })
      .finally(function () {
        if (icon) icon.classList.remove('sh-spinner');
        if (btn)  btn.disabled = false;
      });
  }

  document.getElementById('sh-refresh-btn').addEventListener('click', load);
  load();

  // Cache management buttons (admin only)
  if (IS_ADMIN) {
    var toast = document.getElementById('sh-cache-toast');
    var csrfMeta = document.querySelector('meta[name="biomie-csrf"]');

    function showToast(ok, msg) {
      if (!toast) return;
      toast.className = 'sh-cache-toast sh-cache-toast--' + (ok ? 'ok' : 'err');
      toast.textContent = msg;
      clearTimeout(toast._t);
      toast._t = setTimeout(function () { toast.className = 'sh-cache-toast'; }, 5000);
    }

    function cachePost(url, body) {
      var csrf = csrfMeta ? csrfMeta.getAttribute('content') : '';
      return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
        body: body,
      }).then(function (r) { return r.json(); });
    }

    document.querySelectorAll('[data-cache-action]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var action = btn.getAttribute('data-cache-action');
        btn.disabled = true;
        cachePost('api/admin_clear_cache.php', 'clear=' + encodeURIComponent(action))
          .then(function (json) {
            if (json.ok) {
              showToast(true, 'Cache "' + action + '" limpo. ' + (json.cleared || []).length + ' arquivo(s) removido(s).');
            } else {
              showToast(false, 'Erro: ' + (json.message || 'desconhecido'));
            }
          })
          .catch(function () { showToast(false, 'Falha ao comunicar com o servidor.'); })
          .finally(function () { btn.disabled = false; });
      });
    });

    var opcacheBtn = document.getElementById('sh-opcache-btn');
    if (opcacheBtn) {
      opcacheBtn.addEventListener('click', function () {
        opcacheBtn.disabled = true;
        cachePost('api/admin_opcache_reset.php', '')
          .then(function (json) {
            showToast(json.ok, json.message || (json.ok ? 'OPcache resetado.' : 'Falha ao resetar OPcache.'));
          })
          .catch(function () { showToast(false, 'Falha ao comunicar com o servidor.'); })
          .finally(function () { opcacheBtn.disabled = false; });
      });
    }
  }
}());
</script>

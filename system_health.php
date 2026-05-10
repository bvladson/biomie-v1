<?php

declare(strict_types=1);

use BIomie\Auth;

require __DIR__ . '/app/page_bootstrap.php';

Auth::requireAnyCapability(['logs.view', 'jobs.view', 'admin.view'], false);

$currentUser = Auth::currentUser();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Saúde do Sistema | BIomie MAG</title>
  <link rel="stylesheet" href="assets/css/theme-tokens.css?v=20260509-sh1" />
  <link rel="stylesheet" href="assets/css/shell-index.css?v=20260509-sh1" />
  <link rel="stylesheet" href="assets/css/module-pages.css?v=20260509-sh1" />
  <link rel="stylesheet" href="assets/css/admin-insights.css?v=20260509-10i" />
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet" />
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet" />
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
  </style>
</head>
<body>
<?php require __DIR__ . '/views/partials/sidebar.php'; ?>
<main class="main-content">
  <div class="sh-page">
    <div class="sh-header">
      <div>
        <h1>Saúde do Sistema</h1>
        <p>Status em tempo real dos checks de infraestrutura, dados e jobs.</p>
      </div>
      <button type="button" class="sh-refresh-btn" id="sh-refresh-btn">
        <span class="material-symbols-rounded" id="sh-refresh-icon">refresh</span>
        Atualizar
      </button>
    </div>

    <div id="sh-banner"></div>
    <div id="sh-grid" class="sh-grid">
      <div class="sh-loading">Carregando checks...</div>
    </div>
    <div id="sh-meta" class="sh-meta"></div>
  </div>
</main>

<script>
(function () {
  'use strict';

  var STATUS_ICONS = { ok: 'check_circle', warning: 'warning', critical: 'error', unknown: 'help' };
  var STATUS_LABELS = { ok: 'OK', warning: 'Atenção', critical: 'Crítico', unknown: 'Desconhecido' };

  function esc(v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }

  function renderBanner(status) {
    var icon  = STATUS_ICONS[status]  || 'help';
    var label = STATUS_LABELS[status] || status;
    var cls   = 'sh-banner--' + status;
    var msgs  = {
      ok:       'Todos os sistemas operacionais.',
      warning:  'Um ou mais checks com atenção.',
      critical: 'Um ou mais checks críticos requerem ação.',
      unknown:  'Estado de alguns checks indeterminado.',
    };
    document.getElementById('sh-banner').innerHTML =
      '<div class="sh-banner ' + cls + '">' +
        '<span class="material-symbols-rounded sh-banner__icon">' + esc(icon) + '</span>' +
        '<div><strong>' + esc(label) + '</strong> — ' + esc(msgs[status] || '') + '</div>' +
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

  function renderCard(check) {
    var badge = '<span class="sh-card__badge sh-card__badge--' + esc(check.status) + '">' +
      '<span class="material-symbols-rounded" style="font-size:.8rem">' + esc(STATUS_ICONS[check.status] || 'help') + '</span>' +
      esc(STATUS_LABELS[check.status] || check.status) + '</span>';

    var actions = (check.actions || []).length
      ? '<div class="sh-card__actions">' + check.actions.map(function (a) {
          return '<div class="sh-card__action">' + esc(a) + '</div>';
        }).join('') + '</div>'
      : '';

    var ts = check.checked_at
      ? '<div class="sh-card__ts">Verificado: ' + esc(check.checked_at) + ' (' + esc(check.duration_ms) + 'ms)</div>'
      : '';

    return '<div class="sh-card">' +
      '<div class="sh-card__head"><span class="sh-card__label">' + esc(check.label) + '</span>' + badge + '</div>' +
      '<div class="sh-card__summary">' + esc(check.summary) + '</div>' +
      renderMetrics(check.metrics || {}) +
      actions + ts +
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
    var grid    = document.getElementById('sh-grid');
    var meta    = document.getElementById('sh-meta');
    var icon    = document.getElementById('sh-refresh-icon');
    var btn     = document.getElementById('sh-refresh-btn');

    if (icon) icon.classList.add('sh-spinner');
    if (btn)  btn.disabled = true;

    grid.innerHTML = '<div class="sh-loading">Carregando checks...</div>';
    document.getElementById('sh-banner').innerHTML = '';
    if (meta) meta.innerHTML = '';

    fetch('api/system_health.php', { credentials: 'same-origin' })
      .then(parseJsonResponse)
      .then(function (json) {
        if (!json.ok) {
          grid.innerHTML = '<div class="sh-error">Erro ao carregar relatório: ' + esc(json.message || 'desconhecido') + '</div>';
          return;
        }
        renderBanner(json.status);
        var checks = json.checks || [];
        grid.innerHTML = checks.length
          ? checks.map(renderCard).join('')
          : '<div class="sh-loading">Nenhum check disponível.</div>';
        if (meta) meta.textContent = 'Gerado em: ' + (json.generated_at || '—');
      })
      .catch(function (err) {
        grid.innerHTML = '<div class="sh-error">Falha de rede ao carregar saúde do sistema.</div>';
      })
      .finally(function () {
        if (icon) icon.classList.remove('sh-spinner');
        if (btn)  btn.disabled = false;
      });
  }

  document.getElementById('sh-refresh-btn').addEventListener('click', load);
  load();
}());
</script>
</body>
</html>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
<style>
  .ci-page   { max-width: 1200px; margin: 0 auto; padding: var(--space-6) var(--space-8); }
  .ci-section-title { font-size: .875rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--color-text-muted); margin: var(--space-6) 0 var(--space-3); }
  .ci-kpi-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: var(--space-4); }
  .ci-kpi-card { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-lg); padding: var(--space-5); display: flex; flex-direction: column; gap: var(--space-2); }
  .ci-kpi-card__id   { font-family: 'DM Mono', monospace; font-size: .7rem; color: var(--color-text-muted); }
  .ci-kpi-card__name { font-size: .9375rem; font-weight: 600; }
  .ci-kpi-card__tags { display: flex; gap: var(--space-2); flex-wrap: wrap; margin-top: var(--space-1); }
  .ci-kpi-card__row  { font-size: .8rem; color: var(--color-text-muted); display: flex; gap: 6px; align-items: baseline; }
  .ci-kpi-card__row strong { color: var(--color-text); font-size: .8125rem; }
  .ci-kpi-card__notes { font-size: .75rem; font-style: italic; color: var(--color-text-muted); margin-top: var(--space-1); }
  .ci-health-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: var(--space-3); }
  .ci-health-card { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: var(--space-4); }
  .ci-health-card__domain { font-size: .75rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--color-text-muted); }
  .ci-health-card__status { font-size: 1.1rem; font-weight: 700; margin-top: 4px; }
  .ci-health-card__ts     { font-size: .75rem; color: var(--color-text-muted); margin-top: 2px; }
  .ci-audit-banner { border-radius: var(--radius-md); padding: var(--space-4) var(--space-5); font-size: .875rem; margin-bottom: var(--space-4); }
  .ci-audit-banner--ok   { background: #d1fae5; color: #064e3b; }
  .ci-audit-banner--fail { background: #fee2e2; color: #7f1d1d; }
  .bmie-badge { display: inline-block; font-size: .6875rem; font-weight: 600; padding: 2px 7px; border-radius: 99px; }
  .bmie-badge--ok    { background: #d1fae5; color: #065f46; }
  .bmie-badge--warn  { background: #fef3c7; color: #92400e; }
  .bmie-badge--info  { background: #dbeafe; color: #1e40af; }
  .bmie-badge--muted { background: var(--color-surface-2); color: var(--color-text-muted); }
  .bmie-badge--fail  { background: #fee2e2; color: #991b1b; }
  .ci-loading { color: var(--color-text-muted); font-size: .875rem; padding: var(--space-4) 0; }
  .ci-error   { color: #991b1b; font-size: .875rem; padding: var(--space-4) 0; }
  .mc-back { font-size: .8125rem; color: var(--color-text-muted); text-decoration: none; display: inline-flex; align-items: center; gap: 4px; padding: var(--space-3) var(--space-8) 0; }
  .mc-back:hover { color: var(--color-text); }
</style>
</head>
<body>
<main class="mag-clean-page" data-page="cockpit-interno">
<a class="mc-back" href="admin.php"><span class="material-symbols-rounded" style="font-size:16px">arrow_back</span> Voltar ao Admin</a>

<div class="ci-page">
  <div class="biomie-page-header biomie-page-header--admin">
    <h1 class="biomie-page-header__title"><span class="material-symbols-rounded" style="font-size:1.1em;vertical-align:-.1em">monitoring</span> Cockpit Interno MAG</h1>
    <p class="biomie-page-header__subtitle">KPIs oficiais, status de freshness e saúde semântica do BIomie &mdash; atualizado em cada acesso.</p>
  </div>

  <div id="ci-audit-banner" class="ci-loading" aria-live="polite" role="status">Carregando estado semântico&hellip;</div>

  <div class="ci-section-title"><span class="material-symbols-rounded biomie-icon-sm">refresh</span> Freshness por Domínio</div>
  <div id="ci-freshness-grid" class="ci-health-grid" aria-live="polite"><div class="ci-loading">Carregando freshness&hellip;</div></div>

  <div class="ci-section-title"><span class="material-symbols-rounded biomie-icon-sm">sync</span> Último Gold Mart Refresh</div>
  <div id="ci-job-status" class="ci-loading" aria-live="polite">Carregando&hellip;</div>

  <div class="ci-section-title"><span class="material-symbols-rounded biomie-icon-sm">hub</span> KPIs Oficiais por Domínio</div>
  <div id="ci-kpi-grid" class="ci-kpi-grid" aria-live="polite"><div class="ci-loading">Carregando catálogo&hellip;</div></div>
</div>
</main>
<script>
(function () {
  'use strict';

  function badge(text, cls) {
    return '<span class="bmie-badge bmie-badge--' + cls + '">' + esc(text) + '</span>';
  }

  function esc(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function statusBadge(status) {
    if (status === 'active')     return badge('active', 'ok');
    if (status === 'draft')      return badge('draft', 'warn');
    if (status === 'deprecated') return badge('deprecated', 'muted');
    return badge(status, 'muted');
  }

  function sourceBadge(src) {
    if (src === 'gold')        return badge('gold', 'ok');
    if (src === 'canonical')   return badge('canonical', 'info');
    if (src === 'operational') return badge('operational', 'info');
    if (src === 'legacy')      return badge('legacy', 'muted');
    if (src === 'shadow')      return badge('shadow', 'warn');
    return badge(src, 'muted');
  }

  function freshnessBadge(status) {
    if (status === 'fresh')   return badge('fresh', 'ok');
    if (status === 'stale')   return badge('stale', 'warn');
    if (status === 'partial') return badge('partial', 'warn');
    if (status === 'failed')  return badge('failed', 'fail');
    return badge('unknown', 'muted');
  }

  function fmtTs(ts) {
    if (!ts) return '—';
    try { return new Date(ts).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' }); }
    catch { return ts; }
  }

  const PRIORITY_KPIS = [
    'receita_fiscal', 'vendas_comerciais', 'receita_realizada',
    'contas_a_receber', 'inadimplencia_total', 'ranking_vendedor',
    'clientes_recorrentes', 'vendas_por_cnae', 'compradores_por_bairro',
    'jobs_falhos', 'omie_sync_freshness', 'shadow_divergencias',
    'coverage_canonical', 'vendas_por_cidade', 'vendas_por_uf',
  ];

  fetch('api/semantic_health.php')
    .then(r => r.json())
    .then(data => {
      const sem = data.semantic ?? {};
      const banner = document.getElementById('ci-audit-banner');
      const ok = sem.audit_ok !== false;
      banner.className = 'ci-audit-banner ' + (ok ? 'ci-audit-banner--ok' : 'ci-audit-banner--fail');
      banner.innerHTML = [
        '<strong>', ok ? '✓ Semantic Audit OK' : '✗ Semantic Audit FAIL', '</strong>',
        '&nbsp;·&nbsp;',
        (sem.kpis?.active ?? '?') + ' active',
        '·',
        (sem.kpis?.draft  ?? '?') + ' draft',
        '·',
        (sem.kpis?.total  ?? '?') + ' total',
        sem.errors?.length
          ? ' &nbsp;· <strong>Erros:</strong> ' + sem.errors.map(e => esc(e)).join('; ')
          : '',
        sem.warnings?.length
          ? ' &nbsp;· <strong>Avisos:</strong> ' + sem.warnings.length
          : '',
      ].join(' ');

      const fresh = data.freshness ?? {};
      const byDomain = fresh.by_domain ?? {};
      const domains = Object.entries(byDomain).filter(([k]) => !k.startsWith('kpi:'));
      const grid = document.getElementById('ci-freshness-grid');
      if (domains.length === 0) {
        grid.innerHTML = '<span class="ci-loading">Banco indisponível — freshness não calculado.</span>';
      } else {
        grid.innerHTML = domains.map(([domain, info]) => [
          '<div class="ci-health-card">',
          '<div class="ci-health-card__domain">', esc(domain), '</div>',
          '<div class="ci-health-card__status">', freshnessBadge(info.status), '</div>',
          '<div class="ci-health-card__ts">Último sucesso: ', esc(fmtTs(info.last_success_at)), '</div>',
          '<div class="ci-health-card__ts">Stale após: ', esc(info.stale_after_minutes), ' min</div>',
          '</div>',
        ].join('')).join('');
      }

      const job = fresh.gold_job;
      const jobEl = document.getElementById('ci-job-status');
      if (!job) {
        jobEl.innerHTML = '<span class="ci-loading">Job info não disponível.</span>';
      } else {
        jobEl.innerHTML = [
          '<div class="ci-health-card" style="max-width:360px">',
          '<div class="ci-health-card__domain">gold_mart_refresh</div>',
          '<div class="ci-health-card__status">', freshnessBadge(job.status), '</div>',
          '<div class="ci-health-card__ts">Último sucesso: ', esc(fmtTs(job.last_success_at)), '</div>',
          '<div class="ci-health-card__ts">Última exec: ', esc(fmtTs(job.last_reconcile_at)), '</div>',
          '</div>',
        ].join('');
      }
    })
    .catch(err => {
      document.getElementById('ci-audit-banner').className = 'ci-error';
      document.getElementById('ci-audit-banner').textContent = 'Erro ao carregar estado semântico: ' + err.message;
    });

  fetch('api/metric_catalog.php')
    .then(r => r.json())
    .then(data => {
      const byDomain = data.kpis_by_domain ?? {};
      const grid = document.getElementById('ci-kpi-grid');
      const all = Object.values(byDomain).flat();
      const priority = all.filter(k => PRIORITY_KPIS.includes(k.id));
      const rest     = all.filter(k => !PRIORITY_KPIS.includes(k.id));
      const ordered  = [...priority.sort((a, b) => PRIORITY_KPIS.indexOf(a.id) - PRIORITY_KPIS.indexOf(b.id)), ...rest];

      if (ordered.length === 0) {
        grid.innerHTML = '<span class="ci-error">Nenhum KPI carregado.</span>';
        return;
      }

      grid.innerHTML = ordered.map(kpi => [
        '<div class="ci-kpi-card">',
        '<div class="ci-kpi-card__id">', esc(kpi.id), '</div>',
        '<div class="ci-kpi-card__name">', esc(kpi.name), '</div>',
        '<div class="ci-kpi-card__tags">', statusBadge(kpi.status), sourceBadge(kpi.source_of_truth), badge(kpi.domain, 'muted'), '</div>',
        '<div class="ci-kpi-card__row"><span>Tabela:</span><strong>', esc(kpi.source_table || '—'), '</strong></div>',
        '<div class="ci-kpi-card__row"><span>Data:</span><strong>', esc(kpi.official_date || '—'), '</strong></div>',
        '<div class="ci-kpi-card__row"><span>Permissão:</span><strong>', esc(kpi.permission), '</strong></div>',
        '<div class="ci-kpi-card__row"><span>Freshness:</span><strong>', esc(kpi.freshness_domain), '</strong></div>',
        kpi.notes ? '<div class="ci-kpi-card__notes">' + esc(kpi.notes) + '</div>' : '',
        '</div>',
      ].join('')).join('');
    })
    .catch(err => {
      document.getElementById('ci-kpi-grid').innerHTML =
        '<span class="ci-error">Erro ao carregar catálogo: ' + esc(err.message) + '</span>';
    });
}());
</script>
</body>
</html>

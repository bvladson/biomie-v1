<?php
/** @var array<string,list<object>> $byDomain */
/** @var int $total */
/** @var int $active */
/** @var int $draft */

$_fmtStatus = static function (string $status): string {
    return match ($status) {
        'active'     => '<span class="bmie-badge bmie-badge--ok">active</span>',
        'draft'      => '<span class="bmie-badge bmie-badge--warn">draft</span>',
        'deprecated' => '<span class="bmie-badge bmie-badge--muted">deprecated</span>',
        default      => '<span class="bmie-badge">' . htmlspecialchars($status, ENT_QUOTES) . '</span>',
    };
};

$_fmtSource = static function (string $source): string {
    return match ($source) {
        'gold'          => '<span class="bmie-badge bmie-badge--ok">gold</span>',
        'canonical'     => '<span class="bmie-badge bmie-badge--info">canonical</span>',
        'operational'   => '<span class="bmie-badge bmie-badge--info">operational</span>',
        'legacy'        => '<span class="bmie-badge bmie-badge--muted">legacy</span>',
        'silver'        => '<span class="bmie-badge bmie-badge--muted">silver</span>',
        'shadow'        => '<span class="bmie-badge bmie-badge--warn">shadow</span>',
        'external_omie' => '<span class="bmie-badge bmie-badge--warn">external_omie</span>',
        'bronze'        => '<span class="bmie-badge bmie-badge--fail">bronze</span>',
        default         => htmlspecialchars($source, ENT_QUOTES),
    };
};
?>
<html>
<head>
<style>
  .mc-header { padding: var(--space-6) var(--space-8) var(--space-4); border-bottom: 1px solid var(--color-border); }
  .mc-header h1 { font-size: 1.25rem; font-weight: 700; margin: 0 0 var(--space-1); }
  .mc-header p  { font-size: .875rem; color: var(--color-text-muted); margin: 0; }
  .mc-totals { display: flex; gap: var(--space-4); padding: var(--space-4) var(--space-8); flex-wrap: wrap; }
  .mc-stat { background: var(--color-surface-2); border-radius: var(--radius-md); padding: var(--space-3) var(--space-5); min-width: 100px; }
  .mc-stat__label { font-size: .75rem; color: var(--color-text-muted); text-transform: uppercase; letter-spacing: .05em; }
  .mc-stat__value { font-size: 1.5rem; font-weight: 700; }
  .mc-domain { padding: var(--space-4) var(--space-8); }
  .mc-domain h2 { font-size: 1rem; font-weight: 600; margin: 0 0 var(--space-3); text-transform: capitalize; }
  .mc-table { width: 100%; border-collapse: collapse; font-size: .8125rem; }
  .mc-table th { text-align: left; padding: var(--space-2) var(--space-3); background: var(--color-surface-2); font-weight: 600; font-size: .75rem; text-transform: uppercase; letter-spacing: .05em; color: var(--color-text-muted); }
  .mc-table td { padding: var(--space-2) var(--space-3); border-bottom: 1px solid var(--color-border); vertical-align: top; }
  .mc-table tr:last-child td { border-bottom: none; }
  .mc-table code { font-family: 'DM Mono', monospace; font-size: .75rem; background: var(--color-surface-2); padding: 2px 5px; border-radius: 3px; }
  .mc-notes { color: var(--color-text-muted); font-size: .75rem; font-style: italic; }
  .bmie-badge { display: inline-block; font-size: .6875rem; font-weight: 600; padding: 2px 7px; border-radius: 99px; }
  .bmie-badge--ok   { background: #d1fae5; color: #065f46; }
  .bmie-badge--warn { background: #fef3c7; color: #92400e; }
  .bmie-badge--info { background: #dbeafe; color: #1e40af; }
  .bmie-badge--muted{ background: var(--color-surface-2); color: var(--color-text-muted); }
  .bmie-badge--fail { background: #fee2e2; color: #991b1b; }
  .mc-back { font-size: .8125rem; color: var(--color-text-muted); text-decoration: none; display: inline-flex; align-items: center; gap: 4px; padding: var(--space-3) var(--space-8); }
  .mc-back:hover { color: var(--color-text); }
</style>
</head>
<main class="mag-clean-page" data-page="catalogo-kpis">
<a class="mc-back" href="admin.php"><span class="material-symbols-rounded" style="font-size:16px">arrow_back</span> Voltar ao Admin</a>

<div class="mc-header">
  <h1><span class="material-symbols-rounded" style="font-size:1.1em;vertical-align:-.15em">hub</span> Catálogo de Métricas — BIomie</h1>
  <p>Fonte autoritativa: <code>app/DataPlatform/Semantic/MetricRegistry.php</code> &mdash; gerado em <?php echo htmlspecialchars(date('d/m/Y H:i'), ENT_QUOTES); ?></p>
</div>

<div class="mc-totals">
  <div class="mc-stat"><div class="mc-stat__label">Total KPIs</div><div class="mc-stat__value"><?php echo $total; ?></div></div>
  <div class="mc-stat"><div class="mc-stat__label">Active</div><div class="mc-stat__value" style="color:#065f46"><?php echo $active; ?></div></div>
  <div class="mc-stat"><div class="mc-stat__label">Draft</div><div class="mc-stat__value" style="color:#92400e"><?php echo $draft; ?></div></div>
  <div class="mc-stat"><div class="mc-stat__label">Domínios</div><div class="mc-stat__value"><?php echo count($byDomain); ?></div></div>
</div>

<div class="admin-filterbar" style="padding:0 var(--space-8) var(--space-3);">
  <input type="search" id="metricSearch" placeholder="Buscar KPI" aria-label="Buscar KPI" />
  <select id="metricDomain" aria-label="Filtrar dominio">
    <option value="">Todos os dominios</option>
    <?php foreach (array_keys($byDomain) as $domainOption): ?>
      <option value="<?php echo htmlspecialchars($domainOption, ENT_QUOTES); ?>"><?php echo htmlspecialchars($domainOption, ENT_QUOTES); ?></option>
    <?php endforeach; ?>
  </select>
  <select id="metricStatus" aria-label="Filtrar status">
    <option value="">Todos os status</option>
    <option value="active">active</option>
    <option value="draft">draft</option>
    <option value="deprecated">deprecated</option>
  </select>
</div>

<?php foreach ($byDomain as $domain => $kpis): ?>
<div class="mc-domain">
  <h2><?php echo htmlspecialchars(ucfirst($domain), ENT_QUOTES); ?> <span style="font-weight:400;color:var(--color-text-muted);font-size:.875rem">(<?php echo count($kpis); ?>)</span></h2>
  <table class="mc-table">
    <thead>
      <tr>
        <th>ID</th><th>Nome</th><th>Status</th><th>Fonte</th>
        <th>Tabela</th><th>Data oficial</th><th>Permissão</th><th>Freshness</th><th>Observações</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($kpis as $def): ?>
      <tr data-metric-row="1"
          data-domain="<?php echo htmlspecialchars($def->domain, ENT_QUOTES); ?>"
          data-status="<?php echo htmlspecialchars($def->status, ENT_QUOTES); ?>"
          data-source="<?php echo htmlspecialchars($def->sourceOfTruth, ENT_QUOTES); ?>"
          data-permission="<?php echo htmlspecialchars($def->permission, ENT_QUOTES); ?>"
          data-search="<?php echo htmlspecialchars(mb_strtolower($def->id . ' ' . $def->name . ' ' . $def->domain . ' ' . $def->sourceTable), ENT_QUOTES); ?>"
          data-kpi-detail="<?php echo htmlspecialchars(json_encode($def->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES); ?>"
          tabindex="0" style="cursor:pointer">
        <td><code><?php echo htmlspecialchars($def->id, ENT_QUOTES); ?></code></td>
        <td><?php echo htmlspecialchars($def->name, ENT_QUOTES); ?></td>
        <td><?php echo $_fmtStatus($def->status); ?></td>
        <td><?php echo $_fmtSource($def->sourceOfTruth); ?></td>
        <td><code><?php echo htmlspecialchars($def->sourceTable ?: '—', ENT_QUOTES); ?></code></td>
        <td><code style="font-size:.7rem"><?php echo htmlspecialchars($def->officialDate ?: '—', ENT_QUOTES); ?></code></td>
        <td><code><?php echo htmlspecialchars($def->permission, ENT_QUOTES); ?></code></td>
        <td><?php echo htmlspecialchars($def->freshnessDomain, ENT_QUOTES); ?></td>
        <td class="mc-notes"><?php echo htmlspecialchars($def->notes ?: '', ENT_QUOTES); ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endforeach; ?>

<div class="biomie-drawer" id="metricDrawer" role="dialog" aria-modal="true" aria-labelledby="metricDrawerTitle" aria-hidden="true">
  <div class="biomie-drawer__header">
    <div>
      <div class="biomie-drawer__title" id="metricDrawerTitle">Detalhe da métrica</div>
      <div class="biomie-drawer__subtitle" id="metricDrawerSubtitle"></div>
    </div>
    <button type="button" id="metricDrawerClose" class="biomie-drawer__close" aria-label="Fechar">
      <span class="material-symbols-rounded">close</span>
    </button>
  </div>
  <div class="biomie-drawer__body" id="metricDrawerBody"></div>
</div>
</main>
<script>
(function () {
  function esc(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }
  var search = document.getElementById('metricSearch');
  var domain = document.getElementById('metricDomain');
  var status = document.getElementById('metricStatus');
  var drawer = document.getElementById('metricDrawer');
  var lastFocus = null;

  function applyFilters() {
    var q = String(search && search.value || '').trim().toLowerCase();
    var d = String(domain && domain.value || '');
    var s = String(status && status.value || '');
    document.querySelectorAll('[data-metric-row="1"]').forEach(function (row) {
      row.hidden = !(!q || String(row.dataset.search || '').indexOf(q) !== -1)
        || (!!d && row.dataset.domain !== d)
        || (!!s && row.dataset.status !== s);
    });
  }

  function openMetric(row) {
    var detail = {};
    try { detail = JSON.parse(row.dataset.kpiDetail || '{}'); } catch (e) {}
    lastFocus = row;
    document.getElementById('metricDrawerTitle').textContent = detail.name || detail.id || 'Métrica';
    document.getElementById('metricDrawerSubtitle').textContent = detail.id || '';
    document.getElementById('metricDrawerBody').innerHTML = [
      '<div class="biomie-drawer__stats">',
      '<div class="biomie-drawer__stat"><div class="biomie-drawer__stat-label">Domínio</div><div class="biomie-drawer__stat-value">' + esc(detail.domain || '-') + '</div></div>',
      '<div class="biomie-drawer__stat"><div class="biomie-drawer__stat-label">Status</div><div class="biomie-drawer__stat-value">' + esc(detail.status || '-') + '</div></div>',
      '<div class="biomie-drawer__stat"><div class="biomie-drawer__stat-label">Fonte</div><div class="biomie-drawer__stat-value">' + esc(detail.source_of_truth || '-') + '</div></div>',
      '<div class="biomie-drawer__stat"><div class="biomie-drawer__stat-label">Freshness</div><div class="biomie-drawer__stat-value">' + esc(detail.freshness_domain || '-') + '</div></div>',
      '</div>',
      '<div class="biomie-drawer__section">',
      '<div class="biomie-drawer__section-title">Contrato</div>',
      '<table style="width:100%;border-collapse:collapse;font-size:.8125rem">',
      '<tr><th style="text-align:left;padding:6px 8px;background:var(--color-surface-2);font-size:.75rem">Campo</th><th style="text-align:left;padding:6px 8px;background:var(--color-surface-2);font-size:.75rem">Valor</th></tr>',
      '<tr><td style="padding:6px 8px;border-bottom:1px solid var(--color-border)">Tabela</td><td style="padding:6px 8px;border-bottom:1px solid var(--color-border)"><code>' + esc(detail.source_table || '-') + '</code></td></tr>',
      '<tr><td style="padding:6px 8px;border-bottom:1px solid var(--color-border)">Valor</td><td style="padding:6px 8px;border-bottom:1px solid var(--color-border)">' + esc(detail.value_column || '-') + '</td></tr>',
      '<tr><td style="padding:6px 8px;border-bottom:1px solid var(--color-border)">Data oficial</td><td style="padding:6px 8px;border-bottom:1px solid var(--color-border)">' + esc(detail.official_date || '-') + '</td></tr>',
      '<tr><td style="padding:6px 8px;border-bottom:1px solid var(--color-border)">Permissão</td><td style="padding:6px 8px;border-bottom:1px solid var(--color-border)">' + esc(detail.permission || '-') + '</td></tr>',
      '<tr><td style="padding:6px 8px;border-bottom:1px solid var(--color-border)">Inclusão</td><td style="padding:6px 8px;border-bottom:1px solid var(--color-border)">' + esc(detail.include_rule || '-') + '</td></tr>',
      '<tr><td style="padding:6px 8px;border-bottom:1px solid var(--color-border)">Exclusão</td><td style="padding:6px 8px;border-bottom:1px solid var(--color-border)">' + esc(detail.exclude_rule || '-') + '</td></tr>',
      '<tr><td style="padding:6px 8px">Observações</td><td style="padding:6px 8px">' + esc(detail.notes || '-') + '</td></tr>',
      '</table></div>',
    ].join('');
    drawer.classList.add('is-open');
    drawer.setAttribute('aria-hidden', 'false');
    document.getElementById('metricDrawerClose').focus();
  }

  function closeMetric() {
    drawer.classList.remove('is-open');
    drawer.setAttribute('aria-hidden', 'true');
    if (lastFocus) lastFocus.focus();
  }

  [search, domain, status].forEach(function (el) { if (el) el.addEventListener('input', applyFilters); });
  document.addEventListener('click', function (event) {
    var row = event.target.closest('[data-metric-row="1"]');
    if (row) openMetric(row);
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && drawer && drawer.classList.contains('is-open')) closeMetric();
    if (event.key === 'Enter') {
      var row = event.target.closest('[data-metric-row="1"]');
      if (row) openMetric(row);
    }
  });
  document.getElementById('metricDrawerClose').addEventListener('click', closeMetric);
}());
</script>

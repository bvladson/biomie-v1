(function () {
  'use strict';
  window.BIomieFlightPlan = window.BIomieFlightPlan || {};

  function updateMeta(p, src) {
    const period = p.period || {}, meta = p.meta || {};
    const dom = window.BIomieFlightPlan.Dom;
    const formatters = window.BIomieFlightPlan.Formatters;
    if (!dom || !formatters) return;

    const syncTime = meta.freshness_at || meta.last_sync || meta.generated_at;
    const at = syncTime ? new Date(syncTime).toLocaleString('pt-BR') : '-';
    
    dom.setText('[data-bind="period-label"]', period.label || '-');
    dom.setText('[data-bind="summary-mode"]', `${p.mode || '-'} | ${src}`);
    dom.setText('[data-bind="last-sync"]', at);
    var _pb = meta.realized_period_basis || meta.period_basis || '';
    var _pbLabel = _pb === 'data_competencia' ? 'Vendas faturadas: notas autorizadas por competencia'
                 : _pb === 'issue_date'       ? 'Vendas faturadas: data de emissao do pedido'
                 : _pb !== ''                 ? 'Base: ' + _pb
                 : '-';
    dom.setText('[data-bind="summary-period-basis"]', _pbLabel);
    dom.setText('[data-bind="sync-source"]', formatters.humanizeServedFrom(meta.served_from || p.source || '', src));
    dom.setText('[data-bind="sync-coverage"]', formatters.resolveCoverageLabel(p));
    dom.setText('[data-bind="sync-reconciled-until"]', formatters.formatMetaDate(meta.reconciled_until || p.reconciled_until || ''));
    if (window.renderSyncStateBadges) {
      dom.setHtml('[data-bind="sync-warning"]', window.renderSyncStateBadges(p, src) || '<span class="mag-status-chip mag-status-live">Leitura principal</span>');
    } else {
      dom.setHtml('[data-bind="sync-warning"]', '<span class="mag-status-chip mag-status-live">Leitura principal</span>');
    }
  }

  function renderRiskBadge(s) {
    const b = document.getElementById('flightRiskBadge');
    if (!b) return;
    const formatters = window.BIomieFlightPlan.Formatters;
    if (formatters) {
      b.className = formatters.statusClass(s);
      b.textContent = formatters.statusLabel(s);
    }
  }

  function updateTeam(p, progress) {
    const t = p.team || {};
    const dom = window.BIomieFlightPlan.Dom;
    const formatters = window.BIomieFlightPlan.Formatters;
    if (!dom || !formatters) return;

    dom.setText('[data-bind="fp-meta-total"]', formatters.fmt(t.target_period));
    var activeTarget = Number(t.active_target || 0);
    var maximumTarget = Number(t.maximum_target || t.target_period || 0);
    var activeText = activeTarget > 0
      ? 'Desafio ativo: ' + formatters.fmt(activeTarget)
      : (maximumTarget > 0 ? 'Todos os níveis conquistados · máximo ' + formatters.fmt(maximumTarget) : 'Sem níveis adicionais');
    dom.setText('[data-bind="fp-active-target"]', activeText);
    dom.setText('[data-bind="fp-realizado"]', formatters.fmt(t.realized_period));
    dom.setText('[data-bind="fp-gap-total"]', formatters.fmt(t.gap_to_target));
    dom.setText('[data-bind="fp-atingimento-total"]', `Atingimento-base ${formatters.fmtN(t.attainment_pct, 1)}%`);
    dom.setText('[data-bind="fp-projecao"]', formatters.fmt(t.forecast_period));
    dom.setText('[data-bind="fp-carteira-valor"]', formatters.fmt(t.pipeline_value || 0));
    dom.setText('[data-bind="fp-pedidos-carteira"]', formatters.fmtN(t.pipeline_orders_count || 0));

    // Botão "Editar metas" só para quem tem capability de edição (meta.can_edit_targets)
    if (p && p.meta && p.meta.can_edit_targets === true) {
      document.querySelectorAll('[data-fp-edit-targets]').forEach((el) => el.removeAttribute('hidden'));
    }
    dom.setText('[data-bind="fp-vendedores-risco"]', formatters.fmtN(t.vendors_no_sales || 0));
    dom.setText('[data-bind="fp-vendedores-sem-meta"]', formatters.fmtN(t.vendors_without_target || t.missing_targets_count || 0));
    dom.setText('[data-bind="fp-notas"]', `${formatters.fmtN(t.notes_count || 0)} NFs`);
    
    // Legacy mapping support
    dom.setText('[data-bind="fp-meta"]', formatters.fmt(t.target_period));
    dom.setText('[data-bind="fp-atingimento"]', `${formatters.fmtN(t.attainment_pct, 1)}%`);
    dom.setText('[data-bind="fp-nova-meta-dia"]', formatters.fmt(t.new_daily_target));
    dom.setText('[data-bind="fp-gap-meta"]', formatters.fmt(t.gap_to_target));
    dom.setText('[data-bind="fp-sem-meta"]', formatters.fmtN(t.missing_targets_count ?? p.meta?.targets?.missing_vendors_count ?? 0));
    dom.setText('[data-bind="fp-backlog"]', formatters.fmt(t.pipeline_value || 0));
    dom.setText('[data-bind="fp-cancelado"]', formatters.fmt(t.cancelled_value || 0));
    dom.setText('[data-bind="fp-ticket"]', formatters.fmt((Number(t.notes_count || 0) > 0) ? Number(t.realized_period || 0) / Number(t.notes_count || 1) : 0));
    dom.setText('[data-bind="fp-base-valor"]', 'NF autorizada');
    
    const pf = document.getElementById('flightProgressFill');
    if (pf) pf.style.width = `${Math.min(100, progress)}%`;
    renderRiskBadge(t.risk_status);
  }

  function renderForecastTriplets(team) {
    const wrap = document.getElementById('forecastTripletsWrap');
    if (!wrap) return;
    const formatters = window.BIomieFlightPlan.Formatters;
    if (!formatters) return;
    
    const fcs = team.forecasts || {base:0, probable:0, commit:0};
    const baseTgt = Number(team.target_period||0);
    const tgt = Number(team.active_target||baseTgt);
    const mx = Math.max(tgt, fcs.base, fcs.probable, fcs.commit) * 1.05;
    const track = formatters.theme('--surface-3', '#f0f0f0');
    const targetLine = formatters.theme('--text-1', '#222');
    const commitColor = formatters.theme('--text-3', '#adb5bd');
    const probableColor = formatters.theme('--state-info', '#22a3ff');
    const baseColor = formatters.theme('--page-accent', '#96140a');
    
    if (mx <= 0) {
      wrap.innerHTML = '<div style="color:var(--text-secondary);text-align:center;padding:24px;font-size:13px"><span class="material-symbols-rounded" style="font-size:32px;opacity:0.5;margin-bottom:8px">hourglass_empty</span><br>Sem carteira para projecao.</div>';
      return;
    }
    
    function makeBar(label, val, clr) {
      const p = Math.min(100, (val/mx)*100);
      const pt = tgt>0 ? Math.min(100, (tgt/mx)*100) : 0;
      return `<div style="margin-bottom:12px">
        <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px">
          <span style="color:var(--text-secondary)">${label}</span>
          <strong>${formatters.fmt(val)}</strong>
        </div>
        <div style="height:12px;background:${track};border-radius:2px;position:relative">
          <div style="position:absolute;top:0;bottom:0;left:0;width:${p}%;background:${clr};border-radius:2px"></div>
          ${tgt>0 ? `<div style="position:absolute;top:-2px;bottom:-2px;left:${pt}%;width:2px;background:${targetLine};z-index:2" title="Desafio ativo"></div>` : ''}
        </div>
      </div>`;
    }
    
    wrap.innerHTML = `
      ${makeBar('Commit', Number(fcs.commit||0), commitColor)}
      ${makeBar('Provavel', Number(fcs.probable||0), probableColor)}
      ${makeBar('Base', Number(fcs.base||0), baseColor)}
      <div style="margin-top:16px;font-size:11px;color:var(--text-secondary);text-align:right">
        Compromisso: <strong>${formatters.fmt(baseTgt)}</strong> · Desafio ativo: <strong>${formatters.fmt(tgt)}</strong>
      </div>
    `;
  }

  function renderRiskRanking(vendors) {
    const wrap = document.getElementById('flightRiskRanking');
    if (!wrap) return;
    const formatters = window.BIomieFlightPlan.Formatters;
    const dom = window.BIomieFlightPlan.Dom;
    if (!formatters || !dom) return;

    const sorted = [...vendors].filter(v => (v.risk_status !== 'OK')).sort((a,b) => {
        const w = {'SEM_META':3, 'SALA_DE_GUERRA':2, 'ATENCAO':1, 'OK':0};
        const wa = w[a.risk_status||'OK']||0;
        const wb = w[b.risk_status||'OK']||0;
        if (wa !== wb) return wb - wa;
        return Number(a.attainment_pct||0) - Number(b.attainment_pct||0);
    }).slice(0, 5);

    if (!sorted.length) {
      wrap.innerHTML = '<div style="font-size:13px;color:var(--text-secondary);padding:12px 0;">Time no alvo.</div>';
      return;
    }

    wrap.innerHTML = sorted.map(v => {
      const cls = formatters.statusClass(v.risk_status);
      const lbl = formatters.statusLabel(v.risk_status);
      return `<div class="risk-ranking-item" onclick="openDrawer('${dom.escH(dom.detailRef(v))}')" title="Ver operação">
        <div style="display:flex;flex-direction:column;gap:2px">
          <strong>${dom.escH(v.vendor_name)}</strong>
          <span style="font-size:11px;color:var(--text-secondary)">Ating: ${formatters.fmtN(v.attainment_pct,1)}%</span>
        </div>
        <span class="${cls}" style="font-size:10px;padding:2px 6px">${lbl}</span>
      </div>`;
    }).join('');
  }

  window.BIomieFlightPlan.Kpis = {
    updateMeta, updateTeam, renderRiskBadge, renderForecastTriplets, renderRiskRanking
  };
})();

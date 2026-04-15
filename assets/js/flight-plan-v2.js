/*
 * Plano de Voo – v2.5
 * - Carga rápida de metas (MySQL) + carga completa (Omie) em paralelo
 * - Deduplicação de vendedores por nome
 * - Colunas alinhadas com a planilha: Diferença R$, Diferença %, Ideal p/ período, Real x Ideal
 * - Loading states visuais
 */
(function () {
  let gaugeChart = null;
  let lineChart = null;
  let _lastPayload = null;
  let _fullFlightPlanLoaded = false;

  function fmt(value) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value || 0));
  }
  function fmtN(value, d) {
    d = d || 0;
    return new Intl.NumberFormat('pt-BR', { minimumFractionDigits: d, maximumFractionDigits: d }).format(Number(value || 0));
  }
  function cssVar(n) { return getComputedStyle(document.documentElement).getPropertyValue(n).trim(); }
  function theme(n, fallback) { return cssVar(n) || fallback; }
  function hexRgba(hex, a) {
    const c = hex.replace('#','');
    const b = c.length===3?parseInt(c.split('').map(x=>x+x).join(''),16):parseInt(c,16);
    return `rgba(${(b>>16)&255},${(b>>8)&255},${b&255},${a})`;
  }
  function toLocalIso(date) {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
  }
  function todayIso() { return toLocalIso(new Date()); }
  function setDates(s,e) {
    const ds=document.getElementById('dateStart'), de=document.getElementById('dateEnd');
    if(ds)ds.value=s; if(de)de.value=e;
  }
  function getDates() {
    return { start:document.getElementById('dateStart')?.value||'', end:document.getElementById('dateEnd')?.value||'' };
  }
  function preset(p) {
    const n=new Date();
    if(p==='month'){setDates(toLocalIso(new Date(n.getFullYear(),n.getMonth(),1)),toLocalIso(new Date(n.getFullYear(),n.getMonth()+1,0)));}
    if(p==='year'){setDates(`${n.getFullYear()}-01-01`,todayIso());}
    if(p==='rolling12'){setDates(toLocalIso(new Date(n.getFullYear()-1,n.getMonth(),n.getDate()+1)),todayIso());}
  }
  function apiUrl(path,force) {
    const p=new URLSearchParams(), r=getDates();
    if(r.start)p.set('start',r.start); if(r.end)p.set('end',r.end); if(force)p.set('force','1');
    return path+(p.toString()?`?${p}`:'');
  }
  async function fetchJ(url, options = {}) {
    return await window.fetchJson(url, options);
  }
  function pctOf(r,t) { return (!t||t<=0)?0:(Number(r)/Number(t))*100; }
  function setText(sel,val) { document.querySelectorAll(sel).forEach(el=>{el.textContent=val;}); }
  function setHtml(sel,val) { document.querySelectorAll(sel).forEach(el=>{el.innerHTML=val;}); }
  function escH(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
  function detailRef(v) {
    return String(v?.vendor_ref || v?.vendor_key || v?.vendor_id || '').trim();
  }
  function accentSoftColor() {
    return theme('--page-accent-soft', hexRgba(theme('--page-accent', '#96140a'), 0.18));
  }

  function formatMetaDate(value) {
    if (!value) return 'â€”';
    const parsed = new Date(String(value).length === 10 ? `${value}T12:00:00` : value);
    if (Number.isNaN(parsed.getTime())) return String(value);
    return parsed.toLocaleDateString('pt-BR');
  }

  function humanizeServedFrom(value, src) {
    const normalized = String(value || '').toLowerCase();
    if (normalized.includes('legacy_fallback')) return 'legacy-fallback';
    if (normalized.includes('legacy')) return 'legacy';
    if (normalized.includes('historical')) return 'historical-read-model';
    if (normalized.includes('snapshot')) return 'historical-read-model';
    if (normalized.includes('canonical')) return 'canonical-db';

    const sourceLabel = String(src || '').toLowerCase();
    if (sourceLabel.includes('fallback')) return 'legacy-fallback';
    if (sourceLabel.includes('historico')) return 'historical-read-model';
    if (sourceLabel.includes('plano')) return 'canonical-db';
    return value ? String(value) : '-';
  }

  function resolveCoverageLabel(payload) {
    const meta = payload?.meta || {};
    const actual = meta.coverage_actual || {};
    const start = actual.start || meta.coverage_start || payload?.coverage_start || payload?.period?.start || 'â€”';
    const end = actual.end || meta.coverage_end || payload?.coverage_end || payload?.period?.end || 'â€”';
    return `${formatMetaDate(start)} ate ${formatMetaDate(end)}`;
  }

  function renderSyncStateBadges(payload, src) {
    const meta = payload?.meta || {};
    const badges = [];
    const servedFrom = humanizeServedFrom(meta.served_from || payload?.source || '', src);
    const signalStatus = String(meta.signal_status || '').toLowerCase();
    const openDivergences = Number(meta.open_divergences || 0);

    if (servedFrom === 'canonical-db') {
      badges.push('<span class="mag-status-chip mag-status-live">Leitura principal</span>');
    } else if (servedFrom === 'historical-read-model') {
      badges.push('<span class="mag-status-chip mag-status-warning">Snapshot historico</span>');
    } else if (servedFrom === 'legacy-fallback') {
      badges.push('<span class="mag-status-chip mag-status-warning">Fallback legado</span>');
    } else if (servedFrom === 'legacy') {
      badges.push('<span class="mag-status-chip mag-status-demo">Leitura legado</span>');
    }

    if (signalStatus === 'partial_window') {
      badges.push('<span class="mag-status-chip mag-status-warning">Cobertura parcial</span>');
    } else if (signalStatus === 'sync_missing') {
      badges.push('<span class="mag-status-chip mag-status-warning">Sync ausente</span>');
    } else if (signalStatus === 'empty_after_sync') {
      badges.push('<span class="mag-status-chip mag-status-demo">Periodo vazio</span>');
    }

    if (meta.has_retry_or_delay) {
      badges.push('<span class="mag-status-chip mag-status-warning">Pipeline com atraso</span>');
    }
    if (openDivergences > 0) {
      badges.push(`<span class="mag-status-chip mag-status-warning">${fmtN(openDivergences)} divergencia(s)</span>`);
    }

    return badges.join(' ');
  }

  function statusLabel(s) {
    if(s==='OK') return 'No alvo';
    if(s==='ATENCAO') return 'Atencao';
    if(s==='SALA_DE_GUERRA') return 'Risco';
    return 'Sem meta';
  }
  function statusClass(s) {
    if(s==='OK') return 'status-chip status-chip--ok';
    if(s==='ATENCAO') return 'status-chip status-chip--attention';
    if(s==='SALA_DE_GUERRA') return 'status-chip status-chip--danger';
    return 'status-chip status-chip--muted';
  }

  // ─── Bullet Chart ───────────────────────────────────────
  function renderBulletChart(progress) {
    const wrap = document.getElementById('achievementBulletWrap');
    if(!wrap) return;

    const team = _lastPayload ? _lastPayload.team || {} : {};
    const target = Number(team.target_period || 0);
    const realized = Number(team.realized_period || 0);
    const forecast = Number(team.forecast_period || 0);
    const gap = Number(team.gap_to_target || Math.max(0, target - realized));

    if (target <= 0) {
      wrap.innerHTML = '<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;text-align:center;padding:12px;color:var(--text-secondary)"><span class="material-symbols-rounded" style="font-size:28px;margin-bottom:6px">target</span><span style="font-size:13px;line-height:1.4">Sem meta no periodo.</span><br><a href="plano_voo_metas.php" class="btn-primary btn-sm" style="margin-top:10px;font-size:12px">Abrir metas</a></div>';
      return;
    }

    const maxVal = Math.max(target, realized, forecast) * 1.05; // 5% margin for visual breathing
    const pctRealized = Math.min(100, (realized / maxVal) * 100);
    const pctTarget = Math.min(100, (target / maxVal) * 100);
    const pctForecast = Math.min(100, (forecast / maxVal) * 100);
    const pct70 = Math.min(100, (target * 0.70 / maxVal) * 100);
    const pct85 = Math.min(100, (target * 0.85 / maxVal) * 100);

    const s = team.risk_status || (progress >= 100 ? 'OK' : 'ATENCAO');
    const badgeHtml = '<span class="' + statusClass(s) + '">' + statusLabel(s) + '</span>';
    const track = theme('--surface-3', '#f0f0f0');
    const dangerSoft = theme('--danger-soft', 'rgba(220,53,69,0.15)');
    const warningSoft = theme('--warning-soft', 'rgba(255,193,7,0.15)');
    const successSoft = theme('--success-soft', 'rgba(40,167,69,0.15)');
    const accent = theme('--page-accent', '#96140a');
    const info = theme('--state-info', '#22a3ff');
    const textPrimary = theme('--text-1', '#222');
    const divider = theme('--border', 'rgba(0,0,0,0.08)');

    wrap.innerHTML = `
      <div style="flex-grow:1; display:flex; flex-direction:column; justify-content:center;">
        <div style="position:relative; height:32px; background:${track}; border-radius:4px; overflow:hidden; margin-bottom:24px; box-shadow:inset 0 1px 3px rgba(0,0,0,0.05)">
          <div style="position:absolute; top:0; bottom:0; left:0; width:${pct70}%; background:${dangerSoft}"></div>
          <div style="position:absolute; top:0; bottom:0; left:${pct70}%; width:${pct85-pct70}%; background:${warningSoft}"></div>
          <div style="position:absolute; top:0; bottom:0; left:${pct85}%; right:0; background:${successSoft}"></div>
          <div style="position:absolute; top:6px; bottom:6px; left:0; width:${pctRealized}%; background:${accent}; box-shadow:1px 0 2px rgba(0,0,0,0.1); border-radius:0 2px 2px 0; z-index:2; transition:width 0.5s ease" title="Realizado"></div>
          <div style="position:absolute; top:4px; bottom:4px; left:${pctForecast}%; width:3px; background:${info}; border-radius:1px; z-index:3; box-shadow:0 0 2px rgba(0,0,0,0.3)" title="Projeção do mês"></div>
          <div style="position:absolute; top:0; bottom:0; left:${pctTarget}%; width:2px; background:${textPrimary}; z-index:4; box-shadow:0 0 2px rgba(0,0,0,0.5)" title="Meta do período"></div>
        </div>
        
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; font-size:13px; margin-bottom:16px;">
          <div><span style="color:var(--text-secondary)">Real:</span> <strong>${fmt(realized)}</strong></div>
          <div><span style="color:var(--text-secondary)">Meta:</span> <strong>${fmt(target)}</strong></div>
          <div><span style="color:var(--text-secondary)">Gap:</span> <strong>${fmt(gap)}</strong></div>
          <div><span style="color:var(--text-secondary)">Ating.:</span> <strong>${fmtN(progress,1)}%</strong></div>
        </div>

        <div style="padding-top:16px; border-top:1px solid ${divider}; display:flex; justify-content:space-between; align-items:center;">
          <div style="font-size:13px; display:flex; align-items:center; gap:6px;">
            <span style="display:inline-block;width:10px;height:10px;background:${info};border-radius:2px;"></span>
            <span style="color:var(--text-secondary)">Proj.:</span> <strong style="font-size:15px">${fmt(forecast)}</strong>
          </div>
          <div>${badgeHtml}</div>
        </div>
      </div>
    `;
  }

  // ─── Cenários de Fechamento (Projeções) ───────────────────
  function renderForecastTriplets(team) {
    const wrap = document.getElementById('forecastTripletsWrap');
    if(!wrap) return;
    const fcs = team.forecasts || {base:0, probable:0, commit:0};
    const tgt = Number(team.target_period||0);
    const mx = Math.max(tgt, fcs.base, fcs.probable, fcs.commit) * 1.05;
    const track = theme('--surface-3', '#f0f0f0');
    const targetLine = theme('--text-1', '#222');
    const commitColor = theme('--text-3', '#adb5bd');
    const probableColor = theme('--state-info', '#22a3ff');
    const baseColor = theme('--page-accent', '#96140a');
    
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
          <strong>${fmt(val)}</strong>
        </div>
        <div style="height:12px;background:${track};border-radius:2px;position:relative">
          <div style="position:absolute;top:0;bottom:0;left:0;width:${p}%;background:${clr};border-radius:2px"></div>
          ${tgt>0 ? `<div style="position:absolute;top:-2px;bottom:-2px;left:${pt}%;width:2px;background:${targetLine};z-index:2" title="Meta"></div>` : ''}
        </div>
      </div>`;
    }
    
    wrap.innerHTML = `
      ${makeBar('Commit', Number(fcs.commit||0), commitColor)}
      ${makeBar('Provavel', Number(fcs.probable||0), probableColor)}
      ${makeBar('Base', Number(fcs.base||0), baseColor)}
      <div style="margin-top:16px;font-size:11px;color:var(--text-secondary);text-align:right">
        Meta: <strong>${fmt(tgt)}</strong>
      </div>
    `;
  }

  // ─── Ranking de Risco ────────────────────────────────────
  function renderRiskRanking(vendors) {
    const wrap = document.getElementById('flightRiskRanking');
    if(!wrap) return;
    const sorted = [...vendors].filter(v => (v.risk_status !== 'OK')).sort((a,b) => {
        const w = {'SEM_META':3, 'SALA_DE_GUERRA':2, 'ATENCAO':1, 'OK':0};
        const wa = w[a.risk_status||'OK']||0;
        const wb = w[b.risk_status||'OK']||0;
        if(wa !== wb) return wb - wa;
        return Number(a.attainment_pct||0) - Number(b.attainment_pct||0);
    }).slice(0, 5);

    if(!sorted.length) {
      wrap.innerHTML = '<div style="font-size:13px;color:var(--text-secondary);padding:12px 0;">Time no alvo.</div>';
      return;
    }

    wrap.innerHTML = sorted.map(v => {
      const cls = statusClass(v.risk_status);
      const lbl = statusLabel(v.risk_status);
      return `<div class="risk-ranking-item" onclick="openDrawer('${escH(detailRef(v))}')" title="Ver operação">
        <div style="display:flex;flex-direction:column;gap:2px">
          <strong>${escH(v.vendor_name)}</strong>
          <span style="font-size:11px;color:var(--text-secondary)">Ating: ${fmtN(v.attainment_pct,1)}%</span>
        </div>
        <span class="${cls}" style="font-size:10px;padding:2px 6px">${lbl}</span>
      </div>`;
    }).join('');
  }

  // ─── Line Chart ─────────────────────────────────────────
  function renderLine(labels,realized,target) {
    let cv=document.getElementById('flightPlanChart');
    if(!cv&&!document.getElementById('flightPlanChartWrap')) return;
    if(!window.Chart) return;
    const accent=theme('--page-accent','#96140a');
    const soft=theme('--page-accent-soft','rgba(150,20,10,0.12)');
    const sec=theme('--text-3','#828282');
    const grid=theme('--chart-grid','rgba(0,0,0,0.08)');
    const text=theme('--text-2','#4b5263');
    const tooltipBg=theme('--chart-tooltip-bg','#ffffff');
    const tooltipBorder=theme('--chart-tooltip-border','rgba(0,0,0,0.12)');
    const tooltipTitle=theme('--chart-tooltip-title','#111318');
    const tooltipText=theme('--chart-tooltip-text','#4b5263');
    const secSoft=hexRgba(sec.startsWith('#')?sec:'#828282',0.12);

    const team = _lastPayload ? _lastPayload.team || {} : {};
    const wrap = document.getElementById('flightPlanChartWrap');
    if (Number(team.target_period || 0) <= 0) {
        if(wrap) wrap.innerHTML = '<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:260px;text-align:center;color:var(--text-secondary);padding:20px;"><span class="material-symbols-rounded" style="font-size:40px;margin-bottom:12px;opacity:0.5">query_stats</span><span>Grafico liberado assim que a meta entrar.</span></div>';
        return;
    }

    if(wrap && !wrap.querySelector('canvas')) {
      wrap.innerHTML = '<canvas id="flightPlanChart"></canvas>';
      cv = document.getElementById('flightPlanChart');
    }

    const accRealized = [];
    let sumR = 0;
    for (let v of realized) { sumR += v; accRealized.push(sumR); }

    const accTarget = [];
    let sumT = 0;
    for (let v of target) { sumT += v; accTarget.push(sumT); }

    if(lineChart) lineChart.destroy();
    lineChart=new Chart(cv.getContext('2d'),{type:'line',data:{labels,datasets:[
      {label:'Real',data:accRealized,borderColor:accent,backgroundColor:soft,fill:true,tension:0.35,pointBackgroundColor:accent,pointBorderColor:theme('--bg-card','#ffffff'),pointBorderWidth:2},
      {label:'Meta',data:accTarget,borderColor:sec,backgroundColor:'transparent',borderDash:[4,4],fill:false,tension:0.3,pointBackgroundColor:sec,pointBorderColor:theme('--bg-card','#ffffff'),pointBorderWidth:2}
    ]},options:{responsive:true,resizeDelay:100,maintainAspectRatio:false,plugins:{legend:{position:'top',labels:{color:text}},tooltip:{backgroundColor:tooltipBg,borderColor:tooltipBorder,borderWidth:1,titleColor:tooltipTitle,bodyColor:tooltipText}},scales:{x:{grid:{display:false},ticks:{color:text},border:{color:grid}},y:{beginAtZero:true,ticks:{color:text,callback(v){return fmt(v);}},grid:{color:grid},border:{color:grid}}}}});
  }

  window.applyFilters = applyFilters;
  window.preset = preset;

  // ─── Drawer Modal Logic ─────────────────────────────────
  let drawerChart=null;
  window.openDrawer = async function(vId) {
    if(!String(vId || '').trim() || vId === 'undefined') {
       alert('Este vendedor não possui identificador para detalhamento.');
       return;
    }
    const bg = document.getElementById('flightDrawerBackdrop');
    const panel = document.getElementById('flightDrawer');
    if(bg) bg.style.display = 'block';
    if(panel) { panel.style.display = 'flex'; setTimeout(()=>panel.style.right = '0',10); }

    document.getElementById('drawerVendorName').textContent = 'Abrindo vendedor...';
    document.getElementById('drawerVendorStatus').textContent = '';
    document.getElementById('drawerSyncing').style.display = 'inline-block';

    const r = getDates();
    try {
      const data = await fetchJ('api/flight_plan_vendor_detail.php?vendor_id='+encodeURIComponent(vId)+(r.start?'&start='+r.start:'')+(r.end?'&end='+r.end:''));
      const v = data.vendor || {};
      
      document.getElementById('drawerVendorName').textContent = v.vendor_name || 'Desconhecido';
      document.getElementById('drawerVendorStatus').textContent = v.risk_status ? statusLabel(v.risk_status) : '-';
      document.getElementById('drawerRealized').textContent = fmt(v.realized_period);
      document.getElementById('drawerTarget').textContent = fmt(v.target_period);
      document.getElementById('drawerGap').textContent = fmt(v.gap_to_target);
      document.getElementById('drawerPct').textContent = fmtN(v.attainment_pct, 1) + '%';

    const fw = document.getElementById('drawerForecastWrap');
    if(fw) {
      const f = v.forecasts || {base: v.forecast_period||0, probable: v.forecast_period||0, commit: v.forecast_period||0};
      const m = Math.max(v.target_period||0, f.base, f.probable, f.commit)*1.05 || 1;
      const commitColor = theme('--text-3', '#adb5bd');
      const probableColor = theme('--state-info', '#22a3ff');
      const baseColor = theme('--page-accent', '#96140a');
      const mak = (l, c, val) => `<div class="detail-drawer-forecast-row">
          <div class="detail-drawer-forecast-label">${l}</div>
          <div class="detail-drawer-forecast-track"><div class="detail-drawer-forecast-fill" style="--fill-width:${Math.round((val/m)*100)}%;--fill-color:${c}"></div></div>
          <div class="detail-drawer-forecast-value">${fmt(val)}</div>
        </div>`;
        fw.innerHTML = mak('Commit', commitColor, f.commit) + mak('Provavel', probableColor, f.probable) + mak('Base', baseColor, f.base);
      }

      const cv = document.getElementById('drawerDailyChart');
      if(cv) {
        if(drawerChart) drawerChart.destroy();
        const ds = data.daily_sales || [];
        const lbls = ds.map(d=>(d.date||'').slice(8,10)+'/'+(d.date||'').slice(5,7));
        const vals = ds.map(d=>Number(d.gross_revenue||0));
        drawerChart = new Chart(cv.getContext('2d'), {type:'bar', data:{labels:lbls, datasets:[{label:'NF', data:vals, backgroundColor:accentSoftColor(), borderRadius:2, barPercentage: 0.7}]}, options:{responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false},tooltip:{backgroundColor:tooltipBg,borderColor:tooltipBorder,borderWidth:1,titleColor:tooltipTitle,bodyColor:tooltipText}}, scales:{x:{grid:{display:false}, ticks:{font:{size:9},color:text},border:{color:grid}}, y:{beginAtZero:true, ticks:{color:text,callback(v){let m=v/1000;return m>=1?m+'k':v;}},grid:{color:grid},border:{color:grid}}}}});
      }

      const ow = document.getElementById('drawerOrdersList');
      if(ow) {
        const invoices = data.invoices || [];
        const pipeline = data.pipeline_orders || [];
        const cancelled = data.cancelled_invoices || [];
        const renderList = (items, title, empty, kind) => {
          if(!items.length) return `<div style="font-size:12px;color:var(--text-secondary);padding:10px 0">${empty}</div>`;
          return `<h4 style="font-size:13px;margin:12px 0 8px">${title}</h4>` + items.map(x=>{
            const refDate = x.status_date || x.issue_date || '';
            const dateLabel = refDate ? refDate.slice(8,10)+'/'+refDate.slice(5,7) : '--/--';
            return `<div class="detail-drawer-list-item"><div style="display:flex;flex-direction:column;gap:4px"><strong>${escH(x.cliente||'')}</strong><span style="font-size:11px;color:var(--text-secondary)">${dateLabel} | ${kind} ${x.codigo||'-'}</span></div><div style="text-align:right"><div style="font-size:13px;font-weight:700">${fmt(x.merchandise_total||x.total)}</div><small style="color:var(--text-secondary)">merc.</small></div></div>`;
          }).join('');
        };
        ow.innerHTML = renderList(invoices, 'NF autorizadas', 'Nenhuma NF autorizada no periodo.', 'NF')
          + renderList(pipeline, 'Carteira', 'Nenhum pedido na carteira.', 'Pedido')
          + renderList(cancelled, 'NF canceladas', 'Nenhuma NF cancelada no periodo.', 'NF');
      }

    } catch(e) {
      document.getElementById('drawerVendorName').textContent = 'Erro ao carregar';
      const ow = document.getElementById('drawerOrdersList');
      if(ow) ow.innerHTML = `<div class="module-alert module-alert--danger" style="margin-top:10px">${e.message}</div>`;
    } finally {
      document.getElementById('drawerSyncing').style.display = 'none';
    }
  };

  function closeD() {
      const panel = document.getElementById('flightDrawer');
      const bg = document.getElementById('flightDrawerBackdrop');
      if(panel) panel.style.right = '-450px';
      if(bg) setTimeout(() => bg.style.display = 'none', 300);
  }

  const cBtn = document.getElementById('closeDrawerBtn');
  const dBg = document.getElementById('flightDrawerBackdrop');
  if(cBtn) cBtn.addEventListener('click', closeD);
  if(dBg) dBg.addEventListener('click', closeD);

  // ─── Banner de qualidade de dados ──────────────────────
  function renderDataQualityBanner(payload) {
    const existing = document.getElementById('dataQualityBanner');
    if (existing) existing.remove();

    const quality = payload.data_quality;
    if (quality === 'operational') return;

    const anchor = document.getElementById('cards');
    if (!anchor) return;

    const banner = document.createElement('div');
    banner.id = 'dataQualityBanner';
      banner.style.cssText = 'margin:0 0 16px;padding:16px 20px;border-radius:14px;display:flex;align-items:flex-start;gap:12px;font-size:14px;line-height:1.5;';

    if (quality === 'meta_only') {
      banner.style.background = theme('--warning-soft', 'rgba(255,193,7,0.12)');
      banner.style.border = `1px solid ${theme('--warning-strong', '#b45309')}`;
      banner.innerHTML = `<span class="material-symbols-rounded" style="font-size:24px;color:${theme('--warning-strong', '#b45309')};flex-shrink:0;margin-top:2px">warning</span>`
        + `<div><strong style="display:block;color:${theme('--warning-strong', '#b45309')};margin-bottom:4px">Dados operacionais ausentes</strong>`
        + `<span style="color:${theme('--text-2', '#78350f')}">As metas foram carregadas, mas nenhuma NF ou pedido foi encontrado neste periodo. Realizado, projecao e atingimento estao zerados. Sincronize com o Omie antes de usar este painel para tomada de decisao.</span></div>`;
    } else {
      banner.style.background = theme('--danger-soft', 'rgba(220,53,69,0.08)');
      banner.style.border = `1px solid ${theme('--danger-strong', '#dc2626')}`;
      banner.innerHTML = `<span class="material-symbols-rounded" style="font-size:24px;color:${theme('--danger-strong', '#dc2626')};flex-shrink:0;margin-top:2px">error</span>`
        + `<div><strong style="display:block;color:${theme('--danger-strong', '#dc2626')};margin-bottom:4px">Sem dados no periodo</strong>`
        + `<span style="color:${theme('--text-2', '#7f1d1d')}">Nenhuma meta, NF ou pedido encontrado para o periodo selecionado. Verifique o intervalo de datas e sincronize com o Omie.</span></div>`;
    }

    anchor.parentNode.insertBefore(banner, anchor);
  }

  // ─── Alertas ────────────────────────────────────────────
  function renderAlerts(alerts) {
    const c=document.getElementById('flightAlerts');
    if(!c) return;
    if(!alerts||!alerts.length){c.innerHTML='<div class="module-alert module-alert--info">Sem alerta no periodo.</div>';return;}
    c.innerHTML=alerts.map(a=>`<div class="module-alert module-alert--${a.level||'info'}"><strong>${a.title||'Alerta'}</strong><span>${a.message||''}</span></div>`).join('');
  }

  function renderRiskBadge(s) {
    const b=document.getElementById('flightRiskBadge');
    if(!b)return; b.className=statusClass(s); b.textContent=statusLabel(s);
  }

  // ─── Deduplicação ─────────────────────────────────────
  function dedup(vendors) {
    const seen=new Map(), res=[];
    for(const v of(vendors||[])){
      const k=(v.vendor_name||'').trim().toLowerCase();
      if(!k||k==='sem vendedor'){res.push({...v});continue;}
      if(seen.has(k)){
        const e=res[seen.get(k)];
        e.realized_period=(Number(e.realized_period)||0)+(Number(v.realized_period)||0);
        e.orders=(Number(e.orders)||0)+(Number(v.orders)||0);
        const ei=String(e.vendor_id||''),ni=String(v.vendor_id||'');
        if(ni&&/^\d+$/.test(ni)&&(!ei||ni.length<ei.length))e.vendor_id=v.vendor_id;
        const t=Number(e.target_period)||0;
        e.attainment_pct=t>0?Math.round((e.realized_period/t)*10000)/100:0;
      } else {seen.set(k,res.length);res.push({...v});}
    }
    return res;
  }

  // ─── Enriquecer com "Ideal p/ período" ─────────────────
  function enrich(vendors, payload) {
    const team=payload.team||{};
    const elapsed=team.workdays?.elapsed_weight??0;
    const total=team.workdays?.total_weight??1;
    const ratio=total>0?elapsed/total:0;
    return vendors.map(v=>({...v,ideal_for_period:Math.round((Number(v.target_period)||0)*ratio*100)/100}));
  }

  // ─── Tabela com colunas da planilha ─────────────────────
  function renderVendorTable(vendors) {
    const tb=document.getElementById('flightVendorsTable');
    if(!tb) return;
    if(!vendors||!vendors.length){tb.innerHTML='<tr><td colspan="13" class="empty-row">Sem vendedor no periodo.</td></tr>';return;}
    tb.innerHTML=vendors.map(v=>{
      const target=Number(v.target_month||v.target_period)||0;
      const metaDia=Number(v.meta_day_base||0);
      const realized=Number(v.realized_period)||0;
      const forecast=Number(v.forecast_period)||0;
      const diff=forecast-target;
      const projPct=target>0?(forecast/target)*100:0;
      const ideal=Number(v.ideal_for_period)||0;
      const rix=Number(v.real_x_ideal||0);
      const carteiraQtd=Number(v.pipeline_orders_count||0);
      const backlog=Number(v.pipeline_value||0);
      const dc=diff>=0?'val-positive':'val-negative';
      const rc=rix>=0?'val-positive':'val-negative';
      return `<tr class="clickable-row" onclick="openDrawer('${escH(detailRef(v))}')">
        <td><div class="flight-seller"><strong style="color:var(--page-accent);text-decoration:underline;">${escH(v.vendor_name||'Sem vendedor')}</strong><small>${fmtN(v.notes_count||0)} NF · ${fmtN(carteiraQtd||0)} cart.</small></div></td>
        <td>${fmt(target)}</td>
        <td>${fmt(metaDia)}</td>
        <td>${fmt(realized)}</td>
        <td>${fmt(forecast)}</td>
        <td class="${dc}">${fmt(diff)}</td>
        <td class="${dc}">${fmtN(projPct,1)}%</td>
        <td>${fmt(ideal)}</td>
        <td class="${rc}">${fmt(rix)}</td>
        <td>${fmt(v.new_daily_target)}</td>
        <td>${fmtN(carteiraQtd||0)}</td>
        <td>${fmt(backlog)}</td>
        <td><span class="${statusClass(v.risk_status)}">${statusLabel(v.risk_status)}</span></td>
      </tr>`;
    }).join('');
  }

  // ─── Metadados e KPIs ──────────────────────────────────
  function updateMeta(p,src) {
    const period=p.period||{}, meta=p.meta||{};
    const at=meta.generated_at?new Date(meta.generated_at).toLocaleString('pt-BR'):'—';
    setText('[data-bind="period-label"]',period.label||'—');
    setText('[data-bind="summary-mode"]',`${p.mode||'—'} · ${src}`);
    setText('[data-bind="last-sync"]',at);
  }
  function updateMeta(p,src) {
    const period=p.period||{}, meta=p.meta||{};
    const syncTime=meta.freshness_at||meta.last_sync||meta.generated_at;
    const at=syncTime?new Date(syncTime).toLocaleString('pt-BR'):'â€”';
    setText('[data-bind="period-label"]',period.label||'â€”');
    setText('[data-bind="summary-mode"]',`${p.mode||'â€”'} Â· ${src}`);
    setText('[data-bind="last-sync"]',at);
    setText('[data-bind="summary-period-basis"]', meta.period_basis === 'issue_date' ? 'Base fiscal: emissao' : 'Base comercial: faturamento');
    setText('[data-bind="sync-source"]', humanizeServedFrom(meta.served_from || p.source || '', src));
    setText('[data-bind="sync-coverage"]', resolveCoverageLabel(p));
    setText('[data-bind="sync-reconciled-until"]', formatMetaDate(meta.reconciled_until || p.reconciled_until || ''));
    setHtml('[data-bind="sync-warning"]', renderSyncStateBadges(p, src) || '<span class="mag-status-chip mag-status-live">Leitura principal</span>');
  }

  function updateMeta(p,src) {
    const period=p.period||{}, meta=p.meta||{};
    const syncTime=meta.freshness_at||meta.last_sync||meta.generated_at;
    const at=syncTime?new Date(syncTime).toLocaleString('pt-BR'):'-';
    setText('[data-bind="period-label"]',period.label||'-');
    setText('[data-bind="summary-mode"]',`${p.mode||'-'} | ${src}`);
    setText('[data-bind="last-sync"]',at);
    setText('[data-bind="summary-period-basis"]', meta.period_basis === 'issue_date' ? 'Base fiscal: emissao' : 'Base comercial: faturamento');
    setText('[data-bind="sync-source"]', humanizeServedFrom(meta.served_from || p.source || '', src));
    setText('[data-bind="sync-coverage"]', resolveCoverageLabel(p));
    setText('[data-bind="sync-reconciled-until"]', formatMetaDate(meta.reconciled_until || p.reconciled_until || ''));
    setHtml('[data-bind="sync-warning"]', renderSyncStateBadges(p, src) || '<span class="mag-status-chip mag-status-live">Leitura principal</span>');
  }

  function updateTeam(p,progress) {
    const t=p.team||{};
    setText('[data-bind="fp-realizado"]',fmt(t.realized_period));
    setText('[data-bind="fp-meta"]',fmt(t.target_period));
    setText('[data-bind="fp-atingimento"]',`${fmtN(t.attainment_pct,1)}%`);
    setText('[data-bind="fp-projecao"]',fmt(t.forecast_period));
    setText('[data-bind="fp-nova-meta-dia"]',fmt(t.new_daily_target));
    setText('[data-bind="fp-gap-meta"]',fmt(t.gap_to_target));
    setText('[data-bind="fp-linha-85"]',fmt(t.line_85_value));
    setText('[data-bind="fp-restante-peso"]',fmtN(t.workdays?.remaining_weight??0,2));
    setText('[data-bind="fp-cobertura"]',`${fmtN(t.coverage_pct||0,1)}%`);
    setText('[data-bind="fp-sem-meta"]',fmtN(t.missing_targets_count ?? p.meta?.targets?.missing_vendors_count ?? 0));
    setText('[data-bind="fp-notas"]',fmtN(t.notes_count||0));
    setText('[data-bind="fp-pedidos-carteira"]',fmtN(t.pipeline_orders_count||0));
    setText('[data-bind="fp-backlog"]',fmt(t.pipeline_value||0));
    setText('[data-bind="fp-cancelado"]',fmt(t.cancelled_value||0));
    setText('[data-bind="fp-ticket"]', fmt((Number(t.notes_count||0)>0) ? Number(t.realized_period||0)/Number(t.notes_count||1) : 0));
    setText('[data-bind="fp-base-valor"]','NF autorizada');
    const pf=document.getElementById('flightProgressFill');
    if(pf) pf.style.width=`${Math.min(100,progress)}%`;
    renderRiskBadge(t.risk_status);
  }
  function renderCharts(p, progress) {
    const data = p || _lastPayload;
    if(!data) return;
    renderBulletChart(progress);
    renderForecastTriplets(data.team||{});
    renderRiskRanking(data.vendors||[]);
    const t=data.team||{};
    const days=Array.isArray(data.daily_sales)?data.daily_sales:[];
    if(!days.length){
      const wrap=document.getElementById('flightPlanChartWrap');
      if(wrap)wrap.innerHTML='<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:260px;text-align:center;color:var(--text-secondary)"><span class="material-symbols-rounded" style="font-size:40px;margin-bottom:12px;opacity:0.5">query_stats</span><span>Sem historico diario no periodo.</span></div>';
      return;
    }
    const labels=days.map(r=>{const d=r.date||'';return d?d.slice(8,10)+'/'+d.slice(5,7):'';});
    const realized=days.map(r=>Number(r.gross_revenue||0));
    const avg=(t.target_period&&t.workdays?.total_weight)?Number(t.target_period)/Number(t.workdays.total_weight||1):0;
    renderBulletChart(progress);
    renderLine(labels,realized,days.map(()=>avg));
  }

  // ─── Carga principal ───────────────────────────────────
  async function loadFlightPlan(force) {
    showLoading(true);
    let payload=null;
    try { payload=await fetchJ(apiUrl('api/flight_plan.php',force)); }
    catch(e) { console.warn('flight_plan.php indisponível, tentando fallback.',e); }

    if(payload!==null) {
      _lastPayload=payload;
      const team=payload.team||{}, progress=pctOf(team.realized_period,team.target_period);
      try{updateMeta(payload,'Plano v2');}catch(e){console.warn(e);}
      try{renderDataQualityBanner(payload);}catch(e){console.warn(e);}
      try{updateTeam(payload,progress);}catch(e){console.warn(e);}
      try{renderCharts(payload,progress);}catch(e){console.warn(e);}
      const vendors=enrich(dedup(payload.vendors||[]),payload);
      try{renderAlerts(payload.alerts||[]);}catch(e){}
      try{renderVendorTable(vendors);}catch(e){}
      try{renderTargetsForm(vendors);}catch(e){}
      _fullFlightPlanLoaded=true;
      showLoading(false);
      return;
    }

    // Fallback overview
    let data=null;
    try{data=await fetchJ(apiUrl('api/overview.php',force));}catch(e){console.error(e);}
    if(data!==null) {
      const s=data.summary||{}, r=Number(s.gross_revenue??s.revenue??0), m=Number(s.target??0);
      const fc=Number(s.forecast??r), prog=pctOf(r,m), rem=Math.max(m-r,0);
      try{updateMeta(data,'Fallback overview');}catch(e){}
      setText('[data-bind="fp-realizado"]',fmt(r));setText('[data-bind="fp-meta"]',fmt(m));
      setText('[data-bind="fp-atingimento"]',`${fmtN(prog,1)}%`);setText('[data-bind="fp-projecao"]',fmt(fc));
      setText('[data-bind="fp-nova-meta-dia"]',fmt(rem));setText('[data-bind="fp-gap-meta"]',fmt(rem));
      setText('[data-bind="fp-linha-85"]',fmt(m*0.85));setText('[data-bind="fp-restante-peso"]','—');setText('[data-bind="fp-sem-meta"]','—');
      const pf=document.getElementById('flightProgressFill');if(pf)pf.style.width=`${Math.min(100,prog)}%`;
      try{renderRiskBadge('SEM_META');}catch(e){}
      try{
        renderBulletChart(prog);
        renderForecastTriplets({});
        renderRiskRanking([]);
        const daily=Array.isArray(data.daily_sales)?data.daily_sales:[];
        renderLine(daily.map(r=>(r.date||'').slice(8,10)+'/'+(r.date||'').slice(5,7)),daily.map(r=>Number(r.gross_revenue||r.revenue||0)),daily.map(()=>(m>0&&daily.length?m/daily.length:0)));
      }catch(e){}
    }
    renderAlerts(data!==null
      ?[{level:'info',title:'Fallback legado',message:'O endpoint de Plano de Voo não respondeu. Exibindo dados do overview.'}]
      :[{level:'danger',title:'Sem conexao',message:'Nao foi possivel carregar os dados.'}]);
    renderVendorTable([]);renderTargetsForm([]);
    showLoading(false);
  }

  function showLoading(on) {
    const el=document.getElementById('loadingIndicator');
    if(el) el.style.display=on?'inline-flex':'none';
  }

  // ─── Metas ─────────────────────────────────────────────
  function currentYM() {
    const s=document.getElementById('dateStart'), v=s?.value||'';
    return v.length>=7?v.slice(0,7):toLocalIso(new Date()).slice(0,7);
  }
  function setStatus(msg,type) {
    const el=document.getElementById('targetsSaveStatus');if(!el)return;
    el.textContent=msg;el.className='module-alert module-alert--'+(type||'info');el.style.display='inline-flex';
    if(type==='success') setTimeout(()=>{el.style.display='none';},3500);
  }
  function renderTargetsForm(vendors) {
    const tb=document.getElementById('targetsFormTable'), btn=document.getElementById('saveAllTargets');
    if(!tb) return;
    const rows=(vendors||[]).filter(v=>v.vendor_id&&v.vendor_id!=='');
    const locked = !!(rows[0] && rows[0].locked);
    if(!rows.length){tb.innerHTML='<tr><td colspan="5" class="empty-row">Nenhum vendedor disponível para cadastro neste mês.</td></tr>';if(btn)btn.style.display='none';return;}
    tb.innerHTML=rows.map(v=>{
      const has=Number(v.target_period)>0;
      const cur=has?(Number(v.target_month)>0?Number(v.target_month):Number(v.target_period)):'';
      const risk = String(v.risk_status||'SEM_META');
      const stateChip = v.locked
        ? '<span class="status-chip status-chip--muted">Historico travado</span>'
        : (has
          ? `<span class="status-chip ${risk==='OK'?'status-chip--ok':(risk==='ATENCAO'?'status-chip--attention':(risk==='SALA_DE_GUERRA'?'status-chip--danger':'status-chip--muted'))}">Meta pronta</span>`
          : '<span class="status-chip status-chip--muted">Sem meta</span>');
      return `<tr data-vendor-id="${escH(v.vendor_id)}" data-vendor-name="${escH(v.vendor_name||'')}">
        <td><div class="flight-seller"><strong>${escH(v.vendor_name||'Sem nome')}</strong><small>${escH(v.vendor_id)}</small></div></td>
        <td>${has?`<span class="status-chip status-chip--ok">${fmt(v.target_month||v.target_period)}</span>`:'<span class="status-chip status-chip--muted">Sem meta</span>'}</td>
        <td><input type="number" class="filter-input target-input" data-vendor-id="${escH(v.vendor_id)}" placeholder="${has?fmtN(cur,2):'ex: 80000'}" value="${cur}" min="0" step="0.01" style="width:160px" ${v.locked?'disabled':''}/></td>
        <td>${stateChip}</td>
        <td><button class="btn-secondary btn-save-single" data-vendor-id="${escH(v.vendor_id)}" title="Salvar" ${v.locked?'disabled':''}><span class="material-symbols-rounded" style="font-size:16px">save</span></button></td>
      </tr>`;
    }).join('');
    if(btn){ btn.style.display='inline-flex'; btn.disabled = locked; if(locked) btn.style.display='none'; }
    tb.querySelectorAll('.btn-save-single').forEach(b=>{
      b.addEventListener('click',async()=>{
        const vid=b.dataset.vendorId, row=tb.querySelector(`tr[data-vendor-id="${vid}"]`);
        const inp=row?.querySelector('input.target-input'), vn=row?.dataset.vendorName||'';
        if(!inp)return;const a=parseFloat(inp.value);if(isNaN(a)||inp.value.trim()==='')return;
        await saveOne(vid,vn,a,b);
      });
    });
  }
  async function saveOne(vid,vn,amt,el) {
    if(el)el.disabled=true;setStatus('Salvando…','info');
    try{
      await fetchJ('api/flight_plan_targets_upsert.php',{method:'POST',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify({vendor_id:vid,vendor_name:vn,year_month:currentYM(),target_amount:amt,changed_by:'interface'})});
      setStatus('Meta salva! Recalculando…','success');await loadFlightPlan(true);
    }catch(e){setStatus('Erro: '+e.message,'danger');}finally{if(el)el.disabled=false;}
  }
  async function saveAll() {
    const tb=document.getElementById('targetsFormTable');if(!tb)return;
    const inputs=[...tb.querySelectorAll('input.target-input')].filter(el=>el.value.trim()!==''&&!isNaN(parseFloat(el.value)));
    if(!inputs.length){setStatus('Nenhum valor preenchido.','info');return;}
    const btn=document.getElementById('saveAllTargets');if(btn)btn.disabled=true;
    setStatus(`Salvando ${inputs.length} meta(s) em lote…`,'info');
    const ym=currentYM();
    const payload = inputs.map(inp => {
        const vid = inp.dataset.vendorId, row = tb.querySelector(`tr[data-vendor-id="${vid}"]`);
        return {
            vendor_id: vid,
            vendor_name: row?.dataset.vendorName || '',
            year_month: ym,
            target_amount: parseFloat(inp.value),
            changed_by: 'interface'
        };
    });
    try {
        const csrfToken = document.querySelector('meta[name="biomie-csrf"]')?.getAttribute('content') || '';
        await fetchJ('api/flight_plan_targets_upsert.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrfToken},
            body: JSON.stringify({
              csrf_token: csrfToken,
              rows: payload
            })
        });
        setStatus(`${payload.length} meta(s) salva(s) com sucesso!`, 'success');
        await quickLoad(); 
    } catch (e) {
        setStatus(`Erro ao salvar: ${e.message}`, 'warning');
    } finally {
        if (btn) btn.disabled = false;
    }
  }

  // ─── Quick-load metas do MySQL via Editor Paginado ───
  function monthRangeFromYm(yearMonth) {
    const raw = String(yearMonth || '').trim();
    const parts = raw.split('-');
    const year = Number(parts[0] || 0);
    const month = Number(parts[1] || 0);
    if (!year || !month) {
      const today = new Date();
      const fallbackStart = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-01`;
      return { start: fallbackStart, end: toLocalIso(new Date(today.getFullYear(), today.getMonth() + 1, 0)) };
    }
    return {
      start: `${String(year).padStart(4, '0')}-${String(month).padStart(2, '0')}-01`,
      end: toLocalIso(new Date(year, month, 0))
    };
  }

  function isLockedMonth(yearMonth) {
    return String(yearMonth || '') < toLocalIso(new Date()).slice(0, 7);
  }

  function mapTargetsEditorRows(rows) {
    return (rows || []).map(v => ({
      vendor_id: v.vendor_id,
      vendor_name: v.vendor_name,
      target_period: Number(v.target_month) || Number(v.target_period) || 0,
      target_month: Number(v.target_month) || Number(v.target_period) || 0,
      realized_period: Number(v.realized_period) || 0,
      meta_day_base: Number(v.meta_day_base) || 0,
      ideal_for_period: Number(v.ideal_for_period) || 0,
      forecast_period: Number(v.forecast_period) || 0,
      gap_to_target: Number(v.gap_to_target) || 0,
      attainment_pct: Number(v.attainment_pct) || 0,
      new_daily_target: Number(v.new_daily_target) || 0,
      risk_status: v.risk_status || 'SEM_META',
      locked: !!v.locked
    }));
  }

  function mapFlightPlanRowsForTargets(rows, locked) {
    return (rows || []).map(v => ({
      vendor_id: v.vendor_id,
      vendor_name: v.vendor_name,
      target_period: Number(v.target_month) || Number(v.target_period) || 0,
      target_month: Number(v.target_month) || Number(v.target_period) || 0,
      realized_period: Number(v.realized_period) || 0,
      meta_day_base: Number(v.meta_day_base) || 0,
      ideal_for_period: Number(v.ideal_for_period) || 0,
      forecast_period: Number(v.forecast_period) || 0,
      gap_to_target: Number(v.gap_to_target) || 0,
      attainment_pct: Number(v.attainment_pct) || 0,
      new_daily_target: Number(v.new_daily_target) || 0,
      risk_status: v.risk_status || 'SEM_META',
      locked: !!locked
    }));
  }

  async function quickLoad() {
    showLoading(true);
    try{
      const yearMonth = currentYM();
      const d = await fetchJ(
        `api/flight_plan_targets_editor.php?year_month=${encodeURIComponent(yearMonth)}&page=1&per_page=150`,
        { cache: 'no-store', timeoutMs: 20000 }
      );
      if(_fullFlightPlanLoaded) { showLoading(false); return; }
      let v = mapTargetsEditorRows(d.data || []);
      if (!v.length) {
        const range = monthRangeFromYm(yearMonth);
        const fallback = await fetchJ(
          `api/flight_plan.php?start=${encodeURIComponent(range.start)}&end=${encodeURIComponent(range.end)}`,
          { cache: 'no-store', timeoutMs: 20000 }
        );
        v = mapFlightPlanRowsForTargets(fallback.vendors || [], isLockedMonth(yearMonth));
      }
      if (v.length > 0) {
        renderTargetsForm(v);
        const ac=document.getElementById('flightAlerts');
        if(ac&&!_fullFlightPlanLoaded) ac.innerHTML='<div class="module-alert module-alert--success"><strong>Mesa pronta</strong><span>Leitura rapida do snapshot</span></div>';
      } else {
        const tb=document.getElementById('targetsFormTable');
        if (tb) tb.innerHTML='<tr><td colspan="10" class="empty-row">Sem vendedor ativo no mes. Atualize a rota primeiro.</td></tr>';
      }
    }catch(e){
      console.warn('quickLoad falhou:',e);
      const tb=document.getElementById('targetsFormTable');
      if (tb) tb.innerHTML=`<tr><td colspan="10" class="empty-row" style="color:var(--danger)">Erro ao carregar vendedores: ${e.message}</td></tr>`;
    }finally{
      showLoading(false);
    }
  }

  // ─── Botões Genéricos e Dispatchers ───────────────
  function renderTargetsForm(vendors) {
    const tb=document.getElementById('targetsFormTable'), btn=document.getElementById('saveAllTargets');
    if(!tb) return;
    const rows=(vendors||[]).filter(v=>v.vendor_id&&v.vendor_id!=='');
    const locked = !!(rows[0] && rows[0].locked);
    if(!rows.length){tb.innerHTML='<tr><td colspan="5" class="empty-row">Nenhum vendedor disponível para cadastro neste mês.</td></tr>';if(btn)btn.style.display='none';return;}
    tb.innerHTML=rows.map(v=>{
      const has=Number(v.target_period)>0;
      const cur=has?(Number(v.target_month)>0?Number(v.target_month):Number(v.target_period)):'';
      const risk = String(v.risk_status||'SEM_META');
      const stateChip = v.locked
        ? '<span class="status-chip status-chip--muted">Historico travado</span>'
        : (has
          ? `<span class="status-chip ${risk==='OK'?'status-chip--ok':(risk==='ATENCAO'?'status-chip--attention':(risk==='SALA_DE_GUERRA'?'status-chip--danger':'status-chip--muted'))}">Meta pronta</span>`
          : '<span class="status-chip status-chip--muted">Sem meta</span>');
      return `<tr data-vendor-id="${escH(v.vendor_id)}" data-vendor-name="${escH(v.vendor_name||'')}">
        <td><div class="flight-seller"><strong>${escH(v.vendor_name||'Sem nome')}</strong><small>${escH(v.vendor_id)}</small></div></td>
        <td>${has?`<span class="status-chip status-chip--ok">${fmt(v.target_month||v.target_period)}</span>`:'<span class="status-chip status-chip--muted">Sem meta</span>'}</td>
        <td><input type="number" class="filter-input target-input" data-vendor-id="${escH(v.vendor_id)}" placeholder="${has?fmtN(cur,2):'ex: 80000'}" value="${cur}" min="0" step="0.01" style="width:160px" ${v.locked?'disabled':''}/></td>
        <td>${stateChip}</td>
        <td><button class="btn-secondary btn-save-single" data-vendor-id="${escH(v.vendor_id)}" title="Salvar" ${v.locked?'disabled':''}><span class="material-symbols-rounded" style="font-size:16px">save</span></button></td>
      </tr>`;
    }).join('');
    if(btn){ btn.style.display='inline-flex'; btn.disabled = locked; if(locked) btn.style.display='none'; }
    tb.querySelectorAll('.btn-save-single').forEach(b=>{
      b.addEventListener('click',async()=>{
        const vid=b.dataset.vendorId, row=tb.querySelector(`tr[data-vendor-id="${vid}"]`);
        const inp=row?.querySelector('input.target-input'), vn=row?.dataset.vendorName||'';
        if(!inp)return;const a=parseFloat(inp.value);if(isNaN(a)||inp.value.trim()==='')return;
        await saveOne(vid,vn,a,b);
      });
    });
  }

  async function saveOne(vid,vn,amt,el) {
    if(el)el.disabled=true;setStatus('Salvando meta...','info');
    try{
      const csrfToken = document.querySelector('meta[name="biomie-csrf"]')?.getAttribute('content') || '';
      await fetchJ('api/flight_plan_targets_upsert.php',{
        method:'POST',
        headers:{'Content-Type':'application/json',Accept:'application/json','X-CSRF-Token':csrfToken},
        body:JSON.stringify({
          csrf_token: csrfToken,
          rows: [{
            vendor_id:vid,
            vendor_name:vn,
            year_month:currentYM(),
            target_amount:amt,
            changed_by:'interface'
          }]
        })
      });
      setStatus('Meta salva! As projecoes ja podem ser usadas na Rota Comercial.','success');
      await quickLoad();
    }catch(e){setStatus('Erro: '+e.message,'danger');}finally{if(el)el.disabled=false;}
  }

  async function quickLoad() {
    showLoading(true);
    try{
      const isTargetsPage = !!document.querySelector('[data-page="flight-plan-targets"]');
      const yearMonth = currentYM();
      const d = await fetchJ(
        `api/flight_plan_targets_editor.php?year_month=${encodeURIComponent(yearMonth)}&page=1&per_page=150`,
        { cache: 'no-store', timeoutMs: 20000 }
      );
      if(_fullFlightPlanLoaded) { showLoading(false); return; }
      let v = mapTargetsEditorRows(d.data || []);
      if (!v.length && !isTargetsPage) {
        const range = monthRangeFromYm(yearMonth);
        const fallback = await fetchJ(
          `api/flight_plan.php?start=${encodeURIComponent(range.start)}&end=${encodeURIComponent(range.end)}`,
          { cache: 'no-store', timeoutMs: 20000 }
        );
        v = mapFlightPlanRowsForTargets(fallback.vendors || [], isLockedMonth(yearMonth));
      }
      if (v.length > 0) {
        renderTargetsForm(v);
        const ac=document.getElementById('flightAlerts');
        if(ac&&!_fullFlightPlanLoaded) ac.innerHTML='<div class="module-alert module-alert--success"><strong>Mesa pronta</strong><span>Leitura rapida do snapshot</span></div>';
      } else {
        const tb=document.getElementById('targetsFormTable');
        if (tb) tb.innerHTML='<tr><td colspan="5" class="empty-row">Sem vendedores disponiveis para esse mes. Verifique o cadastro do time comercial.</td></tr>';
      }
    }catch(e){
      console.warn('quickLoad falhou:',e);
      const tb=document.getElementById('targetsFormTable');
      if (tb) tb.innerHTML=`<tr><td colspan="5" class="empty-row" style="color:var(--danger)">Erro ao carregar vendedores: ${e.message}</td></tr>`;
    }finally{
      showLoading(false);
    }
  }

  function boot() {
    const isTargetsPage = !!document.querySelector('[data-page="flight-plan-targets"]');

    // Na página de metas, o input é type="month" (YYYY-MM) — não usar preset de data completa
    if (!isTargetsPage) {
      preset('month');
    }

    document.querySelectorAll('.btn-preset').forEach(b=>{b.addEventListener('click',()=>{preset(b.dataset.preset);});});
    document.getElementById('applyFilters')?.addEventListener('click',() => {
        if(isTargetsPage) quickLoad(); else loadFlightPlan(false);
    });
    
    document.getElementById('syncNow')?.addEventListener('click',() => {
        if(isTargetsPage) quickLoad(); else loadFlightPlan(true);
    });
    
    document.getElementById('saveAllTargets')?.addEventListener('click',saveAll);
    
    if(isTargetsPage){
        quickLoad().catch(e=>console.warn(e));
    } else {
        loadFlightPlan(false).catch(e=>console.error(e));
    }
  }
  document.addEventListener('DOMContentLoaded',boot);
})();

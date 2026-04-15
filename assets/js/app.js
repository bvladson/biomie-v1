(function () {
  const charts = new Map();
  const currencyBRL = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
  const numberBR = new Intl.NumberFormat('pt-BR');
  const compactBR = new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 1 });
  function getCssVar(name) {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
  }

  function surfaceColor() {
    return getCssVar('--bg-card') || '#ffffff';
  }

  function chartTheme() {
    if (window.BIOMIE_THEME && typeof window.BIOMIE_THEME.getChartTheme === 'function') {
      return window.BIOMIE_THEME.getChartTheme();
    }
    return {
      text: getCssVar('--text-2') || '#4b5263',
      title: getCssVar('--text-1') || '#111318',
      subtle: getCssVar('--text-3') || '#9099ac',
      surface: surfaceColor(),
      grid: getCssVar('--chart-grid') || 'rgba(0,0,0,.08)',
      tooltipBg: getCssVar('--chart-tooltip-bg') || '#ffffff',
      tooltipBorder: getCssVar('--chart-tooltip-border') || 'rgba(0,0,0,.12)',
      accent: getCssVar('--page-accent') || '#96140a',
      accentSoft: getCssVar('--page-accent-soft') || 'rgba(150,20,10,0.18)',
      billed: getCssVar('--state-billed') || '#16a34a',
      authorized: getCssVar('--state-auth') || '#d97706',
      cancelled: getCssVar('--state-cancel') || '#dc2626',
      info: getCssVar('--state-info') || '#2563eb'
    };
  }

  function chartAxisOptions(axis, extra = {}) {
    const theme = chartTheme();
    const options = Object.assign({}, extra);
    const currentTicks = Object.assign({}, extra.ticks || {});
    const currentGrid = Object.assign({}, extra.grid || {});
    const currentBorder = Object.assign({}, extra.border || {});

    currentTicks.color = currentTicks.color || theme.text;
    if (currentGrid.display !== false) {
      currentGrid.color = currentGrid.color || theme.grid;
    }
    currentBorder.color = currentBorder.color || theme.grid;

    options.ticks = currentTicks;
    options.grid = currentGrid;
    options.border = currentBorder;

    if (axis === 'money' && typeof currentTicks.callback !== 'function') {
      options.ticks.callback = function (value) { return formatCurrencyShort(value); };
    }

    return options;
  }

  function hexToRgba(hex, alpha) {
    const clean = String(hex || '#000000').replace('#', '');
    const bigint = clean.length === 3
      ? parseInt(clean.split('').map((chunk) => chunk + chunk).join(''), 16)
      : parseInt(clean, 16);
    const r = (bigint >> 16) & 255;
    const g = (bigint >> 8) & 255;
    const b = bigint & 255;
    return `rgba(${r},${g},${b},${alpha})`;
  }

  function adjustColour(hex, percent) {
    const clean = String(hex || '#000000').replace('#', '');
    const num = parseInt(clean, 16);
    let r = (num >> 16) + Math.round(2.55 * percent);
    let g = ((num >> 8) & 0x00FF) + Math.round(2.55 * percent);
    let b = (num & 0x0000FF) + Math.round(2.55 * percent);
    r = Math.min(255, Math.max(0, r));
    g = Math.min(255, Math.max(0, g));
    b = Math.min(255, Math.max(0, b));
    return '#' + ((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1);
  }

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function setText(selector, value) {
    document.querySelectorAll(selector).forEach((element) => {
      element.textContent = value;
    });
  }

  function setHtml(selector, value) {
    document.querySelectorAll(selector).forEach((element) => {
      element.innerHTML = value;
    });
  }

  function formatCurrency(value) {
    return currencyBRL.format(Number(value || 0));
  }

  function formatNumber(value) {
    return numberBR.format(Number(value || 0));
  }

  function formatCurrencyShort(value) {
    const amount = Number(value || 0);
    const abs = Math.abs(amount);
    const prefix = amount < 0 ? '-R$ ' : 'R$ ';
    if (abs >= 1000000) return `${prefix}${compactBR.format(abs / 1000000)} mi`;
    if (abs >= 1000) return `${prefix}${compactBR.format(abs / 1000)} mil`;
    return `${prefix}${numberBR.format(Math.round(abs))}`;
  }

  function shortenLabel(value, maxLength = 18) {
    const label = String(value || '').trim();
    if (label.length <= maxLength) return label;
    return `${label.slice(0, Math.max(0, maxLength - 1)).trim()}...`;
  }

  function toLocalIso(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
  }

  function todayIso() {
    return toLocalIso(new Date());
  }

  function chartDateLabel(dateValue) {
    return dateValue ? new Date(`${dateValue}T12:00:00`).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' }) : '';
  }

  function monthLabel(dateValue) {
    if (!dateValue) return '';
    const normalized = String(dateValue).length === 7 ? `${dateValue}-01` : String(dateValue);
    const parsed = new Date(`${normalized}T12:00:00`);
    if (Number.isNaN(parsed.getTime())) return String(dateValue);
    return parsed.toLocaleDateString('pt-BR', { month: 'short', year: '2-digit' });
  }

  function buildGradient(ctx, color, alpha = 0.24) {
    const gradient = ctx.createLinearGradient(0, 0, 0, 280);
    gradient.addColorStop(0, hexToRgba(color, alpha));
    gradient.addColorStop(1, hexToRgba(color, 0.05));
    return gradient;
  }

  function getDateRange() {
    const startInput = document.getElementById('dateStart');
    const endInput = document.getElementById('dateEnd');
    return { start: startInput?.value || '', end: endInput?.value || '' };
  }

  function setDateRange(start, end) {
    const startInput = document.getElementById('dateStart');
    const endInput = document.getElementById('dateEnd');
    if (startInput) startInput.value = start;
    if (endInput) endInput.value = end;
  }

  function applyPresetRange(preset) {
    const now = new Date();
    if (preset === 'month') {
      setDateRange(
        toLocalIso(new Date(now.getFullYear(), now.getMonth(), 1)),
        toLocalIso(new Date(now.getFullYear(), now.getMonth() + 1, 0))
      );
    }
    if (preset === 'year') {
      setDateRange(`${now.getFullYear()}-01-01`, todayIso());
    }
    if (preset === 'rolling12') {
      setDateRange(toLocalIso(new Date(now.getFullYear() - 1, now.getMonth(), now.getDate() + 1)), todayIso());
    }
  }

  async function fetchJson(url, options = {}) {
    return window.fetchJson(url, options);
  }

  function buildApiUrl(path, force, extraParams = {}) {
    const url = new URL(path, window.location.href);
    const range = getDateRange();
    if (range.start) url.searchParams.set('start', range.start);
    if (range.end) url.searchParams.set('end', range.end);
    if (force) url.searchParams.set('force', '1');
    Object.entries(extraParams || {}).forEach(([key, value]) => {
      if (value == null || value === '') return;
      url.searchParams.set(key, String(value));
    });
    return `${url.pathname}${url.search}`;
  }

  function buildRangeDates(start, end) {
    if (!start || !end) return [];
    const dates = [];
    const cursor = new Date(`${start}T12:00:00`);
    const last = new Date(`${end}T12:00:00`);
    while (cursor <= last) {
      dates.push(toLocalIso(cursor));
      cursor.setDate(cursor.getDate() + 1);
    }
    return dates;
  }

  function resolveRowDate(row) {
    return row?.issue_date || row?.billing_date || row?.date || '';
  }

  function resolveRowTotal(row) {
    return Number(row?.gross_total ?? row?.total ?? row?.revenue ?? 0);
  }

  function aggregateRowsByVendor(rows) {
    const grouped = new Map();
    rows.forEach((row) => {
      const name = String(row?.vendor_name || 'Sem vendedor').trim() || 'Sem vendedor';
      const current = grouped.get(name) || { label: name, value: 0, count: 0 };
      current.value += resolveRowTotal(row);
      current.count += 1;
      grouped.set(name, current);
    });
    return Array.from(grouped.values()).sort((left, right) => right.value - left.value);
  }

  function aggregateRowsByDate(rows, seededDates = []) {
    const grouped = new Map(seededDates.map((date) => [date, 0]));
    rows.forEach((row) => {
      const date = resolveRowDate(row);
      if (!date) return;
      grouped.set(date, Number(grouped.get(date) || 0) + resolveRowTotal(row));
    });
    return Array.from(grouped.entries())
      .sort(([left], [right]) => left.localeCompare(right))
      .map(([date, value]) => ({ date, value }));
  }

  function formatMetaDate(value) {
    if (!value) return '-';
    const parsed = new Date(String(value).length === 10 ? `${value}T12:00:00` : value);
    if (Number.isNaN(parsed.getTime())) return String(value);
    return parsed.toLocaleDateString('pt-BR');
  }

  function humanizeServedFrom(value) {
    const normalized = String(value || '').toLowerCase();
    if (!normalized) return '-';
    if (normalized.includes('legacy_fallback')) return 'legacy-fallback';
    if (normalized.includes('legacy')) return 'legacy';
    if (normalized.includes('historical')) return 'historical-read-model';
    if (normalized.includes('snapshot')) return 'historical-read-model';
    if (normalized.includes('canonical')) return 'canonical-db';
    return String(value);
  }

  function resolveCoverageLabel(data) {
    const meta = data?.meta || {};
    const actual = meta.coverage_actual || {};
    const start = actual.start || meta.coverage_start || data?.coverage_start || data?.period?.start || '-';
    const end = actual.end || meta.coverage_end || data?.coverage_end || data?.period?.end || '-';
    return `${formatMetaDate(start)} ate ${formatMetaDate(end)}`;
  }

  function renderSyncStateBadges(data) {
    const meta = data?.meta || {};
    const badges = [];
    const servedFrom = humanizeServedFrom(meta.served_from || data?.source || '');
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
      badges.push(`<span class="mag-status-chip mag-status-warning">${formatNumber(openDivergences)} divergencia(s)</span>`);
    }

    return badges.join(' ');
  }

  function updateGlobalMeta(data) {
    setText('[data-bind="period-label"]', data.period?.label || '-');
    const syncTime = data.meta?.freshness_at || data.meta?.last_sync || data.meta?.generated_at;
    setText('[data-bind="last-sync"]', syncTime ? new Date(syncTime).toLocaleString('pt-BR') : '-');
    setText('[data-bind="summary-period-basis"]', (data.meta && data.meta.period_basis === 'issue_date') ? 'Base fiscal: emissao' : 'Base comercial: faturamento');
    setHtml('[data-bind="summary-mode"]', renderStatusBadge(data.mode || 'demo'));
    setText('[data-bind="sync-source"]', humanizeServedFrom(data.meta?.served_from || data.source || ''));
    setText('[data-bind="sync-coverage"]', resolveCoverageLabel(data));
    setText('[data-bind="sync-reconciled-until"]', formatMetaDate(data.meta?.reconciled_until || data.reconciled_until || ''));
    setHtml('[data-bind="sync-warning"]', renderSyncStateBadges(data) || '<span class="mag-status-chip mag-status-live">Leitura principal</span>');

    const servedFrom = String(data.meta?.served_from || '');
    const liveDot = document.querySelector('.live-dot');
    if (liveDot) {
      liveDot.classList.remove('stale', 'error');
      if (servedFrom.includes('legacy') || String(data.meta?.signal_status || '').includes('partial')) {
        liveDot.classList.add('stale');
        liveDot.title = 'Cobertura parcial ou fallback';
      } else if (servedFrom.includes('error')) {
        liveDot.classList.add('error');
        liveDot.title = 'Usando ultimo snapshot valido';
      } else {
        liveDot.title = 'Dados atualizados';
      }
    }
  }

  function renderStatusBadge(mode) {
    const normalized = String(mode || '').toLowerCase();
    if (normalized.includes('hybrid')) return '<span class="mag-status-chip mag-status-warning">Hibrido</span>';
    if (normalized.includes('historical') || normalized.includes('snapshot')) return '<span class="mag-status-chip mag-status-warning">Historico</span>';
    if (normalized.includes('canonical') || normalized.includes('online') || normalized.includes('live')) return '<span class="mag-status-chip mag-status-live">Online</span>';
    if (normalized.includes('legacy_fallback') || normalized.includes('fallback')) return '<span class="mag-status-chip mag-status-warning">Fallback</span>';
    return '<span class="mag-status-chip mag-status-demo">Demo</span>';
  }

  function renderSummaryCards(summary, mode) {
    const target = Number(summary.target || 0);
    const billed = Number(summary.gross_revenue ?? summary.revenue ?? 0);
    const pending = Number(summary.pending_value || 0);
    const coveragePct = target > 0 ? (((billed + pending) / target) * 100) : 0;

    setText('[data-bind="summary-revenue"]', formatCurrency(summary.gross_revenue ?? summary.revenue));
    setText('[data-bind="summary-merchandise"]', formatCurrency(summary.merchandise_total));
    setText('[data-bind="summary-discounts"]', formatCurrency(summary.discounts_total));
    setText('[data-bind="summary-orders"]', formatNumber(summary.orders));
    setText('[data-bind="summary-ticket"]', formatCurrency(summary.avg_ticket));
    setText('[data-bind="summary-vendors"]', formatNumber(summary.active_vendors));
    setText('[data-bind="summary-clients"]', formatNumber(summary.active_clients));
    setText('[data-bind="summary-top5-clients"]', `${Number(summary.top5_client_concentration_pct || 0).toFixed(1)}%`);
    setText('[data-bind="summary-pending-orders"]', formatNumber(summary.pending_orders));
    setText('[data-bind="summary-pending-value"]', formatCurrency(summary.pending_value));
    setText('[data-bind="summary-cancelled-orders"]', formatNumber(summary.cancelled_orders));
    setText('[data-bind="summary-cancelled-value"]', formatCurrency(summary.cancelled_value));
    setText('[data-bind="summary-target"]', formatCurrency(summary.target));
    setText('[data-bind="summary-forecast"]', formatCurrency(summary.forecast));
    setText('[data-bind="summary-coverage"]', `${Number(coveragePct || 0).toFixed(1)}%`);
    setText('[data-bind="summary-progress"]', `${Number(summary.goal_progress || 0).toFixed(1)}%`);
    setHtml('[data-bind="summary-mode"]', renderStatusBadge(mode));
    document.querySelectorAll('[data-bind="summary-progress-bar"]').forEach((bar) => {
      const progress = Math.max(0, Math.min(100, Number(summary.goal_progress || 0)));
      bar.style.width = `${progress}%`;
      bar.setAttribute('aria-valuenow', `${progress}`);
    });
    document.querySelectorAll('[data-bind="summary-target-label"]').forEach((element) => {
      element.textContent = 'Meta do periodo:';
    });
  }

  function summaryHasSignal(summary) {
    if (!summary) return false;
    return [
      Number(summary.gross_revenue ?? summary.revenue ?? 0),
      Number(summary.orders || 0),
      Number(summary.pending_value || 0),
      Number(summary.pending_orders || 0),
      Number(summary.cancelled_value || 0),
      Number(summary.active_clients || 0),
      Number(summary.active_vendors || 0)
    ].some((value) => value > 0);
  }

  function vendorsPayloadHasSignal(data) {
    if (!data) return false;
    const summaryStatus = data.summary_status || {};
    return [
      Number(summaryStatus.billed_value || 0),
      Number(summaryStatus.billed_orders || 0),
      Number(summaryStatus.authorized_value || 0),
      Number(summaryStatus.authorized_orders || 0),
      Number(summaryStatus.cancelled_value || 0),
      Number(summaryStatus.cancelled_orders || 0),
      Number(data.summary?.target || 0),
      Number((data.vendor_status_matrix || []).length || 0)
    ].some((value) => value > 0);
  }

  function mergeDashboardSummary(overviewSummary = {}, vendorsPayload = null) {
    const merged = Object.assign({}, overviewSummary || {});
    const vendorSummary = vendorsPayload?.summary || {};
    const summaryStatus = vendorsPayload?.summary_status || {};
    const activeVendorRows = Array.isArray(vendorsPayload?.vendor_status_matrix)
      ? vendorsPayload.vendor_status_matrix.filter((row) => {
        return Number(row.billed_value || 0) > 0
          || Number(row.authorized_value || 0) > 0
          || Number(row.cancelled_value || 0) > 0;
      }).length
      : 0;

    if (!Number(merged.gross_revenue ?? merged.revenue ?? 0) && Number(summaryStatus.billed_value || 0) > 0) {
      merged.gross_revenue = Number(summaryStatus.billed_value || 0);
      merged.revenue = Number(summaryStatus.billed_value || 0);
    }
    if (!Number(merged.orders || 0) && Number(summaryStatus.billed_orders || 0) > 0) {
      merged.orders = Number(summaryStatus.billed_orders || 0);
    }
    if (!Number(merged.pending_value || 0) && Number(summaryStatus.authorized_value || 0) > 0) {
      merged.pending_value = Number(summaryStatus.authorized_value || 0);
    }
    if (!Number(merged.pending_orders || 0) && Number(summaryStatus.authorized_orders || 0) > 0) {
      merged.pending_orders = Number(summaryStatus.authorized_orders || 0);
    }
    if (!Number(merged.cancelled_value || 0) && Number(summaryStatus.cancelled_value || 0) > 0) {
      merged.cancelled_value = Number(summaryStatus.cancelled_value || 0);
    }
    if (!Number(merged.cancelled_orders || 0) && Number(summaryStatus.cancelled_orders || 0) > 0) {
      merged.cancelled_orders = Number(summaryStatus.cancelled_orders || 0);
    }
    if (!Number(merged.avg_ticket || 0) && Number(summaryStatus.billed_orders || 0) > 0) {
      merged.avg_ticket = Number(summaryStatus.billed_value || 0) / Math.max(1, Number(summaryStatus.billed_orders || 0));
    }
    if (!Number(merged.active_vendors || 0) && Number(vendorSummary.active_vendors || 0) > 0) {
      merged.active_vendors = Number(vendorSummary.active_vendors || 0);
    }
    if (!Number(merged.active_vendors || 0) && activeVendorRows > 0) {
      merged.active_vendors = activeVendorRows;
    }
    if (!Number(merged.target || 0) && Number(vendorSummary.target || 0) > 0) {
      merged.target = Number(vendorSummary.target || 0);
    }
    if (!Number(merged.forecast || 0) && Number(vendorSummary.forecast || 0) > 0) {
      merged.forecast = Number(vendorSummary.forecast || 0);
    }
    if (!Number(merged.goal_progress || 0) && Number(vendorSummary.goal_progress || 0) > 0) {
      merged.goal_progress = Number(vendorSummary.goal_progress || 0);
    }

    return merged;
  }

  function vendorsMatrixForHome(vendorsPayload, overviewPayload) {
    if (Array.isArray(vendorsPayload?.vendor_status_matrix) && vendorsPayload.vendor_status_matrix.length) {
      return vendorsPayload.vendor_status_matrix.map((row) => ({
        vendor_name: row.vendor_name || 'Sem vendedor',
        revenue: Number(row.billed_value || 0),
        orders: Number(row.billed_orders || 0),
        ticket_avg: Number(row.avg_ticket_billed || 0),
        gross_revenue: Number(row.billed_value || 0)
      }));
    }
    return Array.isArray(overviewPayload?.vendor_ranking) ? overviewPayload.vendor_ranking : [];
  }

  function overviewSalesAsStatus(dailySales) {
    return (dailySales || []).map((row) => ({
      date: row.date || '',
      billed_value: Number(row.gross_revenue ?? row.revenue ?? 0),
      billed_orders: Number(row.orders || 0),
      authorized_value: 0,
      authorized_orders: 0,
      cancelled_value: 0,
      cancelled_orders: 0
    }));
  }

  function renderStatusMeter(summaryStatus = {}) {
    const billed = Number(summaryStatus.billed_value || 0);
    const authorized = Number(summaryStatus.authorized_value || 0);
    const cancelled = Number(summaryStatus.cancelled_value || 0);
    const total = billed + authorized + cancelled;

    setText('[data-bind="pipeline-billed"]', formatCurrency(billed));
    setText('[data-bind="pipeline-authorized"]', formatCurrency(authorized));
    setText('[data-bind="pipeline-cancelled"]', formatCurrency(cancelled));

    const meter = document.getElementById('statusMeterBar');
    if (!meter) return;

    const billedPct = total > 0 ? (billed / total) * 100 : 0;
    const authorizedPct = total > 0 ? (authorized / total) * 100 : 0;
    const cancelledPct = total > 0 ? (cancelled / total) * 100 : 0;
    meter.style.setProperty('--meter-billed', `${billedPct}%`);
    meter.style.setProperty('--meter-auth', `${authorizedPct}%`);
    meter.style.setProperty('--meter-cancel', `${cancelledPct}%`);
  }

  function weatherCodeMeta(code, isDay) {
    const day = !!isDay;
    const map = {
      0: { icon: day ? 'sunny' : 'clear_night', description: day ? 'Ceo limpo' : 'Noite limpa' },
      1: { icon: day ? 'partly_cloudy_day' : 'partly_cloudy_night', description: 'Predominio de sol' },
      2: { icon: day ? 'partly_cloudy_day' : 'partly_cloudy_night', description: 'Parcialmente nublado' },
      3: { icon: 'cloud', description: 'Nublado' },
      45: { icon: 'foggy', description: 'Neblina' },
      48: { icon: 'foggy', description: 'Neblina intensa' },
      51: { icon: 'grain', description: 'Garoa fraca' },
      53: { icon: 'grain', description: 'Garoa' },
      55: { icon: 'grain', description: 'Garoa intensa' },
      61: { icon: 'rainy', description: 'Chuva fraca' },
      63: { icon: 'rainy', description: 'Chuva moderada' },
      65: { icon: 'rainy_heavy', description: 'Chuva forte' },
      71: { icon: 'weather_snowy', description: 'Neve fraca' },
      73: { icon: 'weather_snowy', description: 'Neve' },
      75: { icon: 'weather_snowy', description: 'Neve forte' },
      80: { icon: 'rainy', description: 'Pancadas isoladas' },
      81: { icon: 'rainy_heavy', description: 'Pancadas moderadas' },
      82: { icon: 'rainy_heavy', description: 'Pancadas fortes' },
      95: { icon: 'thunderstorm', description: 'Trovoadas' },
      96: { icon: 'thunderstorm', description: 'Trovoadas com granizo' },
      99: { icon: 'thunderstorm', description: 'Trovoadas severas' }
    };
    return map[Number(code)] || { icon: day ? 'partly_cloudy_day' : 'partly_cloudy_night', description: 'Clima indisponivel' };
  }

  function renderWeather(weather) {
    const dock = document.getElementById('weatherDock');
    if (!dock) return;

    const iconEl = document.getElementById('weatherIcon');
    if (!weather || weather.ok === false) {
      setText('#weatherDescription', weather?.message || 'Clima indisponivel');
      setText('#weatherTemperature', '--');
      if (iconEl) {
        iconEl.textContent = 'cloud_off';
      }
      dock.title = weather?.message || 'Nao foi possivel carregar o clima agora.';
      return;
    }

    const current = weather.current || {};
    const today = weather.today || {};
    const location = weather.location || {};
    const meta = weatherCodeMeta(current.weather_code, current.is_day);
    const temperature = `${Math.round(Number(current.temperature || 0))} C`;
    const range = `${Math.round(Number(today.temperature_max || 0))} / ${Math.round(Number(today.temperature_min || 0))}`;
    const rain = `${formatNumber(today.precipitation_probability_max || 0)}%`;
    const wind = `${Number(current.wind_speed || 0).toFixed(0)} km/h`;
    const updatedAt = current.time ? new Date(current.time).toLocaleString('pt-BR') : '--';

    setText('#weatherDescription', meta.description);
    setText('#weatherTemperature', temperature);

    if (iconEl) {
      iconEl.textContent = meta.icon;
    }

    dock.title = [
      location.label || 'Fortaleza, CE',
      meta.description,
      `Max/Min ${range}`,
      `Chuva ${rain}`,
      `Vento ${wind}`,
      `Atualizado ${updatedAt}`
    ].join(' | ');
  }
  function renderDashboardWeakSignalNotice(needsAttention) {
    const feedback = document.getElementById('syncFeedback');
    if (!feedback) return;
    if (!needsAttention) {
      if (feedback.dataset.dashboardNotice === '1') {
        feedback.innerHTML = '';
        delete feedback.dataset.dashboardNotice;
      }
      return;
    }
    if (feedback.innerHTML.trim() !== '' && feedback.dataset.dashboardNotice !== '1') return;

    feedback.innerHTML = '<div class="module-alert module-alert--warning"><strong>Leitura parcial detectada</strong><span>O resumo principal veio com pouco sinal fiscal. O painel usou a camada operacional para manter a leitura do periodo, mas vale revisar a competencia historica.</span></div>';
    feedback.dataset.dashboardNotice = '1';
  }

  function formatAlertValue(alert) {
    const value = alert?.value;
    if (typeof value !== 'number') return escapeHtml(value ?? '-');
    const metric = String(alert?.metric || '').toLowerCase();
    if (metric.includes('ating') || metric.includes('top cliente') || metric.includes('%')) {
      return `${Number(value).toFixed(1)}%`;
    }
    if (metric.includes('receita') || metric.includes('valor') || metric.includes('ticket') || metric.includes('backlog')) {
      return formatCurrency(value);
    }
    return formatNumber(value);
  }

  function formatOrderDate(row, preferIssueDate = false) {
    const issueDate = row?.issue_date || '';
    const billingDate = row?.billing_date || '';
    const dateValue = preferIssueDate ? (issueDate || billingDate) : (billingDate || issueDate);
    return dateValue ? new Date(`${dateValue}T12:00:00`).toLocaleDateString('pt-BR') : '-';
  }

  function destroyChart(id) {
    const chart = charts.get(id);
    if (chart) {
      chart.destroy();
      charts.delete(id);
    }
  }

  function createLineChart(canvasId, labels, revenueValues, orderValues) {
    const canvas = document.getElementById(canvasId);
    if (!canvas || !window.Chart) return;
    destroyChart(canvasId);

    const theme = chartTheme();
    const accent = theme.accent;
    const secondary = theme.info;
    const ctx = canvas.getContext('2d');
    const chart = new window.Chart(ctx, {
      data: {
        labels,
        datasets: [
          {
            type: 'bar',
            label: 'Receita',
            data: revenueValues,
            backgroundColor: buildGradient(ctx, accent, 0.26),
            borderColor: accent,
            borderWidth: 1,
            borderRadius: 10,
            maxBarThickness: 18,
            yAxisID: 'y',
            order: 2
          },
          {
            type: 'line',
            label: 'NF',
            data: orderValues,
            borderColor: secondary,
            backgroundColor: hexToRgba(secondary, 0.14),
            pointBackgroundColor: secondary,
            pointBorderColor: surfaceColor(),
            pointBorderWidth: 2,
            pointRadius: 3,
            pointHoverRadius: 4,
            tension: 0.35,
            fill: false,
            yAxisID: 'y1',
            order: 1
          }
        ]
      },
      options: {
        responsive: true,
        resizeDelay: 100,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { position: 'top' },
          tooltip: {
            callbacks: {
              label(context) {
                if (context.dataset.yAxisID === 'y1') return `NF: ${formatNumber(context.parsed.y)}`;
                return `Receita: ${formatCurrency(context.parsed.y)}`;
              }
            }
          }
        },
        scales: {
          x: chartAxisOptions('label', { grid: { display: false } }),
          y: chartAxisOptions('money', { beginAtZero: true }),
          y1: chartAxisOptions('count', { beginAtZero: true, position: 'right', ticks: { precision: 0 }, grid: { drawOnChartArea: false } })
        }
      }
    });
    charts.set(canvasId, chart);
  }

  function createMonthlyComparisonChart(canvasId, rows) {
    const canvas = document.getElementById(canvasId);
    if (!canvas || !window.Chart) return;
    destroyChart(canvasId);

    const items = (rows || []).slice(-6);
    if (!items.length) return;

    const theme = chartTheme();
    const accent = theme.accent;
    const secondary = theme.info;
    const muted = theme.subtle;
    const ctx = canvas.getContext('2d');
    const labels = items.map((row) => monthLabel(row.competencia || row.label || ''));
    const revenue = items.map((row) => Number(row.revenue || 0));
    const targets = items.map((row) => Number(row.target || 0));
    const forecast = items.map((row) => Number(row.forecast || row.revenue || 0));
    const barColors = items.map((row) => row.is_current ? buildGradient(ctx, accent, 0.3) : buildGradient(ctx, adjustColour(accent, 18), 0.18));
    const borderColors = items.map((row) => row.is_current ? accent : adjustColour(accent, 18));

    const chart = new window.Chart(ctx, {
      data: {
        labels,
        datasets: [
          {
            type: 'bar',
            label: 'Receita',
            data: revenue,
            backgroundColor: barColors,
            borderColor: borderColors,
            borderWidth: 1,
            borderRadius: 10,
            maxBarThickness: 30,
            order: 3
          },
          {
            type: 'line',
            label: 'Meta',
            data: targets,
            borderColor: muted,
            backgroundColor: 'transparent',
            borderDash: [6, 4],
            pointRadius: 2,
            pointHoverRadius: 3,
            tension: 0.25,
            order: 1
          },
          {
            type: 'line',
            label: 'Tendencia',
            data: forecast,
            borderColor: secondary,
            backgroundColor: hexToRgba(secondary, 0.12),
            pointBackgroundColor: secondary,
            pointBorderColor: surfaceColor(),
            pointBorderWidth: 2,
            pointRadius: 3,
            pointHoverRadius: 4,
            tension: 0.35,
            fill: false,
            order: 2
          }
        ]
      },
      options: {
        responsive: true,
        resizeDelay: 100,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { position: 'top' },
          tooltip: {
            callbacks: {
              label(context) {
                return `${context.dataset.label}: ${formatCurrency(context.parsed.y)}`;
              }
            }
          }
        },
        scales: {
          x: chartAxisOptions('label', { grid: { display: false } }),
          y: chartAxisOptions('money', { beginAtZero: true })
        }
      }
    });
    charts.set(canvasId, chart);
  }

  function createBarChart(canvasId, labels, values, chartOptions = {}) {
    const canvas = document.getElementById(canvasId);
    if (!canvas || !window.Chart) return;
    destroyChart(canvasId);

    const ctx = canvas.getContext('2d');
    const accent = chartOptions.color || chartTheme().accent;
    const palette = labels.map((_, index) => adjustColour(accent, (index / Math.max(1, labels.length - 1)) * 28));

    const chart = new window.Chart(ctx, {
      type: 'bar',
      data: {
        labels,
        datasets: [
          {
            label: chartOptions.label || 'Receita',
            data: values,
            backgroundColor: palette.map((color) => buildGradient(ctx, color, 0.28)),
            borderColor: palette,
            borderWidth: 1,
            borderRadius: 10,
            maxBarThickness: chartOptions.maxBarThickness || 28
          }
        ]
      },
      options: {
        responsive: true,
        resizeDelay: 100,
        maintainAspectRatio: false,
        indexAxis: chartOptions.indexAxis || 'x',
        plugins: { legend: { display: false } },
        scales: {
          x: chartAxisOptions('label', { grid: { display: chartOptions.indexAxis === 'y' } }),
          y: chartAxisOptions('money', { beginAtZero: true, grid: { display: chartOptions.indexAxis === 'y' ? false : true } })
        }
      }
    });
    charts.set(canvasId, chart);
  }

  function createRevenueTicketChart(canvasId, rows) {
    const canvas = document.getElementById(canvasId);
    if (!canvas || !window.Chart) return;
    destroyChart(canvasId);

    const ranking = (rows || []).slice(0, 6);
    if (!ranking.length) return;

    const theme = chartTheme();
    const accent = theme.accent;
    const secondary = theme.info;
    const labels = ranking.map((row) => shortenLabel(row.vendor_name, 12));
    const fullLabels = ranking.map((row) => row.vendor_name || 'Sem vendedor');
    const revenue = ranking.map((row) => Number(row.revenue ?? row.gross_revenue ?? 0));
    const ticket = ranking.map((row) => Number(row.ticket_avg ?? (Number(row.orders || 0) > 0 ? Number(row.revenue || 0) / Number(row.orders || 1) : 0)));
    const ctx = canvas.getContext('2d');

    const chart = new window.Chart(ctx, {
      data: {
        labels,
        datasets: [
          {
            type: 'bar',
            label: 'Receita',
            data: revenue,
            backgroundColor: buildGradient(ctx, accent, 0.26),
            borderColor: accent,
            borderWidth: 1,
            borderRadius: 10,
            maxBarThickness: 30,
            yAxisID: 'y'
          },
          {
            type: 'line',
            label: 'Ticket',
            data: ticket,
            borderColor: secondary,
            backgroundColor: hexToRgba(secondary, 0.12),
            pointBackgroundColor: secondary,
            pointBorderColor: surfaceColor(),
            pointBorderWidth: 2,
            pointRadius: 4,
            tension: 0.35,
            yAxisID: 'y1'
          }
        ]
      },
      options: {
        responsive: true,
        resizeDelay: 100,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { position: 'top' },
          tooltip: {
            callbacks: {
              title(items) {
                return fullLabels[items[0].dataIndex] || items[0].label;
              },
              label(context) {
                return context.dataset.label === 'Ticket'
                  ? `Ticket: ${formatCurrency(context.parsed.y)}`
                  : `Receita: ${formatCurrency(context.parsed.y)}`;
              }
            }
          }
        },
        scales: {
          x: chartAxisOptions('label', { grid: { display: false } }),
          y: chartAxisOptions('money', { beginAtZero: true }),
          y1: chartAxisOptions('money', { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false } })
        }
      }
    });
    charts.set(canvasId, chart);
  }

  function createGroupedBarChart(canvasId, matrix) {
    const canvas = document.getElementById(canvasId);
    if (!canvas || !window.Chart) return;
    destroyChart(canvasId);

    const rows = (matrix || []).slice(0, 10);
    if (!rows.length) return;

    const theme = chartTheme();
    const billedColor = theme.billed;
    const authColor = theme.authorized;
    const cancelColor = theme.cancelled;
    const labels = rows.map((row) => shortenLabel(row.vendor_name, 18));
    const fullLabels = rows.map((row) => row.vendor_name || 'Sem vendedor');
    const ctx = canvas.getContext('2d');

    const chart = new window.Chart(ctx, {
      type: 'bar',
      data: {
        labels,
        datasets: [
          { label: 'NF', data: rows.map((row) => Number(row.billed_value || 0)), backgroundColor: buildGradient(ctx, billedColor, 0.28), borderColor: billedColor, borderWidth: 1, borderRadius: 8, stack: 'status' },
          { label: 'Carteira', data: rows.map((row) => Number(row.authorized_value || 0)), backgroundColor: buildGradient(ctx, authColor, 0.24), borderColor: authColor, borderWidth: 1, borderRadius: 8, stack: 'status' },
          { label: 'Canceladas', data: rows.map((row) => Number(row.cancelled_value || 0)), backgroundColor: buildGradient(ctx, cancelColor, 0.22), borderColor: cancelColor, borderWidth: 1, borderRadius: 8, stack: 'status' }
        ]
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        resizeDelay: 100,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { position: 'top' },
          tooltip: {
            callbacks: {
              title(items) {
                return fullLabels[items[0].dataIndex] || items[0].label;
              },
              label(context) {
                return `${context.dataset.label}: ${formatCurrency(context.parsed.x)}`;
              },
              footer(items) {
                const row = rows[items[0].dataIndex] || {};
                return `Conversao: ${row.conversion_rate || 0}% | Cancelamento: ${row.cancel_rate || 0}%`;
              }
            }
          }
        },
        scales: {
          x: chartAxisOptions('money', { beginAtZero: true, stacked: true }),
          y: chartAxisOptions('label', { stacked: true, grid: { display: false } })
        }
      }
    });
    charts.set(canvasId, chart);
  }

  function createMixedDailyChart(canvasId, daily) {
    const canvas = document.getElementById(canvasId);
    if (!canvas || !window.Chart) return;
    destroyChart(canvasId);

    const rows = daily || [];
    if (!rows.length) return;

    const theme = chartTheme();
    const billedColor = theme.billed;
    const authColor = theme.authorized;
    const cancelColor = theme.cancelled;
    const ctx = canvas.getContext('2d');

    const chart = new window.Chart(ctx, {
      data: {
        labels: rows.map((row) => chartDateLabel(row.date || '')),
        datasets: [
          { type: 'bar', label: 'NF', data: rows.map((row) => Number(row.billed_value || 0)), backgroundColor: buildGradient(ctx, billedColor, 0.26), borderColor: billedColor, borderWidth: 1, borderRadius: 8, stack: 'status', order: 2 },
          { type: 'bar', label: 'Carteira', data: rows.map((row) => Number(row.authorized_value || 0)), backgroundColor: buildGradient(ctx, authColor, 0.22), borderColor: authColor, borderWidth: 1, borderRadius: 8, stack: 'status', order: 2 },
          { type: 'line', label: 'Canceladas', data: rows.map((row) => Number(row.cancelled_value || 0)), borderColor: cancelColor, backgroundColor: hexToRgba(cancelColor, 0.1), pointBackgroundColor: cancelColor, pointRadius: 3, tension: 0.32, fill: false, order: 1 }
        ]
      },
      options: {
        responsive: true,
        resizeDelay: 100,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { position: 'top' },
          tooltip: {
            callbacks: {
              label(context) {
                return `${context.dataset.label}: ${formatCurrency(context.parsed.y)}`;
              }
            }
          }
        },
        scales: {
          x: chartAxisOptions('label', { stacked: true, grid: { display: false } }),
          y: chartAxisOptions('money', { stacked: true, beginAtZero: true })
        }
      }
    });
    charts.set(canvasId, chart);
  }

  function createOrdersStatusChart(canvasId, orders, pendingOrders, cancelledOrders) {
    const canvas = document.getElementById(canvasId);
    if (!canvas || !window.Chart) return;
    destroyChart(canvasId);

    const range = getDateRange();
    const seededDates = buildRangeDates(range.start, range.end);
    const billed = aggregateRowsByDate(orders || [], seededDates);
    const pending = aggregateRowsByDate(pendingOrders || [], seededDates);
    const cancelled = aggregateRowsByDate(cancelledOrders || [], seededDates);
    const dateSet = new Set(seededDates);
    billed.forEach((row) => dateSet.add(row.date));
    pending.forEach((row) => dateSet.add(row.date));
    cancelled.forEach((row) => dateSet.add(row.date));
    const dates = Array.from(dateSet).sort((left, right) => left.localeCompare(right));
    if (!dates.length) return;

    const billedMap = new Map(billed.map((row) => [row.date, row.value]));
    const pendingMap = new Map(pending.map((row) => [row.date, row.value]));
    const cancelledMap = new Map(cancelled.map((row) => [row.date, row.value]));
    const theme = chartTheme();
    const billedColor = theme.billed;
    const authColor = theme.authorized;
    const cancelColor = theme.cancelled;
    const ctx = canvas.getContext('2d');

    const chart = new window.Chart(ctx, {
      type: 'bar',
      data: {
        labels: dates.map((date) => chartDateLabel(date)),
        datasets: [
          { label: 'NF', data: dates.map((date) => Number(billedMap.get(date) || 0)), backgroundColor: buildGradient(ctx, billedColor, 0.28), borderColor: billedColor, borderWidth: 1, borderRadius: 8, stack: 'status' },
          { label: 'Carteira', data: dates.map((date) => Number(pendingMap.get(date) || 0)), backgroundColor: buildGradient(ctx, authColor, 0.22), borderColor: authColor, borderWidth: 1, borderRadius: 8, stack: 'status' },
          { label: 'Canceladas', data: dates.map((date) => Number(cancelledMap.get(date) || 0)), backgroundColor: buildGradient(ctx, cancelColor, 0.2), borderColor: cancelColor, borderWidth: 1, borderRadius: 8, stack: 'status' }
        ]
      },
      options: {
        responsive: true,
        resizeDelay: 100,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { position: 'top' },
          tooltip: {
            callbacks: {
              label(context) {
                return `${context.dataset.label}: ${formatCurrency(context.parsed.y)}`;
              }
            }
          }
        },
        scales: {
          x: chartAxisOptions('label', { stacked: true, grid: { display: false } }),
          y: chartAxisOptions('money', { stacked: true, beginAtZero: true })
        }
      }
    });
    charts.set(canvasId, chart);
  }

  function createOrdersVendorChart(canvasId, rows) {
    const canvas = document.getElementById(canvasId);
    if (!canvas || !window.Chart) return;
    destroyChart(canvasId);

    const ranking = aggregateRowsByVendor(rows || []).slice(0, 7);
    if (!ranking.length) return;

    const ctx = canvas.getContext('2d');
    const accent = chartTheme().accent;
    const chart = new window.Chart(ctx, {
      type: 'bar',
      data: {
        labels: ranking.map((row) => shortenLabel(row.label, 18)),
        datasets: [
          {
            label: 'Receita NF',
            data: ranking.map((row) => row.value),
            backgroundColor: buildGradient(ctx, accent, 0.28),
            borderColor: accent,
            borderWidth: 1,
            borderRadius: 10,
            maxBarThickness: 26
          }
        ]
      },
      options: {
        responsive: true,
        resizeDelay: 100,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              title(items) {
                return ranking[items[0].dataIndex]?.label || items[0].label;
              },
              label(context) {
                const row = ranking[context.dataIndex] || { value: 0, count: 0 };
                return `Receita: ${formatCurrency(row.value)} | NF: ${formatNumber(row.count)}`;
              }
            }
          }
        },
        scales: {
          x: chartAxisOptions('label', { grid: { display: false } }),
          y: chartAxisOptions('money', { beginAtZero: true })
        }
      }
    });
    charts.set(canvasId, chart);
  }

  function createProductMixChart(canvasId, rows) {
    const canvas = document.getElementById(canvasId);
    if (!canvas || !window.Chart) return;
    destroyChart(canvasId);

    const products = (rows || []).slice(0, 8);
    if (!products.length) return;

    const ctx = canvas.getContext('2d');
    const theme = chartTheme();
    const accent = theme.accent;
    const secondary = theme.info;
    const chart = new window.Chart(ctx, {
      data: {
        labels: products.map((row) => shortenLabel(row.description, 14)),
        datasets: [
          {
            type: 'bar',
            label: 'Receita',
            data: products.map((row) => Number(row.revenue || 0)),
            backgroundColor: buildGradient(ctx, accent, 0.26),
            borderColor: accent,
            borderWidth: 1,
            borderRadius: 10,
            maxBarThickness: 28,
            yAxisID: 'y'
          },
          {
            type: 'line',
            label: 'Qtd',
            data: products.map((row) => Number(row.quantity || 0)),
            borderColor: secondary,
            backgroundColor: hexToRgba(secondary, 0.12),
            pointBackgroundColor: secondary,
            pointBorderColor: surfaceColor(),
            pointBorderWidth: 2,
            pointRadius: 4,
            tension: 0.35,
            yAxisID: 'y1'
          }
        ]
      },
      options: {
        responsive: true,
        resizeDelay: 100,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { position: 'top' },
          tooltip: {
            callbacks: {
              title(items) {
                return products[items[0].dataIndex]?.description || items[0].label;
              },
              label(context) {
                return context.dataset.label === 'Qtd'
                  ? `Qtd: ${formatNumber(context.parsed.y)}`
                  : `Receita: ${formatCurrency(context.parsed.y)}`;
              }
            }
          }
        },
        scales: {
          x: chartAxisOptions('label', { grid: { display: false } }),
          y: chartAxisOptions('money', { beginAtZero: true }),
          y1: chartAxisOptions('count', { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, ticks: { precision: 0 } })
        }
      }
    });
    charts.set(canvasId, chart);
  }

  function createProductShareChart(canvasId, rows) {
    const canvas = document.getElementById(canvasId);
    if (!canvas || !window.Chart) return;
    destroyChart(canvasId);

    const products = (rows || []).slice(0, 5);
    if (!products.length) return;

    const total = (rows || []).reduce((sum, row) => sum + Number(row.revenue || 0), 0);
    const topTotal = products.reduce((sum, row) => sum + Number(row.revenue || 0), 0);
    const labels = products.map((row) => shortenLabel(row.description, 18));
    const values = products.map((row) => Number(row.revenue || 0));
    if (total > topTotal) {
      labels.push('Demais');
      values.push(total - topTotal);
    }

    const accent = chartTheme().accent;
    const palette = labels.map((_, index) => adjustColour(accent, index * 10));
    const chart = new window.Chart(canvas.getContext('2d'), {
      type: 'doughnut',
      data: {
        labels,
        datasets: [
          {
            data: values,
            backgroundColor: palette.map((color) => hexToRgba(color, 0.9)),
            borderColor: chartTheme().surface,
            borderWidth: 2,
            hoverOffset: 6
          }
        ]
      },
      options: {
        responsive: true,
        resizeDelay: 100,
        maintainAspectRatio: false,
        cutout: '66%',
        plugins: {
          legend: { position: 'bottom' },
          tooltip: {
            callbacks: {
              label(context) {
                const share = total > 0 ? ((Number(context.parsed || 0) / total) * 100).toFixed(1) : '0.0';
                return `${context.label}: ${formatCurrency(context.parsed)} | ${share}%`;
              }
            }
          }
        }
      }
    });
    charts.set(canvasId, chart);
  }

  function renderTable(targetId, columns, rows, emptyMessage) {
    const target = document.getElementById(targetId);
    if (!target) return;
    if (!rows.length) {
      target.innerHTML = `<tr><td colspan="${columns.length}" class="text-center py-4 text-muted">${escapeHtml(emptyMessage || 'Sem dados no periodo.')}</td></tr>`;
      return;
    }
    target.innerHTML = rows.map((row) => `<tr>${columns.map((column) => `<td>${column.render(row)}</td>`).join('')}</tr>`).join('');
  }

  function renderAlerts(alerts) {
    const target = document.getElementById('alertsList');
    if (!target) return;
    if (!alerts || !alerts.length) {
      target.innerHTML = '<article class="insight-row"><strong>Sem alerta critico</strong><span>Operacao sem desvios relevantes no periodo consultado.</span></article>';
      return;
    }
    target.innerHTML = alerts.map((alert) => {
      const value = formatAlertValue(alert);
      return `<article class="insight-row">
        <div>
          <strong>${escapeHtml(alert.title)}</strong>
          <span>${escapeHtml(alert.message)}</span>
        </div>
        <div class="insight-metric">
          <small>${escapeHtml(alert.metric || 'Indicador')}</small>
          <strong>${value}</strong>
        </div>
      </article>`;
    }).join('');
  }

  function renderTopClients(rows) {
    const target = document.getElementById('topClientsTable');
    if (!target) return;
    if (!rows || !rows.length) {
      target.innerHTML = '<tr><td colspan="4" class="text-center py-4 text-muted">Sem clientes faturados no periodo.</td></tr>';
      return;
    }
    target.innerHTML = rows.map((row) => `<tr>
      <td><strong>${escapeHtml(row.client_name || 'Cliente nao informado')}</strong>${row.client_document ? `<div class="small text-muted">${escapeHtml(row.client_document)}</div>` : ''}</td>
      <td>${formatNumber(row.notes_count || 0)}</td>
      <td>${formatCurrency(row.invoice_total || 0)}</td>
      <td>${Number(row.share_pct || 0).toFixed(1)}%</td>
    </tr>`).join('');
  }

  function renderCommercialHealth(data) {
    const badge = document.getElementById('commercialHealthBadge');
    const info = document.getElementById('commercialHealthInfo');
    const table = document.getElementById('commercialHealthClientsTable');
    if (!badge || !info || !table) return;

    if (!data || data.available === false) {
      badge.textContent = 'Opcional';
      info.className = 'module-alert module-alert--info';
      info.innerHTML = `<strong>Sem enriquecimento financeiro</strong><span>${escapeHtml(data?.message || 'Modulo financeiro Omie indisponivel para este painel.')}</span>`;
      table.innerHTML = '<tr><td colspan="4" class="text-center py-4 text-muted">Sem dados financeiros adicionais.</td></tr>';
      setText('[data-bind="health-received"]', '-');
      setText('[data-bind="health-open"]', '-');
      setText('[data-bind="health-overdue"]', '-');
      setText('[data-bind="health-cash-conversion"]', '-');
      return;
    }

    const summary = data.summary || {};
    badge.textContent = 'Financeiro Omie';
    info.className = 'module-alert module-alert--success';
    info.innerHTML = `<strong>Leitura de caixa pronta</strong><span>Recebido ${formatCurrency(summary.received)} | em aberto ${formatCurrency(summary.open)} | atraso ${Number(summary.delinquency_rate || 0).toFixed(1)}%.</span>`;
    setText('[data-bind="health-received"]', formatCurrency(summary.received));
    setText('[data-bind="health-open"]', formatCurrency(summary.open));
    setText('[data-bind="health-overdue"]', formatCurrency(summary.overdue));
    setText('[data-bind="health-cash-conversion"]', `${Number(summary.cash_conversion_pct || 0).toFixed(1)}%`);

    const rows = data.top_overdue_clients || [];
    if (!rows.length) {
      table.innerHTML = '<tr><td colspan="4" class="text-center py-4 text-muted">Sem clientes vencidos no periodo consultado.</td></tr>';
      return;
    }
    table.innerHTML = rows.map((row) => `<tr>
      <td><strong>${escapeHtml(row.client_name || 'Cliente nao informado')}</strong>${row.cause_label ? `<div class="small text-muted">${escapeHtml(row.cause_label)}</div>` : ''}</td>
      <td>${formatCurrency(row.overdue || 0)}</td>
      <td>${formatNumber(row.titles || 0)}</td>
      <td>${formatNumber(row.max_days_late || 0)} dias</td>
    </tr>`).join('');
  }

  async function loadCommercialHealth(force, options = {}) {
    const target = document.getElementById('commercialHealthInfo');
    if (!target) return;
    try {
      const data = await fetchJson(buildApiUrl('api/commercial_health.php', force), Object.assign({ timeoutMs: 20000 }, options));
      renderCommercialHealth(data);
    } catch (error) {
      renderCommercialHealth({ available: false, message: error?.message || 'Falha ao consultar dados financeiros.' });
    }
  }

  async function loadDashboard(force, options = {}) {
    const [overviewResult, vendorsResult, healthResult, weatherResult] = await Promise.allSettled([
      fetchJson(buildApiUrl('api/overview.php', force), options),
      fetchJson(buildApiUrl('api/vendors.php', force), Object.assign({}, options, { timeoutMs: 25000 })),
      fetchJson(buildApiUrl('api/commercial_health.php', force), Object.assign({}, options, { timeoutMs: 20000 })),
      fetchJson('api/weather.php', Object.assign({}, options, { timeoutMs: 10000 }))
    ]);

    const overview = overviewResult.status === 'fulfilled' ? overviewResult.value : null;
    const vendors = vendorsResult.status === 'fulfilled' ? vendorsResult.value : null;
    const health = healthResult.status === 'fulfilled' ? healthResult.value : null;
    const weather = weatherResult.status === 'fulfilled' ? weatherResult.value : null;

    if (!overview && !vendors) {
      throw overviewResult.status === 'rejected'
        ? overviewResult.reason
        : vendorsResult.reason;
    }

    const metaCarrier = overview || vendors || {};
    const summary = mergeDashboardSummary(overview?.summary || {}, vendors);
    const dailySales = (overview?.daily_sales || []).length
      ? overview.daily_sales
      : overviewSalesAsStatus(vendors?.daily_status || []);
    const vendorChartRows = vendorsMatrixForHome(vendors, overview);
    const topOrders = Array.isArray(overview?.top_orders) ? overview.top_orders : [];
    const topProducts = Array.isArray(overview?.top_products) ? overview.top_products : [];
    const topClients = Array.isArray(overview?.top_clients) ? overview.top_clients : [];
    const statusSummary = vendors?.summary_status || {
      billed_value: Number(summary.gross_revenue ?? summary.revenue ?? 0),
      billed_orders: Number(summary.orders || 0),
      authorized_value: Number(summary.pending_value || 0),
      authorized_orders: Number(summary.pending_orders || 0),
      cancelled_value: Number(summary.cancelled_value || 0),
      cancelled_orders: Number(summary.cancelled_orders || 0)
    };
    const alerts = []
      .concat(Array.isArray(overview?.alerts) ? overview.alerts : [])
      .concat(Array.isArray(vendors?.alerts_operational) ? vendors.alerts_operational.slice(0, 3).map((alert) => ({
        level: alert.level || 'warning',
        title: alert.title || 'Sinal operacional',
        message: alert.message || 'Atencao operacional no time.',
        metric: 'Operacao',
        value: '-'
      })) : []);

    updateGlobalMeta(metaCarrier);
    renderSummaryCards(summary, metaCarrier.mode || 'demo');
    renderStatusMeter(statusSummary);
    renderDashboardWeakSignalNotice(!summaryHasSignal(overview?.summary || {}) && vendorsPayloadHasSignal(vendors));

    createLineChart(
      'salesTimelineChart',
      dailySales.map((row) => chartDateLabel(row.date)),
      dailySales.map((row) => Number(row.gross_revenue ?? row.revenue ?? row.billed_value ?? 0)),
      dailySales.map((row) => Number(row.orders ?? row.billed_orders ?? 0))
    );
    createMonthlyComparisonChart('monthComparisonChart', overview?.month_comparison || []);
    createMixedDailyChart('dailyMixedChart', Array.isArray(vendors?.daily_status) && vendors.daily_status.length ? vendors.daily_status : overviewSalesAsStatus(dailySales));
    createRevenueTicketChart('vendorsBarChart', vendorChartRows);
    renderTable('topOrdersTable', [
      { render: (row) => `<strong>${escapeHtml(row.codigo)}</strong><div class="small text-muted">${escapeHtml(row.codigo_integracao || 'Sem integracao')}</div><div class="small text-muted">Emis.: ${formatOrderDate(row, true)}</div>` },
      { render: (row) => `<strong>${escapeHtml(row.cliente)}</strong>${row.cliente_documento ? `<div class="small text-muted">${escapeHtml(row.cliente_documento)}</div>` : ''}` },
      { render: (row) => escapeHtml(row.vendor_name || 'Sem vendedor') },
      { render: (row) => formatNumber(row.items_qty) },
      { render: (row) => `${formatCurrency(row.gross_total ?? row.total)}<div class="small text-muted">Merc.: ${formatCurrency(row.merchandise_total || 0)}</div><div class="small text-muted">Desc.: ${formatCurrency(row.discount_total || 0)}</div>` }
    ], topOrders.slice(0, 8));
    renderTable('topProductsTable', [
      { render: (row) => `<strong>${escapeHtml(row.description)}</strong><div class="small text-muted">${escapeHtml(row.product_code || 'Sem SKU')}</div>` },
      { render: (row) => formatNumber(row.quantity) },
      { render: (row) => formatNumber(row.orders) },
      { render: (row) => formatCurrency(row.revenue) }
    ], topProducts.slice(0, 8));
    renderTopClients(topClients.slice(0, 8));
    renderAlerts(alerts);
    renderCommercialHealth(health || { available: false, message: 'Modulo financeiro indisponivel para este carregamento.' });
    renderWeather(weather || { ok: false, message: 'Clima indisponivel no momento.' });
  }

  async function loadVendors(force, options = {}) {
    const data = await fetchJson(buildApiUrl('api/vendors.php', force), options);
    updateGlobalMeta(data);
    renderSummaryCards(data.summary, data.mode);

    const summaryStatus = data.summary_status || {};
    setText('[data-bind="status-billed-value"]', formatCurrency(summaryStatus.billed_value));
    setText('[data-bind="status-billed-orders"]', `${formatNumber(summaryStatus.billed_orders || 0)} NF`);
    setText('[data-bind="status-auth-value"]', formatCurrency(summaryStatus.authorized_value));
    setText('[data-bind="status-auth-orders"]', `${formatNumber(summaryStatus.authorized_orders || 0)} pedidos`);
    setText('[data-bind="status-cancel-value"]', formatCurrency(summaryStatus.cancelled_value));
    setText('[data-bind="status-cancel-orders"]', `${formatNumber(summaryStatus.cancelled_orders || 0)} NF`);
    setText('[data-bind="status-conversion-rate"]', `${summaryStatus.conversion_rate || 0}%`);
    setText('[data-bind="status-backlog-value"]', formatCurrency(summaryStatus.backlog_value));
    setText('[data-bind="status-cancel-rate"]', `${summaryStatus.cancel_rate || 0}%`);

    const total = Number(summaryStatus.billed_value || 0) + Number(summaryStatus.authorized_value || 0) + Number(summaryStatus.cancelled_value || 0);
    if (total > 0) {
      const billed = document.querySelector('#funnelStrip .f-billed');
      const auth = document.querySelector('#funnelStrip .f-auth');
      const cancel = document.querySelector('#funnelStrip .f-cancel');
      if (billed) billed.style.width = `${(Number(summaryStatus.billed_value || 0) / total) * 100}%`;
      if (auth) auth.style.width = `${(Number(summaryStatus.authorized_value || 0) / total) * 100}%`;
      if (cancel) cancel.style.width = `${(Number(summaryStatus.cancelled_value || 0) / total) * 100}%`;
    }

    const matrix = (data.vendor_status_matrix || []).filter((row) => row.vendor_name !== 'Sem vendedor').slice(0, 10);
    createGroupedBarChart('vendorGroupedBar', matrix);
    createMixedDailyChart('dailyMixedChart', data.daily_status || []);
    renderPipelineTable('vendorsTable', matrix);
    renderOperationalAlerts('alertsOperational', data.alerts_operational || []);
  }

  function vendorRiskClass(s) {
    if (s === 'OK') return 'badge-status billed';
    if (s === 'ATENCAO') return 'badge-status authorized';
    if (s === 'SALA_DE_GUERRA') return 'badge-status cancelled';
    return 'badge-status';
  }
  function vendorRiskLabel(s) {
    if (s === 'OK') return 'No alvo';
    if (s === 'ATENCAO') return 'Atencao';
    if (s === 'SALA_DE_GUERRA') return 'Risco';
    return 'Sem meta';
  }

  function renderPipelineTable(targetId, matrix) {
    const target = document.getElementById(targetId);
    if (!target) return;
    if (!matrix.length) {
      target.innerHTML = '<tr><td colspan="9" class="text-center py-4 text-muted">Sem vendedor no periodo.</td></tr>';
      return;
    }
    target.innerHTML = matrix.map((row) => {
      const conversionClass = row.conversion_rate >= 70 ? 'billed' : row.conversion_rate >= 40 ? 'authorized' : 'cancelled';
      const vRef = String(row.vendor_ref || row.vendor_id || '');
      const clickAttr = vRef ? `onclick="window.openVendorDrawer('${escapeHtml(vRef)}')" style="cursor:pointer"` : '';
      const merc = row.billed_merchandise != null ? row.billed_merchandise : row.billed_value;
      const target_ = Number(row.target_period || 0);
      const attPct = Number(row.attainment_pct || 0);
      return `<tr ${clickAttr}>
        <td>
          <strong style="color:var(--page-accent);text-decoration:${vRef ? 'underline' : 'none'}">${escapeHtml(row.vendor_name)}</strong>
          <div class="vendor-micro">
            <span class="vm-billed">${formatNumber(row.billed_orders)} nf</span>
            <span class="vm-auth">${formatNumber(row.authorized_orders)} cart.</span>
            <span class="vm-cancel">${formatNumber(row.cancelled_orders)} canc.</span>
          </div>
        </td>
        <td>${formatCurrency(merc)}</td>
        <td>${target_ > 0 ? formatCurrency(target_) : '<span style="color:var(--text-3)">-</span>'}</td>
        <td>${target_ > 0 ? `<strong>${attPct.toFixed(1)}%</strong>` : '<span style="color:var(--text-3)">-</span>'}</td>
        <td><span class="${vendorRiskClass(row.risk_status)}">${vendorRiskLabel(row.risk_status)}</span></td>
        <td>${formatCurrency(row.authorized_value)}</td>
        <td>${formatCurrency(row.cancelled_value)}</td>
        <td><span class="badge-status ${conversionClass}">${row.conversion_rate}%</span></td>
        <td>${formatCurrency(row.avg_ticket_billed)}</td>
      </tr>`;
    }).join('');
  }

  window.openVendorDrawer = async function (vId) {
    if (!String(vId || '').trim() || vId === 'undefined') return;
    const bg = document.getElementById('flightDrawerBackdrop');
    const panel = document.getElementById('flightDrawer');
    if (!bg || !panel) return;
    bg.style.display = 'block';
    panel.style.display = 'flex';
    setTimeout(() => { panel.style.right = '0'; }, 10);

    const nameEl = document.getElementById('drawerVendorName');
    const statusEl = document.getElementById('drawerVendorStatus');
    const syncEl = document.getElementById('drawerSyncing');
    if (nameEl) nameEl.textContent = 'Abrindo vendedor...';
    if (statusEl) statusEl.textContent = '';
    if (syncEl) syncEl.style.display = 'inline-block';

    const start = document.getElementById('dateStart')?.value || '';
    const end = document.getElementById('dateEnd')?.value || '';
    const params = new URLSearchParams({ vendor_id: vId });
    if (start) params.set('start', start);
    if (end) params.set('end', end);

    try {
      const data = await fetchJson('api/flight_plan_vendor_detail.php?' + params.toString());
      const v = data.vendor || {};

      if (nameEl) nameEl.textContent = v.vendor_name || 'Desconhecido';
      if (statusEl) statusEl.textContent = vendorRiskLabel(v.risk_status || '');

      const setText_ = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
      setText_('drawerRealized', formatCurrency(v.realized_period));
      setText_('drawerTarget', formatCurrency(v.target_period));
      setText_('drawerGap', formatCurrency(v.gap_to_target));
      setText_('drawerPct', (Number(v.attainment_pct || 0)).toFixed(1) + '%');

      const fw = document.getElementById('drawerForecastWrap');
      if (fw) {
        const f = v.forecasts || { base: v.forecast_period || 0, probable: v.forecast_period || 0, commit: v.forecast_period || 0 };
        const maxVal = Math.max(Number(v.target_period || 0), Number(f.base), Number(f.probable), Number(f.commit)) * 1.05 || 1;
        const bar = (lbl, clr, val) => `<div class="detail-drawer-forecast-row">
          <div style="width:70px;font-size:11px;color:var(--text-3);font-weight:600">${lbl}</div>
          <div class="detail-drawer-forecast-track"><div class="detail-drawer-forecast-fill" style="--fill-width:${Math.round((Number(val) / maxVal) * 100)}%;--fill-color:${clr}"></div></div>
          <div style="width:80px;text-align:right;font-size:12px;font-weight:600">${formatCurrency(val)}</div>
        </div>`;
        fw.innerHTML = bar('Commit', getCssVar('--text-3') || '#adb5bd', f.commit)
          + bar('Provavel', getCssVar('--state-info') || '#22a3ff', f.probable)
          + bar('Base', getCssVar('--page-accent') || '#96140a', f.base);
      }

      const cv = document.getElementById('drawerDailyChart');
      if (cv && window.Chart) {
        if (window._vendorDrawerChart) window._vendorDrawerChart.destroy();
        const ds = data.daily_sales || [];
        const labels = ds.map((d) => String(d.date || '').slice(8, 10) + '/' + String(d.date || '').slice(5, 7));
        const values = ds.map((d) => Number(d.gross_revenue || 0));
        window._vendorDrawerChart = new Chart(cv.getContext('2d'), {
          type: 'bar',
          data: { labels, datasets: [{ label: 'NF', data: values, backgroundColor: getCssVar('--page-accent-soft') || hexToRgba(getCssVar('--page-accent') || '#96140a', 0.5), borderRadius: 2, barPercentage: 0.7 }] },
          options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { grid: { display: false }, ticks: { font: { size: 9 } } }, y: { beginAtZero: true, ticks: { callback(val) { const m = val / 1000; return m >= 1 ? m + 'k' : val; } } } } },
        });
      }

      const ow = document.getElementById('drawerOrdersList');
      if (ow) {
        const invoices = data.invoices || [];
        const pipeline = data.pipeline_orders || [];
        const cancelled = data.cancelled_invoices || [];
        const renderList = (items, title, empty, kind) => {
          if (!items.length) return `<div style="font-size:12px;color:var(--text-3);padding:10px 0">${empty}</div>`;
          return `<h4 style="font-size:13px;margin:12px 0 8px">${title}</h4>` + items.map((x) => {
            const refDate = x.status_date || x.issue_date || '';
            const dateLabel = refDate ? refDate.slice(8, 10) + '/' + refDate.slice(5, 7) : '--/--';
            return `<div class="detail-drawer-list-item"><div style="display:flex;flex-direction:column;gap:4px"><strong>${escapeHtml(x.cliente || '')}</strong><span style="font-size:11px;color:var(--text-3)">${dateLabel} | ${kind} ${x.codigo || '-'}</span></div><div style="text-align:right"><div style="font-size:13px;font-weight:700">${formatCurrency(x.merchandise_total || x.total)}</div><small style="color:var(--text-3)">merc.</small></div></div>`;
          }).join('');
        };
        ow.innerHTML = renderList(invoices, 'NF autorizadas', 'Nenhuma NF no periodo.', 'NF')
          + renderList(pipeline, 'Carteira', 'Sem pedidos na carteira.', 'Pedido')
          + renderList(cancelled, 'NF canceladas', 'Nenhuma NF cancelada.', 'NF');
      }
    } catch (err) {
      if (nameEl) nameEl.textContent = 'Erro ao carregar';
      const ow = document.getElementById('drawerOrdersList');
      if (ow) ow.innerHTML = `<div class="module-alert module-alert--danger" style="margin-top:10px">${escapeHtml(err.message)}</div>`;
    } finally {
      if (syncEl) syncEl.style.display = 'none';
    }
  };

  window.closeVendorDrawer = function () {
    const panel = document.getElementById('flightDrawer');
    const bg = document.getElementById('flightDrawerBackdrop');
    if (panel) panel.style.right = '-450px';
    if (bg) setTimeout(() => { bg.style.display = 'none'; }, 300);
  };

  function renderOperationalAlerts(targetId, alerts) {
    const target = document.getElementById(targetId);
    if (!target) return;
    if (!alerts || !alerts.length) {
      target.innerHTML = '<div class="module-alert module-alert--info"><strong>Sem alerta</strong><span>Time sem alerta operacional no periodo.</span></div>';
      return;
    }
    target.innerHTML = alerts.map((alert) => {
      const level = alert.level === 'danger' ? 'danger' : alert.level === 'warning' ? 'warning' : 'info';
      return `<div class="module-alert module-alert--${level}"><strong>${escapeHtml(alert.title)}</strong><span>${escapeHtml(alert.message)}</span></div>`;
    }).join('');
  }

  async function loadOrders(force, options = {}) {
    const data = await fetchJson(buildApiUrl('api/orders.php', force), options);
    updateGlobalMeta(data);
    renderSummaryCards(data.summary, data.mode);

    createOrdersStatusChart('ordersStatusChart', data.orders || [], data.pending_orders || [], data.cancelled_orders || []);
    createOrdersVendorChart('ordersVendorChart', data.orders || []);

    renderTable('ordersTable', [
      { render: (row) => `<strong>${escapeHtml(row.codigo)}</strong><div class="small text-muted">${escapeHtml(row.codigo_integracao || 'Sem integracao')}</div>` },
      { render: (row) => `<strong>${escapeHtml(row.cliente)}</strong>${row.cliente_documento ? `<div class="small text-muted">${escapeHtml(row.cliente_documento)}</div>` : ''}` },
      { render: (row) => escapeHtml(row.vendor_name || 'Sem vendedor') },
      { render: (row) => formatOrderDate(row, true) },
      { render: (row) => formatNumber(row.items_qty) },
      { render: (row) => `${formatCurrency(row.gross_total ?? row.total)}<div class="small text-muted">Merc.: ${formatCurrency(row.merchandise_total || 0)}</div><div class="small text-muted">Desc.: ${formatCurrency(row.discount_total || 0)}</div>` }
    ], data.orders || [], 'Sem NF autorizada no periodo.');

    renderTable('pendingOrdersTable', [
      { render: (row) => `<strong>${escapeHtml(row.codigo)}</strong>` },
      { render: (row) => `<strong>${escapeHtml(row.cliente)}</strong>` },
      { render: (row) => escapeHtml(row.vendor_name || 'Sem vendedor') },
      { render: (row) => formatOrderDate(row, true) },
      { render: (row) => formatCurrency(row.gross_total ?? row.total) }
    ], (data.pending_orders || []).slice(0, 8), 'Sem carteira no periodo.');

    renderTable('cancelledOrdersTable', [
      { render: (row) => `<strong>${escapeHtml(row.codigo)}</strong>` },
      { render: (row) => `<strong>${escapeHtml(row.cliente)}</strong>` },
      { render: (row) => escapeHtml(row.vendor_name || 'Sem vendedor') },
      { render: (row) => formatOrderDate(row, true) },
      { render: (row) => formatCurrency(row.gross_total ?? row.total) }
    ], (data.cancelled_orders || []).slice(0, 8), 'Sem NF cancelada no periodo.');
  }

  async function loadProducts(force, options = {}) {
    const data = await fetchJson(buildApiUrl('api/products.php', force), options);
    updateGlobalMeta(data);
    renderSummaryCards(data.summary, data.mode);
    createProductMixChart('productsRevenueChart', data.products || []);
    createProductShareChart('productsShareChart', data.products || []);
    renderTable('productsTable', [
      { render: (row) => `<button type="button" class="product-breakdown-trigger" data-product-code="${escapeHtml(row.product_code || '')}" data-product-label="${escapeHtml(row.description || 'Produto sem descricao')}"><strong>${escapeHtml(row.description)}</strong><div class="small text-muted">${escapeHtml(row.product_code || 'Sem SKU')}</div></button>` },
      { render: (row) => formatNumber(row.quantity) },
      { render: (row) => formatNumber(row.orders) },
      { render: (row) => formatCurrency(row.revenue) }
    ], data.products || [], 'Sem item fiscal no periodo.');
    bindProductBreakdownTriggers();
  }

  function bindProductBreakdownTriggers() {
    document.querySelectorAll('.product-breakdown-trigger').forEach((button) => {
      if (button.dataset.bound === '1') return;
      button.dataset.bound = '1';
      button.addEventListener('click', () => {
        window.openProductBreakdown(
          button.getAttribute('data-product-code') || '',
          button.getAttribute('data-product-label') || 'Produto sem descricao'
        );
      });
    });
  }

  function renderProductBreakdownRows(rows) {
    return (rows || []).map((row) => `
      <tr>
        <td><strong>${escapeHtml(row.nf_code || '-')}</strong></td>
        <td>${escapeHtml(row.origin_order_code || '-')}</td>
        <td>${escapeHtml(row.vendor_name || 'Sem vendedor')}</td>
        <td>${escapeHtml(row.client_name || 'Cliente nao informado')}</td>
        <td>${formatMetaDate(row.competencia_date || row.issue_date || '')}</td>
        <td>${formatNumber(row.quantity || 0)}</td>
        <td>${formatCurrency(row.revenue || 0)}</td>
        <td>${formatCurrency(row.discount || 0)}</td>
      </tr>
    `).join('');
  }

  function applyProductBreakdownFilter() {
    const input = document.getElementById('productBreakdownFilter');
    const body = document.getElementById('productBreakdownTable');
    if (!input || !body) return;
    const needle = String(input.value || '').trim().toLowerCase();
    body.querySelectorAll('tr').forEach((row) => {
      const hay = String(row.textContent || '').toLowerCase();
      row.style.display = needle === '' || hay.includes(needle) ? '' : 'none';
    });
  }

  function initProductBreakdownModal() {
    const modal = document.getElementById('productBreakdownModal');
    const backdrop = document.getElementById('productBreakdownBackdrop');
    const closeBtn = document.getElementById('closeProductBreakdown');
    const filter = document.getElementById('productBreakdownFilter');
    if (!modal || !backdrop) return;

    const close = () => {
      modal.hidden = true;
      backdrop.hidden = true;
      modal.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('modal-open');
    };

    window.closeProductBreakdown = close;

    closeBtn?.addEventListener('click', close);
    backdrop.addEventListener('click', close);
    modal.addEventListener('click', (event) => event.stopPropagation());
    filter?.addEventListener('input', applyProductBreakdownFilter);

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && !modal.hidden) {
        close();
      }
    });
  }

  window.openProductBreakdown = async function (productCode, productLabel) {
    const modal = document.getElementById('productBreakdownModal');
    const backdrop = document.getElementById('productBreakdownBackdrop');
    const title = document.getElementById('productBreakdownTitle');
    const subtitle = document.getElementById('productBreakdownSubtitle');
    const body = document.getElementById('productBreakdownTable');
    const empty = document.getElementById('productBreakdownEmpty');
    const loading = document.getElementById('productBreakdownLoading');
    const meta = document.getElementById('productBreakdownMeta');
    const filter = document.getElementById('productBreakdownFilter');
    if (!modal || !backdrop || !body) return;

    if (filter) filter.value = '';
    modal.hidden = false;
    backdrop.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('modal-open');
    if (title) title.textContent = productLabel || 'Produto sem descricao';
    if (subtitle) subtitle.textContent = productCode ? `SKU ${productCode}` : 'Sem SKU';
    if (meta) meta.innerHTML = '';
    if (loading) loading.hidden = false;
    if (empty) empty.hidden = true;
    body.innerHTML = '';

    try {
      const data = await fetchJson(buildApiUrl('api/products.php', false, {
        product_code: productCode || '',
        limit: 200,
      }));
      const rows = Array.isArray(data.breakdown) ? data.breakdown : [];
      if (meta) {
        meta.innerHTML = [
          `<span class="mag-status-chip mag-status-live">${formatNumber(rows.length)} documento(s)</span>`,
          data.meta?.coverage_actual?.end ? `<span class="mag-status-chip mag-status-demo">Cobertura ate ${formatMetaDate(data.meta.coverage_actual.end)}</span>` : '',
          data.meta?.signal_status === 'partial_window' ? '<span class="mag-status-chip mag-status-warning">Cobertura parcial</span>' : '',
        ].filter(Boolean).join(' ');
      }
      body.innerHTML = renderProductBreakdownRows(rows);
      if (empty) empty.hidden = rows.length > 0;
    } catch (error) {
      if (empty) {
        empty.hidden = false;
        empty.textContent = error?.message || 'Nao foi possivel carregar o detalhamento do produto.';
      }
    } finally {
      if (loading) loading.hidden = true;
    }
  };

  function syncAgeLabel(isoDate) {
    if (!isoDate) return null;
    const diffMs = Date.now() - new Date(isoDate).getTime();
    if (Number.isNaN(diffMs) || diffMs < 0) return null;
    const mins = Math.floor(diffMs / 60000);
    if (mins < 1) return 'ha menos de 1 min';
    if (mins < 60) return `ha ${mins} min`;
    const hrs = Math.floor(mins / 60);
    if (hrs < 24) return `ha ${hrs}h`;
    return `ha ${Math.floor(hrs / 24)} dia(s)`;
  }

  async function loadIntegrations(options = {}) {
    const data = await fetchJson(buildApiUrl('api/status.php', false), options);
    setHtml('#integrationModeBadge', renderStatusBadge(data.mode));
    setText('#integrationAppName', data.app_name || 'BIomie Comercial MAG');
    setText('#integrationTimezone', data.timezone || 'America/Fortaleza');
    setText('#integrationCredentials', data.credentials_configured ? 'Configuradas' : 'Pendentes');
    setText('#integrationOrdersEndpoint', data.endpoints?.orders || '-');
    setText('#integrationVendorsEndpoint', data.endpoints?.vendors || '-');
    setText('#integrationClientsEndpoint', data.endpoints?.clients || '-');
    setText('#integrationOrdersFatEndpoint', data.endpoints?.orders_fat || '-');
    setText('#integrationReceivableEndpoint', data.endpoints?.accounts_receivable || '-');
    setText('#integrationCacheAge', data.cache?.age_seconds == null ? 'Sem cache' : `${formatNumber(data.cache.age_seconds)} s`);
    setText('#integrationCacheDate', data.cache?.modified_at ? new Date(data.cache.modified_at).toLocaleString('pt-BR') : '-');
    const notes = document.getElementById('integrationNotes');
    if (notes) notes.innerHTML = (data.notes || []).map((note) => `<li>${escapeHtml(note)}</li>`).join('');

    // Ã¢â€â‚¬Ã¢â€â‚¬ SituaÃƒÂ§ÃƒÂ£o dos syncs Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
    const ss = data.sync_status || {};

    const commercialAt = ss.canonical_commercial?.last_sync_at;
    setText('#syncStatusCommercial', commercialAt ? new Date(commercialAt).toLocaleString('pt-BR') : (ss.canonical_commercial?.available ? 'Sem registro' : 'Nao disponivel'));
    setText('#syncStatusCommercialAge', syncAgeLabel(commercialAt) || '');

    const financialAt = ss.canonical_financial?.last_sync_at;
    setText('#syncStatusFinancial', financialAt ? new Date(financialAt).toLocaleString('pt-BR') : (ss.canonical_financial?.available ? 'Sem registro' : 'Nao disponivel'));
    setText('#syncStatusFinancialAge', syncAgeLabel(financialAt) || '');

    const snapshotAt = ss.snapshot_commercial?.generated_at;
    const snapshotAge = ss.snapshot_commercial?.age_seconds;
    setText('#syncStatusSnapshot', snapshotAt ? new Date(snapshotAt).toLocaleString('pt-BR') : 'Sem cache');
    setText('#syncStatusSnapshotAge', snapshotAge != null ? `Idade: ${formatNumber(snapshotAge)} s` : '');

    const cursors = Array.isArray(ss.pipeline_cursors) ? ss.pipeline_cursors : [];
    if (cursors.length === 0) {
      setText('#syncStatusPipeline', 'Nenhum cursor');
    } else {
      const activeDomains = [...new Set(cursors.map((c) => c.domain))].join(', ');
      setText('#syncStatusPipeline', activeDomains || `${cursors.length} cursor(es)`);
    }
  }

  function setDefaultMonth() {
    const startInput = document.getElementById('dateStart');
    const endInput = document.getElementById('dateEnd');
    if (!startInput || !endInput || startInput.value || endInput.value) return;
    applyPresetRange('month');
  }

  let loadController = null;
  const LOADING_MIN_MS = 420;

  function waitFor(ms) {
    return new Promise((resolve) => {
      window.setTimeout(resolve, Math.max(0, ms));
    });
  }

  async function pollRefreshStatus(service) {
    const feedback = document.getElementById('syncFeedback');
    for (let attempt = 0; attempt < 20; attempt += 1) {
      await new Promise((resolve) => window.setTimeout(resolve, 1500));
      const data = await fetchJson(buildApiUrl('api/refresh_status.php', false, { service }), { cache: 'no-store', timeoutMs: 10000 });
      if (['done', 'ready'].includes(String(data.state || '').toLowerCase())) return data;
      if (String(data.state || '').toLowerCase() === 'failed') {
        throw new Error(data.job?.last_error || 'Falha ao sincronizar. Tente novamente em instantes.');
      }
      const syncStateLabels = {
        queued: 'Sincronizacao agendada, aguardando worker...',
        pending: 'Sincronizacao na fila de processamento...',
        running: 'Sincronizacao canonica em andamento...',
        processing: 'Processando dados do periodo...',
        syncing: 'Buscando dados no ERP...',
        finalizing: 'Finalizando snapshot do periodo...',
      };
      const stateKey = String(data.state || '').toLowerCase();
      const stateLabel = syncStateLabels[stateKey] || `Atualizando (${escapeHtml(String(data.state || ''))})...`;
      if (feedback) feedback.innerHTML = `<div class="alert alert-secondary">${stateLabel}</div>`;
    }
    throw new Error('A atualizacao demorou mais que o esperado. Recarregue em instantes.');
  }

  async function syncNow(event) {
    if (event) {
      event.preventDefault();
      event.stopPropagation();
    }
    const feedback = document.getElementById('syncFeedback');
    const csrfToken = document.querySelector('meta[name="biomie-csrf"]')?.getAttribute('content') || '';
    const body = new URLSearchParams();
    if (csrfToken) body.set('csrf_token', csrfToken);
    if (feedback) feedback.innerHTML = '<div class="alert alert-secondary">Agendando sincronizacao comercial...</div>';
    try {
      const { response, payload } = await window.fetchApi(buildApiUrl('api/sync.php', false), {
        method: 'POST',
        cache: 'no-store',
        headers: {
          Prefer: 'respond-async',
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
        },
        body: body.toString(),
        timeoutMs: 15000
      });
      if (feedback) feedback.innerHTML = `<div class="alert alert-success">${escapeHtml(payload.message || 'Sincronizacao iniciada.')}</div>`;
      if (response.status === 202 || String(payload.mode || '').toLowerCase() === 'queued') {
        await pollRefreshStatus('sales');
        await loadCurrentPage(false);
        return;
      }
      await loadCurrentPage(true);
    } catch (error) {
      const extra = error?.code === 419 ? ' Recarregue a pagina para renovar a sessao.' : '';
      if (feedback) feedback.innerHTML = `<div class="alert alert-danger">${escapeHtml(error.message + extra)}</div>`;
    } finally {
      window.setTimeout(() => {
        if (feedback) feedback.innerHTML = '';
      }, 5000);
    }
  }

  function initMenuToggle() {
    const toggle = document.getElementById('menu_toggle');
    if (!toggle) return;
    toggle.addEventListener('click', (event) => {
      event.preventDefault();
      document.body.classList.toggle('nav-md');
      document.body.classList.toggle('nav-sm');
    });
  }

  function highlightCurrentMenu() {
    const current = window.location.pathname.split('/').pop() || 'index.html';
    document.querySelectorAll('#sidebar-menu a[href]').forEach((link) => {
      const href = link.getAttribute('href');
      if (href === current) {
        link.parentElement.classList.add('current-page');
        link.style.color = getCssVar('--page-accent') || '#96140a';
        link.style.background = getCssVar('--red-dim') || 'rgba(150,20,10,0.22)';
        link.style.borderRadius = '10px';
      }
    });
  }

  function initRangePresets() {
    document.querySelectorAll('[data-range-preset]').forEach((button) => {
      button.addEventListener('click', () => {
        applyPresetRange(button.dataset.rangePreset);
        loadCurrentPage(false);
      });
    });
  }

  async function loadCurrentPage(force) {
    const page = document.body.dataset.page || 'dashboard';
    const loading = document.getElementById('pageLoading');
    const errorTarget = document.getElementById('pageError');
    const loadingStartedAt = loading ? performance.now() : 0;
    if (loading) loading.classList.remove('d-none');
    if (errorTarget) errorTarget.classList.add('d-none');
    if (loadController) loadController.abort();
    loadController = new AbortController();
    try {
      const options = { cache: 'no-store', signal: loadController.signal, timeoutMs: 30000 };
      if (page === 'vendors') await loadVendors(force, options);
      else if (page === 'orders') await loadOrders(force, options);
      else if (page === 'products') await loadProducts(force, options);
      else if (page === 'integrations') await loadIntegrations(options);
      else await loadDashboard(force, options);
    } catch (error) {
      if (errorTarget) {
        errorTarget.classList.remove('d-none');
        const extra = error?.code === 419 ? '<div class="small mt-2">Recarregue a pagina para renovar a sessao.</div>' : '';
        errorTarget.innerHTML = `<div class="alert alert-danger mb-0">${escapeHtml(error.message)}${extra}</div>`;
      }
    } finally {
      if (loading) {
        const elapsed = performance.now() - loadingStartedAt;
        if (elapsed < LOADING_MIN_MS) {
          await waitFor(LOADING_MIN_MS - elapsed);
        }
        loading.classList.add('d-none');
      }
    }
  }

  async function syncCanonical(event) {
    if (event) { event.preventDefault(); event.stopPropagation(); }
    const feedback = document.getElementById('syncFeedback');
    const btn = document.getElementById('syncCanonical');
    const csrfToken = document.querySelector('meta[name="biomie-csrf"]')?.getAttribute('content') || '';
    const body = new URLSearchParams();
    if (csrfToken) body.set('csrf_token', csrfToken);
    if (btn) btn.disabled = true;
    if (feedback) feedback.innerHTML = '<div class="alert alert-secondary">Iniciando sync canonico Ã¢â‚¬â€ buscando dados no Omie. Isso pode levar alguns minutos...</div>';
    try {
      const { payload } = await window.fetchApi(buildApiUrl('api/canonical_sync.php', false), {
        method: 'POST',
        cache: 'no-store',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: body.toString(),
        timeoutMs: 320000,
      });
      if (feedback) feedback.innerHTML = `<div class="alert alert-success">${escapeHtml(payload?.message || 'Sync canonico concluido.')}</div>`;
      await loadCurrentPage(false);
    } catch (error) {
      const extra = error?.code === 401 || error?.code === 403 ? ' Apenas administradores podem disparar o sync canonico.' : '';
      if (feedback) feedback.innerHTML = `<div class="alert alert-danger">${escapeHtml(error.message)}${extra}</div>`;
    } finally {
      if (btn) btn.disabled = false;
      window.setTimeout(() => { if (feedback) feedback.innerHTML = ''; }, 8000);
    }
  }

  async function syncFinance(event) {
    if (event) { event.preventDefault(); event.stopPropagation(); }
    const feedback = document.getElementById('syncFeedback');
    const btn = document.getElementById('syncFinance');
    const csrfToken = document.querySelector('meta[name="biomie-csrf"]')?.getAttribute('content') || '';
    const body = new URLSearchParams();
    if (csrfToken) body.set('csrf_token', csrfToken);
    if (btn) btn.disabled = true;
    if (feedback) feedback.innerHTML = '<div class="alert alert-secondary">Agendando sync financeiro...</div>';
    try {
      const { response, payload } = await window.fetchApi(buildApiUrl('api/finance_sync.php', false), {
        method: 'POST',
        cache: 'no-store',
        headers: {
          Prefer: 'respond-async',
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        },
        body: body.toString(),
        timeoutMs: 15000,
      });
      if (feedback) feedback.innerHTML = `<div class="alert alert-success">${escapeHtml(payload?.message || 'Sync financeiro iniciado.')}</div>`;
      if (response.status === 202 || String(payload?.mode || '').toLowerCase() === 'queued') {
        await pollRefreshStatus('finance');
      }
      await loadCurrentPage(false);
    } catch (error) {
      if (feedback) feedback.innerHTML = `<div class="alert alert-danger">${escapeHtml(error.message)}</div>`;
    } finally {
      if (btn) btn.disabled = false;
      window.setTimeout(() => { if (feedback) feedback.innerHTML = ''; }, 8000);
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    setDefaultMonth();
    initProductBreakdownModal();
    initMenuToggle();
    highlightCurrentMenu();
    initRangePresets();
    document.getElementById('applyFilters')?.addEventListener('click', () => { loadCurrentPage(false); });
    document.getElementById('syncNow')?.addEventListener('click', syncNow);
    document.getElementById('syncSnapshot')?.addEventListener('click', syncNow);
    document.getElementById('syncCanonical')?.addEventListener('click', syncCanonical);
    document.getElementById('syncFinance')?.addEventListener('click', syncFinance);
    document.getElementById('closeDrawerBtn')?.addEventListener('click', window.closeVendorDrawer);
    document.getElementById('flightDrawerBackdrop')?.addEventListener('click', window.closeVendorDrawer);
    loadCurrentPage(false);

    if (window.EventSource && document.body.dataset.page === 'index') {
      let liveStreamSince = '';
      const sse = new window.EventSource('api/live_stream.php');
      sse.addEventListener('refresh_dashboard', (e) => {
        try {
          const data = JSON.parse(e.data);
          liveStreamSince = data.timestamp;
          console.log('[SSE] Omie Dashboard payload refresh received', data.timestamp);
          loadCurrentPage(false, true); // true = silent if possible, or just standard reload
        } catch (err) {}
      });
    }
  });
})();

(function () {
  'use strict';
  window.BIomieFlightPlan = window.BIomieFlightPlan || {};

  const numBRL = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
  const numBR = new Intl.NumberFormat('pt-BR');

  function fmt(v) { return numBRL.format(Number(v || 0)); }
  function fmtN(v, d = 0) {
    if (d > 0) return Number(v || 0).toLocaleString('pt-BR', { minimumFractionDigits: d, maximumFractionDigits: d });
    return numBR.format(Number(v || 0));
  }
  function toLocalIso(d) {
    const tzOffset = d.getTimezoneOffset() * 60000;
    return new Date(d - tzOffset).toISOString().slice(0, 10);
  }
  function todayIso() { return toLocalIso(new Date()); }
  function pctOf(val, tot) {
    const v = Number(val || 0), t = Number(tot || 0);
    return t > 0 ? (v / t) * 100 : 0;
  }
  function formatMetaDate(isoString) {
    if (!isoString) return '';
    try {
      const d = new Date(isoString);
      if (isNaN(d.getTime())) return isoString;
      return d.toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    } catch (e) { return isoString; }
  }
  function normalizeServedFrom(sourceStr) {
    if (!sourceStr) return 'cache';
    return String(sourceStr).toLowerCase();
  }
  function humanizeServedFrom(sourceStr, labelStr) {
    const s = normalizeServedFrom(sourceStr);
    const l = String(labelStr || '').toLowerCase();
    if (l === 'fallback overview') return 'Fallback (Overview)';
    if (s === 'db_materialized') return 'Banco (Consolidado)';
    if (s === 'cache') return 'Cache Rapido';
    if (s === 'live') return 'Tempo Real';
    return 'Cache Rapido';
  }
  function resolveCoverageLabel(payload) {
    if (!payload) return 'N/A';
    const c = Number(payload.team?.coverage_pct || 0);
    if (c >= 100) return 'Total (100%)';
    return `${fmtN(c, 1)}%`;
  }
  function statusLabel(s) {
    if (s === 'OK') return 'OK';
    if (s === 'SEM_META') return 'Meta pendente';
    if (s === 'ATENCAO') return 'Atenção';
    if (s === 'SALA_DE_GUERRA') return 'Risco Alto';
    return s || '-';
  }
  function statusClass(s) {
    if (s === 'OK') return 'status-chip status-chip--ok';
    if (s === 'SEM_META') return 'status-chip status-chip--muted';
    if (s === 'ATENCAO') return 'status-chip status-chip--attention';
    if (s === 'SALA_DE_GUERRA') return 'status-chip status-chip--danger';
    return 'status-chip status-chip--muted';
  }
  function cssVar(name) {
    return getComputedStyle(document.body || document.documentElement).getPropertyValue(name).trim();
  }
  function theme(name, fallback) {
    const val = cssVar(name);
    return val || fallback;
  }
  function hexRgba(hex, alpha) {
    let r = 0, g = 0, b = 0;
    if (hex.length === 4) {
      r = "0x" + hex[1] + hex[1]; g = "0x" + hex[2] + hex[2]; b = "0x" + hex[3] + hex[3];
    } else if (hex.length === 7) {
      r = "0x" + hex[1] + hex[2]; g = "0x" + hex[3] + hex[4]; b = "0x" + hex[5] + hex[6];
    }
    return `rgba(${+r},${+g},${+b},${alpha})`;
  }

  window.BIomieFlightPlan.Formatters = {
    fmt, fmtN, toLocalIso, todayIso, pctOf, formatMetaDate,
    normalizeServedFrom, humanizeServedFrom, resolveCoverageLabel,
    statusLabel, statusClass, cssVar, theme, hexRgba
  };
})();

# Auditoria de flight-plan-v2.js e Plano de Modularização

## Mapa de Funções Atuais
- Utilitários: `fmt`, `fmtN`, `cssVar`, `theme`, `hexRgba`, `toLocalIso`, `todayIso`, `pctOf`, `setText`, `setHtml`, `escH`, `detailRef`
- Helpers Específicos: `formatMetaDate`, `normalizeServedFrom`, `humanizeServedFrom`, `resolveCoverageLabel`, `renderSyncStateBadges`, `statusLabel`, `statusClass`, `enrich`, `dedup`
- Filtros: `setDates`, `getDates`, `preset`, `apiUrl`, `currentYM`
- API & Core: `fetchJ`, `loadFlightPlan`, `showLoading`
- Renderização Principal: `updateMeta`, `updateTeam`, `renderRiskBadge`, `renderBulletChart`, `renderForecastTriplets`, `renderRiskRanking`, `renderLine`, `renderAchievementChart`, `renderCharts`, `renderVendorTable`, `renderAlerts`
- Outros (Apoio Metas): `setStatus`, `renderTargetsForm`, `setupTargetsListeners`
- Event Listeners globais: `document.getElementById('applyFilters').addEventListener(...)`, etc.

## Destinos (Os Módulos)
- `formatters.js`: `fmt`, `fmtN`, `formatMetaDate`, `statusLabel`, `statusClass`, `humanizeServedFrom` e utilitários gerais.
- `dom.js`: `setText`, `setHtml`, `escH`, `detailRef`, `showLoading`.
- `state.js`: Gestão de payload e `abortCtrl`. Contém `enrich` e `dedup`.
- `api.js`: `fetchFlightPlan()`.
- `filters.js`: `setDates`, `getDates`, `preset`, `apiUrl`.
- `kpis.js`: `updateMeta`, `updateTeam`, `renderRiskBadge`, `renderForecastTriplets`, `renderRiskRanking`.
- `vendor-table.js`: `renderVendorTable`, `renderTargetsForm`.
- `charts.js`: `renderAchievementChart`, `renderLine`, `renderCharts`.
- `alerts.js`: `renderAlerts`.
- `index.js`: Lógica de boot, wiring (listeners) e orquestração chamando módulos.
- `flight-plan-v2.js`: Reduzido para ser apenas uma fachada de retrocompatibilidade: inicializa o boot e expõe `window.applyFilters`.

## Ordem dos Scripts (`views/plano_voo.html`)
```html
<script src="assets/js/modules/flight-plan/formatters.js" defer></script>
<script src="assets/js/modules/flight-plan/dom.js" defer></script>
<script src="assets/js/modules/flight-plan/state.js" defer></script>
<script src="assets/js/modules/flight-plan/api.js" defer></script>
<script src="assets/js/modules/flight-plan/filters.js" defer></script>
<script src="assets/js/modules/flight-plan/kpis.js" defer></script>
<script src="assets/js/modules/flight-plan/charts.js" defer></script>
<script src="assets/js/modules/flight-plan/vendor-table.js" defer></script>
<script src="assets/js/modules/flight-plan/alerts.js" defer></script>
<script src="assets/js/modules/flight-plan/index.js" defer></script>
<script src="assets/js/flight-plan-v2.js?v=20260415sk1" defer></script>
```

## Riscos
1. Perda de bindings globais. Trataremos isso injetando estritamente ao namespace `window.BIomieFlightPlan`.
2. Quebra do Canvas. Isolaremos corretamente no `charts.js`.
3. Corrida assíncrona. Será evitada carregando os módulos nativamente via `defer` na ordem correta, culminando com o disparo do `DOMContentLoaded`.

'use strict';

const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8');

const view = read('views/index.html');
const dashboard = read('assets/js/modules/dashboard/page-drilldown.js');

const checks = [
  [
    'home nao renderiza uma segunda barra de filtros',
    !view.includes('id="dashboardFilterHub"')
      && !view.includes('data-mobile-filter-sheet')
      && !view.includes('Resultado por vendedor'),
  ],
  [
    'ponte legada fica oculta e fora do layout',
    view.includes('id="dashboardFilterBridge" hidden aria-hidden="true"')
      && view.includes('id="dateStart"')
      && view.includes('id="dateEnd"')
      && view.includes('id="f_dash_vendor_id"'),
  ],
  [
    'filtros contextuais sao registrados no header global',
    dashboard.includes("'biomie:filter-register'")
      && dashboard.includes("source: 'dashboard'")
      && dashboard.includes("key: 'cliente'")
      && dashboard.includes("key: 'produto'")
      && dashboard.includes("key: 'quick'"),
  ],
  [
    'aplicacao global e cancelada pela home para evitar request duplicado',
    dashboard.includes("document.addEventListener('biomie:filter-apply', handleGlobalFilterApply)")
      && dashboard.includes('if (event.cancelable) event.preventDefault();')
      && dashboard.includes('return requestCurrentView(false);'),
  ],
  [
    'troca de vendedores atualiza a home sem recarregar a pagina inteira',
    dashboard.includes("document.addEventListener('biomie:vendor-filter-apply', handleVendorFilterApply)")
      && dashboard.includes('function handleVendorFilterApply(event)')
      && dashboard.includes("showError(error && error.message ? error.message : 'Falha ao aplicar vendedores.');"),
  ],
  [
    'periodo e extras sincronizam os IDs consumidos pelos modulos',
    dashboard.includes("document.getElementById('dateStart')")
      && dashboard.includes("document.getElementById('dateEnd')")
      && dashboard.includes("document.getElementById('f_dash_cliente')")
      && dashboard.includes("document.getElementById('f_dash_produto')"),
  ],
  [
    'multiplos vendedores continuam protegidos pelo diretorio permitido',
    dashboard.includes('var allowedIds = requestedIds.filter')
      && dashboard.includes('persistVendorSelection(allowedIds')
      && dashboard.includes("params.set('vendor_ids', currentVendorIds.join(','))")
      && dashboard.includes("params.set('vendor_id', currentVendorId)"),
  ],
  [
    'painel agregado, atalho individual e limpeza inteligente permanecem disponiveis',
    view.includes('id="dashboardSellerFocus"')
      && dashboard.includes("'Resultado conjunto'")
      && dashboard.includes('currentVendorIds.length !== 1')
      && dashboard.includes("currentQuick = '';")
      && dashboard.includes('persistVendorSelection([], []);'),
  ],
  [
    'agenda e mini calendario recebem todo o conjunto selecionado',
    dashboard.includes("agendaParams.set('seller_ids', currentVendorIds.join(','))")
      && view.includes('sellerIds: initialCalendarVendorIds')
      && view.includes('typeof cal.setSellers'),
  ],
];

const failed = checks.filter(([, ok]) => !ok);
if (failed.length) {
  failed.forEach(([name]) => process.stderr.write(`FAIL ${name}\n`));
  process.exit(1);
}

process.stdout.write(`PASS dashboard compact global filter contract (${checks.length}/${checks.length})\n`);

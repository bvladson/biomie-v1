/* Synthetic rows and containers only; no feature logic or application/API calls. */
(function () {
  'use strict';
  var params = new URLSearchParams(location.search);
  var scenario = params.get('scenario') || 'tall';
  var slot = document.getElementById('fixture-slot');
  var sequence = 0;

  function table(rows, fit, tableClass) {
    var columns = fit ? 3 : 20;
    var head = '';
    for (var col = 0; col < columns; col += 1) head += '<th scope="col">' + (col ? 'Indicador ' + col : 'Produto') + '</th>';
    var body = '';
    for (var row = 0; row < rows; row += 1) {
      body += '<tr>';
      for (var cell = 0; cell < columns; cell += 1) body += '<td>' + (cell ? (row + 1) * (cell + 1) : 'Produto sintético ' + (row + 1)) + '</td>';
      body += '</tr>';
    }
    return '<table class="fixture-table ' + (tableClass || 'mag-table') + (fit ? ' fixture-table--fit' : '') + '"><caption class="mag-sr-only">Produtos sintéticos</caption><thead><tr>' + head + '</tr></thead><tbody>' + body + '</tbody></table>';
  }
  function wrapper(id, content, className, style) {
    return '<div id="' + id + '" class="' + (className || 'mag-table-scroll') + '"' + (style ? ' style="' + style + '"' : '') + '>' + content + '</div>';
  }
  function render(kind) {
    var rows = kind === 'short' ? 3 : 80;
    var content = table(rows, kind === 'fit');
    var output = wrapper('source', content);
    if (kind === 'internal') output = wrapper('source', content, 'fin-table-wrap', 'overflow:auto;max-height:340px');
    if (kind === 'clipped') output = '<div id="clip" class="fixture-clip">' + output + '</div>';
    if (kind === 'panel') output = '<section id="panel" class="fixture-panel"><div class="fixture-panel-spacer"></div>' + output + '</section>';
    if (kind === 'nested') output = '<section id="outer-panel" class="fixture-panel"><section id="panel" class="fixture-panel">' + output + '</section></section>';
    if (kind === 'nested-table') output = wrapper('source', '<table class="fixture-table mag-table"><tbody><tr><td>' + table(50, false) + '</td></tr></tbody></table>');
    if (kind === 'nf') output = '<div id="nfPeriodoTableContainer" class="fixture-panel" style="overflow-x:hidden">' + wrapper('source', table(80, false, 'biomie-table nf-periodo-table'), 'nf-table-scroll', 'overflow-x:auto') + '</div>';
    if (kind === 'flight') output = wrapper('source', table(80, false, 'fp-table'), 'fp-table-wrapper');
    if (kind === 'dashboard') output = wrapper('source', content, 'dashboard-panel__body--table');
    if (kind === 'plain-drawer' || kind === 'drawer' || kind === 'hidden-drawer') {
      output = '<aside id="fixture-drawer" class="biomie-drawer' + (kind === 'hidden-drawer' ? '' : ' open is-open') + '" aria-label="Detalhes de produto"><header class="biomie-drawer__header"><h2>Produto 360</h2><button id="drawer-close">Fechar</button></header><div class="biomie-drawer__tabs"><button class="biomie-drawer__tab">Resumo</button><button class="biomie-drawer__tab">Histórico</button></div><div id="drawer-body" class="biomie-drawer__body"><div id="drawer-filters"><label>Família <input id="drawer-filter-input" value="Todas"></label></div>' + (kind === 'plain-drawer' ? table(80, false, 'drawer-table').replace('<table ', '<table id="plain-table" ') : wrapper('source', table(80, false, ' '), 'biomie-drawer__table-wrap')) + '</div><footer class="fixture-drawer-footer">Ações do painel</footer></aside>';
    }
    if (kind === 'table-source') output = table(80, false).replace('<table ', '<table id="source" data-mobile-scroll-only style="display:block;overflow-x:auto;max-width:100%;width:100% !important;min-width:0 !important" ');
    if (kind === 'optin') output = '<div id="source" data-biomie-floating-scroll="on" data-biomie-floating-scroll-label="Rolar indicadores" style="overflow-x:auto"><div style="width:2800px;height:3200px">Relatório configurado explicitamente</div></div>';
    if (kind === 'optout') output = '<section data-biomie-floating-scroll="off">' + output + '</section>';
    if (kind === 'hidden') output = '<section id="hidden-panel" hidden>' + output + '</section>';
    if (kind === 'footer') output += '<footer id="fixed-footer" data-biomie-floating-scroll-obstacle class="fixture-fixed-footer">Rodapé fixo com ações</footer>';
    output += '<div id="carousel" class="fixture-carousel"><span>Carrossel</span><span>Sem barra adicional</span><span>Sem tabela</span><span>Mais um item</span></div><nav id="tabs" class="biomie-drawer__tabs"><button>Resumo</button><button>Vendas</button><button>Produtos</button></nav>';
    slot.innerHTML = output;
    var originalTable = document.getElementById('plain-table');
    if (originalTable) {
      window.fixtureOriginalTable = originalTable;
      window.fixtureTableClicks = 0;
      originalTable.addEventListener('click', function () { window.fixtureTableClicks += 1; });
    }
    var close = document.getElementById('drawer-close');
    if (close) close.addEventListener('click', function () { document.getElementById('fixture-drawer').classList.remove('open', 'is-open'); });
  }
  render(scenario);
  if (params.get('zoom')) document.body.style.zoom = params.get('zoom');
  document.getElementById('replace-table').addEventListener('click', function () {
    setTimeout(function () { sequence += 1; slot.innerHTML = wrapper('source', table(sequence % 2 ? 70 : 60, false)); }, 30);
  });
  document.getElementById('toggle-menu').addEventListener('click', function () {
    var menu = document.getElementById('fixture-menu'); menu.hidden = !menu.hidden;
  });
  document.getElementById('toggle-modal').addEventListener('click', function () {
    document.getElementById('modal-table-slot').innerHTML = wrapper('modal-source', table(80, false));
    document.getElementById('fixture-modal').showModal();
  });
  document.getElementById('close-modal').addEventListener('click', function () { document.getElementById('fixture-modal').close(); });
  window.fixture = { render: render, table: table, wrapper: wrapper };
}());

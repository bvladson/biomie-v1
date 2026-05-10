/**
 * BIomie - Customer 360 drawer.
 * Opens a local-cache customer drilldown from map buyers.
 */
(function () {
  'use strict';

  var F = window.BIOMIE_FMT || {};
  var DRAWER_ID = 'customer-detail-drawer';
  var EMPTY = 'Não disponível no cache local';
  var TABS = [
    { key: 'resumo', label: 'Resumo' },
    { key: 'nfs', label: 'NFs' },
    { key: 'pedidos', label: 'Pedidos' },
    { key: 'produtos', label: 'Produtos' },
    { key: 'vendedores', label: 'Vendedores' },
    { key: 'financeiro', label: 'Financeiro' },
    { key: 'timeline', label: 'Timeline' }
  ];

  var state = {
    loading: false,
    error: '',
    data: null,
    tab: 'resumo',
    params: {},
    abortCtrl: null,
    openerEl: null
  };
  var cache = {};
  var focusTrapHandler = null;

  function esc(value) {
    if (F.escape) return F.escape(String(value == null ? '' : value));
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }

  function money(value) {
    return F.money ? F.money(value) : new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value || 0));
  }

  function number(value) {
    return new Intl.NumberFormat('pt-BR').format(Number(value || 0));
  }

  function date(value) {
    if (!value) return '—';
    if (F.dateBr) return F.dateBr(value);
    var d = new Date(value);
    if (isNaN(d.getTime())) return String(value);
    return String(d.getDate()).padStart(2, '0') + '/' + String(d.getMonth() + 1).padStart(2, '0') + '/' + d.getFullYear();
  }

  function maskDoc(value) {
    var s = String(value || '');
    if (!s || s.indexOf('*') >= 0) return s || 'Sem documento';
    var digits = s.replace(/\D/g, '');
    if (digits.length === 11) return '***.***.***-**';
    if (digits.length === 14) return digits.slice(0, 2) + '.' + digits.slice(2, 5) + '.' + digits.slice(5, 8) + '/****-**';
    return s || 'Sem documento';
  }

  function fallback(value) {
    value = String(value == null ? '' : value).trim();
    return value || EMPTY;
  }

  function cacheKey(params) {
    var keys = Object.keys(params || {}).sort();
    return keys.map(function (k) { return k + '=' + String(params[k] || ''); }).join('&');
  }

  function fromCache(key) {
    var entry = cache[key];
    return entry && Date.now() - entry.ts < 60000 ? entry.data : null;
  }

  function toCache(key, data) {
    cache[key] = { ts: Date.now(), data: data };
  }

  function getDrawer() {
    var drawer = document.getElementById(DRAWER_ID);
    if (!drawer) {
      drawer = document.createElement('div');
      drawer.id = DRAWER_ID;
      drawer.className = 'biomie-drawer';
      drawer.setAttribute('role', 'dialog');
      drawer.setAttribute('aria-modal', 'true');
      drawer.setAttribute('aria-labelledby', 'cd-title');
      document.body.appendChild(drawer);
    }
    return drawer;
  }

  function buildShell() {
    var drawer = getDrawer();
    drawer.innerHTML =
      '<div class="biomie-drawer__header">' +
        '<div><div class="biomie-drawer__title" id="cd-title">Cliente 360</div><div class="biomie-drawer__subtitle" id="cd-subtitle"></div></div>' +
        '<button class="biomie-drawer__close" id="cd-close" aria-label="Fechar Cliente 360"><span class="material-symbols-rounded">close</span></button>' +
      '</div>' +
      '<div class="biomie-drawer__tabs" id="cd-tabs" role="tablist" aria-label="Seções do Cliente 360"></div>' +
      '<div class="biomie-drawer__body" id="cd-body" role="tabpanel"><div class="biomie-drawer__empty">Carregando...</div></div>';

    document.getElementById('cd-close').addEventListener('click', close);
    document.addEventListener('keydown', onKeyDown);
    renderTabs();
    return drawer;
  }

  function renderTabs() {
    var tabs = document.getElementById('cd-tabs');
    if (!tabs) return;
    tabs.innerHTML = TABS.map(function (tab) {
      var active = state.tab === tab.key;
      return '<button class="biomie-drawer__tab' + (active ? ' is-active' : '') + '" type="button" role="tab" aria-selected="' + active + '" data-tab="' + esc(tab.key) + '">' + esc(tab.label) + '</button>';
    }).join('');
    tabs.querySelectorAll('[data-tab]').forEach(function (button) {
      button.addEventListener('click', function () {
        state.tab = button.getAttribute('data-tab') || 'resumo';
        renderTabs();
        renderBody();
      });
    });
  }

  function renderBody() {
    var body = document.getElementById('cd-body');
    if (!body) return;
    if (state.loading) {
      body.innerHTML = skeleton();
      return;
    }
    if (state.error) {
      body.innerHTML = '<div class="biomie-drawer__empty"><strong>' + esc(EMPTY) + '</strong><br>' + esc(state.error) + '</div>';
      return;
    }
    if (!state.data) {
      body.innerHTML = '<div class="biomie-drawer__empty">' + esc(EMPTY) + '</div>';
      return;
    }

    var d = state.data;
    if (state.tab === 'resumo') body.innerHTML = renderResumo(d);
    else if (state.tab === 'nfs') body.innerHTML = renderInvoices(d.invoices || []);
    else if (state.tab === 'pedidos') body.innerHTML = renderOrders(d.orders || []);
    else if (state.tab === 'produtos') body.innerHTML = renderProducts(d.products || []);
    else if (state.tab === 'vendedores') body.innerHTML = renderVendors(d.vendors || []);
    else if (state.tab === 'financeiro') body.innerHTML = renderFinance(d.finance_titles || []);
    else if (state.tab === 'timeline') body.innerHTML = renderTimeline(d.timeline || []);
    else body.innerHTML = '<div class="biomie-drawer__empty">Aba desconhecida.</div>';
  }

  function skeleton() {
    return '<div class="biomie-drawer__stats">' +
      Array(6).fill('<div class="biomie-drawer__stat"><div style="height:12px;width:70px;border-radius:4px;background:var(--skeleton-base,#e5e7eb)">&nbsp;</div><div style="height:18px;width:96px;border-radius:4px;background:var(--skeleton-base,#e5e7eb);margin-top:6px">&nbsp;</div></div>').join('') +
      '</div>';
  }

  function stat(label, value) {
    return '<div class="biomie-drawer__stat"><div class="biomie-drawer__stat-label">' + esc(label) + '</div><div class="biomie-drawer__stat-value">' + esc(String(value == null || value === '' ? '—' : value)) + '</div></div>';
  }

  function sectionTitle(label) {
    return '<div class="biomie-drawer__section-title">' + esc(label) + '</div>';
  }

  function renderResumo(d) {
    var s = d.summary || {};
    var c = d.customer || {};
    var warnings = (d.meta && Array.isArray(d.meta.warnings)) ? d.meta.warnings : [];
    var warningHtml = warnings.length
      ? '<div class="module-alert module-alert--warning" style="margin-bottom:12px;padding:10px 12px;font-size:12px">' + warnings.map(esc).join('<br>') + '</div>'
      : '';

    return warningHtml +
      '<div class="biomie-drawer__section"><div class="biomie-drawer__stats">' +
        stat('Faturamento', money(s.faturamento)) +
        stat('Notas', number(s.notas_count)) +
        stat('Pedidos', number(s.pedidos_count)) +
        stat('Produtos', number(s.produtos_count)) +
        stat('Ticket médio', money(s.ticket_medio)) +
        stat('Última compra', date(s.ultima_compra)) +
      '</div></div>' +
      '<div class="biomie-drawer__section">' + sectionTitle('Cliente') +
        listItem('Nome', fallback(c.nome)) +
        listItem('Documento', fallback(maskDoc(c.documento))) +
        listItem('Bairro', fallback(c.bairro)) +
        listItem('Cidade/UF', fallback([c.cidade, c.uf].filter(Boolean).join(' / '))) +
        listItem('CNAE', fallback([c.cnae_codigo, c.cnae_descricao].filter(Boolean).join(' - '))) +
        listItem('Endereço', fallback(c.endereco)) +
        listItem('Telefone', fallback(c.telefone)) +
        listItem('E-mail', fallback(c.email)) +
      '</div>' +
      renderDiagnostics(d.meta);
  }

  function renderDiagnostics(meta) {
    meta = meta || {};
    var diag = meta.diagnostics || {};
    var reason = diag.motivo_provavel || '';
    if (!reason || reason === 'ok') return '';
    return '<div class="biomie-drawer__section">' + sectionTitle('Diagnóstico') +
      listItem('Motivo provável', reason) +
      listItem('Mart geo', number(diag.linhas_mart_compradores_por_geo || 0)) +
      listItem('NFs canonical', number(diag.linhas_biomie_notas_fiscais_canonicas || 0)) +
      listItem('Itens', number(diag.linhas_biomie_notas_fiscais_itens_canonicos || 0)) +
      '</div>';
  }

  function renderInvoices(rows) {
    if (!rows.length) return emptyWithDiagnostics('Nenhuma NF detalhada vinculada.');
    return table(
      ['NF', 'Data', 'Pedido', 'Vendedor', 'Itens', 'Valor', ''],
      rows.map(function (row) {
        return [
          button('NF ' + fallback(row.nf_display || row.numero_nf), 'open-invoice', {
            'data-nota-key': row.nota_key || '',
            'data-numero-nf': row.numero_nf || '',
            'data-chave-nfe': row.chave_nfe || ''
          }),
          esc(date(row.data_emissao)),
          row.pedido_codigo ? button(row.pedido_codigo, 'open-order', { 'data-pedido-codigo': row.pedido_codigo }) : esc(fallback(row.pedido_numero)),
          esc(fallback(row.vendedor_nome)),
          esc(number(row.items_count || 0)),
          esc(money(row.valor_total)),
          button('Ver detalhe', 'open-invoice', {
            'data-nota-key': row.nota_key || '',
            'data-numero-nf': row.numero_nf || '',
            'data-chave-nfe': row.chave_nfe || ''
          })
        ];
      }),
      true
    );
  }

  function renderOrders(rows) {
    if (!rows.length) return emptyWithDiagnostics('Nenhum pedido faturado vinculado.');
    return table(
      ['Pedido', 'Status', 'Data', 'NF', 'Vendedor', 'Valor'],
      rows.map(function (row) {
        return [
          row.codigo_pedido ? button(row.codigo_pedido, 'open-order', { 'data-pedido-codigo': row.codigo_pedido }) : esc(fallback(row.numero_pedido)),
          esc(fallback(row.status || row.etapa)),
          esc(date(row.data_faturamento || row.data_inclusao)),
          row.nota_key || row.nf_display ? button(fallback(row.nf_display || row.nota_key), 'open-invoice', { 'data-nota-key': row.nota_key || '' }) : esc(EMPTY),
          esc(fallback(row.vendedor_nome)),
          esc(money(row.valor_total))
        ];
      }),
      true
    );
  }

  function renderProducts(rows) {
    if (!rows.length) return emptyWithDiagnostics('Nenhum produto vendido encontrado.');
    return table(
      ['Código', 'Produto', 'Qtd', 'Unit.', 'Desconto', 'NCM', 'Total', 'Última'],
      rows.map(function (row) {
        var productLabel = fallback(row.descricao);
        if (window.BIomieProductDetail && row.codigo_produto) {
          productLabel = '<button type="button" class="row-inline-link" data-biomie-action="open-product" data-product-code="' + esc(row.codigo_produto) + '">' + esc(productLabel) + '</button>';
        } else {
          productLabel = esc(productLabel);
        }
        return [
          esc(fallback(row.codigo_produto)),
          productLabel,
          esc(number(row.quantidade || 0)),
          esc(money(row.valor_unitario || 0)),
          esc(money(row.desconto_total || 0)),
          esc(fallback(row.ncm)),
          esc(money(row.valor_total || 0)),
          esc(date(row.ultima_compra))
        ];
      }),
      true
    );
  }

  function renderVendors(rows) {
    if (!rows.length) return emptyWithDiagnostics('Nenhum vendedor vinculado.');
    return table(
      ['Vendedor', 'Notas', 'Faturamento', 'Produtos'],
      rows.map(function (row) {
        return [
          fallback(row.vendedor_nome),
          number(row.notas || 0),
          money(row.faturamento || 0),
          number(row.produtos || 0)
        ];
      })
    );
  }

  function renderFinance(rows) {
    if (!rows.length) return emptyWithDiagnostics('Nenhum título financeiro vinculado.');
    return table(
      ['Título', 'Vencimento', 'Valor', 'Status'],
      rows.map(function (row) {
        return [
          fallback(row.numero_documento || row.titulo_key),
          date(row.data_vencimento),
          money(row.valor || row.valor_aberto || row.valor_pago || 0),
          fallback(row.status)
        ];
      })
    );
  }

  function renderTimeline(rows) {
    if (!rows.length) return emptyWithDiagnostics('Sem eventos registrados.');
    return '<div style="display:flex;flex-direction:column;gap:12px;padding:4px 0">' + rows.map(function (row) {
      return '<div style="display:flex;gap:10px;align-items:flex-start">' +
        '<span class="material-symbols-rounded" style="font-size:18px;color:var(--page-accent);margin-top:2px">' + iconFor(row.type) + '</span>' +
        '<div style="min-width:0">' +
          '<div style="font-size:13px;font-weight:600;color:var(--text-1)">' + esc(fallback(row.label)) + '</div>' +
          '<div style="font-size:12px;color:var(--text-3)">' + esc(date(row.date)) + (Number(row.amount || 0) ? ' · ' + esc(money(row.amount)) : '') + (row.ref ? ' · ' + esc(row.ref) : '') + '</div>' +
        '</div>' +
      '</div>';
    }).join('') + '</div>';
  }

  function iconFor(type) {
    if (type === 'invoice') return 'receipt_long';
    if (type === 'order') return 'shopping_cart';
    if (type === 'finance') return 'payments';
    return 'person';
  }

  function listItem(label, value) {
    return '<div class="biomie-drawer__list-item"><span>' + esc(label) + '</span><span style="text-align:right">' + esc(value) + '</span></div>';
  }

  function emptyWithDiagnostics(message) {
    var diag = state.data && state.data.meta ? renderDiagnostics(state.data.meta) : '';
    return '<div class="biomie-drawer__empty"><strong>' + esc(EMPTY) + '</strong><br>' + esc(message) + '</div>' + diag;
  }

  function table(headers, rows, cellsMayContainHtml) {
    return '<div style="overflow-x:auto">' +
      '<table style="width:100%;border-collapse:collapse;font-size:12px"><thead><tr>' +
      headers.map(function (h) { return '<th style="text-align:left;padding:7px 8px;border-bottom:1px solid var(--border);color:var(--text-3);font-weight:700;white-space:nowrap">' + esc(h) + '</th>'; }).join('') +
      '</tr></thead><tbody>' +
      rows.map(function (row) {
        return '<tr>' + row.map(function (cell) {
          return '<td style="padding:7px 8px;border-bottom:1px solid var(--border);vertical-align:top;white-space:nowrap">' + (cellsMayContainHtml ? String(cell) : esc(cell)) + '</td>';
        }).join('') + '</tr>';
      }).join('') +
      '</tbody></table></div>';
  }

  function button(label, action, attrs) {
    attrs = attrs || {};
    var htmlAttrs = Object.keys(attrs).map(function (key) {
      return key + '="' + esc(String(attrs[key] == null ? '' : attrs[key])) + '"';
    }).join(' ');
    return '<button type="button" class="row-inline-link" data-biomie-action="' + esc(action) + '" ' + htmlAttrs + '>' + esc(label) + '</button>';
  }

  function fillMeta() {
    var c = (state.data && state.data.customer) || {};
    var title = document.getElementById('cd-title');
    var subtitle = document.getElementById('cd-subtitle');
    if (title) title.textContent = 'Cliente 360';
    if (subtitle) subtitle.textContent = [c.nome || state.params.cliente_nome || 'Cliente', maskDoc(c.documento || state.params.cliente_documento || '')].filter(Boolean).join(' · ');
  }

  function installFocusTrap(drawer) {
    removeFocusTrap();
    focusTrapHandler = function (event) {
      if (event.key !== 'Tab') return;
      var focusable = Array.from(drawer.querySelectorAll('button:not([disabled]),[href],input,[tabindex]:not([tabindex="-1"])'));
      if (!focusable.length) return;
      var first = focusable[0];
      var last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        last.focus();
        event.preventDefault();
      } else if (!event.shiftKey && document.activeElement === last) {
        first.focus();
        event.preventDefault();
      }
    };
    document.addEventListener('keydown', focusTrapHandler);
  }

  function removeFocusTrap() {
    if (focusTrapHandler) {
      document.removeEventListener('keydown', focusTrapHandler);
      focusTrapHandler = null;
    }
  }

  function onKeyDown(event) {
    if (event.key === 'Escape') close();
  }

  function close() {
    var drawer = document.getElementById(DRAWER_ID);
    if (drawer) drawer.classList.remove('is-open');
    document.removeEventListener('keydown', onKeyDown);
    removeFocusTrap();
    if (state.abortCtrl) {
      try { state.abortCtrl.abort(); } catch (_) {}
      state.abortCtrl = null;
    }
    if (state.openerEl && state.openerEl.focus) {
      try { state.openerEl.focus(); } catch (_) {}
    }
    state.openerEl = null;
  }

  function open(payload) {
    payload = payload || {};
    state.params = payload;
    state.tab = 'resumo';
    state.data = null;
    state.error = '';
    state.loading = true;
    state.openerEl = document.activeElement;

    var drawer = buildShell();
    requestAnimationFrame(function () { drawer.classList.add('is-open'); });
    renderBody();
    window.setTimeout(function () {
      var closeButton = document.getElementById('cd-close');
      if (closeButton) closeButton.focus();
      installFocusTrap(drawer);
    }, 80);

    var params = new URLSearchParams();
    ['start', 'end', 'date_from', 'date_to', 'city', 'bairro', 'cliente_documento', 'documento_norm', 'codigo_cliente_omie', 'cliente_nome', 'nota_key', 'pedido_codigo'].forEach(function (key) {
      if (payload[key] != null && String(payload[key]).trim() !== '') params.set(key, String(payload[key]).trim());
    });
    params.set('include', payload.include || 'orders,invoices,items,finance,timeline');

    var key = cacheKey(Object.fromEntries(params.entries()));
    var cached = fromCache(key);
    if (cached) {
      state.loading = false;
      state.data = cached;
      fillMeta();
      renderBody();
      return;
    }

    if (state.abortCtrl) {
      try { state.abortCtrl.abort(); } catch (_) {}
    }
    state.abortCtrl = new AbortController();
    fetch('api/customer_detail.php?' + params.toString(), {
      credentials: 'same-origin',
      signal: state.abortCtrl.signal,
      cache: 'no-store'
    })
      .then(function (response) {
        return response.json().catch(function () { return {}; }).then(function (json) {
          if (!response.ok || json.ok === false) {
            throw new Error(json.message || 'Falha ao carregar Cliente 360.');
          }
          return json;
        });
      })
      .then(function (json) {
        state.loading = false;
        state.abortCtrl = null;
        state.data = json;
        toCache(key, json);
        fillMeta();
        renderBody();
      })
      .catch(function (error) {
        if (error && error.name === 'AbortError') return;
        state.loading = false;
        state.abortCtrl = null;
        state.error = error && error.message ? error.message : 'Falha ao carregar Cliente 360.';
        renderBody();
      });
  }

  window.BIomieCustomerDetail = { open: open, close: close };
}());

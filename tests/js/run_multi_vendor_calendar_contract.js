'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..', '..');
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8');

const widget = read('assets/js/modules/quote-calendar-widget.js');
const agenda = read('assets/js/modules/quote-agenda/index.js');
const home = read('views/index.html');
const common = read('api/quote_agenda_common.php');

assert.ok(
  widget.includes('normalizeSellerIds')
    && widget.includes("seller_ids=' + encodeURIComponent(sellerIds.join(','))")
    && widget.includes('setSellers: function (values)'),
  'widget deve enviar e atualizar uma selecao multipla em uma unica requisicao'
);

assert.ok(
  agenda.includes("params.get('vendor_ids')")
    && agenda.includes("params.get('seller_ids')")
    && agenda.includes("nav.get('vendor_ids') || nav.get('vendor_id')")
    && agenda.includes("PAGE_SELLER_IDS.length === 1 ? 'seller_id' : 'seller_ids'"),
  'tela da Agenda deve propagar o recorte multiplo para todas as leituras'
);

assert.ok(
  home.includes('sellerIds: initialCalendarVendorIds')
    && home.includes("url.searchParams.set('vendor_ids', selected.join(','))")
    && home.includes("url.searchParams.set('seller_ids', selected.join(','))"),
  'home deve manter miniagenda e link principal no mesmo recorte'
);

assert.ok(
  common.includes("'seller_ids'")
    && common.includes("'vendor_ids'")
    && common.includes('seller_not_authorized'),
  'choke-point da Agenda deve validar a lista no servidor'
);

process.stdout.write('PASS multi vendor calendar contract (4/4)\n');

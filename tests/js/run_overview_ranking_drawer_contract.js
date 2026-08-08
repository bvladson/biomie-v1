'use strict';

/**
 * Drawers "Produtos/Clientes em destaque" (Orc-PD) — contrato de comportamento.
 *
 * Nao valida por regex: extrai as funcoes REAIS do fonte e executa. Se alguem
 * reverter uma das correcoes abaixo, este teste quebra.
 *
 * Cobre tres bugs encontrados na revisao de 2026-07-28, todos silenciosos —
 * nenhum deles gerava erro, todos entregavam numero errado com cara de certo:
 *
 *   1. parseLocaleNumber tratava "." como decimal. "10.000" virava 10 e
 *      "1.234.567" virava null, entao o filtro de receita minima simplesmente
 *      nao filtrava e o usuario nao tinha como perceber.
 *   2. O filtro de vendedor casava por substring. "ALEXANDRE SOUZA" tambem
 *      trazia "ALEXANDRE SOUZA - REV", misturando varejo com revenda — a
 *      separacao que a base mantem de proposito.
 *   3. A lista vinha truncada pelo LIMIT sem aviso. A MAG_FILIAL tem 536
 *      produtos e 1.284 clientes no ano; a tela mostrava a fatia carregada
 *      como se fosse o universo, com percentuais somando 100%.
 *
 *   node tests/js/run_overview_ranking_drawer_contract.js
 */

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..', '..');
const sourcePath = path.join(root, 'assets/js/modules/overview/overview-detail-drawer.js');
const source = fs.readFileSync(sourcePath, 'utf8');

// O contrato da serie diaria tem dois lados: a API agrega por dia e o drawer
// sabe desenhar isso. Verificar so o JS deixaria passar o caso em que o PHP
// volta a devolver uma linha por nota.
const phpSource = fs.readFileSync(
  path.join(root, 'app/Modules/Sales/Application/Overview/OverviewDetailBuilder.php'),
  'utf8',
);

/** Extrai `function nome(...) { ... }` do fonte contando chaves. */
function extract(name) {
  const marker = `function ${name}(`;
  const start = source.indexOf(marker);
  assert.notEqual(start, -1, `funcao ${name}() nao encontrada em ${path.basename(sourcePath)}`);

  let depth = 0;
  let seenBody = false;
  for (let i = source.indexOf('{', start); i < source.length; i += 1) {
    if (source[i] === '{') {
      depth += 1;
      seenBody = true;
    } else if (source[i] === '}') {
      depth -= 1;
      if (seenBody && depth === 0) return source.slice(start, i + 1);
    }
  }
  throw new Error(`nao consegui delimitar ${name}()`);
}

const names = [
  'decodeEntityText', 'normalizeText', 'parseLocaleNumber', 'numberValue',
  'sellerName', 'sellerNames', 'rowHasSeller', 'uniqueSellers',
  'recencyValue', 'serverTotal', 'csvCell', 'csvNumber',
  // Serie diaria da "Evolucao da Receita".
  'pipeList', 'namesLabel', 'formatNumber',
];

// B (window.BIomie) nao existe aqui: as funcoes caem nos fallbacks nativos.
const sandbox = { B: {} };
const factory = new Function('B', `${names.map(extract).join('\n')}\nreturn { ${names.join(', ')} };`);
const fn = factory(sandbox.B);

let checks = 0;
const ok = (label, condition) => {
  assert.ok(condition, label);
  checks += 1;
};

// ── 1. Numero em pt-BR: ponto e milhar ──────────────────────────────────────
ok('"1.500" = mil e quinhentos', fn.parseLocaleNumber('1.500') === 1500);
ok('"10.000" = dez mil', fn.parseLocaleNumber('10.000') === 10000);
ok('"1.234.567" nao vira null', fn.parseLocaleNumber('1.234.567') === 1234567);
ok('"R$ 1.500" ignora simbolo', fn.parseLocaleNumber('R$ 1.500') === 1500);
ok('"1.500,50" usa virgula como decimal', fn.parseLocaleNumber('1.500,50') === 1500.5);
ok('"R$5.000,00" completo', fn.parseLocaleNumber('R$5.000,00') === 5000);
ok('"0,5" decimal puro', fn.parseLocaleNumber('0,5') === 0.5);
ok('"1.5" sem grupo de 3 segue decimal', fn.parseLocaleNumber('1.5') === 1.5);
ok('"1500" inteiro', fn.parseLocaleNumber('1500') === 1500);
ok('vazio = null (sem filtro)', fn.parseLocaleNumber('') === null);
ok('texto = null', fn.parseLocaleNumber('abc') === null);
ok('negativo preservado', fn.parseLocaleNumber('-1.500') === -1500);

// ── 2. Vendedor: casamento exato, nunca substring ───────────────────────────
const varejo = { vendor_names: 'ALEXANDRE SOUZA' };
const revenda = { vendor_names: 'ALEXANDRE SOUZA - REV' };
const ambos = { vendor_names: 'ALEXANDRE SOUZA | CAIO - VAR' };

ok('varejo casa com ele mesmo', fn.rowHasSeller(varejo, 'ALEXANDRE SOUZA'));
ok(
  'escolher varejo NAO traz a revenda (bug do indexOf)',
  fn.rowHasSeller(revenda, 'ALEXANDRE SOUZA') === false
);
ok('revenda casa com o proprio nome', fn.rowHasSeller(revenda, 'ALEXANDRE SOUZA - REV'));
ok('linha multi-vendedor casa cada um', fn.rowHasSeller(ambos, 'CAIO - VAR'));
ok('nao casa vendedor ausente', fn.rowHasSeller(ambos, 'NUBIA OLIVEIRA') === false);
ok('acento/caixa nao atrapalham', fn.rowHasSeller({ vendor_names: 'GABRIEL BENÍCIO' }, 'gabriel benicio'));
ok(
  'uniqueSellers separa por pipe',
  JSON.stringify(fn.uniqueSellers([ambos])) === JSON.stringify(['ALEXANDRE SOUZA', 'CAIO - VAR'])
);

// ── 3. Truncamento: total do servidor tem de chegar na tela ─────────────────
ok('total de produtos vem do summary', fn.serverTotal('top_products', { products_count: 536 }) === 536);
ok('total de clientes vem do summary', fn.serverTotal('top_customers', { clients_count: 1284 }) === 1284);
ok('sem summary = null (nao inventa total)', fn.serverTotal('top_products', null) === null);
ok('zero nao vira total', fn.serverTotal('top_products', { products_count: 0 }) === null);
ok(
  'o fonte avisa quando trunca',
  source.includes('renderTruncationNotice') && /Exibindo os .*maiores de/.test(source)
);
ok('limite deixou de ser 200', /RANKING_LIMIT\s*=\s*(\d+)/.test(source)
  && Number(source.match(/RANKING_LIMIT\s*=\s*(\d+)/)[1]) > 200);

// ── 4. Recencia ordenavel ───────────────────────────────────────────────────
const recente = { last_purchase: '2026-07-20' };
const antigo = { last_purchase: '2026-01-05' };
ok('compra recente ordena acima', fn.recencyValue(recente, true) > fn.recencyValue(antigo, true));
ok('sem data vai para o fim', fn.recencyValue({}, true) === 0);
ok('data invalida nao quebra', fn.recencyValue({ last_purchase: 'xx' }, true) === 0);
ok('produto usa last_sale', fn.recencyValue({ last_sale: '2026-07-20' }, false) > 0);

// ── 5. CSV seguro para Excel pt-BR ──────────────────────────────────────────
ok('aspas sao duplicadas', fn.csvCell('CIA "ABC"') === '"CIA ""ABC"""');
ok('ponto e virgula nao quebra coluna', fn.csvCell('A;B') === '"A;B"');
ok('decimal com virgula', fn.csvNumber(1500.5) === '"1500,50"');
ok('nulo vira vazio', fn.csvCell(null) === '""');
ok('CSV usa separador ; e BOM', source.includes("join(';')") && source.includes('﻿'));

// ── 6. Entidades HTML nao vazam para a tela ─────────────────────────────────
ok('&amp;amp; volta a &', fn.decodeEntityText('F. A. S. MARTINS &amp;amp; CIA') === 'F. A. S. MARTINS & CIA');
ok('&amp; volta a &', fn.decodeEntityText('MELO &amp; CONTADORES') === 'MELO & CONTADORES');

// ── 7. Evolucao da Receita: serie DIARIA com detalhe do dia ─────────────────
//
// O drawer repetia a mesma data uma vez por NF num painel chamado "Evolucao", e
// a coluna "Pedidos" lia `orders` — campo que a API nunca devolveu para este
// grafico, entao mostrava 0 em todas as linhas.
ok('a API agrega por dia, nao por nota',
  phpSource.includes('GROUP BY n.data_competencia'));
ok('a consulta devolve as contagens do dia',
  ['invoices_count', 'customers_count', 'vendors_count'].every((c) => phpSource.includes(c + '')));
ok('a consulta devolve a maior NF do dia',
  phpSource.includes('top_invoice_value') && phpSource.includes('top_invoice_code'));
ok('o drawer usa o renderizador proprio, nao a tabela generica',
  source.includes("chartId === 'revenue_evolution'") &&
  source.includes('renderRevenueEvolutionDetail(chartId, rows, data.summary)'));
ok('pipeline_period e monthly_comparison compartilham o renderizador',
  source.includes("chartId === 'pipeline_period'") &&
  source.includes("chartId === 'monthly_comparison'"));
ok('a coluna "Pedidos" (sempre 0) saiu do cabecalho da serie diaria',
  !/<th class="is-numeric">Pedidos<\/th>/.test(source));
ok('o mapa morto de revenue_evolution saiu do detectColumns',
  !/revenue_evolution:\s*\[/.test(source));
ok('vendor_ranking, que TEM orders, continua no mapa',
  /vendor_ranking:\s*\[/.test(source));

// GROUP_CONCAT chega como "A|B|C" e nao pode vazar separador para a tela.
ok('lista separada por | vira array', JSON.stringify(fn.pipeList('ANA|BRUNO')) === '["ANA","BRUNO"]');
ok('item vazio some da lista', JSON.stringify(fn.pipeList('ANA||BRUNO')) === '["ANA","BRUNO"]');
ok('valor nulo vira lista vazia', fn.pipeList(null).length === 0);
ok('entidade HTML e decodificada na lista',
  fn.pipeList('MELO &amp;amp; CIA')[0] === 'MELO & CIA');

// O SQL corta em 6 nomes; o rotulo tem que dizer que ha mais, senao 6 de 30
// parece a lista inteira — o mesmo erro do LIMIT sem aviso do item 3.
ok('rotulo declara quantos ficaram de fora',
  fn.namesLabel('A|B', 5) === 'A, B e mais 3');
ok('sem sobra nao inventa sufixo', fn.namesLabel('A|B', 2) === 'A, B');
ok('total menor que a lista nao gera texto negativo',
  fn.namesLabel('A|B', 1) === 'A, B');
ok('lista vazia devolve nulo (detailItem some)', fn.namesLabel('', 0) === null);

console.log(JSON.stringify({ ok: true, checks }, null, 2));

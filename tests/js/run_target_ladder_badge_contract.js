'use strict';

/**
 * Escada de metas na tabela de vendedores — contrato de comportamento.
 *
 * O BUG QUE ISTO IMPEDE DE VOLTAR: `isGoalAchieved` era
 * `pct >= 100 || realized >= target`, e o segundo termo comparava o realizado
 * com o COMPROMISSO-BASE. Como `pct` ja era consciente da escada, o `||`
 * passava por cima dele:
 *
 *   SAMANTHA · realizado R$ 49.306 · compromisso R$ 40.000 · desafio R$ 80.000
 *   pct = 61,6%  ->  primeira condicao falsa
 *   realized >= 40.000  ->  TRUE  ->  trofeu "Meta batida"
 *
 * A mesma linha exibia "Meta batida" ao lado de "Meta 2/4 · 61,6% do desafio",
 * com tres niveis e R$ 150.694 ainda por percorrer.
 *
 *   node tests/js/run_target_ladder_badge_contract.js
 */

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..', '..');
const source = fs.readFileSync(path.join(root, 'assets/js/core/render-helpers.js'), 'utf8');
const vendorTable = fs.readFileSync(
  path.join(root, 'assets/js/modules/flight-plan/vendor-table.js'), 'utf8');
const cssSource = fs.readFileSync(path.join(root, 'assets/css/shell-index.css'), 'utf8');
const builderPhp = fs.readFileSync(
  path.join(root, 'app/Modules/Sales/Application/Vendors/CanonicalVendorPayloadBuilder.php'), 'utf8');
const storePy = fs.readFileSync(
  path.join(root, 'services/python/biomie_intel/forecast_store.py'), 'utf8');

/** Extrai `B.nome = function (...) { ... }` contando chaves. */
function extract(name) {
  const marker = 'B.' + name + ' = function';
  const start = source.indexOf(marker);
  assert.notEqual(start, -1, 'B.' + name + ' nao encontrada');
  let depth = 0;
  let seen = false;
  for (let i = source.indexOf('{', start); i < source.length; i += 1) {
    if (source[i] === '{') { depth += 1; seen = true; } else if (source[i] === '}') {
      depth -= 1;
      if (seen && depth === 0) return source.slice(start, i + 1) + ';';
    }
  }
  throw new Error('nao consegui delimitar B.' + name);
}

const nomes = ['goalLadderState', 'isGoalAchieved', 'renderGoalBadge',
  'renderLadderNextStep', 'renderLadderOdds'];

const ENTIDADES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' };
const B = {
  escapeHtml: (v) => String(v == null ? '' : v).replace(/[&<>"]/g, (c) => ENTIDADES[c]),
  formatCurrency: (v) => 'R$ ' + Number(v || 0).toFixed(2),
};
new Function('B', nomes.map(extract).join('\n'))(B);

let checks = 0;
const ok = (label, cond) => { assert.ok(cond, label); checks += 1; };

// ── 1. O caso real que gerou a queixa ───────────────────────────────────────
const samantha = {
  realized_period: 49306.17, base_target: 40000, active_target: 80000,
  maximum_target: 200000, target_period: 40000, target_month: 40000,
  attainment_pct: 61.6, tiers_total: 4, current_tier: 1, achieved_level: 1,
  all_levels_achieved: false,
};
ok('compromisso batido com escada em aberto NAO e "meta batida"',
  B.isGoalAchieved(samantha) === false);
ok('o estado e parcial', B.goalLadderState(samantha).state === 'parcial');
ok('o badge diz o nivel, nao "batida"',
  B.renderGoalBadge(samantha).includes('Meta 1/4')
  && !B.renderGoalBadge(samantha).includes('Meta batida'));
ok('o badge conta quantos faltam', B.renderGoalBadge(samantha).includes('faltam 3'));
ok('nao usa a classe de conquistado',
  !B.renderGoalBadge(samantha).includes('mag-goal-badge--achieved'));

// ── 2. Os outros tres estados ───────────────────────────────────────────────
const abaixo = Object.assign({}, samantha,
  { realized_period: 15915.90, current_tier: 0, achieved_level: 0 });
ok('abaixo do compromisso nao tem badge', B.renderGoalBadge(abaixo) === '');
ok('estado abaixo', B.goalLadderState(abaixo).state === 'abaixo');

const completa = Object.assign({}, samantha, {
  realized_period: 220000, current_tier: 4, achieved_level: 4,
  all_levels_achieved: true, over_maximum_amount: 20000,
});
ok('trofeu so com a escada inteira vencida', B.isGoalAchieved(completa) === true);
ok('o badge do topo diz "Meta maxima"', B.renderGoalBadge(completa).includes('Meta m'));
ok('a superacao entra no title', B.renderGoalBadge(completa).includes('supera'));

ok('sem meta nenhuma nao afirma nada', B.renderGoalBadge({}) === '');
ok('estado sem_meta', B.goalLadderState({}).state === 'sem_meta');
ok('vendor nulo nao quebra', B.goalLadderState(null).state === 'sem_meta');

// ── 3. Payload legado (sem escada) continua funcionando ─────────────────────
ok('legado: compromisso unico batido = topo',
  B.isGoalAchieved({ realized_period: 50000, target_period: 40000 }) === true);
ok('legado: abaixo do unico nivel nao tem badge',
  B.renderGoalBadge({ realized_period: 10000, target_period: 40000 }) === '');

// ── 4. Fase 3: o passo seguinte ─────────────────────────────────────────────
const passo = B.renderLadderNextStep(Object.assign({}, samantha, {
  active_gap: 30693.83, new_daily_target: 2045.0,
}));
ok('diz quanto falta', passo.includes('Faltam'));
ok('diz quanto por dia util', passo.includes('/dia'));
ok('sem gap nao inventa passo', B.renderLadderNextStep({ active_gap: 0 }) === '');
ok('escada zerada propoe proximo patamar',
  B.renderLadderNextStep({ all_levels_achieved: true, recommended_next_target: 250000 })
    .includes('atamar sugerido'));
ok('escada zerada sem sugestao nao inventa',
  B.renderLadderNextStep({ all_levels_achieved: true }) === '');

// ── 5. Fase 3: chance por nivel, com portao de confianca ────────────────────
const odds = [
  { level: 1, target: 40000, achieved: true, probability: 1.0 },
  { level: 2, target: 80000, achieved: false, probability: 0.6378 },
  { level: 3, target: 130000, achieved: false, probability: 0.3244 },
  { level: 4, target: 200000, achieved: false, probability: 0.0563 },
];
const comOdds = B.renderLadderOdds({ level_probabilities: odds, ladder_confidence: 'alta' });
ok('mostra so os niveis em aberto', comOdds.includes('M2') && !comOdds.includes('M1 '));
ok('arredonda a chance', comOdds.includes('64%') && comOdds.includes('32%'));
ok('confianca alta nao marca como fraca', !comOdds.includes('baixa confian'));

const fraco = B.renderLadderOdds({ level_probabilities: odds, ladder_confidence: 'baixa' });
ok('confianca baixa EXIBE mas marca',
  fraco.includes('baixa confian') && fraco.includes('M2'));
ok('sem probabilidade nenhuma nao renderiza', B.renderLadderOdds({}) === '');
ok('escada toda conquistada nao mostra chance',
  B.renderLadderOdds({ level_probabilities: [{ level: 1, achieved: true, probability: 1 }] }) === '');

// ── 6. Fase 2: um renderizador so ───────────────────────────────────────────
ok('existe o helper compartilhado', source.includes('B.renderTargetLadderCell = function'));
ok('o plano de voo usa o helper', vendorTable.includes('B.renderTargetLadderCell(v'));
ok('o plano de voo nao tem mais copia do bloco',
  !/tier-progress__mark|fp-tier-badge/.test(vendorTable));
ok('a tabela de vendedores usa o helper',
  source.includes('B.renderTargetLadderCell(row, { showNextStep: true })'));
ok('o plano de voo mantem fallback se o helper faltar',
  vendorTable.includes("typeof B.renderTargetLadderCell === 'function'"));

// ── 7. Fase 4: a camada prescritiva chega ao banco e a tela ─────────────────
// Procura dentro da LISTA DE COLUNAS do INSERT, nao no arquivo inteiro: o nome
// da coluna tambem aparece no comentario e nos parametros, e uma versao
// anterior deste check aprovou um INSERT ja mutilado por causa disso.
const insertCols = (() => {
  const i = storePy.indexOf('INSERT INTO intelligence_forecasts');
  assert.notEqual(i, -1, 'INSERT de forecasts nao encontrado');
  return storePy.slice(i, storePy.indexOf('VALUES', i));
})();
['required_daily', 'projected_level', 'recommended_next_target', 'ladder_confidence']
  .forEach((coluna) => {
    ok('o Python persiste ' + coluna + ' (na lista de colunas do INSERT)',
      insertCols.includes(coluna));
  });
ok('o PHP le a camada prescritiva',
  builderPhp.includes('ForecastLadderReadService') && builderPhp.includes('mergeInto'));
ok('o PHP calcula quanto por dia util',
  builderPhp.includes('new_daily_target') && builderPhp.includes('remaining_weight'));
ok('a leitura prescritiva nao pode derrubar a tela',
  /ForecastLadderReadService[\s\S]{0,700}catch \(\\Throwable\)/.test(builderPhp));

// ── 8. CSS: classe duplicada vence em silencio ──────────────────────────────
const linhas = cssSource.split(String.fromCharCode(10));
const seletores = linhas.reduce((acc, linha, i) => {
  const m = linha.match(/^\.[a-zA-Z][\w-]*\s*\{/);
  if (!m) return acc;
  if ((linhas[i - 1] || '').trim().endsWith(',')) return acc;
  acc.push(m[0].replace(/\s*\{$/, '').trim());
  return acc;
}, []);
ok('nenhuma classe CSS duplicada (a de baixo vence calada)',
  seletores.filter((s, i) => seletores.indexOf(s) !== i).length === 0);
ok('o detector nao esvaziou', seletores.length > 50);
ok('o badge parcial tem estilo proprio', cssSource.includes('.mag-goal-badge--partial'));

console.log(JSON.stringify({ ok: true, checks }, null, 2));

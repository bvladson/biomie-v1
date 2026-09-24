/**
 * Mede uma pagina em viewports reais via Chrome DevTools Protocol.
 * Sem dependencias: WebSocket global do Node >= 22 e o Chrome ja instalado.
 *
 * Uso: node medir.mjs <url> [larguras] [--shots <dir>]
 *
 * Relata, por largura:
 *   innerWidth, clientWidth, scrollWidth do documento, altura do documento,
 *   elementos que excedem a viewport,
 *   cada secao marcada com [data-secao] (largura x altura, ou ESTOURA),
 *   TODO elemento com overflow-x auto|scroll, se rola, e o motivo declarado
 *   em data-motivo-rolagem. Rolagem sem motivo e reportada como falha.
 */
import { spawn } from 'node:child_process';
import { mkdtempSync, writeFileSync, mkdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const url = process.argv[2];
const larguras = (process.argv[3] || '320,360,375,390,412,430').split(',').map(Number);
const shotsIdx = process.argv.indexOf('--shots');
const shotsDir = shotsIdx > -1 ? process.argv[shotsIdx + 1] : null;
if (shotsDir) mkdirSync(shotsDir, { recursive: true });

const porta = 9333 + Math.floor(Math.random() * 400);
const perfil = mkdtempSync(join(tmpdir(), 'cdp-'));
const chrome = spawn(CHROME, [
  '--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
  `--remote-debugging-port=${porta}`, `--user-data-dir=${perfil}`, 'about:blank',
], { stdio: 'ignore' });

const dormir = (ms) => new Promise((r) => setTimeout(r, ms));

async function esperarChrome() {
  for (let i = 0; i < 80; i++) {
    try {
      const r = await fetch(`http://127.0.0.1:${porta}/json/version`);
      if (r.ok) return (await r.json()).webSocketDebuggerUrl;
    } catch { /* subindo */ }
    await dormir(250);
  }
  throw new Error('Chrome nao abriu a porta de depuracao');
}

class Sessao {
  constructor(ws) {
    this.ws = ws; this.id = 0; this.pend = new Map(); this.sessionId = null;
    ws.addEventListener('message', (e) => {
      const m = JSON.parse(e.data);
      if (m.id && this.pend.has(m.id)) {
        const { ok, err } = this.pend.get(m.id); this.pend.delete(m.id);
        if (m.error) err(new Error(m.error.message)); else ok(m.result);
      }
    });
  }
  send(method, params = {}) {
    const id = ++this.id;
    const msg = { id, method, params };
    if (this.sessionId) msg.sessionId = this.sessionId;
    this.ws.send(JSON.stringify(msg));
    return new Promise((ok, err) => this.pend.set(id, { ok, err }));
  }
}

/* Executado dentro da pagina. Escrito como funcao e serializado com
   toString() para nao depender de escapes dentro de strings. */
function coletar() {
  const nomeDe = (el) => el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') +
    (typeof el.className === 'string' && el.className.trim()
      ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : '');

  const cw = document.documentElement.clientWidth;
  const excedentes = [];
  document.querySelectorAll('body *').forEach((el) => {
    const r = el.getBoundingClientRect();
    if (r.width === 0 && r.height === 0) return;
    const exc = Math.round(r.right - cw);
    if (exc > 1) {
      const cs = getComputedStyle(el);
      excedentes.push({ sel: nomeDe(el), exc, w: Math.round(r.width), minW: cs.minWidth, ws: cs.whiteSpace });
    }
  });
  excedentes.sort((a, b) => b.exc - a.exc);

  const roladores = [];
  document.querySelectorAll('body *').forEach((el) => {
    const ox = getComputedStyle(el).overflowX;
    if (ox !== 'auto' && ox !== 'scroll') return;
    const r = el.getBoundingClientRect();
    if (r.width === 0 && r.height === 0) return;
    roladores.push({
      sel: nomeDe(el),
      rola: el.scrollWidth > el.clientWidth + 1,
      motivo: el.getAttribute('data-motivo-rolagem') || '',
    });
  });

  const blocos = [];
  document.querySelectorAll('[data-secao]').forEach((el) => {
    const r = el.getBoundingClientRect();
    blocos.push({
      secao: el.getAttribute('data-secao'),
      w: Math.round(r.width), h: Math.round(r.height),
      estoura: el.scrollWidth > el.clientWidth + 1,
    });
  });

  return {
    innerWidth: window.innerWidth,
    clientWidth: cw,
    scrollWidth: document.documentElement.scrollWidth,
    alturaDoc: document.documentElement.scrollHeight,
    nExcedentes: excedentes.length,
    piores: excedentes.slice(0, 5),
    roladores, blocos,
  };
}

(async () => {
  const wsUrl = await esperarChrome();
  const ws = new WebSocket(wsUrl);
  await new Promise((r) => ws.addEventListener('open', r));
  const s = new Sessao(ws);

  const { targetId } = await s.send('Target.createTarget', { url: 'about:blank' });
  const { sessionId } = await s.send('Target.attachToTarget', { targetId, flatten: true });
  s.sessionId = sessionId;
  await s.send('Page.enable');
  await s.send('Runtime.enable');

  const expr = '(' + coletar.toString() + ')()';
  const linhas = [];
  for (const w of larguras) {
    await s.send('Emulation.setDeviceMetricsOverride', {
      width: w, height: 900, deviceScaleFactor: 1, mobile: true, screenWidth: w, screenHeight: 900,
    });
    await s.send('Emulation.setTouchEmulationEnabled', { enabled: true, maxTouchPoints: 5 });
    await s.send('Page.navigate', { url });
    await dormir(2200);
    const { result } = await s.send('Runtime.evaluate', { expression: expr, returnByValue: true });
    linhas.push({ largura: w, ...result.value });
    if (shotsDir) {
      const { data } = await s.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
      writeFileSync(join(shotsDir, `vp-${w}.png`), Buffer.from(data, 'base64'));
    }
  }

  const pad = (v, n) => String(v).padStart(n);
  console.log('largura | innerW | clientW | scrollW | rola? | alturaDoc | excedentes');
  console.log('--------+--------+---------+---------+-------+-----------+-----------');
  for (const l of linhas) {
    console.log(pad(l.largura, 7) + ' |' + pad(l.innerWidth, 7) + ' |' + pad(l.clientWidth, 8) +
      ' |' + pad(l.scrollWidth, 8) + ' |' + (l.scrollWidth > l.clientWidth ? '   SIM' : '   nao') +
      ' |' + pad(l.alturaDoc, 10) + ' |' + pad(l.nExcedentes, 10));
  }

  const r0 = linhas[0];
  if (r0 && r0.blocos.length) {
    console.log('');
    console.log('secoes — largura x altura (ou ESTOURA)');
    console.log('  ' + 'secao'.padEnd(26) + larguras.map((w) => pad(w + 'px', 10)).join(''));
    for (const b0 of r0.blocos) {
      const cels = linhas.map((l) => {
        const b = l.blocos.find((x) => x.secao === b0.secao);
        return b ? (b.estoura ? 'ESTOURA' : b.w + 'x' + b.h) : '-';
      });
      console.log('  ' + b0.secao.padEnd(26) + cels.map((c) => pad(c, 10)).join(''));
    }
  }

  console.log('');
  console.log('rolagem horizontal interna (overflow-x auto|scroll)');
  const vistos = new Map();
  for (const l of linhas) {
    for (const r of l.roladores) {
      const v = vistos.get(r.sel) || { sel: r.sel, motivo: r.motivo, rolaEm: [] };
      if (r.rola) v.rolaEm.push(l.largura);
      vistos.set(r.sel, v);
    }
  }
  let semMotivo = 0;
  for (const v of vistos.values()) {
    let status;
    if (v.motivo) status = 'permitido';
    else if (v.rolaEm.length) { status = 'SEM MOTIVO'; semMotivo++; }
    else status = 'nao rola';
    console.log('  ' + v.sel.slice(0, 46).padEnd(48) + status.padEnd(12) +
      (v.rolaEm.length ? 'rola em ' + v.rolaEm.join(',') : '') +
      (v.motivo ? '  — ' + v.motivo : ''));
  }
  if (!vistos.size) console.log('  nenhum elemento com overflow-x auto|scroll');
  console.log('  rolagens sem motivo declarado: ' + semMotivo);

  for (const l of linhas) {
    if (l.piores.length) {
      console.log('');
      console.log(`${l.largura}px — quem excede a viewport:`);
      for (const p of l.piores) {
        console.log(`   ${p.sel}  +${p.exc}px  w=${p.w}  min-width=${p.minW}  white-space=${p.ws}`);
      }
    }
  }

  ws.close(); chrome.kill(); process.exit(0);
})().catch((e) => { console.error('ERRO:', e.message); chrome.kill(); process.exit(1); });

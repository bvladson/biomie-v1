/**
 * mobile-overlay-controller — comportamento comum dos overlays no celular.
 *
 * POR QUE EXISTE
 * A fatia anterior organizou a CAMADA (z-index em theme-tokens.css). Isto trata
 * o COMPORTAMENTO: exclusividade, foco e inércia do fundo. A camada diz quem
 * fica por cima; o controlador diz quem pode continuar aberto.
 *
 * O QUE NÃO FAZ
 * Não reescreve a máquina de estado de nenhum componente. Cada um continua
 * abrindo e fechando do seu jeito; o controlador observa o DOM e, quando um
 * overlay aparece, fecha os outros acionando o CONTROLE DE FECHAR do próprio
 * componente — nunca mexendo em classes ou flags internas.
 *
 * O QUE CADA COMPONENTE JÁ FAZIA (auditado em 24/09/2026)
 *   vendor-filter.js         armadilha de foco + retorno ao gatilho + closeOthers
 *   commercial-card-help.js  armadilha de foco + retorno ao gatilho
 *   global-search.js         foca o campo, mas NÃO prende nem devolve o foco
 *   client_dossier.js        nada: sem Esc, sem foco, sem retorno
 * O controlador cobre as lacunas sem duplicar o que já existe: só devolve o
 * foco se o componente tiver deixado o foco no <body>.
 *
 * FUTURO (anotado, não feito nesta fatia)
 * <dialog> + showModal() coloca o elemento na top layer e torna o resto do
 * documento inert automaticamente, dispensando boa parte desta administração.
 * A Popover API resolve os casos não modais. Migrar exigiria mudar o markup
 * dos cinco componentes — escopo maior do que esta fatia comporta.
 */
(function () {
  'use strict';

  var mq = window.matchMedia ? window.matchMedia('(max-width: 767px)') : null;
  function noCelular() { return !!(mq && mq.matches); }

  /* Painel + o controle que o próprio componente usa para fechar. Clicar
     nesse controle percorre o caminho de fechamento real do componente. */
  /* O rotulo so e aplicado quando o painel nao tem nome acessivel proprio.
     'fechar' precisa casar com a classe REAL criada pelo componente: o help
     cria 'business-help-drawer-backdrop', nao 'business-help-backdrop'. */
  var REGISTRO = [
    { nome: 'busca',  rotulo: 'Busca global',
      painel: '.omnisearch-panel',                    fechar: '[data-mobile-search-close]' },
    { nome: 'filtro', rotulo: 'Filtro de vendedor',
      painel: '.biomie-vendor-filter__panel--mobile', fechar: '[data-vendor-filter-close]' },
    { nome: 'ajuda',  rotulo: 'Explicacao do indicador',
      painel: '.business-help-drawer',                fechar: '.business-help-drawer-backdrop' },
    { nome: 'dossie', rotulo: 'Dossie do cliente',
      painel: '.mag-drawer',                          fechar: '#overlay' }
  ];

  var seqId = 0;

  /* Nome acessivel. Ordem: o que o componente ja declara > titulo dentro do
     painel (via aria-labelledby, que e o preferido do padrao de dialogs) >
     rotulo do registro. Nunca sobrescreve nome existente. */
  function nomear(painel, reg) {
    if (painel.hasAttribute('aria-label') || painel.hasAttribute('aria-labelledby')) return;
    var titulo = painel.querySelector('h1,h2,h3,h4,[data-overlay-title]');
    if (titulo && (titulo.textContent || '').trim()) {
      if (!titulo.id) titulo.id = 'overlay-titulo-' + (++seqId);
      painel.setAttribute('aria-labelledby', titulo.id);
      return;
    }
    painel.setAttribute('aria-label', reg.rotulo || reg.nome);
  }

  /* Regiões que ficam inertes com um modal aberto. Lista explícita em vez de
     "todos os irmãos": um backdrop também é irmão, e inertizá-lo impediria o
     clique que fecha o overlay. */
  var FUNDO = ['.shell', '.mag-app-main', 'main', '.mag-bottom-nav', '.mag-mobile-topbar'];

  var FOCAVEIS = 'a[href],button:not([disabled]),input:not([disabled]),' +
                 'select:not([disabled]),textarea:not([disabled]),' +
                 '[tabindex]:not([tabindex="-1"])';

  var ativo = null;
  var inertizados = [];

  /* Ultimo elemento com que a pessoa interagiu. Nao da para confiar apenas em
     document.activeElement: no Safari do iOS, tocar num <button> NAO o foca,
     entao o gatilho se perderia e o foco nao teria para onde voltar. */
  var ultimoGatilho = null;
  var SELETOR_GATILHO = 'button,a[href],[role="button"],summary,input,select,textarea,[tabindex]';

  function registrarGatilho(ev) {
    var alvo = ev.target && ev.target.closest ? ev.target.closest(SELETOR_GATILHO) : null;
    if (alvo) ultimoGatilho = alvo;
  }
  document.addEventListener('pointerdown', registrarGatilho, true);
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Enter' || ev.key === ' ') registrarGatilho(ev);
  }, true);

  function visivel(el) {
    if (!el || el.hidden) return false;
    var cs = window.getComputedStyle(el);
    if (cs.display === 'none' || cs.visibility === 'hidden' || cs.opacity === '0') return false;
    var r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0;
  }

  function focaveisDe(raiz) {
    return Array.prototype.filter.call(raiz.querySelectorAll(FOCAVEIS), visivel);
  }

  function aplicarInercia(painel) {
    limparInercia();
    FUNDO.forEach(function (sel) {
      Array.prototype.forEach.call(document.querySelectorAll(sel), function (el) {
        if (el === painel || el.contains(painel) || painel.contains(el)) return;
        if (el.hasAttribute('inert')) return;
        el.setAttribute('inert', '');
        el.setAttribute('aria-hidden', 'true');
        inertizados.push(el);
      });
    });
  }

  function limparInercia() {
    inertizados.forEach(function (el) {
      el.removeAttribute('inert');
      el.removeAttribute('aria-hidden');
    });
    inertizados = [];
  }

  function fecharOverlay(reg) {
    var ctrl = document.querySelector(reg.fechar);
    if (ctrl && visivel(ctrl)) { ctrl.click(); return true; }
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    return false;
  }

  function abriu(reg, painel) {
    /* A exclusividade e resolvida em varrer(), que ja fechou os demais. */
    /* O gatilho util esta FORA de qualquer painel de overlay e ainda visivel.
       Preferimos o ultimo com que a pessoa interagiu; se nao servir, caimos
       no activeElement. */
    function serve(el) {
      if (!el || el === document.body || !document.contains(el)) return false;
      if (painel.contains(el)) return false;
      for (var k = 0; k < REGISTRO.length; k++) {
        var outro = document.querySelector(REGISTRO[k].painel);
        if (outro && outro.contains(el)) return false;
      }
      return visivel(el);
    }
    var gatilho = serve(ultimoGatilho) ? ultimoGatilho
                : (serve(document.activeElement) ? document.activeElement : null);

    ativo = { reg: reg, painel: painel, gatilho: gatilho };
    aplicarInercia(painel);

    /* Se o componente já moveu o foco para dentro, respeitamos. */
    if (!painel.contains(document.activeElement)) {
      var alvos = focaveisDe(painel);
      if (alvos.length) {
        alvos[0].focus();
      } else {
        if (!painel.hasAttribute('tabindex')) painel.setAttribute('tabindex', '-1');
        painel.focus();
      }
    }

    if (!painel.hasAttribute('role')) painel.setAttribute('role', 'dialog');
    painel.setAttribute('aria-modal', 'true');
    nomear(painel, reg);
  }

  function fechou() {
    if (!ativo) return;
    var gatilho = ativo.gatilho;
    var painelFechado = ativo.painel;
    painelFechado.removeAttribute("aria-modal");
    ativo = null;
    limparInercia();
    if (!gatilho || !document.contains(gatilho) || typeof gatilho.focus !== "function") return;

    /* Devolve o foco ao gatilho quando ele ficou sem dono util: no <body>,
       em lugar nenhum, ou preso dentro do painel que acabou de sumir — e o
       caso do botao de fechar, que continua sendo o activeElement mesmo
       depois de o painel virar display:none. Se o proprio componente ja
       devolveu o foco para algum lugar visivel, nao roubamos. */
    var atual = document.activeElement;
    var semDono = !atual || atual === document.body || painelFechado.contains(atual);
    if (semDono) gatilho.focus();
  }

  function varrer() {
    if (!noCelular()) { if (ativo) fechou(); return; }

    var abertos = [];
    REGISTRO.forEach(function (reg) {
      var el = document.querySelector(reg.painel);
      if (el && visivel(el)) abertos.push({ reg: reg, painel: el });
    });

    if (!abertos.length) { if (ativo) fechou(); return; }

    /* Exclusividade. Com mais de um visivel, quem vence e o RECEM-CHEGADO,
       isto e, o que ainda nao era o ativo. Escolher pela ordem do REGISTRO
       faria o primeiro da lista vencer sempre e a exclusividade nunca
       dispararia — foi o defeito que os testes de comportamento pegaram. */
    var novo = null;
    if (ativo) {
      for (var i = 0; i < abertos.length; i++) {
        if (abertos[i].painel !== ativo.painel) { novo = abertos[i]; break; }
      }
    }
    if (!novo) novo = abertos[0];

    for (var j = 0; j < abertos.length; j++) {
      if (abertos[j].painel !== novo.painel) fecharOverlay(abertos[j].reg);
    }

    if (!ativo || ativo.painel !== novo.painel) abriu(novo.reg, novo.painel);
  }

  /* Esc fecha; Tab circula dentro do overlay. */
  document.addEventListener('keydown', function (ev) {
    if (!ativo || !noCelular()) return;

    if (ev.key === 'Escape') {
      ev.stopPropagation();
      fecharOverlay(ativo.reg);
      return;
    }
    if (ev.key !== 'Tab') return;

    var alvos = focaveisDe(ativo.painel);
    if (!alvos.length) { ev.preventDefault(); return; }
    var primeiro = alvos[0], ultimo = alvos[alvos.length - 1];
    if (!ativo.painel.contains(document.activeElement)) {
      ev.preventDefault();
      (ev.shiftKey ? ultimo : primeiro).focus();
      return;
    }
    if (ev.shiftKey && document.activeElement === primeiro) {
      ev.preventDefault(); ultimo.focus();
    } else if (!ev.shiftKey && document.activeElement === ultimo) {
      ev.preventDefault(); primeiro.focus();
    }
  }, true);

  /* Foco que escapa por clique ou leitor de tela volta para o overlay. */
  document.addEventListener('focusin', function (ev) {
    if (!ativo || !noCelular()) return;
    if (ativo.painel.contains(ev.target)) return;
    var alvos = focaveisDe(ativo.painel);
    if (alvos.length) alvos[0].focus();
  });

  var temporizador = null;
  function iniciar() {
    var obs = new MutationObserver(function () {
      window.clearTimeout(temporizador);
      temporizador = window.setTimeout(varrer, 30);
    });
    obs.observe(document.body, {
      subtree: true, childList: true,
      attributes: true, attributeFilter: ['class', 'hidden', 'style']
    });
    if (mq && mq.addEventListener) mq.addEventListener('change', varrer);
    varrer();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', iniciar);
  } else {
    iniciar();
  }

  window.BIomieMobileOverlays = {
    ativo: function () { return ativo ? ativo.reg.nome : null; },
    fechar: function () { if (ativo) fecharOverlay(ativo.reg); },
    registro: REGISTRO
  };
})();

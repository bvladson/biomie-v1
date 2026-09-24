/**
 * mobile-table — alterna Resumo/Tabela e avisa sobre rolagem só quando existe.
 *
 * Família 5 de 6. Trabalha apenas com markup já presente na página:
 *   [data-mobile-view]        botões do alternador
 *   [data-mobile-view-panel]  painéis correspondentes
 *   .mobile-table-scroll      região rolável (role="region" tabindex="0")
 *
 * A dica "Arraste para comparar" só é escrita quando scrollWidth > clientWidth.
 * Tabela que cabe na tela não ganha aviso de rolagem — avisar sempre treina a
 * pessoa a ignorar o aviso.
 *
 * Não altera dados, API nem o desktop: o alternador só aparece no mobile por
 * CSS, e a medição é recalculada em resize e quando o conteúdo muda.
 */
(function () {
  'use strict';

  function porPainel(grupo, nome) {
    return grupo.querySelector('[data-mobile-view-panel="' + nome + '"]');
  }

  function trocar(grupo, nome) {
    Array.prototype.forEach.call(grupo.querySelectorAll('[data-mobile-view]'), function (btn) {
      var seu = btn.getAttribute('data-mobile-view');
      btn.setAttribute('aria-pressed', seu === nome ? 'true' : 'false');
    });
    Array.prototype.forEach.call(grupo.querySelectorAll('[data-mobile-view-panel]'), function (p) {
      var seu = p.getAttribute('data-mobile-view-panel');
      if (seu === nome) { p.removeAttribute('hidden'); } else { p.setAttribute('hidden', ''); }
    });
    medirGrupo(grupo);
  }

  function medir(scroll) {
    var dica = scroll.parentElement
      ? scroll.parentElement.querySelector('.mobile-table-dica')
      : null;
    if (!dica) return;
    var rola = scroll.scrollWidth > scroll.clientWidth + 1;
    if (rola) { dica.removeAttribute('hidden'); } else { dica.setAttribute('hidden', ''); }
    /* A região só entra na ordem de tabulação quando de fato rola: um
       tabindex="0" num elemento que não rola é uma parada inútil no teclado. */
    if (rola) {
      scroll.setAttribute('tabindex', '0');
      scroll.setAttribute('role', 'region');
    } else {
      scroll.removeAttribute('tabindex');
    }
  }

  function medirGrupo(raiz) {
    Array.prototype.forEach.call(
      (raiz || document).querySelectorAll('.mobile-table-scroll'), medir);
  }

  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest ? ev.target.closest('[data-mobile-view]') : null;
    if (!btn) return;
    var grupo = btn.closest('[data-mobile-views]') || btn.closest('section') || document;
    ev.preventDefault();
    trocar(grupo, btn.getAttribute('data-mobile-view'));
  });

  var t = null;
  function remedir() {
    window.clearTimeout(t);
    t = window.setTimeout(function () { medirGrupo(document); }, 120);
  }
  window.addEventListener('resize', remedir);
  window.addEventListener('orientationchange', remedir);

  function iniciar() {
    medirGrupo(document);
    /* Os corpos de tabela são preenchidos por JS depois da carga; sem observar
       isso, a dica seria calculada numa tabela ainda vazia. */
    var obs = new MutationObserver(remedir);
    Array.prototype.forEach.call(document.querySelectorAll('.mobile-table-scroll'), function (el) {
      obs.observe(el, { childList: true, subtree: true });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', iniciar);
  } else {
    iniciar();
  }

  window.BIomieMobileTable = { medir: medirGrupo, trocar: trocar };
})();

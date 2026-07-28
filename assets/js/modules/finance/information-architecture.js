(function () {
  'use strict';

  var views = {
    summary: {
      title: 'Resumo executivo',
      description: 'O que entrou, o que esta pendente e onde a equipe precisa agir.'
    },
    receivables: {
      title: 'Recebiveis e origem dos valores',
      description: 'Cruze titulo, nota fiscal, pedido, vendedor, produto e metodo de pagamento.'
    },
    collection: {
      title: 'Cobranca e acompanhamento',
      description: 'Priorize a carteira, registre contatos e acompanhe retornos e prazos.'
    },
    explore: {
      title: 'Todos os detalhes',
      description: 'Abra todas as leituras, graficos e tabelas para investigacoes especificas.'
    }
  };

  var selectors = {
    summary: [
      '#financeExecutiveCompass',
      '#financeReadingGuide',
      '#cards',
      '#cards + section',
      '#finance-visual',
      '#financeVisualAnalytics'
    ],
    receivables: [
      '#finance-trace',
      '#financeReceivableTrace',
      '#metodos-produtos',
      '#financeProductPaymentMethods',
      '#financeRawTitlesDetails'
    ],
    collection: [
      '#cobranca-vendedor',
      '#financeCollectionSection',
      '#cobranca-governanca',
      '#financeGovernanceSection'
    ],
    explore: [
      '#finance-visual',
      '#financeVisualAnalytics',
      '#metodos-produtos',
      '#financeProductPaymentMethods',
      '#graficos',
      '#tabelas',
      '#finDrillBar',
      '#financeRawTitlesDetails',
      '.finance-explore-only'
    ]
  };

  function markLegacyExploreSections() {
    var start = document.getElementById('graficos');
    var end = document.getElementById('tabelas');
    var node = start ? start.nextElementSibling : null;
    while (node && node !== end) {
      node.classList.add('finance-explore-only');
      node = node.nextElementSibling;
    }

    var raw = document.getElementById('financeRawTitlesDetails');
    var diagnostics = document.querySelector('.mag-page-main-diagnostics');
    node = raw ? raw.nextElementSibling : null;
    while (node && node !== diagnostics) {
      node.classList.add('finance-explore-only');
      node = node.nextElementSibling;
    }
    if (diagnostics) diagnostics.classList.add('finance-explore-only');
  }

  function allManagedElements() {
    var elements = [];
    Object.keys(selectors).forEach(function (key) {
      selectors[key].forEach(function (selector) {
        document.querySelectorAll(selector).forEach(function (element) {
          element.dataset.financeManaged = 'true';
          if (!elements.includes(element)) elements.push(element);
        });
      });
    });
    return elements;
  }

  function mirrorExecutiveValues() {
    document.querySelectorAll('[data-finance-mirror]').forEach(function (target) {
      var source = document.querySelector(target.getAttribute('data-finance-mirror'));
      if (source) target.textContent = (source.childNodes[0] && source.childNodes[0].nodeValue) || source.textContent || '0';
    });
  }

  function setChartFocus(nextFocus) {
    var focus = ['all', 'cash', 'risk', 'team'].includes(nextFocus) ? nextFocus : 'all';
    document.body.dataset.financeChartFocus = focus;
    document.querySelectorAll('[data-finance-chart-group]').forEach(function (card) {
      var visible = focus === 'all' || card.getAttribute('data-finance-chart-group') === focus;
      card.dataset.financeFocusHidden = visible ? 'false' : 'true';
    });
    document.querySelectorAll('[data-finance-chart-focus]').forEach(function (button) {
      button.classList.toggle('is-active', button.getAttribute('data-finance-chart-focus') === focus);
    });
    window.setTimeout(function () { window.dispatchEvent(new Event('resize')); }, 80);
  }

  function switchView(nextView, options) {
    var view = views[nextView] ? nextView : 'summary';
    var allowed = [];
    selectors[view].forEach(function (selector) {
      document.querySelectorAll(selector).forEach(function (element) {
        if (!allowed.includes(element)) allowed.push(element);
      });
    });
    allManagedElements().forEach(function (element) {
      element.hidden = !allowed.includes(element);
    });

    document.body.dataset.financeView = view;
    document.querySelectorAll('[data-finance-view]').forEach(function (button) {
      var active = button.getAttribute('data-finance-view') === view;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-selected', active ? 'true' : 'false');
    });

    var title = document.getElementById('financeWorkspaceTitle');
    var description = document.getElementById('financeWorkspaceDescription');
    if (title) title.textContent = views[view].title;
    if (description) description.textContent = views[view].description;
    window.setTimeout(function () {
      window.dispatchEvent(new Event('resize'));
    }, 80);
    if (options && options.scroll) {
      document.querySelector('.finance-workspace')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  }

  function bind() {
    markLegacyExploreSections();
    document.querySelectorAll('[data-finance-view]').forEach(function (button) {
      button.addEventListener('click', function () {
        switchView(button.getAttribute('data-finance-view'), { scroll: true });
      });
    });
    document.querySelectorAll('[data-finance-jump]').forEach(function (link) {
      link.addEventListener('click', function () {
        switchView(link.getAttribute('data-finance-jump'), { scroll: false });
      });
    });
    document.querySelectorAll('[data-finance-chart-focus]').forEach(function (button) {
      button.addEventListener('click', function () {
        setChartFocus(button.getAttribute('data-finance-chart-focus'));
      });
    });

    switchView('summary');
    setChartFocus('all');
    mirrorExecutiveValues();
  }

  document.addEventListener('DOMContentLoaded', bind);
  document.addEventListener('biomie:finance-overview-loaded', mirrorExecutiveValues);

  window.BIomieFinanceWorkspace = {
    switchView: function (view) { switchView(view, { scroll: true }); },
    setChartFocus: setChartFocus
  };
})();

<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <!-- `only light`: o app é light-only. Prometer "dark" faria o navegador
       desenhar scrollbars e controles nativos escuros numa página clara, e o
       modificador `only` ainda barra a adaptação automática de dark mode. -->
  <meta name="color-scheme" content="only light" />
  <meta name="theme-color" content="#f6f3f2" />
  <meta name="description" content="Agenda de orcamentos: quem cobrar, quando cobrar e quanto ainda esta sem fechamento." />
  <meta name="application-name" content="BIomie" />
  <meta name="mobile-web-app-capable" content="yes" />
  <meta name="apple-mobile-web-app-capable" content="yes" />
  <meta name="apple-mobile-web-app-title" content="BIomie" />
  <meta name="apple-mobile-web-app-status-bar-style" content="default" />
  <title>Agenda de Orçamentos | BIomie MAG</title>
  <link rel="icon" href="images/favicon.svg" type="image/svg+xml" />
  <link rel="manifest" href="site.webmanifest" />
  <link rel="apple-touch-icon" href="images/pwa/apple-touch-icon.png" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet" media="print" onload="this.media='all'" />
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet" media="print" onload="this.media='all'" />
  <link rel="stylesheet" href="assets/css/theme-tokens.css?v=20260425-ui1" />
  <link rel="stylesheet" href="assets/css/shell-index.css?v=20260425-ui1" />
  <link rel="stylesheet" href="assets/css/module-pages.css?v=20260425-ui1" />
</head>
<body>
<main class="page-shell">
  <header class="page-head">
    <div>
      <h1 class="page-title">Agenda de Orçamentos</h1>
      <p class="page-subtitle">Quem cobrar, quando cobrar e quanto ainda está sem fechamento.</p>
    </div>
  </header>

<?php if (empty($module_enabled)): ?>
  <!-- Gate negado: o elemento raiz NÃO é renderizado, então o script (injetado
       pelo PageAssetRegistry) não encontra âncora e nenhuma chamada de API ocorre. -->
  <section class="qa-inactive" data-qa-inactive>
    <?php
      $agendaState = is_array($module_state ?? null) ? $module_state : [];
      $agendaReason = (string) ($agendaState['reason'] ?? 'company_disabled');
      $agendaMessage = (string) ($agendaState['message']
        ?? 'A Agenda de Orçamentos ainda não foi ativada para esta empresa.');
    ?>
    <p><strong><?= $agendaReason === 'availability_unavailable'
      ? 'Agenda temporariamente indisponível.'
      : 'Agenda não habilitada.' ?></strong></p>
    <p><?= htmlspecialchars($agendaMessage, ENT_QUOTES, 'UTF-8') ?></p>
    <?php if (!empty($is_admin) && $agendaReason === 'global_disabled'): ?>
      <p>O interruptor global de leitura está desligado. A política individual da empresa foi preservada.</p>
    <?php elseif (!empty($is_admin) && in_array($agendaReason, ['company_disabled', 'company_not_configured'], true)): ?>
      <p>A liberação continua restrita à política desta empresa; nenhuma automação foi iniciada.</p>
    <?php endif; ?>
  </section>
<?php else: ?>

  <div class="qa-shell" id="quoteAgendaRoot"
       data-can-manage="<?= !empty($can_manage) ? '1' : '0' ?>"
       data-can-history="<?= !empty($can_history) ? '1' : '0' ?>">

    <!-- Banner de frescor: some quando o snapshot está atual. -->
    <div class="qa-banner" id="qaFreshness" role="status" aria-live="polite" hidden></div>

    <!-- Toggle de Operação (varejo/licitação) — só aparece em contexto mixed. -->
    <div data-commercial-channel-toggle hidden></div>

    <section class="qa-kpis" aria-label="Indicadores">
      <article class="qa-kpi" id="qaKpiToday">
        <p class="qa-kpi-label">Cobrar hoje</p>
        <p class="qa-kpi-count" data-kpi-count>—</p>
        <p class="qa-kpi-value" data-kpi-value></p>
      </article>
      <article class="qa-kpi" id="qaKpiOverdue">
        <p class="qa-kpi-label">Atrasados</p>
        <p class="qa-kpi-count" data-kpi-count>—</p>
        <p class="qa-kpi-value" data-kpi-value></p>
      </article>
      <article class="qa-kpi" id="qaKpiOpen">
        <p class="qa-kpi-label">Em aberto</p>
        <p class="qa-kpi-count" data-kpi-count>—</p>
        <p class="qa-kpi-value" data-kpi-value></p>
      </article>
      <article class="qa-kpi" id="qaKpiCritical">
        <p class="qa-kpi-label">Críticos</p>
        <p class="qa-kpi-count" data-kpi-count>—</p>
        <p class="qa-kpi-value" data-kpi-value></p>
      </article>
    </section>

    <!-- KPI técnico (qualidade): só aparece se houver data provisória. -->
    <p class="qa-provisional" id="qaProvisional" hidden></p>

    <div class="qa-toolbar">
      <button type="button" class="btn-secondary btn-sm" id="qaPrev" aria-label="Mês anterior">
        <span class="material-symbols-rounded" aria-hidden="true">chevron_left</span>
      </button>
      <span class="qa-month-label" id="qaMonthLabel" aria-live="polite">—</span>
      <button type="button" class="btn-secondary btn-sm" id="qaNext" aria-label="Próximo mês">
        <span class="material-symbols-rounded" aria-hidden="true">chevron_right</span>
      </button>
    </div>

    <section class="qa-grid" id="qaGrid" role="grid" aria-label="Calendário de cobranças"></section>
  </div>

  <!-- Painel lateral: carregado sob demanda ao selecionar um dia. -->
  <aside class="qa-panel" id="qaPanel" aria-label="Orçamentos do dia">
    <header class="qa-panel-head">
      <span class="qa-panel-title" id="qaPanelTitle">—</span>
      <button type="button" class="btn-secondary btn-sm" data-qa-close aria-label="Fechar painel">
        <span class="material-symbols-rounded" aria-hidden="true">close</span>
      </button>
    </header>
    <div class="qa-panel-body" id="qaPanelBody"></div>
  </aside>
<?php endif; ?>
</main>


</body>
</html>

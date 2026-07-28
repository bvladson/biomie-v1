<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Support\TenantContext;

require __DIR__ . '/app/page_bootstrap.php';

// Painel da Diretoria (modo TV). Requer permissão comercial; agrega apenas as
// empresas acessíveis ao usuário (ver BoardConsolidatedService).
Auth::requireCapability('sales.view', false);

$assetVersion = static fn (string $rel): string => (string) (@filemtime(__DIR__ . '/' . $rel) ?: '1');
$cssUrl = Auth::url('assets/css/tv-board.css') . '?v=' . $assetVersion('assets/css/tv-board.css');
$jsUrl  = Auth::url('assets/js/tv-board.js') . '?v=' . $assetVersion('assets/js/tv-board.js');
$apiUrl = Auth::url('api/board_consolidated.php');
$contextId = TenantContext::contextId();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <meta name="robots" content="noindex" />
  <title>Painel da Diretoria · BIomie</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <!-- Fontes carregadas de forma não-bloqueante (media=print → all no onload):
       o painel pinta imediatamente e as fontes trocam ao chegar. Antes o
       Material Symbols com display=block bloqueava o texto até baixar. -->
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=swap" rel="stylesheet" media="print" onload="this.media='all'" />
  <noscript><link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=swap" rel="stylesheet" /></noscript>
  <link rel="stylesheet" href="<?php echo htmlspecialchars($cssUrl, ENT_QUOTES, 'UTF-8'); ?>" />
</head>
<body
  class="tvb"
  data-api="<?php echo htmlspecialchars($apiUrl, ENT_QUOTES, 'UTF-8'); ?>"
  data-context="<?php echo htmlspecialchars($contextId, ENT_QUOTES, 'UTF-8'); ?>"
  data-refresh="300"
>

  <!-- Topbar -->
  <header class="tvb-top">
    <div class="tvb-top__brand">
      <span class="material-symbols-rounded tvb-brand-icon" aria-hidden="true">insights</span>
      <div>
        <h1 class="tvb-top__title">Painel da Diretoria</h1>
        <div class="tvb-top__sub" id="tvbPeriod">Carregando…</div>
      </div>
    </div>
    <div class="tvb-top__right">
      <div class="tvb-live" id="tvbConnection" data-state="loading" role="status" aria-live="polite">
        <span class="tvb-live__dot" aria-hidden="true"></span>
        <span id="tvbConnectionText">Atualizando</span>
      </div>
      <div class="tvb-clock-wrap">
        <div class="tvb-clock" id="tvbClock" aria-live="off">--:--</div>
        <div class="tvb-date" id="tvbDate"></div>
      </div>
      <button type="button" class="tvb-btn" id="tvbFullscreen" title="Tela cheia" aria-label="Alternar tela cheia">
        <span class="material-symbols-rounded" aria-hidden="true" style="font-size:22px">fullscreen</span>
      </button>
    </div>
  </header>

  <main class="tvb-main" id="tvbMain">

    <!-- Hero: 4-column layout -->
    <section class="tvb-hero" id="tvbHero" aria-label="Resumo consolidado do grupo">

      <!-- Col 1: Mês atual -->
      <div class="tvb-hero__block">
        <div class="tvb-hero__label">
          <span class="material-symbols-rounded tvb-hero__label-icon" aria-hidden="true">calendar_month</span>
          Faturamento Mensal
        </div>
        <div class="tvb-hero__value" id="tvbGroupRevenue">—</div>
        <div class="tvb-hero__meta" id="tvbGroupTarget">Meta —</div>
        <div class="tvb-hero__meta" id="tvbGroupOrders">—</div>
        <div class="tvb-hero__channels" id="tvbGroupChannels"></div>
      </div>

      <!-- Col 2+3: Gauges (mensal grande + anual menor) -->
      <div class="tvb-gauges-wrap">
        <div class="tvb-gauges-row">
          <!-- Monthly gauge -->
          <div class="tvb-gauge-wrap">
            <div class="tvb-gauge-label">Mês</div>
            <div class="tvb-gauge" id="tvbGroupGauge" style="--pct:0" role="progressbar" aria-label="Atingimento mensal" aria-valuemin="0" aria-valuemax="100">
              <div class="tvb-gauge__inner">
                <div class="tvb-gauge__pct" id="tvbGroupPct">—</div>
                <div class="tvb-gauge__cap">atingido</div>
              </div>
            </div>
          </div>
          <!-- Annual gauge -->
          <div class="tvb-gauge-wrap">
            <div class="tvb-gauge-label">Ano</div>
            <div class="tvb-gauge tvb-gauge--sm" id="tvbGroupGaugeAnn" style="--ann-pct:0" role="progressbar" aria-label="Atingimento acumulado no ano" aria-valuemin="0" aria-valuemax="100">
              <div class="tvb-gauge__inner">
                <div class="tvb-gauge__pct" id="tvbGroupAnnPct">—</div>
                <div class="tvb-gauge__cap">YTD</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Col 3: YTD acumulado -->
      <div class="tvb-hero__block tvb-hero__block--center">
        <div class="tvb-hero__label">
          <span class="material-symbols-rounded tvb-hero__label-icon" aria-hidden="true">trending_up</span>
          Acumulado Ano (YTD)
        </div>
        <div class="tvb-hero__value--annual" id="tvbGroupAnnualRevenue">—</div>
        <div class="tvb-hero__meta" id="tvbGroupAnnualTarget">Meta —</div>
        <div id="tvbGroupAnnualPctBadge"></div>
        <div class="tvb-hero__coverage" id="tvbGroupCoverage">—</div>
      </div>

      <!-- Col 4: Líder -->
      <div class="tvb-hero__block tvb-hero__block--right">
        <div class="tvb-hero__label">
          Maior faturamento MTD
          <span class="material-symbols-rounded tvb-hero__label-icon tvb-hero__label-icon--gold" aria-hidden="true">emoji_events</span>
        </div>
        <div class="tvb-hero__leader" id="tvbLeader">—</div>
        <div class="tvb-hero__meta" id="tvbLeaderRevenue">—</div>
        <div class="tvb-hero__meta" id="tvbLeaderAtt">—</div>
      </div>

    </section>

    <div class="tvb-section-head">
      <h2>Resultados por empresa</h2>
      <div id="tvbCompanySummary">Carregando dados fiscais…</div>
    </div>

    <!-- Grid de empresas -->
    <section class="tvb-grid" id="tvbGrid" aria-label="Resultados por empresa">
      <div class="tvb-skeleton" role="status">Carregando resultados das empresas…</div>
    </section>

  </main>

  <footer class="tvb-foot">
    <div class="tvb-foot__left" id="tvbStatus" role="status" aria-live="polite">—</div>
    <div class="tvb-foot__right">BIomie · Grupo MAG</div>
  </footer>

  <div class="tvb-error" id="tvbError" role="alert" hidden>
    <span class="material-symbols-rounded" aria-hidden="true" style="font-size:26px;flex-shrink:0">cloud_off</span>
    <span id="tvbErrorMsg">Não foi possível carregar os dados.</span>
    <button type="button" class="tvb-error__action" id="tvbRetry">Tentar agora</button>
  </div>

  <script src="<?php echo htmlspecialchars($jsUrl, ENT_QUOTES, 'UTF-8'); ?>" defer></script>
</body>
</html>

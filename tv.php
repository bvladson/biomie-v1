<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Cache;
use BIomie\Http;
use BIomie\Modules\Sales\Application\Board\BoardConsolidatedService;
use BIomie\Support\TenantContext;

require __DIR__ . '/app/page_bootstrap.php';

/** @var \BIomie\Config $config definido em app/page_bootstrap.php */
/** @var \BIomie\Logger $logger definido em app/page_bootstrap.php */

// Painel da Diretoria (modo TV). Requer permissão comercial; agrega apenas as
// empresas acessíveis ao usuário (ver BoardConsolidatedService).
Auth::requireCapability('sales.view', false);

$assetVersion = static fn (string $rel): string => (string) (@filemtime(__DIR__ . '/' . $rel) ?: '1');
$cssUrl = Auth::url('assets/css/tv-board.css') . '?v=' . $assetVersion('assets/css/tv-board.css');
$jsUrl  = Auth::url('assets/js/tv-board.js') . '?v=' . $assetVersion('assets/js/tv-board.js');
$apiUrl = Auth::url('api/board_consolidated.php');
$logoUrl = Auth::url('images/logo-icon.svg') . '?v=' . $assetVersion('images/logo-icon.svg');
$contextId = TenantContext::contextId();

// Hydrate from the same service used by the API. Cache hits are effectively
// immediate; on a miss the consolidated service computes once and warms the
// shared cache. Any failure remains non-blocking because fetch is the fallback.
$initialPayloadJson = '';
$initialPayloadEtag = '';
try {
    $currentUserId = (int) (Auth::currentUser()['id'] ?? 0);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $today = date('Y-m-d');
    $monthStart = date('Y-m-01');
    $annualStart = date('Y-01-01');
    $cachedPayload = $currentUserId > 0
        ? (new BoardConsolidatedService(
            $config,
            $logger,
            new Cache(__DIR__ . '/storage/cache'),
        ))->build($currentUserId, $monthStart, $today, $annualStart)
        : null;
    if (is_array($cachedPayload) && ($cachedPayload['ok'] ?? false) === true) {
        [$cachedPayload] = Http::contextualizePayload($cachedPayload);
        $etagJson = json_encode(
            $cachedPayload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );
        $embeddedJson = json_encode(
            $cachedPayload,
            JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_INVALID_UTF8_SUBSTITUTE
                | JSON_HEX_TAG
                | JSON_HEX_AMP
                | JSON_HEX_APOS
                | JSON_HEX_QUOT,
        );
        if (is_string($etagJson) && is_string($embeddedJson)) {
            $initialPayloadJson = $embeddedJson;
            $initialPayloadEtag = '"' . md5($etagJson) . '"';
        }
    }
} catch (Throwable) {
    // Cache hydration is an optimization only; fetch handles every fallback.
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <meta name="color-scheme" content="dark" />
  <meta name="robots" content="noindex" />
  <title>Painel da Diretoria · BIomie</title>
  <link rel="stylesheet" href="<?php echo htmlspecialchars($cssUrl, ENT_QUOTES, 'UTF-8'); ?>" />
  <script src="<?php echo htmlspecialchars($jsUrl, ENT_QUOTES, 'UTF-8'); ?>" defer fetchpriority="high"></script>
</head>
<body
  class="tvb"
  data-api="<?php echo htmlspecialchars($apiUrl, ENT_QUOTES, 'UTF-8'); ?>"
  data-context="<?php echo htmlspecialchars($contextId, ENT_QUOTES, 'UTF-8'); ?>"
  data-refresh="300"
>
  <?php if ($initialPayloadJson !== ''): ?>
    <script
      type="application/json"
      id="tvbInitialData"
      data-etag="<?php echo htmlspecialchars($initialPayloadEtag, ENT_QUOTES, 'UTF-8'); ?>"
    ><?php echo $initialPayloadJson; ?></script>
  <?php endif; ?>

  <!-- Topbar -->
  <header class="tvb-top">
    <div class="tvb-top__brand">
      <img
        class="tvb-brand-icon"
        src="<?php echo htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8'); ?>"
        width="56"
        height="56"
        alt=""
        decoding="async"
      />
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
        <span class="tvb-fullscreen-glyph" aria-hidden="true">⛶</span>
      </button>
    </div>
  </header>

  <main class="tvb-main" id="tvbMain">

    <!-- Hero: 4-column layout -->
    <section class="tvb-hero" id="tvbHero" aria-label="Resumo consolidado do grupo">

      <!-- Col 1: Mês atual -->
      <div class="tvb-hero__block">
        <div class="tvb-hero__label">
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
          <span class="tvb-leader-mark" aria-hidden="true">◆</span>
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
    <span class="tvb-error__icon" aria-hidden="true">!</span>
    <span id="tvbErrorMsg">Não foi possível carregar os dados.</span>
    <button type="button" class="tvb-error__action" id="tvbRetry">Tentar agora</button>
  </div>
</body>
</html>

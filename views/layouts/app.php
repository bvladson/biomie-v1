<?php

declare(strict_types=1);

$magHeadHtml = (string) ($headHtml ?? '');
$magBodyClass = trim((string) ($bodyClass ?? 'mag-page mag-clean-page'));
$magPageKey = trim((string) ($pageKey ?? 'dashboard')) ?: 'dashboard';
$magContentHtml = (string) ($contentHtml ?? '');
$magBodyExtraHtml = (string) ($bodyExtraHtml ?? '');
$magLayoutContext = is_array($layoutContext ?? null) ? $layoutContext : [];
$magContextFiltersEnabled = (bool) ($magLayoutContext['contextFiltersEnabled'] ?? true);

$magBodyClassParts = preg_split('/\s+/', $magBodyClass) ?: [];
$magLegacyLayoutClasses = [
    'nav-md' => true,
    'nav-sm' => true,
    'sidebar-mini' => true,
    'sidebar-collapse' => true,
    'sidebar-collapsed' => true,
    'sidebar-open' => true,
    'menu-open' => true,
];
$magBodyClassParts = array_values(array_filter(
    $magBodyClassParts,
    static fn (string $class): bool => $class !== '' && !isset($magLegacyLayoutClasses[$class])
));
$magBodyClassParts = array_values(array_unique(array_filter(array_merge($magBodyClassParts, ['mag-page', 'mag-clean-page', 'mag-app-shell-page']))));
$magBodyClass = implode(' ', $magBodyClassParts);

extract($magLayoutContext, EXTR_SKIP);
$currentPageKey = $magPageKey;
$activePage = $magPageKey;

$magBodyCompany = '';
$magBodyCanal   = '';
try {
    if (\BIomie\Support\TenantContext::isBooted()) {
        $magBodyCompany = \BIomie\Support\TenantContext::slug();
        $magBodyCanal   = \BIomie\Support\TenantContext::canal();
    }
} catch (\Throwable) {}

$magCompanyThemeCss = '';
if ($magBodyCompany !== '') {
    $magCompanyThemeCss = \BIomie\Company\CompanyPackageLoader::resolveThemeCssContent($magBodyCompany);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet" />
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet" media="print" onload="this.media='all'" />
<noscript><link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet" /></noscript>
<?php echo $magHeadHtml; ?>
<?php if ($magCompanyThemeCss !== ''): ?>
<style><?php echo $magCompanyThemeCss; ?></style>
<?php endif; ?>
<script>
(function () {
  try {
    var compact = localStorage.getItem('biomie.sidebar.compact');
    if (compact === null) compact = localStorage.getItem('biomie:sidebar-collapsed');
    if (compact === '1') document.documentElement.classList.add('sidebar-compact');
  } catch (error) {}
})();
</script>
</head>
<body
  class="<?php echo htmlspecialchars($magBodyClass, ENT_QUOTES, 'UTF-8'); ?>"
  data-page="<?php echo htmlspecialchars($magPageKey, ENT_QUOTES, 'UTF-8'); ?>"
  <?php if ($magBodyCompany !== ''): ?>data-company="<?php echo htmlspecialchars(strtoupper($magBodyCompany), ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?>
  <?php if ($magBodyCanal !== ''): ?>data-canal="<?php echo htmlspecialchars($magBodyCanal, ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?>
>
<div class="biomie-nav-progress" id="biomieNavProgress" aria-hidden="true"></div>
<div class="overlay" id="overlay" aria-hidden="true"></div>
<?php require dirname(__DIR__) . '/partials/mobile_topbar.php'; ?>
<?php require dirname(__DIR__) . '/partials/mobile_bottom_nav.php'; ?>

<div class="shell mag-app-shell">
  <?php require dirname(__DIR__) . '/partials/sidebar.php'; ?>

  <div class="main mag-app-main">
    <?php require dirname(__DIR__) . '/partials/topbar.php'; ?>
    <?php echo $magContentHtml; ?>
    <?php if (!empty($isAdmin)): ?>
      <details class="mag-technical-diagnostics mag-performance-diagnostics" data-performance-diagnostics hidden>
        <summary>Diagnostico de desempenho</summary>
        <div class="mag-technical-diagnostics__body">
          <h2>Tempo de carregamento</h2>
          <p>Tempos coletados no navegador para chamadas de API desta pagina.</p>
          <div class="mag-technical-diagnostics__grid" data-performance-diagnostics-grid></div>
          <div class="mag-performance-diagnostics__list" data-performance-diagnostics-list></div>
        </div>
      </details>
    <?php endif; ?>
  </div>
</div>

<?php require dirname(__DIR__) . '/partials/drawer-root.php'; ?>
<?php if ($magContextFiltersEnabled): ?>
  <?php require dirname(__DIR__) . '/partials/context-filters.php'; ?>
<?php endif; ?>
<?php echo $magBodyExtraHtml; ?>
</body>
</html>

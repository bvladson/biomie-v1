<?php

declare(strict_types=1);

$magHeadHtml = (string) ($headHtml ?? '');
$magBodyClass = trim((string) ($bodyClass ?? 'mag-page mag-clean-page'));
$magPageKey = trim((string) ($pageKey ?? 'dashboard')) ?: 'dashboard';
$magContentHtml = (string) ($contentHtml ?? '');
$magBodyExtraHtml = (string) ($bodyExtraHtml ?? '');
$magLayoutContext = is_array($layoutContext ?? null) ? $layoutContext : [];

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
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<?php echo $magHeadHtml; ?>
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
<body class="<?php echo htmlspecialchars($magBodyClass, ENT_QUOTES, 'UTF-8'); ?>" data-page="<?php echo htmlspecialchars($magPageKey, ENT_QUOTES, 'UTF-8'); ?>">
<div class="overlay" id="overlay" aria-hidden="true"></div>

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

<?php echo $magBodyExtraHtml; ?>
</body>
</html>

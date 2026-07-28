<?php

declare(strict_types=1);

/**
 * Drawer root — loads drawer-manager.js and activates URL deep-link parsing.
 * Included once per page via views/layouts/app.php before $magBodyExtraHtml.
 *
 * Deep-link format:
 *   vendedores.php?open=vendor&id=123
 *   pedidos.php?open=invoice&nf=00001247
 *   financeiro.php?open=customer&document=57228483000100
 */
$_drawerManagerVer = @filemtime(dirname(__DIR__, 2) . '/assets/js/core/drawer-manager.js') ?: '20260514';
?>
<script src="assets/js/core/drawer-manager.js?v=<?= $_drawerManagerVer ?>"></script>
<script>
(function () {
  if (window.BIomieDrawer && typeof BIomieDrawer.initFromUrl === 'function') {
    BIomieDrawer.initFromUrl();
  }
}());
</script>

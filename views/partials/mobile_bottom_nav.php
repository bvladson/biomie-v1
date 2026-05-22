<?php

declare(strict_types=1);

// 5 atalhos mais usados no mobile. Exibido apenas em mobile via CSS.
$mobileNavItems = [
    [
        'href'    => 'index.php',
        'page'    => 'dashboard',
        'icon'    => 'space_dashboard',
        'label'   => 'Painel',
        'aria'    => 'Painel Comercial',
    ],
    [
        'href'    => 'vendedores.php',
        'page'    => 'time-comercial',
        'icon'    => 'groups',
        'label'   => 'Time',
        'aria'    => 'Time Comercial',
    ],
    [
        'href'    => 'mercadorias.php',
        'page'    => 'mix-faturado',
        'icon'    => 'inventory_2',
        'label'   => 'Mix',
        'aria'    => 'Mix Faturado',
    ],
    [
        'href'    => 'financeiro.php',
        'page'    => 'financeiro',
        'icon'    => 'account_balance_wallet',
        'label'   => 'Finan.',
        'aria'    => 'Financeiro',
    ],
    [
        'href'    => 'plano_voo.php',
        'page'    => 'rota-comercial',
        'icon'    => 'flight',
        'label'   => 'Rota',
        'aria'    => 'Rota Comercial',
    ],
];
?>
<nav class="mag-bottom-nav" aria-label="Navegação principal mobile">
  <ul class="mag-bottom-nav__list" role="list">
    <?php foreach ($mobileNavItems as $mobileNavItem): ?>
    <li class="mag-bottom-nav__item">
      <a
        class="mag-bottom-nav__link"
        href="<?php echo htmlspecialchars($mobileNavItem['href'], ENT_QUOTES, 'UTF-8'); ?>"
        data-page="<?php echo htmlspecialchars($mobileNavItem['page'], ENT_QUOTES, 'UTF-8'); ?>"
        aria-label="<?php echo htmlspecialchars($mobileNavItem['aria'], ENT_QUOTES, 'UTF-8'); ?>"
      >
        <span class="material-symbols-rounded" aria-hidden="true"><?php echo htmlspecialchars($mobileNavItem['icon'], ENT_QUOTES, 'UTF-8'); ?></span>
        <span><?php echo htmlspecialchars($mobileNavItem['label'], ENT_QUOTES, 'UTF-8'); ?></span>
      </a>
    </li>
    <?php endforeach; ?>
  </ul>
</nav>

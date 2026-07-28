<?php

declare(strict_types=1);

// 4 atalhos principais + botão "Mais" para abrir o menu completo (sidebar).
// Trocar a lista de 5 itens fixos que excluía NFs, Mapa, Omie e Admin.
$mobileNavItems = [
    [
        'href'  => 'index.php',
        'page'  => 'dashboard',
        'icon'  => 'home',
        'label' => 'Painel',
        'aria'  => 'Painel Comercial',
    ],
    [
        'href'  => 'pedidos.php',
        'page'  => 'nf-periodo',
        'icon'  => 'receipt_long',
        'label' => 'NFs',
        'aria'  => 'NF do Período',
    ],
    [
        'href'  => 'vendedores.php',
        'page'  => 'time-comercial',
        'icon'  => 'group',
        'label' => 'Time',
        'aria'  => 'Time Comercial',
    ],
    [
        'href'  => 'mercadorias.php',
        'page'  => 'mix-faturado',
        'icon'  => 'category',
        'label' => 'Mix',
        'aria'  => 'Mix Faturado',
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
    <li class="mag-bottom-nav__item">
      <button
        type="button"
        class="mag-bottom-nav__link mag-bottom-nav__more"
        id="mobileMoreButton"
        data-mobile-sidebar-toggle
        aria-label="Abrir menu completo"
        aria-controls="sidebar"
        aria-expanded="false"
      >
        <span class="material-symbols-rounded" aria-hidden="true">apps</span>
        <span>Mais</span>
      </button>
    </li>
  </ul>
</nav>

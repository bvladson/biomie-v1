<?php

declare(strict_types=1);

$magHeaderIcon = (string) ($pageIcon ?? 'space_dashboard');
$magHeaderKicker = (string) ($pageKicker ?? 'BIomie MAG');
$magHeaderTitle = (string) ($title ?? $pageTitle ?? 'Painel BIomie');
$magHeaderSubtitle = (string) ($description ?? $pageSubtitle ?? '');
$magHeaderBadge = (string) ($badge ?? $pageBadge ?? $magHeaderKicker);
$magHeaderActions = is_array($actions ?? null) ? $actions : (is_array($pageActions ?? null) ? $pageActions : []);
?>
<section class="biomie-page-header biomie-page-header--shell">
  <div class="biomie-page-header__body">
    <div class="biomie-page-header__eyebrow">
      <span class="material-symbols-rounded"><?php echo htmlspecialchars($magHeaderIcon, ENT_QUOTES, 'UTF-8'); ?></span>
      <?php echo htmlspecialchars($magHeaderBadge, ENT_QUOTES, 'UTF-8'); ?>
    </div>
    <h1 class="biomie-page-header__title"><?php echo htmlspecialchars($magHeaderTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
    <?php if ($magHeaderSubtitle !== ''): ?>
      <p class="biomie-page-header__subtitle"><?php echo htmlspecialchars($magHeaderSubtitle, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
  </div>
  <?php if ($magHeaderActions !== []): ?>
    <div class="biomie-page-header__actions">
      <?php foreach ($magHeaderActions as $magAction): ?>
        <a
          class="<?php echo htmlspecialchars((string) ($magAction['class'] ?? 'btn-secondary'), ENT_QUOTES, 'UTF-8'); ?>"
          href="<?php echo htmlspecialchars((string) ($magAction['href'] ?? '#'), ENT_QUOTES, 'UTF-8'); ?>"
        >
          <?php if (!empty($magAction['icon'])): ?>
            <span class="material-symbols-rounded"><?php echo htmlspecialchars((string) $magAction['icon'], ENT_QUOTES, 'UTF-8'); ?></span>
          <?php endif; ?>
          <?php echo htmlspecialchars((string) ($magAction['label'] ?? 'Abrir'), ENT_QUOTES, 'UTF-8'); ?>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

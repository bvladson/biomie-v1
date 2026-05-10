<?php

declare(strict_types=1);

$magKpiTitle = (string) ($title ?? 'Indicador');
$magKpiValue = (string) ($value ?? '-');
$magKpiSubtitle = (string) ($subtitle ?? '');
$magKpiVariation = (string) ($variation ?? '');
$magKpiIcon = (string) ($icon ?? '');
$magKpiHref = (string) ($href ?? '');
$magKpiBind = (string) ($bind ?? '');
?>
<article class="mag-kpi-card">
  <div class="mag-kpi-card__head">
    <span class="mag-kpi-card__title"><?php echo htmlspecialchars($magKpiTitle, ENT_QUOTES, 'UTF-8'); ?></span>
    <?php if ($magKpiIcon !== ''): ?>
      <span class="material-symbols-rounded mag-kpi-card__icon"><?php echo htmlspecialchars($magKpiIcon, ENT_QUOTES, 'UTF-8'); ?></span>
    <?php endif; ?>
  </div>
  <strong class="mag-kpi-card__value" <?php echo $magKpiBind !== '' ? 'data-bind="' . htmlspecialchars($magKpiBind, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>>
    <?php echo htmlspecialchars($magKpiValue, ENT_QUOTES, 'UTF-8'); ?>
  </strong>
  <?php if ($magKpiSubtitle !== ''): ?>
    <span class="mag-kpi-card__subtitle"><?php echo htmlspecialchars($magKpiSubtitle, ENT_QUOTES, 'UTF-8'); ?></span>
  <?php endif; ?>
  <?php if ($magKpiVariation !== ''): ?>
    <span class="mag-kpi-card__variation"><?php echo htmlspecialchars($magKpiVariation, ENT_QUOTES, 'UTF-8'); ?></span>
  <?php endif; ?>
  <?php if ($magKpiHref !== ''): ?>
    <a class="mag-kpi-card__link" href="<?php echo htmlspecialchars($magKpiHref, ENT_QUOTES, 'UTF-8'); ?>">Ver detalhes</a>
  <?php endif; ?>
</article>

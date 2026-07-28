<?php

declare(strict_types=1);

$magMigIcon = (string) ($icon ?? 'database');
$magMigTitle = (string) ($title ?? 'Dados analiticos nao preparados');
$magMigMessage = (string) ($message ?? 'Os dados analiticos ainda nao foram preparados. Execute as migrations pendentes.');
$magMigActionLabel = (string) ($actionLabel ?? 'Ver migrations');
$magMigActionHref = (string) ($actionHref ?? 'admin.php?tab=migrations');
$magMigCompact = (bool) ($compact ?? false);
$magCompactClass = $magMigCompact ? ' mag-state-compact' : '';
?>
<div class="mag-state mag-state-warning<?php echo $magCompactClass; ?>" role="status">
  <span class="material-symbols-rounded mag-state-icon"><?php echo htmlspecialchars($magMigIcon, ENT_QUOTES, 'UTF-8'); ?></span>
  <strong class="mag-state-title"><?php echo htmlspecialchars($magMigTitle, ENT_QUOTES, 'UTF-8'); ?></strong>
  <p class="mag-state-message"><?php echo htmlspecialchars($magMigMessage, ENT_QUOTES, 'UTF-8'); ?></p>
  <?php if ($magMigActionLabel !== '' && $magMigActionHref !== ''): ?>
    <div class="mag-state-actions">
      <a class="btn-secondary mag-button-secondary" href="<?php echo htmlspecialchars($magMigActionHref, ENT_QUOTES, 'UTF-8'); ?>">
        <span class="material-symbols-rounded" style="font-size:16px">play_arrow</span>
        <?php echo htmlspecialchars($magMigActionLabel, ENT_QUOTES, 'UTF-8'); ?>
      </a>
    </div>
  <?php endif; ?>
</div>

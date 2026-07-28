<?php

declare(strict_types=1);

$magSyncIcon = (string) ($icon ?? 'cloud_off');
$magSyncTitle = (string) ($title ?? 'Sincronizacao pendente');
$magSyncMessage = (string) ($message ?? 'Os dados ainda nao foram sincronizados com o Omie.');
$magSyncActionLabel = (string) ($actionLabel ?? 'Ver Conexao Omie');
$magSyncActionHref = (string) ($actionHref ?? 'integracoes.php');
$magSyncCompact = (bool) ($compact ?? false);
$magCompactClass = $magSyncCompact ? ' mag-state-compact' : '';
?>
<div class="mag-state mag-state-warning<?php echo $magCompactClass; ?>" role="status">
  <span class="material-symbols-rounded mag-state-icon"><?php echo htmlspecialchars($magSyncIcon, ENT_QUOTES, 'UTF-8'); ?></span>
  <strong class="mag-state-title"><?php echo htmlspecialchars($magSyncTitle, ENT_QUOTES, 'UTF-8'); ?></strong>
  <p class="mag-state-message"><?php echo htmlspecialchars($magSyncMessage, ENT_QUOTES, 'UTF-8'); ?></p>
  <?php if ($magSyncActionLabel !== '' && $magSyncActionHref !== ''): ?>
    <div class="mag-state-actions">
      <a class="btn-secondary mag-button-secondary" href="<?php echo htmlspecialchars($magSyncActionHref, ENT_QUOTES, 'UTF-8'); ?>">
        <span class="material-symbols-rounded" style="font-size:16px">cloud_sync</span>
        <?php echo htmlspecialchars($magSyncActionLabel, ENT_QUOTES, 'UTF-8'); ?>
      </a>
    </div>
  <?php endif; ?>
</div>

<?php

declare(strict_types=1);

$magPermIcon = (string) ($icon ?? 'lock');
$magPermTitle = (string) ($title ?? 'Acesso restrito');
$magPermMessage = (string) ($message ?? 'Voce nao tem permissao para visualizar esta informacao.');
$magPermCompact = (bool) ($compact ?? false);
$magCompactClass = $magPermCompact ? ' mag-state-compact' : '';
?>
<div class="mag-state mag-state-permission<?php echo $magCompactClass; ?>" role="status">
  <span class="material-symbols-rounded mag-state-icon"><?php echo htmlspecialchars($magPermIcon, ENT_QUOTES, 'UTF-8'); ?></span>
  <strong class="mag-state-title"><?php echo htmlspecialchars($magPermTitle, ENT_QUOTES, 'UTF-8'); ?></strong>
  <p class="mag-state-message"><?php echo htmlspecialchars($magPermMessage, ENT_QUOTES, 'UTF-8'); ?></p>
</div>

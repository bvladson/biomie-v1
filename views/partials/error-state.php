<?php

declare(strict_types=1);

$magErrorIcon = (string) ($icon ?? 'error_outline');
$magErrorTitle = (string) ($title ?? 'Erro ao carregar');
$magErrorMessage = (string) ($message ?? 'Nao foi possivel carregar esta informacao agora.');
$magErrorActionLabel = (string) ($actionLabel ?? '');
$magErrorActionHref = (string) ($actionHref ?? '');
$magErrorCompact = (bool) ($compact ?? false);
$magErrorDiagnostic = (string) ($diagnostic ?? '');
$magCompactClass = $magErrorCompact ? ' mag-state-compact' : '';
?>
<div class="mag-state mag-state-error mag-state-severity--danger<?php echo $magCompactClass; ?>" role="alert">
  <span class="material-symbols-rounded mag-state-icon"><?php echo htmlspecialchars($magErrorIcon, ENT_QUOTES, 'UTF-8'); ?></span>
  <strong class="mag-state-title"><?php echo htmlspecialchars($magErrorTitle, ENT_QUOTES, 'UTF-8'); ?></strong>
  <p class="mag-state-message"><?php echo htmlspecialchars($magErrorMessage, ENT_QUOTES, 'UTF-8'); ?></p>
  <?php if ($magErrorActionLabel !== '' && $magErrorActionHref !== ''): ?>
    <div class="mag-state-actions">
      <a class="btn-secondary mag-button-secondary" href="<?php echo htmlspecialchars($magErrorActionHref, ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($magErrorActionLabel, ENT_QUOTES, 'UTF-8'); ?>
      </a>
    </div>
  <?php endif; ?>
  <?php if ($magErrorDiagnostic !== ''): ?>
    <details class="mag-state-details">
      <summary>Detalhes tecnicos</summary>
      <pre><?php echo htmlspecialchars($magErrorDiagnostic, ENT_QUOTES, 'UTF-8'); ?></pre>
    </details>
  <?php endif; ?>
</div>

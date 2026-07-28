<?php

declare(strict_types=1);

$magEmptyIcon = (string) ($icon ?? 'search_off');
$magEmptyTitle = (string) ($title ?? 'Sem dados');
$magEmptyMessage = (string) ($message ?? 'Nao encontramos dados para o filtro atual.');
$magEmptyActionLabel = (string) ($actionLabel ?? '');
$magEmptyActionHref = (string) ($actionHref ?? '');
$magEmptyVariant = (string) ($variant ?? 'neutral');
$magEmptyCompact = (bool) ($compact ?? false);
$magEmptyDiagnostic = (string) ($diagnostic ?? '');
$magEmptySeverity = (string) ($severity ?? 'info');
$magCompactClass = $magEmptyCompact ? ' mag-state-compact' : '';
?>
<div class="mag-state mag-state-empty mag-state--<?php echo htmlspecialchars($magEmptyVariant, ENT_QUOTES, 'UTF-8'); ?> mag-state-severity--<?php echo htmlspecialchars($magEmptySeverity, ENT_QUOTES, 'UTF-8'); ?><?php echo $magCompactClass; ?>" role="status">
  <span class="material-symbols-rounded mag-state-icon"><?php echo htmlspecialchars($magEmptyIcon, ENT_QUOTES, 'UTF-8'); ?></span>
  <strong class="mag-state-title"><?php echo htmlspecialchars($magEmptyTitle, ENT_QUOTES, 'UTF-8'); ?></strong>
  <p class="mag-state-message"><?php echo htmlspecialchars($magEmptyMessage, ENT_QUOTES, 'UTF-8'); ?></p>
  <?php if ($magEmptyActionLabel !== '' && $magEmptyActionHref !== ''): ?>
    <div class="mag-state-actions">
      <a class="btn-secondary mag-button-secondary" href="<?php echo htmlspecialchars($magEmptyActionHref, ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($magEmptyActionLabel, ENT_QUOTES, 'UTF-8'); ?>
      </a>
    </div>
  <?php endif; ?>
  <?php if ($magEmptyDiagnostic !== ''): ?>
    <details class="mag-state-details">
      <summary>Detalhes tecnicos</summary>
      <pre><?php echo htmlspecialchars($magEmptyDiagnostic, ENT_QUOTES, 'UTF-8'); ?></pre>
    </details>
  <?php endif; ?>
</div>

<?php

declare(strict_types=1);

$magLoadingMessage = (string) ($message ?? 'Carregando dados...');
$magLoadingCompact = (bool) ($compact ?? false);
$magLoadingRows = (int) ($rows ?? 3);
$magLoadingType = (string) ($type ?? 'lines'); // lines|card|table
$magCompactClass = $magLoadingCompact ? ' mag-state-compact' : '';
?>
<div class="mag-state mag-state-loading<?php echo $magCompactClass; ?>" role="status" aria-live="polite" aria-busy="true">
  <div class="mag-loading-content">
    <div class="mag-loading-spinner"></div>
    <p class="mag-state-message"><?php echo htmlspecialchars($magLoadingMessage, ENT_QUOTES, 'UTF-8'); ?></p>
  </div>
  <?php if ($magLoadingType === 'table'): ?>
    <div class="mag-skeleton mag-skeleton-table">
      <?php for ($i = 0; $i < $magLoadingRows; $i++): ?>
        <div class="mag-skeleton-row">
          <span class="mag-skeleton-line" style="width:<?php echo 50 + ($i * 7) % 30; ?>%"></span>
          <span class="mag-skeleton-line" style="width:<?php echo 18 + ($i * 5) % 12; ?>%"></span>
        </div>
      <?php endfor; ?>
    </div>
  <?php elseif ($magLoadingType === 'card'): ?>
    <div class="mag-skeleton mag-skeleton-card">
      <span class="mag-skeleton-line" style="width:60%"></span>
      <span class="mag-skeleton-line" style="width:40%"></span>
    </div>
  <?php else: ?>
    <div class="mag-skeleton mag-skeleton-lines">
      <?php for ($i = 0; $i < $magLoadingRows; $i++): ?>
        <span class="mag-skeleton-line" style="width:<?php echo 60 + ($i * 11) % 30; ?>%"></span>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</div>

<?php

declare(strict_types=1);

$magDiagId = (string) ($id ?? ('magDiagnostics' . random_int(1000, 9999)));
$magDiagTitle = (string) ($title ?? 'Diagnostico tecnico');
$magDiagSummary = (string) ($summary ?? 'Detalhes tecnicos');
$magDiagItems = is_array($items ?? null) ? $items : [];
?>
<details class="mag-technical-diagnostics" id="<?php echo htmlspecialchars($magDiagId, ENT_QUOTES, 'UTF-8'); ?>">
  <summary aria-controls="<?php echo htmlspecialchars($magDiagId, ENT_QUOTES, 'UTF-8'); ?>Content" aria-expanded="false">
    <span class="material-symbols-rounded">tune</span>
    <?php echo htmlspecialchars($magDiagSummary, ENT_QUOTES, 'UTF-8'); ?>
  </summary>
  <div class="mag-technical-diagnostics__body" id="<?php echo htmlspecialchars($magDiagId, ENT_QUOTES, 'UTF-8'); ?>Content">
    <h2><?php echo htmlspecialchars($magDiagTitle, ENT_QUOTES, 'UTF-8'); ?></h2>
    <?php if ($magDiagItems === []): ?>
      <p>Sem diagnostico tecnico adicional para exibir.</p>
    <?php else: ?>
      <div class="mag-technical-diagnostics__grid">
        <?php foreach ($magDiagItems as $magLabel => $magValue): ?>
          <span><?php echo htmlspecialchars((string) $magLabel, ENT_QUOTES, 'UTF-8'); ?></span>
          <strong><?php echo htmlspecialchars((string) $magValue, ENT_QUOTES, 'UTF-8'); ?></strong>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</details>

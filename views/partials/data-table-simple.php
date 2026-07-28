<?php

declare(strict_types=1);

$magTableTitle = (string) ($title ?? 'Tabela');
$magTableDescription = (string) ($description ?? '');
$magTableColumns = is_array($columns ?? null) ? $columns : [];
$magTableRows = is_array($rows ?? null) ? $rows : [];
$magTableSearch = (bool) ($search ?? false);
$magTableCompact = (bool) ($compact ?? false);
$magTableMaxRows = (int) ($maxRows ?? 0);
$magTableEmptyTitle = (string) ($emptyTitle ?? 'Sem dados');
$magTableEmptyMessage = (string) ($emptyMessage ?? 'Ajuste os filtros ou atualize a leitura.');
$magTableEmptyIcon = (string) ($emptyIcon ?? 'search_off');
$magTableError = (string) ($error ?? '');
$magTableResponsive = (bool) ($responsive ?? true);
$magTableViewAllLabel = (string) ($viewAllLabel ?? '');
$magTableViewAllHref = (string) ($viewAllHref ?? '');
$magTableId = (string) ($tableId ?? '');
$h = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$hasRows = $magTableRows !== [];
$totalRows = count($magTableRows);
$isTruncated = $magTableMaxRows > 0 && $totalRows > $magTableMaxRows;
$displayRows = $isTruncated ? array_slice($magTableRows, 0, $magTableMaxRows) : $magTableRows;
$compactClass = $magTableCompact ? ' mag-table-compact' : '';
?>
<section class="mag-data-table-simple">
  <div class="mag-data-table-simple__head">
    <div>
      <h2><?php echo $h($magTableTitle); ?></h2>
      <?php if ($magTableDescription !== ''): ?>
        <p><?php echo $h($magTableDescription); ?></p>
      <?php endif; ?>
    </div>
    <?php if ($magTableSearch): ?>
      <input class="filter-input" type="search" placeholder="Buscar" />
    <?php endif; ?>
  </div>

  <?php if ($magTableError !== ''): ?>
    <div class="mag-state mag-state-error mag-state-compact mag-state-severity--danger" role="alert">
      <span class="material-symbols-rounded mag-state-icon">error_outline</span>
      <strong class="mag-state-title">Erro ao carregar</strong>
      <p class="mag-state-message"><?php echo $h($magTableError); ?></p>
    </div>
  <?php elseif (!$hasRows): ?>
    <div class="mag-state mag-state-empty mag-state-compact mag-state--neutral mag-state-severity--info" role="status">
      <span class="material-symbols-rounded mag-state-icon"><?php echo $h($magTableEmptyIcon); ?></span>
      <strong class="mag-state-title"><?php echo $h($magTableEmptyTitle); ?></strong>
      <p class="mag-state-message"><?php echo $h($magTableEmptyMessage); ?></p>
    </div>
  <?php else: ?>
    <div class="<?php echo $magTableResponsive ? 'mag-table-scroll' : ''; ?>">
      <table class="mag-table mag-table-clean<?php echo $compactClass; ?>"<?php echo $magTableId !== '' ? ' id="' . $h($magTableId) . '"' : ''; ?>>
        <thead>
          <tr>
            <?php foreach ($magTableColumns as $magColumn): ?>
              <?php
                $colLabel = is_array($magColumn) ? ($magColumn['label'] ?? '') : (string) $magColumn;
                $colAlign = is_array($magColumn) ? ($magColumn['align'] ?? 'left') : 'left';
              ?>
              <th<?php echo $colAlign !== 'left' ? ' style="text-align:' . $h($colAlign) . '"' : ''; ?>><?php echo $h($colLabel); ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody data-empty-title="<?php echo $h($magTableEmptyTitle); ?>" data-empty-message="<?php echo $h($magTableEmptyMessage); ?>" data-empty-icon="<?php echo $h($magTableEmptyIcon); ?>">
          <?php foreach ($displayRows as $magRow): ?>
            <tr>
              <?php $colIdx = 0; foreach ($magRow as $magCell): ?>
                <?php
                  $cellAlign = 'left';
                  if (isset($magTableColumns[$colIdx]) && is_array($magTableColumns[$colIdx])) {
                      $cellAlign = (string) ($magTableColumns[$colIdx]['align'] ?? 'left');
                  }
                  $cellValue = $magCell === null || $magCell === '' ? "\xE2\x80\x94" : (string) $magCell;
                  $colIdx++;
                ?>
                <td<?php echo $cellAlign !== 'left' ? ' style="text-align:' . $h($cellAlign) . '"' : ''; ?>><?php echo $h($cellValue); ?></td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($isTruncated || ($magTableViewAllLabel !== '' && $magTableViewAllHref !== '')): ?>
      <div class="mag-table-footer">
        <?php if ($isTruncated): ?>
          <span class="mag-table-footer__info">Mostrando <?php echo count($displayRows); ?> de <?php echo $totalRows; ?></span>
        <?php endif; ?>
        <?php if ($magTableViewAllLabel !== '' && $magTableViewAllHref !== ''): ?>
          <a class="mag-table-footer__link" href="<?php echo $h($magTableViewAllHref); ?>">
            <?php echo $h($magTableViewAllLabel); ?> <span class="material-symbols-rounded" style="font-size:16px">arrow_forward</span>
          </a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</section>

<?php
/** Template: tela cheia da Central de Notificações. Server-rendered. */
declare(strict_types=1);

/** @var list<array<string,mixed>> $notif_items */
$items = is_array($notif_items ?? null) ? $notif_items : [];
/** @var array<int,string> $notif_company_labels */
$labels = is_array($notif_company_labels ?? null) ? $notif_company_labels : [];

$catIcon = [
    'sync' => 'sync', 'webhook' => 'cloud_sync', 'business' => 'trending_up',
    'security' => 'shield', 'system' => 'settings',
];
$timeAgo = static function (?string $iso): string {
    if (!$iso) {
        return '';
    }
    $ts = strtotime($iso);
    if ($ts === false) {
        return '';
    }
    $s = max(0, time() - $ts);
    return match (true) {
        $s < 60      => 'agora',
        $s < 3600    => intdiv($s, 60) . ' min',
        $s < 86400   => intdiv($s, 3600) . ' h',
        $s < 604800  => intdiv($s, 86400) . ' d',
        default      => date('d/m/Y', $ts),
    };
};
$unread = 0;
foreach ($items as $it) {
    if (empty($it['is_read'])) {
        $unread++;
    }
}

// ── AGRUPAMENTO DE REPETIDAS ────────────────────────────────────────────────
// O mesmo fato chega várias vezes (ex.: "Novo cliente: MARCOS AURELIO" emitido
// por integrações diferentes). Repetir a linha empurra o que importa para baixo
// e faz a central parecer barulhenta. Agrupa por (título + corpo) DENTRO da
// mesma categoria e guarda quantas vezes ocorreu + a mais recente.
// Só agrupa REPETIÇÃO EXATA — nunca junta fatos diferentes, que seria esconder
// informação em vez de organizá-la.
$grouped = [];
foreach ($items as $it) {
    $key = mb_strtolower(trim((string) ($it['title'] ?? '')))
        . '|' . mb_strtolower(trim((string) ($it['body'] ?? '')))
        . '|' . (string) ($it['category'] ?? '');
    if (!isset($grouped[$key])) {
        $it['_count'] = 1;
        $it['_ids'] = [(int) ($it['id'] ?? 0)];
        $grouped[$key] = $it;
        continue;
    }
    $grouped[$key]['_count']++;
    $grouped[$key]['_ids'][] = (int) ($it['id'] ?? 0);
    // Uma repetição não-lida mantém o grupo inteiro como não-lido.
    if (empty($it['is_read'])) {
        $grouped[$key]['is_read'] = 0;
    }
}
$items = array_values($grouped);

// Contadores por categoria — alimentam os filtros do topo.
$counts = ['all' => count($items), 'unread' => 0];
foreach ($items as $it) {
    $cat = (string) ($it['category'] ?? 'system');
    $counts[$cat] = ($counts[$cat] ?? 0) + 1;
    if (empty($it['is_read'])) {
        $counts['unread']++;
    }
}
$catLabels = [
    'business' => 'Negócio', 'sync' => 'Sincronização', 'webhook' => 'Webhooks',
    'security' => 'Segurança', 'system' => 'Sistema',
];
?>
<section class="mag-page biomie-notif-page" data-notif-page>
  <div class="mag-page-head" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
    <div>
      <h1 style="margin:0;">Central de Notificações</h1>
      <p class="mag-muted" style="margin:4px 0 0;">Sync, webhooks, negócio e segurança — só das empresas que você acessa.<?php echo $unread > 0 ? ' <strong>' . $unread . ' não lida(s).</strong>' : ''; ?></p>
    </div>
    <button type="button" class="btn-secondary" data-notif-page-markall>
      <span class="material-symbols-rounded" aria-hidden="true">done_all</span> Marcar todas como lidas
    </button>
  </div>

  <!-- FILTROS: categoria + só não lidas + busca. Tudo client-side sobre a lista
       já renderizada — sem ida ao servidor a cada clique. -->
  <div class="biomie-notif-filters" data-notif-filters>
    <div class="biomie-notif-filters__chips" role="group" aria-label="Filtrar por tipo">
      <button type="button" class="biomie-notif-chip is-active" data-notif-cat="all">
        Todas <span class="biomie-notif-chip__count"><?php echo (int) $counts['all']; ?></span>
      </button>
      <?php foreach ($catLabels as $catKey => $catLabel):
          if (empty($counts[$catKey])) { continue; } ?>
        <button type="button" class="biomie-notif-chip" data-notif-cat="<?php echo htmlspecialchars($catKey, ENT_QUOTES, 'UTF-8'); ?>">
          <?php echo htmlspecialchars($catLabel, ENT_QUOTES, 'UTF-8'); ?>
          <span class="biomie-notif-chip__count"><?php echo (int) $counts[$catKey]; ?></span>
        </button>
      <?php endforeach; ?>
      <button type="button" class="biomie-notif-chip biomie-notif-chip--unread" data-notif-unread>
        Só não lidas <span class="biomie-notif-chip__count"><?php echo (int) $counts['unread']; ?></span>
      </button>
    </div>
    <label class="biomie-notif-search">
      <span class="material-symbols-rounded" aria-hidden="true">search</span>
      <input type="search" data-notif-search placeholder="Buscar por cliente, pedido, NF…" autocomplete="off" aria-label="Buscar nas notificações" />
    </label>
  </div>

  <div class="biomie-notif__list" style="margin-top:16px;" data-notif-list>
    <?php if ($items === []): ?>
      <div class="biomie-notif__state">Nenhuma notificação ainda. ✨</div>
    <?php else: ?>
      <?php foreach ($items as $it):
          $cid = $it['company_id'] ?? null;
          $companyLabel = $cid !== null ? ($labels[(int) $cid] ?? '') : '';
          $cat = (string) ($it['category'] ?? 'system');
          $icon = $catIcon[$cat] ?? 'notifications';
          $count = (int) ($it['_count'] ?? 1);
          $title = (string) ($it['title'] ?? '');
          $body = (string) ($it['body'] ?? '');
          // Base de busca: o que o usuário digitaria (cliente, nº de pedido/NF).
          $haystack = mb_strtolower($title . ' ' . $body . ' ' . $companyLabel);
      ?>
      <div
        class="biomie-notif__item<?php echo empty($it['is_read']) ? ' is-unread' : ''; ?>"
        data-severity="<?php echo htmlspecialchars((string) ($it['severity'] ?? 'info'), ENT_QUOTES, 'UTF-8'); ?>"
        data-id="<?php echo (int) ($it['id'] ?? 0); ?>"
        data-ids="<?php echo htmlspecialchars(implode(',', $it['_ids'] ?? []), ENT_QUOTES, 'UTF-8'); ?>"
        data-cat="<?php echo htmlspecialchars($cat, ENT_QUOTES, 'UTF-8'); ?>"
        data-search="<?php echo htmlspecialchars($haystack, ENT_QUOTES, 'UTF-8'); ?>"
      >
        <span class="biomie-notif__icon"><span class="material-symbols-rounded" aria-hidden="true"><?php echo $icon; ?></span></span>
        <span class="biomie-notif__body">
          <span class="biomie-notif__title">
            <?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>
            <?php if ($count > 1): ?>
              <span class="biomie-notif__repeat" title="Este mesmo aviso chegou <?php echo $count; ?> vezes">&times;<?php echo $count; ?></span>
            <?php endif; ?>
          </span>
          <?php if ($body !== ''): ?>
            <span class="biomie-notif__text"><?php echo htmlspecialchars($body, ENT_QUOTES, 'UTF-8'); ?></span>
          <?php endif; ?>
          <span class="biomie-notif__meta">
            <?php if ($companyLabel !== ''): ?><span class="biomie-notif__company"><?php echo htmlspecialchars($companyLabel, ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
            <span><?php echo htmlspecialchars($timeAgo((string) ($it['created_at'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></span>
          </span>
        </span>
        <span class="biomie-notif__actions">
          <?php if (!empty($it['link'])): ?>
            <a class="biomie-notif__open" href="<?php echo htmlspecialchars((string) $it['link'], ENT_QUOTES, 'UTF-8'); ?>">
              Abrir <span class="material-symbols-rounded" aria-hidden="true">chevron_right</span>
            </a>
          <?php endif; ?>
          <?php if (empty($it['is_read'])): ?>
            <button type="button" class="biomie-notif__read" data-notif-read title="Marcar como lida">
              <span class="material-symbols-rounded" aria-hidden="true">check</span>
            </button>
          <?php endif; ?>
        </span>
      </div>
      <?php endforeach; ?>
      <div class="biomie-notif__state" data-notif-empty hidden>Nenhuma notificação para este filtro.</div>
    <?php endif; ?>
  </div>
</section>

<script>
(function () {
  function csrf() { var m = document.querySelector('meta[name="biomie-csrf"]'); return m ? (m.getAttribute('content') || '') : ''; }
  var page = document.querySelector('[data-notif-page]');
  if (!page) return;
  // ── Marcar como lida ─────────────────────────────────────────────────────
  // Um grupo pode representar várias notificações (repetidas): marca TODAS as
  // que ele agrega, senão o contador do sininho ficaria diferente da tela.
  function markRead(el) {
    var ids = String(el.getAttribute('data-ids') || el.getAttribute('data-id') || '')
      .split(',').map(function (s) { return parseInt(s, 10); }).filter(Boolean);
    if (!ids.length || !el.classList.contains('is-unread')) return;
    el.classList.remove('is-unread');
    var btn = el.querySelector('[data-notif-read]');
    if (btn) btn.remove();
    ids.forEach(function (id) {
      fetch('api/notifications.php', { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'read', id: id, csrf_token: csrf() }) });
    });
    applyFilters();
  }

  page.querySelectorAll('.biomie-notif__item').forEach(function (el) {
    // Clique no botão de check marca sem navegar; clique no corpo também marca.
    var readBtn = el.querySelector('[data-notif-read]');
    if (readBtn) {
      readBtn.addEventListener('click', function (ev) {
        ev.preventDefault(); ev.stopPropagation();
        markRead(el);
      });
    }
    el.addEventListener('click', function (ev) {
      // "Abrir" é um link: deixa navegar, só marca como lida.
      if (ev.target.closest && ev.target.closest('[data-notif-read]')) return;
      markRead(el);
    });
  });

  // ── Filtros (categoria + não lidas + busca) ──────────────────────────────
  var state = { cat: 'all', unreadOnly: false, term: '' };
  var listEl = page.querySelector('[data-notif-list]');
  var emptyEl = page.querySelector('[data-notif-empty]');

  function applyFilters() {
    if (!listEl) return;
    var visible = 0;
    listEl.querySelectorAll('.biomie-notif__item').forEach(function (el) {
      var okCat = state.cat === 'all' || el.getAttribute('data-cat') === state.cat;
      var okUnread = !state.unreadOnly || el.classList.contains('is-unread');
      var okTerm = !state.term
        || (el.getAttribute('data-search') || '').indexOf(state.term) !== -1;
      var show = okCat && okUnread && okTerm;
      el.hidden = !show;
      if (show) visible++;
    });
    if (emptyEl) emptyEl.hidden = visible > 0;
  }

  page.querySelectorAll('[data-notif-cat]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      state.cat = btn.getAttribute('data-notif-cat') || 'all';
      page.querySelectorAll('[data-notif-cat]').forEach(function (b) {
        b.classList.toggle('is-active', b === btn);
      });
      applyFilters();
    });
  });
  var unreadBtn = page.querySelector('[data-notif-unread]');
  if (unreadBtn) unreadBtn.addEventListener('click', function () {
    state.unreadOnly = !state.unreadOnly;
    unreadBtn.classList.toggle('is-active', state.unreadOnly);
    applyFilters();
  });
  var searchEl = page.querySelector('[data-notif-search]');
  if (searchEl) searchEl.addEventListener('input', function () {
    state.term = String(searchEl.value || '').toLowerCase().trim();
    applyFilters();
  });
  var mk = page.querySelector('[data-notif-page-markall]');
  if (mk) mk.addEventListener('click', function () {
    fetch('api/notifications.php', { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'read_all', csrf_token: csrf() }) })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok) return;
        page.querySelectorAll('.is-unread').forEach(function (e) {
          e.classList.remove('is-unread');
          var b = e.querySelector('[data-notif-read]');
          if (b) b.remove();
        });
        applyFilters();   // o filtro "só não lidas" precisa refletir a mudança
      });
  });
})();
</script>

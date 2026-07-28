        <section class="admin-section <?php echo $activeTab === 'engine-health' ? 'active' : ''; ?>" data-admin-tab-panel="engine-health">
          <div class="admin-panel">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:16px">
              <div>
                <h2 style="margin:0"><span class="material-symbols-rounded">cardiology</span> Sa&uacute;de do Motor</h2>
                <p class="admin-small" style="margin-top:4px">Integra&ccedil;&atilde;o Omie, webhooks, fila (inbox) e frescor de sync &mdash; por empresa, num s&oacute; lugar.</p>
              </div>
              <button type="button" class="btn-secondary" data-eng-refresh><span class="material-symbols-rounded">refresh</span> Atualizar</button>
            </div>
            <div data-eng-quota class="eng-quota" style="display:none"></div>
            <div data-eng-grid style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:14px">
              <div class="admin-small" style="color:var(--mag-muted,#64748b);padding:20px">Carregando&hellip;</div>
            </div>
          </div>
        </section>

        <style>
          .eng-card{border:1px solid var(--mag-border,#e5e7eb);border-radius:14px;background:var(--mag-surface,#fff);padding:14px;display:flex;flex-direction:column;gap:10px}
          .eng-card__head{display:flex;align-items:center;justify-content:space-between;gap:8px}
          .eng-card__name{font-weight:800;font-size:.95rem;color:var(--mag-text,#0f172a)}
          .eng-badge{font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em;padding:3px 9px;border-radius:999px}
          .eng-badge.ok{background:#dcfce7;color:#16a34a}.eng-badge.warning{background:#fef3c7;color:#b45309}
          .eng-badge.critical{background:#fee2e2;color:#dc2626}.eng-badge.not_configured{background:#e2e8f0;color:#475569}
          .eng-row{display:flex;align-items:center;gap:8px;font-size:.8rem;color:var(--mag-text,#334155)}
          .eng-row .material-symbols-rounded{font-size:17px;color:var(--mag-muted,#64748b)}
          .eng-row b{font-weight:700}
          .eng-dot{width:8px;height:8px;border-radius:999px;flex:0 0 auto}
          .eng-dot.g{background:#16a34a}.eng-dot.y{background:#d97706}.eng-dot.r{background:#dc2626}.eng-dot.n{background:#94a3b8}
          .eng-sub{font-size:.72rem;color:var(--mag-muted,#64748b)}
          .eng-quota{border:1px solid var(--mag-border,#e5e7eb);border-radius:14px;background:var(--mag-surface,#fff);padding:14px;margin-bottom:14px}
          .eng-quota__top{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:8px}
          .eng-quota__title{font-weight:800;font-size:.9rem;color:var(--mag-text,#0f172a);display:flex;align-items:center;gap:7px}
          .eng-quota__title .material-symbols-rounded{font-size:19px;color:var(--mag-muted,#64748b)}
          .eng-quota__val{font-size:.8rem;color:var(--mag-text,#334155);font-weight:700}
          .eng-quota__bar{height:9px;border-radius:999px;background:#eef2f7;overflow:hidden}
          .eng-quota__fill{height:100%;border-radius:999px;background:#16a34a;transition:width .4s}
          .eng-quota__fill.y{background:#d97706}.eng-quota__fill.r{background:#dc2626}
          .eng-quota__pill{font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em;padding:3px 9px;border-radius:999px}
          .eng-quota__pill.ok{background:#dcfce7;color:#16a34a}.eng-quota__pill.cool{background:#fee2e2;color:#dc2626}
        </style>

        <script>
        (function () {
          var root = document.querySelector('[data-admin-tab-panel="engine-health"]');
          if (!root) return;
          var grid = root.querySelector('[data-eng-grid]');
          var quota = root.querySelector('[data-eng-quota]');
          var btn  = root.querySelector('[data-eng-refresh]');
          var timer = null;

          function ageLabel(min) {
            if (min == null) return '—';
            if (min < 60) return min + ' min';
            if (min < 1440) return Math.floor(min / 60) + ' h';
            return Math.floor(min / 1440) + ' d';
          }
          function ageDot(min, warn, crit) {
            if (min == null) return 'n';
            if (min > crit) return 'r';
            if (min > warn) return 'y';
            return 'g';
          }
          function fmtDt(s) { if (!s) return '—'; var d = new Date(s.replace(' ', 'T')); return isNaN(d) ? s : d.toLocaleString('pt-BR'); }
          function esc(t){var d=document.createElement('div');d.textContent=t==null?'':String(t);return d.innerHTML;}

          function card(c) {
            var i = c.integration, w = c.webhook, s = c.sync;
            var intLine = i.configured
              ? '<span class="eng-dot ' + (i.last_error_at ? 'y' : 'g') + '"></span> Integra&ccedil;&atilde;o ativa'
              : '<span class="eng-dot r"></span> N&atilde;o configurada';
            return '' +
              '<div class="eng-card">' +
                '<div class="eng-card__head"><span class="eng-card__name">' + esc(c.name) + '</span>' +
                  '<span class="eng-badge ' + c.status + '">' + c.status.replace('_', ' ') + '</span></div>' +
                '<div class="eng-row"><span class="material-symbols-rounded">link</span>' + intLine + '</div>' +
                (i.last_error ? '<div class="eng-sub" style="color:#dc2626">Erro: ' + esc(i.last_error) + '</div>' : '') +
                '<div class="eng-row"><span class="material-symbols-rounded">cloud_sync</span>Webhook: ' +
                  '<span class="eng-dot ' + ageDot(w.last_received_age_min, 360, 1440) + '"></span> <b>' + ageLabel(w.last_received_age_min) + '</b>' +
                  (w.inbox_failed > 0 ? ' · <span style="color:#dc2626">' + w.inbox_failed + ' falhas</span>' : '') +
                  (w.inbox_pending > 0 ? ' · ' + w.inbox_pending + ' na fila' : '') + '</div>' +
                '<div class="eng-sub">Processados 24h: ' + w.processed_24h + '</div>' +
                '<div class="eng-row"><span class="material-symbols-rounded">sync</span>Sync: ' +
                  'Com <span class="eng-dot ' + ageDot(s.commercial_age_min, 60, 1440) + '"></span> ' + ageLabel(s.commercial_age_min) + ' · ' +
                  'Fin <span class="eng-dot ' + ageDot(s.finance_age_min, 90, 2880) + '"></span> ' + ageLabel(s.finance_age_min) + ' · ' +
                  'Gold <span class="eng-dot ' + ageDot(s.gold_age_min, 90, 1440) + '"></span> ' + ageLabel(s.gold_age_min) + '</div>' +
                (s.fails_24h > 0 ? '<div class="eng-sub" style="color:#b45309">' + s.fails_24h + ' falha(s) de sync nas últimas 24h</div>' : '') +
                '<div class="eng-sub">Último sucesso Omie: ' + fmtDt(i.last_success_at) + '</div>' +
              '</div>';
          }

          function renderQuota(q) {
            if (!quota) return;
            if (!q || !q.per_minute_ceiling) { quota.style.display = 'none'; return; }
            var pct = Math.max(0, Math.min(100, q.utilization_pct || 0));
            var fillCls = pct >= 90 ? 'r' : (pct >= 70 ? 'y' : '');
            var cooling = (q.cooldown_remaining_ms || 0) > 0;
            var coolSecs = Math.ceil((q.cooldown_remaining_ms || 0) / 1000);
            quota.style.display = 'block';
            quota.innerHTML =
              '<div class="eng-quota__top">' +
                '<span class="eng-quota__title"><span class="material-symbols-rounded">speed</span> Quota Omie global (todas as empresas)</span>' +
                (cooling
                  ? '<span class="eng-quota__pill cool">recuo global ' + coolSecs + 's</span>'
                  : '<span class="eng-quota__pill ok">' + (q.propagates_cooldown ? 'coordenado' : 'ativo') + '</span>') +
              '</div>' +
              '<div class="eng-quota__bar"><div class="eng-quota__fill ' + fillCls + '" style="width:' + pct + '%"></div></div>' +
              '<div class="eng-quota__top" style="margin:8px 0 0">' +
                '<span class="eng-quota__val">' + (q.used_last_minute || 0) + ' / ' + q.per_minute_ceiling + ' chamadas no &uacute;ltimo minuto</span>' +
                '<span class="eng-sub">' + pct + '% do teto' + (cooling && q.cooldown_reason ? ' &middot; ' + esc(q.cooldown_reason) : '') + '</span>' +
              '</div>';
          }

          function load() {
            fetch('api/engine_health.php', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
              .then(function (r) { return r.json(); })
              .then(function (d) {
                if (!d || !d.ok) { grid.innerHTML = '<div style="padding:20px">Sem permiss&atilde;o ou falha.</div>'; return; }
                renderQuota(d.global_quota);
                grid.innerHTML = d.companies.map(card).join('') || '<div style="padding:20px">Nenhuma empresa.</div>';
              })
              .catch(function () { grid.innerHTML = '<div style="padding:20px">Falha ao carregar.</div>'; });
          }

          if (btn) btn.addEventListener('click', load);
          load();
          // auto-refresh a cada 60s enquanto a aba estiver visível
          timer = setInterval(function () {
            var sec = document.querySelector('[data-admin-tab-panel="engine-health"]');
            if (sec && sec.classList.contains('active') && !document.hidden) load();
          }, 60000);
        })();
        </script>

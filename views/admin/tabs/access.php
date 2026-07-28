        <section class="admin-section <?php echo $activeTab === 'access' ? 'active' : ''; ?>" data-admin-tab-panel="access">
          <div class="admin-panel">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:16px">
              <div>
                <h2 style="margin:0"><span class="material-symbols-rounded">admin_panel_settings</span> Acessos &amp; Empresas</h2>
                <p class="admin-small" style="margin-top:4px">Defina quais empresas cada usu&aacute;rio acessa, os canais (Varejo/Licita&ccedil;&atilde;o), o papel por empresa e a empresa padr&atilde;o. O isolamento de dados respeita exatamente esta matriz.</p>
              </div>
            </div>

            <!-- Seletor de usuário + resumo -->
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px">
              <label for="accUser" class="admin-small" style="font-weight:700">Usu&aacute;rio:</label>
              <select id="accUser" class="admin-input" style="min-width:260px"></select>
              <span class="acc-summary" data-acc-summary></span>
              <span class="admin-small" data-acc-hint style="color:var(--mag-muted,#64748b)"></span>
            </div>

            <!-- Ações em massa -->
            <div class="acc-toolbar" data-acc-toolbar>
              <button type="button" class="btn-secondary btn-sm" data-acc-all>
                <span class="material-symbols-rounded">done_all</span>Dar acesso a todas
              </button>
              <button type="button" class="btn-secondary btn-sm" data-acc-none>
                <span class="material-symbols-rounded">remove_done</span>Remover todas
              </button>
              <span class="acc-toolbar__sep"></span>
              <label class="admin-small" for="accCopyFrom" style="font-weight:600">Copiar de:</label>
              <select id="accCopyFrom" class="admin-input btn-sm" style="min-width:190px"></select>
              <button type="button" class="btn-secondary btn-sm" data-acc-copy>
                <span class="material-symbols-rounded">content_copy</span>Aplicar
              </button>
            </div>

            <div class="admin-table-wrap">
              <table class="admin-table admin-table-compact">
                <thead>
                  <tr>
                    <th>Empresa</th>
                    <th style="text-align:center">Acesso</th>
                    <th style="text-align:center">Varejo</th>
                    <th style="text-align:center">Licita&ccedil;&atilde;o</th>
                    <th>Papel (nesta empresa)</th>
                    <th style="text-align:center">Padr&atilde;o</th>
                  </tr>
                </thead>
                <tbody data-acc-rows>
                  <tr><td colspan="6" class="admin-small" style="text-align:center;padding:24px;color:var(--mag-muted,#64748b)">Carregando&hellip;</td></tr>
                </tbody>
              </table>
            </div>

            <!-- Barra de salvamento (só aparece quando há alteração pendente) -->
            <div class="acc-savebar" data-acc-savebar hidden>
              <span class="acc-savebar__msg" data-acc-savebar-msg></span>
              <div class="acc-savebar__actions">
                <button type="button" class="btn-secondary btn-sm" data-acc-discard>Descartar</button>
                <button type="button" class="btn-primary btn-sm" data-acc-save-all>
                  <span class="material-symbols-rounded">save</span>Salvar altera&ccedil;&otilde;es
                </button>
              </div>
            </div>

            <p class="admin-small" style="margin-top:10px;color:var(--mag-muted,#64748b)">
              Papel vazio = usa o papel global do usu&aacute;rio. Voc&ecirc; n&atilde;o pode alterar o seu pr&oacute;prio acesso.
              Dar acesso sem nenhum canal marcado faz o usu&aacute;rio n&atilde;o enxergar dado nenhum &mdash; por isso o Varejo &eacute; marcado automaticamente.
            </p>
          </div>

          <!-- ── Visibilidade comercial (Fase B) ─────────────────────────────
               Mesmo seletor de usu&aacute;rio acima: o admin configura acesso E
               visibilidade na MESMA tela, para o mesmo usu&aacute;rio, sem risco
               de esquecer um dos dois. -->
          <div class="admin-panel" data-sv-panel style="margin-top:16px">
            <div style="margin-bottom:12px">
              <h2 style="margin:0"><span class="material-symbols-rounded">visibility</span> Visibilidade comercial</h2>
              <p class="admin-small" style="margin-top:4px">Quais vendedores <strong data-sv-user>este usu&aacute;rio</strong> enxerga em cada empresa. Restringe cards, calend&aacute;rio, drawer, busca, exporta&ccedil;&otilde;es e API &mdash; sempre validado no servidor.</p>
            </div>

            <p class="admin-small" data-sv-selfhint hidden style="color:var(--mag-muted,#64748b);margin:0 0 10px">
              &mdash; voc&ecirc; n&atilde;o pode alterar a pr&oacute;pria visibilidade.
            </p>

            <div data-sv-cards>
              <p class="admin-small" style="text-align:center;padding:20px;color:var(--mag-muted,#64748b)">Carregando&hellip;</p>
            </div>

            <div class="acc-savebar" data-sv-savebar hidden>
              <span class="acc-savebar__msg" data-sv-savebar-msg></span>
              <div class="acc-savebar__actions">
                <button type="button" class="btn-secondary btn-sm" data-sv-discard>Descartar</button>
                <button type="button" class="btn-primary btn-sm" data-sv-save>
                  <span class="material-symbols-rounded">save</span>Salvar visibilidade
                </button>
              </div>
            </div>

            <p class="admin-small" style="margin-top:10px;color:var(--mag-muted,#64748b)">
              <strong>Todos</strong>: v&ecirc; todos os vendedores da empresa. &nbsp;
              <strong>Somente o pr&oacute;prio</strong>: v&ecirc; s&oacute; a carteira vinculada a ele (aba de v&iacute;nculos). &nbsp;
              <strong>Personalizado</strong>: voc&ecirc; escolhe exatamente quais. &nbsp;
              Sem configura&ccedil;&atilde;o, quem tem v&iacute;nculo de vendedor v&ecirc; a pr&oacute;pria carteira; os demais v&ecirc;em todos.
            </p>
          </div>
        </section>

        <script>
        (function () {
          var root = document.querySelector('[data-admin-tab-panel="access"]');
          if (!root) return;

          var userSel   = root.querySelector('#accUser');
          var copySel   = root.querySelector('#accCopyFrom');
          var rowsEl    = root.querySelector('[data-acc-rows]');
          var hintEl    = root.querySelector('[data-acc-hint]');
          var summaryEl = root.querySelector('[data-acc-summary]');
          var saveBar   = root.querySelector('[data-acc-savebar]');
          var saveMsg   = root.querySelector('[data-acc-savebar-msg]');
          var DATA = null;

          function csrf() { return window.ADMIN_CSRF || ''; }
          function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
          function uid() { return parseInt(userSel.value, 10); }
          function isSelf() { return DATA && uid() === DATA.actor_id; }

          // ── Estado corrente da matriz na tela (por empresa) ───────────────────
          function readRow(tr) {
            return {
              cid:        parseInt(tr.getAttribute('data-cid'), 10),
              has:        tr.querySelector('[data-acc-has]').checked,
              varejo:     tr.querySelector('[data-acc-varejo]').checked,
              licitacao:  tr.querySelector('[data-acc-licit]').checked,
              role:       tr.querySelector('[data-acc-role]').value || '',
              is_default: tr.querySelector('[data-acc-default]').checked
            };
          }

          // Estado salvo (vindo do servidor) para a empresa
          function savedState(cid) {
            var link = (DATA.matrix[uid()] && DATA.matrix[uid()][cid]) || null;
            return {
              cid: cid,
              has: !!link,
              varejo: !!(link && link.can_varejo),
              licitacao: !!(link && link.can_licitacao),
              role: (link && link.role_override) || '',
              is_default: !!(link && link.is_default)
            };
          }

          function sameState(a, b) {
            if (!a.has && !b.has) return true; // sem acesso dos dois lados: canais/papel são irrelevantes
            return a.has === b.has && a.varejo === b.varejo && a.licitacao === b.licitacao
                && a.role === b.role && a.is_default === b.is_default;
          }

          function dirtyRows() {
            var out = [];
            rowsEl.querySelectorAll('[data-acc-row]').forEach(function (tr) {
              var cur = readRow(tr);
              if (!sameState(cur, savedState(cur.cid))) out.push({ tr: tr, state: cur });
            });
            return out;
          }

          // ── Sincroniza aparência da linha + barra de salvar ───────────────────
          function syncRow(tr) {
            var has = tr.querySelector('[data-acc-has]').checked;
            var self = isSelf();
            ['[data-acc-varejo]', '[data-acc-licit]', '[data-acc-role]', '[data-acc-default]'].forEach(function (sel) {
              var el = tr.querySelector(sel);
              if (el) el.disabled = self || !has; // canais/papel/padrão só fazem sentido com acesso
            });
            tr.classList.toggle('acc-row--off', !has);

            // Acesso sem canal = usuário não vê nada. Marca Varejo (canal padrão).
            if (has) {
              var v = tr.querySelector('[data-acc-varejo]');
              var l = tr.querySelector('[data-acc-licit]');
              if (v && l && !v.checked && !l.checked) v.checked = true;
            }

            var cur = readRow(tr);
            tr.classList.toggle('acc-row--dirty', !sameState(cur, savedState(cur.cid)));
          }

          function syncAll() {
            rowsEl.querySelectorAll('[data-acc-row]').forEach(syncRow);

            var total = DATA ? DATA.companies.length : 0;
            var withAccess = rowsEl.querySelectorAll('[data-acc-has]:checked').length;
            summaryEl.textContent = total ? ('Acessa ' + withAccess + ' de ' + total + ' empresas') : '';
            summaryEl.className = 'acc-summary' + (withAccess === 0 ? ' acc-summary--none' : '');

            var d = dirtyRows();
            saveBar.hidden = d.length === 0;
            if (d.length) {
              saveMsg.textContent = d.length === 1
                ? '1 empresa com alteração pendente.'
                : d.length + ' empresas com alterações pendentes.';
            }
          }

          // ── Render ────────────────────────────────────────────────────────────
          function renderRows() {
            if (!DATA) return;
            var self = isSelf();
            hintEl.textContent = self ? '— você não pode alterar o próprio acesso.' : '';
            root.querySelector('[data-acc-toolbar]').style.display = self ? 'none' : '';

            var roleOpts = ['<option value="">(papel global)</option>'].concat(
              DATA.roles.map(function (r) { return '<option value="' + r + '">' + r + '</option>'; })
            ).join('');
            var dis = self ? 'disabled' : '';

            rowsEl.innerHTML = DATA.companies.map(function (c) {
              var s = savedState(c.id);
              return '' +
                '<tr data-acc-row data-cid="' + c.id + '">' +
                  '<td><strong>' + esc(c.nome_fantasia || c.slug) + '</strong>' +
                    '<div class="admin-small" style="color:var(--mag-muted,#64748b)">' + esc(c.slug) + '</div></td>' +
                  '<td style="text-align:center"><input type="checkbox" data-acc-has ' + (s.has ? 'checked' : '') + ' ' + dis + '></td>' +
                  '<td style="text-align:center"><input type="checkbox" data-acc-varejo ' + (s.varejo ? 'checked' : '') + ' ' + dis + '></td>' +
                  '<td style="text-align:center"><input type="checkbox" data-acc-licit ' + (s.licitacao ? 'checked' : '') + ' ' + dis + '></td>' +
                  '<td><select class="admin-input" data-acc-role ' + dis + ' style="min-width:130px">' + roleOpts + '</select></td>' +
                  '<td style="text-align:center"><input type="radio" name="acc-default" data-acc-default ' + (s.is_default ? 'checked' : '') + ' ' + dis + '></td>' +
                '</tr>';
            }).join('');

            rowsEl.querySelectorAll('[data-acc-row]').forEach(function (tr) {
              var cid = parseInt(tr.getAttribute('data-cid'), 10);
              var s = savedState(cid);
              var roleSel = tr.querySelector('[data-acc-role]');
              if (roleSel && s.role) roleSel.value = s.role;
              tr.addEventListener('change', function () { syncAll(); });
            });

            syncAll();
          }

          function renderUserSelectors() {
            var opts = DATA.users.map(function (u) {
              return '<option value="' + u.id + '">' + esc(u.full_name || u.username) + ' (' + esc(u.username) + ')</option>';
            }).join('');
            var keepUser = userSel.value;
            userSel.innerHTML = opts;
            if (keepUser) userSel.value = keepUser;
            copySel.innerHTML = '<option value="">— selecione —</option>' + opts;
          }

          function loadMatrix(keepSelection) {
            fetch('api/company_access.php', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
              .then(function (r) { return r.json(); })
              .then(function (d) {
                if (!d || !d.ok) { rowsEl.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:20px">Sem permiss&atilde;o ou falha ao carregar.</td></tr>'; return; }
                DATA = d;
                renderUserSelectors();
                renderRows();
              })
              .catch(function () { rowsEl.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:20px">Falha ao carregar.</td></tr>'; });
          }

          // ── Salvar em lote ────────────────────────────────────────────────────
          // Sequencial de propósito: is_default zera as outras empresas no servidor,
          // então em paralelo a última resposta poderia sobrescrever a anterior.
          function saveAll() {
            var pending = dirtyRows();
            if (!pending.length) return;
            var u = uid();
            var btn = root.querySelector('[data-acc-save-all]');
            btn.disabled = true;
            var done = 0, failed = 0;

            function step(i) {
              if (i >= pending.length) {
                btn.disabled = false;
                saveMsg.textContent = failed
                  ? (done + ' salva(s), ' + failed + ' com falha.')
                  : 'Alterações salvas.';
                loadMatrix(true);
                return;
              }
              var st = pending[i].state;
              var payload = st.has
                ? { action: 'grant', user_id: u, company_id: st.cid,
                    can_varejo: st.varejo, can_licitacao: st.licitacao,
                    role: st.role, is_default: st.is_default, csrf_token: csrf() }
                : { action: 'revoke', user_id: u, company_id: st.cid, csrf_token: csrf() };

              saveMsg.textContent = 'Salvando ' + (i + 1) + ' de ' + pending.length + '…';
              fetch('api/company_access.php', { method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
                .then(function (r) { return r.json(); })
                .then(function (d) { (d && d.ok) ? done++ : failed++; step(i + 1); })
                .catch(function () { failed++; step(i + 1); });
            }
            step(0);
          }

          // ── Ações em massa ────────────────────────────────────────────────────
          root.querySelector('[data-acc-all]').addEventListener('click', function () {
            rowsEl.querySelectorAll('[data-acc-row]').forEach(function (tr) {
              tr.querySelector('[data-acc-has]').checked = true;
            });
            syncAll(); // marca Varejo automaticamente nas que ficaram sem canal
          });

          root.querySelector('[data-acc-none]').addEventListener('click', function () {
            rowsEl.querySelectorAll('[data-acc-row]').forEach(function (tr) {
              tr.querySelector('[data-acc-has]').checked = false;
            });
            syncAll();
          });

          root.querySelector('[data-acc-copy]').addEventListener('click', function () {
            var from = parseInt(copySel.value, 10);
            if (!from || !DATA.matrix) return;
            var src = DATA.matrix[from] || {};
            rowsEl.querySelectorAll('[data-acc-row]').forEach(function (tr) {
              var cid = parseInt(tr.getAttribute('data-cid'), 10);
              var link = src[cid] || null;
              tr.querySelector('[data-acc-has]').checked      = !!link;
              tr.querySelector('[data-acc-varejo]').checked   = !!(link && link.can_varejo);
              tr.querySelector('[data-acc-licit]').checked    = !!(link && link.can_licitacao);
              tr.querySelector('[data-acc-role]').value       = (link && link.role_override) || '';
              tr.querySelector('[data-acc-default]').checked  = !!(link && link.is_default);
            });
            syncAll();
          });

          root.querySelector('[data-acc-save-all]').addEventListener('click', saveAll);
          root.querySelector('[data-acc-discard]').addEventListener('click', function () { renderRows(); });
          userSel.addEventListener('change', renderRows);

          loadMatrix();
        })();
        </script>

        <!-- ── Visibilidade comercial — módulo próprio, mesmo seletor de usuário ── -->
        <script>
        (function () {
          var root = document.querySelector('[data-admin-tab-panel="access"]');
          if (!root) return;
          var panel   = root.querySelector('[data-sv-panel]');
          var userSel = root.querySelector('#accUser');
          if (!panel || !userSel) return;

          var cardsEl   = panel.querySelector('[data-sv-cards]');
          var saveBar   = panel.querySelector('[data-sv-savebar]');
          var saveMsg   = panel.querySelector('[data-sv-savebar-msg]');
          var selfHint  = panel.querySelector('[data-sv-selfhint]');
          var userLabel = panel.querySelector('[data-sv-user]');
          var SV = null; // dados do servidor

          function csrf() { return window.ADMIN_CSRF || ''; }
          function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
          function uid() { return parseInt(userSel.value, 10) || 0; }
          function isSelf() { return SV && uid() === SV.actor_id; }

          // ── Índices a partir do payload ───────────────────────────────────────
          function sellersOf(cid) { return (SV.byCompanySellers[cid] || []); }
          function savedMode(cid, u) {
            var m = SV.modeMap[cid + ':' + u];
            if (m) return m;
            // Default: own se tem vínculo nessa empresa, senão all.
            return (SV.bindMap[cid + ':' + u] && SV.bindMap[cid + ':' + u].length) ? 'own' : 'all';
          }
          function savedPicks(cid, u) { return (SV.pickMap[cid + ':' + u] || []).slice().sort(); }
          function bindCodes(cid, u) { return (SV.bindMap[cid + ':' + u] || []); }

          function buildIndex(d) {
            d.byCompanySellers = {}; d.modeMap = {}; d.pickMap = {}; d.bindMap = {};
            (d.sellers || []).forEach(function (s) {
              (d.byCompanySellers[s.company_id] = d.byCompanySellers[s.company_id] || []).push(s);
            });
            (d.modes || []).forEach(function (m) { d.modeMap[m.company_id + ':' + m.user_id] = m.visibility_mode; });
            (d.picked || []).forEach(function (p) {
              (d.pickMap[p.company_id + ':' + p.user_id] = d.pickMap[p.company_id + ':' + p.user_id] || []).push(String(p.seller_id));
            });
            (d.bindings || []).forEach(function (b) {
              (d.bindMap[b.company_id + ':' + b.user_id] = d.bindMap[b.company_id + ':' + b.user_id] || []).push(String(b.seller_id));
            });
            return d;
          }

          // ── Estado corrente de um card ────────────────────────────────────────
          function readCard(card) {
            var mode = card.querySelector('[data-sv-mode]').value;
            var picks = [];
            if (mode === 'selected') {
              card.querySelectorAll('[data-sv-pick]:checked').forEach(function (cb) { picks.push(cb.value); });
            }
            return { cid: parseInt(card.getAttribute('data-cid'), 10), mode: mode, picks: picks.slice().sort() };
          }
          function sameCard(a, b) {
            if (a.mode !== b.mode) return false;
            if (a.mode !== 'selected') return true;
            return a.picks.join(',') === b.picks.join(',');
          }
          function savedCard(cid) {
            var u = uid();
            return { cid: cid, mode: savedMode(cid, u), picks: savedPicks(cid, u) };
          }

          function dirtyCards() {
            var out = [];
            cardsEl.querySelectorAll('[data-sv-card]').forEach(function (card) {
              var cur = readCard(card);
              if (!sameCard(cur, savedCard(cur.cid))) out.push(cur);
            });
            return out;
          }

          function syncCard(card) {
            var mode = card.querySelector('[data-sv-mode]').value;
            var picker = card.querySelector('[data-sv-picker]');
            if (picker) picker.hidden = (mode !== 'selected');
            var cur = readCard(card);
            card.classList.toggle('acc-row--dirty', !sameCard(cur, savedCard(cur.cid)));
          }

          function syncAll() {
            cardsEl.querySelectorAll('[data-sv-card]').forEach(syncCard);
            var d = dirtyCards();
            saveBar.hidden = d.length === 0 || isSelf();
            if (d.length) {
              saveMsg.textContent = d.length === 1
                ? '1 empresa com alteração pendente.'
                : d.length + ' empresas com alterações pendentes.';
            }
          }

          // ── Render ────────────────────────────────────────────────────────────
          function render() {
            if (!SV) return;
            var u = uid();
            var userObj = (SV.users || []).find(function (x) { return x.id === u; });
            if (userLabel) userLabel.textContent = userObj ? (userObj.full_name || userObj.username) : 'este usuário';

            var self = isSelf();
            selfHint.hidden = !self;
            saveBar.hidden = true;
            if (self) {
              cardsEl.innerHTML = '<p class="admin-small" style="text-align:center;padding:16px;color:var(--mag-muted,#64748b)">Configure outro usuário para editar a visibilidade.</p>';
              return;
            }

            cardsEl.innerHTML = (SV.companies || []).map(function (c) {
              var cid = c.id;
              var mode = savedMode(cid, u);
              var picks = savedPicks(cid, u);
              var binds = bindCodes(cid, u);
              var sellers = sellersOf(cid);

              var ownNote = binds.length
                ? 'Carteira vinculada: ' + binds.length + ' código(s).'
                : 'Sem vínculo de vendedor — no modo "Somente o próprio" não verá nada.';

              var checks = sellers.length
                ? sellers.map(function (s) {
                    var on = picks.indexOf(String(s.seller_id)) >= 0;
                    return '<label class="sv-pick"><input type="checkbox" data-sv-pick value="' + esc(s.seller_id) + '" ' + (on ? 'checked' : '') + '>' +
                      '<span>' + esc(s.nome || s.seller_id) + '</span></label>';
                  }).join('')
                : '<p class="admin-small" style="color:var(--mag-muted,#64748b)">Nenhum vendedor no cadastro desta empresa.</p>';

              return '' +
                '<div class="sv-card" data-sv-card data-cid="' + cid + '">' +
                  '<div class="sv-card__head">' +
                    '<strong>' + esc(c.nome_fantasia || c.slug) + '</strong>' +
                    '<select class="admin-input btn-sm" data-sv-mode style="min-width:190px">' +
                      '<option value="all"' + (mode === 'all' ? ' selected' : '') + '>Todos os vendedores</option>' +
                      '<option value="own"' + (mode === 'own' ? ' selected' : '') + '>Somente o próprio</option>' +
                      '<option value="selected"' + (mode === 'selected' ? ' selected' : '') + '>Personalizado</option>' +
                    '</select>' +
                  '</div>' +
                  '<p class="admin-small" style="margin:2px 0 0;color:var(--mag-muted,#64748b)">' + esc(ownNote) + '</p>' +
                  '<div class="sv-picker" data-sv-picker' + (mode === 'selected' ? '' : ' hidden') + '>' +
                    '<div class="sv-picks">' + checks + '</div>' +
                  '</div>' +
                '</div>';
            }).join('');

            cardsEl.querySelectorAll('[data-sv-card]').forEach(function (card) {
              card.addEventListener('change', syncAll);
            });
            syncAll();
          }

          // ── Salvar (sequencial, uma empresa por vez) ──────────────────────────
          function saveAll() {
            var pending = dirtyCards();
            if (!pending.length || isSelf()) return;
            var u = uid();
            var btn = panel.querySelector('[data-sv-save]');
            btn.disabled = true;
            var done = 0, failed = 0, lastErr = '';

            function step(i) {
              if (i >= pending.length) {
                btn.disabled = false;
                saveMsg.textContent = failed
                  ? (done + ' salva(s), ' + failed + ' com falha' + (lastErr ? ': ' + lastErr : '') + '.')
                  : 'Visibilidade salva.';
                reload();
                return;
              }
              var st = pending[i];
              var payload = { action: 'save', user_id: u, company_id: st.cid, mode: st.mode,
                seller_ids: st.mode === 'selected' ? st.picks : [], csrf_token: csrf() };
              saveMsg.textContent = 'Salvando ' + (i + 1) + ' de ' + pending.length + '…';
              fetch('api/seller_visibility.php', { method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
                .then(function (r) { return r.json(); })
                .then(function (d) { if (d && d.ok) { done++; } else { failed++; lastErr = (d && d.message) || ''; } step(i + 1); })
                .catch(function () { failed++; step(i + 1); });
            }
            step(0);
          }

          function reload() {
            fetch('api/seller_visibility.php', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
              .then(function (r) { return r.json(); })
              .then(function (d) {
                if (!d || !d.ok) {
                  cardsEl.innerHTML = '<p class="admin-small" style="text-align:center;padding:16px">' +
                    esc((d && d.message) || 'Falha ao carregar visibilidade.') + '</p>';
                  saveBar.hidden = true; return;
                }
                SV = buildIndex(d);
                render();
              })
              .catch(function () { cardsEl.innerHTML = '<p class="admin-small" style="text-align:center;padding:16px">Falha ao carregar.</p>'; });
          }

          panel.querySelector('[data-sv-save]').addEventListener('click', saveAll);
          panel.querySelector('[data-sv-discard]').addEventListener('click', render);
          // Mesmo seletor de usuário da matriz de acesso: trocar o usuário re-renderiza os dois.
          userSel.addEventListener('change', function () { if (SV) render(); });

          reload();
        })();
        </script>

        <style>
          .sv-card { border:1px solid var(--mag-border,#e5eaf1); border-radius:12px; padding:12px 14px; margin-bottom:10px; }
          .sv-card.acc-row--dirty { border-color:#2563eb; box-shadow:0 0 0 1px #2563eb33; }
          .sv-card__head { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; }
          .sv-picker { margin-top:10px; padding-top:10px; border-top:1px dashed var(--mag-border,#e5eaf1); }
          .sv-picks { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:6px 14px; }
          .sv-pick { display:flex; align-items:center; gap:7px; font-size:.85rem; }
          .sv-pick span { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        </style>

        <section class="admin-section <?php echo $activeTab === 'seller-binding' ? 'active' : ''; ?>" data-admin-tab-panel="seller-binding">
          <div class="admin-panel">
            <div style="margin-bottom:16px">
              <h2 style="margin:0"><span class="material-symbols-rounded">link</span> V&iacute;nculo de Vendedores</h2>
              <p class="admin-small" style="margin-top:4px">Associe cada conta de usu&aacute;rio ao seu c&oacute;digo de vendedor no Omie. &Eacute; o que permite a um vendedor ver <strong>somente a pr&oacute;pria carteira</strong> de or&ccedil;amentos. Um c&oacute;digo pertence a um &uacute;nico usu&aacute;rio; um usu&aacute;rio pode ter v&aacute;rios c&oacute;digos (ex.: Varejo e Revenda).</p>
              <p class="admin-small" style="margin-top:2px;color:var(--mag-muted,#64748b)">O v&iacute;nculo sozinho n&atilde;o d&aacute; acesso: o papel do usu&aacute;rio precisa ter a permiss&atilde;o de carteira pr&oacute;pria.</p>
            </div>

            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px">
              <label for="sbUser" class="admin-small" style="font-weight:700">Usu&aacute;rio:</label>
              <select id="sbUser" class="admin-input" style="min-width:240px"></select>
              <label for="sbCompany" class="admin-small" style="font-weight:700">Empresa:</label>
              <select id="sbCompany" class="admin-input" style="min-width:180px"></select>
              <span class="admin-small" data-sb-hint style="color:var(--mag-muted,#64748b)"></span>
            </div>

            <div data-sb-status class="admin-small" style="margin-bottom:10px"></div>

            <div class="admin-table-wrap">
              <table class="admin-table admin-table-compact">
                <thead>
                  <tr>
                    <th>Vendedor (Omie)</th>
                    <th>C&oacute;digo</th>
                    <th style="text-align:center">Or&ccedil;. em aberto</th>
                    <th>V&iacute;nculo</th>
                    <th style="text-align:right">A&ccedil;&atilde;o</th>
                  </tr>
                </thead>
                <tbody data-sb-rows>
                  <tr><td colspan="5" class="admin-small" style="color:var(--mag-muted,#64748b)">Carregando&hellip;</td></tr>
                </tbody>
              </table>
            </div>
          </div>

          <script>
          (function () {
            'use strict';
            var panel = document.querySelector('[data-admin-tab-panel="seller-binding"]');
            if (!panel || panel.__sbInit) return;
            panel.__sbInit = true;

            function csrf() { return window.ADMIN_CSRF || ''; }
            var userSel = panel.querySelector('#sbUser');
            var compSel = panel.querySelector('#sbCompany');
            var rowsEl  = panel.querySelector('[data-sb-rows]');
            var statusEl = panel.querySelector('[data-sb-status]');
            var hintEl  = panel.querySelector('[data-sb-hint]');
            var state = { users: [], companies: [], sellers: [], bindings: [], loaded: false };

            function esc(s) { var d = document.createElement('div'); d.textContent = String(s == null ? '' : s); return d.innerHTML; }
            function setStatus(msg, kind) {
              statusEl.textContent = msg || '';
              statusEl.style.color = kind === 'error' ? 'var(--danger,#dc2626)' : (kind === 'ok' ? 'var(--state-success,#16a34a)' : 'var(--mag-muted,#64748b)');
            }

            function bindingOwner(companyId, sellerId) {
              for (var i = 0; i < state.bindings.length; i++) {
                var b = state.bindings[i];
                if (Number(b.company_id) === Number(companyId) && String(b.seller_id) === String(sellerId)) return Number(b.user_id);
              }
              return null;
            }
            function userName(uid) {
              for (var i = 0; i < state.users.length; i++) if (Number(state.users[i].id) === Number(uid)) return state.users[i].full_name || state.users[i].username;
              return 'usuário ' + uid;
            }

            function render() {
              if (!state.loaded) return;
              var uid = Number(userSel.value || 0);
              var cid = Number(compSel.value || 0);
              var sellers = state.sellers.filter(function (s) { return Number(s.company_id) === cid; });
              if (!sellers.length) {
                rowsEl.innerHTML = '<tr><td colspan="5" class="admin-small" style="color:var(--mag-muted,#64748b)">Nenhum vendedor ativo nesta empresa.</td></tr>';
                return;
              }
              rowsEl.innerHTML = sellers.map(function (s) {
                var owner = bindingOwner(s.company_id, s.seller_id);
                var mine  = owner === uid;
                var taken = owner !== null && !mine;
                var vinculo = mine ? '<span style="color:var(--state-success,#16a34a);font-weight:700">Deste usuário</span>'
                            : taken ? '<span style="color:var(--warning,#d97706)">' + esc(userName(owner)) + '</span>'
                            : '<span style="color:var(--mag-muted,#94a3b8)">—</span>';
                var open = Number(s.open_quotes || 0);
                var openBadge = open > 0
                  ? '<strong style="color:var(--page-accent,#96140a)">' + open + '</strong>'
                  : '<span style="color:var(--mag-muted,#94a3b8)">0</span>';
                var btn = mine
                  ? '<button type="button" class="btn-secondary btn-sm" data-sb-unbind data-seller="' + esc(s.seller_id) + '">Desvincular</button>'
                  : taken
                    ? '<button type="button" class="btn-secondary btn-sm" disabled title="Código já vinculado a outro usuário">Ocupado</button>'
                    : '<button type="button" class="btn-primary btn-sm" data-sb-bind data-seller="' + esc(s.seller_id) + '">Vincular</button>';
                return '<tr>' +
                  '<td>' + esc(s.nome || '(sem nome)') + '</td>' +
                  '<td class="admin-small" style="font-family:monospace">' + esc(s.seller_id) + '</td>' +
                  '<td style="text-align:center">' + openBadge + '</td>' +
                  '<td>' + vinculo + '</td>' +
                  '<td style="text-align:right">' + btn + '</td>' +
                '</tr>';
              }).join('');

              var mineCount = state.bindings.filter(function (b) { return Number(b.user_id) === uid; }).length;
              hintEl.textContent = mineCount > 0 ? (mineCount + ' código(s) vinculado(s) a este usuário no total.') : 'Sem vínculos ainda.';
            }

            function act(action, sellerId) {
              var uid = Number(userSel.value || 0), cid = Number(compSel.value || 0);
              if (!uid || !cid || !sellerId) return;
              setStatus('Salvando…');
              fetch('api/quote_seller_binding.php', {
                method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: action, user_id: uid, company_id: cid, seller_id: sellerId, csrf_token: csrf() })
              }).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
                .then(function (res) {
                  if (!res.ok || !res.d || !res.d.ok) { setStatus((res.d && res.d.message) || 'Falha ao salvar.', 'error'); return; }
                  // Atualiza o estado local sem recarregar tudo.
                  state.bindings = state.bindings.filter(function (b) {
                    return !(Number(b.company_id) === cid && String(b.seller_id) === String(sellerId));
                  });
                  if (action === 'bind') state.bindings.push({ user_id: uid, company_id: cid, seller_id: sellerId });
                  setStatus(action === 'bind' ? 'Vinculado.' : 'Desvinculado.', 'ok');
                  render();
                })
                .catch(function () { setStatus('Erro de rede.', 'error'); });
            }

            rowsEl.addEventListener('click', function (e) {
              var b = e.target.closest('[data-sb-bind]'); if (b) { act('bind', b.getAttribute('data-seller')); return; }
              var u = e.target.closest('[data-sb-unbind]'); if (u) { act('unbind', u.getAttribute('data-seller')); return; }
            });
            userSel.addEventListener('change', render);
            compSel.addEventListener('change', render);

            function load() {
              if (state.loaded) return;
              fetch('api/quote_seller_binding.php', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json().then(function (d) { return { status: r.status, d: d }; }); })
                .then(function (res) {
                  if (res.status === 503) { setStatus('A migração da Fase 10 ainda não foi aplicada. O mapeamento fica disponível após o deploy.', 'error'); return; }
                  var d = res.d || {};
                  if (!d.ok) { setStatus(d.message || 'Falha ao carregar.', 'error'); return; }
                  state.users = d.users || []; state.companies = d.companies || [];
                  state.sellers = d.sellers || []; state.bindings = d.bindings || []; state.loaded = true;
                  userSel.innerHTML = state.users.map(function (u) { return '<option value="' + u.id + '">' + esc(u.full_name || u.username) + ' (' + esc(u.role) + ')</option>'; }).join('');
                  compSel.innerHTML = state.companies.map(function (c) { return '<option value="' + c.id + '">' + esc(c.nome_fantasia || c.slug) + '</option>'; }).join('');
                  render();
                })
                .catch(function () { setStatus('Erro de rede ao carregar.', 'error'); });
            }

            // Carrega ao abrir a aba (lazy).
            var trig = document.querySelector('[data-admin-tab-trigger="seller-binding"]');
            if (trig) trig.addEventListener('click', load);
            if (panel.classList.contains('active')) load();
          }());
          </script>
        </section>

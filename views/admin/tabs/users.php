        <section class="admin-section <?php echo $activeTab === 'users' ? 'active' : ''; ?>" data-admin-tab-panel="users">
          <div class="admin-panel">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:16px">
              <div>
                <h2 style="margin:0">Usu&aacute;rios</h2>
                <p class="admin-small" style="margin-top:4px">Acessos, perfis e status dos usu&aacute;rios cadastrados.</p>
              </div>
              <?php if ($can['users'] ?? false): ?>
                <button class="btn-primary mag-button-primary" type="button" data-provision-open>
                  <span class="material-symbols-rounded">person_add</span>Novo usu&aacute;rio
                </button>
              <?php endif; ?>
            </div>
            <div class="admin-table-wrap">
              <table class="admin-table admin-table-compact">
                <thead>
                  <tr>
                    <th>Usu&aacute;rio</th>
                    <th>Perfil</th>
                    <th>Status</th>
                    <th>&Uacute;ltimo login</th>
                    <?php if ($can['users'] ?? false): ?><th>A&ccedil;&otilde;es</th><?php endif; ?>
                  </tr>
                </thead>
                <tbody id="adminUsersTable" data-empty-title="Sem usu&aacute;rios" data-empty-message="Cadastre o primeiro usu&aacute;rio para liberar acesso ao BIomie." data-empty-icon="group_add">
                <?php if ($users === []): ?>
                  <tr><td colspan="<?php echo ($can['users'] ?? false) ? 5 : 4; ?>">Nenhum usu&aacute;rio cadastrado.</td></tr>
                <?php endif; ?>
                <?php foreach ($users as $adminUser): ?>
                  <?php
                    $userRole   = (string) ($adminUser['primary_role'] ?? $adminUser['role'] ?? 'viewer');
                    $uIsActive  = (int) ($adminUser['is_active'] ?? 0) === 1;
                    $uId        = (int) $adminUser['id'];
                    $uFullName  = (string) ($adminUser['full_name'] ?? '');
                    $uUsername  = (string) ($adminUser['username'] ?? '');
                    $uEmail     = (string) ($adminUser['email'] ?? '');
                    $uLastLogin = $adminUser['last_login_at'] ?? null;
                  ?>
                  <tr>
                    <td>
                      <div class="admin-user-cell">
                        <strong><?php echo $h($uFullName); ?></strong>
                        <span class="admin-small"><?php echo $h($uUsername); ?><?php echo $uEmail !== '' ? ' &middot; ' . $h($uEmail) : ''; ?></span>
                      </div>
                    </td>
                    <td><span class="admin-tag"><?php echo $h($roleLabel($userRole)); ?></span></td>
                    <td><?php echo $statusTag($uIsActive); ?></td>
                    <td><span class="admin-small"><?php echo $uLastLogin ? $h($uLastLogin) : 'Nunca'; ?></span></td>
                    <?php if ($can['users'] ?? false): ?>
                      <td>
                        <div class="admin-row-actions">
                          <button type="button" class="admin-row-btn"
                            data-effective-access="<?php echo $uId; ?>"
                            data-effective-name="<?php echo $h($uFullName); ?>"
                            title="O que este usu&aacute;rio realmente enxerga"
                          ><span class="material-symbols-rounded">visibility</span>Acesso</button>
                          <button type="button" class="admin-row-btn"
                            data-admin-modal-open="modal-edit-user"
                            data-edit-user-id="<?php echo $uId; ?>"
                            data-edit-full-name="<?php echo $h($uFullName); ?>"
                            data-edit-email="<?php echo $h($uEmail); ?>"
                            data-edit-role="<?php echo $h($userRole); ?>"
                            data-edit-active="<?php echo $uIsActive ? '1' : '0'; ?>"
                          ><span class="material-symbols-rounded">edit</span>Editar</button>
                          <button type="button" class="admin-row-btn"
                            data-admin-modal-open="modal-reset-password"
                            data-reset-user-id="<?php echo $uId; ?>"
                            data-reset-user-name="<?php echo $h($uFullName); ?>"
                          ><span class="material-symbols-rounded">lock_reset</span>Senha</button>
                          <form method="post" style="display:contents">
                            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>" />
                            <input type="hidden" name="action" value="update_user" />
                            <input type="hidden" name="user_id" value="<?php echo $uId; ?>" />
                            <input type="hidden" name="full_name" value="<?php echo $h($uFullName); ?>" />
                            <input type="hidden" name="email" value="<?php echo $h($uEmail); ?>" />
                            <input type="hidden" name="role" value="<?php echo $h($userRole); ?>" />
                            <?php if (!$uIsActive): ?><input type="hidden" name="is_active" value="1" /><?php endif; ?>
                            <button type="submit" class="admin-row-btn <?php echo $uIsActive ? 'danger' : ''; ?>"
                              onclick="return confirm(<?php echo json_encode($uIsActive ? 'Desativar este usuário?' : 'Ativar este usuário?'); ?>)"
                            ><span class="material-symbols-rounded"><?php echo $uIsActive ? 'person_off' : 'person'; ?></span><?php echo $uIsActive ? 'Desativar' : 'Ativar'; ?></button>
                          </form>
                        </div>
                      </td>
                    <?php else: ?>
                      <td><span class="admin-small">Somente leitura</span></td>
                    <?php endif; ?>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>

          <?php if ($can['users'] ?? false): ?>
          <div class="admin-modal" id="modal-new-user" hidden role="dialog" aria-modal="true" aria-labelledby="modal-new-user-label">
            <div class="admin-modal-box">
              <div class="admin-modal-head">
                <h3 id="modal-new-user-label"><span class="material-symbols-rounded" aria-hidden="true">person_add</span>Novo usu&aacute;rio</h3>
                <button type="button" class="admin-modal-close" data-admin-modal-close="modal-new-user" aria-label="Fechar">
                  <span class="material-symbols-rounded">close</span>
                </button>
              </div>
              <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>" />
                <input type="hidden" name="action" value="create_user" />
                <div class="admin-field"><label>Nome completo</label><input class="admin-input" name="full_name" required autocomplete="off" /></div>
                <div class="admin-field"><label>Usu&aacute;rio</label><input class="admin-input" name="username" required autocomplete="off" /></div>
                <div class="admin-field"><label>E-mail</label><input class="admin-input" name="email" type="email" autocomplete="off" /></div>
                <div class="admin-field"><label>Senha inicial</label><input class="admin-input" name="password" type="password" required autocomplete="new-password" /></div>
                <div class="admin-form-grid">
                  <div class="admin-field">
                    <label>Perfil</label>
                    <select class="admin-select" name="role">
                      <?php foreach ($roles as $roleRow): ?>
                        <option value="<?php echo $h($roleRow['role_key'] ?? 'viewer'); ?>"><?php echo $h($roleRow['name'] ?? 'Perfil'); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <label class="admin-check" style="margin-top:27px"><input type="checkbox" name="is_active" checked /> Ativo</label>
                </div>
                <button class="btn-primary mag-button-primary" type="submit" style="width:100%;margin-top:6px"><span class="material-symbols-rounded">person_add</span>Criar usu&aacute;rio</button>
              </form>
            </div>
          </div>

          <div class="admin-modal" id="modal-edit-user" hidden role="dialog" aria-modal="true" aria-labelledby="modal-edit-user-label">
            <div class="admin-modal-box">
              <div class="admin-modal-head">
                <h3 id="modal-edit-user-label"><span class="material-symbols-rounded" aria-hidden="true">edit</span>Editar usu&aacute;rio</h3>
                <button type="button" class="admin-modal-close" data-admin-modal-close="modal-edit-user" aria-label="Fechar">
                  <span class="material-symbols-rounded">close</span>
                </button>
              </div>
              <form method="post" id="form-edit-user">
                <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>" />
                <input type="hidden" name="action" value="update_user" />
                <input type="hidden" name="user_id" id="edit-user-id" value="" />
                <div class="admin-field"><label>Nome completo</label><input class="admin-input" name="full_name" id="edit-full-name" required /></div>
                <div class="admin-field"><label>E-mail</label><input class="admin-input" name="email" type="email" id="edit-email" /></div>
                <div class="admin-form-grid">
                  <div class="admin-field">
                    <label>Perfil</label>
                    <select class="admin-select" name="role" id="edit-role">
                      <?php foreach ($roles as $roleRow): ?>
                        <option value="<?php echo $h($roleRow['role_key'] ?? 'viewer'); ?>"><?php echo $h($roleRow['name'] ?? 'Perfil'); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <label class="admin-check" style="margin-top:27px"><input type="checkbox" name="is_active" id="edit-active" /> Ativo</label>
                </div>
                <button class="btn-primary mag-button-primary" type="submit" style="width:100%;margin-top:6px"><span class="material-symbols-rounded">save</span>Salvar altera&ccedil;&otilde;es</button>
              </form>
            </div>
          </div>

          <div class="admin-modal" id="modal-reset-password" hidden role="dialog" aria-modal="true" aria-labelledby="modal-reset-password-label">
            <div class="admin-modal-box">
              <div class="admin-modal-head">
                <h3 id="modal-reset-password-label"><span class="material-symbols-rounded" aria-hidden="true">lock_reset</span>Redefinir senha</h3>
                <button type="button" class="admin-modal-close" data-admin-modal-close="modal-reset-password" aria-label="Fechar">
                  <span class="material-symbols-rounded">close</span>
                </button>
              </div>
              <p class="admin-small" id="reset-password-user-name" style="margin:0 0 14px"></p>
              <form method="post" id="form-reset-password">
                <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>" />
                <input type="hidden" name="action" value="reset_password" />
                <input type="hidden" name="user_id" id="reset-password-user-id" value="" />
                <div class="admin-field"><label>Nova senha</label><input class="admin-input" name="password" type="password" required autocomplete="new-password" /></div>
                <button class="btn-primary mag-button-primary" type="submit" style="width:100%;margin-top:6px"><span class="material-symbols-rounded">lock_reset</span>Redefinir senha</button>
              </form>
            </div>
          </div>
          <?php endif; ?>

          <!-- ── ASSISTENTE DE CRIAÇÃO (ADMIN.R1) ──────────────────────────────
               4 etapas, UMA requisição no fim. Nada é gravado enquanto o admin
               navega — o estado vive no JS. Ver api/admin_provision_user.php. -->
          <?php if ($can['users'] ?? false): ?>
          <div class="admin-modal" id="modal-provision-user" hidden
               role="dialog" aria-modal="true" aria-labelledby="provision-user-title"
               data-provision-nonce="<?php echo $h($provisionNonce ?? ''); ?>">
            <div class="admin-modal-card pv-card">
              <div class="admin-modal-head">
                <h3 id="provision-user-title"><span class="material-symbols-rounded" aria-hidden="true">person_add</span>Novo usu&aacute;rio</h3>
                <button type="button" class="admin-row-btn" data-pv-close aria-label="Fechar">
                  <span class="material-symbols-rounded">close</span>
                </button>
              </div>

              <ol class="pv-steps" data-pv-steps aria-label="Etapas">
                <li data-pv-step-dot="1"><span>1</span>Identifica&ccedil;&atilde;o</li>
                <li data-pv-step-dot="2"><span>2</span>Empresas</li>
                <li data-pv-step-dot="3"><span>3</span>Visibilidade</li>
                <li data-pv-step-dot="4"><span>4</span>Revis&atilde;o</li>
              </ol>
              <p class="pv-mobile-step" data-pv-mobile></p>

              <div class="pv-body" data-pv-body>
                <p class="admin-small" style="text-align:center;padding:24px">Carregando&hellip;</p>
              </div>

              <div class="pv-error" data-pv-error hidden role="alert"></div>

              <div class="pv-foot">
                <button type="button" class="btn-secondary btn-sm" data-pv-back hidden>Voltar</button>
                <span style="flex:1"></span>
                <button type="button" class="btn-primary mag-button-primary btn-sm" data-pv-next>Continuar</button>
              </div>
            </div>
          </div>

          <!-- Confirmação de descarte: só aparece se houver dados preenchidos. -->
          <div class="admin-modal" id="modal-pv-discard" hidden
               role="dialog" aria-modal="true" aria-labelledby="pv-discard-title">
            <div class="admin-modal-box" style="max-width:400px">
              <h3 id="pv-discard-title" style="margin:0 0 8px">Descartar cria&ccedil;&atilde;o do usu&aacute;rio?</h3>
              <p class="admin-small" style="margin:0 0 16px">Os dados preenchidos ser&atilde;o perdidos.</p>
              <div style="display:flex;gap:8px;justify-content:flex-end">
                <!-- Foco inicial na opção MENOS destrutiva (W3C). -->
                <button type="button" class="btn-primary mag-button-primary btn-sm" data-pv-keep>Continuar preenchendo</button>
                <button type="button" class="btn-secondary btn-sm" data-pv-discard>Descartar</button>
              </div>
            </div>
          </div>
          <?php endif; ?>

          <!-- ── ACESSO EFETIVO (ADMIN.R1) ─────────────────────────────────────
               Responde "por que esse usuário está vendo essa tela?" mostrando a
               permissão CALCULADA (papel global × papel por empresa × canais ×
               visibilidade de vendedores), não as configurações soltas. -->
          <div class="admin-modal" id="modal-effective-access" hidden
               role="dialog" aria-modal="true" aria-labelledby="ea-title">
            <div class="admin-modal-card" style="max-width:720px">
              <div class="admin-modal-head">
                <h3 id="ea-title"><span class="material-symbols-rounded">visibility</span>Acesso efetivo &mdash; <span data-ea-user>&mdash;</span></h3>
                <button type="button" class="admin-row-btn" data-admin-modal-close aria-label="Fechar">
                  <span class="material-symbols-rounded">close</span>
                </button>
              </div>
              <p class="admin-small" style="margin:0 0 12px;color:var(--mag-muted,#64748b)">
                Permiss&atilde;o <strong>resultante</strong> das quatro camadas: papel global, papel por empresa,
                canais e visibilidade de vendedores. Somente leitura.
              </p>
              <div data-ea-body>
                <p class="admin-small" style="text-align:center;padding:20px">Carregando&hellip;</p>
              </div>
            </div>
          </div>
        </section>

        <style>
          /* ── Assistente ─────────────────────────────────────────────────── */
          /* Altura máxima com rolagem SÓ no corpo: título e ações ficam sempre
             visíveis, mesmo com muitas empresas/vendedores na etapa 3. */
          .pv-card{max-width:680px;display:flex;flex-direction:column;max-height:88vh}
          .pv-body{overflow-y:auto;flex:1 1 auto;min-height:220px;padding:2px}
          .pv-foot{display:flex;align-items:center;gap:8px;padding-top:14px;margin-top:12px;border-top:1px solid var(--mag-border,#e5e7eb)}
          .pv-steps{display:flex;list-style:none;margin:0 0 14px;padding:0;gap:6px}
          .pv-steps li{flex:1;display:flex;align-items:center;gap:6px;font-size:.74rem;font-weight:700;
            color:var(--mag-muted,#94a3b8);border-top:3px solid var(--mag-border,#e5e7eb);padding-top:7px}
          .pv-steps li span{display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;
            border-radius:999px;background:var(--mag-border,#e5e7eb);color:#fff;font-size:.66rem}
          .pv-steps li.done{color:var(--mag-text,#0f172a);border-top-color:#16a34a}
          .pv-steps li.done span{background:#16a34a}
          .pv-steps li.current{color:var(--page-accent,#96140a);border-top-color:var(--page-accent,#96140a)}
          .pv-steps li.current span{background:var(--page-accent,#96140a)}
          .pv-mobile-step{display:none;margin:0 0 12px;font-weight:800;font-size:.86rem}
          @media(max-width:640px){.pv-steps{display:none}.pv-mobile-step{display:block}}
          .pv-error{margin-top:10px;padding:9px 12px;border-radius:9px;background:#fef2f2;border:1px solid #fecaca;
            color:#991b1b;font-size:.82rem}
          .pv-error ul{margin:6px 0 0;padding-left:18px}
          .pv-co{border:1px solid var(--mag-border,#e5e7eb);border-radius:11px;padding:10px 12px;margin-bottom:8px}
          .pv-co__head{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
          .pv-co__head strong{flex:1;min-width:120px}
          .pv-sellers{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:3px 12px;margin-top:9px;
            padding-top:9px;border-top:1px dashed var(--mag-border,#e5e7eb)}
          .pv-sellers label{display:flex;align-items:center;gap:6px;font-size:.81rem}
          .pv-review dl{display:grid;grid-template-columns:auto 1fr;gap:4px 14px;margin:0}
          .pv-review dt{font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;color:var(--mag-muted,#64748b)}
          .pv-review dd{margin:0;font-size:.85rem}
          .pv-warn{color:#9a3412;font-size:.78rem}
          .pv-success{text-align:center;padding:18px 8px}
          .pv-success .material-symbols-rounded{font-size:44px;color:#16a34a}

          .ea-company{border:1px solid var(--mag-border,#e5e7eb);border-radius:12px;padding:12px 14px;margin-bottom:10px}
          .ea-company__head{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:8px}
          .ea-company__name{font-weight:800}
          .ea-origin{font-size:.72rem;color:var(--mag-muted,#64748b)}
          .ea-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:2px 14px}
          .ea-item{display:flex;align-items:center;gap:6px;font-size:.82rem;padding:1px 0}
          .ea-item .material-symbols-rounded{font-size:16px}
          .ea-yes{color:#15803d}.ea-no{color:#b91c1c;opacity:.65}
          .ea-sub{margin:10px 0 4px;font-size:.7rem;font-weight:800;letter-spacing:.05em;text-transform:uppercase;color:var(--mag-muted,#64748b)}
          .ea-badge{display:inline-flex;align-items:center;gap:5px;padding:2px 9px;border-radius:999px;
            font-size:.72rem;font-weight:700;background:var(--mag-bg,#f8f9fb);border:1px solid var(--mag-border,#e5e7eb)}
          .ea-badge--restricted{background:#fff7ed;border-color:#fdba74;color:#9a3412}
        </style>

        <script>
        /* ── ASSISTENTE DE CRIAÇÃO DE USUÁRIO (4 etapas, 1 requisição) ────────
           Estado inteiro no cliente: nada é gravado antes da confirmação. */
        (function () {
          var modal = document.getElementById('modal-provision-user');
          if (!modal) return;
          var discardModal = document.getElementById('modal-pv-discard');
          var bodyEl = modal.querySelector('[data-pv-body]');
          var errEl = modal.querySelector('[data-pv-error]');
          var backBtn = modal.querySelector('[data-pv-back]');
          var nextBtn = modal.querySelector('[data-pv-next]');
          var mobileEl = modal.querySelector('[data-pv-mobile]');
          var STEP_NAMES = ['Identificação', 'Empresas e papéis', 'Visibilidade comercial', 'Revisão'];

          var REF = null;   // empresas + vendedores (api/seller_visibility.php)
          var opener = null;
          var step = 1;
          var done = false; // true após criar: Esc não pede confirmação
          var nonce = modal.getAttribute('data-provision-nonce') || '';
          var st = null;

          function blank() {
            return { name: '', username: '', email: '', password: '', is_active: true,
                     companies: {}, visibility: {} };
          }
          function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
          function csrf() { return window.ADMIN_CSRF || ''; }
          function dirty() {
            if (!st) return false;
            return !!(st.name || st.username || st.email || st.password || Object.keys(st.companies).length);
          }
          function selectedCompanies() {
            return Object.keys(st.companies).map(Number).filter(function (c) { return st.companies[c]; });
          }
          function companyName(cid) {
            var c = (REF && REF.companies || []).find(function (x) { return Number(x.id) === Number(cid); });
            return c ? (c.nome_fantasia || c.slug) : ('Empresa ' + cid);
          }
          function sellersOf(cid) {
            return (REF && REF.sellers || []).filter(function (s) { return Number(s.company_id) === Number(cid); });
          }
          function bindingsOf(cid) {
            return []; // usuário novo nunca tem vínculo — 'own' resulta em zero.
          }

          function showError(msg, list) {
            errEl.hidden = false;
            errEl.innerHTML = '<strong>' + esc(msg) + '</strong>' +
              (list && list.length ? '<ul>' + list.map(function (e) { return '<li>' + esc(e) + '</li>'; }).join('') + '</ul>' : '');
          }
          function clearError() { errEl.hidden = true; errEl.innerHTML = ''; }

          function syncSteps() {
            modal.querySelectorAll('[data-pv-step-dot]').forEach(function (li) {
              var n = Number(li.getAttribute('data-pv-step-dot'));
              li.classList.toggle('current', n === step);
              li.classList.toggle('done', n < step);
            });
            mobileEl.textContent = 'Etapa ' + step + ' de 4 · ' + STEP_NAMES[step - 1];
            backBtn.hidden = step === 1 || done;
            nextBtn.textContent = step === 4 ? 'Criar usuário' : 'Continuar';
            nextBtn.hidden = done;
          }

          // ── Renderização por etapa ──────────────────────────────────────────
          function render() {
            clearError();
            syncSteps();
            if (step === 1) return renderIdentity();
            if (step === 2) return renderCompanies();
            if (step === 3) return renderVisibility();
            return renderReview();
          }

          function renderIdentity() {
            bodyEl.innerHTML =
              '<div class="admin-field"><label>Nome completo</label>' +
                '<input class="admin-input" data-pv-f="name" value="' + esc(st.name) + '" autocomplete="off"></div>' +
              '<div class="admin-field"><label>Usuário</label>' +
                '<input class="admin-input" data-pv-f="username" value="' + esc(st.username) + '" autocomplete="off"></div>' +
              '<div class="admin-field"><label>E-mail</label>' +
                '<input class="admin-input" type="email" data-pv-f="email" value="' + esc(st.email) + '" autocomplete="off"></div>' +
              '<div class="admin-field"><label>Senha inicial</label>' +
                '<input class="admin-input" type="password" data-pv-f="password" value="' + esc(st.password) + '" autocomplete="new-password">' +
                '<span class="admin-small">Mínimo de 8 caracteres.</span></div>' +
              '<label style="display:flex;align-items:center;gap:7px"><input type="checkbox" data-pv-f="is_active"' +
                (st.is_active ? ' checked' : '') + '> Ativo</label>';
            var first = bodyEl.querySelector('[data-pv-f="name"]');
            if (first) first.focus();
          }

          function renderCompanies() {
            var cos = (REF && REF.companies || []);
            if (!cos.length) { bodyEl.innerHTML = '<p class="admin-small">Nenhuma empresa ativa.</p>'; return; }
            var roles = ['viewer', 'analyst', 'manager', 'admin'];
            bodyEl.innerHTML = '<p class="admin-small" style="margin:0 0 10px">O perfil é <strong>por empresa</strong> — o mesmo usuário pode ser gestor numa e visualizador noutra.</p>' +
              cos.map(function (c) {
                var sel = st.companies[c.id];
                return '<div class="pv-co"><div class="pv-co__head">' +
                  '<label style="display:flex;align-items:center;gap:7px;flex:1"><input type="checkbox" data-pv-co="' + c.id + '"' + (sel ? ' checked' : '') + '>' +
                  '<strong>' + esc(c.nome_fantasia || c.slug) + '</strong></label>' +
                  '<select class="admin-input btn-sm" data-pv-role="' + c.id + '"' + (sel ? '' : ' disabled') + ' style="min-width:130px">' +
                    roles.map(function (r) { return '<option value="' + r + '"' + (sel && sel.role === r ? ' selected' : '') + '>' + r + '</option>'; }).join('') +
                  '</select>' +
                  '<label class="admin-small" style="display:flex;align-items:center;gap:5px"><input type="radio" name="pv-default" data-pv-def="' + c.id + '"' +
                    (sel && sel.is_default ? ' checked' : '') + (sel ? '' : ' disabled') + '> Padrão</label>' +
                  '</div>' +
                  '<div class="admin-small" style="margin-top:6px;display:flex;gap:14px">' +
                    '<label style="display:flex;align-items:center;gap:5px"><input type="checkbox" data-pv-ch="varejo" data-cid="' + c.id + '"' +
                      (sel && sel.can_varejo ? ' checked' : '') + (sel ? '' : ' disabled') + '> Varejo</label>' +
                    '<label style="display:flex;align-items:center;gap:5px"><input type="checkbox" data-pv-ch="licitacao" data-cid="' + c.id + '"' +
                      (sel && sel.can_licitacao ? ' checked' : '') + (sel ? '' : ' disabled') + '> Licitação</label>' +
                  '</div></div>';
              }).join('');
          }

          function renderVisibility() {
            var sel = selectedCompanies();
            if (!sel.length) { bodyEl.innerHTML = '<p class="admin-small">Volte e selecione ao menos uma empresa.</p>'; return; }
            bodyEl.innerHTML = sel.map(function (cid) {
              var v = st.visibility[cid] || { mode: 'all', seller_ids: [] };
              var sellers = sellersOf(cid);
              var modes = [['all', 'Todos os vendedores'], ['own', 'Somente o próprio (vínculo)'], ['selected', 'Personalizado']];
              var warn = v.mode === 'own'
                ? '<p class="pv-warn">Usuário novo não tem vínculo de vendedor — <strong>nenhum vendedor será visível</strong> até você criar o vínculo.</p>' : '';
              var picks = v.mode === 'selected'
                ? '<div class="pv-sellers">' + sellers.map(function (s) {
                    var on = v.seller_ids.indexOf(String(s.seller_id)) >= 0;
                    return '<label><input type="checkbox" data-pv-sell="' + cid + '" value="' + esc(s.seller_id) + '"' + (on ? ' checked' : '') + '>' +
                      '<span>' + esc(s.nome || s.seller_id) + '</span></label>';
                  }).join('') + '</div>' : '';
              return '<div class="pv-co"><div class="pv-co__head"><strong>' + esc(companyName(cid)) + '</strong>' +
                '<select class="admin-input btn-sm" data-pv-mode="' + cid + '" style="min-width:200px">' +
                  modes.map(function (m) { return '<option value="' + m[0] + '"' + (v.mode === m[0] ? ' selected' : '') + '>' + m[1] + '</option>'; }).join('') +
                '</select></div>' + warn + picks + '</div>';
            }).join('');
          }

          function renderReview() {
            var sel = selectedCompanies();
            bodyEl.innerHTML =
              '<div class="pv-review"><dl>' +
                '<dt>Nome</dt><dd>' + esc(st.name) + '</dd>' +
                '<dt>Usuário</dt><dd>' + esc(st.username) + '</dd>' +
                '<dt>E-mail</dt><dd>' + esc(st.email || '—') + '</dd>' +
                '<dt>Status</dt><dd>' + (st.is_active ? 'Ativo' : 'Inativo') + '</dd>' +
              '</dl></div>' +
              sel.map(function (cid) {
                var c = st.companies[cid];
                var v = st.visibility[cid] || { mode: 'all', seller_ids: [] };
                var vis = v.mode === 'all' ? 'Todos os vendedores'
                  : v.mode === 'own' ? 'Somente o próprio — sem vínculo, verá ZERO'
                  : v.seller_ids.length + ' vendedor(es) selecionado(s)';
                return '<div class="pv-co" style="margin-top:10px"><strong>' + esc(companyName(cid)) + '</strong>' +
                  (c.is_default ? ' <span class="admin-tag">padrão</span>' : '') +
                  '<div class="admin-small" style="margin-top:4px">Perfil: <strong>' + esc(c.role) + '</strong> · Canais: ' +
                  (c.can_varejo ? 'Varejo' : '') + (c.can_varejo && c.can_licitacao ? ' + ' : '') + (c.can_licitacao ? 'Licitação' : '') +
                  '<br>Visibilidade: ' + esc(vis) + '</div></div>';
              }).join('');
          }

          // ── Validação por etapa (o backend revalida tudo) ───────────────────
          function validate() {
            var errs = [];
            if (step === 1) {
              if (!st.name.trim()) errs.push('Informe o nome completo.');
              if (!st.username.trim()) errs.push('Informe o nome de usuário.');
              else if (!/^[a-z0-9._-]{3,32}$/.test(st.username.trim().toLowerCase())) errs.push('Usuário: 3 a 32 caracteres (letras, números, . _ -).');
              if (st.email && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(st.email)) errs.push('E-mail inválido.');
              if (st.password.length < 8) errs.push('A senha inicial precisa de ao menos 8 caracteres.');
            }
            if (step === 2) {
              var sel = selectedCompanies();
              if (!sel.length) errs.push('Selecione ao menos uma empresa.');
              else if (!sel.some(function (c) { return st.companies[c].is_default; })) errs.push('Escolha uma empresa padrão.');
            }
            if (step === 3) {
              selectedCompanies().forEach(function (cid) {
                var v = st.visibility[cid];
                if (v && v.mode === 'selected' && !v.seller_ids.length) {
                  errs.push(companyName(cid) + ': no modo Personalizado, escolha ao menos um vendedor.');
                }
              });
            }
            return errs;
          }

          // ── Consistência entre etapas ───────────────────────────────────────
          function reconcile() {
            var sel = selectedCompanies();
            // Empresa removida na etapa 2 some da 3 — nada de config órfã.
            Object.keys(st.visibility).forEach(function (cid) {
              if (sel.indexOf(Number(cid)) < 0) delete st.visibility[cid];
            });
            sel.forEach(function (cid) {
              if (!st.visibility[cid]) st.visibility[cid] = { mode: 'all', seller_ids: [] };
            });
            // Padrão precisa existir e ser único.
            var defs = sel.filter(function (c) { return st.companies[c].is_default; });
            if (defs.length > 1) {
              sel.forEach(function (c, i) { st.companies[c].is_default = (c === defs[0]); });
            }
          }

          // ── Envio (única requisição) ────────────────────────────────────────
          function submit() {
            var payload = {
              csrf_token: csrf(), nonce: nonce,
              name: st.name.trim(), username: st.username.trim().toLowerCase(),
              email: st.email.trim(), password: st.password, is_active: st.is_active,
              companies: selectedCompanies().map(function (cid) {
                var c = st.companies[cid];
                return { company_id: cid, role: c.role, can_varejo: c.can_varejo,
                         can_licitacao: c.can_licitacao, is_default: !!c.is_default };
              }),
              visibility: {}
            };
            selectedCompanies().forEach(function (cid) {
              var v = st.visibility[cid] || { mode: 'all', seller_ids: [] };
              payload.visibility[String(cid)] = { mode: v.mode, seller_ids: v.seller_ids };
            });

            // Duplo clique: trava imediata + nonce de uso único no servidor.
            nextBtn.disabled = true;
            nextBtn.setAttribute('aria-busy', 'true');
            clearError();

            // Token também no header: corpo JSON não popula $_POST no PHP, e o
            // guard de CSRF do bootstrap lê header ou $_POST. Só no corpo, o
            // provisionamento morria com 419 antes de chegar ao endpoint.
            fetch('api/admin_provision_user.php', {
              method: 'POST', credentials: 'same-origin',
              headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrf() },
              body: JSON.stringify(payload)
            })
              .then(function (r) { return r.json().then(function (d) { return { status: r.status, d: d }; }); })
              .then(function (res) {
                nextBtn.disabled = false;
                nextBtn.removeAttribute('aria-busy');
                if (res.d && res.d.next_nonce) nonce = res.d.next_nonce;

                if (res.d && res.d.ok) { success(res.d); return; }
                var list = [];
                if (res.d && res.d.errors) {
                  Object.keys(res.d.errors).forEach(function (k) { list.push(res.d.errors[k]); });
                }
                // Erro NÃO limpa o formulário — o admin corrige e reenvia.
                showError((res.d && res.d.message) || 'O usuário não foi criado.', list);
              })
              .catch(function () {
                nextBtn.disabled = false;
                nextBtn.removeAttribute('aria-busy');
                showError('Falha de rede. O usuário não foi criado.');
              });
          }

          function success(d) {
            done = true;
            syncSteps();
            backBtn.hidden = true;
            var s = d.summary || {};
            bodyEl.innerHTML =
              '<div class="pv-success"><span class="material-symbols-rounded">check_circle</span>' +
              '<h3 style="margin:6px 0 4px">Usuário criado com sucesso</h3>' +
              '<p class="admin-small">' + esc(d.user.name) + ' já possui acesso a ' + esc(s.default_company || '—') +
              (s.visible_sellers ? ' e visualiza ' + s.visible_sellers + ' vendedor(es).' : '.') + '</p>' +
              '<div style="display:flex;gap:8px;justify-content:center;margin-top:14px">' +
                '<button type="button" class="btn-primary mag-button-primary btn-sm" data-pv-see="' + d.user.id + '" data-pv-see-name="' + esc(d.user.name) + '">Ver acesso efetivo</button>' +
                '<button type="button" class="btn-secondary btn-sm" data-pv-goto>Ir para usuários</button>' +
              '</div></div>';
          }

          // ── Abrir / fechar (modal verdadeiro: foco preso, Esc confirma) ─────
          function open(btn) {
            opener = btn; step = 1; done = false; st = blank(); clearError();
            modal.hidden = false;
            bodyEl.innerHTML = '<p class="admin-small" style="text-align:center;padding:24px">Carregando empresas…</p>';
            syncSteps();
            fetch('api/seller_visibility.php', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
              .then(function (r) { return r.json(); })
              .then(function (d) {
                if (!d || !d.ok) { showError(d && d.message || 'Falha ao carregar empresas.'); return; }
                REF = d; render();
              })
              .catch(function () { showError('Falha ao carregar empresas.'); });
          }

          function reallyClose() {
            modal.hidden = true;
            discardModal.hidden = true;
            if (opener && opener.isConnected) opener.focus();
            opener = null; st = null;
          }
          function tryClose() {
            if (!done && dirty()) {
              discardModal.hidden = false;
              discardModal.querySelector('[data-pv-keep]').focus();  // menos destrutiva
              return;
            }
            reallyClose();
          }

          // Focus trap: o assistente bloqueia a Administração atrás dele.
          modal.addEventListener('keydown', function (ev) {
            if (ev.key !== 'Tab') return;
            var f = modal.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea');
            if (!f.length) return;
            var first = f[0], last = f[f.length - 1];
            if (ev.shiftKey && document.activeElement === first) { ev.preventDefault(); last.focus(); }
            else if (!ev.shiftKey && document.activeElement === last) { ev.preventDefault(); first.focus(); }
          });

          document.addEventListener('keydown', function (ev) {
            if (ev.key !== 'Escape') return;
            if (!discardModal.hidden) { discardModal.hidden = true; return; }
            if (!modal.hidden) tryClose();
          });

          // ── Eventos ─────────────────────────────────────────────────────────
          document.addEventListener('click', function (ev) {
            var t = ev.target;
            var openBtn = t.closest && t.closest('[data-provision-open]');
            if (openBtn) { open(openBtn); return; }
            if (modal.hidden && discardModal.hidden) return;

            if (t.closest && t.closest('[data-pv-close]')) { tryClose(); return; }
            if (t.closest && t.closest('[data-pv-keep]')) { discardModal.hidden = true; return; }
            if (t.closest && t.closest('[data-pv-discard]')) { reallyClose(); return; }
            if (t.closest && t.closest('[data-pv-goto]')) { window.location.reload(); return; }

            var see = t.closest && t.closest('[data-pv-see]');
            if (see) {
              // Conecta com o painel de Acesso Efetivo já entregue.
              var uid = see.getAttribute('data-pv-see'), nm = see.getAttribute('data-pv-see-name');
              reallyClose();
              var proxy = document.createElement('button');
              proxy.setAttribute('data-effective-access', uid);
              proxy.setAttribute('data-effective-name', nm);
              document.body.appendChild(proxy); proxy.click(); proxy.remove();
              return;
            }

            if (t.closest && t.closest('[data-pv-back]')) {
              if (step > 1) { step--; render(); }
              return;
            }
            if (t.closest && t.closest('[data-pv-next]')) {
              reconcile();
              var errs = validate();
              if (errs.length) { showError('Corrija antes de continuar:', errs); return; }
              if (step === 4) { submit(); return; }
              step++; reconcile(); render();
              return;
            }
          });

          modal.addEventListener('input', function (ev) {
            var f = ev.target.getAttribute && ev.target.getAttribute('data-pv-f');
            if (f && st) { st[f] = ev.target.type === 'checkbox' ? ev.target.checked : ev.target.value; }
          });

          modal.addEventListener('change', function (ev) {
            if (!st) return;
            var el = ev.target, cid;

            cid = el.getAttribute('data-pv-co');
            if (cid) {
              if (el.checked) st.companies[cid] = { role: 'viewer', can_varejo: true, can_licitacao: false, is_default: false };
              else { delete st.companies[cid]; delete st.visibility[cid]; }
              reconcile(); render(); return;
            }
            cid = el.getAttribute('data-pv-role');
            if (cid && st.companies[cid]) { st.companies[cid].role = el.value; return; }
            cid = el.getAttribute('data-pv-def');
            if (cid) {
              selectedCompanies().forEach(function (c) { st.companies[c].is_default = (String(c) === String(cid)); });
              return;
            }
            if (el.getAttribute('data-pv-ch')) {
              cid = el.getAttribute('data-cid');
              if (st.companies[cid]) st.companies[cid][el.getAttribute('data-pv-ch') === 'varejo' ? 'can_varejo' : 'can_licitacao'] = el.checked;
              return;
            }
            cid = el.getAttribute('data-pv-mode');
            if (cid) {
              st.visibility[cid] = { mode: el.value, seller_ids: el.value === 'selected' ? (st.visibility[cid] || {}).seller_ids || [] : [] };
              render(); return;
            }
            cid = el.getAttribute('data-pv-sell');
            if (cid) {
              var v = st.visibility[cid] || { mode: 'selected', seller_ids: [] };
              var i = v.seller_ids.indexOf(el.value);
              if (el.checked && i < 0) v.seller_ids.push(el.value);
              if (!el.checked && i >= 0) v.seller_ids.splice(i, 1);
              st.visibility[cid] = v;
            }
          });
        })();
        </script>

        <script>
        (function () {
          var modal = document.getElementById('modal-effective-access');
          if (!modal) return;
          var body = modal.querySelector('[data-ea-body]');
          var nameEl = modal.querySelector('[data-ea-user]');
          var opener = null;

          function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
          function icon(ok) {
            return '<span class="material-symbols-rounded ' + (ok ? 'ea-yes' : 'ea-no') + '">' +
              (ok ? 'check_circle' : 'cancel') + '</span>';
          }

          function render(d) {
            if (!d || !d.ok) {
              body.innerHTML = '<p class="admin-small">' + esc((d && d.message) || 'Falha ao carregar.') + '</p>';
              return;
            }
            if (!d.companies || !d.companies.length) {
              body.innerHTML = '<p class="admin-small">' + esc(d.note || 'Sem acesso a nenhuma empresa.') + '</p>';
              return;
            }
            body.innerHTML = d.companies.map(function (co) {
              var mods = co.modules.map(function (m) {
                return '<div class="ea-item">' + icon(m.allowed) + '<span>' + esc(m.label) + '</span></div>';
              }).join('');
              var visible = co.sellers.filter(function (s) { return s.visible; });
              var hidden = co.sellers.filter(function (s) { return !s.visible; });
              var sellersHtml = co.seller_scope.restricted
                ? visible.map(function (s) { return '<div class="ea-item">' + icon(true) + '<span>' + esc(s.name) + '</span></div>'; }).join('') +
                  hidden.slice(0, 6).map(function (s) { return '<div class="ea-item">' + icon(false) + '<span>' + esc(s.name) + '</span></div>'; }).join('') +
                  (hidden.length > 6 ? '<div class="ea-item ea-no"><span>e mais ' + (hidden.length - 6) + ' oculto(s)</span></div>' : '')
                : '<div class="ea-item">' + icon(true) + '<span>Todos os ' + co.sellers.length + ' vendedores da empresa</span></div>';

              return '' +
                '<div class="ea-company">' +
                  '<div class="ea-company__head">' +
                    '<span class="ea-company__name">' + esc(co.name || co.slug) + '</span>' +
                    '<span class="ea-badge' + (co.seller_scope.restricted ? ' ea-badge--restricted' : '') + '">' +
                      esc(co.seller_scope.mode_label) + '</span>' +
                  '</div>' +
                  '<div class="ea-origin">Papel <strong>' + esc(co.role) + '</strong> &middot; ' + esc(co.role_source) +
                    ' &middot; canais: ' + (co.channels.varejo ? 'Varejo' : '') +
                    (co.channels.varejo && co.channels.licitacao ? ' + ' : '') +
                    (co.channels.licitacao ? 'Licita&ccedil;&atilde;o' : '') +
                    (!co.channels.varejo && !co.channels.licitacao ? 'nenhum (n&atilde;o v&ecirc; dado)' : '') + '</div>' +
                  '<p class="ea-sub">M&oacute;dulos</p><div class="ea-grid">' + mods + '</div>' +
                  '<p class="ea-sub">Vendedores</p><div class="ea-grid">' + sellersHtml + '</div>' +
                '</div>';
            }).join('');
          }

          function close() {
            modal.hidden = true;
            if (opener && opener.isConnected) opener.focus();
            opener = null;
          }

          document.addEventListener('click', function (ev) {
            var btn = ev.target.closest && ev.target.closest('[data-effective-access]');
            if (btn) {
              opener = btn;
              nameEl.textContent = btn.getAttribute('data-effective-name') || '';
              body.innerHTML = '<p class="admin-small" style="text-align:center;padding:20px">Calculando&hellip;</p>';
              modal.hidden = false;
              modal.querySelector('[data-admin-modal-close]').focus();
              fetch('api/admin_effective_access.php?user_id=' +
                    encodeURIComponent(btn.getAttribute('data-effective-access')),
                    { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(render)
                .catch(function () { body.innerHTML = '<p class="admin-small">Falha ao carregar.</p>'; });
              return;
            }
            if (ev.target.closest && ev.target.closest('#modal-effective-access [data-admin-modal-close]')) close();
            if (ev.target === modal) close();
          });

          document.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape' && !modal.hidden) close();
          });
        })();
        </script>

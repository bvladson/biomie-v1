        <section class="admin-section <?php echo $activeTab === 'users' ? 'active' : ''; ?>" data-admin-tab-panel="users">
          <div class="admin-panel">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:16px">
              <div>
                <h2 style="margin:0">Usu&aacute;rios</h2>
                <p class="admin-small" style="margin-top:4px">Acessos, perfis e status dos usu&aacute;rios cadastrados.</p>
              </div>
              <?php if ($can['users'] ?? false): ?>
                <button class="btn-primary mag-button-primary" type="button" data-admin-modal-open="modal-new-user">
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
        </section>

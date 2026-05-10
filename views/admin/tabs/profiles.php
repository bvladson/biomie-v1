        <section class="admin-section <?php echo $activeTab === 'profiles' ? 'active' : ''; ?>" data-admin-tab-panel="profiles">
          <div class="admin-panel">
            <div class="admin-actions" style="justify-content:space-between;margin-bottom:14px">
              <h2 style="margin:0">Perfis padr&atilde;o</h2>
              <?php if ($can['users'] ?? false): ?>
                <form method="post">
                  <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>" />
                  <input type="hidden" name="action" value="sync_user_roles" />
                  <button class="btn-secondary mag-button-secondary" type="submit"><span class="material-symbols-rounded">sync</span>Sincronizar perfis</button>
                </form>
              <?php endif; ?>
            </div>
            <div class="admin-roles-grid">
              <?php foreach ($roles as $roleRow): ?>
                <?php
                  $roleKey = (string) ($roleRow['role_key'] ?? '');
                  $rolePermissions = $permissionMatrix[$roleKey] ?? [];
                ?>
                <article class="admin-diagnostic-card">
                  <span><?php echo $h($roleKey); ?></span>
                  <strong><?php echo $h($roleRow['name'] ?? $roleKey); ?></strong>
                  <p class="admin-small"><?php echo $h($roleRow['description'] ?? ''); ?></p>
                  <span class="admin-tag neutral"><?php echo count($rolePermissions); ?> permiss&atilde;o(&otilde;es)</span>
                </article>
              <?php endforeach; ?>
            </div>
          </div>
        </section>

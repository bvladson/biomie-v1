        <section class="admin-section <?php echo $activeTab === 'permissions' ? 'active' : ''; ?>" data-admin-tab-panel="permissions">
          <?php foreach ($roles as $roleRow): ?>
            <?php
              $roleKey = (string) ($roleRow['role_key'] ?? '');
              $enabled = array_flip($permissionMatrix[$roleKey] ?? []);
            ?>
            <div class="admin-panel">
              <h2><?php echo $h($roleRow['name'] ?? $roleKey); ?></h2>
              <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>" />
                <input type="hidden" name="action" value="update_role_permissions" />
                <input type="hidden" name="role_key" value="<?php echo $h($roleKey); ?>" />
                <div class="admin-permission-grid">
                  <?php foreach ($permissions as $permissionRow): ?>
                    <?php $permissionKey = (string) ($permissionRow['permission_key'] ?? ''); ?>
                    <div class="admin-permission-item">
                      <label>
                        <input type="checkbox" name="permission_keys[]" value="<?php echo $h($permissionKey); ?>" <?php echo isset($enabled[$permissionKey]) ? 'checked' : ''; ?> <?php echo $roleKey === 'admin' ? 'disabled' : ''; ?> />
                        <span>
                          <?php echo $h($permissionRow['name'] ?? $permissionKey); ?><br />
                          <small class="admin-small"><?php echo $h($permissionKey); ?></small>
                        </span>
                      </label>
                    </div>
                  <?php endforeach; ?>
                </div>
                <?php if ($can['users'] ?? false): ?>
                  <button class="btn-primary mag-button-primary" type="submit" style="margin-top:12px"><span class="material-symbols-rounded">save</span>Salvar permiss&otilde;es</button>
                <?php endif; ?>
              </form>
            </div>
          <?php endforeach; ?>
        </section>

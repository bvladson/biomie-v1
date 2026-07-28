        <section class="admin-section <?php echo $activeTab === 'logs' ? 'active' : ''; ?>" data-admin-tab-panel="logs">
          <div class="admin-panel">
            <h2><span class="material-symbols-rounded">receipt_long</span>Logs administrativos</h2>
            <div class="admin-log-filters">
              <select id="adminLogFilterType" class="admin-select" style="max-width:200px">
                <option value="">Todos os eventos</option>
                <option value="user_create">Criar usuário</option>
                <option value="user_update">Atualizar usuário</option>
                <option value="user_activate">Ativar usuário</option>
                <option value="user_deactivate">Desativar usuário</option>
                <option value="password_reset">Reset de senha</option>
                <option value="role_assign">Papel atribuído</option>
                <option value="permission_update">Permissões atualizadas</option>
                <option value="migration_run">Migration executada</option>
                <option value="console_command">Comando no console</option>
                <option value="cache_clear">Cache limpo</option>
                <option value="sync_triggered">Sync acionado</option>
              </select>
              <input type="text" id="adminLogFilterUser" class="admin-input" placeholder="Filtrar por usuário…" style="max-width:200px" />
              <input type="date" id="adminLogFilterDate" class="admin-input" style="max-width:160px" />
              <button type="button" class="admin-row-btn admin-log-filter-clear" id="adminLogFilterClear">
                <span class="material-symbols-rounded">close</span>Limpar
              </button>
              <span id="adminLogCount" class="admin-small" style="margin-left:auto"></span>
            </div>
            <div class="admin-table-wrap">
              <table class="admin-table">
                <thead><tr><th>Quando</th><th>Evento</th><th>Ator</th><th>Alvo</th><th>Detalhes</th></tr></thead>
                <tbody id="adminLogsTable">
                <?php if ($adminLogs === []): ?>
                  <tr><td colspan="5" class="admin-small" style="padding:20px;text-align:center">Nenhum log administrativo registrado.</td></tr>
                <?php endif; ?>
                <?php foreach ($adminLogs as $logRow): ?>
                  <tr data-log-type="<?php echo $h($logRow['event_type'] ?? ''); ?>"
                      data-log-user="<?php echo $h(strtolower(($logRow['actor_full_name'] ?? '') . ' ' . ($logRow['actor_username'] ?? ''))); ?>"
                      data-log-date="<?php echo $h(substr($logRow['created_at'] ?? '', 0, 10)); ?>">
                    <td class="admin-small" style="white-space:nowrap"><?php echo $h($logRow['created_at'] ?? ''); ?></td>
                    <td>
                      <?php
                        $evType = $logRow['event_type'] ?? '';
                        $evIcon = match(true) {
                          str_contains($evType, 'user')       => 'person',
                          str_contains($evType, 'password')   => 'lock',
                          str_contains($evType, 'role')       => 'badge',
                          str_contains($evType, 'permission') => 'key',
                          str_contains($evType, 'migration')  => 'database',
                          str_contains($evType, 'console')    => 'terminal',
                          str_contains($evType, 'cache')      => 'cached',
                          str_contains($evType, 'sync')       => 'sync',
                          default                             => 'receipt_long',
                        };
                      ?>
                      <span class="admin-tag neutral" style="gap:5px">
                        <span class="material-symbols-rounded" style="font-size:13px"><?php echo $evIcon; ?></span>
                        <?php echo $h($evType); ?>
                      </span>
                    </td>
                    <td><?php echo $h(($logRow['actor_full_name'] ?? '') !== '' ? $logRow['actor_full_name'] : ($logRow['actor_username'] ?? '-')); ?></td>
                    <td class="admin-small"><?php echo $h(($logRow['target_full_name'] ?? '') !== '' ? $logRow['target_full_name'] : ($logRow['target_username'] ?? '-')); ?></td>
                    <td class="admin-small" style="max-width:320px;white-space:normal"><?php echo $h($logRow['details_json'] ?? '-'); ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </section>

        <section class="admin-section <?php echo $activeTab === 'logs' ? 'active' : ''; ?>" data-admin-tab-panel="logs">
          <div class="admin-panel">
            <h2>Logs administrativos</h2>
            <div class="admin-table-wrap">
              <table class="admin-table">
                <thead><tr><th>Quando</th><th>Evento</th><th>Ator</th><th>Alvo</th><th>Rota</th><th>Detalhes</th></tr></thead>
                <tbody id="adminLogsTable" data-empty-title="Sem logs administrativos" data-empty-message="Nenhum evento administrativo foi registrado ainda." data-empty-icon="receipt_long">
                <?php if ($adminLogs === []): ?>
                  <tr><td colspan="6">Sem logs administrativos.</td></tr>
                <?php endif; ?>
                <?php foreach ($adminLogs as $logRow): ?>
                  <tr>
                    <td><?php echo $h($logRow['created_at'] ?? ''); ?></td>
                    <td><span class="admin-tag neutral"><?php echo $h($logRow['event_type'] ?? ''); ?></span></td>
                    <td><?php echo $h(($logRow['actor_full_name'] ?? '') !== '' ? $logRow['actor_full_name'] : ($logRow['actor_username'] ?? '-')); ?></td>
                    <td><?php echo $h(($logRow['target_full_name'] ?? '') !== '' ? $logRow['target_full_name'] : ($logRow['target_username'] ?? '-')); ?></td>
                    <td><?php echo $h($logRow['route'] ?? '-'); ?></td>
                    <td><span class="admin-small"><?php echo $h($logRow['details_json'] ?? '-'); ?></span></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </section>

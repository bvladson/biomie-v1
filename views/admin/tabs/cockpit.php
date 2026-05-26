        <?php if ($can['operations'] ?? false): ?>
        <section class="admin-section <?php echo $activeTab === 'cockpit' ? 'active' : ''; ?>" data-admin-tab-panel="cockpit">
          <div class="admin-panel">
            <div class="admin-ops-head">
              <div>
                <h2>Cockpit Interno</h2>
                <p class="admin-small">Hub executivo t&eacute;cnico: status sem duplicar as telas de sa&uacute;de, integra&ccedil;&otilde;es e cat&aacute;logo.</p>
              </div>
              <div class="admin-actions">
                <button class="admin-row-btn" type="button" data-admin-refresh="cockpit"><span class="material-symbols-rounded">refresh</span>Atualizar</button>
              </div>
            </div>
            <div class="admin-ops-link-grid" style="margin-bottom:14px">
              <a class="admin-ops-link" href="admin.php?tab=system-health"><span class="material-symbols-rounded">monitor_heart</span><div><strong>Sa&uacute;de</strong><p class="admin-small">Checks cr&iacute;ticos e warnings.</p></div></a>
              <a class="admin-ops-link" href="admin.php?tab=integrations"><span class="material-symbols-rounded">hub</span><div><strong>Integra&ccedil;&otilde;es</strong><p class="admin-small">Fila, sync e cron.</p></div></a>
              <a class="admin-ops-link" href="admin.php?tab=metrics"><span class="material-symbols-rounded">query_stats</span><div><strong>KPIs</strong><p class="admin-small">Cat&aacute;logo sem&acirc;ntico.</p></div></a>
              <a class="admin-ops-link" href="admin.php?tab=console"><span class="material-symbols-rounded">terminal</span><div><strong>Console</strong><p class="admin-small">Comandos auditados.</p></div></a>
            </div>
            <div id="adminCockpitSummary" class="admin-ops-grid">
              <article class="admin-ops-card"><span>Status</span><strong>Carregando</strong><p class="admin-small">Consultando api/cockpit_summary.php.</p></article>
            </div>
            <details class="admin-ops-details">
              <summary>Detalhes do cockpit</summary>
              <pre id="adminCockpitRaw" class="admin-ops-pre">Carregando...</pre>
            </details>
          </div>
        </section>
        <?php endif; ?>

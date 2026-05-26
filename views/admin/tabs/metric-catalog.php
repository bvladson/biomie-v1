        <?php if ($can['operations'] ?? false): ?>
        <section class="admin-section <?php echo $activeTab === 'metrics' ? 'active' : ''; ?>" data-admin-tab-panel="metrics">
          <div class="admin-panel">
            <div class="admin-ops-head">
              <div>
                <h2>Cat&aacute;logo de KPIs</h2>
                <p class="admin-small">M&eacute;tricas oficiais por dom&iacute;nio, fonte e status. Use a busca para encontrar KPIs sem abrir uma p&aacute;gina t&eacute;cnica separada.</p>
              </div>
              <div class="admin-actions">
                <button class="admin-row-btn" type="button" data-admin-refresh="metrics"><span class="material-symbols-rounded">refresh</span>Atualizar</button>
              </div>
            </div>
            <div id="adminMetricsSummary" class="admin-ops-grid">
              <article class="admin-ops-card"><span>KPIs</span><strong>Carregando</strong><p class="admin-small">Consultando api/metric_catalog.php.</p></article>
            </div>
            <div class="admin-ops-toolbar">
              <input id="adminMetricsSearch" class="admin-input admin-ops-search" type="search" placeholder="Buscar KPI, dominio ou fonte..." />
            </div>
            <div class="admin-table-wrap">
              <table class="admin-table admin-table-compact">
                <thead><tr><th>KPI</th><th>Dom&iacute;nio</th><th>Status</th><th>Fonte</th><th>Owner</th></tr></thead>
                <tbody id="adminMetricsRows">
                  <tr><td colspan="5" class="admin-small">Carregando KPIs...</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </section>
        <?php endif; ?>

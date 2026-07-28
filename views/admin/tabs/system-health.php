        <?php if ($can['operations'] ?? false): ?>
        <section class="admin-section <?php echo $activeTab === 'system-health' ? 'active' : ''; ?>" data-admin-tab-panel="system-health">
          <div class="admin-panel">
            <div class="admin-ops-head">
              <div>
                <h2>Sa&uacute;de do Sistema</h2>
                <p class="admin-small">Checks t&eacute;cnicos consolidados por severidade. Detalhes longos ficam recolhidos para manter a tela acion&aacute;vel.</p>
              </div>
              <div class="admin-actions">
                <button class="admin-row-btn" type="button" data-admin-refresh="system-health"><span class="material-symbols-rounded">refresh</span>Atualizar</button>
              </div>
            </div>
            <div id="adminSystemHealthSummary" class="admin-ops-grid">
              <article class="admin-ops-card"><span>Status</span><strong>Carregando</strong><p class="admin-small">Consultando api/system_health.php.</p></article>
            </div>
            <div class="admin-table-wrap">
              <table class="admin-table admin-table-compact">
                <thead><tr><th>Check</th><th>Status</th><th>Mensagem</th><th>Detalhes</th></tr></thead>
                <tbody id="adminSystemHealthRows">
                  <tr><td colspan="4" class="admin-small">Carregando checks...</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </section>
        <?php endif; ?>

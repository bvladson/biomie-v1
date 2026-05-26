        <?php if ($can['operations'] ?? false): ?>
        <section class="admin-section <?php echo $activeTab === 'integrations' ? 'active' : ''; ?>" data-admin-tab-panel="integrations">
          <div class="admin-panel">
            <div class="admin-ops-head">
              <div>
                <h2>Integra&ccedil;&otilde;es Omie</h2>
                <p class="admin-small">Resumo operacional de sync, fila e comandos esperados. Opera&ccedil;&otilde;es sens&iacute;veis continuam no console auditado.</p>
              </div>
              <div class="admin-actions">
                <button class="admin-row-btn" type="button" data-admin-refresh="integrations"><span class="material-symbols-rounded">refresh</span>Atualizar</button>
              </div>
            </div>
            <div id="adminIntegrationsSummary" class="admin-ops-grid">
              <article class="admin-ops-card"><span>Status</span><strong>Carregando</strong><p class="admin-small">Consultando api/omie_status.php.</p></article>
            </div>
            <div class="admin-ops-grid two">
              <div class="admin-ops-card">
                <span>Comandos Hostinger/hPanel</span>
                <strong>Wrappers oficiais</strong>
                <details class="admin-ops-details" open>
                  <summary>Ver comandos</summary>
                  <pre class="admin-ops-pre">*/5 * * * * /bin/sh /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/cron/hostinger/worker.sh
*/15 * * * * /bin/sh /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/cron/hostinger/sync_commercial.sh
*/15 * * * * /bin/sh /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/cron/hostinger/sync_finance.sh
0,30 * * * * /bin/sh /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/cron/hostinger/materialize.sh</pre>
                </details>
              </div>
              <div class="admin-ops-card">
                <span>Logs</span>
                <strong>storage/logs/cron</strong>
                <p class="admin-small">Cada wrapper grava in&iacute;cio, fim e exit code em arquivo pr&oacute;prio, com lock para evitar overlap.</p>
              </div>
            </div>
            <details class="admin-ops-details">
              <summary>Resposta t&eacute;cnica da integra&ccedil;&atilde;o</summary>
              <pre id="adminIntegrationsRaw" class="admin-ops-pre">Carregando...</pre>
            </details>
          </div>
        </section>
        <?php endif; ?>

        <?php if ($can['operations'] ?? false): ?>
        <section class="admin-section <?php echo $activeTab === 'integrations' ? 'active' : ''; ?>" data-admin-tab-panel="integrations">
          <div class="admin-panel">
            <div class="admin-ops-head">
              <div>
                <h2><span class="material-symbols-rounded">hub</span>Integra&ccedil;&otilde;es Omie</h2>
                <p class="admin-small">Status completo de sync, origem can&ocirc;nica, Gold, fila, rate limit, comandos e tabelas. Opera&ccedil;&otilde;es sens&iacute;veis continuam no console auditado.</p>
              </div>
              <div class="admin-actions">
                <button class="admin-row-btn" type="button" id="btnTestOmieConnection"><span class="material-symbols-rounded">wifi_tethering</span>Testar conex&atilde;o</button>
                <button class="admin-row-btn" type="button" data-admin-refresh="integrations"><span class="material-symbols-rounded">refresh</span>Atualizar</button>
                <a class="admin-row-btn" href="admin.php?tab=console"><span class="material-symbols-rounded">terminal</span>Console</a>
              </div>
              <div id="omieTestResult" class="cc-action-feedback" hidden style="margin-top:10px"></div>
            </div>
            <div id="adminIntegrationsSummary" class="admin-ops-grid">
              <article class="admin-ops-card"><span>Status</span><strong>Carregando</strong><p class="admin-small">Consultando api/omie_status.php.</p></article>
            </div>

            <?php if ($can['webhooks'] ?? false): ?>
            <section class="admin-ops-block">
              <div class="admin-ops-section-title">
                <div>
                  <h3><span class="material-symbols-rounded">webhook</span>Webhooks por empresa</h3>
                  <p class="admin-small">Copie uma URL por empresa e cadastre no Omie. O token &eacute; separado e pode ser rotacionado sem alterar as demais empresas.</p>
                </div>
                <button class="admin-row-btn" type="button" id="btnRotateAllWebhooks">
                  <span class="material-symbols-rounded">autorenew</span>Gerar/rotacionar todas
                </button>
              </div>
              <div id="adminWebhookFeedback" class="cc-action-feedback" hidden></div>
              <div class="admin-table-wrap">
                <table class="admin-table admin-table-compact">
                  <thead><tr><th>Empresa</th><th>Status</th><th>Endpoint para o Omie</th><th>&Uacute;ltimo recebimento</th><th>A&ccedil;&otilde;es</th></tr></thead>
                  <tbody id="adminWebhookRows"><tr><td colspan="5" class="admin-small">Carregando endpoints...</td></tr></tbody>
                </table>
              </div>
              <p class="admin-small" style="margin-top:10px">Formato: <code>https://magmoveiscorporativos.com.br/biomie/webhooks/omie/empresa/TOKEN</code>. A URL aceita somente POST.</p>
            </section>
            <?php endif; ?>

            <section class="admin-ops-block">
              <div class="admin-ops-section-title">
                <h3>Status da integra&ccedil;&atilde;o</h3>
                <p class="admin-small">Sinais executivos de API, origem, Gold, fila e credenciais.</p>
              </div>
              <div id="adminIntegrationStatusCards" class="admin-ops-grid"></div>
            </section>

            <section class="admin-ops-block">
              <div class="admin-ops-section-title">
                <h3>Base sincronizada</h3>
                <p class="admin-small">Bronze/can&ocirc;nico/dimens&otilde;es que sustentam os pain&eacute;is.</p>
              </div>
              <div id="adminIntegrationDataCards" class="admin-ops-grid"></div>
            </section>

            <section class="admin-ops-block">
              <div class="admin-ops-section-title">
                <h3>Opera&ccedil;&atilde;o por dom&iacute;nio</h3>
                <p class="admin-small">Delta/backfill esperado por dom&iacute;nio Omie.</p>
              </div>
              <div class="admin-table-wrap">
                <table class="admin-table admin-table-compact">
                  <thead><tr><th>Dom&iacute;nio</th><th>Status</th><th>&Uacute;ltimo sucesso</th><th>Idade</th><th>Comando</th><th>Erro</th></tr></thead>
                  <tbody id="adminIntegrationDomainRows"><tr><td colspan="6" class="admin-small">Carregando...</td></tr></tbody>
                </table>
              </div>
            </section>

            <section class="admin-ops-block">
              <div class="admin-ops-section-title">
                <h3>Rate limit, cache e fila</h3>
                <p class="admin-small">Limites Omie, cache curto e worker/fila.</p>
              </div>
              <div id="adminIntegrationRateQueue" class="admin-ops-grid"></div>
            </section>

            <section class="admin-ops-block">
              <div class="admin-ops-section-title">
                <h3>Wrappers cron Hostinger</h3>
                <p class="admin-small">Comandos configurados no hPanel e &uacute;ltimos logs locais.</p>
              </div>
              <div class="admin-table-wrap">
                <table class="admin-table admin-table-compact">
                  <thead><tr><th>Rotina</th><th>Status</th><th>Frequ&ecirc;ncia</th><th>&Uacute;ltimo log</th><th>Comando</th><th>Detalhes</th></tr></thead>
                  <tbody id="adminIntegrationCronRows"><tr><td colspan="6" class="admin-small">Carregando...</td></tr></tbody>
                </table>
              </div>
            </section>

            <div class="admin-ops-grid two">
              <section class="admin-ops-block">
                <div class="admin-ops-section-title">
                  <h3>A&ccedil;&otilde;es recomendadas</h3>
                  <p class="admin-small">Prioridade operacional calculada pelos checks.</p>
                </div>
                <div id="adminIntegrationActions" class="admin-ops-list"></div>
              </section>
              <section class="admin-ops-block">
                <div class="admin-ops-section-title">
                  <h3>&Uacute;ltimas sincroniza&ccedil;&otilde;es</h3>
                  <p class="admin-small">Execu&ccedil;&otilde;es recentes do pipeline.</p>
                </div>
                <div class="admin-table-wrap">
                  <table class="admin-table admin-table-compact">
                    <thead><tr><th>Dom&iacute;nio</th><th>Tipo</th><th>Status</th><th>In&iacute;cio</th><th>Itens</th></tr></thead>
                    <tbody id="adminIntegrationRecentRows"><tr><td colspan="5" class="admin-small">Carregando...</td></tr></tbody>
                  </table>
                </div>
              </section>
            </div>

            <section class="admin-ops-block">
              <details class="admin-ops-details">
                <summary>Status das tabelas bronze / can&ocirc;nico / gold</summary>
                <div id="adminIntegrationTables" class="admin-table-wrap admin-ops-details-body"></div>
              </details>
            </section>

            <details class="admin-ops-details">
              <summary>Resposta t&eacute;cnica da integra&ccedil;&atilde;o</summary>
              <pre id="adminIntegrationsRaw" class="admin-ops-pre">Carregando...</pre>
            </details>
          </div>
        </section>
        <?php endif; ?>

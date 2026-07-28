        <?php if ($can['operations'] ?? false): ?>
        <section class="admin-section <?php echo $activeTab === 'cockpit' ? 'active' : ''; ?>" data-admin-tab-panel="cockpit">

          <!-- Hero Status Bar -->
          <div class="cc-hero">
            <div class="cc-hero__status">
              <span class="cc-status-dot unknown" id="ccStatusDot"></span>
              <div>
                <p class="cc-hero__title" id="ccHeroLabel">Control Center</p>
                <p class="cc-hero__sub" id="ccHeroSub">Verificando status do sistema&hellip;</p>
              </div>
            </div>
            <div class="cc-hero__meta">
              <span class="admin-small" id="ccLastUpdated">—</span>
              <span class="cc-auto-badge"><span class="material-symbols-rounded" style="font-size:14px">autorenew</span>Auto&nbsp;60s</span>
              <button class="admin-row-btn" id="ccRefreshBtn" data-admin-refresh="cockpit">
                <span class="material-symbols-rounded">refresh</span>Atualizar
              </button>
            </div>
          </div>

          <!-- KPI Strip -->
          <div class="cc-kpi-strip" id="ccKpiStrip">
            <?php foreach (['Omie API','Sync','Gold','Fila','Erros'] as $kl): ?>
            <div class="cc-kpi unknown">
              <div class="cc-kpi__accent"></div>
              <span class="cc-kpi__icon material-symbols-rounded">hourglass_empty</span>
              <div class="cc-kpi__label"><?php echo $kl; ?></div>
              <div class="cc-kpi__value">—</div>
              <div class="cc-kpi__sub">verificando&hellip;</div>
            </div>
            <?php endforeach; ?>
          </div>

          <!-- Quick Actions -->
          <div class="cc-actions-panel">
            <h3 class="cc-section-title">
              <span class="material-symbols-rounded">bolt</span>
              A&ccedil;&otilde;es r&aacute;pidas
              <span class="admin-small cc-section-title__badges" style="margin-left:auto">Todas as opera&ccedil;&otilde;es s&atilde;o auditadas</span>
            </h3>
            <div class="cc-actions-grid">
              <button class="cc-action-btn" id="ccActionTestOmie" type="button">
                <div class="cc-action-btn__icon"><span class="material-symbols-rounded">wifi_tethering</span></div>
                <div>
                  <div class="cc-action-btn__label">Testar Omie</div>
                  <div class="cc-action-btn__desc">Verifica conex&atilde;o e lat&ecirc;ncia da API</div>
                </div>
              </button>
              <button class="cc-action-btn" id="ccActionSyncDelta" type="button">
                <div class="cc-action-btn__icon"><span class="material-symbols-rounded">sync</span></div>
                <div>
                  <div class="cc-action-btn__label">Sync Delta</div>
                  <div class="cc-action-btn__desc">Sincroniza comercial e financeiro</div>
                </div>
              </button>
              <button class="cc-action-btn" id="ccActionMaterialize" type="button">
                <div class="cc-action-btn__icon"><span class="material-symbols-rounded">layers</span></div>
                <div>
                  <div class="cc-action-btn__label">Materializar Gold</div>
                  <div class="cc-action-btn__desc">Reconstr&oacute;i todos os marts comerciais</div>
                </div>
              </button>
              <button class="cc-action-btn" id="ccActionClearCache" type="button">
                <div class="cc-action-btn__icon"><span class="material-symbols-rounded">cached</span></div>
                <div>
                  <div class="cc-action-btn__label">Limpar Cache</div>
                  <div class="cc-action-btn__desc">Remove cache de API e dashboard</div>
                </div>
              </button>
            </div>
            <div id="ccActionFeedback" class="cc-action-feedback" hidden></div>
          </div>

          <!-- Health + Recent em duas colunas -->
          <div class="cc-grid-two">
            <div class="cc-panel">
              <h3 class="cc-section-title">
                <span class="material-symbols-rounded">monitor_heart</span>
                Health Checks
                <div class="cc-health-badges cc-section-title__badges" id="ccHealthBadges"></div>
              </h3>
              <div id="ccHealthList" class="cc-health-list">
                <div class="cc-health-item neutral">
                  <span class="cc-health-icon material-symbols-rounded">hourglass_empty</span>
                  <div><div class="cc-health-label">Verificando checks&hellip;</div></div>
                </div>
              </div>
            </div>
            <div class="cc-panel">
              <h3 class="cc-section-title">
                <span class="material-symbols-rounded">history</span>
                Opera&ccedil;&otilde;es recentes
              </h3>
              <div id="ccRecentOps" class="cc-recent-list">
                <div class="cc-recent-item">
                  <span class="cc-recent-icon material-symbols-rounded">hourglass_empty</span>
                  <span class="cc-recent-label">Carregando&hellip;</span>
                </div>
              </div>
            </div>
          </div>

          <!-- Domain Operations -->
          <div class="cc-panel" style="margin-bottom:16px">
            <h3 class="cc-section-title">
              <span class="material-symbols-rounded">hub</span>
              Dom&iacute;nios Omie
              <span class="admin-small cc-section-title__badges" style="margin-left:auto">Sync por dom&iacute;nio</span>
            </h3>
            <div id="ccDomains" class="cc-domains">
              <div class="cc-domain-row">
                <span class="cc-domain-icon material-symbols-rounded">hourglass_empty</span>
                <div class="cc-domain-info">
                  <div class="cc-domain-name">Carregando dom&iacute;nios&hellip;</div>
                </div>
              </div>
            </div>
          </div>

          <!-- Raw técnico -->
          <details class="admin-ops-details">
            <summary>Dados t&eacute;cnicos brutos</summary>
            <pre id="ccRaw" class="admin-ops-pre">Aguardando carga&hellip;</pre>
          </details>

        </section>
        <?php endif; ?>

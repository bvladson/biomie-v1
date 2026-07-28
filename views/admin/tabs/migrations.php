        <section class="admin-section <?php echo $activeTab === 'migrations' ? 'active' : ''; ?>" data-admin-tab-panel="migrations">
          <?php
            $migSummary  = $migrationStatus['summary']     ?? [];
            $migDiag     = $migrationStatus['diagnostics'] ?? [];
            $migList     = $migrationStatus['migrations']  ?? [];
            $migError    = $migrationStatus['error']       ?? null;
            $migPending  = (int) ($migSummary['pending']   ?? 0);
            $hasPending  = $migPending > 0;
          ?>

          <div class="admin-panel" style="padding:12px 16px">
            <div style="display:flex;flex-wrap:wrap;gap:14px;align-items:center;font-size:.82rem;color:var(--mag-muted,var(--text-2))">
              <?php if (!empty($migDiag['db_name'])): ?>
                <span title="Banco de dados ativo"><strong>Banco:</strong> <?php echo $h($migDiag['db_name']); ?></span>
              <?php endif; ?>
              <?php if (!empty($migDiag['db_version'])): ?>
                <span title="Versao do servidor"><strong>Servidor:</strong> <?php echo $h($migDiag['db_version']); ?></span>
              <?php endif; ?>
              <?php if (!empty($migDiag['environment'])): ?>
                <span title="Ambiente de execucao"><strong>Ambiente:</strong> <?php echo $h($migDiag['environment']); ?></span>
              <?php endif; ?>
              <?php if (!empty($migDiag['php_version'])): ?>
                <span title="Versao PHP"><strong>PHP:</strong> <?php echo $h($migDiag['php_version']); ?></span>
              <?php endif; ?>
              <?php if (!empty($migDiag['migrations_table'])): ?>
                <span title="Tabela de controle de migrations"><strong>Tabela:</strong> <?php echo $h($migDiag['migrations_table']); ?></span>
              <?php endif; ?>
            </div>
          </div>

          <?php if ($migError !== null): ?>
            <div class="admin-msg error" style="margin-top:10px"><?php echo $h($migError); ?></div>
          <?php endif; ?>

          <div class="admin-panel" style="margin-top:10px">
            <div class="admin-actions" style="justify-content:space-between;margin-bottom:14px">
              <h2 style="margin:0">Migrations</h2>
              <?php if ($can['migrations'] ?? false): ?>
                <form method="post" onsubmit="return confirm('Esta acao ira alterar o banco de dados.\nDeseja executar as migrations pendentes?')">
                  <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>" />
                  <input type="hidden" name="action" value="run_migrations" />
                  <button class="btn-primary mag-button-primary" type="submit" <?php echo !$hasPending ? 'disabled title="Nenhuma migration pendente."' : ''; ?>>
                    <span class="material-symbols-rounded">play_arrow</span>Executar pendentes (<?php echo $migPending; ?>)
                  </button>
                </form>
              <?php endif; ?>
            </div>

            <div class="admin-diagnostics-grid" style="margin-bottom:14px">
              <article class="admin-diagnostic-card"><span>Descobertas</span><strong><?php echo (int) ($migSummary['discovered'] ?? 0); ?></strong></article>
              <article class="admin-diagnostic-card"><span>Aplicadas</span><strong style="color:var(--success-strong,#147a42)"><?php echo (int) ($migSummary['applied'] ?? 0); ?></strong></article>
              <article class="admin-diagnostic-card"><span>Pendentes</span><strong style="color:<?php echo $hasPending ? 'var(--warning-strong,#925500)' : 'inherit'; ?>"><?php echo $migPending; ?></strong></article>
              <?php if (($migSummary['rolled_back'] ?? 0) > 0): ?>
                <article class="admin-diagnostic-card"><span>Rolled back</span><strong style="color:var(--danger-strong,#96140a)"><?php echo (int) $migSummary['rolled_back']; ?></strong></article>
              <?php endif; ?>
              <?php if (($migSummary['connection_error'] ?? 0) > 0): ?>
                <article class="admin-diagnostic-card"><span>Erro de conexao</span><strong style="color:var(--danger-strong,#96140a)"><?php echo (int) $migSummary['connection_error']; ?></strong></article>
              <?php endif; ?>
              <?php if (($migSummary['missing_connection'] ?? 0) > 0): ?>
                <article class="admin-diagnostic-card"><span>Conexao ausente</span><strong style="color:var(--danger-strong,#96140a)"><?php echo (int) $migSummary['missing_connection']; ?></strong></article>
              <?php endif; ?>
              <?php if (($migSummary['checksum_mismatch'] ?? 0) > 0): ?>
                <article class="admin-diagnostic-card"><span>Checksum divergente</span><strong style="color:var(--warning-strong,#925500)"><?php echo (int) $migSummary['checksum_mismatch']; ?></strong></article>
              <?php endif; ?>
            </div>

            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px">
              <button type="button" class="admin-tab active" data-mig-filter="all" onclick="filterMigrations('all',this)">Todas</button>
              <button type="button" class="admin-tab" data-mig-filter="applied" onclick="filterMigrations('applied',this)">Aplicadas</button>
              <button type="button" class="admin-tab" data-mig-filter="pending" onclick="filterMigrations('pending',this)">Pendentes</button>
              <?php if (($migSummary['rolled_back'] ?? 0) > 0): ?>
                <button type="button" class="admin-tab" data-mig-filter="rolled_back" onclick="filterMigrations('rolled_back',this)">Rolled back</button>
              <?php endif; ?>
              <?php if (($migSummary['connection_error'] ?? 0) + ($migSummary['missing_connection'] ?? 0) > 0): ?>
                <button type="button" class="admin-tab" data-mig-filter="error" onclick="filterMigrations('error',this)">Erros</button>
              <?php endif; ?>
            </div>

            <div class="admin-table-wrap">
              <table class="admin-table admin-table-compact">
                <thead><tr>
                  <th>Vers&atilde;o</th>
                  <th>Descri&ccedil;&atilde;o</th>
                  <th>Grupo</th>
                  <th>Conex&atilde;o</th>
                  <th>Status</th>
                  <th>Tempo</th>
                  <th>Executada em</th>
                  <?php if ($can['migrations'] ?? false): ?><th>A&ccedil;&atilde;o</th><?php endif; ?>
                </tr></thead>
                <tbody id="adminMigrationsTable" data-empty-title="Sem migrations" data-empty-message="Nao foi possivel listar migrations agora." data-empty-icon="database">
                <?php if ($migList === []): ?>
                  <tr><td colspan="<?php echo ($can['migrations'] ?? false) ? 8 : 7; ?>"><?php echo $h($migError ?? 'Nenhuma migration encontrada.'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($migList as $migrationRow): ?>
                  <?php
                    $migState       = (string) ($migrationRow['status']      ?? 'pending');
                    $migGroup       = (string) ($migrationRow['domain_group'] ?? 'Geral');
                    $migMs          = $migrationRow['execution_ms']           ?? null;
                    $migCheckOk     = (bool)   ($migrationRow['checksum_match'] ?? true);
                    $migVersion     = (string) ($migrationRow['version']      ?? '');
                    $migDescription = (string) ($migrationRow['description']  ?? '-');
                    $migFilterClass = match ($migState) {
                        'applied'                              => 'applied',
                        'pending'                              => 'pending',
                        'rolled_back'                          => 'rolled_back',
                        'missing_connection', 'connection_error' => 'error',
                        default                                => 'other',
                    };
                  ?>
                  <tr data-mig-status="<?php echo $h($migFilterClass); ?>">
                    <td><strong><?php echo $h($migVersion); ?></strong></td>
                    <td>
                      <?php echo $h($migDescription); ?>
                      <?php if (!$migCheckOk): ?>
                        <span class="admin-tag warn" title="Checksum do arquivo diferente do registrado." style="margin-left:4px;font-size:.68rem">checksum</span>
                      <?php endif; ?>
                    </td>
                    <td><span class="admin-tag neutral" style="font-size:.72rem"><?php echo $h($migGroup); ?></span></td>
                    <td><span class="admin-small"><?php echo $h($migrationRow['actual_connection'] ?? $migrationRow['logical_connection'] ?? '-'); ?></span></td>
                    <td><span class="admin-tag <?php echo $h($statusLabel($migState)); ?>"><?php echo $h($migState); ?></span></td>
                    <td>
                      <?php if ($migMs !== null): ?>
                        <span class="admin-small"><?php echo $migMs >= 1000 ? number_format($migMs / 1000, 1, ',', '.') . 's' : $migMs . 'ms'; ?></span>
                      <?php else: ?>
                        -
                      <?php endif; ?>
                    </td>
                    <td><?php echo !empty($migrationRow['executed_at']) ? $h($migrationRow['executed_at']) : '-'; ?></td>
                    <?php if ($can['migrations'] ?? false): ?>
                      <td>
                        <?php if ($migState === 'pending'): ?>
                          <form method="post" style="display:contents" onsubmit="return confirm('Executar migration <?php echo $h(addslashes($migVersion)); ?>?\n\n<?php echo $h(addslashes($migDescription)); ?>')">
                            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>" />
                            <input type="hidden" name="action" value="run_single_migration" />
                            <input type="hidden" name="version" value="<?php echo $h($migVersion); ?>" />
                            <button type="submit" class="admin-row-btn">
                              <span class="material-symbols-rounded">play_circle</span>Executar
                            </button>
                          </form>
                        <?php else: ?>
                          <span class="admin-small" style="color:var(--mag-muted)">—</span>
                        <?php endif; ?>
                      </td>
                    <?php endif; ?>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>

          <?php if (!empty($migDiag['loose_sql_files'])): ?>
            <div class="admin-panel" style="margin-top:10px">
              <h2 style="display:flex;align-items:center;gap:6px"><span class="material-symbols-rounded" style="font-size:18px;color:var(--warning-strong,#925500)">warning</span>Arquivos SQL soltos</h2>
              <p class="admin-small" style="margin-bottom:8px">Estes arquivos <code>.sql</code> na pasta de migrations nao sao gerenciados pelo runner. Se representam DDL importante, considere encapsula-los em um arquivo <code>Version*.php</code>.</p>
              <ul class="admin-small" style="margin:0;padding-left:18px">
                <?php foreach ($migDiag['loose_sql_files'] as $sqlFile): ?>
                  <li><?php echo $h($sqlFile); ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

          <div class="admin-panel" style="margin-top:10px">
            <h2 style="cursor:pointer;user-select:none;display:flex;align-items:center;gap:6px" onclick="var p=document.getElementById('migDiagPanel');p.hidden=!p.hidden;this.querySelector('.mig-chevron').textContent=p.hidden?'expand_more':'expand_less'" role="button" tabindex="0" aria-expanded="false" aria-controls="migDiagPanel">
              <span class="material-symbols-rounded" style="font-size:18px">engineering</span>Diagnostico tecnico
              <span class="material-symbols-rounded mig-chevron" style="margin-left:auto;font-size:20px">expand_more</span>
            </h2>
            <div id="migDiagPanel" hidden>
              <?php if (!empty($migDiag)): ?>
                <div class="admin-table-wrap" style="margin-top:10px">
                  <table class="admin-table admin-table-compact">
                    <tbody>
                      <tr><td><strong>Pasta de migrations</strong></td><td class="admin-small"><?php echo $h($migDiag['migrations_path'] ?? '-'); ?></td></tr>
                      <tr><td><strong>Tabela de controle</strong></td><td class="admin-small"><?php echo $h($migDiag['migrations_table'] ?? '-'); ?></td></tr>
                      <tr><td><strong>Ambiente</strong></td><td class="admin-small"><?php echo $h($migDiag['environment'] ?? '-'); ?></td></tr>
                      <tr><td><strong>Versao app</strong></td><td class="admin-small"><?php echo $h($migDiag['app_version'] ?? '-'); ?></td></tr>
                      <tr><td><strong>PHP</strong></td><td class="admin-small"><?php echo $h($migDiag['php_version'] ?? '-'); ?></td></tr>
                      <tr><td><strong>MySQL/MariaDB</strong></td><td class="admin-small"><?php echo $h($migDiag['db_version'] ?? '-'); ?></td></tr>
                      <tr><td><strong>Banco ativo</strong></td><td class="admin-small"><?php echo $h($migDiag['db_name'] ?? '-'); ?></td></tr>
                      <tr><td><strong>Gerado em</strong></td><td class="admin-small"><?php echo $h($migDiag['generated_at'] ?? '-'); ?></td></tr>
                    </tbody>
                  </table>
                </div>
                <?php if (!empty($migDiag['connections'])): ?>
                  <h3 style="font-size:.88rem;margin:14px 0 6px;color:var(--mag-muted)">Conexoes configuradas</h3>
                  <div class="admin-table-wrap">
                    <table class="admin-table admin-table-compact">
                      <thead><tr><th>Conexao</th><th>Host</th><th>Porta</th><th>Banco</th><th>Usuario</th><th>Charset</th></tr></thead>
                      <tbody>
                      <?php foreach ($migDiag['connections'] as $connName => $connInfo): ?>
                        <tr>
                          <td><strong><?php echo $h($connName); ?></strong></td>
                          <?php if ($connInfo['configured'] ?? false): ?>
                            <td class="admin-small"><?php echo $h($connInfo['host']     ?? ''); ?></td>
                            <td class="admin-small"><?php echo $h($connInfo['port']     ?? ''); ?></td>
                            <td class="admin-small"><?php echo $h($connInfo['database'] ?? ''); ?></td>
                            <td class="admin-small"><?php echo $h($connInfo['user']     ?? ''); ?></td>
                            <td class="admin-small"><?php echo $h($connInfo['charset']  ?? ''); ?></td>
                          <?php else: ?>
                            <td colspan="5"><span class="admin-tag danger">nao configurada</span></td>
                          <?php endif; ?>
                        </tr>
                      <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>
              <?php else: ?>
                <p class="admin-small">Diagnostico indisponivel.</p>
              <?php endif; ?>
            </div>
          </div>
        </section>

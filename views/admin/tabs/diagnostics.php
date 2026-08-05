        <section class="admin-section <?php echo $activeTab === 'diagnostics' ? 'active' : ''; ?>" data-admin-tab-panel="diagnostics">
          <div class="admin-panel">
            <h2>Diagn&oacute;stico</h2>
            <div class="admin-diagnostics-grid">
              <article class="admin-diagnostic-card"><span>MariaDB</span><strong>Conectado</strong><p class="admin-small">Conexao principal disponivel.</p></article>
              <article class="admin-diagnostic-card"><span>PHP</span><strong><?php echo $h(PHP_VERSION); ?></strong><p class="admin-small">Runtime do servidor.</p></article>
              <article class="admin-diagnostic-card"><span>Ambiente</span><strong><?php echo $h($environment ?? 'producao'); ?></strong><p class="admin-small">Configuracao atual da aplicacao.</p></article>
              <article class="admin-diagnostic-card"><span>Charset</span><strong><?php echo $h($rbacDiagnostics['charset'] ?? '-'); ?></strong><p class="admin-small">Banco atual.</p></article>
              <article class="admin-diagnostic-card"><span>Usuarios sem perfil</span><strong><?php echo (int) ($rbacDiagnostics['users_without_role'] ?? 0); ?></strong><p class="admin-small">Registros sem vinculo em biomie_user_roles.</p></article>
              <article class="admin-diagnostic-card"><span>Permissoes sem perfil</span><strong><?php echo (int) ($rbacDiagnostics['permissions_without_role'] ?? 0); ?></strong><p class="admin-small">Permissoes ativas sem matriz.</p></article>
            </div>
          </div>

          <div class="admin-panel">
            <h2>Tabelas RBAC</h2>
            <div class="admin-table-wrap">
              <table class="admin-table">
                <thead><tr><th>Tabela</th><th>Status</th><th>Linhas</th></tr></thead>
                <tbody>
                <?php foreach (($rbacDiagnostics['tables'] ?? []) as $tableName => $tableInfo): ?>
                  <tr>
                    <td><strong><?php echo $h($tableName); ?></strong></td>
                    <td><span class="admin-tag <?php echo !empty($tableInfo['exists']) ? 'ok' : 'danger'; ?>"><?php echo !empty($tableInfo['exists']) ? 'existe' : 'ausente'; ?></span></td>
                    <td><?php echo isset($tableInfo['rows']) ? (int) $tableInfo['rows'] : '-'; ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>

          <div class="admin-panel">
            <h2>Sa&uacute;de operacional</h2>
            <div class="admin-table-wrap">
              <table class="admin-table">
                <thead><tr><th>&Aacute;rea</th><th>Status</th><th>Linhas</th><th>Mensagem</th></tr></thead>
                <tbody>
                <?php foreach (($systemHealth['tables'] ?? []) as $healthRow): ?>
                  <tr>
                    <td><?php echo $h($healthRow['label'] ?? '-'); ?></td>
                    <td><span class="admin-tag <?php echo $h($statusLabel((string) ($healthRow['status'] ?? 'neutral'))); ?>"><?php echo $h($healthRow['status'] ?? '-'); ?></span></td>
                    <td><?php echo isset($healthRow['rows']) ? (int) $healthRow['rows'] : '-'; ?></td>
                    <td class="admin-small"><?php echo $h($healthRow['message'] ?? '-'); ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>

          <?php
          /**
           * Frescor da camada Python.
           *
           * Aparece SEMPRE, e nao so quando algo quebra: o modo de falha destes
           * jobs e silencioso — o cron morre e a home segue exibindo a ultima
           * leitura ate ela vencer, sem nada na tela dizendo que o numero parou
           * de ser atualizado. Um painel que so mostra problema nao permite
           * perceber "isso aqui esta velho" antes de virar alerta.
           *
           * Ausencia do bloco = camada nao instalada, que e diferente de camada
           * quebrada; por isso nao renderiza nada em vez de mostrar zeros.
           */
          $intelligenceJobs = (array) (($systemHealth['intelligence']['jobs'] ?? []));
          ?>
          <?php if ($intelligenceJobs !== []): ?>
          <div class="admin-panel">
            <h2>Intelig&ecirc;ncia (Python)</h2>
            <div class="admin-table-wrap">
              <table class="admin-table">
                <thead><tr><th>Job</th><th>Situa&ccedil;&atilde;o</th><th>&Uacute;ltimo sucesso</th><th>Idade</th><th>Limite</th><th>Falhas (7d)</th></tr></thead>
                <tbody>
                <?php foreach ($intelligenceJobs as $jobName => $jobInfo): ?>
                  <?php
                  $severity = (string) ($jobInfo['severity'] ?? 'ok');
                  $tag = match ($severity) {
                      'critical' => 'danger',
                      'warning'  => 'warn',
                      default    => 'ok',
                  };
                  $idade = $jobInfo['age_hours'] ?? null;
                  ?>
                  <tr>
                    <td><strong><?php echo $h((string) $jobName); ?></strong></td>
                    <td><span class="admin-tag <?php echo $h($tag); ?>"><?php echo $h($severity === 'ok' ? 'em dia' : ($severity === 'warning' ? 'atrasado' : 'parado')); ?></span></td>
                    <td class="admin-small"><?php echo $h((string) ($jobInfo['last_ok'] ?? 'nunca completou')); ?></td>
                    <td><?php echo $idade === null ? '-' : $h((string) $idade) . 'h'; ?></td>
                    <td class="admin-small"><?php echo (int) ($jobInfo['limit_hours'] ?? 0); ?>h</td>
                    <td><?php echo (int) ($jobInfo['failures_7d'] ?? 0); ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <p class="admin-small">Horas em UTC. O mesmo limite dispara o alerta do OpsSentinel &mdash; ver <code>IntelligenceJobSla</code>.</p>
          </div>
          <?php endif; ?>
        </section>

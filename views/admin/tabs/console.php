        <?php if ($can['console'] ?? false): ?>
        <section class="admin-section <?php echo $activeTab === 'console' ? 'active' : ''; ?>" data-admin-tab-panel="console">

          <div class="admin-panel" style="margin-bottom:12px">
            <div style="display:flex;align-items:flex-start;gap:10px">
              <span class="material-symbols-rounded" style="font-size:22px;color:var(--mag-muted);flex-shrink:0;margin-top:2px">terminal</span>
              <div>
                <strong style="display:block;color:var(--mag-text);margin-bottom:4px">Ferramentas administrativas</strong>
                <p class="admin-small" style="margin:0">Comandos restritos e auditados. N&atilde;o executa shell do servidor. Toda execu&ccedil;&atilde;o &eacute; registrada em log.</p>
              </div>
            </div>
          </div>

          <div style="display:grid;grid-template-columns:340px 1fr;gap:16px;align-items:start" id="consoleLayout">

            <div>
              <div class="admin-panel" style="margin-bottom:12px">
                <h2 style="margin-bottom:12px;display:flex;align-items:center;gap:6px">
                  <span class="material-symbols-rounded" style="font-size:18px">play_circle</span>Executar comando
                </h2>

                <div class="admin-field" style="margin-bottom:10px">
                  <label for="consoleCommandSelect">Comando</label>
                  <select id="consoleCommandSelect" class="admin-select" onchange="consoleOnCommandSelect(this.value)">
                    <option value="">-- selecione --</option>
                    <?php
                    $consoleGroups = [];
                    foreach ($consoleCommandList as $cmd) {
                        $consoleGroups[$cmd['group']][] = $cmd;
                    }
                    $consolGroupLabels = [
                        'sistema'    => 'Sistema',
                        'banco'      => 'Banco de Dados',
                        'migrations' => 'Migrations',
                        'gold'       => 'Gold / Marts',
                        'clientes'   => 'Clientes / Dimensoes',
                        'cnae'       => 'CNAE / Fiscal',
                        'geo'        => 'Geo / Mapa',
                        'omie'       => 'Omie',
                        'logs'       => 'Logs',
                        'rbac'       => 'RBAC',
                    ];
                    foreach ($consolGroupLabels as $gKey => $gLabel) {
                        if (!isset($consoleGroups[$gKey])) continue;
                        echo '<optgroup label="' . $h($gLabel) . '">';
                        foreach ($consoleGroups[$gKey] as $cmd) {
                            $requiresConf = $cmd['requires_confirmation'] ? ' [confirmar]' : '';
                            echo '<option value="' . $h($cmd['name']) . '" data-requires-confirmation="' . ($cmd['requires_confirmation'] ? '1' : '0') . '">'
                                . $h($cmd['name'] . $requiresConf) . '</option>';
                        }
                        echo '</optgroup>';
                    }
                    ?>
                  </select>
                </div>

                <div id="consoleArgsArea" style="display:none">
                  <div id="consoleLimitField" class="admin-field" style="display:none;margin-bottom:8px">
                    <label for="consoleArgLimit">limit (5&ndash;50)</label>
                    <input type="number" id="consoleArgLimit" class="admin-input" min="5" max="50" value="20" />
                  </div>
                  <div id="consoleLevelField" class="admin-field" style="display:none;margin-bottom:8px">
                    <label for="consoleArgLevel">level</label>
                    <select id="consoleArgLevel" class="admin-select">
                      <option value="ALL">ALL</option>
                      <option value="INFO">INFO</option>
                      <option value="WARNING">WARNING</option>
                      <option value="ERROR">ERROR</option>
                    </select>
                  </div>
                  <div id="consoleDomainField" class="admin-field" style="display:none;margin-bottom:8px">
                    <label for="consoleArgDomain">domain</label>
                    <select id="consoleArgDomain" class="admin-select">
                      <option value="ALL">ALL</option>
                      <option value="omie">omie</option>
                      <option value="gold">gold</option>
                      <option value="migrations">migrations</option>
                      <option value="http">http</option>
                      <option value="sync">sync</option>
                      <option value="admin">admin</option>
                    </select>
                  </div>
                  <div id="consoleDryRunField" class="admin-field" style="display:none;margin-bottom:8px">
                    <label class="admin-check">
                      <input type="checkbox" id="consoleArgDryRun" />
                      Simular (dry run) &mdash; nenhum dado ser&aacute; alterado
                    </label>
                  </div>
                  <div id="consoleDateRangeField" style="display:none;margin-bottom:8px">
                    <div class="admin-field" style="margin-bottom:6px">
                      <label for="consoleArgStart">start (YYYY-MM-DD)</label>
                      <input type="date" id="consoleArgStart" class="admin-input" value="" />
                    </div>
                    <div class="admin-field">
                      <label for="consoleArgEnd">end (YYYY-MM-DD)</label>
                      <input type="date" id="consoleArgEnd" class="admin-input" value="" />
                    </div>
                  </div>
                </div>

                <div id="consoleConfirmArea" style="display:none;margin-bottom:10px">
                  <label class="admin-check" style="color:var(--warning-strong,#925500);background:var(--warning-soft,#fff4de);border-radius:10px;padding:8px 10px">
                    <input type="checkbox" id="consoleConfirmCheck" />
                    Confirmo a execu&ccedil;&atilde;o deste comando
                  </label>
                </div>

                <div class="admin-actions">
                  <button class="btn-primary" id="consoleBtnRun" onclick="consoleRun(false)" disabled>
                    <span class="material-symbols-rounded">play_arrow</span>Executar
                  </button>
                </div>
              </div>

              <div class="admin-panel">
                <h2 style="margin-bottom:10px;font-size:.92rem;display:flex;align-items:center;gap:6px">
                  <span class="material-symbols-rounded" style="font-size:16px">list</span>Comandos dispon&iacute;veis
                </h2>
                <?php foreach ($consolGroupLabels as $gKey => $gLabel): ?>
                  <?php if (!isset($consoleGroups[$gKey])) continue; ?>
                  <p style="font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:var(--mag-muted);margin:10px 0 4px"><?php echo $h($gLabel); ?></p>
                  <?php foreach ($consoleGroups[$gKey] as $cmd): ?>
                    <div style="display:flex;align-items:flex-start;gap:8px;margin-bottom:6px;cursor:pointer" onclick="consoleSelectCommand('<?php echo $h($cmd['name']); ?>')">
                      <code style="font-size:.78rem;font-weight:700;color:var(--page-accent);background:var(--page-accent-soft);border-radius:6px;padding:2px 6px;white-space:nowrap;flex-shrink:0"><?php echo $h($cmd['name']); ?></code>
                      <span class="admin-small" style="font-size:.75rem;line-height:1.4"><?php echo $h($cmd['description']); ?></span>
                    </div>
                  <?php endforeach; ?>
                <?php endforeach; ?>
              </div>
            </div>

            <div>
              <div class="admin-panel" id="consoleResultPanel">
                <div id="consoleResultEmpty" style="display:flex;flex-direction:column;align-items:center;justify-content:center;padding:40px 0;gap:8px;color:var(--mag-muted)">
                  <span class="material-symbols-rounded" style="font-size:40px;opacity:.35">terminal</span>
                  <span style="font-size:.88rem">Selecione um comando e clique em Executar</span>
                </div>

                <div id="consoleResultContent" hidden>
                  <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px">
                    <span id="consoleResultIcon" class="material-symbols-rounded" style="font-size:20px">check_circle</span>
                    <strong id="consoleResultTitle" style="font-size:1rem;color:var(--mag-text)"></strong>
                    <span id="consoleResultBadge" class="admin-tag" style="margin-left:auto;font-size:.72rem"></span>
                    <span id="consoleResultDuration" class="admin-small" style="font-size:.72rem"></span>
                  </div>

                  <p id="consoleResultMessage" class="admin-small" style="margin:0 0 12px;padding:8px 10px;border-radius:8px;background:var(--mag-bg)"></p>

                  <div id="consoleOutputLines" hidden>
                    <div style="background:#1e1e2e;border-radius:12px;padding:14px 16px;font-family:'DM Mono',monospace;font-size:.8rem;line-height:1.7;color:#cdd6f4;overflow-x:auto;margin-bottom:10px">
                      <pre id="consoleOutputPre" style="margin:0;white-space:pre-wrap"></pre>
                    </div>
                  </div>

                  <div id="consoleMetricsArea" hidden style="margin-bottom:10px">
                    <div class="admin-diagnostics-grid" id="consoleMetricsGrid" style="grid-template-columns:repeat(auto-fill,minmax(140px,1fr))"></div>
                  </div>

                  <div id="consoleWarningsArea" hidden style="margin-bottom:10px">
                    <div class="admin-msg warning" id="consoleWarningsList"></div>
                  </div>

                  <div id="consoleErrorsArea" hidden style="margin-bottom:10px">
                    <div class="admin-msg error" id="consoleErrorsList"></div>
                  </div>

                  <div id="consoleTablesArea"></div>

                  <div style="margin-top:12px;border-top:1px solid var(--mag-border);padding-top:10px">
                    <button type="button" style="background:none;border:none;cursor:pointer;font:inherit;font-size:.78rem;color:var(--mag-muted);display:flex;align-items:center;gap:4px;padding:0" onclick="var d=document.getElementById('consoleMeta');d.hidden=!d.hidden;this.querySelector('.cm-chev').textContent=d.hidden?'expand_more':'expand_less'">
                      <span class="material-symbols-rounded" style="font-size:14px">engineering</span>Diagn&oacute;stico t&eacute;cnico
                      <span class="material-symbols-rounded cm-chev" style="font-size:14px">expand_more</span>
                    </button>
                    <div id="consoleMeta" hidden style="margin-top:8px">
                      <table class="admin-table admin-table-compact" style="font-size:.75rem">
                        <tbody id="consoleMetaRows"></tbody>
                      </table>
                    </div>
                  </div>
                </div>

                <div id="consoleResultLoading" hidden style="display:flex;align-items:center;gap:10px;padding:24px 0;color:var(--mag-muted);justify-content:center">
                  <span class="material-symbols-rounded" style="font-size:22px;animation:spin 1s linear infinite">progress_activity</span>
                  <span style="font-size:.9rem">Executando...</span>
                </div>
              </div>
            </div>
          </div>

          <style>
            @keyframes spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}
            @media(max-width:960px){#consoleLayout{grid-template-columns:1fr}}
          </style>
        </section>
        <?php endif; ?>

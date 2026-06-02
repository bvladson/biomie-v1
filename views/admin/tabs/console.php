        <?php if ($can['console'] ?? false): ?>
        <section class="admin-section <?php echo $activeTab === 'console' ? 'active' : ''; ?>" data-admin-tab-panel="console">

          <div style="display:grid;grid-template-columns:360px 1fr;gap:16px;align-items:start" id="consoleLayout">

            <div>
              <!-- ── Executar comando ─────────────────────────────────────── -->
              <div class="admin-panel" style="margin-bottom:12px">
                <h2 style="margin-bottom:10px;display:flex;align-items:center;gap:6px;font-size:.95rem">
                  <span class="material-symbols-rounded" style="font-size:18px">play_circle</span>Executar comando
                </h2>

                <!-- Search filter -->
                <div style="position:relative;margin-bottom:10px">
                  <span class="material-symbols-rounded" style="position:absolute;left:8px;top:50%;transform:translateY(-50%);font-size:16px;color:var(--mag-muted);pointer-events:none">search</span>
                  <input type="search" id="consoleCommandSearch" class="admin-input" placeholder="Filtrar comandos..." style="padding-left:30px;font-size:.82rem" oninput="consoleFilterCommands(this.value)" autocomplete="off" />
                </div>

                <div class="admin-field" style="margin-bottom:10px">
                  <label for="consoleCommandSelect" style="font-size:.78rem">Comando selecionado</label>
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
                    $consolGroupIcons = [
                        'sistema' => 'settings', 'banco' => 'storage', 'migrations' => 'published_with_changes',
                        'gold' => 'auto_awesome', 'clientes' => 'group', 'cnae' => 'category',
                        'geo' => 'map', 'omie' => 'sync', 'logs' => 'receipt_long', 'rbac' => 'shield',
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

                <!-- Description of selected command -->
                <div id="consoleCmdDesc" style="display:none;font-size:.78rem;color:var(--mag-muted);background:var(--mag-bg);border-radius:8px;padding:7px 10px;margin-bottom:10px;line-height:1.5"></div>

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

              <!-- Command browser -->
              <div class="admin-panel" id="consoleCmdBrowser" style="max-height:420px;overflow-y:auto">
                <h2 style="margin-bottom:8px;font-size:.85rem;display:flex;align-items:center;gap:6px;position:sticky;top:0;background:var(--mag-panel,#fff);padding-bottom:6px;z-index:1">
                  <span class="material-symbols-rounded" style="font-size:15px">list</span>Comandos
                  <span id="consoleFilterBadge" style="display:none;margin-left:auto;font-size:.7rem;background:var(--page-accent-soft);color:var(--page-accent);border-radius:20px;padding:1px 8px"></span>
                </h2>
                <div id="consoleCmdList">
                <?php foreach ($consolGroupLabels as $gKey => $gLabel): ?>
                  <?php if (!isset($consoleGroups[$gKey])) continue; ?>
                  <div class="console-cmd-group" data-group="<?php echo $h($gKey); ?>">
                    <p style="font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--mag-muted);margin:10px 0 4px;display:flex;align-items:center;gap:4px">
                      <span class="material-symbols-rounded" style="font-size:12px"><?php echo $h($consolGroupIcons[$gKey] ?? 'circle'); ?></span><?php echo $h($gLabel); ?>
                    </p>
                    <?php foreach ($consoleGroups[$gKey] as $cmd): ?>
                      <button type="button"
                        class="console-cmd-item"
                        data-cmd="<?php echo $h($cmd['name']); ?>"
                        data-desc="<?php echo $h($cmd['description']); ?>"
                        data-group="<?php echo $h($gKey); ?>"
                        onclick="consoleSelectCommand('<?php echo $h($cmd['name']); ?>')"
                        title="<?php echo $h($cmd['description']); ?>"
                        style="display:flex;align-items:flex-start;gap:8px;width:100%;text-align:left;background:none;border:1px solid transparent;border-radius:8px;padding:5px 6px;cursor:pointer;margin-bottom:3px;transition:background .12s">
                        <code style="font-size:.75rem;font-weight:700;color:var(--page-accent);background:var(--page-accent-soft);border-radius:6px;padding:2px 7px;white-space:nowrap;flex-shrink:0;line-height:1.6"><?php echo $h($cmd['name']); ?><?php if ($cmd['requires_confirmation']): ?><span style="opacity:.6;font-weight:400"> ⚠</span><?php endif; ?></code>
                        <span style="font-size:.73rem;color:var(--mag-muted);line-height:1.4;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical"><?php echo $h($cmd['description']); ?></span>
                      </button>
                    <?php endforeach; ?>
                  </div>
                <?php endforeach; ?>
                </div>
                <p id="consoleCmdEmpty" style="display:none;text-align:center;color:var(--mag-muted);font-size:.8rem;padding:16px 0">Nenhum comando encontrado.</p>
              </div>
            </div>

            <div>

              <!-- ── Ações rápidas de manutenção ──────────────────────────── -->
              <div class="admin-panel" style="margin-bottom:12px">
                <h2 style="margin-bottom:10px;font-size:.88rem;display:flex;align-items:center;gap:6px">
                  <span class="material-symbols-rounded" style="font-size:16px">bolt</span>Ações rápidas
                </h2>
                <div style="display:flex;flex-wrap:wrap;gap:6px">
                  <button type="button" class="console-quick-btn" onclick="consoleSelectCommand('geo:materialize')" title="Rematerializar mart geo">
                    <span class="material-symbols-rounded" style="font-size:14px">map</span>Geo mart
                  </button>
                  <button type="button" class="console-quick-btn" onclick="consoleSelectCommand('clientes:enrich-omie-all')" title="Enriquecer clientes via Omie">
                    <span class="material-symbols-rounded" style="font-size:14px">group</span>Enriquecer clientes
                  </button>
                  <button type="button" class="console-quick-btn" onclick="consoleSelectCommand('clientes:geo-coverage')" title="Ver cobertura geo dos clientes">
                    <span class="material-symbols-rounded" style="font-size:14px">pin_drop</span>Cobertura geo
                  </button>
                  <button type="button" class="console-quick-btn" onclick="consoleSelectCommand('gold:materialize-commercial')" title="Materializar gold comercial">
                    <span class="material-symbols-rounded" style="font-size:14px">auto_awesome</span>Gold comercial
                  </button>
                  <button type="button" class="console-quick-btn" onclick="consoleSelectCommand('cnae:materialize')" title="Materializar dim CNAE">
                    <span class="material-symbols-rounded" style="font-size:14px">category</span>CNAE dim
                  </button>
                  <button type="button" class="console-quick-btn" onclick="consoleSelectCommand('system:health')" title="Status do sistema">
                    <span class="material-symbols-rounded" style="font-size:14px">monitor_heart</span>Health
                  </button>
                  <button type="button" class="console-quick-btn" onclick="consoleSelectCommand('logs:recent')" title="Ver logs recentes">
                    <span class="material-symbols-rounded" style="font-size:14px">receipt_long</span>Logs
                  </button>
                  <button type="button" class="console-quick-btn" onclick="consoleSelectCommand('db:counts')" title="Contagem das tabelas">
                    <span class="material-symbols-rounded" style="font-size:14px">storage</span>DB contagens
                  </button>
                </div>
              </div>

              <!-- ── Painel de resultado ──────────────────────────────────── -->
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
            @media(max-width:1024px){#consoleLayout{grid-template-columns:1fr}}
            .console-cmd-item:hover{background:var(--page-accent-soft,#fdf2f1)!important;border-color:var(--page-accent,#96140a)!important}
            .console-cmd-item.is-selected{background:var(--page-accent-soft,#fdf2f1);border-color:var(--page-accent,#96140a)}
            .console-quick-btn{display:inline-flex;align-items:center;gap:5px;font:inherit;font-size:.76rem;font-weight:600;padding:5px 10px;border-radius:20px;border:1px solid var(--mag-border,#e2e2e2);background:var(--mag-bg,#f8f8f8);color:var(--mag-text);cursor:pointer;transition:background .12s,border-color .12s;white-space:nowrap}
            .console-quick-btn:hover{background:var(--page-accent-soft);border-color:var(--page-accent);color:var(--page-accent)}
            #consoleCmdBrowser::-webkit-scrollbar{width:4px}
            #consoleCmdBrowser::-webkit-scrollbar-thumb{background:var(--mag-border,#ddd);border-radius:4px}
          </style>
        </section>
        <?php endif; ?>

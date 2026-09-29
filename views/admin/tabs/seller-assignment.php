        <section class="admin-section <?php echo $activeTab === 'seller-assignment' ? 'active' : ''; ?>" data-admin-tab-panel="seller-assignment">
          <div class="admin-panel">
            <div style="margin-bottom:16px">
              <h2 style="margin:0"><span class="material-symbols-rounded">person_add</span> Vendedor ausente</h2>
              <p class="admin-small" style="margin-top:4px">Notas e pedidos que vieram do Omie <strong>sem vendedor</strong>. Escolha um vendedor do cadastro do Omie: a mudan&ccedil;a vale <strong>s&oacute; no BIomie</strong> &mdash; o Omie n&atilde;o &eacute; alterado.</p>
              <p class="admin-small" style="margin-top:2px;color:var(--mag-muted,#64748b)">O canal segue a regra oficial: vendedor &ldquo;Licita&ccedil;&atilde;o&rdquo; &rarr; licita&ccedil;&atilde;o; qualquer outro &rarr; varejo. Se o Omie passar a informar um vendedor, o Omie vence e a atribui&ccedil;&atilde;o deixa de valer. Os indicadores do m&ecirc;s s&atilde;o recalculados em seguida.</p>
            </div>

            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px">
              <label for="saCompany" class="admin-small" style="font-weight:700">Empresa:</label>
              <select id="saCompany" class="admin-input" style="min-width:200px"></select>
              <span class="admin-small" data-sa-summary style="color:var(--mag-muted,#64748b)"></span>
            </div>

            <div data-sa-status class="admin-small" style="margin-bottom:10px"></div>

            <div class="admin-table-wrap">
              <table class="admin-table admin-table-compact">
                <thead>
                  <tr>
                    <th>Opera&ccedil;&atilde;o</th>
                    <th>Data</th>
                    <th>Cliente</th>
                    <th style="text-align:right">Valor</th>
                    <th>Canal no BI</th>
                    <th>Vendedor</th>
                    <th style="text-align:right">A&ccedil;&atilde;o</th>
                  </tr>
                </thead>
                <tbody data-sa-rows>
                  <tr><td colspan="7" class="admin-small" style="color:var(--mag-muted,#64748b)">Carregando&hellip;</td></tr>
                </tbody>
              </table>
            </div>
          </div>

          <script>
          (function () {
            'use strict';
            var panel = document.querySelector('[data-admin-tab-panel="seller-assignment"]');
            if (!panel || panel.__saInit) return;
            panel.__saInit = true;

            function csrf() { return window.ADMIN_CSRF || ''; }
            var compSel = panel.querySelector('#saCompany');
            var rowsEl = panel.querySelector('[data-sa-rows]');
            var statusEl = panel.querySelector('[data-sa-status]');
            var summaryEl = panel.querySelector('[data-sa-summary]');
            var state = { sellers: [], rows: [], companiesLoaded: false };

            function esc(s) { var d = document.createElement('div'); d.textContent = String(s == null ? '' : s); return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
            function brl(v) { return Number(v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }); }
            function dt(v) { return v ? String(v).substring(0, 10).split('-').reverse().join('/') : '—'; }
            function setStatus(msg, kind) {
              statusEl.textContent = msg || '';
              statusEl.style.color = kind === 'error' ? 'var(--danger,#dc2626)' : (kind === 'ok' ? 'var(--state-success,#16a34a)' : 'var(--mag-muted,#64748b)');
            }
            function canal(r) {
              var c = r.business_channel === 'licitacao' ? 'Licitação' : (r.business_channel === 'outros' ? 'Intra-grupo' : 'Varejo');
              return esc(c) + '<br><span class="admin-small" style="color:var(--mag-muted,#94a3b8)">' + esc(r.visibility_rule_source || '—') + '</span>';
            }
            function sellerOptions() {
              return '<option value="">Escolha o vendedor…</option>' + state.sellers.map(function (s) {
                return '<option value="' + esc(s.codigo) + '">' + esc(s.nome) + (s.licitacao ? ' (canal licitação)' : '') + '</option>';
              }).join('');
            }

            function render() {
              if (!state.rows.length) {
                rowsEl.innerHTML = '<tr><td colspan="7" class="admin-small" style="color:var(--mag-muted,#64748b)">Nenhuma nota ou pedido sem vendedor nesta empresa.</td></tr>';
                summaryEl.textContent = '';
                return;
              }
              var pend = state.rows.filter(function (r) { return !r.atribuicao_id; });
              var feitas = state.rows.length - pend.length;
              summaryEl.textContent = pend.length + ' sem vendedor · ' + feitas + ' com vendedor atribuído no BI';
              rowsEl.innerHTML = state.rows.map(function (r, i) {
                var tipo = r.tipo === 'nf' ? 'NF' : 'Pedido';
                var op = '<strong>' + tipo + ' ' + esc(r.documento) + '</strong><br><span class="admin-small" style="color:var(--mag-muted,#94a3b8)">' + esc(r.status || '') + '</span>';
                var vend, acao;
                if (r.atribuicao_id) {
                  vend = '<strong>' + esc(r.atribuido_nome) + '</strong> <span class="admin-small" style="color:var(--page-accent,#96140a)">(atribuído no BI)</span>'
                       + '<br><span class="admin-small" style="color:var(--mag-muted,#94a3b8)">Omie: sem vendedor · ' + esc(r.motivo || '') + '</span>';
                  acao = '<button type="button" class="btn-secondary btn-sm" data-sa-remove="' + i + '">Remover</button>';
                } else {
                  var codInfo = (r.codigo_vendedor_omie && r.codigo_vendedor_omie !== '0')
                    ? '<br><span class="admin-small" style="color:var(--warning,#d97706)">Código ' + esc(r.codigo_vendedor_omie) + ' fora do cadastro</span>' : '';
                  vend = '<select class="admin-input btn-sm" data-sa-seller="' + i + '" style="min-width:190px">' + sellerOptions() + '</select>'
                       + '<br><input class="admin-input btn-sm" data-sa-motivo="' + i + '" placeholder="Motivo (obrigatório)" style="min-width:190px;margin-top:4px">' + codInfo;
                  acao = '<button type="button" class="btn-primary btn-sm" data-sa-assign="' + i + '">Atribuir</button>';
                }
                return '<tr>' +
                  '<td>' + op + '</td>' +
                  '<td class="admin-small">' + dt(r.data_emissao) + '</td>' +
                  '<td>' + esc(r.nome_cliente || '') + '</td>' +
                  '<td style="text-align:right;white-space:nowrap">' + brl(r.valor) + '</td>' +
                  '<td>' + canal(r) + '</td>' +
                  '<td>' + vend + '</td>' +
                  '<td style="text-align:right">' + acao + '</td>' +
                '</tr>';
              }).join('');
            }

            function post(payload, okMsg) {
              setStatus('Salvando…');
              fetch('api/seller_assignment.php', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() },
                body: JSON.stringify(Object.assign({ csrf_token: csrf(), company_id: Number(compSel.value || 0) }, payload))
              }).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
                .then(function (res) {
                  if (!res.ok || !res.d || !res.d.ok) { setStatus((res.d && res.d.message) || 'Falha ao salvar.', 'error'); return; }
                  var r = res.d.result || {};
                  var fila = res.d.rematerializacao && res.d.rematerializacao.queued !== false ? ' Indicadores do mês agendados para recálculo.' : '';
                  setStatus(okMsg + ' Canal: ' + (r.canal || '—') + ' (' + (r.origem || '') + ').' + fila, 'ok');
                  load(false);
                })
                .catch(function () { setStatus('Erro de rede.', 'error'); });
            }

            rowsEl.addEventListener('click', function (e) {
              var a = e.target.closest('[data-sa-assign]');
              if (a) {
                var i = a.getAttribute('data-sa-assign'), r = state.rows[Number(i)];
                var seller = panel.querySelector('[data-sa-seller="' + i + '"]').value;
                var motivo = panel.querySelector('[data-sa-motivo="' + i + '"]').value.trim();
                if (!seller) { setStatus('Escolha o vendedor.', 'error'); return; }
                if (motivo.length < 3) { setStatus('Informe o motivo — fica na auditoria.', 'error'); return; }
                post({ action: 'assign', tipo: r.tipo, chave: r.chave, seller_id: seller, motivo: motivo }, 'Vendedor atribuído.');
                return;
              }
              var d = e.target.closest('[data-sa-remove]');
              if (d) {
                var rr = state.rows[Number(d.getAttribute('data-sa-remove'))];
                if (!window.confirm('Remover a atribuição? A operação volta a ficar sem vendedor no BI.')) return;
                post({ action: 'remove', tipo: rr.tipo, chave: rr.chave }, 'Atribuição removida.');
              }
            });
            compSel.addEventListener('change', function () { load(false); });

            function load(first) {
              var q = first || !compSel.value ? '' : ('?company_id=' + encodeURIComponent(compSel.value));
              fetch('api/seller_assignment.php' + q, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                  if (!d || !d.ok) { setStatus((d && d.message) || 'Falha ao carregar.', 'error'); return; }
                  if (!state.companiesLoaded) {
                    compSel.innerHTML = (d.companies || []).map(function (c) { return '<option value="' + c.id + '">' + esc(c.nome_fantasia || c.slug) + '</option>'; }).join('');
                    state.companiesLoaded = true;
                  }
                  compSel.value = String(d.company_id);
                  if (d.ready === false) setStatus('A migração 20260929140000 ainda não foi aplicada: dá para ver a lista, mas não atribuir.', 'error');
                  state.sellers = d.sellers || [];
                  state.rows = d.rows || [];
                  render();
                })
                .catch(function () { setStatus('Erro de rede ao carregar.', 'error'); });
            }

            var trig = document.querySelector('[data-admin-tab-trigger="seller-assignment"]');
            if (trig) trig.addEventListener('click', function () { if (!state.companiesLoaded) load(true); });
            if (panel.classList.contains('active')) load(true);
          }());
          </script>
        </section>

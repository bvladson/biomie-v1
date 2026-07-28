# BIomie - Plano Mestre de Otimizacao de Desempenho

Data: 2026-06-11
Status: proximas evolucoes integradas; observacao operacional pendente
Escopo: backend, banco, cache, jobs, frontend, observabilidade e operacao

## 0. Resultado Do Ciclo Implementado

| Estagio | Resultado entregue | Medicao atual | Proxima melhoria |
|---|---|---|---|
| Guardrails | Metricas por request, SLO, assets, guard Omie em GET e budgets no release | release guard 53/53 PASS, com guardas dedicados de query, idempotencia e lazy-load | coletar amostra autenticada por 24h e ativar enforce |
| Comercial canonico | Shadow independente da flag e comparacao legacy somente por cache local | golden 6/6 e shadow 10/10 | observar fontes servidas e retirar fallback apenas apos SLA |
| Financeiro | Stale-while-revalidate com teste automatizado | ultimo snapshot valido retorna sem build web | completar leitura local dos endpoints secundarios |
| Schema e conexao | Cache compartilhado de schema e remocao de probe redundante | Mix 6 para 4 queries; repeticao 3 queries | migrar os leitores administrativos restantes |
| Plano de Voo | NFs e roster reutilizados, leitura analitica sem enriquecimento visual e schema compartilhado | 32 para 7 queries; ~3,6s para ~0,7s; delta diario zero | manter budget operacional de 8 e avaliar read model dedicado para chegar a 6 |
| Clientes | UPSERT em lotes, hash fisico e geo idempotente | segunda execucao: 1.101 sem mudanca, zero escritas, `updated_at` preservado e ~0,64s | adicionar watermark para nao reler fontes sem alteracao |
| Frontend | Ajuda e seis familias de drawers carregadas somente na primeira interacao | menos 114.675 bytes iniciais em dashboard/time/NFs; 169.598 no mix; 46.065 no plano; 195.999 na geografia | carregar graficos secundarios por uso e medir no navegador autenticado |
| TLS MySQL | Persistencia passou a ser opt-in; TLS e verificacao continuam obrigatorios | evita conexao TLS persistente envelhecida em processos longos | medir conexoes e erros por 7 dias antes de qualquer opt-in |
| Retencao | Dry-run somente leitura com volume, idade e lotes estimados | 5.718 spans elegiveis; bronze 868,84 MB dentro da politica | validar backup antes de criar limpeza em lotes |
| Infraestrutura | Auditoria TLS, verificacao SSL desativada, CA e OPcache | TLS AES-256-GCM; zero verificacoes SSL desativadas | configurar CA do PHP CLI e confirmar OPcache no PHP web |

Validacoes executadas neste ciclo:

- `php tools/dev/lint_all.php`: 588 arquivos, 0 falhas.
- `php tests/run_phase2_coverage.php`: 53/53 testes.
- `php tools/dev/flight_plan_probe.php`: R$ 129.200,60 e delta diario zero.
- `php tools/dev/performance_query_budget_probe.php --enforce`: todos os budgets verdes.
- `php tools/dev/customer_materialization_idempotence_probe.php`: zero escritas na repeticao e `updated_at` preservado.
- `php tools/dev/drilldown_lazy_load_audit.php`: 12/12 verificacoes.
- `php tools/dev/visual_layout_guard.php --json`: 472/472 verificacoes visuais.
- `php tools/dev/biomie_release_guard.php`: 53/53 dominios em PASS.

### 0.1 Melhorias De Segunda Camada

Estas sao as melhorias recomendadas sobre cada ganho ja entregue. Devem ser
ativadas somente depois da janela de observacao indicada.

| Base entregue | Melhoria seguinte | Beneficio esperado | Gate de ativacao |
|---|---|---|---|
| Guard Omie em modo observacao | ativar `enforce` nos GETs comprovadamente locais | elimina regressao que volte a travar telas por rede externa | 24h sem violacao legitima e worker de refresh saudavel |
| Shadow canonico local | retirar fallback legacy endpoint por endpoint | reduz complexidade, memoria e caminhos duplicados | 7 dias de golden/shadow sem divergencia critica |
| Financeiro stale-while-revalidate | migrar endpoints financeiros secundarios para read models locais | abertura e drawers previsiveis mesmo com Omie indisponivel | freshness visivel e reconciliacao NF x titulo verde |
| Cache de schema compartilhado | migrar leitores administrativos restantes e medir SQL acumulado | reduz consultas pequenas em toda a aplicacao | query budget por fluxo sem piora funcional |
| Plano de Voo com 7 queries | avaliar consolidacao final em read model dedicado | aproximar o fluxo da meta estrategica de 6 queries sem aumentar complexidade | payload identico, delta monetario zero e EXPLAIN revisado |
| Clientes fisicamente idempotentes | adicionar watermark por fonte e processar somente candidatos alterados | reduz tambem leitura e CPU da materializacao | freshness e cobertura preservadas em duas execucoes |
| Ajuda e drawers sob demanda | lazy-load de graficos secundarios e bibliotecas exclusivas por pagina | reduz parse, execucao JS e tempo ate interacao | medicao real de transferencia e interatividade autenticada |
| Dry-run de retencao | limpeza de spans em lotes pequenos com checkpoint | recupera espaco sem lock longo | backup validado, janela operacional e primeiro lote supervisionado |
| TLS e OPcache auditados | configurar CA no PHP CLI e confirmar OPcache no PHP web | jobs com cadeia confiavel e PHP web com bytecode cache | auditoria de ambiente sem alertas bloqueantes |

## 1. Objetivo

Tornar o BIomie previsivelmente rapido sem alterar regras comerciais, valores,
permissoes ou contratos visuais.

Resultados esperados:

- Requests de tela leem somente dados locais, canonicos, gold ou cache.
- Integracoes Omie, materializacoes e reconstrucoes pesadas rodam em jobs ou CLI.
- Endpoints principais deixam de apresentar picos de 10 a 30 segundos.
- Consultas repetidas de schema e conexao deixam de consumir o tempo das telas.
- Primeira visita carrega apenas os recursos necessarios para a pagina aberta.
- Crescimento de bronze e telemetria passa a ter politica de retencao auditavel.

## 2. Principios Obrigatorios

1. GET de tela nunca chama Omie, executa sync ou materializacao pesada.
2. Fonte oficial e regra comercial nao mudam para ganhar velocidade.
3. Toda troca de fonte passa por shadow validation e golden contracts.
4. Toda fase possui baseline, meta, rollback e medicao posterior.
5. Ultimo dado valido e preferivel a tela indisponivel, desde que freshness seja
   exibida corretamente.
6. Otimizacao de banco exige EXPLAIN ou medicao; indices nao serao criados por
   suposicao.
7. Nenhuma limpeza de dados ocorre sem dry-run, backup e criterio de retencao.
8. Long polling e jobs nao entram no mesmo SLO de endpoints de leitura.

## 3. Decisoes Sobre a Sugestao Recebida

### Adotado

- Canonical-first no comercial.
- Stale-while-revalidate no comercial e financeiro.
- Omie fora do caminho de navegacao.
- Cache compartilhado de capacidades do schema.
- Reducao do Plano de Voo para um pequeno conjunto de agregacoes.
- UPSERT agrupado e incremental para dimensao de clientes.
- Carregamento sob demanda de ajuda, drawers e recursos pesados.
- Retencao de bronze, spans e metricas.
- Validacao explicita de SSL e OPcache em producao.

### Ajustado ao projeto atual

- Nao criar `biomie_cache_snapshots` inicialmente. O projeto ja possui `Cache`,
  `RefreshQueue`, `JobRunner`, historico de snapshot e fila persistente. Uma nova
  tabela somente sera considerada se os mecanismos atuais nao atenderem ao SLA.
- Nao ativar leitura canonica diretamente. A ativacao sera endpoint por endpoint,
  depois de shadow validation, golden tests e reconciliacao.
- Nao adicionar conexao persistente como solucao. `Database` ja usa
  `PDO::ATTR_PERSISTENT`; primeiro sera medida a seguranca e removido o custo
  redundante de `SELECT 1`.
- Nao aplicar particionamento como primeira medida de retencao. Em hospedagem
  compartilhada, a primeira entrega sera agregacao e exclusao em lotes.
- UPSERT em lote usara sintaxe compativel com a versao MariaDB de producao.

## 4. SLOs E Orcamentos

### Endpoints

| Classe | Meta p50 | Meta p95 | Erros 5xx |
|---|---:|---:|---:|
| Leitura simples | <= 200 ms | <= 800 ms | < 0,5% |
| Dashboard agregado | <= 350 ms | <= 1.000 ms | < 0,5% |
| Drawer/detalhe complexo | <= 600 ms | <= 2.000 ms | < 0,5% |
| Busca paginada | <= 400 ms | <= 1.200 ms | < 0,5% |

`live_stream.php`, jobs, syncs e materializacoes terao SLOs separados.

### Banco

| Fluxo | Orcamento inicial |
|---|---:|
| Overview | <= 8 queries |
| Vendedores | <= 8 queries |
| Pedidos | <= 6 queries |
| Produtos | <= 8 queries |
| Plano de Voo | <= 8 queries; meta estrategica <= 6 |
| Financeiro resumo | <= 8 queries locais |
| Drawer simples | <= 6 queries |

### Frontend

| Pagina | Meta inicial transferida | Regra |
|---|---:|---|
| Dashboard | <= 700 KB comprimidos | detalhes sob demanda |
| Financeiro | <= 850 KB comprimidos | modulos secundarios sob demanda |
| Geografia | <= 900 KB comprimidos | drawers e analises sob demanda |
| Demais paginas | <= 650 KB comprimidos | sem catalogos globais pesados |

As metas de frontend devem ser recalibradas depois da primeira medicao real no
navegador autenticado.

## 5. Fases De Execucao

## Fase 0 - Baseline E Guardrails

Objetivo: impedir que uma otimizacao melhore tempo e altere dados.

Entregas:

- Criar relatorio de baseline pos-deploy para endpoints autenticados.
- Separar p50, p95, maximo, 5xx, cache hit e fonte servida.
- Registrar contagem de queries por fluxo critico.
- Registrar tamanho transferido, quantidade de requests e tempo de interatividade.
- Definir um guard de arquitetura para detectar chamadas Omie em endpoints GET.
- Adicionar os SLOs deste documento ao release guard.

Ferramentas existentes a reutilizar:

- `tools/dev/biomie_release_guard.php`
- `tools/dev/commercial_dashboard_performance_audit.php`
- `tools/dev/nf_periodo_performance_audit.php`
- `tools/dev/geo_map_performance_probe.php`
- `tests/run_golden_endpoints.php`
- `tests/run_shadow_homologation.php`
- `tools/dev/shadow_audit.php`

Aceite:

- Baseline registrado para todos os endpoints criticos.
- Golden endpoints e shadow homologation verdes.
- Nenhuma fase seguinte inicia sem valor anterior comparavel.

Rollback:

- Nao aplicavel; fase somente de medicao e guardrails.

## Fase 1 - Corte Canonico Comercial

Objetivo: overview, vendedores, pedidos e produtos responderem por dados locais.

Entregas:

- Validar `overview`, `vendors` e `orders` em shadow contra o legacy.
- Confirmar freshness, cobertura canonica e marts atualizados.
- Garantir que builders canonicos preservam o contrato atual das APIs.
- Impedir `SalesAnalyticsService::buildSnapshot()` em contexto web.
- Permitir build completo apenas em CLI, job ou acao administrativa enfileirada.
- Ativar as flags uma por vez:
  - `CANONICAL_READS_OVERVIEW`
  - `CANONICAL_READS_VENDORS`
  - `CANONICAL_READS_ORDERS`
- Tratar produtos separadamente, pois o mix depende de NFs e itens canonicos.

Ordem de ativacao:

1. Pedidos.
2. Vendedores.
3. Overview.
4. Produtos.

Gate por endpoint:

- Shadow sem divergencia critica.
- Totais monetarios dentro da tolerancia contratual.
- Mesmas regras de licitacao, remessa, canal e visibilidade.
- Freshness dentro do SLA.
- p95 abaixo de 1 segundo por 24 horas.

Arquivos centrais:

- `app/SalesAnalyticsService.php`
- `app/Modules/Sales/Application/Overview/OverviewPayloadBuilder.php`
- `app/Modules/Sales/Application/Vendors/VendorPayloadBuilder.php`
- `api/orders.php`
- `api/products.php`
- `healthz.php`

Aceite:

- Nenhum endpoint comercial principal chama Omie durante GET.
- `healthz.php` informa fonte canonica para os modulos ativados.
- Picos de 10 a 30 segundos deixam de ocorrer.

Rollback:

- Desativar somente a flag do endpoint afetado.
- Preservar tabelas canonicas e jobs; nao apagar dados.

## Fase 2 - Financeiro Local E Sempre Disponivel

Objetivo: usuario nunca aguardar fan-out de APIs Omie ao abrir o financeiro.

Entregas:

- Aplicar stale-while-revalidate em `FinanceAnalyticsService`, como ja existe no
  comercial.
- Ao expirar o cache, entregar ultimo snapshot aceitavel e enfileirar
  `finance.snapshot.refresh`.
- Bloquear `buildSnapshot()` em contexto web, exceto quando executado por job ou
  CLI autorizado.
- Priorizar `FinanceReadRepository` e tabelas canonicas nos endpoints financeiros.
- Identificar endpoints financeiros que ainda usam snapshot legacy.
- Exibir freshness, ultima atualizacao e erro de refresh sem quebrar a tela.
- Garantir resposta minima explicativa quando ainda nao existir snapshot.
- Validar o cruzamento NF x titulo sem alterar a fonte oficial de nenhum KPI.

Arquivos centrais:

- `app/FinanceAnalyticsService.php`
- `app/Modules/Finance/Infrastructure/FinanceReadRepository.php`
- `app/Queue/JobRunner.php`
- `api/finance_overview.php`
- `api/finance_titles.php`
- endpoints `api/finance_*.php`

Aceite:

- Abrir a pagina financeira nao chama Omie.
- Cache expirado retorna ultimo dado valido e agenda refresh.
- Falha Omie aparece como freshness/aviso operacional, sem erro 500 da tela.
- Resumos financeiros ficam abaixo de 1 segundo p95.

Rollback:

- Desativar a leitura canonica financeira no endpoint afetado.
- Manter stale-if-error ativo.
- Refresh manual continua enfileirado, nunca sincrono.

## Fase 3 - Orquestracao De Conexao E Schema

Objetivo: remover consultas pequenas e repetidas que nao entregam dados ao usuario.

Entregas:

- Criar um servico unico de capacidades de schema por request/processo.
- Evoluir `TableHealthService` para responder existencia de tabela, coluna e
  indice com cache compartilhado.
- Migrar primeiro os caminhos quentes; rotinas de migration e diagnostico podem
  continuar consultando `information_schema`.
- Remover consultas diretas a `information_schema` dos services de leitura.
- Alterar `Database::connect()` para nao executar `SELECT 1` a cada reutilizacao
  dentro do mesmo request.
- Manter reconexao apos falha real e health check nas fronteiras operacionais.
- Tornar conexao persistente configuravel e medir conexoes abertas/erros antes de
  decidir seu default definitivo.

Prioridade de migracao:

1. Overview, vendedores, pedidos e produtos.
2. Plano de Voo e financeiro.
3. Geo e drawers.
4. Materializadores e rotinas administrativas.

Aceite:

- Nenhuma consulta de schema repetida no mesmo request.
- Reducao mensuravel de queries sem mudanca de payload.
- Nenhum aumento de erros de conexao ou conexoes esgotadas.

Rollback:

- Cache de schema pode ser desativado por configuracao.
- Restaurar probe de conexao somente se erros reais aumentarem.

## Fase 4 - Plano De Voo Com Read Model Enxuto

Objetivo: manter a leitura comercial completa com no maximo 6 queries principais.

Entregas:

- Mapear as 32 queries atuais por origem e dado produzido.
- Reutilizar NFs, carteira, metas e clientes ja carregados no mesmo request.
- Consolidar agregacoes por vendedor em consultas por conjunto.
- Usar marts gold quando preservarem exatamente o contrato comercial.
- Evitar loops que consultam o banco por vendedor.
- Preservar filtros, detalhes, metas, risco e visibilidade por canal.

Orcamento proposto:

1. Vendedores e identidade comercial.
2. Metas por competencia.
3. Realizado autorizado por competencia.
4. Carteira aberta.
5. Clientes/carteirizacao.
6. Alertas ou financeiro complementar, quando necessario.

Aceite:

- Plano de Voo executa no maximo 6 queries principais.
- Totais iguais ao contrato `flight-plan`.
- Tempo p95 permanece abaixo de 1 segundo com crescimento de vendedores.

Rollback:

- Manter o builder atual atras de flag durante a homologacao.

## Fase 5 - Materializacao De Clientes Em Lote

Objetivo: substituir milhares de round trips por pequenos lotes idempotentes.

Entregas:

- Transformar `batchUpsert()` em UPSERT multi-row por lote.
- Transformar `upsertGeoDim()` em UPSERT multi-row por lote.
- Usar lotes iniciais de 100 a 250 registros, ajustados pelo tamanho do payload.
- Preservar prioridade de fonte e confianca de vendedor.
- Avaliar `row_hash` para atualizar somente registros alterados.
- Consolidar contagens finais em uma unica consulta.
- Envolver cada lote em transacao curta.
- Registrar quantidade inserida, alterada, ignorada e duracao.

Cuidados:

- Confirmar sintaxe compativel com MariaDB de producao.
- Nao atualizar `updated_at` quando nenhum campo comercial mudou.
- Testar idempotencia com duas execucoes consecutivas.

Arquivo central:

- `app/CustomerDimensionMaterializationService.php`

Aceite:

- Reducao de aproximadamente 2.200 comandos para dezenas de comandos.
- Segunda execucao sem alteracoes nao modifica registros.
- Totais e vinculos permanecem iguais.

Rollback:

- Manter caminho atual disponivel por flag durante uma janela de validacao.

## Fase 6 - Frontend Sob Demanda

Objetivo: carregar no inicio somente o necessario para entender e operar a pagina.

Entregas:

- Separar o runtime leve da Ajuda Comercial do catalogo completo de explicacoes.
- Carregar o catalogo apenas na primeira abertura de ajuda.
- Carregar drawers de NF, pedido, cliente, vendedor e produto na primeira abertura.
- Carregar graficos e analises secundarias quando o bloco entrar em uso.
- Revisar `PageAssetRegistry` por pagina e remover dependencias nao utilizadas.
- Dividir `shell-index.css` entre shell global e estilos especificos.
- Medir JavaScript nao utilizado e custo de parse.
- Preservar acessibilidade, teclado, foco e estados vazios.

Primeira ordem:

1. Ajuda Comercial Contextual.
2. Drawers comerciais.
3. Financeiro.
4. Geografia.
5. Dashboard e Mix Faturado.

Arquivos centrais:

- `app/PageAssetRegistry.php`
- `assets/js/commercial-card-help.js`
- `assets/css/commercial-card-help.css`
- modulos em `assets/js/modules/`

Aceite:

- Ajuda e drawers continuam funcionando apos carregamento sob demanda.
- Menos JS inicial, requests e tempo de parse.
- Nenhuma regressao visual ou de acessibilidade.

Rollback:

- Recolocar temporariamente o modulo especifico no registro da pagina.

## Fase 7 - Retencao E Crescimento Controlado

Objetivo: impedir crescimento silencioso de tabelas operacionais.

Politica inicial proposta:

| Tabela | Retencao quente | Tratamento posterior |
|---|---:|---|
| `biomie_omie_bronze` | 180 dias | arquivar ou resumir conforme auditoria |
| `biomie_operational_spans` | 30 dias | excluir em lotes |
| `biomie_operational_metrics` | 90 dias | consolidar diario antes de excluir |
| logs operacionais | 30 a 90 dias | preservar erros relevantes |

Entregas:

- Confirmar colunas de data, volume diario e necessidade legal/operacional.
- Criar relatorio dry-run com quantidade e tamanho elegivel.
- Criar agregacao diaria de metricas antigas.
- Excluir em lotes pequenos, com limite de tempo e lock.
- Agendar job de retencao em horario de baixa.
- Avaliar particionamento somente apos a primeira janela de retencao.

Aceite:

- Retencao executada sem bloquear sync ou telas.
- Volume e duracao registrados em pipeline/telemetria.
- Backup e restauracao testados antes da primeira exclusao.

Rollback:

- Restaurar somente a partir de backup ou arquivo validado.
- Job inicia desativado e exige ativacao explicita.

## Fase 8 - Infraestrutura PHP, SSL E Operacao

Objetivo: confirmar que a infraestrutura nao limita as melhorias de codigo.

Entregas:

- Validar `curl.cainfo` e `openssl.cafile` no PHP web e CLI.
- Validar conexao TLS com Omie e banco sem desativar verificacao.
- Confirmar OPcache no PHP web, hit rate, memoria e politica de revalidacao.
- Medir efeito real de conexoes persistentes e limites do provedor.
- Confirmar compressao, cache imutavel e versionamento de assets.
- Atualizar runbook de deploy e rollback com checks de desempenho.

Aceite:

- SSL valido em web, job e CLI.
- Nenhum uso de `CURLOPT_SSL_VERIFYPEER = false`.
- OPcache ativo no ambiente web.
- Assets versionados continuam recebendo cache longo.

Rollback:

- Mudancas de PHP/infra devem ser reversiveis pelo painel ou arquivo de
  configuracao, sem alterar dados.

## 6. Observabilidade Transversal

Cada fase deve registrar:

- `request_id`
- endpoint ou job
- fonte servida
- `served_from`
- cache hit/miss/stale
- duracao total
- quantidade de queries
- tempo SQL acumulado, quando disponivel
- tamanho do payload
- erro sanitizado
- freshness e ultima atualizacao

Alertas recomendados:

- p95 acima do SLO por 15 minutos.
- 5xx acima de 1% por 5 minutos.
- cache miss seguido de build web.
- chamada Omie detectada em request GET.
- job de refresh parado ou falho.
- fonte legacy servida em endpoint marcado como canonico.
- tabela operacional acima da tendencia esperada.

## 7. Estrategia De Teste

Toda fase deve executar, conforme o escopo:

```bash
php tools/dev/lint_all.php
php tests/run_phase2_coverage.php
php tests/run_golden_endpoints.php
php tests/run_shadow_homologation.php
php tools/dev/shadow_audit.php
php tools/dev/biomie_release_guard.php --pretty
```

Testes adicionais obrigatorios:

- Comparacao de payload antes/depois.
- Query count antes/depois.
- Cache fresh, stale, inexistente e erro de refresh.
- Worker parado e worker ativo.
- Periodo atual e historico.
- Usuario com permissao completa e usuario restrito.
- Dados vazios e dados parciais.
- Idempotencia dos materializadores.

## 8. Ordem Recomendada E Dependencias

| Ordem | Fase | Dependencia | Impacto |
|---:|---|---|---|
| 1 | Baseline e guardrails | nenhuma | protege todas as mudancas |
| 2 | Corte canonico comercial | baseline + shadow | maior ganho imediato |
| 3 | Financeiro local | jobs + freshness | maior ganho no financeiro |
| 4 | Conexao e schema | baseline de queries | ganho transversal |
| 5 | Plano de Voo | schema cache + contratos | escala comercial |
| 6 | Clientes em lote | testes de idempotencia | reduz carga de jobs |
| 7 | Frontend sob demanda | contratos visuais | melhora primeira visita |
| 8 | Retencao | backup + dry-run | controla crescimento |
| 9 | Infraestrutura | acesso operacional | confirma ambiente |

Infraestrutura e SSL podem ser auditados em paralelo, mas alteracoes de ambiente
devem ocorrer fora da janela dos cortes canonicos.

## 9. Estrategia De Release

Cada fase sera entregue em quatro passos:

1. Implementar atras de flag ou mantendo o caminho anterior disponivel.
2. Rodar testes locais, golden, shadow e release guard.
3. Ativar em producao fora do horario de pico.
4. Observar por 24 horas antes de remover o fallback.

Criterios para interromper uma ativacao:

- Qualquer divergencia monetaria critica.
- Aumento de 5xx.
- Freshness incorreta ou fonte desconhecida.
- p95 pior que o baseline.
- Worker sem processar refresh.
- Aumento anormal de conexoes ou locks.

## 10. Definicao De Pronto

O plano sera considerado concluido quando:

- Endpoints comerciais e financeiros nao chamarem Omie durante navegacao.
- Todas as fontes oficiais ativas estiverem identificadas no payload e health.
- SLOs forem atendidos por sete dias consecutivos.
- Plano de Voo respeitar o orcamento de queries.
- Materializacao de clientes estiver em lote e idempotente.
- Ajuda e drawers forem carregados sob demanda.
- Politica de retencao estiver ativa, auditada e com backup validado.
- SSL, OPcache e conexoes estiverem documentados e monitorados.
- Release guard bloquear regressao de desempenho e chamadas externas em GET.

## 11. Primeira Entrega Recomendada

O primeiro ciclo de implementacao deve conter somente:

1. Baseline automatizado e guard de chamada Omie em GET.
2. Validacao shadow de pedidos, vendedores e overview.
3. Corte canonico de pedidos com rollback por flag.
4. Medicao de 24 horas.

Depois do aceite de pedidos, repetir a mesma estrategia para vendedores e
overview. Isso entrega ganho real cedo, com risco controlado e sem misturar
otimizacoes de banco, frontend e infraestrutura na mesma liberacao.

## 12. Backlog Executavel

Escala de esforco:

- S: alteracao pequena e isolada.
- M: alteracao em mais de um componente, com testes dedicados.
- L: alteracao transversal ou com homologacao operacional.

| ID | Entrega | Fase | Esforco | Dependencia |
|---|---|---:|---:|---|
| PERF-001 | Baseline autenticado de endpoints e assets | 0 | M | nenhuma |
| PERF-002 | Guard que detecta chamada Omie em GET | 0 | M | PERF-001 |
| PERF-003 | SLO e query budget no release guard | 0 | M | PERF-001 |
| PERF-010 | Shadow e corte canonico de pedidos | 1 | M | PERF-001 |
| PERF-011 | Shadow e corte canonico de vendedores | 1 | L | PERF-010 |
| PERF-012 | Shadow e corte canonico de overview | 1 | L | PERF-011 |
| PERF-013 | Corte canonico de produtos e mix | 1 | M | PERF-012 |
| PERF-020 | Stale-while-revalidate financeiro | 2 | M | PERF-001 |
| PERF-021 | Leitura financeira local por endpoint | 2 | L | PERF-020 |
| PERF-022 | Estado minimo, freshness e erro de refresh | 2 | M | PERF-020 |
| PERF-030 | Capacidades de schema compartilhadas | 3 | M | PERF-001 |
| PERF-031 | Migrar caminhos quentes para schema cache | 3 | L | PERF-030 |
| PERF-032 | Remover probe redundante da conexao | 3 | S | PERF-001 |
| PERF-033 | Tornar persistencia configuravel e medir | 3 | M | PERF-032 |
| PERF-040 | Mapa das queries do Plano de Voo | 4 | S | PERF-001 |
| PERF-041 | Read model do Plano de Voo | 4 | L | PERF-030, PERF-040 |
| PERF-050 | UPSERT agrupado de clientes | 5 | L | PERF-001 |
| PERF-051 | UPSERT agrupado de geo e contagem unica | 5 | M | PERF-050 |
| PERF-052 | Hash/idempotencia e telemetria de mudanca | 5 | M | PERF-050 |
| PERF-060 | Ajuda Comercial sob demanda | 6 | M | PERF-001 |
| PERF-061 | Drawers comerciais sob demanda | 6 | L | PERF-060 |
| PERF-062 | Asset budget e auditoria de JS nao utilizado | 6 | M | PERF-001 |
| PERF-070 | Auditoria e dry-run de retencao | 7 | M | backup validado |
| PERF-071 | Agregacao e limpeza em lotes | 7 | L | PERF-070 |
| PERF-080 | Auditoria SSL web/job/CLI | 8 | S | acesso producao |
| PERF-081 | Auditoria OPcache e conexoes | 8 | S | acesso producao |
| PERF-090 | Painel e alertas de SLO | transversal | M | PERF-001 |

Sequencia do primeiro ciclo:

```text
PERF-001 -> PERF-002 -> PERF-003 -> PERF-010 -> observar 24h
```

Sequencia do segundo ciclo:

```text
PERF-011 -> PERF-012 -> PERF-013 -> observar 24h por corte
```

Financeiro pode avancar em paralelo depois do baseline:

```text
PERF-020 -> PERF-022 -> PERF-021
```

# BIomie - Auditoria de Performance

Data: 2026-05-01

## Resumo

Esta auditoria foca carregamento inicial, historico, endpoints de dashboard, mapa, financeiro, rota comercial, administracao e integracoes Omie.

Correcoes aplicadas neste patch:

- Monitoramento central de chamadas API no front-end via `assets/js/performance-monitor.js`.
- Tempo total de API no backend via `app/Support/PerformanceTimer.php`.
- Diagnostico recolhivel de desempenho no AppShell para administradores.
- Evitado materializar snapshots historicos durante requests de `overview`, `vendors` e `flight_plan`.
- Dashboard deixou de carregar todas as notas fiscais para montar "Maiores notas fiscais"; agora busca Top N direto no SQL com `ORDER BY valor_total DESC LIMIT`.
- Leituras principais de exibicao fiscal/comercial passaram a selecionar colunas explicitas em vez de `SELECT *` nos caminhos mais usados pelo dashboard e listas comerciais.

## Paginas Auditadas

### index.php

Objetivo: resumo executivo.

Endpoints chamados:

- `api/overview.php`
- `api/vendors.php`
- `api/commercial_health.php`
- `api/weather.php`
- Blocos geograficos via `assets/js/index_geo.js`: `api/geo_summary.php`, `api/sales_by_city.php`, `api/sales_by_neighborhood.php`

Gargalos encontrados:

- `api/overview.php` carregava todas as notas do periodo e ordenava em PHP para montar Top 8 de maiores notas.
- Snapshots historicos podiam ser materializados durante o request quando ausentes.
- Varias chamadas sao feitas em paralelo; antes deste patch nao havia medicao visual central por endpoint.

Acao aplicada:

- Criado `listTopInvoicesForDisplayCommercial()` com SQL limitado.
- Adicionado diagnostico de tempo de API e tempo de backend.
- Materializacao historica removida do request.

### nf_periodo.php / pedidos.php

Objetivo: leitura fiscal do periodo.

Endpoints principais:

- `api/orders.php`
- `api/products.php`
- `api/overview.php`, quando a pagina precisa de resumo executivo.

Gargalos provaveis:

- Listas fiscais longas ainda podem carregar muitos registros se o periodo for amplo.
- Consultas de reconciliacao ainda usam `SELECT *` porque sao diagnosticas e precisam de trilha completa.

Acao recomendada:

- Paginar tabela longa de NFs em patch dedicado.
- Manter Top N e resumo em gold/canonical, sem payload bruto.

### finance.php

Objetivo: caixa, vencidos, recebidos e risco.

Endpoints principais:

- `api/finance_overview.php`
- `api/finance_titles.php`
- `api/commercial_health.php`

Gargalos provaveis:

- Historico financeiro em cache/snapshot pode ser pesado se carregado com muitos titulos.
- `listFinancialTitlesByPeriod()` ainda usa `SELECT *`; deve ser convertido quando a tabela visual for paginada.

Acao recomendada:

- Paginar titulos em atraso e historico.
- Selecionar apenas colunas exibidas no resumo.

### mapa_vendas.php

Objetivo: leitura comercial geografica.

Endpoints principais:

- `api/geo_summary.php`
- `api/sales_by_neighborhood.php`
- `api/top_buyers_by_geo.php`
- `api/sales_by_cnae.php`
- `api/top_clients_by_cnae.php`

Gargalos provaveis:

- Depende das tabelas gold de geografia, CNAE e compradores.
- O front-end deve atualizar estilos do Leaflet sem recriar o mapa.

Acao aplicada anteriormente:

- Endpoints ja retornam 409 amigavel quando tabela gold ausente.

Acao recomendada:

- Validar EXPLAIN nos filtros por `competencia`, `city_key`, `neighborhood_key`, `cnae` e `seller_id`.

### rota_comercial.php / plano_voo.php

Objetivo: metas, carteira, realizado e ritmo.

Endpoints principais:

- `api/flight_plan.php`
- `api/flight_plan_vendor_detail.php`
- `api/flight_plan_targets_editor.php`
- `api/overview.php`

Gargalos encontrados:

- `api/flight_plan.php` podia materializar snapshot historico durante request.

Acao aplicada:

- O endpoint agora usa snapshot apenas se ja existir; caso contrario segue leitura canonical/fallback sem materializar no carregamento.

### conexao_omie.php / integracoes.php

Objetivo: status operacional da integracao.

Endpoints principais:

- `api/status.php`
- `api/canonical_sync.php`
- `api/realtime_sync_status.php`
- `api/domain_entity_map.php`

Gargalos provaveis:

- Status deve ler logs/cursors/tabelas locais, sem chamar Omie ao vivo para montar a tela.
- Jobs e logs precisam de `LIMIT`.

Acao recomendada:

- Manter diagnostico operacional resumido e detalhes recolhidos.

### admin.php / migrations

Objetivo: administracao, diagnostico e migrations.

Gargalos provaveis:

- Listas de logs e migrations podem crescer.
- Status de migrations depende da conexao MariaDB.

Acao recomendada:

- Mostrar ultimos 50 logs por padrao.
- Evitar varrer tabelas grandes sem filtros.

### Historico de resultados anteriores

Origem principal:

- `biomie_competencia_snapshots`
- `biomie_competencia_sync_catalog`
- Cache financeiro `finance_snapshot_history`

Gargalo encontrado:

- Se um snapshot historico nao existia, endpoints podiam chamar `materializeCompetencia()` durante a propria requisicao.

Acao aplicada:

- `overview`, `vendors` e `flight_plan` nao executam mais `materializeCompetencia()` no request.
- A criacao/reprocessamento de snapshots fica para rotina administrativa/CLI.

Acao recomendada:

- Criar endpoint paginado para catalogo/historico se a tela passar a listar muitos meses.

## Queries e Caminhos Auditados

### Corrigido

`api/overview.php`:

- Antes: `listInvoicesForDisplayCommercial()` + `usort()` em PHP + `array_slice()`.
- Depois: `listTopInvoicesForDisplayCommercial($start, $end, 8)` com `ORDER BY nf.valor_total DESC ... LIMIT :limit`.

`app/Canonical/CanonicalReadRepository.php`:

- Removido `SELECT *` dos caminhos de exibicao:
  - `listInvoicedOrdersForDisplayCommercial()`
  - `listPipelineOrdersForDisplayCommercial()`
  - `listInvoicesCommercial()`
  - `listCancelledInvoicesCommercial()`
  - `listPendingOrdersCommercial()`

### Ainda Candidatos a Otimizacao

- `listFinancialTitlesByPeriod()` ainda usa `SELECT *` ate a tabela financeira ser paginada.
- Reconciliacao canonical usa `SELECT *` em rotinas diagnosticas.
- `ForecastRepository` usa `SELECT *` em consultas pequenas por competencia.
- `SyncPipelineRepository` e `TelemetryRepository` usam `SELECT *` com `LIMIT` em telas operacionais/diagnosticas.

## Indices

Indices ja presentes em migrations/canonical SQL:

- `biomie_notas_fiscais_canonicas`: `idx_invoice_status_competencia (status_fiscal, data_competencia)`
- `biomie_notas_fiscais_canonicas`: `idx_invoice_vendor_status (codigo_vendedor_omie, status_fiscal, data_competencia)`
- `biomie_notas_fiscais_canonicas`: `idx_invoice_emissao (data_emissao)`
- `biomie_pedidos_canonicos`: `idx_status_faturamento (status_comercial, data_faturamento)`
- `biomie_pedidos_canonicos`: `idx_data_emissao (data_emissao)`

Indice sugerido para validar com EXPLAIN:

- `biomie_notas_fiscais_canonicas (status_fiscal, data_competencia, valor_total)`

Motivo: Top N de maiores notas filtra por status e competencia, ordena por valor total e usa LIMIT. Nao foi criada migration neste patch porque o banco local nao permitiu EXPLAIN real.

## EXPLAIN

EXPLAIN nao foi executado nesta sessao porque a conexao MariaDB local usada pelo `schema_migrate.php status` falha com:

`SQLSTATE[HY000] [2054] Server sent charset unknown to the client. Please, report to the developers`

e em tentativas anteriores o driver tambem indicou problema com plugin de autenticacao `auth_gssapi_client`.

Assim que a conexao estiver operacional, validar:

1. Top N de notas fiscais no `api/overview.php`.
2. Agregacoes por vendedor no `api/vendors.php`.
3. Tabelas gold de geografia por `competencia`, `city_key`, `neighborhood_key`.
4. Financeiro por `data_competencia`, `data_vencimento`, `status_titulo`.

## Diagnostico Implementado

Backend:

- `PerformanceTimer` mede tempo total e medidas nomeadas.
- `Http::json()` adiciona header `X-BIomie-Response-Time-Ms`.
- Para admin ou ambiente nao production com `debug=1`, a resposta JSON recebe `diagnostics.performance`.

Front-end:

- `BIOMIE_PERF` mede cada chamada feita por `window.fetchApi`/`window.fetchJson`.
- API acima de 1000ms e marcada como lenta.
- O AppShell renderiza diagnostico recolhivel apenas para admin.

## Pendencias Reais

- Executar EXPLAIN com MariaDB acessivel.
- Criar migration de indice somente apos confirmar plano de execucao.
- Paginar tabela longa de NFs e titulos financeiros.
- Criar endpoint especifico para historico/catologo se a tela listar muitos snapshots.

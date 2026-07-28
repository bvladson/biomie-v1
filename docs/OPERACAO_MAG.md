# Operacao MAG - Ambiente BIomie

Este documento registra o minimo operacional para deixar o BIomie executavel de forma reproduzivel.

## Composer e dependencias

- `composer.lock` deve existir e ser versionado em aplicacao/projeto.
- `vendor/` nao entra no Git.
- Em ambiente novo, instale o Composer e rode:

```bash
composer validate
composer install
```

- Se `composer.lock` ainda nao existir, gerar conscientemente em ambiente de desenvolvimento:

```bash
composer update --lock
composer install
```

- Nao usar `--ignore-platform-reqs` como solucao permanente. Isso pode esconder extensoes PHP ausentes, como `pdo_mysql`, `curl` ou `mbstring`.
- Em producao, o release deve receber dependencias por `composer install --no-dev --optimize-autoloader` ou por pacote controlado que ja contenha `vendor/`.

## Banco MariaDB/MySQL

Use um usuario dedicado ao BIomie com autenticacao por senha compativel com PDO MySQL. Evite usuario configurado com plugin GSSAPI/Kerberos para a aplicacao PHP.

Variaveis esperadas:

```text
DB_HOST
DB_PORT
DB_NAME
DB_USER
DB_PASS
DB_TIMEOUT
```

Validacoes:

```bash
php tools/dev/runtime_readiness.php
php bin/schema_migrate.php status
```

Se aparecer `auth_plugin_gssapi_client`, crie/ajuste o usuario do banco no painel/DBA para autenticacao por senha.

## Workers e jobs

Requests web devem enfileirar syncs e materializacoes pesadas. Execute os workers por cron/supervisor:

```bash
php cron/worker.php --queue=high,default --limit=50 --sleep=1 --max-runtime=120
php cron/worker.php --queue=low --limit=20 --sleep=2 --max-runtime=120
```

Status de jobs:

```bash
curl "https://SEU-DOMINIO/biomie/api/refresh_status.php?job_id=JOB_ID"
```

Detalhes da politica de fila e freshness ficam em `docs/JOBS_E_FRESHNESS.md`.

---

## Camada Semântica e Cockpit Interno (Fase 7)

### Validar KPIs registrados

```bash
# Verifica todos os 29 KPIs: permissões, source_of_truth, active/draft
php tools/dev/semantic_audit.php

# Saída JSON para CI
php tools/dev/semantic_audit.php --json

# Relatório Markdown
php tools/dev/semantic_audit.php --markdown
```

Resultado esperado: `Semantic Audit: OK` — 22 active, 7 draft, 0 erros.

### Interpretar KPI draft

KPIs marcados `draft` têm uma ou mais regras não formalizadas (fonte incerta, denominador indefinido, status ambíguo). **Não exibir dados de KPIs draft em dashboards executivos.** Para promover para `active`:

1. Confirmar `source_table` e `official_date` no banco.
2. Escrever `include_rule` / `exclude_rule` verificada contra dados reais.
3. Atualizar o entry em `MetricRegistry` (status `'active'`).
4. Rodar `php tools/dev/semantic_audit.php` — deve sair 0.
5. Rodar `php tests/run_golden_endpoints.php` — deve manter 6/6.
6. Remover `notes` que indicavam a razão do draft.

### Saber se dado está stale

A freshness é derivada de `biomie_pipeline_jobs` (job `gold_mart_refresh`):

| Status | Significado | Ação recomendada |
|---|---|---|
| `fresh` | Dados atualizados dentro do threshold | Nenhuma |
| `stale` | Último sucesso há mais de N min sem falha | Verificar job e executar sync |
| `partial` | Sucesso anterior, mas houve exec mais recente sem sucesso | Verificar erros do job |
| `failed` | Último job registrado falhou, sem sucesso anterior | Acionar equipe técnica |
| `unknown` | Banco indisponível ou job nunca executou | Verificar infra |

Thresholds por domínio:

| Domínio | Stale após |
|---|---|
| operational / operations | 30 min |
| gold / sales / fiscal / finance | 60 min |
| canonical / customers / omie / shadow | 120 min |
| geo / cnae / external | 240 min |
| legacy | 24 h |

### Agir se job falhar

1. Consultar `api/semantic_health.php` (permissão: `logs.view`).
2. Verificar `biomie_pipeline_jobs` diretamente:
   ```sql
   SELECT job_name, status, error_message, started_at, completed_at
   FROM biomie_pipeline_jobs
   ORDER BY started_at DESC LIMIT 20;
   ```
3. Reexecutar o job manualmente:
   ```bash
   php bin/materialize_marts.php
   ```
4. Se houver erro de SQL ou dados corrompidos, escalar para engenharia antes de reexecutar.

### Acessar o Cockpit Interno

| URL | Permissão | Descrição |
|---|---|---|
| `/biomie/cockpit_interno.php` | sales.view, finance.view, fiscal.view, geo.view, admin.view, logs.view | Cards de KPIs + freshness + audit status |
| `/biomie/metric_catalog.php` | admin.view, settings.view, logs.view | Tabela completa de KPIs |
| `/biomie/api/metric_catalog.php` | settings.view, admin.view, logs.view | JSON com catálogo completo |
| `/biomie/api/semantic_health.php` | logs.view, jobs.view, admin.view | JSON com saúde semântica + freshness |

### Inventário de termos métricos

```bash
# Onde cada KPI aparece no código (referência, não autoridade)
php tools/dev/kpi_inventory.php
```

A autoridade é sempre `MetricRegistry::build()` — o inventário é apenas orientativo para localizar implementações antigas.

### Navegação semântica no BIomie

#### URLs oficiais

| Página | URL | Tipo |
|---|---|---|
| Cockpit Interno MAG | `/biomie/cockpit_interno.php` | PHP (rota oficial) |
| Catálogo de KPIs | `/biomie/metric_catalog.php` | PHP (rota oficial) |
| API — catálogo JSON | `/biomie/api/metric_catalog.php` | API PHP |
| API — saúde semântica | `/biomie/api/semantic_health.php` | API PHP |

> **Aviso:** Rotas oficiais no BIomie são sempre `.php`. Arquivos `.html` não são rotas oficiais e não possuem proteção RBAC server-side.

#### Quem vê cada item no menu

As páginas aparecem automaticamente na barra lateral (grupo **Sistema**) para qualquer usuário com ao menos uma das permissões listadas:

| Item de menu | Grupo | Permissão necessária (qualquer uma) |
|---|---|---|
| Cockpit Interno MAG | Sistema | sales.view, finance.view, fiscal.view, geo.view, admin.view, **logs.view** |
| Catálogo de KPIs | Sistema | settings.view, admin.view, logs.view |

O menu é filtrado server-side via `views/partials/sidebar.php` (campo `permission_any`). A página continua protegida por `Auth::requireAnyCapability()` mesmo se a URL for acessada diretamente — esconder o link não substitui a proteção.

#### Como testar por perfil

Execute o script de simulação para validar o comportamento esperado por perfil sem precisar de sessão real:

```bash
php tools/dev/nav_rbac_sim.php
```

Saída esperada: `29/29 PASS`.

Roteiro de teste manual por perfil (6 cenários):

**1. Administrador (role=admin)**
- Permissões: admin.view, settings.view, logs.view, sales.view, finance.view, fiscal.view, geo.view
- Cockpit no menu: ✓ visível
- Catálogo no menu: ✓ visível
- `cockpit_interno.php` → 200
- `metric_catalog.php` → 200
- `api/metric_catalog.php` → 200 JSON
- `api/semantic_health.php` → 200 JSON

**2. Gestor comercial (role=manager com sales.view)**
- Permissões: sales.view, finance.view, fiscal.view, geo.view, logs.view (sem admin.view, sem settings.view)
- Cockpit no menu: ✓ visível (via sales.view)
- Catálogo no menu: ✓ visível (via logs.view)
- `cockpit_interno.php` → 200
- `metric_catalog.php` → 200

> Nota: o role `manager` inclui `logs.view` na matriz padrão. Se o objetivo for testar "manager sem acesso ao catálogo", usar um usuário com apenas `sales.view` via override de módulos.

**3. Viewer com geo.view (role=viewer)**
- Permissões: sales.view, commercial.view, dashboard.view, geo.view
- Cockpit no menu: ✓ visível (via sales.view ou geo.view)
- Catálogo no menu: ✗ oculto
- `cockpit_interno.php` → 200
- `metric_catalog.php` → 403

**4. Usuário com settings.view apenas**
- Permissões: settings.view (sem sales/finance/fiscal/geo/admin.view)
- Cockpit no menu: ✗ oculto
- Catálogo no menu: ✓ visível
- `cockpit_interno.php` → 403
- `metric_catalog.php` → 200

**5. Usuário com logs.view apenas (monitoramento)**
- Permissões: logs.view
- Cockpit no menu: ✓ visível (logs.view está em permission_any do Cockpit)
- Catálogo no menu: ✓ visível
- `cockpit_interno.php` → 200
- `metric_catalog.php` → 200
- `api/semantic_health.php` → 200 JSON

**6. Usuário sem permissões relevantes**
- Permissões: apenas commercial.view, dashboard.view
- Cockpit no menu: ✗ oculto
- Catálogo no menu: ✗ oculto
- `cockpit_interno.php` → 403 (redirect para login)
- `metric_catalog.php` → 403 (redirect para login)
- `api/metric_catalog.php` → 403 JSON
- `api/semantic_health.php` → 403 JSON

#### Como interpretar 403

| Contexto | Comportamento |
|---|---|
| Página `.php` (cockpit, catálogo) | Redirect para login ou página de erro (não JSON) |
| API (`api/*.php`) | JSON `{"ok":false,"message":"...","request_id":"..."}` com HTTP 403 |
| Sem sessão ativa | Redirect para login em páginas; 401/403 JSON em APIs |

Se você ver 403 ao tentar acessar uma página:
1. Verifique se está logado (`admin.php` → aba usuários → capabilities do seu usuário).
2. Verifique se a capability necessária aparece na tabela acima.
3. Solicite ao administrador que adicione a capability ao seu perfil.

#### Como validar semantic_health

```bash
# Via curl (requer cookie de sessão válida)
curl -b "session=SEU_COOKIE" "https://SEU-DOMINIO/biomie/api/semantic_health.php"
```

Campos esperados na resposta:

| Campo | Significado |
|---|---|
| `semantic.audit_ok` | true se 0 erros na auditoria semântica |
| `semantic.kpis.active` | Número de KPIs em produção |
| `semantic.kpis.draft` | Número de KPIs ainda em rascunho |
| `freshness.by_domain` | Status fresh/stale por domínio de negócio |
| `freshness.gold_job.status` | Status do job gold_mart_refresh |

#### Como saber se KPI está draft

KPIs com `status=draft` aparecem com badge amarelo `draft` no Cockpit e no Catálogo. Eles indicam que a definição formal ainda não foi finalizada — fonte incerta, denominador indefinido ou regra não verificada contra dados reais.

**Não usar dados de KPIs draft em relatórios executivos.** Para promover um KPI de draft para active, consulte a seção "Interpretar KPI draft" acima.

#### Como validar menu após alteração de permissão

Após ajustar capabilities de um usuário em `admin.php`:
1. Fazer logout e login novamente (as capabilities são lidas na sessão).
2. Verificar se o item aparece/desaparece na barra lateral.
3. Para validar programaticamente: `php tools/dev/nav_rbac_sim.php`.
4. Para auditar a estrutura do menu: `php tools/dev/menu_audit.php`.

---

## Drilldown Comercial / Fiscal (Fase 8B)

### Como usar o drilldown no dia a dia

O drilldown permite navegar de agregados (dashboard, tabelas) até o detalhe de cada entidade sem sair da tela. Toda a navegação é via drawers laterais.

**Fluxo principal:**
```
Dashboard / tabela
  └─ clique no nome do vendedor → painel Vendor 360
       └─ aba Pedidos → clique no pedido → painel Detalhe do Pedido
            └─ aba NFs → clique na NF → painel Detalhe da NF
                 └─ aba Financeiro → lista de títulos (sem drilldown adicional)
```

- Para fechar qualquer painel: pressione `Esc` ou clique no botão `×`.
- Ao fechar, o foco retorna ao elemento que abriu o painel.
- Painéis são cacheados por 60 segundos — reabrir o mesmo item não refaz a requisição.

### Como buscar um pedido ou NF

Na tela `pedidos.php`, acima das tabelas existe a **barra de busca**:

| Campo | Uso |
|---|---|
| Campo de texto | Busca livre por número do pedido, nome do cliente, CPF/CNPJ, código de integração |
| Vendedor | Filtra por nome parcial ou ID do vendedor |
| Status | Filtra pelo `status_comercial` exato |
| Período | Data de emissão início e fim (`YYYY-MM-DD`) |
| Valor mín/máx | Faixa de valor total |

A busca acontece com debounce de 300 ms ao digitar e o endpoint consultado é `api/orders_search.php` (nunca chama o Omie diretamente).

**Resultado da busca:**
- Clique no número do pedido → abre `BIomieOrderDetail`
- Clique no número da NF → abre `BIomieInvoiceDetail`

**Deep-link direto para um pedido:**
```
pedidos.php?q=PED-2025-00123
```

### Como analisar um vendedor

**Via tabela de vendedores (`vendedores.php`):**
1. Clique no nome do vendedor na tabela.
2. O painel **Vendor 360** abre com: realizado, meta, gap, attainment e indicador de risco.
3. Use as abas: **Resumo** (KPIs + gráficos), **Pedidos** (lista paginada), **NFs** (notas emitidas), **Clientes** (top clientes), **Produtos** (top produtos).

**Deep-link direto:**
```
vendedores.php?vendor_id=12345
vendedores.php?vendor_id=12345&start=2025-01-01&end=2025-12-31
```

**Gráficos disponíveis no Vendor 360:**
- Receita diária (linha)
- Breakdown por status (pizza)
- Top clientes (barras)
- Top produtos (barras)

**Permissão necessária:** `sales.view`

### O que fazer se uma NF não tiver DANFE/XML local

O painel da NF exibe "DANFE não disponível" ou "XML não disponível" quando a tabela `biomie_xml_documentos_canonicos` não tem entrada para aquela nota.

**Causa provável:** o cron de sincronização de XML não cobre o período ou está inativo.

**Diagnóstico:**
```sql
-- Verificar se a NF tem entrada na tabela de documentos
SELECT * FROM biomie_xml_documentos_canonicos
WHERE numero_nfe = '12345' OR chave_nfe = '35250123456789...';

-- Verificar jobs recentes de sync de XML
SELECT job_name, status, started_at, completed_at, error_message
FROM biomie_pipeline_jobs
WHERE job_name LIKE '%xml%' OR job_name LIKE '%danfe%'
ORDER BY started_at DESC LIMIT 10;
```

**Ação:**
1. Verificar se o cron de sync XML está ativo e cobriand o período da NF.
2. Executar manualmente se necessário:
   ```bash
   php bin/sync_xml_documentos.php
   ```
3. O painel da NF mostra os links automaticamente após a próxima sincronização — não é necessário atualizar nenhum cache manualmente.

> **Nota:** A ausência de DANFE/XML não impede a visualização dos dados fiscais da NF (itens, totais, datas, títulos financeiros). É somente o PDF/XML que fica indisponível.

### Permissões por funcionalidade de drilldown

| Funcionalidade | Permissão mínima |
|---|---|
| Painel Vendor 360 | `sales.view` |
| Painel Detalhe do Pedido | `sales.view` OU `fiscal.view` |
| Painel Detalhe da NF | `fiscal.view` OU `sales.view` |
| Busca de pedidos | `sales.view` OU `fiscal.view` |

### Testar drilldown manualmente

1. Abrir `pedidos.php`
2. Clicar no número de uma NF → deve abrir painel `BIomieInvoiceDetail`
3. Na aba Resumo, clicar "Ver pedido origem" → deve abrir painel `BIomieOrderDetail`
4. Na aba Resumo do pedido, clicar no nome do vendedor → deve abrir painel `BIomieVendorDetail`
5. Na aba NFs do vendedor, clicar em uma NF → deve abrir `BIomieInvoiceDetail`
6. Pressionar `Esc` → deve fechar o painel ativo e devolver foco ao elemento de origem

---

## Saúde do Sistema (Fase 9)

A tela **Saúde do Sistema** (`system_health.php`) centraliza 8 checks de infraestrutura, dados e jobs em tempo real.

**Acesso:** menu Sistema → Saúde do Sistema (requer `logs.view`, `jobs.view` ou `admin.view`)

### Checks disponíveis

| Check | O que verifica |
|---|---|
| `database` | Conectividade MySQL e tabelas críticas |
| `jobs` | SLA do `gold_mart_refresh` em `biomie_pipeline_jobs` |
| `freshness` | Freshness dos domínios gold, canonical, sales, fiscal, geo |
| `semantic` | Erros/avisos na definição dos KPIs |
| `shadow` | Taxa de divergência shadow validation nas últimas 24h |
| `customer_quality` | % sem CPF/CNPJ e sem nome em `biomie_clientes_dim` |
| `geo_quality` | Enrichment geográfico de clientes e `biomie_bairros_fortaleza_dim` |
| `webhook_inbox` | Fila pendente em `biomie_webhook_inbox` |

### Status dos checks

- **ok** — tudo dentro do esperado
- **warning** — atenção, degradação leve
- **critical** — ação necessária, SLA ou threshold violado
- **unknown** — check não pôde ser executado (tabela ausente, banco inacessível)

O status geral da página é o pior entre todos os checks: `critical > warning > unknown > ok`.

### Como usar no dia a dia

**Ver saúde geral:**
1. Acessar `system_health.php` via menu Sistema
2. Banner no topo indica status global
3. Cards individuais mostram métricas e ações sugeridas

**Via CLI (automação/CI):**
```bash
# Status geral (código de saída: 0=ok, 1=warning, 2=unknown, 3=critical)
php tools/dev/system_health_check.php

# JSON para scripts
php tools/dev/system_health_check.php --json

# Check específico
php tools/dev/system_health_check.php --check jobs,freshness
```

### O que fazer quando um check está crítico

**`database` critical:**
- Verificar credenciais em `config/env.php`
- Testar conectividade: `php tools/dev/db_check.php`
- Se tabelas ausentes: rodar migrations em `admin.php?tab=migrations`

**`jobs` critical (gold_mart_refresh atrasado):**
- Verificar se o cron `cron/materialize_marts.php` está ativo
- Ver logs: `storage/logs/biomie.log`
- Executar manualmente: `php cron/materialize_marts.php`

**`freshness` critical/warning:**
- Geralmente decorre de falha no jobs
- Resolver o check `jobs` primeiro

**`customer_quality` ou `geo_quality` critical:**
- Executar enriquecimento: `php cron/enrich_customers.php`
- Ver `docs/CUSTOMER_ENRICHMENT.md` para detalhes

**`webhook_inbox` critical (muitos pendentes):**
- Verificar worker: `php cron/worker.php --queue=high`
- Ver `docs/JOBS_E_FRESHNESS.md`

### Permissões

| Funcionalidade | Permissão mínima |
|---|---|
| Tela `system_health.php` | `logs.view` OU `jobs.view` OU `admin.view` |
| API `api/system_health.php` | `logs.view` OU `jobs.view` OU `admin.view` |
| CLI `system_health_check.php` | execução direta (sem RBAC) |

---

## Mapa de Vendas por Bairro — Diagnóstico e Rebuild (Fase 10C)

### Sintoma: mapa mostra R$0 e bairro errado (Aerolândia)

Causa raiz: `mart_cliente_periodo` vazio para a competência do filtro. Como `mart_compradores_por_geo` depende deste mart, o mapa retorna faturamento zero e, com todos os valores em zero, o bairro "Aerolândia" aparece primeiro por ordem alfabética.

### Pipeline de dados do mapa

```
biomie_notas_fiscais_canonicas   (canonical — fonte de verdade)
         ↓  CustomerPeriodMartBuilder (por competência)
mart_cliente_periodo              (gold mart mensal)
         ↓  GeoBuyerMartBuilder   (por período de datas)
mart_compradores_por_geo          (geo mart — fonte do mapa)
         ↓  api/geo_summary.php + api/sales_by_neighborhood.php
mapa_vendas.php
```

### Por que o mês corrente fica fora do rebuild automático

`cron/materialize_competencia_snapshots.php` rejeita meses ainda abertos.
O mês corrente só entra no ciclo automático ao fechar.

### Diagnóstico rápido

```bash
# Rastreia cada camada para o período
php tools/dev/geo_sales_debug.php --start=2026-05-01 --end=2026-05-31 --city=Fortaleza

# Verifica mínimos esperados para maio 2026 (NFs >= 70, faturamento >= R$120k, bairros >= 50)
php tools/dev/geo_sales_assert_may2026.php
```

### Rebuild do período (mês corrente ou qualquer período)

**Via CLI:**
```bash
# Rebuild básico
php cron/geo_rebuild_period.php --start=2026-05-01 --end=2026-05-31

# Com validação automática ao final
php cron/geo_rebuild_period.php --start=2026-05-01 --end=2026-05-31 --assert

# Saída JSON para automação
php cron/geo_rebuild_period.php --start=2026-05-01 --end=2026-05-31 --json
```

**Via API (Admin Console — requer `admin.view` + CSRF):**
```
POST api/admin_geo_rebuild.php
Content-Type: application/x-www-form-urlencoded
Body: start=2026-05-01&end=2026-05-31
```

O endpoint retorna JSON com `canonical.nfs_autorizadas`, `geo_result.faturamento`,
`geo_result.bairros_distintos` e antes/depois de cada tabela.

**Via mapa (usuário admin):**
Quando o mapa detecta o problema, exibe o botão **"Reprocessar período"**
que aciona o endpoint automaticamente e recarrega o mapa ao concluir.

### Rotas e permissões

| Rota | Método | Permissão |
|------|--------|-----------|
| `api/admin_geo_rebuild.php` | POST | `admin.view` OU `settings.manage` |
| `api/geo_summary.php` | GET | `geo.view` |
| `api/sales_by_neighborhood.php` | GET | `geo.view` |

### Critérios de aceite pós-rebuild

Após executar o rebuild para maio 2026:

- `mart_cliente_periodo` com `competencia='2026-05'` deve ter linhas com `nfes_emitidas > 0`
- `mart_compradores_por_geo` com `competencia='2026-05'` deve ter `faturamento > 0`
- `bairros_distintos` deve ser ≥ 50
- `best_neighborhood` deve ser diferente de "Aerolândia" e diferente de vazio
- `php tools/dev/geo_sales_assert_may2026.php` deve retornar exit 0 (10/10 assertions)

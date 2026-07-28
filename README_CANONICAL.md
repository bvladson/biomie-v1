# Camada Canonica de Vendas - Guia de Ativacao

## Governanca Semantica

Antes de expandir a camada canonica, consulte [README_MATRIZ_VERDADE_OMIE.md](README_MATRIZ_VERDADE_OMIE.md).

Esse documento registra:
- a matriz oficial de verdade Omie -> BIomie
- as datas oficiais por KPI
- as chaves naturais por dominio
- a politica de conflito
- o bloco preparatorio da Fase 2

Ele deve ser tratado como contrato de referencia para evolucoes da camada canonica.

## 1. Diagnostico

### Descobrir o path real no Hostinger

Via SSH ou Terminal do hPanel:

```bash
cd ~/public_html/biomie && pwd
# ou
cd ~/domains/magmoveiscorporativos.com.br/public_html/biomie && pwd
```

A saida sera algo como:

```text
/home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie
```

Guarde esse path. Ele e usado em todos os crons.

### Verificar estado atual

```bash
curl -s https://magmoveiscorporativos.com.br/biomie/healthz.php | python3 -m json.tool
```

Espere ver:
- `canonical.tables_present: true` apos rodar o SQL
- `canonical.vendor_count: 0` antes do primeiro sync
- `canonical.reads_enabled: {vendors: false, orders: false, overview: false}`

## 2. Criar as tabelas

O caminho oficial agora e via migrations:

```bash
php bin/schema_migrate.php status
php bin/schema_migrate.php migrate
```

O runbook completo fica em [README_MIGRATIONS.md](README_MIGRATIONS.md).

Se voce estiver provisionando um ambiente legado manualmente, ainda pode usar:

```text
database/canonical_sales.sql
```

Esse script agora provisiona:
- camada bronze `biomie_omie_bronze`
- event store canonico `biomie_eventos_canonicos`
- camada canonica comercial e fiscal
- documentos XML canonicos `biomie_documentos_xml_canonicos`
- titulos financeiros canonicos `biomie_titulos_financeiros_canonicos`
- inbox duravel de webhook `biomie_webhook_inbox`
- cursores operacionais `biomie_sync_cursors`
- run log operacional `biomie_sync_runs`
- tabelas de divergencia, shadow validation e sync state

As tabelas de autenticacao, snapshots de competencia, metas do plano de voo e runs de reconciliacao passaram a ter migrations versionadas.

## Observabilidade Operacional

A partir da Fase 6, o projeto tambem passou a persistir:
- eventos operacionais `biomie_operational_events`
- metricas operacionais `biomie_operational_metrics`
- spans/traces `biomie_operational_spans`

O dashboard interno admin-only fica em `observabilidade.php` e consome `api/operational_observability.php`.

Ele cruza:
- pipeline realtime
- freshness dos datasets
- shadow validation
- divergencias abertas
- tempos de metodo Omie

## 3. Primeira sincronizacao

Via SSH:

```bash
cd /home/uXXXX/domains/magmoveiscorporativos.com.br/public_html/biomie
/usr/bin/php -f cron/sync_canonical.php
```

Ou via interface admin, com POST autenticado:

```text
POST /biomie/api/canonical_sync.php?start=2026-03-01&end=2026-03-31
```

Espere ver no output algo como:

```json
{"ok":true,"vendors_synced":10,"orders":{"inserted":306}}
```

## 4. Validar o sync

```bash
curl -s https://magmoveiscorporativos.com.br/biomie/healthz.php | python3 -m json.tool
```

Agora deve mostrar:
- `canonical.vendor_count: > 0`
- `canonical.order_count: > 0`
- `canonical.last_sync: "2026-03-30T..."`

## 5. Ligar as feature flags

No arquivo `config/.env.local.php`, adicionar:

```php
return [
    // ... credenciais existentes ...
    'CANONICAL_READS_VENDORS' => '1',
    'CANONICAL_READS_ORDERS' => '1',
    'CANONICAL_READS_OVERVIEW' => '1',
];
```

As flags podem ser ligadas uma por uma. Comece por `VENDORS`, valide, depois `ORDERS`, depois `OVERVIEW`.

### Verificar apos ligar

- `GET /biomie/api/vendors.php` deve retornar `"source": "canonical"`
- `GET /biomie/api/orders.php` deve retornar `"source": "canonical"`
- `GET /biomie/healthz.php` deve mostrar `sources.vendors: "canonical"`

### Rollback instantaneo

Para desligar, basta mudar a flag para `'0'` no `.env.local.php`. Nenhum deploy e necessario.

## 6. Configurar crons

### Descobrir o path obrigatorio

```bash
cd ~/public_html/biomie && pwd
```

Resultado esperado:

```text
/home/uXXXXX/domains/magmoveiscorporativos.com.br/public_html/biomie
```

### Opcao A - Tipo PHP no hPanel

| Frequencia | Path no campo PHP |
| --- | --- |
| A cada 1 min | `/home/uXXXX/.../biomie/cron/process_webhook_inbox.php 20` |
| A cada 5 min | `/home/uXXXX/.../biomie/cron/sync_realtime_pipeline.php --domains=commercial` |
| A cada 5 min | `/home/uXXXX/.../biomie/cron/sync_canonical.php` |
| A cada 15 min | `/home/uXXXX/.../biomie/cron/sync_realtime_pipeline.php --domains=finance` |
| Diario 6:00 UTC | `/home/uXXXX/.../biomie/cron/sync_canonical.php --reconcile 60` |
| Diario 6:15 UTC | `/home/uXXXX/.../biomie/cron/sync_realtime_pipeline.php --reconcile 30` |
| Diario 6:30 UTC | `/home/uXXXX/.../biomie/cron/reconcile_exact_queue.php --days=30` |
| A cada 30 min | `/home/uXXXX/.../biomie/cron/sync_finance.php` |
| A cada 2 min | `/home/uXXXX/.../biomie/cron/process_refresh_queue.php 5` |

### Opcao B - Tipo Custom recomendado

Primeiro torne os scripts executaveis:

```bash
chmod +x /home/uXXXX/.../biomie/cronjobs/*.sh
```

Depois no hPanel > Cron Jobs > Type: Custom:

| Frequencia | Comando |
| --- | --- |
| `* * * * *` | `/home/uXXXX/.../biomie/cron/process_webhook_inbox.php 20` |
| `*/5 * * * *` | `/home/uXXXX/.../biomie/cron/sync_realtime_pipeline.php --domains=commercial` |
| `*/5 * * * *` | `/home/uXXXX/.../biomie/cronjobs/sync_canonical_delta.sh` |
| `*/15 * * * *` | `/home/uXXXX/.../biomie/cron/sync_realtime_pipeline.php --domains=finance` |
| `0 6 * * *` | `/home/uXXXX/.../biomie/cronjobs/sync_canonical_reconcile.sh` |
| `15 6 * * *` | `/home/uXXXX/.../biomie/cron/sync_realtime_pipeline.php --reconcile 30` |
| `30 6 * * *` | `/home/uXXXX/.../biomie/cron/reconcile_exact_queue.php --days=30` |
| `*/30 * * * *` | `/home/uXXXX/.../biomie/cronjobs/sync_finance.sh` |
| `*/2 * * * *` | `/home/uXXXX/.../biomie/cronjobs/process_refresh_queue.sh` |

Hostinger roda crons em UTC. Fortaleza = UTC-3. Entao 3h local = 6h UTC.

## 7. Trava de concorrencia

Todos os crons usam `GET_LOCK` do MariaDB:
- `biomie:sync_canonical`
- `biomie:sync_finance`
- `biomie:refresh_queue`
- `biomie:webhook_inbox`
- `biomie:realtime_pipeline`
- `biomie:reconcile_exact`

Se um job ja estiver rodando, o proximo sai com exit `0`, sem erro, e registra skip por execucao concorrente.

## 8. Monitoramento

### healthz.php sem auth

```text
GET /biomie/healthz.php
```

Mostra: tabelas, contagens, ultima sync, flags ativas e fontes em uso.

### api/canonical_sync.php admin-only

```text
GET /biomie/api/canonical_sync.php?start=2026-03-01&end=2026-03-31
```

Mostra: resumo canonico, divergencias abertas e mapa de vendedores.

### api/reconciliation.php admin-only

```text
GET /biomie/api/reconciliation.php?start=2026-03-01&end=2026-03-31
```

Mostra: totais por vendedor em faturado, autorizado e cancelado. E o melhor ponto para conferir BIomie x planilha.

### api/reconciliation_queue.php admin-only

```text
GET /biomie/api/reconciliation_queue.php?start=2026-03-01&end=2026-03-31&statuses=aberta,reincidente
POST /biomie/api/reconciliation_queue.php?action=scan&start=2026-03-01&end=2026-03-31
POST /biomie/api/reconciliation_queue.php?action=update_status&id=123&status=resolvida
```

Mostra: catalogo tipado de divergencias, resumo por severidade/status e fila operacional com abertas, reincidentes, resolvidas e ignoradas por regra.

### api/domain_entity_map.php admin-only

```text
GET /biomie/api/domain_entity_map.php
```

Mostra o mapa logico de entidades da Fase 5, separando comercial, fiscal, financeiro e gold.

### api/realtime_sync_status.php admin-only

```text
GET /biomie/api/realtime_sync_status.php
```

Mostra: contagem da inbox, cursores por dominio, runs recentes e defaults de cadencia da Fase 4.

### api/omie_method_policy.php admin-only

```text
GET /biomie/api/omie_method_policy.php
```

Mostra: politica tecnica por metodo Omie, incluindo page size alvo, concorrencia maxima, janela recomendada, retry, fallback e protecao contra consulta redundante.

### Contrato de frescor nos endpoints criticos

Os endpoints `overview`, `orders`, `vendors`, `finance_overview`, `finance_titles` e `reconciliation` agora retornam tambem:

- `data_contract.source`
- `data_contract.freshness_at`
- `data_contract.coverage_start`
- `data_contract.coverage_end`
- `data_contract.reconciled_until`
- `data_contract.staleness_reason`
- `data_contract.reconcile_status`
- `data_contract.reconciliation_pct`
- `data_contract.open_divergences`
- `data_contract.data_mode`

### api/omie_webhook.php public 2XX-only

```text
POST /biomie/api/omie_webhook.php?domain=commercial&endpoint_group=pedido
```

Esse endpoint so persiste bronze + inbox e devolve `202`. O processamento fica para `cron/process_webhook_inbox.php`.

### Logs de cron

```bash
tail -f storage/logs/cron_canonical.log
tail -f storage/logs/cron_finance.log
tail -f storage/logs/cron_queue.log
tail -f storage/logs/biomie.log
```

### CLI de reconciliacao exata

```bash
php cron/reconcile_exact_queue.php --days=30
php cron/reconcile_exact_queue.php --start=2026-03-01 --end=2026-03-31
```

### CLI de homologacao de consistencia

```bash
php tests/run_consistency_homologation.php
```

Executa a bateria de casos da Fase 9 com fixture documentada de entrada Omie, estado canonico esperado, KPI esperado e divergencia esperada.

## 9. O que ficou canonico vs legado

| Modulo | Fonte com flag ON | Fonte com flag OFF |
| --- | --- | --- |
| `vendedores.php` | `canonical` | `legacy` com Omie direto |
| `pedidos.php` | `canonical` | `legacy` |
| cockpit vendor ranking | `canonical` overlay | `legacy` |
| plano de voo metas | `canonical` (biomie_sales_targets_monthly via FlightPlanTargetsService) | — |
| cockpit daily_sales, products e alerts | `canonical` | — |
| `financeiro.php` | `canonical` (FIN.R2 fallback canônico) | emergency-only |
| plano de voo principal | `canonical` (FlightPlanV2Service, sem fallback legado) | — |

> Nota 2026-07-12: a tabela acima foi atualizada — o Plano de Voo é servido
> incondicionalmente pelo FlightPlanV2Service ("authoritative source, no
> legacy fallback" em api/flight_plan.php). O contrato vigente é
> docs/flight-plan-contract.md (year_month + data_competencia).

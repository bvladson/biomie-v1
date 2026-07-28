# System Health — BIomie

**Fase:** 9 — System Health & Geo
**Status:** Implementado

## Visão Geral

O módulo System Health fornece um relatório de saúde em tempo real do sistema BIomie, cobrindo banco de dados, pipeline de jobs, freshness de dados, semântica de KPIs, shadow validation, qualidade de dados de clientes, qualidade de dados geográficos e webhook inbox.

**Permissão:** `logs.view | jobs.view | admin.view`

## Arquitetura

```
app/SystemHealth/
  CheckInterface.php          — Interface que todos os checks implementam
  SystemHealthCheck.php       — DTO imutável do resultado de um check
  SystemHealthReport.php      — Agregado de N checks + status overall
  SystemHealthService.php     — Orquestrador (roda os 8 checks, degrada graciosamente)
  Checks/
    DatabaseHealthCheck.php   — SELECT 1 + contagem de tabelas críticas
    JobsHealthCheck.php       — SLA do gold_mart_refresh em biomie_pipeline_jobs
    FreshnessHealthCheck.php  — FreshnessService::forDomain() para 5 domínios
    SemanticHealthCheck.php   — SemanticMetricValidator — erros/avisos de KPIs
    ShadowHealthCheck.php     — Taxa de divergência em biomie_shadow_validations
    CustomerDataQualityCheck.php — % sem CPF/CNPJ e sem nome em biomie_clientes_dim
    GeoDataQualityCheck.php   — Enrichment rate geo + bairros_fortaleza_dim
    WebhookInboxHealthCheck.php — Fila pendente em biomie_webhook_inbox
```

## Endpoint

### `GET api/system_health.php`

**Permissão:** `logs.view | jobs.view | admin.view`

**Resposta:**
```json
{
  "ok": true,
  "status": "ok|warning|critical|unknown",
  "generated_at": "2026-05-09T10:00:00+00:00",
  "checks": [
    {
      "id": "database",
      "label": "Banco de Dados",
      "status": "ok",
      "severity": "info",
      "summary": "42 tabelas biomie_ disponíveis, tabelas críticas populadas.",
      "metrics": { "biomie_tables_count": 42, "empty_critical_tables": 0 },
      "actions": [],
      "checked_at": "2026-05-09T10:00:00+00:00",
      "duration_ms": 12
    }
  ]
}
```

## Status dos Checks

| Status | Significado |
|---|---|
| `ok` | Check passou sem problemas |
| `warning` | Atenção — degradação leve ou métrica fora do ideal |
| `critical` | Ação requerida — SLA violado ou dado ausente |
| `unknown` | Check não pôde ser executado (tabela ausente, exceção) |

**Agregação:** `critical > warning > unknown > ok` — o status overall é o pior de todos os checks.

## Checks Individuais

### `database` — Banco de Dados

| Condição | Status |
|---|---|
| Falha ao conectar (`SELECT 1`) | critical |
| Tabelas críticas ausentes (`biomie_pedidos_canonicos`, etc.) | critical |
| Tabelas críticas existem mas vazias | warning |
| Tudo OK | ok |

### `jobs` — Pipeline Jobs

Monitora `biomie_pipeline_jobs` para o job `gold_mart_refresh`.

| Condição | Status |
|---|---|
| Nenhum job com sucesso encontrado | critical |
| Último sucesso há > 180 min | critical |
| Último sucesso há 60–180 min | warning |
| Último sucesso há < 60 min | ok |

### `freshness` — Freshness de Dados

Usa `FreshnessService::forDomain()` para os domínios: `gold`, `canonical`, `sales`, `fiscal`, `geo`.

| Condição | Status |
|---|---|
| Qualquer domínio com status `failed` | critical |
| Qualquer domínio `stale` ou `partial` | warning |
| Todos frescos | ok |

### `semantic` — Semântica de KPIs

Executa `SemanticMetricValidator` sobre `MetricRegistry`.

| Condição | Status |
|---|---|
| Erros semânticos encontrados | critical |
| Avisos semânticos | warning |
| 0 erros, 0 avisos | ok |

### `shadow` — Shadow Validation

Taxa de divergência nas últimas 24h em `biomie_shadow_validations`.

| Condição | Status |
|---|---|
| Tabela indisponível ou sem registros | unknown (não-bloqueante) |
| Taxa ≥ 15% | critical |
| Taxa 5–15% | warning |
| Taxa < 5% | ok |

### `customer_quality` — Qualidade de Dados de Clientes

Cobertura de CPF/CNPJ e nome em `biomie_clientes_dim`.

| Condição | Status |
|---|---|
| Tabela vazia | warning |
| Pior % (doc ou nome) ≥ 30% | critical |
| Pior % 10–30% | warning |
| Pior % < 10% | ok |

### `geo_quality` — Qualidade de Dados Geográficos

Enrichment rate de clientes e população de `biomie_bairros_fortaleza_dim`.

| Condição | Status |
|---|---|
| Falha ao consultar tabelas geo | unknown |
| Enrichment < 50% dos clientes | critical |
| Enrichment 50–80% ou bairros < 100 | warning |
| Enrichment ≥ 80% e bairros ≥ 100 | ok |

### `webhook_inbox` — Webhook Inbox

Fila pendente em `biomie_webhook_inbox`.

| Condição | Status |
|---|---|
| Tabela indisponível | unknown (não-bloqueante) |
| ≥ 50 pendentes ou mais antigo ≥ 60 min | critical |
| ≥ 10 pendentes ou mais antigo ≥ 10 min | warning |
| < 10 pendentes e mais antigo < 10 min | ok |

## CLI

```bash
# Relatório textual
php tools/dev/system_health_check.php

# JSON (para scripts/CI)
php tools/dev/system_health_check.php --json

# Check específico
php tools/dev/system_health_check.php --check database
php tools/dev/system_health_check.php --check jobs,freshness

# Códigos de saída
# 0 = ok | 1 = warning | 2 = unknown | 3 = critical
```

## Página Web

`system_health.php` — interface visual com cards por check, banner de status geral e botão de atualização.

**Permissão:** `logs.view | jobs.view | admin.view` (via `Auth::requireAnyCapability`)

## Degradação Graciosa

O `SystemHealthService` envolve cada check em `try/catch`. Se um check lançar exceção não tratada, o resultado é `unknown` (nunca `critical`), garantindo que o relatório sempre retorna — mesmo que parcialmente.

Checks de tabelas opcionais (`biomie_shadow_validations`, `biomie_webhook_inbox`) retornam `unknown` com `severity=info` quando a tabela não existe, sem bloquear o status overall.

## Invariantes

- Nenhum check chama o Omie diretamente.
- Nenhum check executa sync ou operações de escrita.
- Timeouts de conexão vêm da configuração `db.timeout` (padrão: 5s).
- O endpoint nunca retorna 500 — exceções são capturadas pelo `SystemHealthService`.
- Permissão obrigatória verificada antes de qualquer execução de check.

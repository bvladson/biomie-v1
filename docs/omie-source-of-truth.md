# BIomie — Fonte Oficial dos Dados (Omie como Source of Truth)

## Regra Absoluta

**É proibido subir planilha, CSV, Excel ou arquivo consolidado manual para popular dados do BIomie.**

A fonte oficial de todos os dados de vendas, clientes e notas fiscais é exclusivamente a **API Omie**.
Qualquer dado que não transite pelo pipeline abaixo não é dado oficial do sistema.

## Pipeline Oficial

```
API Omie
  │  ListarNF, ConsultarNF, ListarPedidos, ListarVendedores
  ↓
Bronze (raw JSON)
  │  biomie_omie_bronze_nfs, biomie_omie_bronze_pedidos
  ↓
Canônico (Silver)
  │  biomie_notas_fiscais_canonicas
  │  biomie_notas_fiscais_itens_canonicos
  │  biomie_pedidos_canonicos
  ↓
Gold / Marts
  │  mart_cliente_periodo
  │  mart_produto_periodo
  │  mart_compradores_por_geo
  ↓
APIs internas (api/*.php)
  ↓
Dashboard / Vendedores / Mapa / Flight Plan
```

## Uso Correto da Planilha Pivot2

A planilha Pivot2 do Omie pode ser usada **somente como referência externa de auditoria visual**.

- **Nunca** como entrada do sistema
- **Nunca** em tabelas de staging
- **Nunca** importada via script
- **Nunca** como dependência de funcionamento de qualquer feature

## Reconciliação Omie API × Canônico

Para verificar se os dados locais estão em sincronia com a API Omie, use:

```bash
# Reconciliar o mês corrente (API Omie vs banco canônico)
php tools/dev/reconcile_omie_api_fiscal.php --json

# Especificar período
php tools/dev/reconcile_omie_api_fiscal.php --start=2026-05-01 --end=2026-05-31 --json

# Ver apenas o banco canônico sem chamar a API
php tools/dev/reconcile_omie_api_fiscal.php --canonical-only --json

# Detalhar cada divergência
php tools/dev/reconcile_omie_api_fiscal.php --explain
```

Este script usa `OmieApiReconciliationService` e `OmieRepository::listInvoicesByIssueDate()`.
Não depende de CSV, planilha ou qualquer arquivo externo.

## Corrigindo o Dashboard Zerado

Se o dashboard mostra R$0 / 0 clientes / 0 NFs, o fluxo de debug correto é:

1. **Verificar sincronização Omie → Bronze → Canônico**
   ```bash
   php cron/sync_realtime_pipeline.php --domains=fiscal --mode=reconcile --reconcile=30 --json
   ```

2. **Verificar flags de feature flags (canonical reads)**
   ```bash
   php tools/dev/overview_debug.php --json
   ```

3. **Reconciliar API vs banco**
   ```bash
   php tools/dev/reconcile_omie_api_fiscal.php --explain
   ```

4. **Rematerializar marts Gold**
   ```bash
   php bin/materialize_marts.php --json
   ```

5. **Limpar cache**
   ```bash
   php tools/dev/cache_clear.php
   ```

**Nunca** corrija dados do dashboard subindo arquivo CSV ou planilha.
Se há divergência, a origem está na sincronização Omie → canônico.

## Limites de Taxa da API Omie

| Limite | Valor |
|--------|-------|
| Por IP | 960 req/min |
| Por IP + AppKey + Método | 240 req/min |
| Simultâneas por IP + AppKey + Método | 4 |

O `OmieClient` respeita esses limites via `OmieMethodPolicyRegistry` com sleep/backoff automático.

## Tabelas Proibidas

As seguintes tabelas **não devem existir** no banco de produção:

- `omie_pivot2_faturamento_staging` — staging de CSV; removida pela migration `20260517000001`
- Qualquer tabela `*_import*`, `*_staging_csv*`, `*_excel*`

A migration `Version20260517000001_DropPivot2StagingTable` detecta e remove automaticamente
a tabela de staging se estiver vazia, ou a renomeia para `*_deprecated_YYYYMMDD` com alerta
no log se contiver dados.

## Health Check

O sistema verifica a ausência de artefatos proibidos via `tools/dev/system_health_check.php`.
Qualquer sinal de import manual é reportado como falha crítica.

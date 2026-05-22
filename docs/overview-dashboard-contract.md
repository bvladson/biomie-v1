# Overview Dashboard — API Contract

**Endpoint:** `GET api/overview.php`  
**Auth:** `sales.view`  
**Source of truth:** `biomie_notas_fiscais_canonicas` (status_fiscal = 'autorizada', data_competencia)

---

## Response shape

```json
{
  "ok": true,
  "mode": "canonical|hybrid",
  "source": "canonical|canonical+legacy",
  "period": {
    "start": "YYYY-MM-DD",
    "end": "YYYY-MM-DD",
    "label": "...",
    "realized_period_basis": "data_competencia"
  },
  "freshness": {
    "omie_sync_status": "ok|warning|critical|unknown",
    "canonical_status": "ok|warning|critical|unknown",
    "gold_status": "ok|warning|critical|unknown",
    "last_omie_sync_at": "ISO8601|null",
    "last_canonical_nf_at": "ISO8601|null",
    "last_gold_materialized_at": "ISO8601|null",
    "is_stale": false,
    "stale_reason": "string|null"
  },
  "summary": { ... },
  "daily_sales": [...],
  "vendor_ranking": [...],
  "top_clients": [...],
  "top_customers": [...],
  "topCustomers": [...],
  "month_comparison": [...],
  "alerts": [...],
  "diagnostics": { ... },
  "empty_states": { ... },
  "meta": { ... }
}
```

---

## top_clients — Fallback chain

| Priority | Source | Condition |
|---|---|---|
| 1 (primary) | `mart_cliente_periodo` | Gold mart, queried via `GoldMartRepository::topClientsCommercial()` |
| 2 (fallback) | `biomie_notas_fiscais_canonicas` | Used only when mart returns 0 rows |

The frontend normalizes all three aliases (`top_clients`, `top_customers`, `topCustomers`) to the same array. `OverviewPayloadBuilder::ensureTopClientsContract()` guarantees all three are always present.

---

## empty_states

Both cards have a structured empty state block when data is unavailable:

```json
"empty_states": {
  "top_clients": {
    "is_empty": true,
    "reason_code": "no_authorized_nfs|mart_not_materialized|no_clients_in_period",
    "title": "...",
    "message": "...",
    "action": "..."
  },
  "month_comparison": {
    "is_empty": true,
    "reason_code": "no_authorized_nfs|not_enough_months|sync_stale|query_error",
    "title": "...",
    "message": "...",
    "action": "..."
  }
}
```

### reason_code — top_clients

| Code | Condition | Suggested action |
|---|---|---|
| `sync_stale` | Sync is critical (>72h) AND no NFs found — stale data may hide clients | Execute Omie sync then Gold materialization |
| `no_authorized_nfs` | `canonical_authorized_nfs_count = 0` (and sync is current) | Verify period or Omie fiscal sync |
| `mart_not_materialized` | Mart empty AND NFs exist in canonical | Run `GoldMartRefreshCommand` |
| `no_clients_in_period` | NFs and mart rows exist but top_clients query returned 0 | Check `codigo_cliente_omie` mapping in NFs |

### reason_code — month_comparison

| Code | Condition |
|---|---|
| `query_error` | Exception thrown during month comparison build (see request_id in logs) |
| `sync_stale` | Freshness SLA is critical (>72h) — data may be stale |
| `no_authorized_nfs` | No NFs in canonical for period |
| `not_enough_months` | NFs exist but insufficient data for 4-month range |

---

## freshness SLA

| Status | Condition |
|---|---|
| `ok` | Last sync ≤ 24h ago |
| `warning` | Last sync 24h–72h ago |
| `critical` | Last sync > 72h ago |
| `unknown` | No sync record available |

---

## diagnostics

```json
"diagnostics": {
  "canonical_authorized_nfs_count": 0,
  "mart_cliente_periodo_count": 0,
  "top_clients_count": 0,
  "month_comparison_count": 0,
  "used_fallback": false,
  "top_clients": {
    "source_table": "mart_cliente_periodo|biomie_notas_fiscais_canonicas|none",
    "primary_source": "mart_cliente_periodo",
    "fallback_source": "biomie_notas_fiscais_canonicas",
    "fallback_used": false,
    "rows_count": 0,
    "accepted_keys": ["top_clients", "top_customers", "topCustomers"]
  },
  "top_orders_identity": { ... },
  "request_id": "..."
}
```

---

## period_basis fields

| Field | Value | Meaning |
|---|---|---|
| `meta.period_basis` | `data_competencia` | Backward compat (realized) |
| `meta.realized_period_basis` | `data_competencia` | NFs autorizadas — campo data_competencia |
| `meta.wallet_period_basis` | `data_emissao` | Carteira — campo data_emissao do pedido |
| `meta.target_period_basis` | `year_month` | Metas — competencia mensal |
| `period.realized_period_basis` | `data_competencia` | Same, at period level |

---

## CLI diagnostic tool

```bash
php tools/dev/overview_debug.php [--start=YYYY-MM-DD] [--end=YYYY-MM-DD] [--json] [--explain]
```

Outputs:
- Canonical NF count and coverage
- Gold mart (mart_cliente_periodo) row count
- Top clients source resolution and sample
- Freshness SLA status
- Empty state reason codes
- `--explain`: recommended MySQL indexes and implementation notes

---

## Recommended MySQL indexes

```sql
-- Speeds up top clients fallback query on biomie_notas_fiscais_canonicas
CREATE INDEX idx_nf_status_comp_cliente
  ON biomie_notas_fiscais_canonicas (status_fiscal, data_competencia, codigo_cliente_omie);

-- Speeds up month comparison query
CREATE INDEX idx_nf_comp_status_cliente
  ON biomie_notas_fiscais_canonicas (data_competencia, status_fiscal, codigo_cliente_omie);

-- Speeds up mart_cliente_periodo lookups
CREATE INDEX idx_mart_cliente_periodo_comp
  ON mart_cliente_periodo (competencia, codigo_cliente_omie);
```

---

## top_clients item schema

Each `top_clients` row contains dual-named fields for compatibility:

| Field | Alias | Description |
|---|---|---|
| `client_id` | `customer_id` | `codigo_cliente_omie` |
| `client_name` | `customer_name` | Client display name |
| `client_document` | `customer_document` | CPF/CNPJ (masked) |
| `notes_count` | `invoices_count` | Number of authorized NFs |
| `invoice_total` | `revenue` | Total revenue (formatted) |
| `merchandise_total` | — | Merchandise subtotal (formatted) |
| `ticket_avg` | — | Average ticket per NF |
| `last_purchase` | `last_purchase_at` | Last purchase date |
| `share_pct` | — | Percentage of total revenue |
| `source` | — | `mart_cliente_periodo` or `biomie_notas_fiscais_canonicas` |

## month_comparison item schema

| Field | Description |
|---|---|
| `year_month` / `competencia` | `YYYY-MM` |
| `label` | Display label (e.g. `Mai/26`) |
| `revenue` | Total NF revenue |
| `merchandise_total` | Merchandise subtotal |
| `invoices_count` / `orders` | Count of authorized NFs |
| `target` | Monthly sales target |
| `forecast` | Revenue forecast |
| `attainment_pct` | Attainment percentage |
| `previous_revenue` | Previous month's revenue (null for first month) |
| `variation_abs` | Absolute variation vs previous month |
| `variation_pct` | Percentage variation vs previous month |
| `is_current` | True for the current month |
| `source` | `canonical`, `snapshot`, or `gold` |

## renderDashboardEmptyState (frontend)

```js
B.renderDashboardEmptyState(container, {
  title,        // string
  message,      // string
  action,       // string | null — recommended action
  reasonCode,   // string — one of the reason_codes above
  source,       // 'canonical' | 'gold' | 'fallback' | 'empty'
  freshnessStatus, // 'ok' | 'warning' | 'critical' | 'unknown'
  requestId     // string | null — shown in collapsible diagnostics
})
```

Visual: icon (context-sensitive) + title + message + action + source badge + freshness badge + collapsible request_id.

## Frontend key normalization

`OverviewPayloadBuilder` runs two contract-enforcement methods on every response (cache hit or rebuilt):

- `ensureTopClientsContract()`: normalizes `top_clients` / `top_customers` / `topCustomers` to all three always being present
- `ensureMonthComparisonContract()`: normalizes `month_comparison` / `monthly_comparison` / `monthlyComparison`

Both methods populate `diagnostics.top_clients` / `diagnostics.month_comparison` when the dataset is empty.

## Escopo de Operação (Fase H)

**Todos os dados do Overview são exclusivamente `operation_scope = 'commercial'`.**

- NFs `remittance` (Remessa de Produto) não entram em nenhum KPI do Overview.
- NFs `unknown` também são excluídas enquanto aguardam backfill.
- O filtro é aplicado via `CanonicalReadSupport::commercialScopeFilter()` e `AbstractMartBuilder::commercialScopeFragment()`.
- Pré-migration H3 (coluna `operation_scope` ausente): o filtro retorna `''` — comportamento idêntico ao atual (sem scope). Não há regressão.
- Ver contrato completo: `docs/omie-operation-scope-contract.md`

# Semantic Layer — BIomie

The semantic layer is the single source of truth for all metrics in BIomie. It lives in `app/DataPlatform/Semantic/` and defines what a KPI is, where its data comes from, who may see it, and when it is considered fresh.

## Core Concepts

### Entity

An `Entity` is a business object that can be counted or aggregated — a company, an order, a customer, a vendor. Entities are registered in `MetricRegistry` and referenced by KPI definitions. Each entity points to the table that identifies it.

### Dimension

A `Dimension` is a qualitative attribute used to slice or filter metrics — period, UF, seller, CNAE. Dimensions have typed values (`string`, `date`, `period`, `integer`, `boolean`, `enum`) and a list of domains that may use them.

### Measure

A `Measure` is a quantitative calculation — a sum, a count, an average. Measures declare their aggregation type, source column and table, and an optional filter rule.

### KPI (Key Performance Indicator)

A `KPI` combines a business definition (domain, description, include/exclude rules) with a data contract (source table, official date column, source_of_truth tier) and access metadata (RBAC permission, freshness domain). KPIs are the primary contract between the data platform and the presentation layer.

---

## KPI Status: active vs draft vs deprecated

| Status | Meaning |
|---|---|
| `active` | Source verified. `source_table` and `official_date` are known. Ready for dashboards. |
| `draft` | Rules unclear or source unconfirmed. Do not expose in production dashboards. |
| `deprecated` | Replaced or removed. Kept for audit history only. |

**Rule:** A KPI may only be promoted to `active` after its `source_table`, `official_date`, and `include_rule` / `exclude_rule` have been verified against real data. Update the registry entry and run `php tools/dev/semantic_audit.php` to confirm no errors.

---

## Source of Truth Tiers

Defined in `DataSourceTruth`. From most to least reliable:

| Constant | Value | Use when |
|---|---|---|
| `GOLD` | `gold` | Final mart table, fully validated |
| `CANONICAL` | `canonical` | Canonical sync output, validated on ingest |
| `LEGACY` | `legacy` | Legacy table — known structure, not rewritten |
| `OPERATIONAL` | `operational` | Pipeline / job metadata tables |
| `SILVER` | `silver` | Intermediate cleaned layer |
| `EXTERNAL_OMIE` | `external_omie` | Direct Omie API response, no local validation |
| `SHADOW` | `shadow` | Shadow validation output — not a stable mart |
| `BRONZE` | `bronze` | Raw ingest, no cleaning |

Dashboard-approved sources: `gold`, `canonical`, `legacy`, `operational`.
Discouraged sources for active KPIs: `bronze`, `external_omie`, `shadow`.

---

## Official Omie Data Architecture

Commercial data follows this order:

```text
Omie API
  -> raw / staging tables
  -> canonical layer
  -> commercial marts
  -> internal APIs
  -> pages and drawers
```

The detailed contract lives in `docs/OMIE_DATA_ARCHITECTURE.md`.

Official sources for the current commercial surface:

| Domain | Official source | Required rule |
|---|---|---|
| Realized sales | `biomie_notas_fiscais_canonicas` | `status_fiscal = 'autorizada'` and `data_competencia` inside the selected period |
| Items/products | Canonical NF items or the table used by `productDocumentBreakdownCommercial()` | Must inherit the authorized NF and `data_competencia` filters |
| Targets | `biomie_sales_targets_monthly` | Full-month target for a full month; proportional target for partial periods |
| Vendors | `codigo_vendedor_omie` / `vendor_id` | Vendor name is display/fallback only, never the primary key |
| Map | `mart_compradores_por_geo` + `biomie_bairros_fortaleza_dim` | Must expose coverage diagnostics |

Gold marts are preferred for dashboard speed only when they reconcile to the canonical contract above. If a mart is empty while canonical NFs exist for the same period, the API must report a materialization/coverage diagnostic instead of treating the period as zero sales.

---

## Adding a New KPI

1. Identify the data source and verify `source_table` and `official_date` exist in the DB.
2. Confirm the RBAC permission in `app/Admin/RbacService.php`.
3. Choose `status: 'draft'` if any rule is uncertain.
4. Add the KPI entry in the appropriate `register*Kpis()` method in `MetricRegistry`.
5. Run `php tools/dev/semantic_audit.php` — must exit 0 with no errors.
6. Run `php tests/run_golden_endpoints.php` — must remain 6/6.
7. If promoting to `active`, also run `php tests/run_phase2_coverage.php`.

```php
// Example — minimal active KPI
$r->registerKpi(self::kpi([
    'id'             => 'my_kpi',
    'name'           => 'My KPI',
    'domain'         => 'sales',
    'description'    => 'What this measures.',
    'source_of_truth'=> 'gold',
    'source_table'   => 'mart_vendedor_dia',
    'value_column'   => 'total_venda',
    'official_date'  => 'data_ref',
    'status'         => 'active',
    'include_rule'   => "status = 'faturado'",
    'permission'     => 'sales.view',
    'freshness_domain' => 'sales',
    'entities'       => ['vendor'],
    'dimensions'     => ['period', 'vendor_id'],
    'measures'       => ['sum_order_value'],
    'owner'          => 'data-platform',
]));
```

---

## Freshness

`FreshnessService` reads from `biomie_pipeline_jobs` (job `gold_mart_refresh`) to report data age. Each KPI's `freshness_domain` maps to a stale threshold:

| Domain | Stale after |
|---|---|
| `operational` / `operations` | 30 min |
| `gold` / `sales` / `fiscal` / `finance` | 60 min |
| `canonical` / `customers` / `omie` / `shadow` | 120 min |
| `geo` / `cnae` / `external` | 240 min |
| `legacy` | 24 h |

Possible statuses: `fresh`, `stale`, `partial`, `failed`, `unknown`.

---

## RBAC

Every KPI must declare a `permission` that exists in the known matrix (`MetricPermissionMap::KNOWN_PERMISSIONS`). Authorization is enforced by `Auth::requireCapability()` — the semantic layer only maps and validates, it never grants access.

---

## Tools

| Command | Purpose |
|---|---|
| `php tools/dev/kpi_inventory.php` | Scan codebase for metric term occurrences |
| `php tools/dev/semantic_audit.php` | Validate all KPIs in registry |
| `php tools/dev/semantic_audit.php --json` | Machine-readable audit output |
| `php tools/dev/semantic_audit.php --markdown` | Markdown report with KPI table |
| `php tools/dev/semantic_audit.php --fail-on-warning` | Exit 2 on warnings (CI use) |

---

## Class Map

```
app/DataPlatform/Semantic/
  DataSourceTruth.php          — source_of_truth constants + helpers
  MetricEntity.php             — entity value object
  MetricDimension.php          — dimension value object
  MetricMeasure.php            — measure value object
  KpiDefinition.php            — KPI data contract value object
  KpiContract.php              — validated KPI + RBAC binding
  MetricPermissionMap.php      — KPI-to-RBAC mapping
  MetricRegistry.php           — central registry + build() factory
  SemanticAuditResult.php      — audit outcome accumulator
  SemanticMetricValidator.php  — validates the full registry
  FreshnessService.php         — data freshness from pipeline jobs
  MetricCatalogExporter.php    — exports registry as array/JSON/Markdown
```

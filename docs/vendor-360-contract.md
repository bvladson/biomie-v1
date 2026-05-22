# Vendor 360 — Contrato de Dados

## Conceitos e campos semânticos

Existem três conceitos distintos de "pedidos/contagens" no Vendor 360. Nunca colapse esses conceitos em um único campo.

| Campo | Significado | Fonte |
|---|---|---|
| `invoices_count` | NFs autorizadas (fonte oficial do realizado) | `biomie_notas_fiscais_canonicas` |
| `linked_orders_count` | Pedidos que geraram NFs, via bridge | `mart_bridge_pedido_nfe_titulo` |
| `wallet_orders_count` | Pedidos autorizados sem NF (carteira) | `mart_bridge_pedido_nfe_titulo` (`pedido_sem_nf`) |
| `orders_count` | **Legado** — alias de `linked_orders_count`. Não usar em UI nova. | — |

## Contrato do summary (api/vendor_detail.php → Vendor360ReadService)

```json
{
  "ok": true,
  "source": "canonical",
  "vendor": { ... },
  "period": { "start": "YYYY-MM-DD", "end": "YYYY-MM-DD" },
  "summary": {
    "realized_total":      "R$ ...",
    "merchandise_total":   "R$ ...",
    "discount_total":      "R$ ...",
    "cancelled_total":     "R$ ...",
    "open_total":          "R$ ...",
    "invoices_count":      123,
    "linked_orders_count": 98,
    "wallet_orders_count": 12,
    "cancelled_count":     3,
    "customers_count":     45,
    "products_count":      67,
    "ticket_avg":          "R$ ...",
    "orders_count":        98,
    "orders_count_legacy": true,
    "deprecated_fields": {
      "orders_count": "Use linked_orders_count (bridge) ou wallet_orders_count (carteira)"
    },
    "gross_total":    "R$ ...",
    "invoice_total":  "R$ ..."
  },
  "targets": {
    "target_period":  "R$ ...",
    "target_month":   "R$ ...",
    "attainment_pct": 73.5,
    "gap_to_target":  "R$ ...",
    "risk_status":    "OK | SEM_META | ATENCAO | CRITICO",
    "target_status":  "found | missing"
  },
  "invoices":           [...],
  "linked_orders":      [...],
  "wallet_orders":      [...],
  "cancelled_invoices": [...],
  "customers":          [...],
  "products":           [...],
  "daily_sales":        [...]
}
```

## Regras semânticas

### Realizado
- Fonte: `biomie_notas_fiscais_canonicas` com `status_fiscal = 'autorizada'`
- Campo de data: `data_competencia`
- **Carteira nunca entra no realizado**

### Pedidos vinculados (`linked_orders`)
- Fonte: `mart_bridge_pedido_nfe_titulo` com `bridge_status NOT IN ('pedido_sem_nf', 'cancelado')` e `nota_key IS NOT NULL`
- Filtro de data: OR entre `data_competencia_nfe`, `data_faturamento`, `data_emissao`
- Fallback: se bridge vazia mas NFs existem → deriva de `allInvoices` via `pedido_id_omie` (`_source: 'nf_fallback'`)

### Carteira (`wallet_orders`)
- Fonte: `mart_bridge_pedido_nfe_titulo` com `status_comercial = 'autorizado'` e `bridge_status = 'pedido_sem_nf'`
- Filtro de data: `data_emissao BETWEEN :start AND :end`
- Fallback: se bridge indisponível → usa `allPipeline` (pedidos canônicos autorizados)

### Bridge disponível (`isBridgeAvailable`)
- Verifica OR entre `data_competencia_nfe`, `data_faturamento`, `data_emissao` — mesma condição das queries
- Retorna `false` apenas se a tabela estiver vazia para o período todo (não vendor-específico)

## Contrato do summary (api/vendors.php → CanonicalVendorPayloadBuilder)

O summary da página de lista de vendedores (não do drawer) expõe:

```json
{
  "summary": {
    "orders":              123,
    "invoices_count":      123,
    "pending_orders":      45,
    "wallet_orders_count": 45,
    "linked_orders_count": 0,
    "cancelled_orders":    7,
    ...
  }
}
```

`linked_orders_count = 0` nesta visão pois a bridge não é consultada no builder agregado.
Use `api/vendor_detail.php` para dados bridge por vendedor.

## Campo `target_status`

- `"found"`: vendedor tem meta cadastrada para o período (`target_period > 0`).
- `"missing"`: sem meta cadastrada — `attainment_pct` e `gap_to_target` serão 0.

Usado pela UI para decidir se exibe o badge de atingimento ou a mensagem "Sem meta cadastrada".

## Diagnóstico

```bash
# Drawer individual
php tools/dev/vendor_360_debug.php --vendor=123456 --start=2026-05-01 --end=2026-05-31

# Consistência cross-source (V360 vs Plano de Voo vs canônico direto)
php tools/dev/vendor_plan_consistency.php --start=2026-05-01 --end=2026-05-31
php tools/dev/vendor_plan_consistency.php --start=2026-05-01 --end=2026-05-31 --json
php tools/dev/vendor_plan_consistency.php --start=2026-05-01 --end=2026-05-31 --vendor=123456 --json
```

Alertas gerados:
- `bridge_vs_nf`: NFs sem pedido vinculado → bridge desatualizado
- `nf_vs_pedidos`: divergência realizado NFs vs pedidos faturados → problema de sincronia

## Campos legados (não usar em UI nova)

| Campo legado | Usar em vez disso |
|---|---|
| `orders_count` | `linked_orders_count` ou `wallet_orders_count` |
| `orders` (summary lista) | `invoices_count` |
| `pending_orders` | `wallet_orders_count` |
| `billed_orders` | `invoices_count` |
| `authorized_orders` | `wallet_orders_count` |

## Escopo de Operação (Fase H)

**Todos os dados do Vendor 360 são exclusivamente `operation_scope = 'commercial'`.**

- NFs `remittance` (Remessa de Produto) não entram em `invoices_count`, `revenue`, `ticket_medio` nem em nenhum KPI.
- O filtro é aplicado via `CanonicalReadSupport::commercialScopeFilter()`.
- Pré-migration H3: filtro retorna `''` — sem regressão de comportamento.
- Ver contrato completo: `docs/omie-operation-scope-contract.md`

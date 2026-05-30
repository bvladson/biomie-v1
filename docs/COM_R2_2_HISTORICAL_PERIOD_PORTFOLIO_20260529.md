# COM.R2.2 — Historical Period Coverage, Portfolio & Client Recurrence

**Data:** 2026-05-29
**Status:** Implementado — 31/31 guard PASS, 152/152 VLG PASS, deploy produção OK

---

## Diagnóstico executado em produção

### Cobertura mensal de NFs canônicas (canonical_nf_orc_pd)

| Mês | NFs | Base Comercial | Clientes | Vendedores | Status |
|---|---|---|---|---|---|
| 2026-01 | **0** | R$0 | 0 | 0 | `backfill_needed` |
| 2026-02 | 202 | R$955k | 187 | 10 | `materialize_needed` (mart gap) |
| 2026-03 | **0** | R$0 | 0 | 0 | `backfill_needed` |
| 2026-04 | 84 | R$726k | 76 | 11 | `materialize_needed` |
| 2026-05 | 262 | R$491k | 226 | 9 | `materialize_needed` |

**Causa raiz dos gráficos vazios em Jan e Mar:** 0 NFs no banco canônico — o backfill histórico para esses meses não foi executado.

**Total NFs Orç-PD no ano (Feb+Apr+May):** 548 NFs | R$2.172.066,85 (conforme diagnóstico confirmado)

### Product 360 — Bug corrigido

**Erro:** `BIomie\Http::query(): Argument #2 ($default) must be of type ?string, int given`
**Arquivo:** `api/product_detail.php`, linha 43
**Fix:** `(int) Http::query('limit', 50)` → `(int) Http::query('limit', '50')` (string default)
**Impact:** Product 360 agora abre corretamente para qualquer produto

### Dashboard KPIs Carteira e Orçamentos

**Antes:** `summary-wallet` e `summary-quotes` ficavam com "-" indefinidamente
**Fix:** Dois pontos complementares:
1. `api/commercial_dashboard.php` — novas queries em `biomie_pedidos_canonicos`:
   - `wallet_value` = SUM(valor_mercadorias) WHERE status_comercial='autorizado'
   - `quotes_value` = SUM(valor_mercadorias) WHERE etapa_omie='10'
2. `assets/js/core/render-helpers.js` — novos bindings em `renderSummaryCards`:
   - `summary-wallet` → `summary.wallet_value || pending_value`
   - `summary-quotes` → `summary.quotes_value`

### Estoque Omie — Diagnóstico

**Estado:** Nenhuma tabela de estoque existe no banco. 340 produtos distintos sem snapshot de estoque.
**APIs Omie disponíveis:** ListarEstoques, ConsultarEstoque, ResumoEstoque, ListarMovEstoque, ListarLocaisEstoque
**Próximo passo:** criar migrations + sync_omie_stock.php + cron

---

## Arquivos criados/modificados

| Arquivo | Tipo | Mudança |
|---|---|---|
| `api/product_detail.php` | Fix | Http::query limit default `50` → `'50'` (string) |
| `api/commercial_dashboard.php` | Fix | wallet_value + quotes_value adicionados ao response.kpis |
| `assets/js/core/render-helpers.js` | Fix | Bindings summary-wallet e summary-quotes em renderSummaryCards |
| `app/Commercial/CommercialTimeBucket.php` | Novo | Granularidade automática: ≤62d→day, 63-180d→week, >180d→month |
| `tools/dev/commercial_monthly_coverage_audit.php` | Novo | Cobertura mês-a-mês: NFs, pedidos, marts vs canonical |
| `tools/dev/sales_goal_broken_period_examples_audit.php` | Novo | Exemplos de meta proporcional com tabela de breakdown por mês |
| `tools/dev/omie_stock_capability_audit.php` | Novo | Diagnóstico de estoque: tabelas, produtos sem snapshot, scripts |
| `tools/dev/recover_commercial_range.php` | Novo | Dry-run/apply de backfill+materialização por range de datas |
| `tools/dev/biomie_release_guard.php` | Fix | D30 adicionado (12 checks COM.R2.2) |
| `tools/dev/visual_layout_guard.php` | Fix | 10 checks COM.R2.2; total 152/152 PASS |

---

## Backfill necessário para Jan e Mar 2026

**AÇÃO NECESSÁRIA (não executada automaticamente):**

```bash
# Dry-run (já executado — 6 ações identificadas):
php tools/dev/recover_commercial_range.php --start=2026-01-01 --end=2026-05-29 --pretty

# Executar backfill (REQUER APROVAÇÃO EXPLÍCITA):
php tools/dev/recover_commercial_range.php --start=2026-01-01 --end=2026-01-31 --apply --pretty
php tools/dev/recover_commercial_range.php --start=2026-03-01 --end=2026-03-31 --apply --pretty
```

**Impacto esperado:** Jan e Mar passarão a ter NFs no banco canônico → gráficos de evolução mostrarão dados completos para o ano.

---

## CommercialTimeBucket — Granularidade automática

```
resolveGranularity(start, end):
  ≤ 62 dias  → 'day'   (diário)
  63-180 dias → 'week'  (semanal)
  > 180 dias  → 'month' (mensal)

buildBuckets(start, end) → [{bucket_start, bucket_end, label, granularity}]
bucketSqlExpr(col, granularity) → MySQL expression for GROUP BY
bucketLabel(start, granularity) → '15/04' | 'Semana 15/04' | 'Abr/2026'
```

**Uso nos endpoints:** `CommercialTimeBucket::resolveGranularity($start, $end)` determina como a série temporal deve ser agrupada antes de enviar ao browser.

---

## Guards

- **D30** (12 checks): CommercialTimeBucket, coverage_audit, goal_examples_audit, stock_audit, recover_range, Product360 fix, dashboard wallet/quotes, render-helpers bindings, sem SELECT*, sem DDL, BiVisibilityScope
- **VLG** 10 novos checks COM.R2.2: time_bucket, monthly_coverage, goal_examples, stock_audit, recover_range, product360_fix, dashboard_wallet, dashboard_quotes, js_wallet_binding, js_quotes_binding

---

## Validações em produção

```
✔ Release Guard: 31/31 PASS (D1-D30)
✔ Visual Layout Guard: 152/152 PASS
✔ healthz: OK (DB conectado, 604 NFs, 29 vendedores)
✔ commercial_monthly_coverage_audit: executa corretamente
✔ recover_commercial_range (dry-run): identifica Jan+Mar como backfill_needed
✔ omie_stock_capability_audit: executa corretamente
```

---

## Limitações e próximos passos

1. **Jan e Mar 2026**: Precisam de backfill manual via `recover_commercial_range --apply`
2. **mart_cliente_periodo.faturamento_orc_pd**: Coluna divergente — mart precisa ser rematerializado após schema alinhado
3. **Estoque Omie**: Nenhuma tabela existe — PROD.R1 deve criar o schema
4. **CommercialTimeBucket**: Criado mas não integrado ainda nos endpoints (APIs de evolução ainda retornam dados diários). Integração é PROD.R1/UX.R13
5. **NF page UX**: Tabela compacta com scroll ainda não implementada (UX.R13)
6. **Client portfolio drawer**: ClientCommercialPortfolioResolver não integrado ainda na UI (COM.R3)

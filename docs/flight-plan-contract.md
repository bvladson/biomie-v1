# Plano de Voo — Contrato Oficial

## Objetivo

O Plano de Voo é o motor oficial de metas, atingimento, projeção e ritmo diário do BIomie.
Alimentado por `plano_voo_metas.php` e integrado com Vendor 360, Dashboard e páginas de vendedores.

## Fonte de dados

| Dado         | Fonte                               | Campo chave          |
|---|---|---|
| Realizado    | `biomie_notas_fiscais_canonicas`    | `data_competencia`   |
| Carteira     | pedidos autorizados sem NF          | `data_emissao`       |
| Meta         | `biomie_sales_targets_monthly`      | `year_month`         |
| Série diária | `biomie_notas_fiscais_canonicas`    | `data_competencia`   |

## Regras de negócio

1. Apenas NFs com `status_fiscal = 'autorizada'` entram no realizado.
2. Canceladas (`status_fiscal = 'cancelada'`) não entram no realizado.
3. Carteira não entra no realizado — é informação de apoio.
4. Apenas vendedores com `target_amount > 0` no mês aparecem no Plano de Voo.
5. Vendedor com meta e zero vendas aparece (risk = no_sales).
6. Vendedor sem meta não aparece.

## Endpoint

```
GET api/flight_plan.php
Params: start, end, vendor_id, force
Auth: sales.view
```

## Contrato de resposta (sucesso)

```json
{
  "ok": true,
  "source": "flight_plan_v2",
  "data_quality": "operational|meta_only|empty",
  "period": {
    "start": "2026-05-01",
    "end": "2026-05-31",
    "year_month": "2026-05",
    "elapsed_days": 19,
    "calendar_days": 31,
    "remaining_days": 12,
    "is_month_in_progress": true,
    "label": "01/05/2026 ate 31/05/2026"
  },
  "team": { ... },
  "summary": { ... },
  "vendors": [ ... ],
  "daily_sales": [ ... ],
  "daily": [ ... ],
  "alerts": [ ... ],
  "risks": [ ... ],
  "meta": { "realized_period_basis": "data_competencia", "wallet_period_basis": "data_emissao", "target_period_basis": "year_month" },
  "diagnostics": { "request_id": "...", "runtime_ms": 0, "fallback_used": false, "legacy_used": false }
}
```

### Resposta: sem metas ativas

```json
{
  "ok": true,
  "source": "flight_plan_v2",
  "reason_code": "no_active_targets",
  "message": "Nenhuma meta ativa para 5/2026.",
  "vendors": [],
  "summary": { "target_total": 0, "realized_total": 0 }
}
```

### Resposta: erro

```json
{
  "ok": false,
  "error": "flight_plan_error",
  "reason_code": "canonical_unavailable|service_exception|auth_required",
  "message": "...",
  "source": "flight_plan_v2",
  "request_id": "...",
  "diagnostics": { "request_id": "..." }
}
```

## Campos do vendor

| Campo              | Tipo    | Descrição                                    |
|---|---|---|
| vendor_id          | string  | ID Omie do vendedor                          |
| vendor_name        | string  | Nome do vendedor                             |
| target_period      | float   | Meta pro-ratada para o período               |
| realized_period    | float   | Realizado fiscal (NFs autorizadas)           |
| attainment_pct     | float   | `realized / target * 100`                   |
| forecast_period    | float   | Projeção provável                            |
| forecast_pct       | float   | `forecast / target * 100`                   |
| gap_to_target      | float   | `max(target - realized, 0)`                 |
| new_daily_target   | float   | Ritmo diário necessário para bater a meta    |
| pipeline_value     | float   | Carteira (pedidos sem NF)                    |
| risk               | string  | `on_track|attention|critical|no_sales`       |
| risk_label         | string  | Label legível do risco                       |
| risk_reason        | string  | Motivo do risco                              |
| risk_severity      | string  | `success|warning|danger`                     |
| risk_sort          | int     | 0=on_track, 1=attention, 2=no_sales, 3=critical |

## Classificação de risco

| Risco      | Condição                                         |
|---|---|
| no_sales   | target > 0 AND realized = 0                      |
| critical   | forecast_pct < 70%                               |
| attention  | forecast_pct >= 70% AND < 95%                    |
| on_track   | forecast_pct >= 95%                              |

## Fórmulas

- `attainment_pct = realized / target * 100`
- `forecast (base) = (realized / elapsed_weight) * total_weight`
- `forecast (probable) = base + pipeline * 0.8`
- `new_daily_target = max(target - realized, 0) / remaining_weight`
- `ideal_for_period = (target / total_weight) * elapsed_weight`

## Integração com Vendor 360

O campo `realized_period` do Plano de Voo deve bater com o `realized` do Vendor 360 por vendedor/competência.
Delta aceitável: ≤ 0.01 (1 centavo).

Ferramenta de validação:
```bash
php tools/dev/flight_plan_vendor_reconcile.php --start=2026-05-01 --end=2026-05-31 --json
```

## Comandos de debug

```bash
# Diagnóstico completo
php tools/dev/flight_plan_debug.php --start=2026-05-01 --end=2026-05-31 --json

# Reconciliação Plano de Voo x Vendor 360 x NFs
php tools/dev/flight_plan_vendor_reconcile.php --start=2026-05-01 --end=2026-05-31 --json
```

## Critérios de aceite

- [ ] `api/flight_plan.php` retorna JSON válido
- [ ] `source = flight_plan_v2` sempre
- [ ] `fallback_used = false`, `legacy_used = false` sempre
- [ ] Sem metas → `reason_code = no_active_targets`
- [ ] Vendedores sem meta não aparecem em `vendors`
- [ ] Realizado usa `data_competencia`
- [ ] Carteira usa `data_emissao`
- [ ] Delta vs Vendor 360 ≤ 0.01
- [ ] Mobile não quebra

## Escopo de Operação (Fase H)

**O realizado do Plano de Voo usa exclusivamente `operation_scope = 'commercial'`.**

- NFs `remittance` e `unknown` não entram no realizado nem na carteira.
- O filtro é injetado via `CanonicalReadSupport::commercialScopeFilter()` nos serviços de leitura.
- Pré-migration H3: filtro retorna `''` — sem regressão de comportamento.
- Ver contrato completo: `docs/omie-operation-scope-contract.md`

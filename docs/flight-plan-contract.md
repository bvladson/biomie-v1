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
4. Vendedores com meta ou atividade comercial real aparecem no Plano de Voo.
5. Vendedor com meta e zero vendas aparece (`risk = no_sales`).
6. Vendedor com vendas ou carteira, mas sem meta, aparece como `risk = no_target`.
7. Licitação e remessa não entram na visão comercial padrão.
8. Realizado, série diária e Vendor 360 usam a mesma base comercial da NF.

## Escada de metas

Uma meta mensal pode ter níveis crescentes. `target_amount` continua sendo o
compromisso-base (Meta 1); os demais níveis são persistidos de forma auditável
em `biomie_sales_target_levels`, com `tiers_json` mantido apenas para
retrocompatibilidade.

- Um nível é conquistado somente pelo realizado fiscal confirmado.
- `active_target` é o primeiro nível ainda não atingido.
- Ao conquistar um nível, o compromisso permanece visível e o desafio avança.
- Forecast e carteira nunca desbloqueiam nível; apenas estimam a chance de alcançá-lo.
- Depois do nível máximo, `active_target = null` e `all_levels_achieved = true`.
- `target_period` e `base_target` preservam a série histórica do compromisso.
- `gap_to_target`, `active_gap` e `new_daily_target` miram o desafio ativo.
- O risco-base informa cumprimento do compromisso; `challenge_risk` informa a
  situação do próximo nível.

Campos canônicos: `base_target`, `active_target`, `maximum_target`,
`achieved_level`, `active_level`, `tiers_total`, `active_gap`,
`base_attainment_pct`, `active_attainment_pct`, `step_progress_pct`,
`overall_progress_pct`, `all_levels_achieved` e `over_maximum_amount`.

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

Quando houver atividade comercial sem metas cadastradas, a resposta permanece operacional:

```json
{
  "ok": true,
  "reason_code": "targets_missing",
  "message": "Há atividade comercial no período, mas as metas ainda não foram definidas.",
  "vendors": [{ "risk": "no_target" }]
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
| vendor_channel     | string  | `varejo|revenda|representante|outro`         |
| has_target         | bool    | Informa se o vendedor possui meta no período |
| risk               | string  | `on_track|attention|critical|no_sales|no_target` |
| risk_label         | string  | Label legível do risco                       |
| risk_reason        | string  | Motivo do risco                              |
| risk_severity      | string  | `success|warning|danger`                     |
| risk_sort          | int     | 0=on_track, 1=attention, 2=no_sales, 3=critical, 4=no_target |

## Classificação de risco

| Risco      | Condição                                         |
|---|---|
| no_target  | target <= 0 e há venda ou carteira               |
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
- [ ] Sem metas e sem atividade → `reason_code = no_active_targets`
- [ ] Atividade sem metas → `reason_code = targets_missing`
- [ ] Vendedores com atividade e sem meta aparecem como `no_target`
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
- **Consequência para auditorias:** comparar mart/Plano de Voo contra o canônico
  SEM aplicar o escopo produz divergência esperada (ex.: remessas). Toda
  reconciliação deve segmentar por `operation_scope` antes de acusar delta.

## Régua única de risco (2026-07-12)

A classificação de risco tem UMA régua, definida em
`CommercialPlanning::RISK_ON_TRACK_PCT` (100) e
`CommercialPlanning::RISK_ATTENTION_PCT` (85):

| Projeção vs meta | Tabela (`riskStatus`) | Payload (`classifyRisk`) |
| --- | --- | --- |
| ≥ 100% | `OK` | `on_track` |
| ≥ 85%  | `ATENCAO` | `attention` |
| < 85%  | `SALA_DE_GUERRA` | `critical` |

Nenhum serviço, alerta ou gráfico pode declarar cortes próprios — sempre
derivar dessas constantes. (Antes de 2026-07-12 existia uma segunda régua
70/95 em `classifyRisk`, eliminada.)

## Duas fontes de meta — semântica oficial (2026-07-12)

| Fonte | Tabela | Papel |
| --- | --- | --- |
| Meta por vendedor (bottom-up) | `biomie_sales_targets_monthly` | **Fonte oficial do Plano de Voo** — soma dos vendedores = meta do time |
| Meta institucional (top-down) | `biomie_company_targets` (empresa × canal × competência) | Meta da diretoria — consumida pelo board/TV e visões consolidadas |

Elas **podem divergir por decisão de negócio** (a meta distribuída aos
vendedores costuma ser mais agressiva que a institucional). Regras:

1. O Plano de Voo NUNCA lê `biomie_company_targets`; o board/TV NUNCA soma
   metas de vendedores.
2. Divergência entre as duas não é bug — mas deve ser visível: cabe à
   diretoria aprovar a distribuição quando a soma dos vendedores diferir da
   meta institucional do mês.
3. Alterações de meta de vendedor são auditadas com `changed_by` derivado da
   sessão autenticada (nunca do payload do cliente).

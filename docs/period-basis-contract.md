# BIomie — Contrato de Bases de Período

## Conceito

Cada métrica no BIomie usa uma base de data diferente. Confundir essas bases é a causa mais comum de discrepâncias entre telas.

| Campo | Base de data | Tabela de origem | Lógica |
|---|---|---|---|
| `realized_period_basis` | `data_competencia` | `biomie_notas_fiscais_canonicas` | NFs autorizadas. Data de competência fiscal da NF (pode diferir da data de emissão). |
| `wallet_period_basis` | `data_emissao` | `biomie_pedidos_canonicos` / `mart_bridge_pedido_nfe_titulo` | Pedidos sem NF (carteira ativa). Data de emissão do pedido. |
| `target_period_basis` | `year_month` | `biomie_sales_targets_monthly` | Metas mensais. Competência no formato `YYYY-MM`. |

---

## Onde isso aparece no código

### Vendor 360 (`Vendor360ReadService`)

```php
'realized_period_basis' => 'data_competencia',   // NFs autorizadas
'wallet_period_basis'   => 'data_emissao',        // carteira
'target_period_basis'   => 'year_month',          // metas
```

### Plano de Voo (`FlightPlanV2Service`)

Usa `VendorRealizedService::summarizeByVendor()` como fonte do realizado.
`VendorRealizedService` filtra `biomie_notas_fiscais_canonicas` por `data_competencia BETWEEN :start AND :end`.

### Overview (`CanonicalOverviewPayloadBuilder`)

Idem — usa `data_competencia` para realizado, `data_emissao` para carteira.

---

## Por que `data_competencia` e não `data_emissao`?

- A nota fiscal pode ser emitida num mês mas competir no mês seguinte (ex.: NF de 31/jan registrada com competência fev).
- A Receita Federal e o departamento fiscal usam `data_competencia` para apuração de resultado.
- O BIomie adotou `data_competencia` como convenção uniforme para o **realizado** desde a Fase 4 (materalização geo segura).

---

## Invariantes

1. **Realizado sempre usa `data_competencia`** — em todas as telas (Vendor 360, Plano de Voo, Overview, Mapa Geo).
2. **Carteira sempre usa `data_emissao`** — reflete a data em que o pedido foi aprovado/emitido.
3. **Metas sempre usam `year_month`** — independente do dia; a meta é mensal.
4. Um vendedor com `target_status: 'missing'` não tem meta cadastrada no período; `attainment_pct` será 0 e não deve ser exibido como 0%.

---

## Validação

```bash
# Checar consistência entre Vendor 360, Plano de Voo e canônico direto:
php tools/dev/vendor_plan_consistency.php --start=2026-05-01 --end=2026-05-31 --json

# Status critical significa que algum valor diverge > R$ 0,01 entre as fontes.
# Causas comuns: bug de identity resolution, filtro de status_fiscal diferente, join errado.
```

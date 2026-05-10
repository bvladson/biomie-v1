# Contrato Oficial: Realizado por Vendedor (Fase 11B-FIX2)

## Definição Oficial
O KPI `realizado_vendedor` (e seu respectivo total de Receita) é definido como a **Receita Fiscal Autorizada** atribuída ao vendedor no período.

## Regras e Fontes
* **Fonte Primária:** `mart_fiscal_nfe`
* **Fallback:** `biomie_notas_fiscais_canonicas` + `mart_bridge_pedido_nfe_titulo` + `biomie_pedidos_canonicos`
* **Data Oficial:** `COALESCE(data_competencia, data_emissao)`
* **Status Considerado:** Somente NF Autorizada / Válida
* **Exclusões:** NFs Canceladas, Denegadas e Inutilizadas.
* **Resolução de Vendedor:** `VendorIdentityResolver` (agregação de aliases, fallback para `__SEM_VENDEDOR__`).
* **Campo de Valor Oficial:** `total` (Valor Fiscal Cheio da Nota, incluindo fretes, seguros e descontos já rateados na nota).

> [!WARNING]
> O campo `merchandise_total` (Valor de Mercadorias) **NÃO** deve ser usado para compor o `realizado_vendedor`. Ele é uma métrica secundária chamada `mercadoria_faturada_vendedor`.

## Tabela de Mapeamento de Fontes

| Tela / Recurso | Endpoint | Classe / Builder | Fonte de Valor | Métrica Oficial Aplicada |
| --- | --- | --- | --- | --- |
| Resumo Comercial | `api/overview.php` | `CanonicalOverviewPayloadBuilder` | `total` via VendorRealizedService | `realizado_vendedor` |
| Lista de Vendedores | `api/vendors.php` | `VendorPayloadBuilder` | `total` via VendorRealizedService | `realizado_vendedor` |
| Plano de Voo | `api/flight_plan.php` | `FlightPlanV2Service` | `total` via VendorRealizedService | `realizado_vendedor` |
| Vendor 360 | `api/flight_plan_vendor_detail.php` | `FlightPlanVendorDetail` | `total` via VendorRealizedService | `realizado_vendedor` |
| Ranking Comercial | `api/overview_detail.php` | `CanonicalOverviewPayloadBuilder` | `total` via VendorRealizedService | `realizado_vendedor` |

## Composição do Valor (Vendor 360)
No painel de detalhes do vendedor (Drawer), a aba "NFs" deverá apresentar exatamente a listagem de NFs que somadas formam o `realizado_vendedor`, de modo a auditar facilmente o montante.

## Arquitetura: `VendorRealizedService`
Toda consulta por "realizado do vendedor" DEVE passar por `$vendorRealizedService->summarizeByVendor($start, $end)`. Nenhuma tela deve fazer `GROUP BY` por vendedor manualmente.

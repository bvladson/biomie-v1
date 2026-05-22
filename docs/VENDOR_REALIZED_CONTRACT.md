# Contrato oficial: realizado por vendedor

## Definicao oficial

O KPI `realizado_vendedor` e a receita fiscal autorizada atribuida ao vendedor dentro do periodo filtrado.

## Regras e fontes

- Fonte primaria: `biomie_notas_fiscais_canonicas`.
- Filtro obrigatorio: `status_fiscal = 'autorizada'`.
- Data oficial: `data_competencia`.
- Periodo: `data_competencia BETWEEN :start_date AND :end_date`.
- Chave principal do vendedor: `codigo_vendedor_omie` / `vendor_id`.
- Nome do vendedor: fallback de exibicao/resgate, nunca chave primaria.
- Campo de valor oficial: valor fiscal total da NF, exposto pela camada de leitura como `total`, `gross_total` ou `invoice_total`.
- Exclusoes: NFs canceladas, denegadas, inutilizadas ou sem status autorizado.

`mart_fiscal_nfe` e outros marts podem ser usados para performance somente quando forem derivados dessa regra canonica e tiverem reconciliacao documentada. Eles nao substituem a tabela canonica como fonte de verdade.

> Atencao: `merchandise_total` representa mercadorias e nao deve compor `realizado_vendedor`. Quando usado, deve aparecer como metrica secundaria (`mercadoria_faturada_vendedor`).

## Consulta base

```sql
SELECT
    codigo_vendedor_omie,
    nota_key,
    chave_nfe,
    numero_nf,
    serie_nf,
    data_competencia,
    status_fiscal,
    total
FROM biomie_notas_fiscais_canonicas
WHERE status_fiscal = 'autorizada'
  AND data_competencia BETWEEN :start_date AND :end_date
```

Quando a camada de leitura normalizar nomes, os identificadores de NF devem continuar presentes no payload:

- `nota_key`
- `chave_nfe`
- `numero_nf`
- `serie_nf`

## Tabela de mapeamento

| Tela / Recurso | Endpoint | Classe / Builder | Fonte de valor | Metrica oficial |
| --- | --- | --- | --- | --- |
| Resumo Comercial | `api/overview.php` | `CanonicalOverviewPayloadBuilder` | NFs canonicas via `VendorRealizedService` | `realizado_vendedor` |
| Lista de Vendedores | `api/vendors.php` | `VendorPayloadBuilder` | NFs canonicas via `VendorRealizedService` | `realizado_vendedor` |
| Plano de Voo | `api/flight_plan.php` | `FlightPlanV2Service` | NFs canonicas via `VendorRealizedService` | `realizado_vendedor` |
| Vendor 360 | `api/vendor_detail.php` | Vendor detail canonical endpoint | NFs canonicas autorizadas | `realizado_vendedor` |
| Vendor 360 fallback | `api/flight_plan_vendor_detail.php` | Flight plan vendor detail | NFs canonicas autorizadas ou mart reconciliado | `realizado_vendedor` |
| Ranking Comercial | `api/overview_detail.php` | `CanonicalOverviewPayloadBuilder` | NFs canonicas via `VendorRealizedService` | `realizado_vendedor` |

## Composicao no Vendor 360

A aba "Notas fiscais" do drawer deve listar exatamente as NFs que somam o `realizado_vendedor` do periodo. A soma da lista precisa bater com o resumo do vendedor.

Pedidos vinculados, carteira ou pipeline podem aparecer como contexto, mas nao entram no realizado enquanto nao houver NF autorizada.

## Arquitetura: `VendorRealizedService`

Toda consulta por realizado do vendedor deve passar por `$vendorRealizedService->summarizeByVendor($start, $end)` ou por uma API que use esse contrato. Nenhuma tela deve fazer `GROUP BY` por nome de vendedor manualmente.

O servico deve:

- agrupar por `codigo_vendedor_omie` / `vendor_id`;
- usar nome apenas como fallback;
- aplicar `status_fiscal = 'autorizada'`;
- usar `data_competencia` como periodo;
- expor diagnostico quando vendedor, chave ou periodo estiverem incompletos.

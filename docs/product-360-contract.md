# Product 360 — Contrato e Arquitetura

**Endpoint:** `GET api/product_detail.php`  
**Auth:** `sales.view`  
**Fonte de verdade:** `biomie_notas_fiscais_itens_canonicos` + `biomie_notas_fiscais_canonicas` (status_fiscal = 'autorizada', data_competencia)

---

## Conceito

Product 360 é a visão analítica completa de um produto a partir de dados locais sincronizados do BIomie.  
Responde as perguntas:

- Quanto esse produto vendeu?
- Quantas unidades saíram?
- Quais NFs sustentam esse número?
- Quais clientes compraram?
- Quais vendedores venderam?
- Quais pedidos geraram essas NFs?
- Como evoluiu no tempo?
- Qual o LTV observado?
- O dado veio de canônico, gold ou fallback?

---

## Fonte de verdade

| Dimensão | Fonte | Campo temporal |
|---|---|---|
| Realizado (produto vendido) | `biomie_notas_fiscais_itens_canonicos` + `biomie_notas_fiscais_canonicas` | `data_competencia` |
| Pedidos vinculados | `mart_bridge_pedido_nfe_titulo` → NFs | `data_competencia_nfe` |
| Carteira | `biomie_pedidos_canonicos` sem NF vinculada | `data_emissao` |
| LTV histórico | `biomie_notas_fiscais_itens_canonicos` (sem filtro de período) | `data_competencia` |

**Regra fiscal:** Realizado nunca usa `data_emissao`. Sempre `data_competencia`.

---

## Parâmetros da API

| Parâmetro | Tipo | Obrigatório | Default |
|---|---|---|---|
| `product_code` | string | sim | — |
| `start` | YYYY-MM-DD | não | 1º dia do mês atual |
| `end` | YYYY-MM-DD | não | hoje |
| `limit` | int (1–200) | não | 50 |
| `tab` | string | não | null (carrega tudo) |

**Tabs válidas:** `resumo`, `nfs`, `clientes`, `vendedores`, `pedidos`, `evolucao`, `ltv`

---

## Contrato de resposta

```json
{
  "ok": true,
  "source": "canonical|empty",
  "product": {
    "code": "CODIGO",
    "description": "Descricao do produto"
  },
  "period": { "start": "YYYY-MM-DD", "end": "YYYY-MM-DD" },
  "period_basis": {
    "realized": "data_competencia",
    "linked_orders": "data_competencia_nfe",
    "wallet": "data_emissao"
  },
  "summary": { ... },
  "invoices": [...],
  "customers": [...],
  "vendors": [...],
  "linked_orders": [...],
  "timeline": [...],
  "ltv": { ... },
  "data_quality": { ... },
  "empty_states": { ... },
  "meta": { ... }
}
```

---

## summary — campos

| Campo | Tipo | Descrição |
|---|---|---|
| `product_code` | string | Código do produto |
| `product_description` | string | Descrição |
| `realized_total` | money | Receita no período (valor_total dos itens) |
| `merchandise_total` | money | Subtotal mercadorias (valor_mercadorias ou fallback para valor_total) |
| `quantity_total` | float | Quantidade total vendida |
| `discount_total` | money | Total de descontos |
| `invoices_count` | int | NFs autorizadas |
| `customers_count` | int | Clientes únicos |
| `vendors_count` | int | Vendedores únicos |
| `linked_orders_count` | int | Pedidos vinculados (bridge) |
| `ticket_avg_per_invoice` | money | Ticket médio por NF |
| `avg_unit_price` | money | Preço médio unitário |
| `first_sale_at` | date | Primeira competência com venda |
| `last_sale_at` | date | Última competência com venda |
| `source` | string | `canonical` ou `empty` |
| `realized_period_basis` | string | Sempre `data_competencia` |

---

## invoices — campos por item

| Campo | Descrição |
|---|---|
| `nota_key` | Chave interna canonical (identificador principal para drawer NF) |
| `chave_nfe` | Chave de acesso SEFAZ |
| `numero_nf` | Número da NF |
| `serie_nf` | Série |
| `data_competencia` | Base temporal fiscal |
| `customer_id` / `customer_name` | Cliente |
| `vendor_id` / `vendor_name` | Vendedor |
| `quantity` | Quantidade do produto na NF |
| `product_total` | Valor total do item |
| `invoice_total` | Valor total da NF |
| `origin_order_code` | Pedido de origem |
| `pedido_id_omie` | ID Omie do pedido |
| `status_fiscal` | Status da NF |

---

## linked_orders — diferença entre pedido vinculado e carteira

| Conceito | Definição |
|---|---|
| **Pedido vinculado** | Pedido que gerou uma NF autorizada onde o produto apareceu. Vem de `mart_bridge_pedido_nfe_titulo`. |
| **Carteira** | Pedidos autorizados sem NF vinculada. **Não entra no realizado do produto.** |

---

## LTV observado

- LTV = receita histórica acumulada do produto em NFs autorizadas, **considerando todo o histórico local sincronizado**.
- Não é limitado ao período selecionado.
- Campo `note` sempre indica: "LTV calculado com base no histórico local sincronizado."
- Se o sync Omie estiver incompleto, exibir `"Historico parcial"` no badge.
- **Não chamar de LTV total absoluto** se o histórico Omie estiver incompleto.
- Clientes recorrentes = clientes com `COUNT(DISTINCT nota_key) >= 2` para o produto no histórico.

---

## data_quality — campos

| Campo | Descrição |
|---|---|
| `canonical_items_count` | NFs encontradas via canônica |
| `authorized_invoices_count` | Mesmo que `canonical_items_count` |
| `linked_orders_count` | Pedidos vinculados encontrados |
| `mart_rows_count` | Linhas em `mart_produto_periodo` para o produto |
| `source` | `canonical` ou `empty` |
| `freshness_status` | `ok` / `warning` / `critical` / `unknown` |
| `last_canonical_nf_at` | Última NF canônica atualizada |
| `last_gold_materialized_at` | Último refresh de `mart_produto_periodo` |
| `warnings` | Lista de avisos textuais |

---

## Freshness SLA

| Status | Condição |
|---|---|
| `ok` | Último sync ≤ 24h |
| `warning` | 24h–72h |
| `critical` | > 72h |
| `unknown` | Sem registro |

---

## empty_states — reason codes

### empty_states.sales

| Código | Condição |
|---|---|
| `sync_stale` | Freshness crítica (>72h) + sem NFs |
| `no_authorized_nfs` | Sem NFs (sync atual) |

### empty_states.linked_orders

| Código | Condição |
|---|---|
| `bridge_empty` | NFs existem mas bridge retornou vazio |

### empty_states.ltv

| Código | Condição |
|---|---|
| `ltv_partial` | mart_produto_periodo vazio mas NFs existem — histórico pode estar incompleto |

---

## Abas do drawer Product 360

| Tab | Dados |
|---|---|
| **Resumo** | KPIs principais, badges de source/freshness, warnings |
| **NFs** | Lista de NFs autorizadas; botões para drawer NF, cliente, vendedor |
| **Clientes** | Compradores do produto; botão drawer cliente |
| **Vendedores** | Vendedores com % de participação; botão drawer vendedor |
| **Pedidos vinculados** | Bridge pedido → NF; botões para drawers de pedido, NF, cliente e vendedor |
| **Evolução** | Chart.js: receita + quantidade por dia (≤45d) ou mês (>45d) |
| **LTV** | Histórico observado com badge de parcialidade se aplicável |
| **Diagnóstico** | data_quality, period_basis, request_id, generated_at |

---

## Carregamento sob demanda

- Abrir o drawer carrega apenas o `tab=resumo`.
- Clicar em cada aba dispara `api/product_detail.php?tab=<key>` lazily.
- Cache local de 90s por `product_code + start + end + tab`.
- Botão de refresh ignora cache (implementável via `?force=1`).

---

## Refresh substitutivo do mart (ProductPeriodMartBuilder)

```sql
BEGIN;

-- Remove registros da competência antes de recriar — evita produtos fantasmas
DELETE FROM mart_produto_periodo WHERE competencia = :competencia;

-- Insere dados frescos da fonte canônica
INSERT INTO mart_produto_periodo (competencia, codigo_produto, descricao_produto, ...)
SELECT ...
FROM biomie_notas_fiscais_itens_canonicos i
JOIN biomie_notas_fiscais_canonicas n ON n.nota_key = i.nota_key AND n.status_fiscal = 'autorizada'
WHERE n.data_competencia BETWEEN :start AND :end
GROUP BY i.codigo_produto, i.descricao_produto;

COMMIT;
```

**Regras:**
- Sempre em transação com rollback em erro.
- Apagar apenas a competência recalculada (nunca toda a tabela).
- Nunca usar `data_emissao` — sempre `data_competencia`.
- Logar `deleted` e `inserted` por competência.

---

## Debug CLI

```bash
php tools/dev/product_360_debug.php --product=CODIGO [--start=YYYY-MM-DD] [--end=YYYY-MM-DD] [--json] [--explain]
```

**Alertas automáticos:**
- `warning` se mart tem o produto mas canônica não tem NFs → produto fantasma
- `warning` se `invoices_count > 0` e `linked_orders_count = 0`
- `warning` se `nota_key` ausente em alguma NF retornada
- `critical` se `realized_period_basis != 'data_competencia'`

---

## Índices recomendados

```sql
-- Itens por produto e nota (acesso principal de todas as queries)
CREATE INDEX idx_nf_itens_produto_nota
  ON biomie_notas_fiscais_itens_canonicos (codigo_produto, nota_key);

-- NFs por status e competência (filtro fiscal)
CREATE INDEX idx_nf_status_comp_nota
  ON biomie_notas_fiscais_canonicas (status_fiscal, data_competencia, nota_key);

-- NFs por vendedor e competência (aba Vendedores)
CREATE INDEX idx_nf_vendor_comp_status
  ON biomie_notas_fiscais_canonicas (codigo_vendedor_omie, data_competencia, status_fiscal);

-- Bridge por nota_key (aba Pedidos vinculados)
CREATE INDEX idx_bridge_nota_key
  ON mart_bridge_pedido_nfe_titulo (nota_key);

-- Mart produto por competência e código (refresh e debug CLI)
CREATE INDEX idx_mart_produto_periodo_comp_prod
  ON mart_produto_periodo (competencia, codigo_produto);
```

Aplicar via `database/migrations/Version20260516000001_Product360Indexes.php` (usa `maybeAddIndex` — seguro em produção).

---

## Arquivos criados / modificados na Fase 5

| Arquivo | Tipo | Ação |
|---|---|---|
| `app/PageAssetRegistry.php` | PHP | Adicionados módulos ao page-key `mix-faturado` |
| `assets/js/core/render-helpers.js` | JS | `data-nota-key` no botão NF + Product 360 button |
| `app/DataPlatform/Gold/Builders/ProductPeriodMartBuilder.php` | PHP | Delete+insert transacional por competência |
| `app/Modules/Sales/Infrastructure/Product360ReadRepository.php` | PHP | Novo — queries canônicas locais |
| `app/Modules/Sales/Application/Products/Product360ReadService.php` | PHP | Novo — orquestração Product 360 |
| `api/product_detail.php` | PHP | Novo — endpoint Product 360 |
| `assets/js/modules/drilldown-router.js` | JS | Adicionado `open-product` action |
| `assets/js/modules/product-detail/tabs.js` | JS | Novo — renderizadores de aba |
| `assets/js/modules/product-detail/charts.js` | JS | Novo — Chart.js para evolução |
| `assets/js/modules/product-detail/index.js` | JS | Novo — drawer BIomieProductDetail |
| `database/migrations/Version20260516000001_Product360Indexes.php` | PHP | Novo — migration de índices |
| `tools/dev/product_360_debug.php` | PHP | Novo — CLI de diagnóstico |
| `docs/product-360-contract.md` | Markdown | Este arquivo |

## Escopo de Operação (Fase H)

**Todos os dados do Product 360 e do mix de produtos usam exclusivamente `operation_scope = 'commercial'`.**

- `mart_produto_periodo` é materializado apenas com NFs `commercial` via `commercialScopeFragment()` no `ProductPeriodMartBuilder`.
- Vendas de `remittance` (Remessa de Produto) não aparecem no ranking, histórico ou evolução de produtos.
- Auditoria de contaminação do mart: `php tools/dev/operation_scope_mart_audit.php`
- Ver contrato completo: `docs/omie-operation-scope-contract.md`

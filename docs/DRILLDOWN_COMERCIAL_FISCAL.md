# Drilldown Comercial / Fiscal — BIomie

**Fase:** 8B — Integração dos Drawers nas Telas Existentes
**Status:** Implementado e conectado

## Visão geral

O drilldown permite ao usuário navegar de agregados (dashboard) até o detalhe de cada entidade (vendedor → pedido → NF → título financeiro) sem sair do contexto da tela atual, usando drawers laterais.

## Endpoints de detalhe

### `api/vendor_detail.php`

Vendor 360 — visão completa do vendedor por período.

**Permissão:** `sales.view`

**Parâmetros:**
| Param | Tipo | Obrigatório | Descrição |
|---|---|---|---|
| `vendor_id` | string | Sim | Código numérico ou nome do vendedor |
| `start` | YYYY-MM-DD | Não | Início do período |
| `end` | YYYY-MM-DD | Não | Fim do período |

**Payload (resumido):**
```json
{
  "ok": true,
  "vendor": { "id", "name", "realized", "goal", "gap", "attainment", "risk" },
  "period": { "start", "end" },
  "summary": {
    "orders_count", "invoices_count", "customers_count", "products_count",
    "gross_total", "discount_total", "cancelled_total", "open_total", "ticket_avg"
  },
  "charts": {
    "daily_revenue": [ { "date", "gross_revenue", "orders" } ],
    "status_breakdown": [ { "label", "value", "key" } ],
    "top_customers": [ { "id", "label", "value", "orders" } ],
    "top_products":  [ { "id", "label", "value" } ]
  },
  "orders": [], "invoices": [], "customers": [], "products": [],
  "meta": { "source_of_truth", "freshness", "last_success_at", "request_id" }
}
```

---

### `api/order_detail.php`

Detalhe completo de pedido.

**Permissão:** `sales.view` ou `fiscal.view`

**Parâmetros:**
| Param | Tipo | Obrigatório | Descrição |
|---|---|---|---|
| `codigo_pedido` | string | Sim | Código canônico do pedido |

**Payload (resumido):**
```json
{
  "ok": true,
  "order": {
    "codigo_pedido", "numero_pedido", "status", "etapa",
    "data_previsao", "data_inclusao", "data_faturamento",
    "seller": { "id", "name" },
    "customer": { "id", "name", "document" },
    "totals": { "subtotal", "discount", "total" }
  },
  "items": [],
  "installments": [ { "numero", "vencimento", "valor", "status", "forma", "liquidacao" } ],
  "invoices": [],
  "finance_titles": [],
  "timeline": [ { "date", "event", "icon" } ],
  "raw_available": false,
  "meta": { "source", "request_id" }
}
```

---

### `api/invoice_detail.php`

Detalhe completo de Nota Fiscal.

**Permissão:** `fiscal.view` ou `sales.view`

**Parâmetros** (ao menos um obrigatório):
| Param | Tipo | Descrição |
|---|---|---|
| `numero_nf` | string | Número da NF |
| `nota_key` | string | Chave interna canônica |
| `chave_nfe` | string | Chave de acesso SEFAZ (44 dígitos) |

**Payload (resumido):**
```json
{
  "ok": true,
  "invoice": {
    "numero_nfe", "serie_nfe", "chave_nfe", "status_nfe",
    "data_emissao", "hora_emissao", "data_fatura",
    "danfe": "URL ou null",
    "xml_distr": "URL ou null"
  },
  "order": {},
  "customer": { "id", "name", "document" },
  "seller": { "id", "name" },
  "items": [],
  "totals": { "subtotal", "discount", "ipi", "icms_st", "total" },
  "finance_titles": [],
  "timeline": [ { "date", "event", "icon" } ],
  "meta": { "source", "request_id" }
}
```

---

### `api/orders_search.php`

Busca e filtros de pedidos canônicos. Nunca chama o Omie diretamente.

**Permissão:** `sales.view` ou `fiscal.view`

**Parâmetros:**
| Param | Tipo | Padrão | Descrição |
|---|---|---|---|
| `q` | string | — | Busca livre (pedido, cliente, documento, integração) |
| `vendor` | string | — | ID numérico ou nome parcial do vendedor |
| `status` | string | — | status_comercial exato |
| `start` | YYYY-MM-DD | — | Data de emissão início |
| `end` | YYYY-MM-DD | — | Data de emissão fim |
| `min_value` | number | — | Valor total mínimo |
| `max_value` | number | — | Valor total máximo |
| `sort` | string | `emissao` | Campo: `emissao`, `valor`, `cliente`, `pedido` |
| `order` | string | `desc` | `asc` ou `desc` |
| `page` | int | `1` | Página (1-based) |
| `per_page` | int | `50` | Itens por página (máx 200) |

**Payload:**
```json
{
  "ok": true,
  "results": [ { "codigo_pedido", "status", "data_emissao", "cliente", "vendedor", "valor_total", ... } ],
  "pagination": { "page", "per_page", "total", "total_pages", "has_next", "has_prev" },
  "sort": { "field", "order" },
  "filters": { "q", "vendor", "status", "start", "end" },
  "meta": { "source", "request_id" }
}
```

---

## JS Modules

Localização: `assets/js/modules/`

| Módulo | Arquivo | Global |
|---|---|---|
| Vendor 360 | `vendor-detail/index.js` | `BIomieVendorDetail` |
| Pedido | `order-detail/index.js` | `BIomieOrderDetail` |
| Nota Fiscal | `invoice-detail/index.js` | `BIomieInvoiceDetail` |

### Como incluir

```html
<script src="assets/js/biomie-formatters.js"></script>
<script src="assets/js/modules/vendor-detail/index.js"></script>
<script src="assets/js/modules/order-detail/index.js"></script>
<script src="assets/js/modules/invoice-detail/index.js"></script>
```

### Como usar

```js
// Em click de linha de vendedor:
BIomieVendorDetail.open('12345', { start: '2025-01-01', end: '2025-12-31' });

// Em click de linha de pedido:
BIomieOrderDetail.open('PED-2025-00123');

// Em click de linha de NF (por número):
BIomieInvoiceDetail.open({ numero_nf: '12345' });

// Em click de linha de NF (por nota_key ou chave):
BIomieInvoiceDetail.open({ nota_key: 'abc123def' });
BIomieInvoiceDetail.open({ chave_nfe: '35250123456789...' });
```

## Fluxo de navegação

```
Dashboard (KPIs)
  └─ click vendedor → BIomieVendorDetail.open(id) → api/vendor_detail.php
       └─ aba Pedidos → row click → BIomieOrderDetail.open(codigo)
            └─ aba NFs → row click → BIomieInvoiceDetail.open({ nota_key })
                 └─ aba Financeiro → lista de títulos (sem drilldown adicional)

Tela de Pedidos (pedidos.php)
  └─ busca: api/orders_search.php → results → click → BIomieOrderDetail.open()

Tela de NFs (nf-periodo.php)
  └─ click linha → BIomieInvoiceDetail.open({ numero_nf })
```

## Tabelas consultadas

| Tabela | Uso |
|---|---|
| `biomie_pedidos_canonicos` | Pedidos (order_detail, orders_search) |
| `biomie_notas_fiscais_canonicas` | NFs (invoice_detail, order_detail) |
| `biomie_notas_fiscais_itens_canonicos` | Itens de NF |
| `biomie_titulos_financeiros_canonicos` | Títulos financeiros |
| `biomie_xml_documentos_canonicos` | URLs DANFE/XML (degradação graciosa) |

## Integração nas telas (Fase 8B)

### Como cada tela aciona os drawers

| Tela | Trigger | Drawer aberto |
|---|---|---|
| `vendedores.php` | Click no nome do vendedor na tabela | `BIomieVendorDetail` |
| `vendedores.php` | `?vendor_id=...` na URL (deep-link) | `BIomieVendorDetail` |
| `pedidos.php` | Click no número da NF (`ordersTable`) | `BIomieInvoiceDetail` |
| `pedidos.php` | Click no número do pedido (`pendingOrdersTable`) | `BIomieOrderDetail` |
| `pedidos.php` | Click no nome do vendedor | `BIomieVendorDetail` |
| `pedidos.php` | Busca via barra de pesquisa → click resultado | `BIomieInvoiceDetail` / `BIomieOrderDetail` |
| Qualquer drawer | Clique em "Ver pedido origem" | `BIomieOrderDetail` |
| Qualquer drawer | Clique em NF na aba NFs | `BIomieInvoiceDetail` |
| Qualquer drawer | Clique em vendedor | `BIomieVendorDetail` |

### Data attributes padronizados

| Atributo | Significado |
|---|---|
| `data-biomie-action="open-vendor"` | Abre drawer Vendor 360 |
| `data-biomie-action="open-order"` | Abre drawer Order Detail |
| `data-biomie-action="open-invoice"` | Abre drawer Invoice Detail |
| `data-vendor-id` | ID/código numérico do vendedor |
| `data-vendor-name` | Nome do vendedor |
| `data-pedido-codigo` | Código canônico do pedido |
| `data-codigo` | Fallback: NF ou pedido |
| `data-nota-key` | Chave interna da NF canônica |
| `data-numero-nf` | Número da NF |
| `data-chave-nfe` | Chave SEFAZ 44 dígitos |

O roteamento central é feito por `assets/js/modules/drilldown-router.js`.

### Módulos JS por página

| Arquivo | Página | Responsabilidade |
|---|---|---|
| `modules/drilldown-router.js` | Todas | Event delegation central para `data-biomie-action` |
| `modules/vendor-detail/index.js` | vendedores, pedidos | Drawer Vendor 360 com cache + focus trap |
| `modules/order-detail/index.js` | vendedores, pedidos | Drawer Order Detail com cache + focus trap |
| `modules/invoice-detail/index.js` | pedidos | Drawer Invoice Detail com cache + focus trap |
| `modules/vendors/page-drilldown.js` | vendedores | Override `openVendorDrawer`, deep-link handler |
| `modules/orders/page-drilldown.js` | pedidos | Barra de busca + paginação via `orders_search.php` |
| `modules/invoices/page-drilldown.js` | pedidos | MutationObserver para vendor links em novas linhas |

### Como testar URL direta

```
# Vendor 360 direto
vendedores.php?vendor_id=12345

# Com período
vendedores.php?vendor_id=12345&start=2025-01-01&end=2025-12-31
```

### Como testar drilldown manual

1. Abrir `pedidos.php`
2. Clicar no número de uma NF → abre `BIomieInvoiceDetail`
3. Na aba Resumo, clicar "Ver pedido origem" → abre `BIomieOrderDetail`
4. Na aba Resumo do pedido, clicar no nome do vendedor → abre `BIomieVendorDetail`
5. Na aba NFs do vendedor, clicar em uma NF → abre `BIomieInvoiceDetail`
6. ESC fecha o drawer ativo e devolve foco ao elemento de origem

### Permissões

| Endpoint | Cap. mínima |
|---|---|
| `vendor_detail.php` | `sales.view` |
| `order_detail.php` | `sales.view` OU `fiscal.view` |
| `invoice_detail.php` | `fiscal.view` OU `sales.view` |
| `orders_search.php` | `sales.view` OU `fiscal.view` |

### O que fazer se NF não tiver DANFE/XML local

O drawer mostra "DANFE não disponível" / "XML não disponível" ao lado dos links.
Isso indica que a tabela `biomie_xml_documentos_canonicos` não tem entrada para aquela NF.
Ação: verificar se o cron de sync de XML está ativo e cobrindo o período.

## Invariantes

- Nenhum endpoint de detalhe chama o Omie diretamente.
- URLs DANFE/XML são validadas no frontend (`https://` ou `http://` apenas) antes de renderizar.
- Erros em tabelas opcionais (ex: `biomie_xml_documentos_canonicos`) são silenciados com fallback `null`.
- Paginação obrigatória em `orders_search.php` — máximo 200 resultados por página.
- Todos os endpoints verificam RBAC com `Auth::requireAnyCapability()` antes de qualquer query.
- Cache de 60s por session: drawers não re-buscam o mesmo item se aberto novamente dentro de 1 min.
- AbortController: requests anteriores são cancelados quando um novo drawer abre antes do anterior terminar.
- Focus trap: Tab/Shift+Tab não escapam do drawer enquanto aberto; Escape fecha e devolve foco.
- Progressive enhancement: se JS falhar, links de vendedor navegam normalmente para `vendedores.php`.

# EST.R1 — Sync Real de Estoque Omie por Produto/Local

**Data:** 2026-05-30  
**Branch:** main  
**Escopo:** Fase 0 (estabilização UX pós-COM.R3) + EST.R1 (sincronização de estoque)

---

## 1. Fase 0 — Estabilização UX/Funcional

### 0.1 HTTP 500 na tabela server-side (pedidos.php)

**Causa raiz:** `api/nf_periodo_table.php` reutilizava o mesmo parâmetro PDO `:search` em 4 colunas — PDO não suporta o mesmo named param múltiplas vezes em uma única query.

**Fix:** Parâmetros renomeados para `:search1`, `:search2`, `:search3`. Limite `length` corrigido para `max 100` (era 200). Bootstrap trocado para o padrão canônico (`require bootstrap.php` + `Auth::requireCapability`).

### 0.2 Botões/filtros rápidos sem função

Adicionados ao backend (`nf_periodo_table.php`) e ao módulo JS (`nf-periodo-table.js`):
- `has_outras` → `COALESCE(nf.valor_outras_despesas, 0) > 0`
- `has_seguro` → `COALESCE(nf.valor_seguro, 0) > 0`
- `com_desconto` → `COALESCE(nf.valor_desconto, 0) > 0`

Tabs de escopo (`nfScopeTabs`) agora exibidas imediatamente pelo inline script em vez de esperar o callback do `app.js`.

### 0.3 Drawer global

`assets/css/biomie-drawer.css` adicionado ao `PageAssetRegistry::BASE_CSS` — carregado em todas as páginas automaticamente.

### 0.4 "Em aberto" × "Carteira aceita"

Card "Em aberto" removido do dashboard comercial (`views/index.html`). Substituído por comentário semântico. Apenas "Carteira aceita" permanece (pedidos aceitos não faturados).

### 0.5 Dashboard timeout

Criado `tools/dev/commercial_dashboard_performance_audit.php` para medir tempo de cada bloco por cenário (mensal, anual, quebrado). Threshold: WARN > 4s, CRITICAL > 8s.

---

## 2. EST.R1 — Infraestrutura de Estoque

### 2.1 Tabelas (já criadas na COM.R3, greenfield)

| Tabela | Propósito |
|--------|-----------|
| `biomie_estoque_locais_dim` | Locais físicos de estoque (filiais, depósitos) |
| `biomie_produtos_estoque_saldos` | Saldo atual, mínimo, inicial por produto × local |
| `biomie_produtos_estoque_movimentos` | Histórico de entradas/saídas |

### 2.2 Endpoints Omie investigados

| Endpoint URL | Call | Retorna |
|---|---|---|
| `/api/v1/geral/locaisestoque/` | `ListarLocaisEstoque` | Locais com nome, ativo, permite_venda |
| `/api/v1/geral/estoque/` | `ResumoEstoque` | Saldo por produto × local |
| `/api/v1/geral/estoque/` | `ConsultarEstoque` | Saldo de produto específico |
| `/api/v1/geral/estoque/` | `ConsultarMovimentoEstoque` | Movimentos com tipo, qtd, documento |

### 2.3 Serviços criados

```
app/Stock/
├── OmieStockClient.php        — wrapper read-only para API Omie
├── StockPayloadMapper.php     — mapeamento payload Omie → modelo canônico
├── StockSyncCoordinator.php   — orquestra dry-run / apply
├── StockReadRepository.php    — leitura de estoque do DB
└── ProductStockResolver.php   — resolve status de estoque para o Product 360
```

### 2.4 APIs atualizadas

- `api/products.php` — modo `stock_stats` adicionado (lazy load para mercadorias.html)
- `api/product_detail.php` — delega tab `estoque` ao `Product360ReadService` (já existia o service)

### 2.5 Product360ReadRepository + Service

Adicionados ao repositório:
- `stockByLocation(string $productCode): array` — saldo por local com status
- `stockMovements(string $productCode, string $start, string $end, int $limit): array`

Service atualizado para carregar stock/stock_movements quando `$tab === 'estoque'` ou `$loadAll`.

### 2.6 mercadorias.html

Seção recolhível "Estoque e Ruptura" adicionada ao final da página:
- 4 KPI cards: com estoque, abaixo do mínimo, ruptura, sem sync
- Tabela com top 50 produtos por saldo total
- Filtros por status (OK, abaixo min, ruptura, sem sync)
- Carregamento lazy (somente quando expandido)

---

## 3. Como rodar dry-run

```bash
# Verificar endpoints disponíveis na Omie
php tools/dev/omie_stock_endpoint_probe.php --dry-run --sample=3 --pretty

# Verificar estado local das tabelas
php tools/dev/product_stock_sync_audit.php --pretty

# Verificar Product 360 e guards
php tools/dev/product_stock_product360_audit.php --pretty
```

## 4. Como rodar apply

```bash
# 1. Sync de locais
php tools/dev/sync_stock_omie.php --apply --locations --pretty

# 2. Sync de saldos (todos os produtos)
php tools/dev/sync_stock_omie.php --apply --balances --pretty

# 3. Sync de movimentos (período)
php tools/dev/sync_stock_omie.php --apply --movements --start=2026-01-01 --end=2026-05-30 --pretty

# 4. Produto específico
php tools/dev/sync_stock_omie.php --apply --product-code=SKU123 --balances --movements --start=2026-01-01 --end=2026-05-30 --pretty
```

## 5. Validar Product 360

Após sync, abrir Product 360 de qualquer produto:
- Aba Estoque → deve mostrar saldos por local
- Se vazia → empty state "Produto sem saldo de estoque — sincronizar via Omie"

## 6. Limitações conhecidas

- Os endpoints Omie e campos retornados dependem do plano contratado.
- Campos como `saldo_disponivel` e `saldo_reservado` não foram confirmados — mapeados como `estoque_atual` genérico até validação via `omie_stock_endpoint_probe.php`.
- Movimentos históricos podem ser incompletos para períodos anteriores a 2025.
- `custo_medio` depende de configuração Omie do cliente.

## 7. Rollback

Se necessário:
```bash
# Tabelas são greenfield — podem ser truncadas sem risco comercial/fiscal
TRUNCATE TABLE biomie_produtos_estoque_movimentos;
TRUNCATE TABLE biomie_produtos_estoque_saldos;
TRUNCATE TABLE biomie_estoque_locais_dim;
```

As migrations são idempotentes (verificam existência antes de criar).

## 8. Cron futuro (EST.R1.1)

Sugestão de cron no Hostinger (diário, fora de horário comercial):
```
0 2 * * * php /path/to/biomie/tools/dev/sync_stock_omie.php --apply --locations --balances 2>&1
0 3 * * * php /path/to/biomie/tools/dev/sync_stock_omie.php --apply --movements --start=$(date -d '7 days ago' +%Y-%m-%d) --end=$(date +%Y-%m-%d) 2>&1
```

---

## 9. Guards

| Guard | Checks |
|-------|--------|
| D32 (biomie_release_guard.php) | 10 checks: tabelas, serviços, CLI, service tab, sem SELECT *, filters, drawer CSS |
| product_stock_product360_audit.php | 28 checks: service, repo, API, mercadorias, drawer, migrations |
| product_stock_sync_audit.php | Contagens, cobertura NF×estoque, último sync, abaixo min, ruptura |
| commercial_dashboard_performance_audit.php | Tempo por bloco por cenário (mensal/anual/quebrado) |

# Fase 5 — Manutenibilidade e Modularização

## Objetivo

Reduzir arquivos grandes e acoplados sem mudar regra de negócio, contrato de API, visual ou comportamento funcional.

Princípio: **extrair → testar → delegar → reduzir → melhorar** (nessa ordem).

## Regra central

- Arquivos antigos viram **fachadas temporárias** que delegam para classes novas.
- Nenhum método público é removido.
- Nenhum contrato JSON muda.
- Nenhum SQL de negócio muda.
- Nenhum visual muda.

---

## Linha de base (Fase 5 — início)

| Arquivo | Linhas | Meta Fase 5 |
|---------|--------|-------------|
| `app/CanonicalRepository.php` | 1715 | < 900 |
| `app/Canonical/CanonicalReadRepository.php` | 1466 | < 800 |
| `app/GoldMartBuilder.php` | 990 | < 450 |
| `api/overview.php` | 820 | < 250 |
| `assets/js/app.js` | 2636 | < 1500 |
| `assets/js/finance.js` | 1263 | < 700 |
| `views/admin.php` | 1189 | < 500 |

---

## Estado validado nesta rodada

Auditoria executada em 2026-05-08 com `php tools/dev/large_file_audit.php --json`:

| Arquivo | Linhas atuais | Status |
|---------|---------------|--------|
| `app/CanonicalRepository.php` | 892 | Abaixo da meta inicial de 900; segue como fachada parcial |
| `app/GoldMartBuilder.php` | 52 | Fachada para `GoldRefreshCoordinator` |
| `views/admin.php` | 188 | View principal ja delega para partials |
| `assets/js/app.js` | 923 | Abaixo da meta inicial de 1500 |
| `assets/js/finance.js` | 631 | Abaixo da meta inicial de 700 |
| `app/Canonical/CanonicalReadRepository.php` | 1283 | Ainda critico; reducao inicial aplicada por delegacao |
| `api/overview.php` | 904 | Ainda critico; deve virar endpoint fino em uma subfase dedicada |

Nesta rodada, a reducao segura em `CanonicalReadRepository.php` moveu leitura financeira, leitura de eventos, enriquecimento de dimensoes e mapeamento de exibicao para classes menores, sem alterar contrato de retorno.

---

## Subfases

### 5.0 — Auditoria e mapa ✅

- `tools/dev/large_file_audit.php` — inventário automático de arquivos grandes
- `docs/REFACTOR_PHASE5_PLAN.md` — este arquivo
- `docs/ARCHITECTURE_MODULES.md` — estrutura-alvo dos módulos

### 5.1 — Estrutura base ✅

Criar diretórios vazios com `.gitkeep`:

```
app/DataPlatform/Silver/Schema/
app/DataPlatform/Silver/Writers/
app/DataPlatform/Silver/Readers/
app/DataPlatform/Gold/Builders/
app/DataPlatform/Reconciliation/
app/Modules/Sales/Infrastructure/
app/Modules/Finance/Infrastructure/
app/Modules/Fiscal/Infrastructure/
app/Modules/Geo/Infrastructure/
assets/js/core/
assets/js/modules/finance/
assets/js/modules/admin/
```

### 5.2 — DataPlatform PHP

#### CanonicalRepository.php → fachada

Extrações:

| Novo arquivo | Responsabilidade |
|---|---|
| `app/DataPlatform/Silver/Schema/CanonicalSchemaManager.php` | CREATE TABLE, ALTER TABLE, schema helpers |
| `app/Modules/Geo/Infrastructure/GeoFactReconstructor.php` | `reconstructGeoFactTables()`, `normalizeGeoTextSql()` |
| `app/Modules/Geo/Infrastructure/ClientDimensionWriter.php` | `upsertClientDimensions()`, `getPendingEnrichmentClients()`, `markEnrichmentStatus()` |

`CanonicalRepository` mantém todos os métodos públicos, delegando para as novas classes.

#### GoldMartBuilder.php → fachada

Extrações:

| Novo arquivo | Método privado original |
|---|---|
| `app/DataPlatform/Gold/Builders/CommercialOrderMartBuilder.php` | `refreshComercialPedido()` |
| `app/DataPlatform/Gold/Builders/FiscalNfeMartBuilder.php` | `refreshFiscalNfe()` |
| `app/DataPlatform/Gold/Builders/FinanceTitleMartBuilder.php` | `refreshFinanceiroTitulo()` |
| `app/DataPlatform/Gold/Builders/VendorDayMartBuilder.php` | `refreshVendedorDia()` |
| `app/DataPlatform/Gold/Builders/CustomerPeriodMartBuilder.php` | `refreshClientePeriodo()` |
| `app/DataPlatform/Gold/Builders/ProductPeriodMartBuilder.php` | `refreshProdutoPeriodo()` |
| `app/DataPlatform/Gold/Builders/GeoBuyerMartBuilder.php` | `refreshCompradoresPorGeo()` |
| `app/DataPlatform/Gold/Builders/CnaeSalesMartBuilder.php` | `refreshVendasPorCnaeCnpj()` |
| `app/DataPlatform/Gold/Builders/BridgeMartBuilder.php` | `refreshBridge()` |
| `app/DataPlatform/Gold/GoldRefreshCoordinator.php` | Orquestra todos os builders |

#### CanonicalReadRepository.php - fachada gradual

Extracoes pequenas ja validadas:

| Novo arquivo | Responsabilidade |
|---|---|
| `app/Modules/Finance/Infrastructure/FinanceReadRepository.php` | leitura de titulos financeiros canonicos por periodo |
| `app/DataPlatform/Silver/Readers/CanonicalEventReadRepository.php` | leitura de eventos canonicos por periodo |
| `app/DataPlatform/Silver/Readers/CanonicalDimensionEnricher.php` | enriquecimento de cliente/vendedor por dimensoes locais |
| `app/DataPlatform/Silver/Readers/CanonicalDisplayMapper.php` | mapeamento de pedidos/notas para formato de exibicao |
| `app/Modules/Sales/Infrastructure/SalesReadRepository.php` | adaptador de leitura comercial por dominio |
| `app/Modules/Fiscal/Infrastructure/FiscalReadRepository.php` | adaptador de leitura fiscal por dominio |
| `app/Modules/Geo/Infrastructure/GeoReadRepository.php` | adaptador reservado para leituras geo |

`CanonicalReadRepository` continua sendo o ponto publico existente, mas passa a delegar responsabilidades isoladas.

### 5.3 — API Overview

`api/overview.php` deve virar endpoint fino (< 250 linhas).

Extrações para `app/Modules/Sales/Application/Overview/`:
- `OverviewPayloadBuilder.php`
- `LegacyOverviewPayloadBuilder.php`
- `CanonicalOverviewPayloadBuilder.php`

### 5.4 — Frontend core (app.js)

Funções puras extraídas para `assets/js/core/`:
- `formatters.js` — `formatCurrency`, `formatNumber`, `formatDate`, `escapeHtml`
- `api-client.js` — `fetchJson`, `buildApiUrl`
- `date-range.js` — `getDateRange`, resolução de período
- `chart-factory.js` — `createLineChart`, `createBarChart`, wrappers Chart.js
- `table-renderer.js` — `renderTable`, helpers de tabela
- `refresh-polling.js` — `pollRefreshStatus`

Compatibilidade: `window.BIomie.*` mantida em `app.js`.

### 5.5 — Finance JS

Extrações para `assets/js/modules/finance/`:
- `formatters.js`, `charts.js`, `tables.js`, `filters.js`, `sync.js`, `index.js`

Compatibilidade: `window.BIomieFinance.*` mantida.

### 5.6 — Admin view

`views/admin.php` → extrai partials:
- `views/admin/tabs/users.php`
- `views/admin/tabs/roles.php`
- `views/admin/tabs/logs.php`
- `views/admin/tabs/migrations.php`
- `views/admin/tabs/console.php`
- `views/admin/partials/modal.php`
- `views/admin/partials/result_panel.php`
- `assets/js/modules/admin/index.js`

### 5.7 — Auditoria final

```bash
php tools/dev/lint_all.php
php bin/check_encoding.php
php tools/dev/rbac_audit.php
php tests/run_phase2_coverage.php
php tools/dev/large_file_audit.php
```

---

## Limites para arquivos novos

| Tipo | Limite ideal |
|------|-------------|
| PHP | < 350 linhas |
| JS | < 300 linhas |
| View partial | < 250 linhas |

Se um arquivo novo nascer com > 400 linhas, dividir antes de continuar.

---

## Referências

- PSR-4 autoload (namespaces mapeados para caminhos)
- Strangler Fig pattern (substituição gradual, fachadas temporárias)
- MDN JavaScript Modules (fase posterior: migrar para `type="module"`)

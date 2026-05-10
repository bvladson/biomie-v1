# Arquitetura de Módulos — BIomie

## Visão geral

O BIomie está sendo migrado gradualmente de uma estrutura monolítica para uma estrutura modular por domínio. Os arquivos antigos continuam existindo como **fachadas** que delegam para classes novas — nenhuma chamada existente quebra.

---

## Estrutura-alvo

```
app/
├── Shared/                        # Utilitários transversais (já existe: app/Support/)
│   ├── Database/
│   ├── Http/
│   ├── Support/
│   ├── Security/
│   └── Contracts/
│
├── Integrations/
│   └── Omie/                      # Clientes e adapters Omie (já existe: app/Omie/)
│
├── Modules/                       # Domínios de negócio (novo)
│   ├── Sales/
│   │   ├── Application/
│   │   │   └── Overview/          # Builders do endpoint overview
│   │   ├── Domain/
│   │   └── Infrastructure/        # SalesReadRepository
│   │
│   ├── Finance/
│   │   ├── Application/
│   │   ├── Domain/
│   │   └── Infrastructure/        # FinanceReadRepository
│   │
│   ├── Fiscal/
│   │   ├── Application/
│   │   ├── Domain/
│   │   └── Infrastructure/        # FiscalReadRepository
│   │
│   └── Geo/
│       ├── Application/
│       ├── Domain/
│       └── Infrastructure/        # GeoReadRepository, GeoFactReconstructor, ClientDimensionWriter
│
└── DataPlatform/                  # Camada de dados (novo — extrai de CanonicalRepository, GoldMartBuilder)
    ├── Bronze/
    ├── Silver/
    │   ├── Schema/                # CanonicalSchemaManager
    │   ├── Writers/               # CanonicalOrderWriter, CanonicalInvoiceWriter, etc.
    │   ├── Readers/               # CanonicalEventReadRepository, CanonicalDisplayMapper, etc.
    │   └── State/                 # CanonicalSyncStateRepository (já existe em app/Canonical/)
    ├── Gold/
    │   ├── Builders/              # Um builder por mart
    │   └── GoldRefreshCoordinator.php
    ├── Semantic/
    ├── Freshness/
    └── Reconciliation/
```

---

## Namespaces PHP

Seguindo PSR-4 com autoload configurado no `composer.json`:

| Caminho | Namespace |
|---------|-----------|
| `app/` | `BIomie\` |
| `app/Modules/Sales/` | `BIomie\Modules\Sales\` |
| `app/Modules/Finance/` | `BIomie\Modules\Finance\` |
| `app/Modules/Fiscal/` | `BIomie\Modules\Fiscal\` |
| `app/Modules/Geo/` | `BIomie\Modules\Geo\` |
| `app/DataPlatform/` | `BIomie\DataPlatform\` |
| `app/Shared/` | `BIomie\Shared\` |

---

## Estrutura JS alvo

```
assets/js/
├── core/                          # Funções puras reutilizáveis (novo)
│   ├── api-client.js              # fetchJson, buildApiUrl
│   ├── formatters.js              # formatCurrency, formatNumber, escapeHtml
│   ├── date-range.js              # resolução de período
│   ├── chart-factory.js           # createLineChart, createBarChart
│   ├── chart-theme.js             # cores, defaults Chart.js
│   ├── table-renderer.js          # renderTable
│   ├── dom.js                     # helpers DOM
│   └── refresh-polling.js         # pollRefreshStatus
│
├── modules/                       # Módulos por tela/domínio (novo)
│   ├── overview/
│   ├── vendors/
│   ├── orders/
│   ├── products/
│   ├── finance/                   # Extraído de finance.js
│   │   ├── api.js
│   │   ├── charts.js
│   │   ├── tables.js
│   │   ├── filters.js
│   │   ├── sync.js
│   │   └── index.js
│   └── admin/                     # Extraído de views/admin.php JS inline
│       ├── tabs.js
│       ├── modals.js
│       ├── console.js
│       └── index.js
│
├── app.js                         # Fachada — continua carregando módulos e expondo window.BIomie.*
└── finance.js                     # Fachada — continua carregando window.BIomieFinance.*
```

---

## Regras de transição

1. **Fachadas temporárias**: arquivos originais continuam com mesmos métodos públicos.
2. **window.BIomie**: funções JS extraídas são expostas via `window.BIomie.*` para compatibilidade com views existentes.
3. **require_once temporário**: novos arquivos PHP são incluídos via `require_once` onde o autoload PSR-4 ainda não estiver ativo.
4. **Sem big bang**: cada extração é feita em um commit separado, com lint verificado.

---

## Fachadas existentes (após Fase 5)

| Arquivo original | Delega para |
|---|---|
| `app/CanonicalRepository.php` | `CanonicalSchemaManager`, `GeoFactReconstructor`, `ClientDimensionWriter`, sub-repos existentes |
| `app/Canonical/CanonicalReadRepository.php` | `FinanceReadRepository`, `CanonicalEventReadRepository`, `CanonicalDimensionEnricher`, `CanonicalDisplayMapper` para extracoes pontuais |
| `app/GoldMartBuilder.php` | Builders individuais em `DataPlatform/Gold/Builders/` |
| `assets/js/app.js` | `core/` + `modules/` |
| `assets/js/finance.js` | `modules/finance/` |
| `views/admin.php` | partials em `views/admin/` |

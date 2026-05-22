# Contrato: Escopo de Operação Omie (Fase H)

## Objetivo

Separar oficialmente as operações do Omie no BIomie em dois escopos mutuamente exclusivos:

| Escopo | Valor DB | Operação Omie | Onde aparece |
|--------|----------|---------------|-------------|
| Comercial | `commercial` | Orçamento - PD | Dashboard, Vendor 360, Plano de Voo, Mix Faturado, Geo, Rankings |
| Remessa | `remittance` | Remessa de Produto | Somente NF do Período (alerta informativo) |
| Desconhecido | `unknown` | Não classificado | Pós-backfill deve ser < 5% |

## Regras Absolutas

1. Proibido usar CSV, planilha, texto livre de cliente, CFOP ou qualquer fonte que não seja o campo de operação Omie como discriminador.
2. Fonte única: `ide.natOp` na NF-e; `cabecalho.nome_tipo_atividade` no pedido.
3. Nenhum valor do Dashboard Comercial inclui `operation_scope = 'remittance'` ou `'unknown'`.
4. Unknown nunca entra na base comercial por padrão — nem pré-migration.
5. Sync nunca é interrompido por falha na classificação de escopo.

## Campos no Banco

### `biomie_notas_fiscais_canonicas` (migration H3)
```sql
operation_scope             VARCHAR(32)  NOT NULL DEFAULT 'unknown'  -- 'commercial'|'remittance'|'unknown'
omie_operation_name         VARCHAR(120) NULL   -- valor raw do Omie (ex: "REMESSA DE PRODUTO")
operation_scope_source      VARCHAR(60)  NULL   -- ex: 'ide.natOp', 'ide.finNFe'
operation_scope_confidence  VARCHAR(20)  NULL   -- 'high' | 'medium' | 'low' | 'unknown'
operation_scope_rule        VARCHAR(120) NULL   -- ex: 'exact_orçamento___pd', 'db_exact_...'
operation_scope_checked_at  DATETIME     NULL   -- timestamp do último backfill/sync
```

### `biomie_pedidos_canonicos` (migration H3)
```sql
operation_scope             VARCHAR(32)  NOT NULL DEFAULT 'unknown'
omie_operation_name         VARCHAR(120) NULL
operation_scope_source      VARCHAR(60)  NULL
operation_scope_confidence  VARCHAR(20)  NULL
operation_scope_rule        VARCHAR(120) NULL
operation_scope_checked_at  DATETIME     NULL
```

### `biomie_operation_scope_rules` (opcional, prioridade sobre constantes PHP)
```sql
CREATE TABLE biomie_operation_scope_rules (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  normalized_pattern VARCHAR(120) NOT NULL,        -- lowercase, trimmed
  scope              ENUM('commercial','remittance') NOT NULL,
  priority           INT NOT NULL DEFAULT 10,
  is_active          TINYINT(1) NOT NULL DEFAULT 1,
  description        VARCHAR(255) NULL,
  created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pattern (normalized_pattern)
);
```

## Classificação — OmieOperationScope

**Arquivo:** `app/Support/OmieOperationScope.php`

**Prioridade de classificação (`classifyInvoice`):**
1. `ide.natOp` → `classifyName()` com regras DB + constantes PHP
   - Match exato → confidence `high`; substring → confidence `medium`
2. `finNFe = 4` → `remittance` (confidence `medium`)
3. `det[0].prod.CFOP` → `classifyByCfop()` (adicionado Fase H.3)
   - CFOPs de remessa (ex: 5.915, 5.911, 5.916) → `remittance`
   - CFOPs comerciais → `commercial`
4. `finNFe = 1` → `unknown` (**nunca** `commercial` — regra absoluta)
5. Fallback → `unknown` (confidence `unknown`)

**Manual Override (Fase H.4):**

NFs com `operation_scope_rule LIKE 'manual_override%'` são **protegidas permanentemente** — nenhum backfill recalcula seu escopo. Para casos onde o CFOP do sistema contradiz a natureza fiscal real da operação:

```sql
-- Exemplo: NFs 00001285/00001286 — CFOP 6.116 (comercial no sistema, remessa para MAG Móveis)
UPDATE biomie_notas_fiscais_canonicas
SET operation_scope            = 'remittance',
    operation_scope_source     = 'manual_override',
    operation_scope_confidence = 'high',
    operation_scope_rule       = 'manual_override_remittance_cfop6116',
    omie_operation_name        = 'Remessa de Produto'
WHERE numero_nf IN ('00001285', '00001286');
```

O script `backfill_operation_origin_scope.php` guarda essa proteção com:
```sql
WHERE operation_scope_rule IS NULL OR operation_scope_rule NOT LIKE 'manual_override%'
```

**API pública (com lineage completo):**
```php
OmieOperationScope::classifyName(string $name, string $source): array
// → ['scope'=>'commercial','source'=>'ide.natOp','confidence'=>'high','rule'=>'exact_orçamento___pd','raw_value'=>'Orçamento - PD']

OmieOperationScope::classifyInvoice(array $rawInvoice): array
OmieOperationScope::classifyOrder(array $rawOrder): array

// Atalhos (retornam scope string apenas — compatibilidade):
OmieOperationScope::scopeForName(string $name): string
OmieOperationScope::scopeForInvoice(array $rawInvoice): string
OmieOperationScope::scopeForOrder(array $rawOrder): string

OmieOperationScope::isCommercial(string $scope): bool
OmieOperationScope::isRemittance(string $scope): bool
OmieOperationScope::isUnknown(string $scope): bool
OmieOperationScope::label(string $scope): string   // 'Orçamento - PD' | 'Remessa de Produto' | 'Operação desconhecida'
OmieOperationScope::loadRulesFromDb(PDO $pdo): void // silencioso em falha
OmieOperationScope::rulesSource(): string           // 'php_constants' | 'database'
```

## Filtros SQL — CanonicalReadSupport

**Arquivo:** `app/DataPlatform/Silver/Readers/CanonicalReadSupport.php`

```php
// Filtra apenas commercial. Retorna '' pré-migration (coluna ausente).
// Lança OperationScopeEnforcedException (HTTP 503) se enforce_mode ativo e coluna ausente.
$this->commercialScopeFilter(string $tableAlias = ''): string

// Genérico: qualquer scope.
$this->scopeFilter(string $tableAlias, string $scope): string

// Verifica se coluna operation_scope existe.
$this->operationScopeAvailable(): bool
```

**Exemplo de uso em query:**
```php
$scopeFilter = $this->commercialScopeFilter('n.');
$sql = "SELECT ... FROM biomie_notas_fiscais_canonicas n
        WHERE n.data_competencia BETWEEN :start AND :end{$scopeFilter}";
```

## Enforce Mode

**Variável de ambiente:** `BIOMIE_OPERATION_SCOPE_ENFORCED=1`
**Config alternativa:** `features.operation_scope_enforced = true`

Com enforce mode ativo, se a coluna `operation_scope` estiver ausente nas tabelas canônicas, `commercialScopeFilter()` lança `OperationScopeEnforcedException` (HTTP 503).

**ErrorBoundary** captura a exceção e retorna:
```json
{
  "ok": false,
  "error": "...",
  "reason_code": "operation_scope_missing",
  "request_id": "..."
}
```

O campo `reason_code` vem da interface `HasReasonCode` (`app/Http/HasReasonCode.php`).

## Gold Marts — AbstractMartBuilder

**Arquivo:** `app/DataPlatform/Gold/Builders/AbstractMartBuilder.php`

```php
protected function commercialScopeFragment(string $table, string $alias): string
// → "\n              AND {alias}.operation_scope = 'commercial'"   (se coluna existe)
// → ''                                                              (pré-migration)
```

Todos os builders que materializam dados comerciais (`CustomerPeriodMartBuilder`, `ProductPeriodMartBuilder`, `GeoBuyerMartBuilder`) injetam esse fragmento no WHERE. Isso garante que re-materialização pós-H3 elimina automaticamente remessas dos marts.

## Sync — CanonicalWriteRepository

**Arquivo:** `app/Canonical/CanonicalWriteRepository.php`

Após cada INSERT/UPSERT de NF ou pedido, o repositório tenta atualizar os campos de scope:

```php
$this->tryUpdateInvoiceScope(string $notaKey, array $rawInvoice): void
$this->tryUpdateOrderScope(int $pedidoIdOmie, array $rawOrder): void
```

Ambos são fire-and-forget (try/catch silencioso) — nunca interrompem o sync.
Reutilizam cache de existência de coluna (`$nfScopeColumnExists`, `$ordScopeColumnExists`) para não consultar `information_schema` a cada NF.

**FiscalCanonicalMapper** (`app/FiscalSync/FiscalCanonicalMapper.php`) também preenche os campos ao normalizar:
```php
$scopeLineage = OmieOperationScope::classifyInvoice($raw);
// → 'operation_scope', 'omie_operation_name', 'operation_scope_source',
//   'operation_scope_confidence', 'operation_scope_rule'
```

## Labels Visuais por Escopo (Fase H.5)

Dashboard, Plano de Voo, Vendor 360 e todos os KPIs comerciais exibem os seguintes rótulos:

| Antes (ambíguo) | Depois (Fase H.5) |
|---|---|
| Vendas | Faturamento Orçamento-PD |
| Pedidos | NFs Orçamento-PD |
| Ticket médio | Ticket médio Orçamento-PD |
| Ranking comercial | Ranking comercial — Orç-PD |
| Evolução da receita | Evolução do faturamento Orç-PD |
| Produtos em destaque | Produtos em destaque — Orç-PD |

**Card informativo de Remessa** (Dashboard, painel "Recebimentos e risco"):
- Fonte: `api/commercial_health.php` → campo `remittance_summary`
- Exibido apenas quando `nf_count > 0` no período
- Contém link "Ver remessas" → `pedidos.php?scope=remittance`

## NF do Período — Três Abas (H5 / H.5)

**Endpoint:** `api/orders.php` — campos adicionados ao payload:
```json
{
  "scope_tabs_available": true,
  "orders_remittance": [ ... ]
}
```

`scope_tabs_available` é `true` apenas quando a coluna `operation_scope` existe (pós-migration H3).

**UI:** `views/pedidos.html` exibe barra de abas `#nfScopeTabs` (oculta pré-migration):
- **Orçamento-PD** — NFs `commercial`, dados do faturamento real
- **Remessa** — NFs `remittance` com badge de contagem e alerta: "movimentação de mercadoria sem transferência de propriedade — não compõe faturamento comercial, ticket médio, ranking, metas, Plano de Voo, Mix ou Geo"
- **Todas** — todas as NFs com badge colorido por linha (`_display_scope`) para conferência fiscal

O JS atualiza o título e subtítulo da seção dinamicamente ao trocar de aba via `NF_SCOPE_META`.

## Drawer NF — Badge + Explicação de Negócio (H6 / H.5)

**Endpoint:** `api/invoice_detail.php` — campos no objeto `invoice`:
```json
{
  "operation_scope":              "commercial" | "remittance" | "unknown" | null,
  "omie_operation_name":         "VENDA DE MERCADORIAS",
  "operation_scope_confidence":  "high",
  "operation_scope_source":      "ide.natOp"
}
```

Todos os campos são `null` quando a coluna ainda não existe (pré-migration).

**UI:** `assets/js/modules/invoice-detail/index.js` exibe:
- Badge colorido com ícone e label:
  - `commercial` → verde: "Orçamento-PD — base comercial"
  - `remittance` → amarelo: "Remessa de Produto — conferência fiscal"
  - `unknown` → cinza: "Operação não identificada"
- Bloco de explicação de negócio por escopo (via `SCOPE_BADGE[scope].explanation`):
  - commercial: "Esta NF compõe o faturamento comercial, ranking, mix, metas e Plano de Voo."
  - remittance: "Esta NF registra movimentação de mercadoria e **não compõe** faturamento comercial, ticket médio, ranking, metas, Plano de Voo, Mix ou Geo."
  - unknown: "Esta NF está fora dos KPIs comerciais até classificação."
- `omie_operation_name` exibido abaixo do bloco de explicação (somente se não for código raw como "1", "5.915")

**Regra de display:** `omie_operation_name` é suprimido se `isRawCode = /^\d+(\.\d+)?$/`. Confidence e raw codes nunca aparecem na UI do drawer.

## System Health — OperationScopeHealthCheck

**Arquivo:** `app/SystemHealth/Checks/OperationScopeHealthCheck.php`

12 critérios (por severidade):

**CRITICAL:**
1. Coluna `operation_scope` ausente em `biomie_notas_fiscais_canonicas`
2. Coluna `operation_scope` ausente em `biomie_pedidos_canonicos`
3. Enforce mode ativo com colunas ausentes (amplifica mensagem)
4. Percentual de NFs `unknown` > 20% (`UNKNOWN_THRESHOLD_PCT`)
5. Nenhuma NF classificada como `commercial`

**WARNING:**
6. Colunas de lineage (`operation_scope_confidence` / `omie_operation_name`) ausentes
7. NFs autorizadas com `unknown` > 20%
8. Nenhuma NF `remittance` (incomum mas possível)
9. Tabela `biomie_operation_scope_rules` ausente
10. Regras DB inativas (tabela vazia ou sem `is_active=1`)
11. Gold marts contaminados (`mart_geo_buyer`, `mart_produto_periodo` com NFs não-comerciais)
12. Sync não populou scope — NFs sincronizadas nos últimos 7 dias com `unknown` > 50%

## Fluxo de Implantação

```
H1:  operation_scope_debug.php        → identificar campos no bronze
H2:  OmieOperationScope.php           → contrato de classificação (+ DB rules)
H3:  backfill_operation_scope.php     → migration DDL + backfill + lineage
H3b: classifyByCfop()                 → CFOP como 3ª prioridade (5.915, 5.911, 5.916 → remittance)
H4:  AbstractMartBuilder              → WHERE operation_scope = 'commercial' em todos os builders
H4b: fix_nf1285_1286_remittance.php  → manual_override CFOP 6.116 para MAG Móveis (NFs 1285/1286)
H4c: backfill_operation_origin_scope  → guard NOT LIKE 'manual_override%' (proteção permanente)
H5:  NF do Período — três abas        → Orçamento-PD | Remessa | Todas (api/orders.php + views/pedidos.html)
H5b: labels visuais                   → Vendas→Faturamento Orç-PD, Pedidos→NFs Orç-PD, etc.
H5c: card remessa Dashboard           → remittance_summary em commercial_health.php + index.html
H5d: commercial_kpi_scope_guard.php   → auditoria por módulo (T6)
H6:  Drawer NF                        → badge + explicação de negócio por scope (commercial/remittance/unknown)
H7:  fiscal_operation_summary.php     → endpoint diagnóstico
H8:  OperationScopeHealthCheck        → monitoramento contínuo (14 checks)
H9:  reconcile_omie_operation_scope   → reconciliação bronze→canonical
H10: CanonicalWriteRepository         → scope via fire-and-forget no sync incremental
I:   commercial_recalibration_audit   → cross-check de receita entre marts vs canonical
```

## Critérios de Aceite

A fase está concluída quando:

1. `operation_scope = 'commercial'` é a única base do faturamento comercial em todos os builders e endpoints.
2. `operation_scope = 'remittance'` não entra em KPIs do Dashboard, Vendor 360, Plano de Voo, Mix ou Geo.
3. `api/fiscal_operation_summary.php` retorna `scope_coverage_pct >= 95`.
4. `OperationScopeHealthCheck` retorna status `ok`.
5. NF do Período exibe duas abas separadas por escopo (pós-H3).
6. O drawer de NF exibe `omie_operation_name` e badge colorido.
7. Zero uso de CSV, planilha ou CFOP como discriminador.
8. `operation_scope = 'unknown'` < 5% após backfill completo.
9. `reconcile_omie_operation_scope.php` retorna divergências = 0.
10. Sync incremental preenche `operation_scope` nas novas NFs sem interrupção.
11. `operation_scope_unit_check.php` retorna exit 0 (todos os fixtures passam).

## Scripts de Operação

```bash
# Testes unitários de classificação (sem banco)
php tools/dev/operation_scope_unit_check.php
php tools/dev/operation_scope_unit_check.php --verbose
php tools/dev/operation_scope_unit_check.php --json

# Diagnosticar campos no bronze (antes de migrar)
php tools/dev/operation_scope_debug.php --raw

# Ver o que a migration vai fazer (dry-run)
php tools/dev/backfill_operation_scope.php --dry-run

# Aplicar migration H3 (DDL + backfill)
php tools/dev/backfill_operation_scope.php --apply

# Verificar status pós-backfill
php tools/dev/backfill_operation_scope.php --status

# Re-executar backfill de origem/escopo (respeita manual_override)
php tools/dev/backfill_operation_origin_scope.php --apply

# Criar/popular tabela de regras DB
php tools/dev/operation_scope_rules_setup.php --create --seed

# Auditoria de contaminação nos gold marts (por escopo)
php tools/dev/operation_scope_mart_audit.php
php tools/dev/operation_scope_mart_audit.php --json
php tools/dev/operation_scope_mart_audit.php --fix-hint

# Guard por módulo (Dashboard, Vendor 360, Geo, Mix, etc.) — Fase H.5 T6
php tools/dev/commercial_kpi_scope_guard.php
php tools/dev/commercial_kpi_scope_guard.php --json
php tools/dev/commercial_kpi_scope_guard.php --module=geo --verbose

# Cross-check de receita entre todos os marts vs canonical (Fase I)
php tools/dev/commercial_recalibration_audit.php
php tools/dev/commercial_recalibration_audit.php --start=2026-01-01 --end=2026-05-31 --json --pretty

# Reconciliar scope bronze→canonical
php tools/dev/reconcile_omie_operation_scope.php

# Corrigir unknown restantes
php tools/dev/reconcile_omie_operation_scope.php --fix-unknown

# API de diagnóstico
curl "https://HOST/biomie/api/fiscal_operation_summary.php?start=2026-05-01&end=2026-05-31"
```

## Notas de Implementação

- O campo `ide.natOp` na NF-e é texto livre configurado por conta Omie. Execute `operation_scope_debug.php --raw` para descobrir os valores exatos desta conta antes do backfill.
- Se `operation_scope_debug.php` mostrar valores inesperados, adicione-os a `COMMERCIAL_NAMES` ou `REMITTANCE_NAMES` em `OmieOperationScope.php`, ou insira uma linha em `biomie_operation_scope_rules` via `operation_scope_rules_setup.php`.
- Pré-migration H3: todos os filtros SQL retornam `''` (sem WHERE de scope), então queries funcionam sem erro mas retornam todos os registros. Apenas com enforce mode ativo é que o sistema recusa servir dados sem a coluna.
- A re-materialização dos gold marts pós-H3 elimina remessas automaticamente via `commercialScopeFragment()`.
- A tabela `biomie_operation_scope_rules` é opcional — sem ela, o sistema cai para as constantes PHP. Com ela, novas operações Omie podem ser mapeadas sem deploy.

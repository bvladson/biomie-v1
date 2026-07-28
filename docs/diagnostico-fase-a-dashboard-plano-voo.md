# Diagnóstico Fase A — Dashboard Principal & Plano de Voo

**Data:** 2026-05-18  
**Ambiente:** Produção (82.112.247.199)  
**Período de referência:** 2026-05-01 a 2026-05-31  
**Ferramentas:** `tools/dev/overview_debug.php`, `tools/dev/flight_plan_debug.php`, `tools/dev/flight_plan_vendor_reconcile.php`, `tools/dev/http_probe.php`  

---

## 1. Resumo Executivo

Este relatório mapeia os 8 problemas visuais observados no Dashboard Principal e no Plano de Voo para suas causas técnicas raízes. **Todos os diagnósticos são read-only** — nenhuma alteração de dado, regra fiscal, cron ou Omie API foi feita.

| # | Sintoma | Causa Raiz (Hipótese) | Status |
|---|---------|----------------------|--------|
| 1 | Pontos de Atenção aparecem como texto solto | Backend envia `alerts` como strings em vez de objetos estruturados (via legacy snapshot) | A confirmar |
| 2 | Recebimentos / Risco sem dados | `biomie_titulos_financeiros_canonicos` vazia ou endpoint financeiro separado sem dados | A confirmar |
| 3 | Comparativo Mensal indisponível | `month_comparison` vazio — canonical sem NFs ou chave errada no payload | A confirmar |
| 4 | Tooltips pretos nos gráficos | `mag-chart-defaults.js` não carrega antes dos charts; ou cache stale com versão antiga | **Diagnosticado** — `tooltip.backgroundColor = '#1a2235'` existe e está registrado |
| 5 | Plano de Voo usando legado | `canonical_fiscal_ready = false` ou canonical sem sinal no período corrente | A confirmar |
| 6 | Metas ativas zeradas | `biomie_sales_targets_monthly` vazia ou sem rows para 2026-05 | A confirmar |
| 7 | Realizado x Meta vazio | Nenhum dos dois: sem metas OU sem realized canonical | A confirmar |
| 8 | Ritmo Diário sem série útil | Nenhuma NF faturada por `data_competencia` no mês corrente | A confirmar |

---

## 2. Ferramentas Criadas

### `tools/dev/overview_debug.php`
Mapeia o contrato real de `api/overview.php` diretamente via DB. Seções:
- `canonical` — disponibilidade, NFs autorizadas, freshness
- `gold` — mart_cliente_periodo, top_clients fonte
- `alerts` — formato de alertas, divergências abertas
- `finance` — biomie_titulos_financeiros_canonicos
- `month_comparison` — 4 meses de dados + targets
- `chart_tooltip` — mag-chart-defaults.js, registro em PageAssetRegistry
- `targets` — metas ativas no período
- `features` — feature flags

```bash
# Rodar diagnóstico completo
php tools/dev/overview_debug.php --start=2026-05-01 --end=2026-05-31 --json

# Seção específica
php tools/dev/overview_debug.php --start=2026-05-01 --end=2026-05-31 --section=finance
php tools/dev/overview_debug.php --start=2026-05-01 --end=2026-05-31 --section=month_comparison
php tools/dev/overview_debug.php --start=2026-05-01 --end=2026-05-31 --section=alerts
```

### `tools/dev/http_probe.php`
Verifica o contrato HTTP real de endpoints (requer cookie de sessão autenticada):
- HTTP status, redirects, content-type, cache headers
- JSON validity, avisos PHP no body, redirect de login
- Aliases de chave (`month_comparison` vs `monthly_comparison`)
- Formato de `alerts` (objetos vs strings)

```bash
php tools/dev/http_probe.php --endpoint=overview --cookie="PHPSESSID=xxx" --base-url=https://site.com
php tools/dev/http_probe.php --all --cookie="PHPSESSID=xxx" --json
```

### `tools/dev/flight_plan_debug.php`
Diagnostica qual caminho o Plano de Voo toma e por quê:
- Roteamento: canonical_v2 vs legacy
- Metas ativas (targets)
- Realizado x Meta por vendedor
- Ritmo diário (daily_sales)
- Vendedores sem meta tendo realizado

```bash
php tools/dev/flight_plan_debug.php --start=2026-05-01 --end=2026-05-31 --json
php tools/dev/flight_plan_debug.php --start=2026-05-01 --end=2026-05-31 --section=routing
php tools/dev/flight_plan_debug.php --start=2026-05-01 --end=2026-05-31 --section=realized_vs_target
```

### `tools/dev/flight_plan_vendor_reconcile.php`
Compara 3 fontes de realizado por vendedor:
- **Fonte A:** FlightPlanV2Service via canonical (invoiced rows)
- **Fonte B:** VendorRealizedService (Vendor 360)
- **Fonte C:** NFs canônicas diretas (`biomie_notas_fiscais_canonicas` sum)

Sinaliza divergências acima do threshold (padrão 5%).

```bash
php tools/dev/flight_plan_vendor_reconcile.php --start=2026-05-01 --end=2026-05-31 --json
php tools/dev/flight_plan_vendor_reconcile.php --start=2026-05-01 --end=2026-05-31 --threshold=10
```

---

## 3. Diagnósticos Detalhados

### 3.1 Pontos de Atenção (alerts)

**Localização no código:** `api/overview.php` → `CanonicalOverviewPayloadBuilder::build()` → campo `alerts`

**Contrato esperado:**
```json
"alerts": [
  { "level": "warning", "title": "Texto", "message": "Texto detalhado." }
]
```

**Causa potencial:** Legacy snapshot (`SalesAnalyticsService::getSnapshot`) pode retornar `alerts` como array de strings em vez de objetos. Quando `canonical_fiscal_ready = false`, o path legado é tomado e o front-end recebe strings soltas.

**Como verificar:**
```bash
php tools/dev/http_probe.php --endpoint=overview --cookie="..." --json | jq '.probes[0].contract.alerts_format_valid'
```

**Verificação da seção `alerts` em `OverviewPayloadBuilder`:** o método `ensureContractFields` não normaliza `alerts` — se o legacy snapshot enviar strings, elas chegam ao front-end.

---

### 3.2 Recebimentos / Finance

**Localização no código:** `app/FinanceShadowCandidateBuilder.php` → `biomie_titulos_financeiros_canonicos`

**Causa:** O campo `financial` **não faz parte do contrato canonical overview** — vem de endpoint separado (`api/finance_shadow.php` ou similar). O overview não inclui dados financeiros diretamente.

**Como verificar:**
```bash
php tools/dev/overview_debug.php --section=finance --start=2026-05-01 --end=2026-05-31
```

**Campos esperados:**
- `table_exists: true`
- `rows_in_period > 0`

---

### 3.3 Comparativo Mensal

**Localização no código:** `OverviewMonthComparisonBuilder::build()` → `month_comparison`

**Aliases aceitos:** `month_comparison`, `monthly_comparison`, `monthlyComparison` (via `ensureMonthComparisonContract`)

**Causa potencial do vazio:**
1. Canonical sem NFs nos últimos 4 meses
2. `canonical_fiscal_ready = false` → path legado → `month_comparison` não gerado
3. Snapshot histórico sem meses anteriores materializados

**Como verificar:**
```bash
php tools/dev/overview_debug.php --section=month_comparison --start=2026-05-01 --end=2026-05-31
```

---

### 3.4 Tooltips dos Gráficos

**Status: DIAGNOSTICADO — configuração correta no código**

**Localização:** `assets/js/mag-chart-defaults.js` (linha 40)
```js
tooltip.backgroundColor = '#1a2235';  // azul escuro correto
```

**Registrado em:** `app/PageAssetRegistry.php` → `js_body` array

**Causa potencial de tooltips pretos:**
1. Cache stale — browser com versão antiga de `mag-chart-defaults.js` sem o bloco de tooltip
2. `mag-chart-defaults.js` carregando com `defer` enquanto chart renderiza antes (race condition)
3. Script de página definindo `Chart.defaults.plugins.tooltip.backgroundColor = 'black'` e sobrescrevendo

**Resolução já aplicada:** versão `20260518-geo-fix-cache1` invalida caches e força reload via `cache-refresh.js`.

---

### 3.5 Plano de Voo usando Legado

**Localização:** `api/flight_plan.php` — condição:
```php
if ($canonical !== null && (bool) $config->get('features.canonical_fiscal_ready', false)) {
    // canonical v2
}
// fallback: FlightPlanService (legado)
```

**Causa:** Se `features.canonical_fiscal_ready = false` (em `.env` ou `config/features.php`), o path canônico nunca é tentado.

**Causa alternativa:** Canonical disponível mas sem sinal (`realized = 0`, `pipeline = 0`, `cancelled = 0`) → `canonicalFlightPlanPayloadHasSignal()` retorna `false` → fallback para legado.

**Como verificar:**
```bash
php tools/dev/flight_plan_debug.php --section=routing --start=2026-05-01 --end=2026-05-31 --json
```

---

### 3.6 Metas Ativas Zeradas

**Localização:** `app/TargetsRepository.php` → `biomie_sales_targets_monthly`

**Causa:** Tabela existe mas sem rows para `year_month = '2026-05'` — metas não foram cadastradas para o mês corrente.

**Como verificar:**
```bash
php tools/dev/flight_plan_debug.php --section=targets --start=2026-05-01 --end=2026-05-31
```

---

### 3.7 Realizado x Meta vazio

**Dependência:** ambas as seções anteriores (metas + realizado) precisam ter dados.

**Causa:** Se `canonical_fiscal_ready = false`, `FlightPlanService` legado é chamado. O payload legado inclui `vendors` mas com `realized_period = 0` se a API Omie retornou vazio ou cache stale.

**Como verificar:**
```bash
php tools/dev/flight_plan_debug.php --section=realized_vs_target --start=2026-05-01 --end=2026-05-31
```

---

### 3.8 Ritmo Diário sem Série Útil

**Localização:** `api/flight_plan.php` → `daily_sales` array no payload

**Causa potencial:**
1. Canonical `dailyByStatusCommercial()` retornando vazio — sem NFs faturadas por `data_competencia` em maio/2026
2. Legado: `FlightPlanService` gera `daily_sales` via `SalesAnalyticsService` que pode estar vazio

**Como verificar:**
```bash
php tools/dev/flight_plan_debug.php --section=daily_pace --start=2026-05-01 --end=2026-05-31
```

---

## 4. Regras Absolutas da Fase A

Esta fase é **apenas diagnóstica**. Nenhuma das ações abaixo está permitida:

| Proibido | Alternativa |
|----------|------------|
| Alterar API Omie | Verificar sync existente |
| Importar CSV/planilha | — |
| Mudar cron ou sync | — |
| Alterar regras fiscais | — |
| Alterar cálculos comerciais | — |
| Mudanças visuais | — |
| Remover fallbacks | Diagnosticar por que estão ativos |
| Mascarar erros com R$0 | Mostrar estado vazio honesto |
| Dados inventados | — |
| Chamadas Omie em tempo real | — |

---

## 5. Próximos Passos (Fase B)

Após rodar as ferramentas em produção e confirmar as causas raízes:

1. **Pontos de Atenção:** normalizar `alerts` no path legado para sempre emitir objetos com `{level, title, message}`
2. **Finance:** verificar se `api/finance_shadow.php` existe e está retornando dados; caso contrário, integrar `FinanceShadowCandidateBuilder` ao overview
3. **Comparativo Mensal:** confirmar se canonical tem NFs dos meses M-3 a M; se não, verificar sincronismo histórico
4. **Plano de Voo:** se `canonical_fiscal_ready = false`, avaliar ativação controlada; se false-por-ausência-de-sinal, investigar NFs em maio
5. **Metas:** cadastrar metas para 2026-05 via `api/flight_plan_targets_editor.php`

---

## 6. Como Executar em Produção

```bash
# Via SSH na produção (u106688109@82.112.247.199:65002)
cd /home/u106688109/domains/*/public_html

# Diagnóstico completo do overview
php tools/dev/overview_debug.php --start=2026-05-01 --end=2026-05-31 --json 2>&1

# Diagnóstico do plano de voo
php tools/dev/flight_plan_debug.php --start=2026-05-01 --end=2026-05-31 --json 2>&1

# Reconciliação de vendedores
php tools/dev/flight_plan_vendor_reconcile.php --start=2026-05-01 --end=2026-05-31 --json 2>&1

# Probe HTTP (com cookie de sessão logada)
php tools/dev/http_probe.php --endpoint=overview --base-url=https://SEU_DOMINIO --cookie="PHPSESSID=SEU_COOKIE" --json
```

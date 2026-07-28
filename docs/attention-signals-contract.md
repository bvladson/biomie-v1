# Attention Signals — Contrato e Especificação

**Data:** 2026-05-18  
**Versão:** 1.0  
**Fase:** B — Pontos de Atenção Estruturado

---

## Objetivo

Substituir o campo `alerts[]` (strings/objetos legados sem contrato formal) pelo campo `attention_signals[]` — sinais comerciais estruturados, ordenados por severidade, com rastreabilidade de fonte e período.

---

## Contrato JSON (api/overview.php)

```json
{
  "ok": true,
  "data": {
    "attention_signals": [
      {
        "id":           "top_vendor_2026_05",
        "severity":     "success",
        "category":     "destaque",
        "title":        "Maior vendedor do período",
        "value":        "João Silva",
        "amount":       125000.00,
        "amount_label": "Receita",
        "description":  "Lidera o período em faturamento autorizado por data_competência.",
        "metric": {
          "name":      "receita_fiscal",
          "value":     125000.00,
          "formatted": "R$ 125.000,00",
          "unit":      "BRL"
        },
        "context": {
          "vendor_id":       "42",
          "vendor_name":     "João Silva",
          "documents_count": 18
        },
        "action": {
          "label": "Ver vendedor",
          "url":   "vendedores.php?start=2026-05-01&end=2026-05-31&vendor_id=42"
        },
        "source":       "canonical",
        "period_basis": "data_competencia",
        "reason_code":  null
      }
    ],
    "attentionSignals": [],
    "attention_summary": {
      "total":            3,
      "success":          1,
      "info":             0,
      "warning":          1,
      "danger":           1,
      "hidden_count":     0,
      "highest_severity": "danger",
      "source":           "canonical",
      "generated_at":     "2026-05-18T10:00:00+00:00"
    },
    "diagnostics": {
      "attention": {
        "rules_evaluated": 7,
        "rules_failed":    0,
        "rules_skipped":   1,
        "query_count":     1,
        "runtime_ms":      12,
        "errors":          []
      }
    }
  }
}
```

---

## Campos por Sinal

| Campo | Tipo | Obrigatório | Descrição |
|-------|------|-------------|-----------|
| `id` | string | sim | Identificador único, snake_case, inclui período |
| `severity` | enum | sim | `danger`, `warning`, `success`, `info` |
| `category` | enum | sim | `destaque`, `carteira`, `cancelamento`, `meta`, `financeiro`, `operacao`, `dados` |
| `title` | string | sim | Título curto do sinal (máx 60 chars) |
| `value` | string\|null | não | Valor principal legível (nome, percentual, data) |
| `amount` | float\|null | não | Valor monetário em BRL |
| `amount_label` | string\|null | não | Rótulo do amount (ex: "Receita", "Em atraso") |
| `description` | string | sim | Descrição textual completa |
| `metric` | object\|null | não | `{name, value, formatted, unit}` |
| `context` | object | sim | Dados brutos para drill-down |
| `action` | object\|null | não | `{label, url}` — URL sempre relativa, nunca `javascript:` ou externa |
| `source` | string | sim | `canonical`, `gold`, `legacy`, `empty` |
| `period_basis` | string\|null | sim | `data_competencia`, `data_emissao`, `year_month`, `data_vencimento` |
| `reason_code` | string\|null | sim | Código de rastreio quando o sinal é de erro/indisponibilidade |

---

## Severidades

| Valor | Cor | Ordem | Uso |
|-------|-----|-------|-----|
| `danger` | Vermelho (#96140a) | 0 (mais alto) | Crítico — ação imediata necessária |
| `warning` | Âmbar (#d97706) | 1 | Atenção — acompanhar |
| `success` | Verde (#16a34a) | 2 | Positivo — operação saudável |
| `info` | Azul (#3b82f6) | 3 | Informativo — contexto |

Sinais são ordenados `danger → warning → success → info`. Dentro da mesma severidade, maior `amount` aparece primeiro.

---

## Regras Implementadas

| ID | Trigger | Severidade | period_basis |
|----|---------|-----------|--------------|
| `top_vendor_*` | Maior vendedor do período com receita > 0 | `success` | `data_competencia` |
| `wallet_stale_*` | Pedidos autorizados com > 5 dias sem faturar | `danger` (≥R$30k ou ≥10 pedidos) / `warning` | `data_emissao` |
| `cancel_high_*` | Vendedor com taxa de cancelamento ≥ 20% (mín 3 docs) | `danger` (≥30%) / `warning` | `data_competencia` |
| `meta_risco_danger_*` | Projeção do vendedor < 70% da meta | `danger` | `year_month` |
| `meta_risco_warning_*` | Projeção do vendedor 70–95% da meta | `warning` | `year_month` |
| `meta_no_ritmo_*` | Todos os vendedores com projeção ≥ 95% | `success` | `year_month` |
| `finance_overdue_*` | Recebíveis em atraso (requer `finance_summary` no context) | `danger` (≥R$50k ou ≥20% da receita) / `warning` | `data_vencimento` |
| `no_critical_signals_*` | Nenhum `danger` ou `warning` e receita > 0 | `success` | `data_competencia` |

---

## Attention Summary

```json
{
  "total":            6,
  "success":          2,
  "info":             0,
  "warning":          1,
  "danger":           3,
  "hidden_count":     0,
  "highest_severity": "danger",
  "source":           "canonical",
  "generated_at":     "2026-05-18T10:00:00+00:00"
}
```

- `hidden_count`: sinais calculados mas não exibidos (máx 6 visíveis)
- `source`: `canonical` se `gross_revenue > 0`, `empty` caso contrário

---

## Attention Diagnostics

Incluído em `data.diagnostics.attention`:

```json
{
  "rules_evaluated": 7,
  "rules_failed":    0,
  "rules_skipped":   2,
  "query_count":     1,
  "runtime_ms":      14,
  "errors": [
    {
      "rule":       "wallet_stale",
      "message":    "Table not found",
      "class":      "PDOException",
      "request_id": "abc-123"
    }
  ]
}
```

- `rules_skipped`: regra detectou que dados necessários estão ausentes (ex: sem metas)
- `rules_failed`: exceção capturada durante a regra — sinal não gerado, builder continua
- `performance_warning`: campo opcional adicionado se `runtime_ms > 200`

---

## Aliases Aceitos no Frontend

`normalizeOverviewPayload` em `app.js` reconhece:

```javascript
attention_signals: firstArray(payload.attention_signals, payload.attentionSignals, payload.data?.attention_signals)
attention_summary: payload.attention_summary || payload.data?.attention_summary || null
```

---

## Fallback Legado

Se `attention_signals` estiver vazio e o payload contiver `alerts[]`, o frontend converte automaticamente cada alerta legado para o formato de sinal:

- Strings → `{severity: 'info', category: 'operacao', title: <string>}`
- Objeto legado `{level, title, message}` → `{severity: level, category: 'operacao', title, description: message, source: 'legacy'}`

---

## Segurança

- `B.escapeHtml()` aplicado em todos os campos de texto no frontend
- URLs de `action.url` validadas: rejeitam `javascript:`, `data:`, URLs externas (`http://`, `https://`)
- Nenhum HTML cru no payload da API

---

## Estado Vazio

Quando não há sinais ou receita = 0:

```json
[{
  "id":           "attention_unavailable",
  "severity":     "info",
  "category":     "dados",
  "title":        "Sinais indisponíveis",
  "description":  "Não foi possível avaliar os sinais comerciais neste momento.",
  "source":       "empty",
  "reason_code":  "attention_builder_error"
}]
```

Quando operação saudável (sem danger/warning):

```json
[{
  "id":           "no_critical_signals_2026_05",
  "severity":     "success",
  "category":     "operacao",
  "title":        "Operação sem alertas críticos",
  "value":        "Período saudável",
  "description":  "Não foram encontrados sinais críticos nos filtros atuais.",
  "source":       "canonical",
  "period_basis": "data_competencia"
}]
```

---

## Diagnóstico via CLI

```bash
# Seção attention completa
php tools/dev/overview_debug.php --start=2026-05-01 --end=2026-05-31 --section=attention

# JSON para inspecionar payload completo
php tools/dev/overview_debug.php --start=2026-05-01 --end=2026-05-31 --section=attention --json

# Todas as seções incluindo attention
php tools/dev/overview_debug.php --start=2026-05-01 --end=2026-05-31 --json
```

---

## Critérios de Aceitação

1. `attention_signals` presente no payload quando `canonical_fiscal_ready = true`
2. Nenhum sinal tem `severity = null`
3. Ordenação: `danger` antes de `warning` antes de `success` antes de `info`
4. Máximo 6 sinais visíveis; `attention_summary.hidden_count` reflete o restante
5. Falha de uma regra não impede as demais (catch por regra)
6. `attention_diagnostics.errors` lista as regras que falharam com `request_id`
7. Operação sem sinais críticos exibe estado `success` (não fica vazio)
8. URLs de `action.url` são sempre relativas e não contêm `javascript:` ou `data:`
9. Frontend usa `B.escapeHtml()` em todos os campos de texto
10. Mobile: layout não quebra em viewport < 640px

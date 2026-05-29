# FIN.R4.4 — Finance Receivable Identity Resolver + UX Financeira Operacional

**Data:** 2026-05-28
**Fase:** FIN.R4.4 (evolução da FIN.R4.3)
**Status:** Implementado localmente — pendente smoke visual em produção

---

## 1. Causa Raiz

A tela financeiro.php após FIN.R4.3 exibia dados mas a identidade operacional de cada título era fragmentada:

| Problema | Causa | Fase |
|---|---|---|
| Fila de risco sem NF/Pedido | `finance_collection_governance.php` não juntava NF canonical | P4 |
| Vendor "Sem vendedor" sem explicação de por quê | Nenhuma lógica de `gap_reason` | P0/P1 |
| Sem ferramenta de diagnóstico de cobertura de identidade | Ausência de audit tool | P0 |
| `FinanceReceivableIdentityResolver` inexistente | Resolução espalhada em múltiplos lugares | P1 |
| Coluna NF ausente na fila de risco prioritária | thead sem `<th>NF / Pedido</th>` | P4 |

---

## 2. Fases Implementadas

### FASE 0 — Diagnóstico de Identidade (`finance_receivable_identity_audit.php`)
- Ferramenta read-only CLI
- Aceita: `--start`, `--end`, `--pretty`, `--json`, `--sample=N`
- Audita **20 métricas**: total, abertos, vencidos, nota_key direto, chave_nfe direto, bridge, NF total, pedido, vendedor, cliente, sem_nf, sem_pedido, sem_vendedor, sem_cliente, dedup antes/depois, avg score, grade A/B/C/D
- Top 20 causas de gap com contagem
- Exemplos com título_key, nota_key, pedido, cliente, vendedor, gap_reason, score, nf_source
- Usa `FinanceReceivableIdentityResolver` para lógica de resolução

### FASE 1 — `FinanceReceivableIdentityResolver.php`
Classe read-only com **batch loading** para evitar N+1 queries.

**Métodos públicos:**
- `resolveTitle(array $title): array` — resolve identidade de 1 título
- `resolveMany(array $titles): array` — resolve lista completa (lazy init por instância)
- `explainGap(array $resolved): string` — texto explicativo do gap
- `makeIdentityKey(array $row): string` — chave composta nota_key|pedido|vendor|client

**Paths de resolução NF (5 caminhos):**
1. bridge items por titulo_key (maior confidence_score)
2. titulo.nota_key direto → NF canonical
3. titulo.chave_nfe → NF canonical
4. bridge parcial (nota_key sem NF table match)
5. titulo_nota_key parcial

**Paths de resolução Pedido (4 caminhos):**
1. NF canonical.pedido_id_omie
2. titulo.pedido_id_omie direto
3. titulo.codigo_pedido_origem
4. bridge row.pedido_id_omie

**Paths de resolução Vendedor (5 caminhos):**
1. NF canonical.codigo_vendedor_omie
2. titulo direct fields
3. bridge row vendor
4. pedido canônico vendor
5. gap_reason explicativo

**Paths de resolução Cliente (5 caminhos):**
1. titulo direct (nome + dim lookup)
2. NF canonical cliente + dim
3. pedido canônico cliente
4. clientes_dim por codigo
5. clientes_dim por documento

**Saída (trace_quality):**
```json
{
  "score": 75,
  "gap_reason": "Pedido não vinculado; Vendedor não identificado",
  "missing": ["pedido", "vendedor"]
}
```

### FASE 2 — Integração na Governance API
**`api/finance_collection_governance.php`** modificado:
- SELECT expandido: `nota_key AS titulo_nota_key`, `chave_nfe`, `especie_documento`, `forma_pagamento`
- `FinanceReceivableIdentityResolver` inicializado uma vez por request
- Batch resolve todos os títulos em memória antes de construir `$riskQueue`
- Cada item do `$riskQueue` agora inclui:
  - `numero_nf` — número da NF resolvida
  - `nota_key` — chave da NF resolvida
  - `nf_source` — caminho usado para resolver
  - `gap_reason` — motivo quando falta vínculo
  - `trace_quality_score` — 0-100
  - `nome_cliente` — preferindo versão resolvida pelo resolver
  - `nome_vendedor` — preferindo versão resolvida pelo resolver

### FASE 4 — UX Fila de Risco Prioritária
**`views/financeiro.html`:**
- Adicionado `<th>NF / Pedido</th>` ao thead da fila de risco (11 colunas total)

**`assets/js/modules/finance/collection-governance.js`:**
- Função `riskNfCell(row)` adicionada:
  - Mostra `NF <numero_nf>` em badge azul quando resolvida
  - Fallback para `NF …<last-8>` quando só nota_key disponível
  - Mostra `Ped. <id>` em badge azul claro quando pedido disponível
  - Mostra `Sem vínculo` em badge cinza com tooltip do gap_reason quando nada disponível
  - Mostra `—` quando realmente vazio
- Vendedor cell agora mostra `.fin-gap-hint` quando `is_unknown_vendor` e `gap_reason` presente
- Score badge discreto (`Q:XX`) quando `trace_quality_score < 75`
- `colspan` atualizado de 10 → 11

**`assets/css/finance-realizado.css`:**
- Classe `.fin-gap-hint` para hint de gap no vendedor (cor amber)

### FASE 10 — Guards Atualizados
**`tools/dev/biomie_release_guard.php`:**
- D21 adicionado: verifica resolver, audit, governance integration, JS riskNfCell, colspan
- Versão: `AUTO.R1.1+ADMIN.R1.1+FIN.R4.1+FIN.R4.2+FIN.R4.3+FIN.R4.4`

**`tools/dev/visual_layout_guard.php`:**
- +10 novos checks FIN.R4.4 (resolver, audit, governance, HTML thead, JS, CSS)

---

## 3. Arquivos Criados

| Arquivo | Função |
|---|---|
| `app/Support/FinanceReceivableIdentityResolver.php` | FASE 1: resolver de identidade operacional, batch loading |
| `tools/dev/finance_receivable_identity_audit.php` | FASE 0: diagnóstico de cobertura de identidade |
| `docs/FIN_R4_4_RECEIVABLE_IDENTITY_RESOLVER_20260527.md` | Este arquivo |

## 4. Arquivos Modificados

| Arquivo | Mudança |
|---|---|
| `api/finance_collection_governance.php` | FASE 2: SELECT expandido + resolver + enrichment de risk_queue |
| `views/financeiro.html` | FASE 4: thead fila de risco com coluna NF / Pedido |
| `assets/js/modules/finance/collection-governance.js` | FASE 4: riskNfCell(), gap hint, colspan 11, score badge |
| `assets/css/finance-realizado.css` | FASE 4: .fin-gap-hint |
| `tools/dev/biomie_release_guard.php` | FASE 10: D21 + versão FIN.R4.4 |
| `tools/dev/visual_layout_guard.php` | FASE 10: +10 checks FIN.R4.4 |

---

## 5. O Que NÃO Foi Alterado

- Schema/migrations
- Dados raw/bronze
- Regras fiscais/comerciais/financeiras de valor
- Base Comercial NF ou BiVisibilityScope
- Licitação, conciliação ou materialização comercial
- FinanceReceivableTraceBuilder (FIN.R4.3 mantido intacto)

---

## 6. Métricas de Cobertura (contexto produção — via gap report existente)

| Métrica | Antes FIN.R4.4 | Após FIN.R4.4 |
|---|---|---|
| NF encontrada | 99.9% (título.nota_key) | 99.9% (multi-path) |
| Pedido resolvido | 0% (Omie não popula) | Depende do NF canonical |
| Vendedor resolvido | 71% via NF bridge | 71% (mesmo dado, apresentado com gap_reason) |
| Cliente resolvido | 95% (via título) | 95%+ (dim fallback) |
| Coluna NF na fila de risco | ✖ ausente | ✔ presente |
| gap_reason na fila de risco | ✖ ausente | ✔ presente |

---

## 7. Validações

```bash
php -l app/Support/FinanceReceivableIdentityResolver.php
php -l tools/dev/finance_receivable_identity_audit.php
php -l api/finance_collection_governance.php
php -l tools/dev/biomie_release_guard.php
php -l tools/dev/visual_layout_guard.php

php tools/dev/visual_layout_guard.php
php tools/dev/biomie_release_guard.php --start=2026-05-01 --end=2026-05-31 --pretty
php tools/dev/finance_receivable_identity_audit.php --start=2026-05-01 --end=2026-05-31 --pretty --sample=10
php healthz.php
```

---

## 8. Pendências FIN.R4.4

| Item | Prioridade | Fase |
|---|---|---|
| Smoke visual em produção: fila de risco com NF, gap_reason, score | Alta | após deploy |
| Integrar resolver em `finance_collection_by_vendor.php` (títulos acionáveis) | Média | FIN.R4.4.1 |
| Integrar resolver em `finance_receivable_trace.php` (drawer identity block) | Média | FIN.R4.4.2 |
| FASE 3: Tabs/chips na seção de rastreabilidade | Média | FIN.R4.5 |
| FASE 5: Aging buckets com mais detalhe (NFs, clientes, vendedores por faixa) | Baixa | FIN.R4.5 |
| FASE 8: Reorganizar página em 3 blocos (executivo, operação, legado recolhido) | Baixa | FIN.R4.5 |
| `FinanceTraceIdentityResolver` → `FinanceReceivableIdentityResolver` unificação | Baixa | cleanup |

---

## 9. Smoke Visual Pós-Deploy

Verificar em `financeiro.php?start=2026-05-01&end=2026-05-31`:

1. Fila de risco prioritária mostra coluna "NF / Pedido"
2. Onde NF existe: badge `NF 00001380`
3. Onde NF indisponível: badge `Sem vínculo` com tooltip explicativo
4. Vendedor "Sem vendedor definido" mostra hint de gap_reason
5. Score badge `Q:50` aparece em linhas com trace_quality < 75
6. Coluna existente "NF / Pedido" nos Títulos acionáveis continua funcionando
7. release_guard PASS, healthz ok:true

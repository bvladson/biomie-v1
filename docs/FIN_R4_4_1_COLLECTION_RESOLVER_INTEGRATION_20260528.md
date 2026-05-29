# FIN.R4.4.1 — Resolver Operacional da Carteira + Reconstrução de Vínculo NF × Título

**Data:** 2026-05-28  
**Guards:** D22 PASS, visual_layout_guard 89/89 PASS  
**Domínio:** Financeiro — Cobrança por Vendedor

---

## Problema raiz diagnosticado

`biomie_titulos_financeiros_canonicos.nota_key` armazena a **chave_nfe** (44 chars), enquanto `biomie_notas_fiscais_canonicas.nota_key` usa formato `"omie:XXXXXXXX"` — os dois campos são incompatíveis para join direto.

O resolver tentava `nfByNotaKey[chave_nfe]` (Path 2 original), que nunca retornava resultado. A cobertura real via `t.nota_key = nf.chave_nfe` é **62.6%** (730 títulos/323 NFs em maio/2026).

---

## O que foi feito

### FASE 0 — Diagnóstico de gaps
- **`tools/dev/finance_receivable_identity_gap_drilldown.php`** (novo): diagnóstico em 4 seções:
  - **A** — compatibilidade de chaves (A1–A9): confirma nota_key ≠ nf.nota_key (0 matches), nota_key = nf.chave_nfe (730 matches)
  - **B** — NF → Pedido via chave_nfe
  - **C** — NF/Pedido → Vendedor
  - **D** — bridge NF×Título: estado atual + oportunidades

### FASE 1 — Resolver corrigido (6-path NF resolution)
- **`app/Support/FinanceReceivableIdentityResolver.php`** (modificado):
  - Adicionado `$nfByNumeroNf[]` — índice por numero_nf
  - **Path 2 corrigido**: `strlen(nota_key) === 44 → nfByChaveNfe[nota_key]` (source: `title_nota_key_as_chave_nfe`)
  - **Path 5** (novo): `strlen(documento_origem_ref) === 44 → nfByChaveNfe[doc_origem_ref]` (source: `documento_origem_ref`)
  - **Path 6** (novo): `numero_documento → nfByNumeroNf[numero_documento]` (source: `numero_documento_nf`)

### FASE 2 — Bridge audit (dry-run)
- **`tools/dev/finance_nf_title_bridge_rebuild_audit.php`** (novo): simula reconstrução do bridge sem alterar dados, mostra oportunidades de vínculo e impacto potencial

### FASE 3 — Integração em finance_collection_by_vendor.php
- **`api/finance_collection_by_vendor.php`** (modificado): resolver integrado em batch por página
- Novos campos por título: `nf_label`, `nf_chave_nfe`, `nf_nota_key`, `nf_source`, `pedido_label`, `payment_method_label`, `trace_quality_score`, `gap_reason`, `missing_fields`, `vendedor_nome_resolvido`, `cliente_nome_resolvido`, `vendedor_source`
- Novo bloco de resposta: `unresolved_vendor_bucket` (count, top_gap_reasons) quando há títulos sem vendedor na página

### FASE 4 — Enriquecimento do governance
- **`api/finance_collection_governance.php`** (modificado): risk_queue agora inclui `nf_label`, `nf_chave_nfe`, `pedido_label`, `pedido_id_omie`, `payment_method_label`, `missing_fields`

### FASE 5 — Visual compacto (títulos acionáveis)
- **`views/financeiro.html`**: coluna "Vendedor" fundida na célula "Cliente / Vendedor"; div `fcUnresolvedVendorAlert` adicionada acima da tabela
- **`assets/js/modules/finance/collection-by-vendor.js`**:
  - `renderOriginRef()`: prioriza `nf_label`/`pedido_label` do resolver
  - `renderUnresolvedVendorAlert(bucket)`: alerta amarelo com count e motivos de gap
  - `renderRows()`: Vendedor em chip azul/laranja na célula do Cliente; método em badge cinza na célula do Título; trace quality badge `Q:N` se < 75
  - `renderRanking()`: sem_vendedor mostra "Títulos sem vínculo resolvido" em laranja
- **`assets/js/modules/finance/collection-governance.js`**:
  - `riskNfCell()`: prioriza `nf_label`/`pedido_label`; fallback para numero_nf/nota_key

### FASE 6 — Drawer identity block
- **`views/financeiro.html`**: substituído info-grid por `fcDrawerIdentity` com campos expandidos; adicionado `fcInfoNfPedido`, `fcInfoTraceQuality`, `fcDiagnosticContent`
- **`assets/js/modules/finance/collection-by-vendor.js`**: `openDrawer()` popula chips de NF/Pedido/Método, alerta de qualidade se score < 75, seção de diagnóstico colapsível com trace path

### FASE 7 — Guards
- **D22** adicionado ao `biomie_release_guard.php`: 14 verificações FIN.R4.4.1
- **16 checks FIN.R4.4.1** adicionados ao `visual_layout_guard.php`
- Versão atualizada para `AUTO.R1.1+ADMIN.R1.1+FIN.R4.1+FIN.R4.2+FIN.R4.3+FIN.R4.4+FIN.R4.4.1`

---

## Resultado dos guards locais

```
biomie_release_guard.php:  21/21 PASS (D22 PASS)
visual_layout_guard.php:   89/89 PASS
php -l:                    7/7 PASS
node --check:              2/2 PASS
```

---

## Regras absolutas respeitadas

- Sem migrations / schema changes
- Sem alteração em dados raw/bronze
- Sem alteração em regras fiscais/comerciais/financeiras
- Sem alteração em BiVisibilityScope / Licitação / conciliação
- Read-only (exceto ações de cobrança via AdminLogService)
- PHP 8.1+ compatível, prepared statements

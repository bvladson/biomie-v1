# FIN.R4.1 — Rastreabilidade NF × Boleto × Vendedor × Métodos de Pagamento

**Data:** 2026-05-27  
**Fase:** FIN.R4.1 (evolução da FIN.R4)  
**Status:** Implementado — pendente smoke visual em produção

---

## 1. Problema Observado

A tela financeira (FIN.R4) ficou visual, mas não respondia operacionalmente às perguntas de cobrança:

1. Quais notas fiscais têm boletos em aberto?
2. Quais boletos/títulos pertencem a cada NF?
3. Qual cliente está devendo?
4. Qual vendedor é responsável pela cobrança?
5. Qual o ranking Top 10 de métodos de pagamento?
6. Quais boletos estão sem NF vinculada?
7. Quais NFs têm título aberto, vencido ou a vencer?

---

## 2. Perguntas de Negócio Respondidas

| Pergunta | Onde encontrar |
|---|---|
| Quais NFs têm boleto em aberto? | Seção "NFs com boletos em aberto" — tabela compacta |
| Quais boletos pertencem à NF X? | Drawer da NF → lista de títulos |
| Qual cliente está devendo? | Drawer da NF → campo Cliente |
| Qual vendedor é responsável? | Drawer da NF → campo Vendedor |
| Top 10 métodos de pagamento? | Gráfico "Top 10 métodos de pagamento" com toggle valor/qtd |
| Quais boletos estão sem NF? | KPI "Sem NF vinculada" → drawer de cobertura |
| Quem cobrar hoje? | Coluna "Ação" na tabela e drawer → "Cobrar hoje" |

---

## 3. Fontes de Dados

| Tabela | Uso |
|---|---|
| `biomie_titulos_financeiros_canonicos` | Fonte primária de títulos/boletos |
| `biomie_bridge_nf_titulo_items` | Vínculo NF × título (prioridade 1) |
| `biomie_notas_fiscais_canonicas` | Dados da NF: número, data, cliente, vendedor |
| `biomie_bridge_nf_titulo_summary` | Resumo agregado por NF |
| `mart_bridge_pedido_nfe_titulo` | Fallback de vínculo (prioridade 3) |
| `biomie_admin_logs` | Histórico de ações de cobrança |

---

## 4. Regras de Vínculo NF × Título

Prioridade aplicada em `resolveNfKey()`:

1. `bridge_nota_key` — via `biomie_bridge_nf_titulo_items.nota_key` (JOIN por `titulo_key`)
2. `titulo_nota_key` — campo `nota_key` diretamente no registro do título
3. `mart_nota_key` — via `mart_bridge_pedido_nfe_titulo.nota_key`
4. String vazia → classificado como `sem_nf_vinculada`

**Importante:** não são criados vínculos fuzzy novos. Apenas leitura e classificação.

---

## 5. Regras de Identificação de Boleto

Implementado em `normalizePaymentMethod()`:

- Detectado por keywords em `forma_pagamento` + `especie_documento`:
  - `boleto`, `bol `, `cobrança`, `cobranca`, `duplicata`, `borderô`, `bordero`
- O campo `is_boleto` é `true` quando o método normalizado é `boleto`

---

## 6. Normalização de Método de Pagamento

Método `normalizePaymentMethod(array $title): string`

| Retorno | Keywords detectadas |
|---|---|
| `boleto` | boleto, bol, cobrança, duplicata, borderô |
| `pix` | pix |
| `cartao_credito` | cred, crédito, mastercard, visa, elo, amex |
| `cartao_debito` | débito, debito, debit |
| `dinheiro` | dinheiro, espécie, cash |
| `transferencia` | transfer, ted, doc, tev, wire |
| `cheque` | cheque, chq |
| `deposito` | depós, depos, deposit |
| `nao_informado` | ambos campos vazios |
| `outro` | qualquer outro valor |

Preservados: `raw_payment_method`, `raw_document_species`, `raw_account`.

---

## 7. Novos Gráficos

| ID | Tipo | Descrição |
|---|---|---|
| `financeTraceNfChart` | Barra horizontal stacked | Top 10 NFs com boletos em aberto (vencido + a vencer) |
| `financeTraceMethodChart` | Barra horizontal | Top 10 métodos — toggle: Valor total / Aberto / Qtd |
| `financeTraceVendorChart` | Barra horizontal stacked | Boletos por vendedor (aberto + vencido) |
| `financeTraceCoverageChart` | Donut | Cobertura NF × título (com NF / sem NF / sem vendedor) |
| `financeTraceAgingChart` | Barra horizontal | Aging apenas para boletos em aberto |

---

## 8. Novos Drawers

| Tipo | Abertura | Conteúdo |
|---|---|---|
| `nf` | Clique na NF no gráfico ou tabela | Resumo da NF + lista de títulos com NF, cliente, vendedor, vencimento, aberto, método, ação |
| `method` | Clique no método no gráfico | Resumo do método + títulos filtrados |
| `vendor` | Clique no vendedor no gráfico | Boletos do vendedor + clientes |
| `coverage` | Clique no donut de cobertura | Lista de títulos da fatia (sem NF / sem vendedor / com NF) |
| `aging` | Clique na faixa de aging | Boletos da faixa |

Regra: todo drawer exibe NF (ou "—" se ausente), título, cliente, vendedor, vencimento, atraso, valor aberto, método e ação recomendada.

---

## 9. O Que Não Foi Alterado

- Regras financeiras de valor e status
- Sync, cron, worker, materialização
- Base Comercial NF
- BiVisibilityScope
- Schema/migrations
- Bridge NF × título (leitura apenas)
- Licitação excluída (mantida)
- Tabelas antigas (preservadas em `details` recolhível)
- FIN.R4 gráficos originais

---

## 10. Validações

```bash
php -l api/finance_receivable_trace.php
php -l app/Support/FinanceReceivableTraceBuilder.php
php -l tools/dev/finance_receivable_trace_audit.php

php tools/dev/finance_receivable_trace_audit.php --start=2026-05-01 --end=2026-05-31 --pretty
php tools/dev/finance_visual_analytics_audit.php --start=2026-05-01 --end=2026-05-31 --pretty
php tools/dev/visual_layout_guard.php --pretty
php tools/dev/biomie_release_guard.php --start=2026-05-01 --end=2026-05-31 --pretty
php healthz.php
vendor/bin/phpstan analyse app tools/dev bin --level=0 --memory-limit=1G
```

---

## 11. Smoke Visual

URL: `financeiro.php?start=2026-05-01&end=2026-05-31`

Checklist:
- [ ] Aparece bloco "Rastreabilidade de Recebíveis"
- [ ] Aparecem 6 KPIs de rastreabilidade
- [ ] Aparecem 5 gráficos de rastreabilidade
- [ ] Boleto aparece como método se houver boleto no período
- [ ] Clicar em NF no gráfico abre drawer da NF com títulos
- [ ] Drawer mostra cliente, vendedor, vencimento, valor aberto
- [ ] Tabela "NFs com boletos em aberto" mostra Top 10
- [ ] Toggle "Valor total / Aberto / Qtd" funciona no gráfico de métodos
- [ ] Títulos sem NF aparecem como "Sem NF vinculada"
- [ ] Títulos sem vendedor aparecem como "Sem vendedor definido"
- [ ] Gráficos FIN.R4 originais continuam funcionando
- [ ] Tabelas antigas continuam acessíveis em `details`
- [ ] Mobile sem overflow horizontal crítico
- [ ] `release_guard` PASS (D1-D18)
- [ ] `visual_layout_guard` PASS
- [ ] `healthz` ok=true
- [ ] PHPStan OK

---

## 12. Pendências Conhecidas

| Item | Prioridade | Nota |
|---|---|---|
| Ação "Cobrar hoje" via drawer com registro de ação | Alta | Integrar com `finance_collection_governance` existente |
| Exportar CSV de NFs com boleto aberto | Média | Endpoint já suporta `mode=titles` |
| Ranking de métodos por NFs (não só valor) | Baixa | Toggle já parcialmente implementado |
| Detalhamento de boleto individual com código de lançamento Omie | Média | Depende de sincronização de dados Omie |

---

## Arquivos Criados/Modificados

| Arquivo | Tipo |
|---|---|
| `app/Support/FinanceReceivableTraceBuilder.php` | Novo |
| `api/finance_receivable_trace.php` | Novo |
| `tools/dev/finance_receivable_trace_audit.php` | Novo |
| `views/financeiro.html` | Modificado — nova seção + drawer |
| `assets/js/modules/finance/visual-analytics.js` | Modificado — IIFE de rastreabilidade |
| `assets/css/finance-realizado.css` | Modificado — CSS trace |
| `tools/dev/biomie_release_guard.php` | Modificado — D18 adicionado |
| `tools/dev/visual_layout_guard.php` | Modificado — 14 checks FIN.R4.1 |

# FIN.R4.2 — Enriquecimento de Métodos Financeiros e Detecção Real de Boletos

**Data:** 2026-05-27
**Fase:** FIN.R4.2 (evolução da FIN.R4.1)
**Status:** Implementado — pendente smoke em produção após próximo sync financeiro

---

## 1. Diagnóstico Confirmado

`finance_payment_method_payload_audit.php` executado em produção:

| Métrica | Valor |
|---|---|
| Títulos no período 2026-05 | 697 |
| `forma_pagamento` preenchido | 0 (0%) |
| `especie_documento` preenchido | 0 (0%) |
| Registros bronze `PesquisarLancamentos` | 31.995 |
| `cFormaPag` em payloads bronze | 0 amostras |
| `cTipoTitulo` em payloads bronze | 0 amostras |

**Causa raiz:** O endpoint `PesquisarLancamentos` (`/financas/pesquisartitulos/`) não retorna os campos `cFormaPag`, `cTipoTitulo`, `cEspecie` ou `cMeioPagamento`. Os 697 títulos com `nao_informado` são o estado real — o Omie simplesmente não envia esses campos nesse endpoint.

Também verificado:
- `ListarMovimentos` (`/financas/mf/`) — 36.806 registros bronze — também sem campos de pagamento.
- O mapper `normalizeTitle()` usa `findFirstByKeys()` (busca recursiva) — o mapper está correto.
- A tabela `biomie_titulos_financeiros_canonicos` já possui `forma_pagamento` e `especie_documento` — **não é necessária migration**.

---

## 2. Estratégia de Enriquecimento (Dois Níveis)

### Nível 1 — Sync futuro via `ListarContasReceber`

O endpoint `/financas/contasreceber/` com `ListarContasReceber` retorna o objeto `cabecTitulo` com:
- `cFormaPag` — forma de pagamento (texto livre Omie)
- `cTipoTitulo` — tipo do título (BOL, DUP, CHQ, etc.)
- `cEspecie` — espécie do documento

**Implementação:** `FinanceSyncCoordinator.syncMonth()` chama `listReceivablesByDueDate()` após `buildCanonicalTitles()` como passo de enriquecimento não-bloqueante.

### Nível 2 — Dados existentes via bronze `ListarContasCorrentes`

A tabela `biomie_omie_bronze` contém 140+ registros de `ListarContasCorrentes` com os campos:
- `bol_sn` = `'S'` → conta configurada para boleto bancário (campo oficial Omie)
- `pix_sn` = `'S'` → conta configurada para PIX (campo oficial Omie)

**Implementação:** `FinanceReceivableTraceBuilder.detectMethodFromAccount()` carrega o lookup do bronze e usa `bol_sn`/`pix_sn` por `nCodCC` para detectar o método de pagamento de títulos cujo `forma_pagamento` está vazio.

> **Nota:** `bol_sn` indica que a *conta* está configurada para boleto, não que uma *transação específica* é boleto. É uma heurística oficial Omie, usada apenas como fallback quando não há campo mais preciso.

---

## 3. Regras de Detecção (Ordem de Prioridade)

Implementado em `FinanceReceivableTraceBuilder.normalizePaymentMethod()`:

| Prioridade | Fonte | Critério | Exemplo |
|---|---|---|---|
| 1 | `especie_documento` (após enriquecimento) | In `['bol','dup','dmi','dme']` | `especie='BOL'` → boleto |
| 2 | `forma_pagamento` + `especie_documento` | Keyword search (METHOD_MAP) | `'boleto bancário'` → boleto |
| 3 | `codigo_conta_corrente_omie` + bronze | `bol_sn='S'` | conta boleto → boleto |
| 3 | `codigo_conta_corrente_omie` + bronze | `pix_sn='S'` | conta PIX → pix |
| — | nenhuma fonte | vazio | `nao_informado` |

---

## 4. Arquivos Modificados

| Arquivo | Mudança |
|---|---|
| `app/FinanceRepository.php` | + `listReceivablesByDueDate()` — endpoint ListarContasReceber |
| `app/Sync/FinanceSyncCoordinator.php` | + `enrichPaymentMethods()` chamado em `syncMonth()` |
| `app/Support/FinanceReceivableTraceBuilder.php` | + `BOLETO_ESPECIES`, `detectMethodFromAccount()`, `loadAccountLookup()` |
| `tools/dev/finance_receivable_trace_audit.php` | + seção FIN.R4.2 com cobertura de método |
| `tools/dev/biomie_release_guard.php` | + D19 informacional; versão `FIN.R4.2` |

## 5. Arquivos Criados

| Arquivo | Função |
|---|---|
| `tools/dev/finance_payment_method_payload_audit.php` | Diagnóstico: bronze + canonical + schema + endpoint coverage |

---

## 6. O Que Não Foi Alterado

- Regras financeiras de valor, status, data
- Sync, cron, worker, materialização de marts
- Base Comercial NF
- BiVisibilityScope
- Schema/migrations (não necessário)
- Bridge NF × título
- Licitação excluída

---

## 7. Validações

```bash
php -l app/FinanceRepository.php
php -l app/Sync/FinanceSyncCoordinator.php
php -l app/Support/FinanceReceivableTraceBuilder.php
php -l tools/dev/finance_payment_method_payload_audit.php
php -l tools/dev/finance_receivable_trace_audit.php
php -l tools/dev/biomie_release_guard.php

php tools/dev/finance_payment_method_payload_audit.php --start=2026-05-01 --end=2026-05-31 --pretty
php tools/dev/finance_receivable_trace_audit.php --start=2026-05-01 --end=2026-05-31 --pretty
php tools/dev/biomie_release_guard.php --start=2026-05-01 --end=2026-05-31 --pretty
php healthz.php
```

---

## 8. Quando os Métodos Aparecem

O enriquecimento via `ListarContasReceber` só tem efeito na **próxima sincronização financeira** do período (cron noturno ou sync manual). Para reprocessar:

```bash
php bin/sync_finance.php --start=2026-05-01 --end=2026-05-31
```

O enriquecimento via `bol_sn`/`pix_sn` (nível 2) é imediato — não exige resync, pois o `FinanceReceivableTraceBuilder` lê o bronze na hora da query.

---

## 9. Pendências

| Item | Prioridade |
|---|---|
| Smoke após próximo sync financeiro: confirmar `forma_pagamento` populado via `ListarContasReceber` | Alta |
| Verificar se `ListarContasReceber` suporta filtro `dDtVencDe`/`dDtVencAte` no ambiente de produção | Alta |
| Se o endpoint falhar ou retornar vazio: investigar parâmetros alternativos (`filtrar_por_data_de`) | Média |

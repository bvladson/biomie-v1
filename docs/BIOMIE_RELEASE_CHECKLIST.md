# BIomie Comercial — Release Checklist

> Versão: Q.11 | Atualizado: 2026-05-23
> Substitui a sequência manual por um único comando validado.

---

## Comando único

```bash
php tools/dev/biomie_release_guard.php --start=2026-01-01 --end=2026-05-31 --pretty
```

Ou via Composer:

```bash
composer guard
```

**Exit code 0 = seguro para deploy. Exit code 1 = investigar antes de prosseguir.**

---

## Domínios verificados

| ID | Domínio | Guard | Critério de PASS |
|----|---------|-------|-----------------|
| D1 | Fase O — Base Comercial NF | `fiscal_value_basis_guard.php` | 10/10 |
| D2 | Fase L — Licitação excluída | `fase_l_qa.php` | 11/11 |
| D3 | Fase Q — Conciliação NF × Título | `reconciliation_guard.php` | 30/30 |
| D4 | Marts — dados materializados | DB COUNT(*) 7 tabelas | 7/7 com linhas |
| D5 | APIs — sintaxe PHP | `php -l api/*.php` | 0 erros |
| D6 | SELECT * em APIs | `grep` | WARN — informacional |

Último resultado validado: **6/6 PASS em 5.56s** (2026-05-23).

---

## Interpretando os resultados

### PASS (verde `✔`)
Todos os checks do domínio passaram. Seguro para prosseguir.

### WARN (amarelo) — D6 apenas
Informacional, não bloqueia. D6 lista `SELECT *` conhecidos:
- `reconciliation_search.php:309` — subquery wrapper, aceitável
- `geo_trace_nf.php:147` — endpoint de diagnóstico
- `invoice_detail.php` — 3 ocorrências, candidato a refatoração futura

Não bloquear releases por D6. Registrar como dívida técnica e priorizar ao editar esses arquivos.

### FAIL (vermelho `✗`)
Exit code 1. Não fazer deploy. Ver "O que fazer se..." abaixo.

---

## Sequência pré-deploy

```bash
# 1. Rodar guard master (localmente ou em produção)
php tools/dev/biomie_release_guard.php --start=2026-01-01 --end=2026-05-31 --pretty

# 2. Confirmar 6/6 PASS (D6 WARN é esperado)

# 3. Deploy dos arquivos
#    (git pull, rsync ou pscp individual)

# 4. Limpar cache após deploy
php tools/dev/biomie_release_guard.php --start=2026-01-01 --end=2026-05-31 --pretty --clear-cache

# 5. Confirmar guard verde em produção
```

---

## O que fazer se...

### D1 (Base Comercial NF) falhar

```bash
php tools/dev/fiscal_value_basis_guard.php --pretty
```

- Verificar se a coluna `valor_base_comercial_nf` foi removida/renomeada em `biomie_notas_fiscais_canonicas`
- Verificar se `frete`, `outras_despesas`, `seguro` ainda existem na tabela
- **Nunca alterar a fórmula Base Comercial NF** — ver `docs/BIOMIE_COMMERCIAL_SCOPE.md`

### D2 (Licitação excluída) falhar

```bash
php tools/dev/fase_l_qa.php --start=2026-01-01 --end=2026-05-31 --pretty
```

- Identificar qual check AC-XX falhou no output
- Garantir que novos endpoints comerciais usam `BiVisibilityScope::getCondition()`
- Verificar se `biomie_visibility_overrides` está íntegra

### D3 (Conciliação) falhar

```bash
php tools/dev/reconciliation_guard.php --start=2026-01-01 --end=2026-05-31 --pretty
```

- Identificar qual check C-XX falhou
- **Nunca executar `bridge_nf_titulo_build.php --apply`** sem dry-run antes
- Verificar se a bridge foi alterada manualmente

### D4 (Marts) falhar — tabela ausente ou vazia

Tabelas esperadas em produção (todas sem prefixo `biomie_`):

| Tabela | Rows mínimo esperado |
|--------|---------------------|
| `mart_vendedor_dia` | > 0 |
| `mart_cliente_periodo` | > 0 |
| `mart_vendas_por_cnae_cnpj` | > 0 |
| `mart_compradores_por_geo` | > 0 |
| `mart_produto_periodo` | > 0 |
| `biomie_bridge_nf_titulo_items` | > 0 |
| `biomie_bridge_nf_titulo_summary` | > 0 |

Se tabela vazia: rematerializar via painel admin ou via script específico do mart.
Se tabela ausente: verificar migrações pendentes.

### D5 (Sintaxe PHP) falhar

```bash
# Verificar arquivo específico
php -l api/arquivo_com_erro.php
```

Sempre executar `php -l` localmente antes de qualquer deploy de arquivo PHP.

---

## Quando limpar cache

Limpar cache **após** deploy, nunca antes.

```bash
php tools/dev/biomie_release_guard.php --pretty --clear-cache
```

- Cache: `storage/cache/*.json` — file-based, sem `flush()` programático
- **Não limpar em horário de pico** (9h–18h) sem necessidade
- Após limpeza, os primeiros requests recalculam e regravam automaticamente

---

## Quando rematerializar marts

Rematerializar quando:
- Sync completo do Omie (novas NFs importadas)
- Mudança de `operation_scope` em NFs existentes
- Atualização de `biomie_visibility_overrides` (incluir/excluir licitação)
- D4 reportar tabela vazia após deploy

**Validar com guard antes e depois de qualquer rematerialização.**

---

## Flags do guard master

| Flag | Descrição | Default |
|------|-----------|---------|
| `--start=YYYY-MM-DD` | Início do período de validação | `date('Y-01-01')` |
| `--end=YYYY-MM-DD` | Fim do período | `date('Y-m-d')` |
| `--pretty` | Saída colorida e legível | — |
| `--json` | Saída JSON para CI | — |
| `--clear-cache` | Limpa `storage/cache/*.json` ao final | desativado |

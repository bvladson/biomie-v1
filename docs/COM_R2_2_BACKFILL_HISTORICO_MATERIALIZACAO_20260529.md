# COM.R2.2 — Pós-Backfill: Materialização e Fechamento
**Data:** 2026-05-29  
**Contexto:** Backfill histórico Jan/2026 e Mar/2026 executado com sucesso.  
**Objetivo:** Rematerializar marts Gold, recalcular bridge NF×Título, fechar D2 e D3.

---

## Estado antes desta fase

| Mês      | NFs Canônicas | Base Comercial | Clientes | Vendedores |
|----------|--------------|----------------|----------|------------|
| Jan/2026 | 306          | ~R$ 960k       | 281      | 14         |
| Fev/2026 | 202          | ~R$ 955k       | 187      | 10         |
| Mar/2026 | 312          | ~R$ 926k       | 266      | 13         |
| Abr/2026 | 84           | ~R$ 726k       | 76       | 11         |
| Mai/2026 | 262          | ~R$ 491k       | 226      | 9          |
| **Total**| **1.166**    | **~R$ 4,06M**  | —        | —          |

**Release Guard:** 29/31 PASS  
**Falhas:** D2 (mart_vendedor_dia + mart_compradores_por_geo com licitação stale) e D3 (bridge summary desatualizada pós-backfill de 618 NFs novas).

---

## Por que D2 e D3 falharam

### D2 — Licitação em marts antigos
- `backfill_visibility_scope.php --apply` corrigiu 11 NFs canônicas (visible=0)
- Mas `mart_vendedor_dia` e `mart_compradores_por_geo` **não foram rematerializados** depois disso
- Solução: rematerializar — os builders `VendorDayMartBuilder` e `GeoBuyerMartBuilder` já aplicam `visibilityFragment` (Fase L) corretamente

### D3 — Bridge summary desatualizada
- `NfTituloBridgeSummaryBuilder.refresh()` recalcula `biomie_bridge_nf_titulo_summary` a partir de `biomie_bridge_nf_titulo_items`
- Com 618 NFs novas (Jan/Mar), o summary ficou desatualizado
- Solução primária: reexecutar materialização (`materialize_marts.php --force`) que chama `bridgeSummary()->refresh()` automaticamente
- Se `biomie_bridge_nf_titulo_items` também estiver incompleto: usar `finance_nf_title_bridge_rebuild_audit.php --apply`

---

## Sequência aprovada de execução no servidor

```bash
cd /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie
```

### Passo 0 — Segurança operacional (verificar workers/locks antes do --force)

```bash
php tools/dev/automation_runtime_diagnosis.php --pretty || true
php tools/dev/job_failure_report.php --pretty || true
```

Só continuar se não houver worker de sync/backfill rodando no mesmo momento.

### Passo 1 — Diagnóstico antes da materialização

```bash
php tools/dev/nf_title_bridge_coverage_audit.php \
  --start=2026-01-01 --end=2026-05-29 --pretty
```

Salvar output para comparação posterior.

### Passo 2 — Rematerializar marts Gold + bridge summary

> `--force` ignora o check de lag canônico. Seguro: apenas regrava marts a partir do canônico existente.

```bash
php bin/materialize_marts.php \
  --start=2026-01-01 --end=2026-05-29 --force
```

**O que isso faz automaticamente:**
- `mart_vendedor_dia` → D2 fix (licitação excluída via `visibilityFragment`)
- `mart_compradores_por_geo` → D2 fix
- `mart_cliente_periodo` + `mart_produto_periodo` → Jan/Mar populados
- `biomie_bridge_nf_titulo_summary` → D3 fix via `NfTituloBridgeSummaryBuilder.refresh()`
- `mart_bridge_pedido_nfe_titulo` → bridge pedido-NF-título

### Passo 3 — Validar Licitação nos marts (D2)

```bash
php tools/dev/fase_l_qa.php \
  --start=2026-01-01 --end=2026-05-29 --pretty
```

Critério: AC-07 (mart_compradores_por_geo) e AC-09 (mart_vendedor_dia) PASS.

### Passo 4 — Validar bridge NF×Título depois da materialização (D3)

```bash
php tools/dev/nf_title_bridge_coverage_audit.php \
  --start=2026-01-01 --end=2026-05-29 --pretty
```

### Passo 5 — Release Guard anual

```bash
php tools/dev/biomie_release_guard.php \
  --start=2026-01-01 --end=2026-05-29 --pretty
```

Critério mínimo para deploy: 31/31 PASS.

---

## Se D2 ainda falhar após materialização

Não é mais problema canônico. Diagnosticar qual builder ainda lê sem filtro:

```bash
php tools/dev/fase_l_qa.php --start=2026-01-01 --end=2026-05-29 --pretty
php tools/dev/licitacao_visibility_guard.php --start=2026-01-01 --end=2026-05-29 --pretty
```

Se apontar `mart_vendedor_dia` ou `mart_compradores_por_geo`, revisar apenas `VendorDayMartBuilder` e `GeoBuyerMartBuilder`. Sem mexer em regra de licitação, sem alterar canônico, sem novo backfill.

---

## Se D3 ainda falhar após materialização

O summary foi recalculado mas `biomie_bridge_nf_titulo_items` está incompleto. Executar rebuild da bridge:

```bash
# Diagnóstico (dry-run, sempre seguro)
php tools/dev/finance_nf_title_bridge_rebuild_audit.php \
  --start=2026-01-01 --end=2026-05-29 --dry-run --pretty

# Aplicar somente se dry-run mostrar matches confiáveis (chave_nfe, nota_key, pedido_id)
php tools/dev/finance_nf_title_bridge_rebuild_audit.php \
  --start=2026-01-01 --end=2026-05-29 --apply --pretty

# Rematerializar summary depois do rebuild de items
php bin/materialize_marts.php \
  --start=2026-01-01 --end=2026-05-29 --force

# Rerodar guard
php tools/dev/biomie_release_guard.php \
  --start=2026-01-01 --end=2026-05-29 --pretty
```

Se D3 ainda divergir (C06 ou C15 falhando), verificar se são NFs com múltiplos títulos parciais (parcelamento): a soma dos títulos difere do valor unitário da NF nesse caso, e o check C16 já trata via `confidence_score`. Documentar como WARN informacional se comprovado que é dado real.

---

## Critérios de aceite para fechamento de COM.R2.2

| Critério                                      | Alvo      |
|----------------------------------------------|-----------|
| D2 Fase L — Licitação excluída               | PASS      |
| D3 Fase Q — Conciliação NF×Título            | PASS (ou WARN documentado se dado real) |
| D30 COM.R2.2                                  | PASS      |
| VLG                                           | PASS      |
| healthz                                       | ok=true   |
| Janeiro no dashboard (NFs e gráfico)         | Visível   |
| Março no dashboard (NFs e gráfico)           | Visível   |
| summary-wallet e summary-quotes populados    | Visível   |
| Product 360 abre para top-3 produtos         | Sem erro  |
| Carteira aceita e Orçamentos preenchidos     | Visível   |
| Licitação não contamina Orç-PD              | Confirmado|

---

## Observações de segurança

- Não usar `--apply` em ferramentas de backfill canônico agora
- `materialize_marts.php --force` é seguro: apenas regrava marts derivados, não altera canônico
- `finance_nf_title_bridge_rebuild_audit.php --apply` delega ao `bridge_nf_titulo_build.php` — preserva `is_manual_override = 1`
- Logs em `storage/logs/materialize_marts.log`
- Após fechamento desta fase: rotacionar senha/chave SSH e MySQL (credenciais apareceram em logs da sessão anterior)

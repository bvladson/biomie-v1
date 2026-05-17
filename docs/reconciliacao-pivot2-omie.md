# Reconciliação Pivot2 × Omie Canônico

## O que é o Pivot2?

O Pivot2 é o relatório exportado diretamente do Omie na aba **"Faturamento por Período"**
(filtro Situação = Autorizado). Ele consolida uma linha por NF emitida, com os totais de
mercadoria, desconto, IPI, ICMS-ST, seguro e valor total.

**Limitação importante**: o Pivot2 contém NFs consolidadas, mas **não contém os itens/produtos
por NF**. Por isso:

| Pode reconciliar com Pivot2 | Não pode reconciliar com Pivot2 |
|---|---|
| Dashboard (total, NFs, clientes) | Mix Faturado por produto |
| Ranking de vendedores | Quantidade por SKU |
| NFs do período | Margem por produto |
| Mapa por cidade | |

Para reconciliar o Mix por produto, é necessária a exportação de itens do Omie ou a API
`/produtos/` do Omie.

---

## Fluxo de reconciliação

```
pivot2_faturamento_normalizado.csv
        │
        ▼
omie_pivot2_faturamento_staging (tabela de staging no MySQL)
        │
        ▼
reconcile_pivot2_fiscal.php (compara staging × canonical)
        │
        ▼
biomie_notas_fiscais_canonicas (fonte de verdade do BIomie)
```

---

## Passo 1 — Preparar o CSV

Exporte do Omie:
1. Abrir **Faturamento por Período**
2. Filtrar **Situação = Autorizado**
3. Exportar como CSV (UTF-8 com BOM ou Latin-1)
4. Salvar como `pivot2_faturamento_normalizado.csv`

Colunas esperadas (cabeçalho na linha 1):
```
Data de Emissão, Nota Fiscal, CEP, Cidade, Cliente (Razão Social),
CNAE Principal, CNPJ/CPF, Contato, MDF-e - Número, Vendedor,
Total de Mercadoria, Desconto, Valor do ICMS ST, Seguro,
Valor do IPI, Total da Nota Fiscal
```

O importador aceita separador `,` ou `;` e valores monetários no formato brasileiro
(`1.234,56`) ou americano (`1234.56`). Datas no formato `dd/mm/YYYY`.

---

## Passo 2 — Importar o CSV para staging

```bash
# Dry-run (não insere nada, só mostra as primeiras 3 linhas)
php tools/dev/import_pivot2_csv.php --file=pivot2_faturamento_normalizado.csv --dry-run

# Importar (INSERT IGNORE — duplicatas são ignoradas automaticamente)
php tools/dev/import_pivot2_csv.php --file=pivot2_faturamento_normalizado.csv

# Re-importar do zero (trunca a tabela antes)
php tools/dev/import_pivot2_csv.php --file=pivot2_faturamento_normalizado.csv --truncate
```

Saída esperada:
```
Processadas: 146 | Inseridas: 146 | Duplicadas/ignoradas: 0 | Erros: 0
Staging total: 146 linhas, R$426313.04
```

---

## Passo 3 — Rodar reconciliação

```bash
# Saída legível
php tools/dev/reconcile_pivot2_fiscal.php --start=2026-05-01 --end=2026-05-31

# Saída JSON (para automação ou CI)
php tools/dev/reconcile_pivot2_fiscal.php --start=2026-05-01 --end=2026-05-31 --json
```

Saída JSON esperada quando OK:
```json
{
  "status": "ok",
  "pivot2": { "nfs": 146, "total_nf": 426313.04, "total_mercadoria": 418903.12 },
  "canonical": { "nfs": 146, "total_nf": 426313.04, "total_mercadoria": 418903.12 },
  "diff": { "nfs_diff": 0, "total_diff": 0.0, "missing_in_canonical": 0, "missing_in_pivot2": 0 }
}
```

Saída quando há problema crítico:
```json
{
  "status": "critical",
  "pivot2": { "nfs": 146, "total_nf": 426313.04 },
  "canonical": { "nfs": 0, "total_nf": 0 },
  "diff": { "missing_in_canonical": 146 }
}
```

---

## Passo 4 — Validar aceite no dashboard

Após reconciliação OK, verificar no BIomie (período 01/05/2026–31/05/2026):

| Tela | Métrica | Esperado |
|---|---|---|
| Dashboard | Vendas | R$ 426.313,04 |
| Dashboard | NFs | 146 |
| Dashboard | Ticket médio | R$ 2.919,95 |
| Dashboard | Clientes | ~136 |
| Vendedores | 1º lugar | ROBERTO SIQ. ~R$ 126.144,03 |
| Vendedores | 2º lugar | Licitação ~R$ 80.750,00 |
| Vendedores | Total geral | R$ 426.313,04 |
| Mix Faturado | Empty state | "Itens de NF não sincronizados" |

---

## Diagnóstico rápido (sem CSV)

Para verificar apenas o canônico (sem staging):
```bash
php tools/dev/biomie_db_diagnostico.php \
  --host=srv1659.hstgr.io \
  --db=u106688109_Comercial \
  --user=u106688109_Comercial_user \
  --pass=<senha> \
  --start=2026-05-01 \
  --end=2026-05-31
```

---

## Causas históricas de divergência

| Sintoma | Causa | Correção |
|---|---|---|
| R$0 no dashboard | `listTopInvoicesForDisplayCommercial` faltando em `CanonicalRepository` | Adicionado no commit `f4c1cf6` |
| R$0 no dashboard | Feature flags `CANONICAL_READS_*=0` | Corrigido em `config/.env.local.php` |
| R$0 mês corrente | `canonicalCoverageIsComplete()` rejeitando mês aberto | Fix em `OverviewPayloadBuilder.php` (commit `f4c1cf6`) |
| 0 NFs em maio | Sync parado em 05/09 | Forçar `php cron/sync_canonical.php 2026-05-01 2026-05-31` |

---

## Rematerialização dos marts

Após qualquer sync ou correção de dados:
```bash
php bin/materialize_marts.php
```

Verifica: `mart_cliente_periodo`, `mart_compradores_por_geo`, `mart_produto_periodo`.

# BIomie — Escopo Comercial: Regras Imutáveis

> Versão: Q.12 | Atualizado: 2026-05-23
>
> Este documento define as regras de negócio que **nunca devem ser alteradas** sem aprovação explícita.
> Qualquer mudança aqui invalida todos os relatórios históricos.

---

## Base Comercial NF

### Definição

A **Base Comercial NF** é o valor de referência para todas as análises de receita do BIomie.

**Fórmula (imutável):**

```
Base Comercial NF = valor_total − frete − outras_despesas − seguro
```

Representada em SQL como:

```sql
COALESCE(valor_base_comercial_nf,
    valor_total
    - COALESCE(frete, 0)
    - COALESCE(outras_despesas, 0)
    - COALESCE(seguro, 0)
)
```

Onde:
- `valor_base_comercial_nf` — coluna pré-computada em `biomie_notas_fiscais_canonicas` (calculada na ingestão)
- Quando nula, o fallback ao vivo recalcula com a fórmula acima

**Nunca adicionar IPI, ICMS-ST, ou outros encargos à base.** Nunca subtrair desconto da base.

### Como usar no código

```php
use BIomie\Support\FiscalValueBasis;

$baseSql = FiscalValueBasis::preComputedBaseSql('nf');
// Retorna: COALESCE(`nf`.`valor_base_comercial_nf`, ...)
```

### Validação

Guard D1 (`fiscal_value_basis_guard.php`) verifica:
- Coluna `valor_base_comercial_nf` existe em `biomie_notas_fiscais_canonicas`
- Colunas `frete`, `outras_despesas`, `seguro` existem
- Fórmula calculada coincide com valor pré-computado para amostra de NFs

---

## operation_scope

### Valores válidos

| Valor | Significado |
|-------|-------------|
| `comercial` | NF entra no escopo comercial — visível em dashboards de venda |
| `licitacao` | NF de processo licitatório — **excluída** por padrão dos dashboards |
| `mercado_pago` | NF de gateway de pagamento — excluída, não é receita operacional |
| `devolucao` | NF de devolução — tratada separadamente, nunca soma à receita |
| `interno` | NF interna / transferência — excluída |
| `indefinido` | Classificação pendente — não entra em nenhum escopo definitivo |

### Regra de ouro

`operation_scope` é atribuído **uma única vez** na ingestão. Nunca recalcular automaticamente para NFs já classificadas. Correções são feitas via `biomie_visibility_overrides` (ver abaixo).

### Onde é aplicado

A coluna `operation_scope` existe em:
- `biomie_notas_fiscais_canonicas.operation_scope`
- `biomie_omie_pedidos.operation_scope`

---

## Exclusão de Licitação

### Mecanismo

A Licitação (`operation_scope = 'licitacao'`) é excluída via `BiVisibilityScope`:

```php
use BIomie\Support\BiVisibilityScope;

$condition = BiVisibilityScope::getCondition($pdo, 'biomie_notas_fiscais_canonicas', 'nf');
// Retorna: nf.operation_scope NOT IN ('licitacao', ...) AND NOT EXISTS (override exclusão)
```

**Toda API, mart e dashboard comercial DEVE usar `BiVisibilityScope::getCondition()`.**

Exceções permitidas:
- Painel Financeiro Admin (visão total, sem filtro de escopo)
- CLIs de diagnóstico que explicitamente auditam a Licitação

### Overrides manuais

A tabela `biomie_visibility_overrides` permite incluir ou excluir NFs individuais do escopo comercial, independentemente do `operation_scope`. Nunca alterá-la automaticamente.

### Validação

Guard D2 (`fase_l_qa.php`) verifica 11 checks AC-01 a AC-11 confirmando que Licitação não aparece em nenhum dashboard, mart ou relatório comercial.

---

## Mercado Pago

**Não implementar integração com Mercado Pago** neste projeto.

NFs com `operation_scope = 'mercado_pago'` já são excluídas automaticamente pelo `BiVisibilityScope`. Nenhuma lógica adicional é necessária ou desejada.

---

## Tabela canônica de NFs

`biomie_notas_fiscais_canonicas` — a fonte da verdade para todas as análises.

Colunas críticas que nunca devem ser removidas ou renomeadas:

| Coluna | Uso |
|--------|-----|
| `valor_total` | Total da NF (bruto) |
| `valor_base_comercial_nf` | Base pré-computada (fallback da fórmula) |
| `frete` | Dedução da base |
| `outras_despesas` | Dedução da base |
| `seguro` | Dedução da base |
| `operation_scope` | Classifica o escopo da NF |
| `chave_nfe` | Chave de vinculação com títulos financeiros |
| `data_emissao` | Data canônica para análise temporal |
| `cnpj_emitente` | Identificador do emitente |
| `cnpj_destinatario` | Identificador do cliente |
| `cod_vendedor` | Vendedor responsável pela NF |

Nunca apagar, reprocessar ou reclassificar NFs já canônicas sem dry-run e aprovação.

---

## Conciliação NF × Título

### Bridge

A tabela `biomie_bridge_nf_titulo_items` vincula cada NF (`nota_key`) a um ou mais títulos financeiros (`titulo_key`). Esta tabela é construída pelo processo de matching e nunca deve ser sobrescrita manualmente.

```
biomie_bridge_nf_titulo_items    — vínculos individuais (UNIQUE: nota_key + titulo_key)
biomie_bridge_nf_titulo_summary  — resumo por nota_key (soma dos títulos vinculados)
```

### Regras de modificação

- **Nunca executar `bridge_nf_titulo_build.php --apply`** sem dry-run antes
- Dry-run primeiro: `php tools/dev/bridge_nf_titulo_build.php --start=... --end=... --dry-run`
- O guard D3 (`reconciliation_guard.php`) verifica 30 invariantes da bridge

---

## Marts comerciais

Os marts materializam os dados da base canônica para queries rápidas em dashboards. São reconstruídos sob demanda, nunca em tempo real.

| Tabela | Granularidade | Builder |
|--------|--------------|---------|
| `mart_vendedor_dia` | Vendedor × Dia | `VendorDayMartBuilder` |
| `mart_cliente_periodo` | Cliente × Período | `CustomerPeriodMartBuilder` |
| `mart_vendas_por_cnae_cnpj` | CNAE × CNPJ | `CnaeSalesMartBuilder` |
| `mart_compradores_por_geo` | Geo × Comprador | `GeoBuyerMartBuilder` |
| `mart_produto_periodo` | Produto × Período | `ProductPeriodMartBuilder` |

Todos os builders usam `BiVisibilityScope` e a Base Comercial NF. Nunca contêm Licitação.

### Quando rematerializar

- Após sync completo do Omie (novas NFs importadas)
- Após mudança de `operation_scope` em NFs existentes
- Após atualização de `biomie_visibility_overrides`
- Quando guard D4 reportar tabela vazia

---

## Resumo das regras imutáveis

| # | Regra |
|---|-------|
| R1 | Não alterar a fórmula Base Comercial NF |
| R2 | Não adicionar campos à fórmula da base (IPI, desconto, etc.) |
| R3 | Não reclassificar `operation_scope` automaticamente |
| R4 | Não implementar Mercado Pago |
| R5 | Não alterar `biomie_omie_bronze` (tabela de bronze — fonte bruta) |
| R6 | Não apagar NFs, pedidos ou títulos |
| R7 | Não sobrescrever `biomie_visibility_overrides` automaticamente |
| R8 | Licitação continua excluída via `BiVisibilityScope` em todo escopo comercial |
| R9 | Diagnóstico primeiro, apply depois (dry-run obrigatório para operações de escrita) |
| R10 | Toda API/mart/query comercial usa `BiVisibilityScope::getCondition()` |

# COM.R1.1 — Investigação Bronze: Semântica de Etapas Omie, Varejo/Revenda e Orçamentos com NF

**Data:** 2026-05-28  
**Fase:** COM.R1 FASE 1 — Diagnóstico read-only concluído  
**Ferramentas:** `commercial_stage_semantics_audit.php` + `omie_order_payload_semantics_audit.php`

---

## Resumo Executivo

A investigação dos payloads bronze (`biomie_omie_bronze`) confirmou e expandiu os achados da FASE 0. Os resultados permitem uma proposta de mapeamento semântico oficial e apontam os próximos passos para COM.R1.2.

---

## 1. Fontes Bronze Disponíveis

| entity_name | omie_method | count | Período |
|---|---|---|---|
| `movement` | ListarMovimentos | 39.266 | abr-mai/2026 |
| `title` | PesquisarLancamentos | 34.893 | abr-mai/2026 |
| **`order`** | **ListarPedidos** | **22.974** | **abr-mai/2026** |
| `invoice` | ListarNF | 19.510 | abr-mai/2026 |
| `client` | ListarClientesResumido | 13.024 | abr-mai/2026 |
| `budget` | ListarOrcamentos | 2.576 | abr-mai/2026 |
| **`order_stage`** | **ListarEtapasPedido** | **475** | **mai/2026** |
| `vendor` | ListarVendedores | 411 | abr-mai/2026 |

**Chave:** o bronze tem `order` (pedidos) e `order_stage` (eventos de transição de etapa).

---

## 2. Mapeamento Confirmado dos Códigos Numéricos

A investigação do `cabecalho.etapa` no payload de `ListarPedidos` confirmou:

| Código | Label Omie | `status_comercial` atual | Semântica correta | Correto? |
|--------|-----------|--------------------------|-------------------|---------|
| `10` | Orçamento | `outro` | `orcamento` | **AJUSTE** — deveria ser `orcamento` semanticamente |
| `20` | Pedido / Autorizado | `autorizado` | `carteira_aceita` | OK |
| `50` | Faturado | `faturado` | `faturado` | OK |
| `60` | Entrega Finalizada | `faturado` | `entrega_finalizada` | **DIVERGENTE** |
| `70` | Cancelado | `cancelado` | `cancelado` | OK |
| `80` | Devolvido | `cancelado` | `devolvido` | PARCIAL |

---

## 3. Campos Confiáveis no Payload Bronze

O `infoCadastro` contém flags booleanas que são **mais confiáveis** que `cabecalho.etapa` para classificação semântica:

```
infoCadastro.faturado         "S"/"N"   — NF foi emitida?
infoCadastro.autorizado       "S"/"N"   — pedido autorizado?
infoCadastro.cancelado        "S"/"N"   — cancelado?
infoCadastro.devolvido        "S"/"N"   — devolvido?
infoCadastro.devolvido_parcial "S"/"N"  — devolução parcial?
infoCadastro.denegado         "S"/"N"   — NF denegada?
infoCadastro.dFat             "DD/MM/YYYY"  — data de faturamento
infoCadastro.dCan             "DD/MM/YYYY"  — data de cancelamento
```

**Regra de classificação confiável:**
```
faturado=S + etapa=50 → faturado (realizado com NF)
faturado=S + etapa=60 → entrega_finalizada (NF já existe, só confirma entrega)
faturado=N + etapa=10 → orcamento (proposta sem NF)
faturado=S + etapa=10 → STALE SNAPSHOT (canônico desatualizado)
autorizado=S + devolvido=N + cancelado=N + faturado=N → carteira_aceita
cancelado=S → cancelado
devolvido=S → devolvido
```

---

## 4. Achado Crítico: Varejo vs Revenda NÃO está nos Payloads Atuais

**Conclusão definitiva:** os campos `cabecalho.etapa` e `order_stage.cEtapa` armazenam **apenas o código numérico** (10, 20, 50, 60, 70, 80). O rótulo textual "Pedidos Varejo" ou "Pedidos Revenda" visível no Kanban Omie **não existe** em nenhum campo dos payloads bronze analisados.

**Por quê?** Os rótulos das etapas do funil de vendas Omie são **configuráveis por empresa** e retornados por um endpoint separado: `ListarEtapasFaturamento`. O sync atual não chama esse endpoint — logo a distinção Varejo/Revenda nunca foi capturada no canônico.

**O que `order_stage` (ListarEtapasPedido) contém:**
```json
{
  "cEtapa": "60",
  "cNumero": "1535",
  "dDtEtapa": "18/05/2026",
  "cHrEtapa": "07:56:44",
  "nCodPed": 8862827050,
  "faturamento": {
    "cFaturado": "S",
    "cNumNFE": "00001308",
    "cChaveNFE": "23260526537584...",
    "cAutorizado": "S"
  }
}
```

São **eventos de transição de etapa** (log histórico), não um catálogo de rótulos. O `natural_key` tem formato `{numero_pedido}:{etapa}:{timestamp}`.

**Próximo passo para obter Varejo/Revenda:**  
Chamar `ListarEtapasFaturamento` via OmieClient para buscar o dicionário de código → rótulo da empresa. Esse dicionário permitirá saber que `etapa=20` se chama "Pedidos Varejo" ou "Pedidos Revenda" para esta empresa específica.

---

## 5. Achado Crítico: Etapa 60 = Entrega Finalizada (pós-venda)

**Problema atual:** `classifyStatus()` mapeia `etapa >= 50 && <= 69` → `faturado`. Isso inclui etapa 60.

**Prova do bronze:**
- Pedidos em etapa 60 têm `infoCadastro.faturado = "S"` — a NF JÁ FOI EMITIDA quando o pedido estava em etapa 50.
- Etapa 60 = confirmação de entrega, não novo faturamento.
- `order_stage` confirma: o evento de etapa 50 (com `cFaturado=S` e `cNumNFE`) ocorre ANTES do evento de etapa 60.

**Impacto atual:**
- 139 pedidos em etapa 60 = R$ 652.976,18 sendo tratados como `faturado`
- 138/139 sem NF vinculada na camada canônica (a NF vinculou ao pedido em etapa 50, que pode ter sido faturado em período anterior)
- Risco de dupla contagem se o canônico atualizar de etapa 50 → 60 sem remover o pedido da base de realizado

**Proposta:** `classifyStatus()` deve tratar etapa 60 explicitamente:
```php
if ($code === 60) return 'entrega_finalizada';  // ANTES do range 50-69
if ($code >= 50 && $code <= 69) return 'faturado';
```

---

## 6. Achado Crítico: 14 Orçamentos com NF = Canonical Snapshot Stale

**Diagnóstico:** 12 dos 14 casos são `snapshot_stale`. O bronze histórico (`order_stage`) mostra que esses pedidos avançaram de etapa 10 para 50/60 depois que o canônico foi gravado.

**Exemplos de trajetória:**
```
Ped.1597  etapa_canônico=10  bronze_history=[10→80]       NF autorizada
Ped.1661  etapa_canônico=10  bronze_history=[10→60→50]    NF autorizada
Ped.1570  etapa_canônico=10  bronze_history=[10→50→60]    NF autorizada
Ped.1574  etapa_canônico=10  bronze_history=[10→60→80→50] NF autorizada
```

**Causa raiz:** o sync salva o pedido canônico quando ele entra na janela de sincronização, mas **não atualiza `etapa_omie`** quando o pedido avança de etapa em sincronizações subsequentes. O `order_stage` tem os eventos corretos, mas não é usado para atualizar o canônico.

**Não é bug de dados** — é bug de sync: o canônico fica com a etapa mais antiga, não a mais recente.

**Fix proposto (COM.R1.2):** usar `order_stage` para enriquecer/atualizar `etapa_omie` no canônico com a etapa mais recente disponível.

**2 casos restantes (`converted_after_quote`):**
- NF emitida DEPOIS da data do pedido — o pedido foi convertido corretamente, mas ficou em etapa 10 no canônico porque a data de conversão estava fora da janela de sync.

---

## 7. Proposta de Mapeamento Semântico Oficial (CommercialPipelineClassifier)

| etapa | label | biomie_scope | realizado | carteira | orçamento | entrega | cancelado | devolvido |
|-------|-------|---|---|---|---|---|---|---|
| `10` | Orçamento | `orcamento` | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ |
| `20` | Pedido/Autorizado | `carteira_aceita` | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `50` | Faturado | `faturado` | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| `60` | Entrega Finalizada | `entrega_finalizada` | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ |
| `70` | Cancelado | `cancelado` | ❌ | ❌ | ❌ | ❌ | ✅ | ❌ |
| `80` | Devolvido | `devolvido` | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ |

**Nota:** Varejo vs Revenda são sub-tipos de `carteira_aceita` (etapa 20) que exigem `ListarEtapasFaturamento` para serem distinguidos.

---

## 8. Achados Adicionais

- `cabecalho.origem_pedido = "ERP"` — todos os pedidos analisados têm origem ERP (não há origem que indique Varejo/Revenda)
- `informacoes_adicionais.codigo_categoria = "1.01.01" | "1.01.99"` — categorias fiscais, não comerciais
- `informacoes_adicionais.consumidor_final = "N" | "S"` — pode indicar B2B vs B2C, mas não Varejo vs Revenda explicitamente
- `budget` (ListarOrcamentos, 2.576 registros) — é um endpoint SEPARADO do Omie para orçamentos criados diretamente pelo módulo de orçamentos. Os 92 pedidos canônicos em etapa 10 foram sincronizados via `ListarPedidos`, não via `ListarOrcamentos`.

---

## 9. Critérios de Aceite — Status

| Critério | Status |
|---|---|
| Saber onde o Omie informa a etapa real | ✅ `cabecalho.etapa` (numérico) + `infoCadastro.*` (flags) |
| Confirmar se existe campo Varejo/Revenda | ✅ NÃO existe — requer `ListarEtapasFaturamento` |
| Confirmar etapa 60 = pós-venda (não faturado) | ✅ Confirmado pelo bronze |
| Classificar os 14 orçamentos com NF | ✅ 12=snapshot_stale, 2=converted_after_quote |
| Proposta segura para CommercialPipelineClassifier | ✅ Tabela na seção 7 |
| Nenhuma escrita no banco | ✅ 100% read-only |
| Nenhuma alteração de regra visual | ✅ |
| Nenhuma alteração de dados fiscais | ✅ |

---

## 10. Próximos Passos (COM.R1.2)

1. **Implementar `CommercialPipelineClassifier`** — classe PHP com a tabela de mapeamento da seção 7, substituindo o `classifyStatus()` atual
2. **Corrigir etapa 60** — `SalesSupport::classifyStatus()` precisa tratar explicitamente etapa 60 como `entrega_finalizada`
3. **Chamar `ListarEtapasFaturamento`** — novo sync para obter mapeamento código→rótulo (ex: 20="Pedidos Varejo") e armazenar em tabela de lookup
4. **Corrigir canonical stale** — usar `order_stage` bronze para atualizar `etapa_omie` dos pedidos canônicos com a etapa mais recente disponível no bronze histórico
5. **Metas proporcionais** — implementar `ProportionalTargetCalculator` que proporcionaliza meta do período (não soma mensais inteiras)

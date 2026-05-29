# COM.R1.2 — Classificador Comercial Oficial + Correção de Etapas Omie

**Data:** 2026-05-28  
**Guards:** D23 PASS, lint_all 477/477, schema_migrate verify OK, PHPStan level=0 OK  
**Domínio:** Semântica Comercial — Pedidos, Etapas e Pipeline

---

## 1. Contexto (achados de COM.R1.1)

| Campo | Realidade |
|---|---|
| `etapa_omie` | Código numérico: 10/20/50/60/70/80 |
| `status_comercial` | ENUM: `faturado`/`autorizado`/`cancelado`/`outro` |
| Varejo vs Revenda | NÃO disponível no payload `ListarPedidos` |
| Etapa 60 no BIomie | Mapeada como `faturado` (INCORRETO) |
| 14 orçamentos com NF | Snapshot stale (canônico salvo em etapa 10, avançou para 50/60) |

---

## 2. Mapa Oficial de Etapas (resultado desta fase)

| etapa | Label Omie | `commercial_scope` | realizado? | carteira? | orçamento? |
|-------|-----------|---|---|---|---|
| `10` | Orçamento | `orcamento` | ❌ | ❌ | ✅ |
| `20` | Pedido/Autorizado | `carteira_aceita` | ❌ | ✅ | ❌ |
| `50` | Faturado | `faturado` | ✅ | ❌ | ❌ |
| `60` | Entrega Finalizada | `entrega_finalizada` | ❌ | ❌ | ❌ (pós-venda) |
| `70` | Cancelado | `cancelado` | ❌ | ❌ | ❌ |
| `80` | Devolvido | `devolvido` | ❌ | ❌ | ❌ |

---

## 3. Por que etapa 60 NÃO é faturado

A investigação bronze (COM.R1.1) provou:
- O evento de transição para etapa 50 já contém `faturamento.cFaturado=S`, `cChaveNFE` e `cNumNFE`
- O evento de etapa 60 contém os mesmos dados de NF — a NF foi emitida EM ETAPA 50
- Etapa 60 = confirmação operacional de entrega, não emissão de nova receita

**Antes (BIomie atual):** `classifyStatus("60") → 'faturado'`  
**Depois (COM.R1.2):** `classifyStatus("60") → 'outro'` (ENUM-safe)  
`CommercialPipelineClassifier::classifyEtapa("60") → scope='entrega_finalizada'`

**Impacto medido:** 139 pedidos / R$ 652.976 anteriormente tratados como faturado — agora classificados corretamente como `entrega_finalizada`.

---

## 4. Realizado continua vindo de NF autorizada

A classificação por etapa (`should_count_as_realized_by_stage`) é analítica/diagnóstica. O **realizado oficial dos dashboards** continua baseado em `biomie_notas_fiscais_canonicas` com `status_fiscal = 'autorizada'` e respeito ao `BiVisibilityScope`. Nenhum dashboard foi alterado nesta fase.

---

## 5. Orçamentos com NF — tratamento

Para `etapa_omie = 10` com NF vinculada:
- `CommercialPipelineClassifier` emite `warning = 'quote_with_invoice'`
- `commercial_stage_stale_audit.php` classifica como `stale_quote_with_invoice`
- `commercial_stage_stale_repair.php` propõe correção quando bronze history confirma avanço (10→50 ou 10→60)
- Sem bronze history: o caso é ignorado (vai para lista `skipped`) para revisão manual

---

## 6. ListarEtapasFaturamento — roadmap Varejo/Revenda

O endpoint `ListarEtapasFaturamento` retorna o catálogo de etapas com seus rótulos reais (ex: "Pedidos Varejo", "Pedidos Revenda"). O tool `omie_stage_catalog_audit.php --refresh-api` chamará esse endpoint.

**Quando disponível**, os rótulos serão passados como `etapa_label` ao `CommercialPipelineClassifier`, que já suporta:
```php
case 20:
    $subScope = $this->detectSubScope($etapaLabel, $input);
    // 'varejo' | 'revenda' | 'aceito'
    $scope = match ($subScope) {
        'varejo'  => self::SCOPE_CARTEIRA_VAREJO,
        'revenda' => self::SCOPE_CARTEIRA_REVENDA,
        default   => self::SCOPE_CARTEIRA_ACEITA,
    };
```

---

## 7. order_stage para correção de stale

O bronze `order_stage` (ListarEtapasPedido, 475 registros) contém o log histórico de transições de etapa. O `commercial_stage_stale_repair.php` usa o `natural_key` `{numero_pedido}:{etapa}:{timestamp}` para construir a sequência de etapas e propor a correção.

**--apply bloqueado em COM.R1.2.** Desbloqueado em COM.R1.3+ com `--reason` obrigatório.

---

## 8. Arquivos entregues

| Arquivo | Tipo | Função |
|---|---|---|
| `app/Support/CommercialPipelineClassifier.php` | Novo | Classificação semântica completa |
| `app/SalesAnalytics/SalesSupport.php` | Modificado | Fix `classifyStatus()` — etapa 60 → 'outro' |
| `app/Sync/CommercialSyncCoordinator.php` | Modificado | Fix `classifyStatus()` — etapa 60 → 'outro' |
| `tools/dev/omie_stage_catalog_audit.php` | Novo | Catálogo de etapas Omie (lê bronze + API) |
| `tools/dev/commercial_stage_stale_audit.php` | Novo | Diagnóstico de canônicos stale |
| `tools/dev/commercial_stage_stale_repair.php` | Novo | Plano dry-run de correção (apply bloqueado) |
| D23 em `biomie_release_guard.php` | Atualizado | 16 checks COM.R1.2 |

---

## 9. Guards

```
biomie_release_guard.php:  22/22 PASS (D23 PASS)
lint_all.php:              477/477 PASS
schema_migrate verify:     OK (0 mismatches)
PHPStan level=0:           No errors
php -l:                    6/6 PASS
healthz.php:               ok=true db=true
```

---

## 10. Próxima fase (COM.R1.3)

1. **Desbloquear `--apply`** no `commercial_stage_stale_repair.php` com confirmação explícita
2. **Chamar `ListarEtapasFaturamento`** via `--refresh-api` para obter rótulos Varejo/Revenda
3. **Integrar `CommercialPipelineClassifier`** nas páginas `vendedores.php` e `plano_voo.php`
4. **Metas proporcionais** — `ProportionalTargetCalculator` para períodos quebrados
5. **Carteirização de clientes** — dono da carteira por vendedor via `ListarClientes`

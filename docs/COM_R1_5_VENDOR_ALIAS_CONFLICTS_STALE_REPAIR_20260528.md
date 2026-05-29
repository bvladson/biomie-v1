# COM.R1.5 — Vendor Alias, Canal, Status Ativo/Inativo

**Data:** 2026-05-28  
**Status:** Concluído — D26 PASS, VLG atualizado

---

## Regra especial CAIO — Revenda e Varejo

**CAIO - REV e CAIO - VAR representam canais comerciais DISTINTOS. Eles NÃO devem ser tratados como aliases equivalentes.** O BIomie preserva a separação para metas, ranking, conflitos de carteira, plano de voo e análise comercial.

- **CAIO - REV** = canal de Revenda
- **CAIO - VAR** = canal de Varejo
- Somente Caio atende os dois canais com nomes separados no Omie

**Aliases permitidos (intra-canal):**
- "CAIO REV" → "CAIO - REV" (formatação)
- "CAIO - REVENDA" → "CAIO - REV" (sinônimo de sufixo)
- "CAIO VAR" → "CAIO - VAR" (formatação)
- "CAIO - VAREJO" → "CAIO - VAR" (sinônimo de sufixo)

**Proibido:**
- "CAIO - REV" → "CAIO - VAR" (cross-channel)
- "CAIO" genérico resolvendo automaticamente para REV ou VAR quando ambos existem

Quando um nome ambíguo como "CAIO" é encontrado e há múltiplos canais disponíveis no roster, o `VendorAliasResolver` retorna `alias_confidence < 100` e o warning `ambiguous_caio_channel`.

## Vendedores inativos

Vendedores inativos **não são removidos do histórico**, mas ficam **ocultos por padrão nas telas operacionais**. Eles só aparecem em auditorias, detalhes históricos ou quando o usuário ativar o filtro "Incluir inativos".

**Por padrão (`active_only=true`):**
- Não aparecem nos rankings comerciais
- Não entram nas metas atuais
- Não aparecem nos filtros principais
- Não aparecem nos cards executivos
- Não aparecem na seleção de vendedor
- Não aparecem no plano de voo

**Com filtro "Incluir inativos" ativo:**
- Aparecem no final da tabela com badge cinza "Inativo — histórico"
- Dados históricos (NFs, pedidos) são preservados

**Sinalizações automáticas:**
- Cliente com dono inativo → `conflict_type = owner_inactive_review`, label "Cliente com dono inativo — revisar carteirização"
- Venda recente com vendedor inativo → `conflict_type = sale_vendor_inactive_warning`

## Novos componentes

### `app/Support/VendorAliasResolver.php`
Resolve aliases de vendedor com consciência de canal:
- `detectChannel(name)` — extrai canal do nome normalizado
- `stripChannel(name)` — remove sufixo de canal, retorna base
- `areEquivalent(a, b)` — só retorna true se canal == canal E base == base
- `isCrossChannelConflict(a, b)` — detecta CAIO-REV vs CAIO-VAR
- `resolve(name)` — retorna canonical_vendor_name, vendor_channel, alias_confidence, warnings

Canais detectados: `revenda` (REV), `varejo` (VAR), `representante` (REP), `licitacao`, `outro`

### `app/Support/ActiveVendorResolver.php`
Resolve status ativo/inativo e canal via `biomie_vendedores_omie`:
- `resolve(vendorRef, vendorName)` — retorna is_active, vendor_channel, display_badge
- `ensureLoaded()` — carrega roster em memória (lazy, uma vez por request)
- `getActiveVendorNames()` — retorna lista de nomes ativos
- `getSummary()` — total/ativos/inativos/source para auditorias
- Fallback graceful: se tabela ausente, assume ativo com confidence=50

### Fix `VendorIdentityResolver::cleanAlias()`
O método anterior removia `' - rev'` e `' - var'` dos nomes, o que permitia CAIO-REV e CAIO-VAR serem resolvidos como o mesmo vendedor.

**Correção:** O step 3 de alias agora:
1. Extrai o canal do nome buscado (`extractChannel`)
2. Extrai a base do nome buscado (`stripChannel`)
3. Para cada candidato no vendorMap, verifica se o canal é idêntico
4. Só faz match se canal == canal E base == base
5. Se "CAIO" sem canal e há múltiplas variantes com canal, retorna `resolved=false` com `warnings=['ambiguous_caio_channel']`

### Novos conflito types em `CustomerOwnershipResolver`
- `CONFLICT_OWNER_INACTIVE_REVIEW` — dono do cliente inativo; não é conflito operacional, requer revisão de carteirização
- `CONFLICT_SALE_VENDOR_INACTIVE` — vendedor da venda está inativo
- `CONFLICT_CROSS_CHANNEL` — CAIO-REV vendeu cliente de CAIO-VAR (ou inverso); mantido como conflito real, nunca resolvido por alias

### `api/canonical_vendors.php` atualizado
Novos parâmetros:
- `active_only=1` (padrão true) — só retorna vendedores ativos
- `include_inactive=1` — inclui inativos (sobrescreve active_only)
- `inactive_only=1` — só inativos
- `channel=varejo|revenda|representante|licitacao|outro` — filtra por canal

Cada vendedor agora retorna: `is_active`, `vendor_channel`, `canonical_name`, `inactive_badge`, `channel_badge`

A resposta `meta` inclui `active_filter` e `vendor_roster` (total/ativos/inativos/source).

### `views/vendedores.html` atualizado
- Botão "Incluir inativos" na barra de filtros rápidos
- Quando ativo, flag global `BIomieVendorsIncludeInactive=true` → `app.js` passa `include_inactive=1` para a API
- Vendedores inativos exibidos com opacidade reduzida + badge "Inativo"
- Channel badges (REV/VAR/REP/LIC) visíveis próximos ao nome

## Ferramentas de diagnóstico

### `tools/dev/vendor_alias_audit.php`
```
php tools/dev/vendor_alias_audit.php --start=2026-01-01 --end=2026-05-28 --pretty
```
Verifica: CAIO-REV ≠ CAIO-VAR, pares cross-channel, nomes ambíguos, distribuição por canal.

### `tools/dev/vendor_active_status_audit.php`
```
php tools/dev/vendor_active_status_audit.php --pretty
```
Verifica: total/ativos/inativos, vendedores inativos com vendas recentes (WARNING), ghost vendors (em NFs mas não no roster).

## COM.R1.5.1 — Fidelidade do cadastro mestre de vendedores

Motivo da microfase: a tela do Omie mostra vendedores ativos e inativos, mas a auditoria anterior do BIomie retornou 29 ativos e 0 inativos. O novo diagnostico encontrou a hipotese concreta `active_status_mapping_gap`: o payload bruto `ListarVendedores` no bronze traz o campo `inativo` com valores `S/N`, enquanto o upsert local de `biomie_vendedores_omie` mantem `ativo=1` para todo vendedor sincronizado.

Decisoes mantidas:
- `CAIO - REV` continua sendo Revenda.
- `CAIO - VAR` continua sendo Varejo.
- `CAIO` generico continua ambiguo.
- REV e VAR nunca sao aliases equivalentes.
- Vendedor inativo sai da operacao atual somente quando o status tiver confianca suficiente.
- Historico de NF, pedido, metas e marts nao e apagado.

Novas ferramentas read-only:
```
php tools/dev/omie_vendor_payload_audit.php --pretty
php tools/dev/vendor_master_reconciliation_audit.php --pretty
```

O `ActiveVendorResolver` agora diferencia `status_from_biomie_master` de `status_from_omie_payload`. Quando a fonte local esta inconsistente, retorna `active_status_confidence`, `active_status_warning`, `vendor_status_source_untrusted` e/ou `active_status_mapping_gap`. Se o nome visto em NF/pedido/carteira nao existe por match exato no cadastro Omie, retorna `ghost_vendor`.

Achado especifico:
- `CAIO BRUNO - REV` aparece em NF, pedidos, donos de carteira e marts.
- O cadastro Omie contem `CAIO - REV`, nao `CAIO BRUNO - REV` por nome exato.
- O caso fica como `ghost_vendor` e `alias_candidate=true`, sugerindo `CAIO - REV` somente porque o canal REV bate com REV.
- A sugestao requer aprovacao manual e nunca pode cair em `CAIO - VAR`.

A tela de vendedores agora exibe alerta discreto quando `active_status_mapping_gap` estiver presente: "Status de vendedores em validacao: ha divergencia entre cadastro Omie e BIomie." Enquanto a confianca do status for baixa, a API nao deve ocultar automaticamente vendedores; o filtro `active_only` so remove inativos quando `active_status_confidence >= 80`.

Proximo passo recomendado: ajustar o sync real de vendedores para gravar o status Omie (`inativo=S/N`) em `biomie_vendedores_omie.ativo`, sem alterar schema e sem mexer em dados fiscais/comerciais.

## COM.R1.5.2 — Vendor Status Sync Repair

Objetivo: transformar o diagnostico `active_status_mapping_gap` em correcao controlada do cadastro mestre de vendedores.

Regras aplicadas:
- `inativo = "S"` no payload Omie vira `ativo = 0`.
- `inativo = "N"` no payload Omie vira `ativo = 1`.
- Vendedor inativo nao e apagado.
- Historico de NF, pedidos, metas, marts e valores permanece intacto.
- A escrita corretiva fica limitada a `biomie_vendedores_omie.ativo`.

Mudanca no sync:
- `CanonicalWriteRepository::upsertVendor()` passou a receber `bool $ativo`.
- `CommercialSyncCoordinator::syncVendors()` e `FiscalSyncCoordinator::syncVendors()` usam `OmieVendorStatusMapper` para mapear o status bruto da Omie.
- Upserts futuros deixam de forcar `ativo=1` e passam a preservar o status real do cadastro de vendedores Omie.

Ferramenta de reparo pontual:
```
php tools/dev/omie_vendor_status_sync_repair.php --pretty
php tools/dev/omie_vendor_status_sync_repair.php --apply --pretty
```

O script roda em dry-run por padrao. O apply so e permitido quando o plano contem exatamente 10 alteracoes do tipo `ativo=1 -> ativo=0`, que corresponde ao achado atual: 29 vendedores no payload Omie, 19 ativos e 10 inativos, contra 29 ativos e 0 inativos no BIomie antes do reparo.

Resultado esperado apos apply:
- `vendor_active_status_audit.php` mostra BIomie master com 29 total, 19 ativos e 10 inativos.
- `ActiveVendorResolver` deixa de marcar `active_status_mapping_gap`.
- `active_status_confidence` fica alta para vendedores presentes no cadastro/payload.
- `vendedores.php`, `plano_voo.php` e `canonical_vendors.php` podem esconder inativos com seguranca operacional, sem remover historico.

Observacao futura: campos Omie como "Incluido por", "Alterado por", data de inclusao e ultima alteracao podem alimentar uma auditoria de carteirizacao/origem do vendedor em fase posterior.

## Guard D26

Checks adicionados ao `biomie_release_guard.php` — D26 Customer Ownership and Goal Application (COM.R1.5):
1. VendorAliasResolver existe e não une REV=VAR
2. ActiveVendorResolver existe
3. canonical_vendors.php usa active_only
4. vendedores.html tem toggle Incluir inativos
5. CAIO-REV e CAIO-VAR são canais distintos (VendorIdentityResolver fix)
6. CustomerOwnershipResolver classifica owner_inactive_review e cross_channel
7. customer_ownership_conflict_audit classifica owner_inactive_review
8. Sem migration, sem DDL, sem alteração em Base Comercial NF

## Limitações

1. A tabela `biomie_vendedores_omie` precisa ser mantida sincronizada via Omie API para refletir mudanças de status ativo/inativo em tempo real.
2. A detecção de canal (REV/VAR) é baseada em heurísticas de sufixo de nome — novos vendedores com convenção diferente precisariam ser catalogados manualmente.
3. Vendedores marcados como inativos mas que continuam vendendo (via NF com nome do vendedor) serão detectados pelo `vendor_active_status_audit.php` como "Vendedor inativo com vendas recentes — WARNING".
4. O mapeamento de aliases dentro do mesmo canal ainda é baseado em comparação de base de nome. Aliases explícitos (tabela `biomie_vendor_aliases`) são sugeridos para COM.R1.6.

## Próxima fase recomendada

**COM.R1.6:**
- Tabela `biomie_vendor_aliases` explícita para mapeamento gerenciado de aliases
- Sync automático de status ativo/inativo via `ListarVendedores` Omie
- Drill-down de conflitos de canal na tabela de vendedores
- Filtro `conflict_type=cross_channel` no `api/customer_ownership_conflicts.php`
- Desbloquear `--apply` no `commercial_stage_stale_repair.php`

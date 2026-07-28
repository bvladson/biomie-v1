# STAB.R3.2 - Geo bairro_key, conflitos e backfill seguro

Data: 2026-05-25
Escopo: `bairro_key` / `cidade_key` em marts e dimensoes geo derivadas.
Regras preservadas: sem migrations pendentes, sem alteracao em Base Comercial NF, BiVisibilityScope, Licitacao, conciliacao ou FIN.R1/R2.

## Ferramenta corrigida

Arquivo: `tools/dev/geo_keys_backfill.php`

Mudancas:
- `--dry-run` permanece padrao.
- `--pretty` foi aceito explicitamente.
- A saida humana deixou de tentar imprimir arrays aninhados como string.
- O script gera log JSON em `storage/logs/geo_keys_backfill_<modo>_YYYYmmdd_HHMMSS.json`.
- O apply executa somente mapeamentos deterministicos.
- Conflitos nao deterministicos bloqueiam o apply.

Validacao:

```bash
php -l tools/dev/geo_keys_backfill.php
```

Resultado: PASS.

## Dry-run pre-apply

Comando:

```bash
php tools/dev/geo_keys_backfill.php --dry-run --pretty
```

Log:

`storage/logs/geo_keys_backfill_dry_run_20260525_063854.json`

Resumo geral:

| Metrica | Valor |
|---|---:|
| eligible_rows | 814 |
| eligible_pairs | 414 |
| updated_rows | 0 |
| updates_executed | 0 |
| display_name_variant_conflicts | 6 |
| blocked_conflicts | 0 |
| missing_key_with_revenue | 690 |
| affected_revenue | R$ 3.828.238,30 |

## Diagnostico de mart_compradores_por_geo

| Metrica | Valor |
|---|---:|
| total_rows | 437 |
| missing_key_rows | 437 |
| missing_key_with_revenue_rows | 437 |
| affected_revenue | R$ 2.047.147,54 |
| pairs_found / pairs_total | 179 / 179 |
| eligible_pairs | 178 |
| would_update_rows | 417 |
| skipped_empty_rows | 20 |
| blocked_conflict_rows | 0 |

Impacto por competencia:

| Competencia | Linhas | Faturamento |
|---|---:|---:|
| 2026-02 | 189 | R$ 955.265,74 |
| 2026-04 | 76 | R$ 726.085,15 |
| 2026-05 | 172 | R$ 365.796,65 |

Top bairros afetados:

| Bairro / cidade | Linhas | Faturamento |
|---|---:|---:|
| Centro / fortaleza | 23 | R$ 630.654,05 |
| Coco / fortaleza | 9 | R$ 302.369,30 |
| monte gordo monte gordo / camacari | 1 | R$ 110.075,01 |
| ondina / salvador | 1 | R$ 77.992,90 |
| Aldeota / fortaleza | 25 | R$ 63.859,43 |

## Conflitos classificados antes do apply

Os 2 conflitos do `mart_compradores_por_geo` nao sao ambiguidades estruturais de chave. Ambos possuem o mesmo `bairro_key` e o mesmo `bairro_norm`; a diferenca e apenas nome humano de exibicao, case, acento ou separador.

| bairro_key | Nomes atuais | bairro_norm candidato | Linhas | Faturamento | Motivo | Decisao |
|---|---|---|---:|---:|---|---|
| `sapiranga_coite` | `sapiranga coite`; `Sapiranga/Coite` | `sapiranga coite` | 7 | R$ 28.225,00 | display_name_variant; cidades `fortaleza` e `eusebio`, com `cidade_key` preservado | mapear |
| `boa_vista_castelao` | `Boa Vista/Castelao`; `boa vista castelao` | `boa vista castelao` | 4 | R$ 2.876,70 | display_name_variant; mesma cidade `fortaleza` | mapear |

Decisao: aplicar `bairro_key` e `cidade_key` nos casos deterministicos. Nenhum conflito nao deterministico foi encontrado; portanto nao ha caso marcado como `manter nulo` ou `exigir correcao manual` nesta rodada.

## Salvaguardas

- Nenhuma migration pendente sera aplicada nesta fase.
- O backfill nao apaga dados.
- O apply so pode atualizar campos derivados de chave/nome geo nas tabelas alvo.
- Se surgir `blocked_conflicts > 0`, o apply deve abortar.
- Rematerializacao e validacoes devem ocorrer depois do apply.

## Apply inicial

Comando:

```bash
php tools/dev/geo_keys_backfill.php --apply --pretty
```

Log:

`storage/logs/geo_keys_backfill_apply_20260525_064036.json`

Resultado:

| Tabela | Linhas atualizadas |
|---|---:|
| mart_compradores_por_geo | 417 |
| mart_vendas_por_cnae_cnpj | 253 |
| biomie_clientes_dim | 72 |
| biomie_clientes_geo_dim | 72 |
| Total | 814 |

## Rematerializacao e correcao do fluxo recorrente

Comando:

```bash
php bin/materialize_marts.php --start=2026-05-01 --end=2026-05-31
```

A primeira rematerializacao mostrou que maio/2026 voltava a nascer sem `bairro_key`. Causa encontrada: o caminho real de `bin/materialize_marts.php` usa os builders Gold (`GeoBuyerMartBuilder` e `CnaeSalesMartBuilder`), nao apenas os builders em `app/Modules/Geo/Infrastructure`.

Correcoes aplicadas:

- `app/DataPlatform/Gold/Builders/GeoBuyerMartBuilder.php`: backfill interno de `bairro_key`, `cidade_key`, `bairro_norm`, `bairro_nome` e `cidade_nome` apos o insert do mart geo.
- `app/DataPlatform/Gold/Builders/CnaeSalesMartBuilder.php`: backfill interno de `bairro_key`, `cidade_key` e `bairro_norm` apos o insert do mart CNAE.
- `app/Modules/Geo/Infrastructure/GeoAggregateBuilder.php`: removeu placeholder duplicado no backfill interno e passou a pular bairros vazios.
- `app/Modules/Geo/Infrastructure/CnaeAggregateBuilder.php`: adicionou backfill interno equivalente para o fluxo Geo/CNAE legado.

Rematerializacao final:

```bash
php bin/materialize_marts.php --start=2026-05-01 --end=2026-05-31
```

Resultado:

| Mart | Linhas |
|---|---:|
| compradores_geo | 172 |
| vendas_cnae | 170 |
| cliente_periodo | 243 |
| produto_periodo | 162 |
| fiscal | 424 |
| financeiro | 1158 |

Observacao: `source_freshness.status=warning` por origem canonica com ~25h de atraso, mas a materializacao concluiu com `status=pronto`.

## Padronizacao dos nomes de exibicao

Apos preencher as chaves, o health ainda apontou 2 `bairro_key` com nomes humanos conflitantes. Como ambos ja estavam classificados como `display_name_variant`, foi aplicado ajuste somente em `bairro_nome`, sem alterar faturamento, escopo comercial ou chaves.

Comando:

```bash
php tools/dev/geo_keys_backfill.php --apply --pretty
```

Log:

`storage/logs/geo_keys_backfill_apply_20260525_064854.json`

Resultado:

| bairro_key | canonical bairro_nome | Linhas ajustadas |
|---|---|---:|
| `boa_vista_castelao` | `Boa Vista/Castelão` | parte das 8 |
| `sapiranga_coite` | `Sapiranga/Coité` | parte das 8 |
| Total | - | 8 |

## Estado final do backfill

Comando:

```bash
php tools/dev/geo_keys_backfill.php --dry-run --pretty
```

Log:

`storage/logs/geo_keys_backfill_dry_run_20260525_064911.json`

Resumo final:

| Metrica | Valor |
|---|---:|
| eligible_rows | 0 |
| eligible_pairs | 0 |
| updates_executed | 0 |
| display_name_conflicts | 0 |
| blocked_conflicts | 0 |
| mart_compradores_por_geo.missing_key_with_revenue_rows | 20 |
| mart_compradores_por_geo.affected_revenue | R$ 23.087,80 |
| mart_vendas_por_cnae_cnpj.missing_key_with_revenue_rows | 0 |

As 20 linhas restantes em `mart_compradores_por_geo` tem bairro/cidade vazios na origem. Nao existe `bairro_key` deterministico a gerar sem corrigir os dados cadastrais ou a regra semantica de `cidade_norm IS NULL`; por isso ficaram justificadamente intocadas.

## Health e validacoes finais

Geo health:

```bash
php tools/dev/system_health_check.php --check geo_detail_consistency
```

Resultado: `WARNING`, nao mais `CRITICAL`.

Metricas principais:

| Metrica | Valor |
|---|---:|
| bairro_key_null | 0 |
| bairro_norm_with_uppercase | 0 |
| bairro_norm_with_accent | 0 |
| multiple_names_per_key | 0 |
| propagation_bug_count | 0 |
| bairro_norm_not_propagated | 0 |
| null_cidade_conflated | 20 |
| empty_bairro_with_revenue | 20 |

Validacoes:

| Comando | Resultado |
|---|---|
| `php -l tools/dev/geo_keys_backfill.php` | PASS |
| `php -l app/DataPlatform/Gold/Builders/GeoBuyerMartBuilder.php` | PASS |
| `php -l app/DataPlatform/Gold/Builders/CnaeSalesMartBuilder.php` | PASS |
| `php -l app/Modules/Geo/Infrastructure/GeoAggregateBuilder.php` | PASS |
| `php -l app/Modules/Geo/Infrastructure/CnaeAggregateBuilder.php` | PASS |
| `php tools/dev/lint_all.php` | PASS, 465 arquivos |
| `php tools/dev/biomie_release_guard.php --start=2026-05-01 --end=2026-05-31 --pretty` | PASS, 9/9 dominios |
| `php healthz.php` | ok=true |
| `php bin/schema_migrate.php status` | PASS, 38 descobertas, 34 aplicadas, 4 pendentes |
| `php bin/schema_migrate.php verify` | PASS, 34 verified, 0 mismatches |

Status geral do `system_health_check.php --json` ainda ficou `critical` por Shadow Validation (`2/2` divergencias nas ultimas 24h) e avisos operacionais fora do escopo desta fase. A parte Geo melhorou de `critical` para `warning` com causa restante documentada: 20 clientes sem cidade/bairro deterministico.

## Confirmacoes de escopo

- Nenhuma migration pendente foi aplicada.
- Nenhum arquivo em `database/migrations` foi alterado.
- Nenhum dado foi apagado.
- Nenhuma alteracao foi feita em Base Comercial NF.
- Nenhuma alteracao foi feita em BiVisibilityScope.
- Nenhuma alteracao foi feita em Licitacao.
- Nenhuma alteracao foi feita em conciliacao.
- Nenhuma alteracao foi feita em FIN.R1/R2.

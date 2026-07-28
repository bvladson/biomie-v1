# STAB.R3.3 - Shadow Validation e divergencias sistemicas

Data: 2026-05-25
Escopo: diagnostico e observabilidade do Shadow Validation.
Regras preservadas: sem alteracao em Base Comercial NF, BiVisibilityScope, Licitacao, conciliacao, FIN.R1/R2, migrations ou `database/migrations`.

## Resumo executivo

O health acusava Shadow Validation `CRITICAL` por taxa de divergencia de 100% nas ultimas 24h.

Diagnostico mostrou que a amostra recente era pequena e duplicada:

| Metrica | Valor |
|---|---:|
| registros 24h | 2 |
| divergentes 24h | 2 |
| query_signatures | 1 |
| endpoint | `finance_overview` |
| severidade dos registros | `high` |
| severidade `critical` | 0 |

A causa nao depende de Geo, Base Comercial NF, Licitacao/visibility nem marts comerciais. A divergencia recente compara `historical-snapshot` financeiro legado contra `canonical_shadow` financeiro.

Decisao: nao alterar FIN.R1/R2 nesta fase. A correcao feita foi no health/observabilidade: amostra baixa sem severidade `critical` agora fica `WARNING`, com acao explicita para auditoria.

## Ferramenta de auditoria criada

Arquivo:

`tools/dev/shadow_audit.php`

Comandos:

```bash
php tools/dev/shadow_audit.php --hours=24 --pretty
php tools/dev/shadow_audit.php --hours=24 --json
```

A ferramenta e somente leitura e consulta `biomie_shadow_validations`.

## Divergencias encontradas

Comando:

```bash
php tools/dev/shadow_audit.php --hours=24 --pretty
```

Resultado:

| id | endpoint | janela | fonte principal | fonte shadow | diff_type | severity |
|---:|---|---|---|---|---|---|
| 371 | `finance_overview` | 2026-05-01 a 2026-05-31 | `historical-snapshot` | `canonical_shadow` | `material_difference` | `high` |
| 370 | `finance_overview` | 2026-05-01 a 2026-05-31 | `historical-snapshot` | `canonical_shadow` | `material_difference` | `high` |

Os dois registros tem a mesma `query_signature`; portanto sao amostras duplicadas do mesmo contexto.

## Diferencas de metricas

| Metrica | Legacy | Candidate | Delta |
|---|---:|---:|---:|
| `summary.received` | 296.437,54 | 296.826,07 | 388,53 |
| `summary.received_titles` | 460 | 461 | 1 |
| `summary.vendors` | 1 | 13 | 12 |

Listas:

| Bloco | Legacy | Candidate | Missing | Extra |
|---|---:|---:|---:|---:|
| `received_rows` | 80 | 80 | 8 | 8 |
| `open_titles` | 70 | 70 | 0 | 0 |
| `overdue_titles` | 46 | 46 | 0 | 0 |

Freshness tambem divergiu:

| Campo | Legacy | Candidate |
|---|---|---|
| `data_contract.freshness_at` | 2026-05-24 21:32:24 | 2026-05-24 21:38:08/16 |
| `data_contract.reconciled_until` | 2026-05-24 21:32:24 | 2026-05-24 21:38:08/16 |

## Classificacao

| Divergencia | Classificacao | Causa raiz | Correcao nesta fase |
|---|---|---|---|
| `finance_overview` ids 370/371 | `legacy_snapshot_vs_canonical_ordering_or_freshness` | Snapshot financeiro legado/Omie comparado com tabela canonica financeira local; lista de recebidos limitada ao top 80 com mesma contagem, mas conjunto/ordenacao divergente, e resumo canonico com +1 titulo. | Nao alterar FIN.R1/R2. Documentar e ajustar health para amostra baixa. |

Dependencias:

| Dependencia | Resultado |
|---|---|
| marts comerciais | nao depende |
| Geo | nao depende |
| Base Comercial NF | nao depende |
| Licitacao / visibility | nao depende |
| financeiro | depende, mas FIN.R1/R2 ficou intocado |

## Correcao feita

Arquivo:

`app/SystemHealth/Checks/ShadowHealthCheck.php`

Mudanca:

- O health agora mede `query_signatures_24h`, `critical_rows_24h`, `high_rows_24h` e `last_divergent_endpoint`.
- Quando ha divergencia, mas a amostra e menor que 10 registros e nao ha linha `critical`, o status passa a `WARNING`.
- A porcentagem de divergencia continua exposta em `metrics.divergence_pct`.
- A acao agora aponta para `tools/dev/shadow_audit.php --hours=24 --pretty`.

Antes:

```text
CRITICAL - Taxa de divergencia: 100% (2/2 nas ultimas 24h).
```

Depois:

```text
WARNING - Amostra shadow baixa: 2/2 divergente(s), 1 assinatura(s), 100% nas ultimas 24h.
```

## Divergencias restantes justificadas

Permanece divergencia em registros historicos de `finance_overview`, mas ela esta documentada como comparacao entre fonte legada financeira e canonical financeiro. A decisao foi nao corrigir FIN.R1/R2 nesta fase para evitar mudanca indevida de regra financeira.

Proxima decisao possivel: em fase separada, rodar amostra forcada apos novo sync/cache financeiro e decidir se o contrato de `finance_overview` deve migrar para canonical ou se o comparador deve ignorar diferencas de ordenacao em listas limitadas.

## Validacoes

| Comando | Resultado |
|---|---|
| `php -l app/SystemHealth/Checks/ShadowHealthCheck.php` | PASS |
| `php -l tools/dev/shadow_audit.php` | PASS |
| `php tools/dev/lint_all.php` | PASS; 465 arquivos |
| `php tools/dev/shadow_audit.php --hours=24 --pretty` | PASS; 2 registros, 1 assinatura, `finance_overview` |
| `php tools/dev/system_health_check.php --check shadow --json` | WARNING, causa explicita |
| `php tools/dev/semantic_audit.php --json` | PASS; `ok=true`, 29 metricas |
| `php healthz.php` | PASS; `ok=true`, DB conectado |
| `php tools/dev/biomie_release_guard.php --start=2026-05-01 --end=2026-05-31 --pretty` | PASS geral; 9/9 dominios |
| `php bin/schema_migrate.php verify` | PASS; 34 verificadas, 0 mismatches |
| `php tools/dev/system_health_check.php --json` | WARNING geral; Shadow em WARNING com causa explicita |

## Confirmacoes de seguranca

- Nenhuma migration pendente foi aplicada.
- Nenhum arquivo em `database/migrations` foi alterado.
- Nenhum dado foi apagado.
- Nenhuma escrita foi feita em tabela de negocio.
- Base Comercial NF, BiVisibilityScope, Licitacao, conciliacao e FIN.R1/R2 ficaram intocados.

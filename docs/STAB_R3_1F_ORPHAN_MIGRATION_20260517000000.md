# STAB.R3.1f - Auditoria do registro orfao 20260517000000

Data: 2026-05-25

## Escopo

Diagnostico read-only do registro `20260517000000` em `biomie_schema_migrations`.

Nao foram executados:

- `migrate`;
- `repair`;
- `UPDATE`, `DELETE` ou cleanup em `biomie_schema_migrations`;
- alteracao de schema;
- alteracao de arquivos em `database/migrations`;
- aplicacao das 4 migrations pendentes.

## Registro historico

Consulta read-only em `biomie_schema_migrations`:

| Campo | Valor |
|---|---|
| `id` | 32 |
| `schema_version` | `20260517000000` |
| `description` | `Staging table para reconciliacao Pivot2 x canonico (NFs consolidadas do Omie).` |
| `logical_connection` | vazio |
| `actual_connection` | vazio |
| `migration_type` | `expansive` |
| `checksum` | vazio |
| `app_version` | `dev` |
| `status` | `applied` |
| `executed_at` | `2026-05-17 08:12:34` |
| `execution_ms` | `NULL` |
| `rolled_back_at` | `NULL` |

Metadados da tabela:

- tabela: `biomie_schema_migrations`;
- chave primaria: `id`;
- chave unica: `uq_schema_version(schema_version)`;
- nao existe coluna `batch`;
- nao existe coluna `applied_at`;
- o campo equivalente de tempo e `executed_at`;
- o campo equivalente de duracao e `execution_ms`.

## Evidencias no Git

`git log --all --name-only -- '*20260517000000*'` encontrou dois commits relevantes:

| Commit | Data | Evidencia |
|---|---|---|
| `5252edd` | 2026-05-17 05:12:51 -0300 | adicionou `database/migrations/Version20260517000000_Pivot2FaturamentoStaging.php` |
| `b6f1a11` | 2026-05-17 05:44:22 -0300 | removeu `Version20260517000000_Pivot2FaturamentoStaging.php` e adicionou `Version20260517000001_DropPivot2StagingTable.php` |

O commit `5252edd` declarou a migration `20260517000000` como criadora da tabela `omie_pivot2_faturamento_staging`, com unique key em `(numero_nf, documento, total_nota_fiscal)` para deduplicar imports.

O commit `b6f1a11` removeu o fluxo Pivot2 CSV e registrou a regra: entrada por CSV/planilha e proibida; a fonte oficial deve ser API Omie -> canonico -> marts. Ele tambem removeu:

- `tools/dev/import_pivot2_csv.php`;
- `tools/dev/reconcile_pivot2_fiscal.php`;
- `docs/reconciliacao-pivot2-omie.md`;
- `database/migrations/Version20260517000000_Pivot2FaturamentoStaging.php`.

E adicionou:

- `database/migrations/Version20260517000001_DropPivot2StagingTable.php`;
- `tools/dev/reconcile_omie_api_fiscal.php`;
- `docs/omie-source-of-truth.md`.

## Evidencias no projeto atual

`database/migrations` nao contem arquivo `*20260517000000*`.

Referencias atuais encontradas:

- `app/SystemHealth/Checks/ProhibitedManualImportHealthCheck.php` lista `database/migrations/Version20260517000000_Pivot2FaturamentoStaging.php` como arquivo proibido;
- `app/SystemHealth/Checks/ProhibitedManualImportHealthCheck.php` tambem trata `omie_pivot2_faturamento_staging` e `omie_pivot2_faturamento_staging_deprecated` como tabelas proibidas/deprecated;
- `docs/omie-source-of-truth.md` documenta que `omie_pivot2_faturamento_staging` era staging de CSV e foi removida pela migration `20260517000001`;
- logs de repair STAB.R3.1d/e citam somente `20260517000001`, nao tornam `20260517000000` elegivel.

## Estado estrutural no banco

Consulta read-only em `information_schema.TABLES`:

| Tabela | Resultado |
|---|---|
| `omie_pivot2_faturamento_staging` | ausente |
| `omie_pivot2_faturamento_staging_deprecated%` | ausente |

Janela de historico ao redor do registro:

| Version | Status | Checksum | Conn | Executed at | Observacao |
|---|---|---|---|---|---|
| `20260516000004` | applied | v2 preenchido | `db` | 2026-05-16 23:44:37 | registro normal |
| `20260517000000` | applied | vazio | vazio | 2026-05-17 08:12:34 | registro orfao |
| `20260517000001` | applied | v2 preenchido | `flight_plan_db` | 2026-05-17 15:06:42 | remove staging Pivot2 |
| `20260517000002` | applied | v2 preenchido | `flight_plan_db` | 2026-05-17 15:06:46 | geo keys |
| `20260518120000` | applied | v2 preenchido | `flight_plan_db` | 2026-05-18 09:44:58 | geo quality/scope |

## Hipoteses avaliadas

| Hipotese | Evidencia | Resultado |
|---|---|---|
| `removed_migration_file` | arquivo existiu em `5252edd` e foi removido em `b6f1a11` | forte |
| `orphan_history_record` | registro permanece na tabela, mas o runner atual nao descobre arquivo correspondente | forte |
| `manual_insert` | `logical_connection`, `actual_connection` e `checksum` vazios nao batem com o formato atual do runner | possivel |
| `failed/partial legacy` | `status=applied`, mas sem checksum/conexao/execution_ms | possivel, sem evidencia de falha ativa |
| `unknown` | ha evidencia suficiente de origem Pivot2 removida | nao e a classificacao principal |

## Classificacao

Classificacao principal:

```text
orphan_history_record + removed_migration_file
```

Subclassificacao:

```text
legacy_or_manual_metadata_shape
```

Justificativa: a migration existiu no historico Git, foi removida por uma decisao explicita de arquitetura/produto, e seu efeito estrutural esperado nao existe mais no banco porque a staging Pivot2 e proibida e foi removida pela migration posterior `20260517000001`. O formato do registro historico, com checksum e conexoes vazias, indica que ele foi gravado por mecanismo antigo/incompleto ou insercao manual, nao pelo formato atual do `MigrationRunner`.

## Risco

Risco operacional atual: baixo.

Motivos:

- `php bin/schema_migrate.php verify` passa com 34 migrations descobertas e 0 mismatches;
- `php bin/schema_migrate.php status` mostra 38 descobertas, 34 aplicadas, 4 pendentes e 0 checksum mismatch;
- a tabela proibida `omie_pivot2_faturamento_staging` nao existe;
- tabelas deprecated Pivot2 tambem nao existem;
- o release guard passa 9/9.

Risco de auditoria: medio.

Motivo: `biomie_schema_migrations` contem um registro aplicado sem arquivo atual, sem checksum e sem conexao. Isso nao bloqueia o runner atual, mas deve continuar documentado para nao ser confundido com drift ativo.

## Recomendacao

Decisao para STAB.R3.1f:

```text
manter intocado
```

Nao deletar, nao atualizar e nao tentar repair deste registro nesta fase.

Recomendacao futura:

1. Manter este documento como trilha de auditoria antes de qualquer aplicacao das 4 migrations pendentes.
2. Se houver necessidade de limpeza formal, criar uma fase separada para definir uma politica de "archived/removed migrations", sem apagar historico.
3. Evitar recriar `Version20260517000000_Pivot2FaturamentoStaging.php`, porque o proprio produto passou a tratar Pivot2 CSV como fonte proibida.
4. Nao criar migration corretiva: o efeito estrutural desejado hoje e a ausencia da staging Pivot2, ja confirmado.

## Validacoes

Executadas apos a auditoria:

```bash
php bin/schema_migrate.php status
php bin/schema_migrate.php verify
php tools/dev/biomie_release_guard.php --start=2026-05-01 --end=2026-05-31 --pretty
```

Resultados:

- `status`: PASS; 38 descobertas, 34 aplicadas, 4 pendentes, 0 checksum mismatch;
- `verify`: PASS; 34 verificadas, 0 mismatches;
- `release_guard`: PASS geral, 9/9 dominios.

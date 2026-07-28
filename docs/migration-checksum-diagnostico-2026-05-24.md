# Diagnostico de Checksums de Migrations - 2026-05-24

## Escopo

STAB.R3.1a ate STAB.R3.1d foram conduzidas sem aplicar migrations pendentes, sem reescrever migrations aplicadas e sem alterar schema. Em STAB.R3.1e foi executado repair controlado apenas de metadados de checksum na tabela `biomie_schema_migrations`, com backup previo, `--apply` explicito e `--reason` obrigatorio.

## Comandos

O checklist solicitado usa `tools/dev/schema_migrate.php`, mas esse arquivo nao existe neste checkout. O CLI real e `bin/schema_migrate.php`.

Comandos executados:

```bash
php tools/dev/schema_migrate.php status
php tools/dev/schema_migrate.php verify --pretty
php tools/dev/schema_migrate.php list --pretty
```

Resultado: os tres falharam com `Could not open input file: tools/dev/schema_migrate.php`.

Equivalentes executados:

```bash
php bin/schema_migrate.php status
php bin/schema_migrate.php verify --pretty
php bin/schema_migrate.php list --pretty
```

Resultado inicial:

- `status`: ok, 38 migrations descobertas, 34 aplicadas, 4 pendentes, 34 checksums divergentes.
- `verify --pretty`: falhou com 34 divergencias.
- `list --pretty`: comando inexistente no CLI naquele momento. O runner aceitava `status`, `migrate`, `rollback` e `verify`.

Comando novo adicionado nesta microfase, em modo somente leitura:

```bash
php bin/schema_migrate.php checksum-diagnose
```

## Causa primaria

O checksum legado era path-dependent. Em `app/Migrations/MigrationRunner.php`, `computeChecksum()` concatenava o caminho absoluto do arquivo antes do conteudo:

```php
$buffer = $migrationFile . "\n";
```

Isso significa que o mesmo arquivo gera checksums diferentes em:

- `C:\Users\Usuario\Downloads\biomie\...`
- `/home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/...`

Dos 34 mismatches, 26 batem exatamente quando o checksum e recalculado simulando o path de producao `/home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie`. Esses casos sao falsos positivos de ambiente/local-path.

As outras 8 migrations aplicadas nao bateram com os paths conhecidos, mas os efeitos estruturais esperados delas estao presentes no banco. Isso indica diferenca historica de conteudo/path/metadado, nao ausencia de schema.

## STAB.R3.1b - Normalizacao do algoritmo

Decisao implementada: usar caminho relativo normalizado + conteudo normalizado. Essa opcao preserva a identidade do arquivo/versionamento sem depender de `C:\Users\...` ou `/home/...`.

Algoritmo legado:

- incluia caminho absoluto da migration;
- incluia caminho absoluto de arquivos extras de checksum;
- usava conteudo bruto do arquivo;
- CRLF/LF afetava o hash;
- namespace, classe e nome da migration entravam apenas como conteudo bruto do arquivo;
- a versao entrava indiretamente pelo nome/caminho absoluto do arquivo.

Algoritmo novo (`v2:relative-path-plus-normalized-content`):

```text
sha256(
  "BIOMIE-MIGRATION-CHECKSUM:v2\n"
  + relative_path_normalizado + "\n"
  + conteudo_normalizado
  + fontes_extras_relativas_normalizadas
)
```

Normalizacoes aplicadas:

- caminho relativo a partir da raiz do projeto;
- separador de diretorio sempre `/`;
- sem caminho absoluto;
- BOM UTF-8 removido quando presente;
- CRLF/CR convertidos para LF;
- final do arquivo normalizado para exatamente uma quebra de linha.

O comando `checksum-diagnose` compara, sem alterar banco:

- checksum armazenado;
- checksum legado no path local;
- checksum legado em roots conhecidos de producao;
- checksum novo v2;
- existencia do arquivo;
- classificacao operacional.

Resultado do diagnostico apos a mudanca:

| Classe | Quantidade | Leitura |
|---|---:|---|
| `path_only_mismatch` | 26 | checksum armazenado bate com algoritmo legado usando root de producao conhecido |
| `content_mismatch` | 8 | checksum armazenado nao bate com path local, path de producao conhecido nem algoritmo v2 |
| `pending` | 4 | migrations descobertas sem registro aplicado |
| `missing_file` | 0 | nenhum arquivo aplicado ausente |
| `unknown` | 0 | nenhum caso sem classificacao nesta rodada |

Observacao importante: `status` e `verify` passam a calcular checksums com o algoritmo v2, mas a tabela `biomie_schema_migrations` ainda guarda hashes legados. Portanto, nesta fase, `verify` continuar falhando com 34 mismatches e esperado. Isso nao deve ser corrigido com repair automatico; a reconciliacao de metadados precisa ser uma acao futura, deliberada e documentada.

## STAB.R3.1c - Diagnostico v2 em producao

Deploy controlado realizado para producao em `/home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie`, contendo apenas:

- `bin/schema_migrate.php`;
- `app/Migrations/MigrationRunner.php`;
- `docs/migration-checksum-diagnostico-2026-05-24.md`.

Comandos executados em producao:

```bash
php -l bin/schema_migrate.php
php -l app/Migrations/MigrationRunner.php
php bin/schema_migrate.php checksum-diagnose
php bin/schema_migrate.php status
php bin/schema_migrate.php verify
php tools/dev/biomie_release_guard.php --start=2026-05-01 --end=2026-05-31 --pretty
```

Resultado:

- `php -l bin/schema_migrate.php`: PASS.
- `php -l app/Migrations/MigrationRunner.php`: PASS.
- `checksum-diagnose`: PASS.
- `status`: PASS, 38 descobertas, 34 aplicadas, 4 pendentes, 34 mismatches.
- `verify`: falha esperada, 34 mismatches, porque a tabela ainda guarda checksums legados.
- `biomie_release_guard`: PASS geral, 9/9 dominios. Houve apenas WARN informacional em D6 (`SELECT * em APIs`), sem falha de release.

Comparacao local x producao:

| Metrica | Local | Producao | Divergencia |
|---|---:|---:|---|
| total | 38 | 38 | nao |
| aplicadas | 34 | 34 | nao |
| pendentes | 4 | 4 | nao |
| `path_only_mismatch` | 26 | 26 | nao |
| `content_mismatch` | 8 | 8 | nao |
| `missing_file` | 0 | 0 | nao |
| `unknown` | 0 | 0 | nao |
| `new_matches` | 0 | 0 | nao |
| `legacy_matches` | 26 | 26 | nao |
| `verify` mismatches | 34 | 34 | nao |

Observacao: em producao, `legacy_roots` detectou apenas o root real `/home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie`; no local, a lista tambem inclui `C:/Users/Usuario/Downloads/biomie`. Essa diferenca e esperada e nao muda a classificacao.

Comandos explicitamente nao executados:

- `php bin/schema_migrate.php migrate`;
- qualquer comando de `repair`;
- qualquer ferramenta de `--apply`;
- qualquer aplicacao das 4 migrations pendentes.

## STAB.R3.1d - Repair dry-run auditavel

Objetivo: responder, sem escrita no banco, quais linhas da tabela `biomie_schema_migrations` seriam atualizadas em um repair futuro para reconciliar checksums v2.

Tabela historica confirmada:

- tabela: `biomie_schema_migrations`;
- chave primaria: `id`;
- chave unica de migration: `uq_schema_version(schema_version)`;
- campo de versao: `schema_version`;
- campo de checksum: `checksum CHAR(64)`;
- status: `status ENUM('applied','rolled_back')`;
- execucao: `executed_at`, `execution_ms`, `rolled_back_at`.

Comando implementado:

```bash
php bin/schema_migrate.php repair-checksums --dry-run --reason="normalizar checksum v2 path-independent apos STAB.R3.1c"
```

Regras implementadas:

- `--dry-run` e o modo padrao;
- `--reason` e obrigatorio;
- `--apply` esta bloqueado em STAB.R3.1d;
- nenhum `UPDATE` e executado;
- migrations pendentes entram como `pending_not_touched`;
- `missing_file > 0` bloqueia o plano;
- `content_mismatch` sem validacao estrutural vira `content_mismatch_unverified` e bloqueia o plano;
- total de elegiveis diferente de 34 bloqueia o plano.

Resultado do dry-run local:

| Metrica | Resultado |
|---|---:|
| total discovered | 38 |
| applied | 34 |
| pending | 4 |
| `path_only_mismatch` | 26 |
| `content_mismatch_structurally_verified` | 8 |
| `content_mismatch_unverified` | 0 |
| `missing_file` | 0 |
| `already_v2_match` | 0 |
| `eligible_for_future_repair` | 34 |
| `not_touched_pending` | 4 |
| `updates_executed` | 0 |

Log auditavel gerado:

```text
storage/logs/migration_repair_dry_run_20260525_053238.json
```

O log contem:

- timestamp e ambiente;
- reason informado;
- metadados da tabela historica;
- lista das 34 linhas elegiveis para repair futuro;
- lista das 4 migrations pendentes intocadas;
- classificacao de cada migration;
- confirmacao explicita de `updates_executed = 0`.

Validacoes apos o dry-run:

- `php -l bin/schema_migrate.php`: PASS.
- `php -l app/Migrations/MigrationRunner.php`: PASS.
- `php tools/dev/lint_all.php`: PASS, 465 arquivos.
- `php bin/schema_migrate.php checksum-diagnose`: PASS, 26 path-only, 8 content mismatch, 4 pendentes.
- `php bin/schema_migrate.php status`: PASS, 38 descobertas, 34 aplicadas, 4 pendentes, 34 mismatches.
- `php bin/schema_migrate.php verify`: falha esperada, 34 mismatches antes de qualquer repair real.
- `php tools/dev/biomie_release_guard.php --start=2026-05-01 --end=2026-05-31 --pretty`: PASS geral, 9/9 dominios.

Comandos explicitamente nao executados em STAB.R3.1d:

- `php bin/schema_migrate.php migrate`;
- qualquer repair real;
- qualquer `--apply`;
- qualquer aplicacao das 4 migrations pendentes;
- qualquer alteracao em `database/migrations`;
- qualquer alteracao de schema ou dados de negocio.

## STAB.R3.1e - Repair apply controlado

Objetivo: reconciliar os checksums das 34 migrations aplicadas e descobertas pelo runner para o algoritmo v2, alterando somente a coluna `checksum` em `biomie_schema_migrations`.

Comando de revalidacao dry-run executado antes do apply:

```bash
php bin/schema_migrate.php repair-checksums --dry-run --reason="normalizar checksum v2 path-independent apos STAB.R3.1d dry-run aprovado"
```

Resultado antes do apply:

| Metrica | Resultado |
|---|---:|
| total discovered | 38 |
| applied | 34 |
| pending | 4 |
| `path_only_mismatch` | 26 |
| `content_mismatch_structurally_verified` | 8 |
| `content_mismatch_unverified` | 0 |
| `missing_file` nas migrations descobertas | 0 |
| `eligible_for_future_repair` | 34 |
| `not_touched_pending` | 4 |
| `updates_executed` | 0 |

Comando apply executado:

```bash
php bin/schema_migrate.php repair-checksums --apply --reason="normalizar checksum v2 path-independent apos STAB.R3.1d dry-run aprovado"
```

Artefatos gerados:

```text
storage/backups/migrations/schema_history_before_repair_20260525_054341.json
storage/logs/migration_repair_apply_20260525_054346.json
```

Resultado do apply:

| Metrica | Resultado |
|---|---:|
| registros no backup da tabela historica | 35 |
| migrations atualizadas | 34 |
| pendentes intocadas | 4 |
| updates executados | 34 |
| schema alterado | nao |
| arquivos em `database/migrations` alterados | nao |
| migrations pendentes aplicadas | nao |

Detalhe de auditoria: o backup completo da tabela historica mostrou um registro antigo `20260517000000` (`Staging table para reconciliacao Pivot2 x canonico`) sem arquivo atual correspondente em `database/migrations`. Esse registro nao fazia parte das 34 migrations descobertas pelo runner, nao era elegivel no plano, tinha checksum vazio e nao foi atualizado. O repair aplicado ficou restrito as 34 migrations descobertas, aplicadas e validadas estruturalmente/path-only.

Resultado pos-apply:

| Comando | Resultado |
|---|---|
| `php bin/schema_migrate.php checksum-diagnose` | PASS; `new_matches=34`, `already_v2_match=34`, `path_only_mismatch=0`, `content_mismatch=0`, `missing_file=0` nas migrations descobertas |
| `php bin/schema_migrate.php status` | PASS; 38 descobertas, 34 aplicadas, 4 pendentes, `checksum_mismatch=0` |
| `php bin/schema_migrate.php verify` | PASS; 34 verificadas, 0 mismatches |
| `php tools/dev/biomie_release_guard.php --start=2026-05-01 --end=2026-05-31 --pretty` | PASS geral, 9/9 dominios |

Comandos explicitamente nao executados em STAB.R3.1e:

- `php bin/schema_migrate.php migrate`;
- aplicacao das 4 migrations pendentes;
- qualquer alteracao em `database/migrations`;
- qualquer alteracao de schema;
- qualquer alteracao de dados de negocio.

## Sumario

- Banco: MariaDB `11.8.6-MariaDB-log`, database `u106688109_Comercial`.
- Tabela de historico: `biomie_schema_migrations`.
- Arquivos de migrations: sem alteracoes locais (`git status --short database/migrations` vazio).
- Migrations pendentes: 4.
- Migrations aplicadas com mismatch local: 34.
- Migrations aplicadas com checksum compativel com path de producao: 26.
- Migrations aplicadas com efeito estrutural presente, mas checksum historico nao reproduzido: 8.

## Diagnostico por migration

Checksums abaixo estao truncados para 12 caracteres para manter a tabela legivel. Os valores completos estao na saida de `php bin/schema_migrate.php verify`.

| Migration | Status banco | Checksum banco | Checksum arquivo | Arquivo existe? | Arquivo foi alterado? | Natureza | Pode reparar? | Precisa migration corretiva? |
|---|---|---:|---:|---|---|---|---|---|
| 20260413050100 | applied | bb7d79ccc64c | c0e82946d03a | sim | nao local; bate com path prod | cosmetica/path | nao agora | nao |
| 20260413050200 | applied | 90ffc1db203a | 3c51a0f3be11 | sim | nao local; bate com path prod | cosmetica/path | nao agora | nao |
| 20260413050300 | applied | 1e4a56f15dbd | 982a2a45f7f3 | sim | nao local; bate com path prod | cosmetica/path | nao agora | nao |
| 20260413050400 | applied | 84b5434dcfff | 9dbd8436fe83 | sim | nao local; bate com path prod | cosmetica/path | nao agora | nao |
| 20260413050500 | applied | d8770aeb4239 | ab68877f22b7 | sim | nao local; bate com path prod | cosmetica/path | nao agora | nao |
| 20260413050600 | applied | fa0b9b1c8894 | cfd20d3bf50e | sim | nao local; bate com path prod | cosmetica/path | nao agora | nao |
| 20260413050700 | applied | 51109dad777b | 56a24f647cbb | sim | nao local; bate com path prod | cosmetica/path | nao agora | nao |
| 20260416190000 | applied | fc1b3549829f | e74abb3679a6 | sim | nao local; bate com path prod | cosmetica/path | nao agora | nao |
| 20260417090000 | applied | 1be666380505 | caf8c98084bb | sim | nao local; bate com path prod | cosmetica/path | nao agora | nao |
| 20260417150000 | applied | 4c610be3e235 | 619d31fd41e3 | sim | nao local; bate com path prod | cosmetica/path | nao agora | nao |
| 20260417170000 | applied | 7a63cbafa08f | 822ef205d426 | sim | nao local; bate com path prod | cosmetica/path | nao agora | nao |
| 20260417190000 | applied | 6ec552b69621 | f00a6a6a36fc | sim | nao local; bate com path prod | cosmetica/path | nao agora | nao |
| 20260417210000 | applied | ec087b4c417e | 70f2e7945055 | sim | nao local; bate com path prod | cosmetica/path | nao agora | nao |
| 20260417250000 | applied | f55f04cafdfa | 84933fe730ac | sim | nao local; bate com path prod | cosmetica/path | nao agora | nao |
| 20260418000000 | applied | c814c3c28169 | 0ac5eaa26ba1 | sim | nao local; bate com path prod | cosmetica/path | nao agora | nao |
| 20260419000000 | applied | 90f7efe6b9a7 | c39aa6aa9f06 | sim | nao local; bate com path prod | cosmetica/path | nao agora | nao |
| 20260419010000 | applied | 3f0e9dc8df15 | d2f353c708bb | sim | nao local; bate com path prod | cosmetica/path | nao agora | nao |
| 20260501090000 | applied | 9fb25af2b9c9 | d04f81782b93 | sim | nao local; bate com path prod | cosmetica/path | nao agora | nao |
| 20260501220000 | applied | 73859f834d60 | 0f93bae4401b | sim | nao local; bate com path prod | cosmetica/path | nao agora | nao |
| 20260502120000 | applied | 856fbc7bb76a | 89ad6c4354f0 | sim | nao local; bate com path prod | cosmetica/path | nao agora | nao |
| 20260502130000 | applied | d835de5d43aa | 4ab38196bb9e | sim | nao local; bate com path prod | cosmetica/path | nao agora | nao |
| 20260503000000 | applied | ae84ad1e9eb4 | 54ad41e4720f | sim | nao local; bate com path prod | cosmetica/path | nao agora | nao |
| 20260504010000 | applied | c3e4d62a904b | 45268cb49e01 | sim | nao local; bate com path prod | cosmetica/path | nao agora | nao |
| 20260506210000 | applied | 3784810d7b14 | c19b63c0fce3 | sim | nao local; bate com path prod | cosmetica/path | nao agora | nao |
| 20260506223000 | applied | 32e242715288 | 3dd4177fd84e | sim | nao local; bate com path prod | cosmetica/path | nao agora | nao |
| 20260515000000 | applied | 74e74729c8f3 | d05792a9b359 | sim | nao local; conteudo/path historico nao reproduzido | estrutural ja presente | sim, deliberado | nao |
| 20260516000000 | applied | 9dc15463ed8f | 14504cd4efd2 | sim | nao local; conteudo/path historico nao reproduzido | estrutural ja presente | sim, deliberado | nao |
| 20260516000001 | applied | cdae0d577139 | 24bda8dff0b9 | sim | nao local; conteudo/path historico nao reproduzido | estrutural ja presente | sim, deliberado | nao |
| 20260516000002 | applied | 6467f9d233b5 | 46b6ec0e116f | sim | nao local; conteudo/path historico nao reproduzido | estrutural ja presente | sim, deliberado | nao |
| 20260516000003 | applied | 62ed36fb63ba | 2676c30667f9 | sim | nao local; bate com path prod | cosmetica/path | nao agora | nao |
| 20260516000004 | applied | 16dc345fe950 | 6cf55f0a69e7 | sim | nao local; conteudo/path historico nao reproduzido | estrutural ja presente | sim, deliberado | nao |
| 20260517000001 | applied | 81da1b52792f | 7f997f243331 | sim | nao local; conteudo/path historico nao reproduzido | estrutural ja presente | sim, deliberado | nao |
| 20260517000002 | applied | 8c65ba3440b3 | 18ba078a9db3 | sim | nao local; conteudo/path historico nao reproduzido | estrutural ja presente | sim, deliberado | nao |
| 20260518120000 | applied | cbf61485ecea | b26948044432 | sim | nao local; conteudo/path historico nao reproduzido | estrutural ja presente | sim, deliberado | nao |
| 20260522000000 | pending | - | ac321c029af0 | sim | nova pendente | estrutural pendente | nao | nao, ja existe |
| 20260522010000 | pending | - | d9d34c1d4eac | sim | nova pendente | estrutural pendente | nao | nao, ja existe |
| 20260522020000 | pending | - | 606f4c61529e | sim | nova pendente | estrutural pendente | nao | nao, ja existe |
| 20260522030000 | pending | - | b01508ae028f | sim | nova pendente | estrutural pendente | nao | nao, ja existe |

## Verificacao estrutural das 8 migrations historicas nao reproduzidas

Foi checado o efeito esperado no schema atual. Em STAB.R3.1c, a mesma verificacao foi repetida em producao usando apenas consultas de leitura em `information_schema`. Todos os artefatos esperados existem:

| Migration | Efeito esperado | Resultado |
|---|---|---|
| 20260515000000 | 6 indices Vendor 360 | 6/6 presentes |
| 20260516000000 | 3 indices Overview | 3/3 presentes |
| 20260516000001 | 5 indices Product 360 | 5/5 presentes |
| 20260516000002 | 6 indices Geo Map | 6/6 presentes |
| 20260516000004 | 6 indices Arquitetura 10/10 | 6/6 presentes |
| 20260517000001 | tabela `omie_pivot2_faturamento_staging` ausente | ok |
| 20260517000002 | 10 colunas e 4 indices geo key | 14/14 presentes |
| 20260518120000 | 2 colunas e 1 indice geo quality/scope | 3/3 presentes |

## Matriz das 8 `content_mismatch`

Checksums truncados para 12 caracteres. Os valores completos estao na saida de `php bin/schema_migrate.php checksum-diagnose`.

Diff do arquivo atual vs versao esperada: a versao historica exata que gerou o checksum armazenado nao foi localizada nos commits alcancaveis. `git log` mostra esses arquivos como adicionados nos commits `15ade14`, `786906f` e `b6f1a11`, sem alteracao posterior rastreada em `database/migrations`. Assim, a diferenca fica classificada como historico/conteudo nao reproduzido, nao como drift estrutural confirmado.

| Migration | Checksum armazenado | Checksum v2 | Efeito estrutural encontrado | Tipo provavel | Risco | Recomendacao |
|---|---:|---:|---|---|---|---|
| 20260515000000 | 74e74729c8f3 | b18b99e38ad7 | 6/6 indices Vendor 360 presentes | conteudo/path historico nao reproduzido; possivel diferenca de encoding/comentario/formato | baixo | repair futuro permitido, documentado; sem migration corretiva |
| 20260516000000 | 9dc15463ed8f | 02ec0d49021b | 3/3 indices Overview presentes | conteudo/path historico nao reproduzido; possivel diferenca de encoding/comentario/formato | baixo | repair futuro permitido, documentado; sem migration corretiva |
| 20260516000001 | cdae0d577139 | 7e05385504f3 | 5/5 indices Product 360 presentes | conteudo/path historico nao reproduzido; possivel diferenca de encoding/comentario/formato | baixo | repair futuro permitido, documentado; sem migration corretiva |
| 20260516000002 | 6467f9d233b5 | cda478ae30d4 | 6/6 indices Geo Map presentes | conteudo/path historico nao reproduzido; possivel diferenca de encoding/comentario/formato | baixo | repair futuro permitido, documentado; sem migration corretiva |
| 20260516000004 | 16dc345fe950 | f7a94ed58d70 | 6/6 indices Arquitetura 10/10 presentes | conteudo/path historico nao reproduzido; SQL atual equivalente pelos artefatos | baixo | repair futuro permitido, documentado; sem migration corretiva |
| 20260517000001 | 81da1b52792f | a4826f8d43fa | tabela `omie_pivot2_faturamento_staging` ausente | conteudo/path historico nao reproduzido em migration destrutiva | medio | investigar origem se houver exigencia de auditoria da staging; repair futuro permitido se auditoria aceitar efeito atual |
| 20260517000002 | 8c65ba3440b3 | 22709105f8f1 | 10 colunas e 4 indices geo key presentes | conteudo/path historico nao reproduzido; schema atual completo | baixo | repair futuro permitido, documentado; sem migration corretiva |
| 20260518120000 | cbf61485ecea | d8773bcfda9f | 2 colunas e 1 indice geo quality/scope presentes | conteudo/path historico nao reproduzido; schema atual completo | baixo | repair futuro permitido, documentado; sem migration corretiva |

Decisao aplicada em STAB.R3.1e: atualizar apenas metadados de checksum das 34 migrations aplicadas e descobertas, com `reason` obrigatorio, backup antes do update e log depois do apply. Nao ha indicio atual de necessidade de migration corretiva para as 8, porque os artefatos estruturais esperados estao presentes.

## Conclusao operacional

1. As 34 migrations aplicadas e descobertas foram reconciliadas para checksum v2.
2. Nao reescrever migrations ja aplicadas.
3. O algoritmo path-dependent foi corrigido no codigo e os metadados das 34 aplicadas foram atualizados com backup previo.
4. `checksum-diagnose` foi rodado em producao e confirmou os mesmos numeros do local.
5. `verify` passou apos o apply, com 34 verificadas e 0 mismatches.
6. As 4 migrations pendentes continuam pendentes; nenhuma migration foi aplicada nesta fase.
7. O registro historico orfao `20260517000000` permanece intocado e deve ser tratado em fase propria de auditoria historica, se necessario.
8. Como os efeitos estruturais das 8 migrations historicas estao presentes, nao ha indicio neste diagnostico de necessidade de migration corretiva para elas. Se alguma diferenca funcional for encontrada depois, criar migration nova.

## Proximo passo recomendado

Com `verify` passando, a proxima fase pode voltar ao fluxo normal de decisao das 4 migrations pendentes. Antes de qualquer `migrate`, revisar o registro historico orfao `20260517000000` em uma microfase separada de auditoria, sem alterar schema.

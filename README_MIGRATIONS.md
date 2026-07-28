# Migrations - Guia de Rollout de Schema

## Objetivo

O BIomie passa a tratar `database/migrations/` como trilha principal de evolucao de schema.

Os `.sql` soltos continuam como referencia historica, mas o rollout oficial agora e:
- migration versionada
- tabela de controle `biomie_schema_migrations`
- status auditavel
- checksum verificavel
- rollback explicito por versao

## Estrutura

- PHP versionado em `database/migrations/Version*.php`
- SQL congelado por versao em `database/migrations/sql/`
- Runner CLI em [bin/schema_migrate.php](bin/schema_migrate.php)

## Conexoes

- `canonical`: usa `flight_plan_db` quando configurado; caso contrario cai para `db`
- `primary`: usa `db`

Isso permite versionar o schema canonico e o schema autenticado sem assumir que vivem no mesmo banco.

## Tipos de migration

- `expansive`: cria ou amplia estrutura sem remover legado
- `destructive`: remove estrutura antiga; usar so depois do corte
- `index`: adiciona indices
- `backfill`: recomputa ou preenche historico
- `data`: move ou ajusta dados

As migrations entregues nesta fase sao expansivas e baselineadas.
As fases seguintes podem acrescentar novas migrations expansivas, como a de observabilidade operacional.

## Comandos

```bash
php bin/schema_migrate.php status
php bin/schema_migrate.php migrate
php bin/schema_migrate.php migrate --target=20260413050300
php bin/schema_migrate.php rollback
php bin/schema_migrate.php rollback --version=20260413050500
php bin/schema_migrate.php verify
```

Via Composer:

```bash
composer migrate:status
composer migrate:up
composer migrate:down
composer migrate:verify
```

## Ordem recomendada de rollout

1. Rodar `status`
2. Rodar `verify` se o banco ja estiver em uso
3. Aplicar `migrate`
4. Validar tabelas e endpoints criticos
5. So depois promover novas leituras/escritas

## Runbook

### Local

1. Configure `DB_*` e, se houver, `flight_plan_db`
2. Rode `php bin/schema_migrate.php status`
3. Rode `php bin/schema_migrate.php migrate`
4. Rode a suite `php tests/run_phase2_coverage.php`

### Homologacao

1. Backup do banco
2. `status`
3. `verify`
4. `migrate`
5. Smoke dos crons e endpoints criticos

### Producao

1. Backup e janela de deploy
2. Aplicar migrations expansivas primeiro
3. Promover leitura/escrita nova
4. Observar logs, shadow validation e freshness
5. So depois agendar migrations destrutivas

## Rollback

- Use `rollback --version=...` para voltar uma versao especifica
- Se nenhuma versao for informada, o runner tenta voltar a ultima migration aplicada
- Rollback de baseline derruba as tabelas daquela migration; use apenas em ambiente controlado

## Relacao com o legado

`ensureSchema()` e afins ainda existem para compatibilidade e bootstrap defensivo.

Mas, a partir desta fase:
- rollout oficial = migrations
- `.sql` solto = referencia historica
- remocao de legado = depois que a trilha versionada estiver estabilizada

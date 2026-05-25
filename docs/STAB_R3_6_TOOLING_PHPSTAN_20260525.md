# STAB.R3.6 - Tooling, Composer/vendor, PHPStan e scripts quebrados

Data: 2026-05-25
Escopo: restaurar tooling de desenvolvimento e analise estatica, sem alterar regra comercial, schema ou dados de negocio.

## Resumo executivo

Estado inicial:

- `composer` nao estava no PATH local.
- `composer.phar` nao existia no repositorio.
- `vendor/` nao existia.
- `phpstan/phpstan` estava apenas no `composer.lock`.
- `tools/dev/data_coverage_audit.php` falhava com `Class "BIomie\Database" not found`.
- PHPStan nao podia rodar antes da restauracao do vendor.

Estado final:

- Composer temporario baixado fora do repositorio em `%TEMP%/biomie-composer/composer.phar`, com assinatura SHA384 do instalador validada.
- `composer install --no-interaction --prefer-dist` executado a partir do `composer.lock`.
- `vendor/` recriado localmente e continua ignorado por git.
- `vendor/bin/phpstan` disponivel.
- `vendor/bin/phpstan analyse app tools/dev bin --level=0` passa.
- `tools/dev/data_coverage_audit.php` roda e retorna cobertura `ok` para 2026-05.

Nenhum `migrate` foi executado. Nenhum schema foi alterado. Nenhuma migration foi editada. Nenhuma regra de Base Comercial NF, BiVisibilityScope, Licitacao, conciliacao ou FIN.R1/R2 foi alterada.

## Composer/vendor

Diagnostico:

| Item | Resultado |
|---|---|
| `composer --version` | ausente no PATH local |
| `php composer.phar --version` | ausente no repositorio |
| `composer.json` | presente |
| `composer.lock` | presente |
| `vendor/` inicial | ausente |
| `.gitignore` | contem `/vendor/` |

Composer usado:

```bash
php %TEMP%/biomie-composer/composer.phar --version
```

Resultado:

- Composer 2.9.8;
- PHP CLI local 8.5.4;
- instalador validado por SHA384 antes do uso.

Instalacao:

```bash
php %TEMP%/biomie-composer/composer.phar install --no-interaction --prefer-dist
```

Resultado:

- `phpstan/phpstan` 1.12.33 instalado pelo lock;
- `vendor/bin/phpstan`, `vendor/bin/phpstan.bat`, `vendor/bin/phpstan.phar` disponiveis;
- `composer.json` validado com sucesso;
- `composer.lock` nao precisou ser recriado.

## PHPStan

Primeira execucao ampla apos restaurar `vendor/`:

```bash
vendor/bin/phpstan analyse app tools/dev bin --level=0 --no-progress --memory-limit=1G
```

Resultado inicial:

- 449 apontamentos;
- 432 eram `ignore.unmatched`, causados por baseline antigo com erros que ja nao existem;
- 11 eram `classConstant.nativeTypeNotSupported`, porque o codigo usa `readonly class` e o PHPStan estava mirando PHP 8.1;
- 4 eram `class.notFound` para `BIomie\DataMasker`;
- 1 era `return.never` em `Auth::forbidden()`;
- 1 era `variable.undefined` em closure de `HomeBootstrapService`.

Correcoes aplicadas:

| Arquivo | Correcao | Classificacao |
|---|---|---|
| `tools/dev/data_coverage_audit.php` | bootstrap real com `CliGuard`, `Bootstrap::init()`, `Config` e `Logger` | bootstrap/autoload |
| `app/Modules/Sales/Application/Overview/OverviewDetailBuilder.php` | namespace correto `BIomie\Acl\DataMasker` e uso de `applyToRows()` | autoload real |
| `app/HomeBootstrapService.php` | closure de reconnect deixou de ser `static` para acessar `$this->config` | bug real pequeno |
| `app/Auth.php` | `exit` explicito apos renderizar pagina 403 | contrato `never` |
| `phpstan.shared.neon` | `phpVersion: 80200` e `reportUnmatchedIgnoredErrors: false` | tooling/config |

Resultado final:

```bash
vendor/bin/phpstan analyse app tools/dev bin --level=0 --no-progress --memory-limit=1G
```

Resultado: `PASS`, sem erros.

O comando exato pedido na validacao tambem passou:

```bash
vendor/bin/phpstan analyse app tools/dev bin --level=0
```

Resultado: `PASS`, sem erros.

Observacao: o PHPStan agora mira PHP 8.2 porque o proprio codigo ja usa `readonly class`, sintaxe indisponivel em PHP 8.1. A decisao de alinhar `composer.json`/`composer.lock` oficialmente para PHP >=8.2 deve ser uma microfase futura, para nao misturar contrato de runtime com esta fase de tooling.

## Scripts dev testados

| Script/comando | Resultado | Observacao |
|---|---|---|
| `php tools/dev/data_coverage_audit.php --month=2026-05 --json` | PASS | cobertura `ok`; erro de autoload eliminado |
| `php tools/dev/semantic_audit.php --json` | PASS | 29 metricas, 22 ativas, 7 draft, 0 erros |
| `php tools/dev/biomie_release_guard.php --start=2026-05-01 --end=2026-05-31 --pretty` | PASS | 9/9 dominios; D6 segue warning informacional do `grep` no Windows |
| `php tools/dev/finance_enrichment_diagnosis.php --start=2026-05-01 --end=2026-05-31 --pretty` | PASS | 579 titulos; 82 sem vendedor; 0 enriqueciveis nesta janela |
| `php tools/dev/geo_keys_backfill.php --dry-run --pretty` | PASS | 0 elegiveis, 0 conflitos, 0 updates |
| `php tools/dev/dead_letter_report.php --pretty` | PASS | fila vazia, DLQ 0, heartbeat/cron stale documentado |
| `php bin/schema_migrate.php status` | PASS | 38 descobertas, 38 aplicadas, 0 pendentes, 0 mismatches |
| `php bin/materialize_marts.php --start=2026-05-01 --end=2026-05-31 --dry-run` | PASS | dry-run usado para cumprir regra de nao alterar dados nesta fase |

O comando real de materializacao sem `--dry-run` nao foi executado nesta fase, porque STAB.R3.6 e uma fase de tooling e as regras proibem alteracao de dados/schema.

## Pendencias futuras

1. Decidir se `composer.json` deve mudar oficialmente de `php >=8.1` para `php >=8.2`, ja que o codigo atual usa `readonly class`.
2. Limpar ou regenerar `phpstan-baseline.neon` em uma fase propria, para reduzir ruido historico.
3. Avaliar nivel PHPStan superior a 0 depois de congelar o contrato de runtime.
4. Confirmar Composer no PATH da Hostinger ou documentar uso operacional de `composer.phar` em deploy.

## Validacoes finais

| Comando | Resultado |
|---|---|
| `php %TEMP%/biomie-composer/composer.phar validate --no-interaction` | PASS |
| `php -l tools/dev/data_coverage_audit.php` | PASS |
| `php -l app/Modules/Sales/Application/Overview/OverviewDetailBuilder.php` | PASS |
| `php -l app/HomeBootstrapService.php` | PASS |
| `php -l app/Auth.php` | PASS |
| `vendor/bin/phpstan analyse app tools/dev bin --level=0 --no-progress --memory-limit=1G` | PASS |
| `vendor/bin/phpstan analyse app tools/dev bin --level=0` | PASS |
| `php tools/dev/lint_all.php` | PASS; 465 arquivos, 0 falhas |
| `php bin/schema_migrate.php verify` | PASS; 38 verificadas, 0 mismatches |
| `php tools/dev/biomie_release_guard.php --start=2026-05-01 --end=2026-05-31 --pretty` | PASS geral; 9/9 dominios |
| `php healthz.php` | PASS; `ok=true` |
| `php tools/dev/system_health_check.php --json` | WARNING; sem CRITICAL |

# Diagnóstico do Projeto BIomie

Este arquivo explica as fontes de diagnóstico. Ele não registra manualmente
quantidades que podem mudar entre duas execuções.

## Estado das migrations

O estado vivo é gerado por:

```bash
php bin/schema_migrate.php status --format=json
```

Última captura sanitizada:
`docs/generated/migration-status.json`.

Para atualizar todas as capturas de release, sem aplicar migrations ou executar
retenção:

```bash
php tools/dev/generate_runtime_diagnostics.php
```

O comando atualiza:

- `docs/generated/migration-status.json`
- `docs/generated/phpstan-status.json`
- `docs/generated/guard-status.json`
- `docs/generated/schema-inventory.json`

## Onde ver o estado real

| Pergunta | Fonte viva |
| --- | --- |
| O release está saudável? | `composer release:guard` (55+ domínios; `--read-only` para auditoria sem mutação) |
| Migrations aplicadas? | `php bin/schema_migrate.php status` / `verify` |
| Empresas prontas (frescor/completude)? | `php tests/run_company_readiness.php` (exit 1 se qualquer empresa `critical`) |
| Isolamento multi-tenant? | `php bin/tenant_isolation_guard.php`, D48/D49/D53 (`bin/d*_guard.php`) |
| Sintaxe/PHPStan | `composer lint` / `composer phpstan-full` (zero erros fora do baseline) |
| Smoke das 5 empresas | `php bin/smoke_test_all_companies.php` |

## Convenções que este repositório segue

- Comando de migração é `migrate` (não existe `run`): `php bin/schema_migrate.php migrate`.
- Contrato do Plano de Voo: `docs/flight-plan-contract.md` (year_month + data_competencia;
  régua única de risco em `CommercialPlanning::RISK_*`).
- Agenda de cron canônica: `docs/hostinger-cron-setup.md` + scripts em `cron/hostinger/`.
  Qualquer outra listagem de cron em docs antigos é histórica.
- Assets: fontes em `assets/`, minificados gerados por `tools/dev/minify_assets.ps1`
  (rodar no deploy); `Asset::url()` serve o `.min` quando atualizado.

# RBAC Matrix - BIomie

## Permissoes oficiais

- Comercial: `sales.view`, `sales.sync`, `sales.export`
- Financeiro: `finance.view`, `finance.sync`, `finance.export`
- Fiscal: `fiscal.view`, `fiscal.sync`, `fiscal.export`
- Omie: `omie.view`, `omie.sync`
- Jobs: `jobs.view`, `jobs.run`
- Logs: `logs.view`
- Administracao: `admin.view`, `admin.manage`
- Configuracoes: `settings.view`, `settings.manage`
- Geo: `geo.view`, `geo.materialize`
- Dados sensiveis: `documents.full`

## Perfis MAG

- Viewer: leitura comercial e geo.
- Analyst: leitura comercial, financeira, fiscal, geo, Omie e jobs.
- Manager: leitura ampla, sincronizacao comercial, exportacoes, materializacao geo, jobs e logs.
- Admin: todas as permissoes oficiais e aliases legados.

## Aliases legados

- `dashboard.view` e `commercial.view` => `sales.view`
- `financial.view` => `finance.view`
- `nf.view` e `reconciliation.view` => `fiscal.view`
- `admin.logs` e `observability.view` => `logs.view`
- `admin.users` e `users.manage` => `admin.manage`
- `admin.migrations` e `admin.console` => `settings.manage`
- `export` continua como alias generico para exports por dominio.

## Protecao server-side

Menus e botoes podem esconder acoes, mas nao autorizam acesso. Cada pagina ou API sensivel precisa chamar `Auth::requireCapability()` ou `Auth::requireAnyCapability()` no backend.

Validacao estatica:

```bash
php tools/dev/rbac_audit.php
```

Testes manuais apos banco/sessao:

- Viewer em `/financeiro.php` deve receber `403`.
- Analyst em `/financeiro.php` deve acessar.
- Analyst em `api/finance_sync.php` deve receber `403`.
- Admin em `api/finance_sync.php` deve acessar.
- Viewer em `api/sync.php` deve receber `403`.
- Manager em `api/sync.php` deve acessar se tiver `sales.sync`.
- Usuario sem `logs.view` em `api/admin_health.php` deve receber `403`.


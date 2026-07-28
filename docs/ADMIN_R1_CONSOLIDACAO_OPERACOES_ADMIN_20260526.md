# ADMIN.R1 - Consolidacao das operacoes tecnicas no Admin

Data: 2026-05-26

## Objetivo

Reduzir poluicao visual e concentrar paginas tecnicas em `admin.php`, usando o shell visual novo e permissao administrativa.

## Mapa de rotas

| Rota antiga | Destino novo |
| --- | --- |
| `system_health.php` | `admin.php?tab=system-health` |
| `integracoes.php` | `admin.php?tab=integrations` |
| `metric_catalog.php` | `admin.php?tab=metrics` |
| `cockpit_interno.php` | `admin.php?tab=cockpit` |

As rotas antigas continuam existindo como redirect 302 protegido por `admin.view` ou `admin.manage`.

## Abas criadas

- Saude do Sistema
- Integracoes Omie
- KPIs
- Cockpit

As abas ficam dentro de `views/admin.php` e usam partials:

- `views/admin/tabs/system-health.php`
- `views/admin/tabs/integrations.php`
- `views/admin/tabs/metric-catalog.php`
- `views/admin/tabs/cockpit.php`

## Sidebar

Itens tecnicos ocultados do menu principal:

- Conexao Omie
- Observabilidade
- Cockpit Interno MAG
- Catalogo de KPIs
- Saude do Sistema

O acesso operacional passa por `Administracao`.

## Icones crus

A causa provavel era renderizacao de paginas tecnicas por shells/assets diferentes. Ao consolidar no Admin, as telas passam a usar o mesmo carregamento de Material Symbols e o mesmo shell.

## Decisoes de UX

- Saude mostra criticos/warnings primeiro e detalhes em `details`.
- Integracoes mostra comandos e logs em blocos recolhiveis.
- Catalogo de KPIs tem busca compacta e tabela unica.
- Cockpit virou hub tecnico, sem duplicar todo o catalogo/saude.

## Guard

Adicionado D13 no release guard:

- valida abas do Admin;
- valida redirects protegidos;
- valida que rotas antigas nao renderizam shell proprio;
- valida que sidebar nao expoe paginas tecnicas;
- valida wrappers cron com logs e lock.

Resultado observado:

`D13 Admin Operations Consolidation: PASS`

## Smoke HTTP local

Servidor local temporario:

```bash
php -S 127.0.0.1:8000 -t .
```

Resultados sem sessao autenticada:

- `healthz.php`: HTTP 200
- `admin.php?tab=system-health`: HTTP 302 para login
- `admin.php?tab=integrations`: HTTP 302 para login
- `admin.php?tab=metrics`: HTTP 302 para login
- `admin.php?tab=cockpit`: HTTP 302 para login
- rotas antigas: HTTP 302 para login antes do redirect operacional, preservando protecao de auth

O D13 cobre estaticamente que, apos auth admin, as rotas antigas apontam para as novas abas `admin.php?tab=...`.

## Checklist manual

1. `admin.php` abre.
2. Aba Saude funciona.
3. Aba Integracoes funciona.
4. Aba KPIs funciona.
5. Aba Cockpit funciona.
6. Rotas antigas redirecionam.
7. Sidebar fica limpa.
8. Icones crus nao aparecem.

Observacao: a validacao visual autenticada deve ser feita no navegador com usuario admin, porque o smoke HTTP local sem cookie confirma apenas protecao/roteamento inicial.

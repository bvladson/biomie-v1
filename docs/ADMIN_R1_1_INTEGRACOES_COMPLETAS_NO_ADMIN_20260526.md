# ADMIN.R1.1 - Integracoes completas no Admin

Data: 2026-05-26

## Objetivo

Restaurar a riqueza operacional da antiga `integracoes.php` dentro de `admin.php?tab=integrations`, mantendo o shell Admin limpo, sem sidebar duplicada e sem poluicao visual.

## Estado anterior

A consolidacao ADMIN.R1 moveu as paginas tecnicas para o Admin, mas a aba Integracoes ficou resumida demais. A tela antiga tinha informacoes importantes para operacao:

- status da integracao;
- base de dados sincronizada;
- status Geo/CNAE;
- operacao Omie por dominio;
- rate limit, cache e fila;
- worker/fila;
- comandos esperados;
- acoes recomendadas;
- ultimas sincronizacoes;
- status das tabelas;
- resposta tecnica.

## Ajustes implementados

### Aba Admin > Integracoes

`views/admin/tabs/integrations.php` agora renderiza uma tela operacional completa com blocos:

1. Status da integracao
2. Base sincronizada
3. Operacao por dominio
4. Rate limit, cache e fila
5. Wrappers cron Hostinger
6. Acoes recomendadas
7. Ultimas sincronizacoes
8. Status das tabelas
9. Resposta tecnica colapsavel

O conteudo e carregado por `views/admin/partials/admin-js.php`, usando:

- `api/omie_status.php`
- `api/cron_wrapper_status.php`

### Cron wrapper status

Criado endpoint read-only:

```text
api/cron_wrapper_status.php
```

Permissoes aceitas:

- `jobs.view`
- `logs.view`
- `admin.view`

O endpoint mostra:

- rotina;
- frequencia sugerida;
- comando esperado;
- arquivo de log;
- ultima atualizacao do log;
- status detectado;
- ultimas linhas do log.

Nao ha `SELECT *` e nao ha escrita em banco.

### UX

Decisoes:

- cards compactos para leitura rapida;
- detalhes tecnicos em blocos colapsaveis;
- resposta JSON fechada por padrao;
- comandos e logs separados de KPIs;
- sem shell duplicado;
- sem topbar/sidebar extra;
- sem nomes crus de icones.

### Rotas antigas

`integracoes.php` continua protegida e redireciona para:

```text
admin.php?tab=integrations
```

Bookmarks antigos continuam funcionando sem expor uma segunda tela tecnica.

## Release Guard

Adicionado dominio:

- `D15 - Admin Integrations Completeness`

O D15 verifica:

- existencia de `api/cron_wrapper_status.php`;
- existencia da aba `admin.php?tab=integrations`;
- blocos principais da aba;
- consumo de `api/omie_status.php`;
- consumo de `api/cron_wrapper_status.php`;
- ausencia de shell duplicado na partial;
- presenca de resposta tecnica colapsavel.

## Permissoes

A aba fica dentro do Admin. O endpoint de wrapper exige permissao operacional/admin. A intencao e manter paginas tecnicas fora da navegacao comercial comum.

## Checklist manual

1. Abrir `admin.php?tab=integrations`.
2. Confirmar cards de status da integracao.
3. Confirmar base sincronizada.
4. Confirmar operacao por dominio.
5. Confirmar rate limit/cache/fila.
6. Confirmar wrappers cron com logs.
7. Confirmar ultimas sincronizacoes.
8. Confirmar status das tabelas.
9. Abrir resposta tecnica.
10. Confirmar que `integracoes.php` redireciona.
11. Confirmar que nao ha icones crus.

## Decisoes

- Nao houve migration.
- Nao houve alteracao de schema.
- Nao houve alteracao de regra comercial/fiscal/financeira.
- A tela antiga nao foi apagada; foi mantida como redirect protegido.


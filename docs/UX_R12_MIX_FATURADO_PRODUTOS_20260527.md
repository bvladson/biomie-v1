# UX.R12 - Mix Faturado e Drilldown de Produto

Data: 2026-05-27

## Objetivo

Limpar a pagina `mercadorias.php` para que o Mix Faturado padrao represente apenas Orc-PD comercial, sem Licitacao e sem Remessas, e tornar cada produto investigavel por drawer.

## Diagnostico

`tools/dev/product_mix_scope_audit.php --start=2026-05-01 --end=2026-05-31 --pretty` confirmou:

- Filtro oficial Orc-PD: `licitacao_leaks=0`
- Filtro oficial Orc-PD: `remittance_leaks_in_orc_pd=0`
- O filtro legado baseado apenas em `operation_scope='commercial'` incluiria 1 NF / 1 produto de Licitacao, R$ 78.207,50
- Remessas existem no periodo e somam R$ 73.817,01, agora separadas do Orc-PD

Causa: `ProductMixReadRepository` aplicava `operation_scope='commercial'`, mas ainda nao reaproveitava o `BiVisibilityScope`. Como Licitacao pode ser `operation_scope='commercial'`, isso deixava o Mix Faturado vulneravel a vazamento de escopo.

## Correcoes

- `ProductMixReadRepository` passou a aplicar `BiVisibilityScope` em resumo, ranking e breakdown.
- `api/products.php` agora aceita `scope=commercial|remittance|all`.
- `api/products_search.php` usa o mesmo escopo e mantem visibilidade comercial.
- A tela ganhou seletor `Orc-PD`, `Remessas`, `Todas`.
- `Todas` combina Orc-PD + Remessas, mas continua excluindo Licitacao.
- Graficos de barras foram substituidos por doughnuts Top 10 + Outros.
- Legendas dos doughnuts sao clicaveis e abrem o drawer do produto.
- A tabela ficou mais compacta e cada linha abre o drawer.
- O drawer mostra KPIs, clientes compradores, vendedores e NFs do produto.

## O que nao foi alterado

- Nenhuma migration.
- Nenhum schema.
- Nenhum dado fiscal/comercial.
- Nenhum raw/bronze/canonical manual.
- Nenhuma regra de Base Comercial NF.
- Nenhuma regra de conciliacao NF x titulo.
- Nenhum sync, worker, cron ou materializacao.

## Guards

- `tools/dev/product_mix_scope_audit.php` criado para diagnostico read-only.
- `tools/dev/visual_layout_guard.php` atualizado com checks UX.R12.
- `tools/dev/biomie_release_guard.php` recebeu D16 Product Mix Scope.

## Validacoes

Comandos esperados:

```bash
php tools/dev/product_mix_scope_audit.php --start=2026-05-01 --end=2026-05-31 --pretty
php tools/dev/visual_layout_guard.php --pretty
php tools/dev/lint_all.php
php bin/schema_migrate.php verify
php tools/dev/biomie_release_guard.php --start=2026-05-01 --end=2026-05-31 --pretty
php healthz.php
vendor/bin/phpstan analyse app tools/dev bin --level=0 --memory-limit=1G
```

## Smoke visual

URL:

`mercadorias.php?start=2026-05-01&end=2026-05-31`

Checklist:

- Orc-PD nao exibe Licitacao.
- Orc-PD nao exibe Remessas.
- Remessas aparece em chip separado.
- Todas mostra Orc-PD + Remessas com aviso.
- Doughnuts mostram Top 10 + Outros.
- Fatiar/legenda/linha abre drawer do produto correto.
- Drawer mostra NFs, clientes e vendedores.
- Tabela ficou compacta.

## UX.R12.1 - Hotfix visual

Problema observado apos a primeira publicacao:

- O grid generico `charts-grid` deixava um doughnut largo demais e o outro espremido.
- A legenda competia com o canvas em vez de ficar em uma area controlada.
- Clique em fatia/legenda abria o drawer, mas clique na linha da tabela nao abria o produto correto.
- A tabela ainda carregava ruido visual do estado legado de detalhamento.

Correcoes aplicadas:

- A area dos doughnuts passou a usar `product-chart-grid` / `product-donut-grid` com duas colunas equilibradas.
- Cada doughnut usa canvas fixo/controlado e legenda com rolagem interna.
- As linhas da tabela recebem `data-product-key`, `data-product-code` e `data-product-label`.
- `assets/js/app.js` recebeu delegacao de clique e teclado para abrir o drawer pelo produto clicado na tabela.
- A frase de indisponibilidade permanece fora da linha principal para nao poluir a leitura.

Validacao visual esperada:

- Os dois doughnuts ocupam espaco equivalente no desktop.
- A legenda fica legivel sem espremer o grafico.
- Clique em linha, fatia ou legenda abre o mesmo drawer do produto correto.
- Licitacao continua fora do Orc-PD e Remessas continuam em escopo separado.

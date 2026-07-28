# FIN.R3 - Carteira de Cobranca por Vendedor

Data: 2026-05-25

## Resumo

FIN.R3 adicionou uma carteira operacional de cobranca dentro do financeiro, baseada exclusivamente em titulos financeiros canonicos enriquecidos.

Arquivos criados:

- `api/finance_collection_by_vendor.php`
- `app/Support/Filters/Presets/FinanceCollectionFilters.php`
- `assets/js/modules/finance/collection-by-vendor.js`

Arquivos alterados:

- `views/financeiro.html`
- `app/PageAssetRegistry.php`
- `assets/css/finance-realizado.css`

## Decisoes

- A carteira considera somente titulos em aberto: status nao liquidado/recebido/pago e `valor_aberto > 0`.
- O periodo usa `data_vencimento`, porque a pergunta operacional e cobranca.
- Titulos sem vendedor ficam agrupados como `Sem vendedor definido`.
- Nenhum titulo sem vendedor foi redistribuido ou inferido artificialmente.
- A acao recomendada para sem vendedor e `Revisar vinculo financeiro`.
- A implementacao nao altera Base Comercial NF, BiVisibilityScope, Licitacao, conciliacao, FIN.R1/R2, raw/bronze, schema ou migrations.

## API

Endpoint:

```bash
api/finance_collection_by_vendor.php
```

Filtros aceitos:

- `start`, `end`
- `q`
- `filters[vendedor]`
- `filters[cliente]`
- `status`
- `aging`
- `valor_min`, `valor_max`
- `only_overdue`
- `only_no_vendor`
- `page`, `per_page`
- `sort`, `direction`

Saida:

- KPIs da carteira
- ranking por vendedor
- aging buckets
- tabela paginada de titulos acionaveis

## UI

O bloco `Cobranca por vendedor` foi incluido antes da busca server-side de titulos, sem remover ou alterar os cards/tabelas existentes do overview financeiro.

Inclui:

- KPIs executivos de cobranca, sem repetir os cards financeiros gerais do topo
- resumo financeiro compacto da carteira
- filtros compactos e chips com restore via URL (`fcol_*`)
- quick filters
- ranking por vendedor
- aging buckets
- tabela acionavel paginada

## FIN.R3.1 - Polimento visual

Microfase executada antes de commit/tag final para reduzir redundancia visual sem alterar regra, API ou dados.

Ajustes aplicados:

- removidos do bloco principal os cards repetidos `Total em aberto`, `Total vencido` e `A vencer`
- mantidos como KPIs da carteira: vendedores, clientes, titulos sem vendedor, valor sem vendedor, maior atraso e percentual vencido
- valores financeiros da carteira ficaram em uma linha compacta no cabecalho
- filtros da carteira foram compactados dentro da propria secao
- `Titulos financeiros` antigo foi movido para um bloco recolhivel, mantendo a visao bruta disponivel sem competir com `Titulos acionaveis`
- coluna `NF / Pedido` passou a usar referencias curtas com `title` contendo o valor completo
- estilos responsivos adicionados para reduzir densidade e preservar mobile basico

Sem alteracao em:

- calculos da API FIN.R3
- Base Comercial NF
- BiVisibilityScope
- Licitacao
- conciliacao
- FIN.R1/R2
- schema, migrations, raw ou bronze

## Validacoes

Executadas com sucesso:

```bash
php -l api/finance_collection_by_vendor.php
php -l app/Support/Filters/Presets/FinanceCollectionFilters.php
php -l app/PageAssetRegistry.php
node --check assets/js/modules/finance/collection-by-vendor.js
php tools/dev/lint_all.php
php bin/schema_migrate.php verify
php tools/dev/biomie_release_guard.php --start=2026-05-01 --end=2026-05-31 --pretty
php healthz.php
vendor/bin/phpstan analyse app tools/dev bin --level=0 --memory-limit=1G
```

Resultado:

- lint_all: PASS, 467 arquivos
- schema_migrate verify: PASS, 38 verificadas, 0 mismatches
- release_guard: PASS, 9/9 dominios
- healthz: ok=true
- system_health_check: WARNING, sem CRITICAL
- PHPStan nivel 0: PASS com `--memory-limit=1G`

Observacao: `vendor/bin/phpstan analyse app tools/dev bin --level=0` sem limite explicito estourou o `memory_limit` local de 128M antes de concluir. O mesmo escopo passou com `--memory-limit=1G`, que ja e o padrao usado pelos scripts Composer do projeto.

Revalidacao apos FIN.R3.1:

- `node --check assets/js/modules/finance/collection-by-vendor.js`: PASS
- `php -l api/finance_collection_by_vendor.php`: PASS
- `php -l app/Support/Filters/Presets/FinanceCollectionFilters.php`: PASS
- `php -l app/PageAssetRegistry.php`: PASS
- `php tools/dev/lint_all.php`: PASS, 467 arquivos
- `php bin/schema_migrate.php verify`: PASS, 38 verificadas, 0 mismatches
- `php tools/dev/biomie_release_guard.php --start=2026-05-01 --end=2026-05-31 --pretty`: PASS, 9/9 dominios
- `php healthz.php`: ok=true
- `vendor/bin/phpstan analyse app tools/dev bin --level=0 --memory-limit=1G`: PASS
- `php tools/dev/system_health_check.php --json`: WARNING esperado, sem CRITICAL

## Smoke local autenticado

Foi criada uma sessao PHP local temporaria somente para smoke HTTP, sem alterar banco, schema ou dados de negocio.

Validado em `http://127.0.0.1:8000/financeiro.php`:

- pagina autenticada respondeu HTTP 200
- bloco `Cobranca por vendedor` presente no HTML
- `financeCollectionSection` presente no HTML
- asset `assets/js/modules/finance/collection-by-vendor.js` registrado na pagina
- busca server-side existente `financeSearchSection` continuou presente
- apos FIN.R3.1, `financeRawTitlesDetails` presente como bloco recolhivel
- apos FIN.R3.1, KPIs repetidos da carteira (`fcTotalOpen`, `fcTotalOverdue`, `fcTotalDue`) nao aparecem mais no HTML
- apos FIN.R3.1, `fcPortfolioCompact`, `fcNoVendorValueCard` e `fcOverduePct` presentes
- overview antigo `api/finance_overview.php` respondeu `ok=true`

Validado em `api/finance_collection_by_vendor.php` para maio/2026:

- total em aberto: 210692,77
- total vencido: 156162,13
- total a vencer: 54530,64
- titulos em aberto: 70
- titulos vencidos: 48
- `Sem vendedor definido`: 25 titulos, 104743,47
- `only_no_vendor=1`: retorna apenas `Sem vendedor definido` com acao `Revisar vinculo financeiro`
- filtro por vendedor (`CAIO`) respondeu 20 titulos
- filtro por vencido + aging `16_30` respondeu 5 titulos
- busca por NF/pedido (`q=232605`) respondeu 35 titulos
- paginacao/ordenacao por `valor_aberto` respondeu `page=2` corretamente

Ponto corrigido durante o smoke: valores literais `Sem vendedor` vindos da base canonica agora sao classificados como pendencia de vendedor e exibidos como `Sem vendedor definido`.

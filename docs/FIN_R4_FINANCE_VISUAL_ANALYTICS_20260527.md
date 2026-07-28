# FIN.R4 - Finance Visual Analytics

Data: 2026-05-27

## Objetivo

Transformar `financeiro.php` em uma leitura mais visual e acionavel, mantendo os titulos, a carteira por vendedor e a governanca existentes como fontes de detalhe.

## Fontes de dados

- `biomie_titulos_financeiros_canonicos`
- `biomie_admin_logs` com `event_type = finance.collection_action`
- `api/finance_overview.php`
- `api/finance_collection_by_vendor.php`
- `api/finance_collection_governance.php`

Nenhum dado financeiro original foi alterado.

## Implementado

- `api/finance_visual_analytics.php`
- `app/Support/FinanceVisualAnalyticsBuilder.php`
- `tools/dev/finance_visual_analytics_audit.php`
- `assets/js/modules/finance/visual-analytics.js`
- Nova secao `Financeiro visual` em `views/financeiro.html`
- Drawer `financeInsightDrawer` para detalhes de graficos e KPIs
- D17 `Finance Visual Analytics` no release guard

## Graficos

- Doughnut: composicao da carteira em Recebido, Vencido e A vencer.
- Grafico misto: barras de recebido e linhas de aberto/vencido.
- Barras horizontais: aging por faixa.
- Ranking horizontal: cobranca por vendedor.
- Pareto: clientes de maior risco.
- Doughnut: governanca da cobranca.

## Drawers

Cliques em KPIs, fatias, barras, vendedores, clientes e blocos de governanca abrem um drawer com:

- valor do recorte;
- quantidade de titulos;
- maior atraso;
- titulos compactos com cliente, vendedor, vencimento, atraso, valor e acao recomendada;
- diagnostico tecnico recolhivel.

## O que nao foi alterado

- Nenhuma migration.
- Nenhum schema.
- Nenhum raw/bronze.
- Nenhum valor/status financeiro original.
- Nenhuma regra FIN.R1/R2/R3.
- Nenhuma conciliacao NF x titulo.
- Nenhum sync, cron, worker ou materializacao.

## Validacoes

Comandos:

```bash
php tools/dev/finance_visual_analytics_audit.php --start=2026-05-01 --end=2026-05-31 --pretty
php tools/dev/visual_layout_guard.php --pretty
php tools/dev/biomie_release_guard.php --start=2026-05-01 --end=2026-05-31 --pretty
php healthz.php
vendor/bin/phpstan analyse app tools/dev bin --level=0 --memory-limit=1G
```

Checklist visual:

- A secao `Financeiro visual` aparece acima das analises antigas.
- Composicao, evolucao, aging, vendedor, clientes de risco e governanca renderizam.
- Cliques em cards/graficos abrem o drawer.
- Tabelas antigas continuam acessiveis no bloco recolhivel.

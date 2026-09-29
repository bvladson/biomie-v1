# Licitação nos números + regra de canal por vendedor — release 29/09/2026

Branch `feat/licitacao-nos-numeros-20260929`.

## Por que

Uni Design, setembro/2026: 33 NFs autorizadas, R$ 46.710,95 (fecha ao centavo com o
relatório Omie "Faturamento por Mês/Ano"). O painel mostrava **R$ 0,00**: o único contexto do
usuário na empresa era "Licitação" e as NFs são varejo. O dado existia; o contexto escondia.

## Regra de negócio (definida pelo dono do BIomie)

- Nenhuma venda comercial válida some por causa do canal. Empresa abre em
  **"Todas as operações"**; Varejo/Licitação e vendedor são **filtros independentes**.
- Canal de uma NF/pedido, nesta ordem:
  1. override manual (`bin/channel_override.php`) → `manual_override`
  2. cliente é empresa do grupo → `outros` / `intra_grupo` — **interrompe; não importa o vendedor**
  3. código Omie do vendedor "Licitação" da empresa → `licitacao` / `seller_licitacao`
  4. nome do vendedor normalizado = LICITACAO (fallback) → `licitacao` / `seller_licitacao`
  5. qualquer outro vendedor → `varejo` / `seller_regular`; sem vendedor → `varejo` / `seller_missing`
- Não se infere canal por cliente, CNAE, órgão público, observação (pregão/edital/empenho) ou projeto.
- Nome por **igualdade** normalizada: "LICITAÇÃO", "licitacao", " Licitação " casam;
  "Licitante", "Licitações", "João Licitação" não.
- Vendedor atribuído no BIomie (NF/pedido sem vendedor no Omie): Admin → Vendedor ausente.
  Muda só o BI; origem ganha `:atribuido_bi`; se o Omie passar a informar vendedor, o Omie vence.

Códigos do vendedor "Licitação": MAG Filial 8766069381 · Expert 8766069241 ·
Uni Design 10118842872 · MAG Matriz 10432605713 · Ergo 7467611512.

## Migrações (todas reversíveis)

| Versão | O quê | down() |
|---|---|---|
| 20260929120000 | vínculos usuário×empresa com os 2 canais | restaura de `biomie_user_companies_canais_20260929` |
| 20260929130000 | regras de canal por vendedor em todas as empresas | restaura de `biomie_channel_rules_20260929` |
| 20260929140000 | tabela `biomie_seller_assignments` | devolve o vendedor original e remove a tabela |

## Testes

```
Application regression: 0
Known environment failures: 12
```

As 12 falham igual no código de produção (baseline `1561661`) neste ambiente — mesma lista,
suíte por suíte. (Uma rodada intermediária mostrou 11: um dado de teste esquecido no banco fazia
`run_quote_refresh_cooldown` passar; com o banco limpo, volta a falhar como no baseline.) Causas: banco de teste
sem dados (readiness, quote_*, tenant_isolation), sem chave de cifra (multitenant_foundation),
suítes que esperam o banco de produção (test_database_guard, csrf, db_error_envelope,
migration_pending) e dois checks estáticos antigos de geo (geo_summary, sales_by_neighborhood).

Novos/estendidos: `run_mixed_company_context` (46), `run_licitacao_seller_rule` (36),
`run_seller_assignment` (17).

## Runbook

```
# 1. deploy (máquina local, pasta biomie-git)
BIOMIE_VPS_KEY=~/.ssh/hostinger_srv1851839_ed25519 bash tools/deploy/vps/deploy.sh --skip-minify
# 2. VPS, /var/www/biomie/live
sudo -u www-data php bin/schema_migrate.php migrate
# 3. simulação oficial (somente leitura) + revisão do CSV
sudo -u www-data php bin/reclassify_unclassified_invoices.php --company=all --todas --pedidos --csv=/tmp/reclass.csv
# 4. aplicar
sudo -u www-data php bin/reclassify_unclassified_invoices.php --company=all --todas --pedidos --apply
# 5. rematerializar 2026 por empresa (MAG_FILIAL, EXPERT, UNI_DESIGN, MAG_MATRIZ, ERGOOFFICE)
sudo -u www-data php bin/biomie gold:materialize-commercial --company=UNI_DESIGN --from=2026-01-01 --to=2026-09-30
```

Deploy aprovado ≠ dados reconciliados: os checks abaixo são obrigatórios depois do passo 5.

## Checks de aceite (depois de rematerializar)

Base comercial NF (autorizada, escopo comercial). Valores "antes" medidos em produção em 29/09.

| Check | Esperado |
|---|---|
| Uni Design set/26 · Todas | R$ 46.710,95 |
| Uni Design set/26 · Varejo | R$ 46.710,95 |
| Uni Design set/26 · Licitação | R$ 0,00 |
| Uni Design set/26 · ROBERTO SIQ. + CAIO - REV | R$ 2.938,00 + R$ 43.772,95 = R$ 46.710,95 |
| Uni Design jul/26 · Todas | R$ 341.317,50 → **R$ 11.137,50** (NF 26, R$ 330.180,00, vira intra-grupo) |
| Uni Design jun/26 · Todas | R$ 23.243,00 → **R$ 23.243,00** (só troca de coluna: licitação → varejo) |
| MAG Filial jul/26 · Todas | R$ 1.134.892,56 → **R$ 1.126.913,19** (NF 1851, R$ 7.979,37, vira intra-grupo) |
| Expert e Ergo · Todas, todos os meses | **inalterados** (NF 1 da Expert e NF 270 da Ergo só trocam de coluna) |
| Toda empresa/mês | Varejo + Licitação = Todas |
| Toda empresa/mês | soma dos vendedores = Todas |

Regra (cobertos por teste, conferir em produção por amostragem):

- cliente público + ROBERTO → varejo; cliente público + NUBIA → varejo
- cliente público + vendedor Licitação → licitação; cliente privado + vendedor Licitação → licitação
- observação com pregão/edital/empenho não muda o canal
- empresa do grupo + vendedor Licitação → intra-grupo; empresa do grupo + ROBERTO → intra-grupo

## Fora desta release (próximos passos)

- `CommercialFilterSet` global: empresa + período + canal + vendedor produzindo o mesmo
  universo em Dashboard, Plano de Voo, Vendedores, Geografia, CNAE, Financeiro e Cockpit.
  Hoje não passam pelo filtro de vendedor diretamente: `geo_*`, `sales_by_city/neighborhood`,
  `top_clients_by_cnae`, `finance_overview`, `cockpit_summary`.
- Indicador de saúde dos dados (NFs/pedidos sem vendedor, operações não classificadas).
- MAG Filial: 142 NFs de abril ausentes da canônica; Ergo: 55 NFs com operação `unknown`
  (R$ 334 mil) — ambos exigem nova busca no Omie.

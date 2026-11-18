# Licitação nos números + regra de canal por vendedor — release 29/09/2026

Branch `feat/licitacao-nos-numeros-20260929`.

## Por que

Uni Design, setembro/2026: 33 NFs autorizadas, R$ 46.710,95 (fecha ao centavo com o
relatório Omie "Faturamento por Mês/Ano"). O painel mostrava **R$ 0,00**: o único contexto do
usuário na empresa era "Licitação" e as NFs são varejo. O dado existia; o contexto escondia.

## Causa raiz do incidente

Uni Design aparecia zerada porque o usuário estava restrito ao contexto **Licitação**, enquanto
as vendas de setembro estavam classificadas como **Varejo**. Além disso, havia filtro ativo de
vendedor **"Licitação"**. O dado existia, mas o contexto e os filtros ocultavam o faturamento.

Fatos medidos em produção (29/09/2026, somente leitura):

- As 33 NFs de set/26 da Uni Design estão na base canônica, todas `varejo`, visíveis, somando
  R$ 46.710,95 — ao centavo do relatório Omie. Nenhuma NF faltava.
- O usuário `vlad` tinha, na Uni Design, `can_licitacao = 1` e `can_varejo = 0`. O acesso por
  canal foi semeado a partir do `canal_padrao` da empresa (migração 20260621030000): empresa
  "licitação" ⇒ só o contexto Licitação. O mesmo valia para Expert, MAG Matriz e Ergo.
- No contexto Licitação o painel filtra `business_channel = 'licitacao'` ⇒ R$ 0,00. O painel
  "Leitura da operação" usa o universo varejo + licitação e mostrava R$ 46.931 no mesmo mês —
  duas telas, dois universos.
- O filtro salvo "Vendedor: Licitação" zerava também o contexto certo: o vendedor Licitação não
  teve venda na Uni Design em setembro.
- Agravantes encontrados na investigação: os 50 pedidos da Uni Design herdavam o canal do
  contexto do sync (`licitacao`) enquanto as NFs deles eram varejo; 257 NFs gravadas entre 30/06
  e 23/07 nunca passaram pelas regras de canal (entre elas a NF 26, R$ 330.180,00, venda
  intra-grupo contada como receita).

Por isso a correção é de arquitetura, não de acesso: canal deixa de decidir se a venda existe e
passa a ser um filtro, como o vendedor.

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
- Vendedor da operação, nesta ordem: intra-grupo decide o canal antes de tudo; depois o
  vendedor vindo do Omie; depois a atribuição no BIomie (só se o Omie estiver sem vendedor);
  por fim `seller_missing` (Omie não enviou vendedor) ou `seller_unresolved` (enviou código
  que o cadastro não resolve — "vendedor desconhecido").
- Atribuição no BIomie: Admin → Vendedor ausente. Muda só o BI; se o Omie passar a informar
  vendedor, o Omie vence (atribuição vira `superada`). Não altera "Todas": só redistribui
  (sem vendedor → vendedor; no máximo varejo ↔ licitação se o escolhido for "Licitação").
- Duas origens em campos separados: `visibility_rule_source` = por que o CANAL
  (`seller_licitacao`, `seller_regular`, `seller_missing`, `seller_unresolved`, `intra_grupo`,
  `manual_override`); `seller_source` = de onde veio o VENDEDOR (`omie`, `bi`, `missing`,
  `unresolved`). Enquanto ninguém atribui: canal varejo, vendedor "Sem vendedor", incluído em "Todas".

Códigos do vendedor "Licitação": MAG Filial 8766069381 · Expert 8766069241 ·
Uni Design 10118842872 · MAG Matriz 10432605713 · Ergo 7467611512.

## Migrações (todas reversíveis)

| Versão | O quê | down() |
|---|---|---|
| 20260929120000 | vínculos usuário×empresa com os 2 canais | restaura de `biomie_user_companies_canais_20260929` |
| 20260929130000 | regras de canal por vendedor em todas as empresas | restaura de `biomie_channel_rules_20260929` |
| 20260929140000 | tabela `biomie_seller_assignments` + coluna `seller_source` em NFs e pedidos (histórico preenchido: omie/missing/unresolved) | devolve o vendedor original, remove a tabela e a coluna |
| 20260929150000 | desativa as regras de exclusão dura da MAG Filial com motivo "Remocao solicitada: dados de Licitacao" (08/06/2026) | reativa as mesmas regras |

### MAG Filial: a licitação era descartada antes de entrar

Em 08/06/2026 foram criadas regras em `biomie_data_exclusion_rules` (MAG Filial): vendedor
"Licitação" (código 8766069381) + 11 NFs, 11 pedidos, 4 títulos e 7 XMLs. A escrita canônica
descarta tudo que casa com elas antes de gravar — nenhum contexto ou filtro mostrava a licitação
da Filial. No bronze: 7 NFs de licitação descartadas entre 01/07 e 23/09/2026 (Total NF):

| NF | Emissão | Cliente | Total NF |
|---|---|---|---|
| 1728 | 01/07 | Município de Paracuru | R$ 323.000,00 |
| 1742 | 02/07 | Prefeitura de Fortaleza | R$ 9.870,00 |
| 1895 | 20/07 | Correios | R$ 15,00 |
| 2115 | 10/08 | Correios | R$ 252,50 |
| 2285 | 24/08 | Município de Acaraú | R$ 37.030,00 |
| 2335 | 28/08 | Município de Alcântaras/Câmara | R$ 21.480,00 |
| 2537 | 23/09 | Município de Paracuru | R$ 80.750,00 |

Total R$ 472.397,50. As 11 NFs anteriores a 08/06 não estão mais no bronze: voltam só buscando
de novo no Omie (passo 5 do runbook), que também recupera as 142 NFs de abril ausentes (caso C).

## Testes

```
Baseline conhecida (produção 1561661): 12 falhas ambientais
Branch:                                12 falhas ambientais (as mesmas, suíte por suíte)
Novas regressões:                      0
```

As 12 falham igual no código de produção (baseline `1561661`) neste ambiente — mesma lista,
suíte por suíte. (Uma rodada intermediária mostrou 11: um dado de teste esquecido no banco fazia
`run_quote_refresh_cooldown` passar; com o banco limpo, volta a falhar como no baseline.) Causas: banco de teste
sem dados (readiness, quote_*, tenant_isolation), sem chave de cifra (multitenant_foundation),
suítes que esperam o banco de produção (test_database_guard, csrf, db_error_envelope,
migration_pending) e dois checks estáticos antigos de geo (geo_summary, sales_by_neighborhood).

Novos/estendidos: `run_mixed_company_context` (46), `run_licitacao_seller_rule` (38),
`run_seller_assignment` (26 — inclui o invariante permanente "atribuir vendedor não muda Todas").

## Runbook

```
# 1. deploy (máquina local, pasta biomie-git)
BIOMIE_VPS_KEY=~/.ssh/id_ed25519_vps bash tools/deploy/vps/deploy.sh --skip-minify
# 2. VPS, /var/www/biomie/live
sudo -u www-data php bin/schema_migrate.php migrate
# 3. simulação oficial (somente leitura) + revisão do CSV
sudo -u www-data php bin/reclassify_unclassified_invoices.php --company=all --todas --pedidos --csv=/tmp/reclass.csv
# 4. aplicar
sudo -u www-data php bin/reclassify_unclassified_invoices.php --company=all --todas --pedidos --apply
# 5. MAG Filial: buscar de novo no Omie o que a exclusão descartou (pedidos, NFs, títulos),
#    jan–set/2026, sem marts (o passo 6 materializa); depois reclassificar só a Filial
sudo -u www-data php bin/initial_sync_company.php --company=MAG_FILIAL --from=2026-01 --to=2026-09 --skip-marts
sudo -u www-data php bin/reclassify_unclassified_invoices.php --company=MAG_FILIAL --todas --pedidos --apply
# 6. rematerializar 2026 por empresa (MAG_FILIAL, EXPERT, UNI_DESIGN, MAG_MATRIZ, ERGOOFFICE)
sudo -u www-data php bin/biomie gold:materialize-commercial --company=UNI_DESIGN --from=2026-01-01 --to=2026-09-30
# 7. agendar a sentinela (na máquina local, pasta biomie-git) e verificar na VPS:
#    tem de sair "OK" (exit 0) depois dos passos 4-6
BIOMIE_VPS_KEY=~/.ssh/id_ed25519_vps bash tools/deploy/vps/install_cron.sh --enable
sudo -u www-data php bin/commercial_integrity_check.php
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
| MAG Filial jul/26 · Todas | R$ 1.134.892,56 → R$ 1.126.913,19 depois da reclassificação (NF 1851 vira intra-grupo — ver abaixo); **depois do passo 5 soma a licitação recuperada** (NFs 1728, 1742, 1895: Total NF R$ 332.885,00; a base exata desconta frete/outras/seguro) |
| MAG Filial set/26 · Licitação | **R$ 80.750,00** (NF 2537, Município de Paracuru) — base exata após o passo 5 |
| MAG Filial · qualquer mês | nenhuma NF do vendedor "Licitação" pode ficar fora da canônica (conferir bronze × canônica) |
| Expert e Ergo · Todas, todos os meses | **inalterados** (NF 1 da Expert e NF 270 da Ergo só trocam de coluna) |
| Toda empresa/mês | Varejo + Licitação = Todas |
| Toda empresa/mês | soma dos vendedores = Todas |

### MAG Filial julho: por que R$ 7.979,37 e não R$ 8.017,47

A prévia inicial mostrou "R$ 8.017,47" para MAG Filial varejo → intra-grupo: era a soma de
**duas** NFs em qualquer status/escopo. Só uma está no universo comercial (medido em 29/09):

| | NF 1851 | NF 1785 |
|---|---|---|
| Cliente | UNI DESIGN (grupo) | MAGAZINE DOS MOVEIS (grupo) |
| Operação (`operation_scope`) | commercial (venda) | **remittance** (remessa) |
| Total NF | R$ 7.979,37 | R$ 38,10 |
| Mercadoria + IPI | 7.728,20 + 251,17 | 36,90 + 1,20 |
| Frete / outras / seguro | 0 / 0 / 0 | 0 / 0 / 0 |
| Base comercial (Total − frete − outras − seguro; IPI fica) | **R$ 7.979,37** | R$ 38,10 |
| Em "Todas" hoje? | sim | não (remessa nunca conta) |
| Impacto em "Todas" jul/26 | **− R$ 7.979,37** | R$ 0,00 |

Julho por `data_ref` (data usada pelo painel) = por `data_emissao` = R$ 1.134.892,56 em 261 NFs.
Atenção na conciliação com o Omie: a base do BI inclui IPI; o "Total de Mercadoria" do Omie não
(na NF 1851: R$ 7.979,37 no BI × R$ 7.728,20 de mercadoria no Omie).

### Invariante da tela "Vendedor ausente"

Total da empresa antes de atribuir vendedor = total depois. A atribuição só move valor de
"sem vendedor" para o vendedor escolhido e, se o escolhido for "Licitação", de varejo para
licitação. Coberto por `run_seller_assignment`; conferir em produção na primeira atribuição.

Regra (cobertos por teste, conferir em produção por amostragem):

- cliente público + ROBERTO → varejo; cliente público + NUBIA → varejo
- cliente público + vendedor Licitação → licitação; cliente privado + vendedor Licitação → licitação
- observação com pregão/edital/empenho não muda o canal
- empresa do grupo + vendedor Licitação → intra-grupo; empresa do grupo + ROBERTO → intra-grupo

## Proteções anti-regressão

Cada causa do incidente tem uma trava (a venda não some) ou um alarme (alguém fica sabendo):

| Causa | Proteção | Onde |
|---|---|---|
| Exclusão dura pelo vendedor "Licitação" | **Trava**: a política ignora a regra (pelo cadastro e pelo nome do vendedor no registro) | `HardDataExclusionPolicy`, `CanonicalWriteRepository::isHardExcluded` |
| Ferramenta que apagou a licitação da Filial | **Removida**; se voltar, deploy aborta e o check fica crítico | `tools/dev/remove_licitacao_data.php` |
| Contexto de um canal só (Uni Design) | "Todas" ligado por padrão; se desligar, deploy aborta e o check fica crítico; acesso de um canal só vira alerta | `config/env.php`, check |
| Venda do Omie que não chega ao BI (qualquer causa) | **Alarme**: bronze × canônica, últimos 60 dias | check `venda_omie_fora_do_bi` |
| NF nunca classificada / pedido × NF divergente | **Alarme** | check `nf_nunca_classificada`, `pedido_nf_divergente` |
| Regra que descarta um vendedor inteiro | **Alarme** | check `exclusao_vendedor` |

- **Check "Integridade Comercial"** (Admin → Saúde): `CommercialIntegrityHealthCheck`.
- **Sentinela** de hora em hora (`cron/hostinger/commercial_integrity.sh`, minuto 40): alerta por
  Telegram/webhook e no sininho, com dedup de 24 h. Manual: `php bin/commercial_integrity_check.php`
  (exit 0 ok · 1 alerta · 2 crítico).
- **Portão de deploy**: `run_commercial_regression_guard_contract.php` (16 checks, sem banco) roda no
  `remote_deploy` antes da troca de versão, junto com `run_intercompany_channel_regression.php` e
  `run_company_access_self_rule_contract.php`. Testado por mutação: recolocar a ferramenta ou
  desligar "Todas" reprova o deploy.
- Testes com banco: `run_commercial_integrity_guard` (16), `run_licitacao_filial_exclusion` (7).

## Fora desta release (próximos passos)

- `CommercialFilterSet` global: empresa + período + canal + vendedor produzindo o mesmo
  universo em Dashboard, Plano de Voo, Vendedores, Geografia, CNAE, Financeiro e Cockpit.
  Hoje não passam pelo filtro de vendedor diretamente: `geo_*`, `sales_by_city/neighborhood`,
  `top_clients_by_cnae`, `finance_overview`, `cockpit_summary`.
- Indicador de saúde dos dados (NFs/pedidos sem vendedor, operações não classificadas).
- MAG Filial: 142 NFs de abril ausentes da canônica; Ergo: 55 NFs com operação `unknown`
  (R$ 334 mil) — ambos exigem nova busca no Omie.

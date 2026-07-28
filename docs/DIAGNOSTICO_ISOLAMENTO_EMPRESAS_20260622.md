# Diagnóstico de isolamento por empresa

> Este documento registra o estado anterior às correções. O resultado final
> está em `docs/RELATORIO_CORRECAO_ISOLAMENTO_EMPRESAS_20260623.md`.

Data da auditoria: 22/06/2026  
Escopo: páginas, APIs, repositórios, contexto de tenant, consolidado e dados atuais do banco.

## Conclusão executiva

A regra de negócio ainda não está garantida em todas as páginas.

A infraestrutura central existe e está correta:

- o contexto da empresa vem da sessão autenticada;
- o usuário só pode selecionar empresas às quais possui acesso;
- o modo consolidado mantém uma lista de empresas permitidas;
- `TenantContext::sqlFilter()` gera `company_id = N` para empresa individual e `company_id IN (...)` para consolidado;
- caches normais são separados por empresa e canal;
- NF, pedidos, títulos, eventos, bronze e a maior parte dos marts possuem `company_id`.

Entretanto, há leituras que não usam o filtro central. Os problemas mais graves estão em:

1. Financeiro do Dashboard e visão principal do Financeiro.
2. Inteligência de produtos e Product 360.
3. Drilldowns do mapa geográfico.
4. Dimensões de clientes e enriquecimento geográfico.
5. Forecasts e oportunidades preditivas.
6. Algumas seções do Fiscal.
7. Implementação incompleta do modo consolidado.

O sistema, no estado auditado, não pode ser considerado multitenant seguro em todas as superfícies.

## Resultados reais por empresa

### Movimento comercial acumulado disponível

Período disponível nas NFs: 01/01/2026 a 22/06/2026.

| Empresa | NFs | Autorizadas | Canceladas | Receita comercial | Remessas | Escopo desconhecido | Valor cancelado |
|---|---:|---:|---:|---:|---:|---:|---:|
| MAG_FILIAL | 1.494 | 1.408 | 86 | R$ 3.579.247,36 | R$ 83.147,75 | R$ 0,00 | R$ 276.758,65 |
| EXPERT | 74 | 71 | 3 | R$ 1.096.472,74 | R$ 68,60 | R$ 0,00 | R$ 12.992,00 |
| UNI_DESIGN | 18 | 16 | 2 | R$ 36.960,00 | R$ 0,00 | R$ 0,00 | R$ 3.189,00 |
| MAG_MATRIZ | 1 | 1 | 0 | R$ 0,00 | R$ 199,00 | R$ 0,00 | R$ 0,00 |
| ERGOOFFICE | 233 | 216 | 17 | R$ 5.243.721,31 | R$ 13.218,19 | R$ 334.056,30 | R$ 1.200.602,83 |
| Consolidado real | 1.820 | 1.712 | 108 | R$ 9.956.401,41 | R$ 96.633,54 | R$ 334.056,30 | R$ 1.493.542,48 |

Observações:

- MAG_MATRIZ possui apenas uma NF de remessa no período auditado.
- ERGOOFFICE possui R$ 334.056,30 em NFs autorizadas com `operation_scope = unknown`; esse valor pode aparecer ou desaparecer conforme a página.
- A receita comercial usa o escopo `commercial`. Algumas páginas usam também a base comercial da NF, descontando frete, seguro e outras despesas.

### Resultado de junho até 22/06/2026

| Empresa | NFs no período | Receita comercial | Remessas |
|---|---:|---:|---:|
| MAG_FILIAL | 214 | R$ 243.404,82 | R$ 561,28 |
| EXPERT | 20 | R$ 239.186,97 | R$ 0,00 |
| UNI_DESIGN | 12 | R$ 18.433,00 | R$ 0,00 |
| MAG_MATRIZ | 1 | R$ 0,00 | R$ 199,00 |
| ERGOOFFICE | 14 | R$ 1.338.817,60 | R$ 0,00 |
| Consolidado real | 261 | R$ 1.839.842,39 | R$ 760,28 |

### Financeiro de junho até 22/06/2026

Critério igual ao usado pela leitura financeira atual: `data_competencia` entre 01/06/2026 e 22/06/2026.

| Empresa | Títulos | Recebido | Em aberto | Vencido em 22/06 |
|---|---:|---:|---:|---:|
| MAG_FILIAL | 523 | R$ 390.739,12 | R$ 175.824,43 | R$ 167.797,34 |
| EXPERT | 20 | R$ 0,00 | R$ 166.489,12 | R$ 166.489,12 |
| UNI_DESIGN | 12 | R$ 4.887,00 | R$ 4.441,00 | R$ 4.441,00 |
| MAG_MATRIZ | 1 | R$ 0,00 | R$ 199,00 | R$ 0,00 |
| ERGOOFFICE | 19 | R$ 0,00 | R$ 228.504,68 | R$ 228.504,68 |
| Consolidado real | 575 | R$ 395.626,12 | R$ 575.458,23 | R$ 567.232,14 |

Hoje a leitura principal de financeiro pode devolver a linha consolidada acima em qualquer empresa selecionada, porque o repositório usado por ela não filtra `company_id`.

## Situação das tabelas principais

### Aprovadas quanto à identidade da empresa

Foram verificados:

- `biomie_notas_fiscais_canonicas`;
- `biomie_notas_fiscais_itens_canonicos`;
- `biomie_pedidos_canonicos`;
- `biomie_titulos_financeiros_canonicos`;
- `biomie_eventos_canonicos`;
- `biomie_omie_bronze`;
- bridges fiscais/financeiras;
- marts comercial, fiscal, financeiro e geográfico.

Resultado:

- zero `company_id` inválido nas tabelas canônicas principais;
- zero duplicidade de `nota_key` dentro da mesma empresa;
- zero duplicidade de `pedido_id_omie` dentro da mesma empresa;
- zero duplicidade de `titulo_key` dentro da mesma empresa;
- zero item de NF órfão em relação à NF da mesma empresa;
- zero chave de NF repetida entre empresas no conjunto atual.

### Tabelas com `company_id = 0`

| Tabela | Linhas sem empresa válida |
|---|---:|
| `biomie_clientes_dim` | 1.225 |
| `biomie_clientes_geo_dim` | 130 |
| `mart_forecasts` | 19 |
| `mart_oportunidades` | 22 |

As dimensões de clientes válidas estão materializadas apenas para `company_id = 1`:

- `biomie_clientes_dim`: 1.259 linhas da MAG_FILIAL e 1.225 linhas globais/legadas.
- `biomie_clientes_geo_dim`: 1.257 linhas da MAG_FILIAL e 130 linhas globais/legadas.
- não existem dimensões válidas de clientes para EXPERT, UNI_DESIGN, MAG_MATRIZ e ERGOOFFICE.

Há 1.097 códigos de cliente repetidos entre `company_id = 0` e `company_id = 1`.

Impacto medido:

- 1.813 NFs encontram alguma dimensão de cliente pertencente a outra empresa ou ao escopo global;
- 327 NFs não possuem dimensão da própria empresa, mas encontram uma dimensão de outra empresa/global;
- 329 NFs encontram geografia de cliente de outra empresa/global;
- as mesmas 329 não possuem geografia da própria empresa.

Qualquer `JOIN` por `codigo_cliente_omie` sem também usar `company_id` é inseguro.

## Diagnóstico por página

| Página/módulo | Empresa individual | Consolidado | Diagnóstico |
|---|---|---|---|
| Dashboard | Parcial | Parcial | KPIs comerciais usam tenant; bloco financeiro mistura empresas; cliente/dossiê depende de dimensões incompletas. |
| Time comercial / Vendedores | Parcial | Parcial | Faturamento principal usa tenant; carteira, ownership e perfis de clientes possuem joins sem empresa em componentes auxiliares. |
| Plano de Voo | Parcial | Incompleto | Realizado canônico usa filtros compatíveis; metas usam `companyId()` único e no consolidado consultam empresa 0. |
| Editor de metas | Seguro apenas por empresa | Não aplicável/inseguro | Escrita é vinculada a uma empresa; o modo consolidado deve ser bloqueado ou exigir seleção explícita da empresa. |
| NF do período | Majoritariamente seguro | Majoritariamente compatível | Consultas principais usam `sqlFilter`; enriquecimentos e dossiê ainda precisam de joins compostos. |
| Mix faturado | Reprovado | Reprovado | Ranking principal novo é isolado, mas inteligência de ação e Product 360 consultam NFs de todas as empresas. |
| Geografia / Mapa | Reprovado | Reprovado | Resumo principal possui caminhos isolados; detalhe territorial e evolução mensal não filtram empresa. |
| Financeiro | Reprovado | Reprovado | Overview canônico e card financeiro do Dashboard leem todos os títulos; outras abas usam empresa fixa e ficam vazias no consolidado. |
| Fiscal | Parcial | Reprovado | KPIs executivos usam empresa fixa; contexto, esfera fiscal e estatística do mart são globais; consolidado resulta em empresa 0 nas leituras principais. |
| Conciliação / Reconciliação | Parcial | Incompleto | Busca principal usa tenant; snapshots e repositórios usam uma empresa fixa; subqueries devem correlacionar também por empresa. |
| Forecast / Inteligência | Reprovado | Reprovado | Marts estão em `company_id = 0` e repositórios não aplicam tenant. |
| Admin / Integrações / Saúde / Observabilidade | Global por natureza | Global | Devem ser tratados como control plane, identificados explicitamente como “Ambiente”, não como resultado da empresa selecionada. |

## Falhas críticas encontradas

### 1. Financeiro mistura títulos de todas as empresas

`FinanceReadRepository` consulta `biomie_titulos_financeiros_canonicos` sem `company_id` em:

- lista de títulos;
- resumo recebido/aberto/vencido;
- clientes inadimplentes;
- títulos em aberto;
- contagem de disponibilidade.

Esse repositório alimenta:

- o `finance_summary` do Dashboard;
- o fallback de saúde comercial;
- a visão canônica principal do Financeiro.

Consequência:

- selecionar MAG_FILIAL, EXPERT, UNI_DESIGN, MAG_MATRIZ ou ERGOOFFICE pode mostrar o mesmo total financeiro consolidado;
- usuários com acesso a apenas uma empresa podem receber números das demais;
- um consolidado de subconjunto pode incluir empresas fora de `allowed_ids`.

### 2. Inteligência de produtos e Product 360 não usam tenant

`ProductActionIntelligenceBuilder`, `Product360ReadRepository` e o builder legado fazem consultas em NF e itens sem `company_id`.

Essas rotas são chamadas diretamente pela página Mix Faturado:

- `api/product_action_intelligence.php`;
- `api/product_detail.php`.

Consequência:

- oportunidades, produtos, recompra, afinidade por vendedor, clientes e LTV podem combinar todas as empresas;
- o nome/código de um produto igual em empresas diferentes é agregado como se fosse uma única operação.

### 3. Drilldowns geográficos são globais

`api/geo_territory_detail.php` e `api/geo_territory_evolution.php` leem `mart_compradores_por_geo` por período e território, sem empresa.

Consequência:

- o mapa pode começar com um resumo isolado e, ao abrir território/evolução, trocar para números de todas as empresas;
- percentuais de participação territorial usam denominador global;
- o comportamento é especialmente enganoso porque a página aparenta manter a empresa selecionada.

### 4. Dimensões de clientes não estão prontas para cinco empresas

O modelo físico aceita `company_id`, mas várias consultas ainda juntam dimensões somente por `codigo_cliente_omie`.

Pontos afetados incluem:

- `CustomerReadRepository`;
- ownership comercial;
- Geo 360;
- enriquecimento de carteira;
- resolução de identidade financeira;
- partes de CNAE e geografia.

`api/client_profile.php` já filtra a dimensão principal pela empresa na versão auditada, mas o join da dimensão geográfica ainda não correlaciona `g.company_id = d.company_id`.

### 5. Forecasts e oportunidades são globais

`mart_forecasts` e `mart_oportunidades` possuem linhas com `company_id = 0`.

Os repositórios filtram apenas por competência, não por empresa ou `allowed_ids`.

Consequência:

- previsões e oportunidades não representam a empresa selecionada;
- não existe distinção confiável entre empresa individual e consolidado.

### 6. Fiscal mistura contexto global com KPIs individuais

No Fiscal:

- `executiveSummary`, tendência e ranking usam `companyId()` e são individuais;
- `martStats`, `fiscalContext` e `sphereSummary` possuem consultas globais;
- no consolidado, as leituras individuais usam `company_id = 0` e tendem a retornar vazio, enquanto trechos globais continuam mostrando dados.

Isso cria uma página internamente contraditória.

## Modo consolidado

O consolidado está corretamente modelado no contexto:

- valida acesso a duas ou mais empresas;
- aceita subconjunto de empresas acessíveis;
- `allowedCompanyIds()` retorna apenas esse subconjunto;
- `sqlFilter()` converte o filtro em `IN (...)`.

O problema está nas consultas que não usam esse contrato.

Há três comportamentos atualmente:

1. Correto: usa `sqlFilter()` e soma somente `allowed_ids`.
2. Incompleto: usa `companyId()`, recebe 0 no consolidado e retorna vazio.
3. Inseguro: não usa filtro e soma todas as empresas do banco, mesmo fora de `allowed_ids`.

O terceiro caso é uma falha de autorização, não apenas uma divergência de relatório.

## Consistência dos marts

O auditor comercial antigo reportou diferença de R$ 88.186,16 ao comparar marts com `valor_total`.

Parte dessa diferença é esperada:

- MAG_FILIAL: R$ 50.344,16 correspondem à diferença entre valor bruto e base comercial.
- UNI_DESIGN: R$ 70,00 correspondem à mesma regra.

O problema real de materialização está na ERGOOFFICE em junho de 2026:

- base comercial canônica de junho: R$ 1.338.817,60;
- `mart_cliente_periodo`, `mart_vendedor_dia` e `mart_compradores_por_geo`: R$ 1.301.045,60;
- diferença real: R$ 37.772,00.

No produto:

- mercadorias canônicas ERGOOFFICE: R$ 5.082.923,42;
- `mart_produto_periodo`: R$ 5.046.340,58;
- diferença: R$ 36.582,84.

Portanto:

- o guard precisa comparar cada mart com sua base semântica correta e por empresa;
- a ERGOOFFICE precisa de rematerialização de junho.

## Operação e sincronização

Testes encontrados:

- 5 empresas ativas e integrações Omie configuradas;
- cache separado por empresa/canal;
- troca de contexto por aba funcionando;
- chaves canônicas compostas por empresa funcionando;
- 7 sincronizações com status `running` há mais de uma hora.

Os 7 runs presos não causam mistura diretamente, mas comprometem frescor e podem explicar marts incompletos.

## Prioridade recomendada

### P0 — impedir exposição entre empresas

1. Aplicar `TenantContext::sqlFilter()` em `FinanceReadRepository`.
2. Aplicar tenant em Product Action e Product 360.
3. Aplicar tenant em todos os endpoints de detalhe/evolução geográfica.
4. Correlacionar todos os joins tenantizados por chave de negócio e `company_id`.
5. Remover qualquer leitura de `company_id = 0` das páginas de resultado.

### P1 — tornar o consolidado consistente

1. Substituir leituras fixas de `companyId()` por `sqlFilter()` nas operações somente de leitura.
2. Bloquear no consolidado operações de escrita/sync/metas que exigem uma empresa única.
3. Garantir que cache, snapshots e marts incluam o conjunto de empresas no escopo.
4. Criar testes para consolidado de subconjunto, não apenas consolidado de todas as empresas.

### P1 — corrigir dimensões

1. Materializar clientes e geografia para as empresas 2, 3, 4 e 5.
2. Migrar ou eliminar as linhas `company_id = 0`.
3. Adotar joins compostos, por exemplo:

```sql
ON dim.company_id = fato.company_id
AND dim.codigo_cliente_omie = fato.codigo_cliente_omie
```

### P2 — consistência e operação

1. Rematerializar junho/2026 da ERGOOFFICE.
2. Classificar os R$ 334.056,30 de `operation_scope = unknown`.
3. Encerrar/reclassificar os 7 sync runs presos.
4. Atualizar os guards para comparar semântica e empresa corretas.

## Critério de aceite recomendado

Para cada endpoint de negócio:

1. Empresa individual A retorna apenas `company_id = A`.
2. Empresa individual B retorna apenas `company_id = B`.
3. Consolidado `[A,B]` retorna exatamente a soma de A+B.
4. Consolidado `[A,B]` não retorna C, mesmo que o usuário também tenha acesso a C.
5. Usuário sem acesso a C nunca recebe registros ou agregados de C.
6. Drilldowns, exportações, busca, drawers e detalhes obedecem ao mesmo escopo do card de origem.
7. Escritas e sincronizações falham explicitamente no consolidado.

## Verificações executadas

- `tests/run_multitenant_foundation.php`: 27/28; falha em 7 sync runs presos.
- `tools/dev/multitenant_channel_guard.php`: aprovado.
- `tools/dev/company_selector_guard.php`: 12/12.
- `tools/dev/tab_context_isolation_guard.php`: 7/7.
- `bin/d50_consolidado_guard.php`: sem falhas críticas do padrão antigo, mas com quatro alertas.
- auditoria direta das tabelas e chaves.
- auditoria estática das consultas usadas pelas páginas.
- reconciliação por empresa entre canonical e marts.

O guard D50 não detecta consultas completamente sem `company_id`; por isso ele aprovou arquivos que ainda misturam empresas.

# Arquitetura oficial dos dados Omie

Este contrato define a ordem oficial para dados comerciais vindos da Omie no BIomie. Toda nova API, pagina, drawer, KPI ou mart deve respeitar esta hierarquia antes de criar calculos locais.

## Fluxo oficial

```text
Omie API
  -> tabelas brutas / staging
  -> camada canonica
  -> marts comerciais
  -> APIs internas
  -> paginas e drawers
```

Dashboards e drawers nao devem consultar a Omie diretamente. A Omie e entrada de sincronizacao; o consumo da aplicacao acontece pelo banco local, preferencialmente por marts reconciliados ou pela camada canonica quando a granularidade precisa ser auditavel.

## Fontes oficiais

| Dominio | Fonte oficial | Regra obrigatoria | Observacao |
| --- | --- | --- | --- |
| Vendas realizadas | `biomie_notas_fiscais_canonicas` | `status_fiscal = 'autorizada'` e `data_competencia` entre inicio e fim | Fonte primaria para receita realizada. Marts podem acelerar leitura, mas devem reconciliar com esta regra. |
| Itens/produtos | Itens das NFs canonicas, ou tabela equivalente usada por `productDocumentBreakdownCommercial()` | Deve herdar o filtro da NF canonica autorizada e do periodo por `data_competencia` | Produto vendido vem da composicao fiscal, nao de pedido aberto. |
| Metas | `biomie_sales_targets_monthly` | Mes alvo por vendedor, com proporcionalizacao quando o filtro for parcial | Meta cheia para mes cheio; meta proporcional para intervalo parcial. |
| Vendedores | `codigo_vendedor_omie` / `vendor_id` | Nome do vendedor e apenas fallback de exibicao ou resgate | Nenhum KPI novo deve usar nome como chave principal. |
| Mapa | `mart_compradores_por_geo` + `biomie_bairros_fortaleza_dim` | Diagnostico de cobertura obrigatorio | O mart geografico deve apontar lacunas de bairro/cidade/cliente em vez de ocultar zeros. |

## Contrato para vendas realizadas

Consulta oficial:

```sql
SELECT *
FROM biomie_notas_fiscais_canonicas
WHERE status_fiscal = 'autorizada'
  AND data_competencia BETWEEN :start_date AND :end_date
```

Campos esperados para rastreabilidade:

- `nota_key`
- `chave_nfe`
- `numero_nf`
- `serie_nf`
- `codigo_cliente_omie`
- `codigo_vendedor_omie`
- `data_competencia`
- `status_fiscal`
- valor fiscal total exposto pela camada de leitura como `total`, `gross_total` ou `invoice_total`

Se uma API precisar usar mart comercial, ela deve declarar no payload o mart usado, a fonte canonica de reconciliacao e o periodo aplicado. Se o mart estiver vazio mas houver NFs canonicas autorizadas, o estado correto e diagnostico de materializacao pendente, nao periodo sem venda.

## Contrato para metas

Metas comerciais partem de `biomie_sales_targets_monthly`.

Para mes cheio:

```text
meta_periodo = meta_mensal
realizado = soma das NFs autorizadas no mes
atingimento_pct = realizado / meta_periodo
gap = meta_periodo - realizado
```

Para periodo parcial:

```text
meta_periodo = meta_mensal proporcional aos dias uteis do intervalo
realizado = soma das NFs autorizadas no intervalo
atingimento_pct = realizado / meta_periodo
gap = meta_periodo - realizado
```

A ligacao com vendedores deve priorizar `vendor_id` ou `codigo_vendedor_omie`. Nome e alias podem ajudar a recuperar historico, mas nao substituem a chave.

## Contrato para produtos

Produto vendido deve sair dos itens das NFs canonicas autorizadas. Quando a tela usar `productDocumentBreakdownCommercial()` ou mart derivado equivalente, o mart precisa preservar:

- periodo fiscal/comercial por `data_competencia`;
- vinculo com NF (`nota_key`, `chave_nfe` ou `numero_nf`);
- cliente;
- vendedor quando disponivel;
- valor faturado do item;
- quantidade;
- diagnostico de item sem produto normalizado.

Pedidos vinculados podem aparecer em drawers como contexto, mas nao devem compor venda realizada enquanto nao houver NF autorizada.

## Contrato para mapa

O mapa deve consumir `mart_compradores_por_geo` e a dimensao `biomie_bairros_fortaleza_dim`.

Diagnosticos obrigatorios:

- quantidade de NFs canonicas autorizadas no periodo;
- quantidade de clientes com venda no periodo;
- clientes sem cidade;
- clientes sem bairro;
- bairros que nao casaram com a dimensao/GeoJSON;
- data de materializacao do mart;
- motivo oficial quando o mapa ficar zerado.

Zeros de mapa precisam diferenciar `periodo_sem_vendas`, `canonical_sem_nfs`, `geo_mart_vazio`, `sem_bairro`, `neighborhood_match_failed` e `omie_sync_stale`.

## Regra para APIs internas

Toda API que devolve KPI comercial deve incluir ou conseguir derivar:

- `period.start` e `period.end`;
- `source.table`;
- `source.tier` (`canonical`, `gold`, `legacy`, etc.);
- filtros aplicados;
- `diagnostics` quando o resultado estiver vazio ou parcial;
- identificadores canonicos para drilldown (`nota_key`, `chave_nfe`, `numero_nf`, `codigo_vendedor_omie`, `codigo_cliente_omie`).

O front-end deve renderizar vazio tecnico quando uma lista/grafico nao tiver dados, mostrando periodo, fonte e motivo. Grafico vazio sem diagnostico e considerado falha de contrato.

## Regra para novos marts

Um mart comercial novo so pode ser fonte primaria de tela quando:

- deriva explicitamente das NFs canonicas autorizadas;
- preserva `data_competencia`;
- preserva as chaves de vendedor/cliente/produto necessarias para drilldown;
- possui rotina de freshness/materializacao;
- tem diagnostico quando estiver vazio;
- reconcilia valores com a camada canonica para o mesmo periodo.


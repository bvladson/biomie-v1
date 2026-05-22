# Contrato de dados geograficos

## Objetivo

Este contrato define o caminho oficial dos dados que alimentam o mapa geografico de Fortaleza no BIomie. O mapa nao consulta a Omie em tempo real; ele consome APIs internas rapidas, baseadas em dados sincronizados e materializados no banco local.

Fluxo esperado:

```text
Omie API
  -> bronze/raw payloads
  -> camada canonica
  -> mart_cliente_periodo
  -> mart_compradores_por_geo
  -> biomie_bairros_fortaleza_dim
  -> api/geo_summary.php e api/sales_by_neighborhood.php
  -> assets/js/biomie-map.js
```

## Fonte de faturamento

A fonte transacional considerada para faturamento e a NF-e Omie sincronizada localmente. Para o mapa, a nota precisa existir na camada canonica, estar vinculada a cliente quando possivel e respeitar a regra comercial/fiscal ja existente no projeto.

Regras operacionais:

- NF cancelada nao deve entrar no faturamento do mapa.
- NF de entrada nao deve ser tratada como venda de Fortaleza.
- Devolucao segue a regra fiscal ja aplicada nos marts atuais; a Fase 3 apenas audita diferencas.
- Nota sem cliente deve ser reportada como pendencia, nao corrigida automaticamente.
- Cliente sem bairro deve ser reportado como pendencia de saneamento geografico.

## Datas e competencia

O periodo informado nas auditorias e APIs usa a competencia comercial/fiscal existente no projeto. Quando a tabela canonica possui `data_competencia`, ela e a referencia preferida. Caso contrario, a auditoria usa `data_emissao` como fallback controlado.

As ferramentas recebem:

```bash
--start=YYYY-MM-DD
--end=YYYY-MM-DD
--city=Fortaleza
```

## Vinculos oficiais

Os vinculos esperados sao:

- NF -> cliente: `codigo_cliente_omie` ou identificador equivalente.
- NF -> pedido: `pedido_id_omie`, `codigo_pedido_origem` ou equivalente.
- Pedido -> cliente: `codigo_cliente_omie`.
- Pedido -> vendedor: `codigo_vendedor_omie` e nome quando disponivel.
- NF -> vendedor: direto na NF ou via pedido.

Quando algum vinculo faltar, a nota ou pedido continua auditavel e deve aparecer nos relatorios como pendencia.

## Cidade e bairro

A cidade e reconhecida por nome normalizado, UF e, quando existir, codigo IBGE/codigo Omie. Para Fortaleza, a confianca e maior quando:

- cidade normalizada = `fortaleza`;
- UF = `CE`;
- codigo IBGE corresponde a Fortaleza, quando disponivel.

O bairro usa a regra de normalizacao da Fase 2:

- nome exibido permanece original/oficial;
- chave tecnica usa `NeighborhoodNormalizer`;
- aliases controlados podem resolver grafias conhecidas;
- fuzzy matching nao e aplicado automaticamente em producao.

## Marts

`mart_cliente_periodo` deve representar clientes e valores no periodo. Ele e a ponte entre notas canonicas e agregacoes por cliente.

`mart_compradores_por_geo` deve representar a agregacao geografica, preferencialmente ja com bairro/cidade normalizados e receita por bairro.

`biomie_bairros_fortaleza_dim` deve ser a dimensao oficial para cobertura de bairros de Fortaleza. O mapa deve usar essa dimensao para diferenciar bairro inexistente, bairro sem venda, bairro sem match no GeoJSON e cliente sem bairro preenchido.

Estados esperados:

- `ok`: linhas e receita coerentes para o periodo.
- `empty`: tabela existe, mas nao tem dados para o periodo.
- `stale`: materializacao anterior ao sync relevante.
- `missing`: tabela ausente ou indisponivel.
- `error`: falha controlada de leitura/diagnostico.

## APIs internas

`api/sales_by_neighborhood.php` deve:

- ler somente banco local/materializado;
- nao chamar Omie;
- manter campos antigos;
- adicionar `bairro_key`, `cidade_key`, `origem`, competencia e `data_quality`;
- responder com `motivo_zero` quando nao houver bairros.

`api/geo_summary.php` deve:

- manter compatibilidade com o front-end;
- adicionar diagnostico por camadas;
- reportar cobertura contra `biomie_bairros_fortaleza_dim`;
- reportar freshness;
- diferenciar periodo sem venda de mart nao materializado;
- sugerir proxima acao operacional.

## Motivos oficiais de zero

Motivos usados pelo diagnostico:

- `geojson_failed`: GeoJSON indisponivel ou sem features.
- `periodo_sem_vendas`: nao ha venda valida no periodo analisado.
- `canonical_sem_nfs`: camada canonica nao tem NFs validas no periodo.
- `mart_cliente_nao_materializado`: existem NFs canonicas, mas `mart_cliente_periodo` esta vazio.
- `geo_mart_vazio`: `mart_compradores_por_geo` esta vazio para o periodo/cidade.
- `sem_bairro`: ha vendas/clientes, mas os bairros nao chegaram preenchidos.
- `neighborhood_match_failed`: a API retornou bairros, mas nenhum casou com o GeoJSON.
- `omie_sync_stale`: sync Omie ou mart geografico estao defasados.
- `api_error`: API interna falhou.
- `unknown_error`: falha nao classificada.

## Proximas acoes sugeridas

Quando o diagnostico encontrar uma causa provavel, use estes comandos:

```bash
php tools/dev/omie_sync_health.php
php tools/dev/omie_geo_source_audit.php --start=2026-05-01 --end=2026-05-31 --city=Fortaleza --no-network
php tools/dev/geo_layer_diff.php --start=2026-05-01 --end=2026-05-31 --city=Fortaleza
php tools/dev/geo_quality_report.php --start=2026-05-01 --end=2026-05-31 --city=Fortaleza
```

Se a camada canonica tiver NFs e o mart de cliente estiver vazio, rode a materializacao de competencia pelo comando real do ambiente. Se o mart de cliente tiver dados e o geo mart estiver vazio, rode a materializacao geografica (`geo:materialize`) pelo console administrativo/CLI existente.

## Auditoria por NF

Para rastrear uma nota, pedido ou cliente:

```bash
php tools/dev/geo_trace_nf.php --nf=NUMERO_DA_NF
php tools/dev/geo_trace_nf.php --chave=CHAVE_NFE
php tools/dev/geo_trace_nf.php --pedido=CODIGO_PEDIDO
php tools/dev/geo_trace_nf.php --cliente=CODIGO_CLIENTE
```

A saida deve indicar se o registro apareceu no bronze, canonico, marts, API e GeoJSON.

## Freshness

Status de frescor:

- `fresh`: dentro do SLA operacional.
- `stale`: desatualizado.
- `missing`: sem referencia de sync/materializacao.
- `error`: ultima execucao falhou.
- `unknown`: nao foi possivel determinar.

SLA sugerido:

- clientes stale apos 24h;
- NFs stale apos 6h;
- pedidos stale apos 6h;
- geo mart stale quando for anterior ao ultimo sync de NFs.

## Seguranca

Credenciais Omie nunca devem aparecer em tela, JSON, erro ou log aberto. O `OmieClient` deve usar POST JSON com `app_key`, `app_secret`, `call` e `param`, mascarando segredos e registrando apenas metadados seguros.

Ferramentas dev rodam sem rede por padrao quando isso envolve Omie. Chamadas reais exigem flag explicita, como `--live-omie`.

## Pendencias para Fase 4

- Consolidar comandos operacionais oficiais de materializacao por periodo.
- Criar tela administrativa pequena para saneamento geografico, usando as sugestoes das auditorias.
- Definir SLA final de freshness por dominio com a operacao.
- Revisar diferencas de receita entre canonical, `mart_cliente_periodo` e geo mart.
- Avaliar migracao futura para colunas normalizadas/indexadas em todos os marts geograficos.

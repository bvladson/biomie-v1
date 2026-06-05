# BIomie Data Source Contracts

Status: fase inicial de migracao incremental.

## Regra

APIs executivas nao devem decidir SQL de negocio diretamente. O fluxo oficial passa a ser:

```text
Tela / JS
  -> API fina
  -> ReadModel do dominio
  -> Repository do dominio
  -> Gold ou Canonico conforme contrato
  -> MariaDB
```

Diagnosticos, auditorias e materializadores podem consultar multiplas fontes, mas devem declarar que sao diagnosticos.

## Contratos Iniciais

Os contratos estao registrados em `BIomie\DataPlatform\Contracts\SourceContractRegistry`.

| Contrato | Dominio | Fonte | Tabela | Uso |
| --- | --- | --- | --- | --- |
| `geo.sales_by_territory.v1` | geo | gold | `mart_compradores_por_geo` | resumo, segmentos, compradores e CNAEs do mapa |
| `geo.map_payload.v1` | geo | gold | `mart_compradores_por_geo` | payload inicial consolidado do mapa |
| `geo.sales_by_neighborhood.v1` | geo | gold | `mart_compradores_por_geo` | vendas por bairro/municipio conforme foco |
| `geo.territory_buyers.v1` | geo | gold | `mart_compradores_por_geo` | compradores por territorio |
| `geo.territory_cnaes.v1` | geo | gold | `mart_compradores_por_geo` | CNAEs por territorio |
| `geo.territory_invoices.v1` | geo | canonical | `biomie_notas_fiscais_canonicas` | NFs do territorio filtradas pelo mart geo |
| `cnae.sales_by_cnpj.v1` | cnae | gold | `mart_vendas_por_cnae_cnpj` | ranking CNAE e B2B por documento |
| `cnae.coverage.v1` | cnae | gold | `mart_vendas_por_cnae_cnpj` | cobertura CNPJ/B2B, CPF nao aplicavel e impacto de CNPJ sem CNAE |
| `cnae.ranking.v1` | cnae | gold | `mart_vendas_por_cnae_cnpj` | ranking oficial de CNAEs por receita/clientes/NFs |
| `cnae.opportunities.v1` | cnae | gold | `mart_vendas_por_cnae_cnpj` | CNPJs sem CNAE priorizados por impacto comercial |
| `cnae.customer_status.v1` | cnae | gold | `mart_vendas_por_cnae_cnpj` | empresas/compradores por CNAE com status semantico |
| `cnae.geo_distribution.v1` | cnae | gold | `mart_vendas_por_cnae_cnpj` | distribuicao CNAE por cidade/bairro |
| `fiscal.invoice_drilldown.v1` | fiscal | canonical | `biomie_notas_fiscais_canonicas` | detalhe e auditoria de NF |
| `customer.profile.v1` | customers | silver | `biomie_clientes_dim` | perfil do cliente com fallback declarado |

## Migracao Incremental

1. Criar o ReadModel sem alterar endpoints.
2. Transformar endpoints antigos em adaptadores finos.
3. Criar endpoint consolidado quando os adaptadores estiverem equivalentes.
4. Ativar por feature flag.
5. Rodar shadow comparison antes de trocar o front.
6. Habilitar guard estrito somente apos reduzir o legado.

## Flags e Endpoints Geo

Flags em `config/env.php`:

| Flag | Padrao | Efeito |
| --- | --- | --- |
| `BIOMIE_GEO_READMODEL_V2` | `0` | Faz `geo_composite_sales.php` e `geo_summary.php` tentarem o `GeoReadModel` antes do legado. |
| `BIOMIE_GEO_SINGLE_PAYLOAD` | `0` | Permite que o front do mapa composto consuma `api/geo_map_payload.php`. |
| `BIOMIE_GEO_PERFORMANCE_LOG` | `1` | Registra logs leves JSONL dos endpoints Geo ReadModel em `storage/logs/performance/`. |

Endpoints finos criados/adaptados:

| Endpoint | Estado |
| --- | --- |
| `api/geo_map_payload.php` | Novo payload consolidado via `GeoReadModel::mapPayload()`. |
| `api/geo_composite_sales.php` | Adaptador opcional por flag/`?readmodel=1`; legado preservado. |
| `api/geo_summary.php` | Adaptador opcional por flag/`?readmodel=1`; legado preservado. |
| `api/sales_by_neighborhood.php` | Adaptador opcional por flag/`?readmodel=1`; legado preservado. |
| `api/geo_territory_invoices.php` | Adaptador opcional por flag/`?readmodel=1`; legado preservado. |
| `api/geo_territory_buyers.php` | Adaptador opcional por flag/`?readmodel=1`; legado preservado. |
| `api/geo_territory_cnaes.php` | Adaptador opcional por flag/`?readmodel=1`; legado preservado. |

Para teste manual sem ativar globalmente, usuarios admin podem usar:

```text
api/geo_composite_sales.php?readmodel=1&date_from=2026-01-01&date_to=2026-06-03
api/geo_summary.php?readmodel=1&date_from=2026-01-01&date_to=2026-06-03&layer=mapa_composto_ceara
api/sales_by_neighborhood.php?readmodel=1&date_from=2026-01-01&date_to=2026-06-03&layer=fortaleza_bairros
api/geo_territory_buyers.php?readmodel=1&date_from=2026-01-01&date_to=2026-06-03&territory_type=fortaleza_bairro&cidade_key=fortaleza&bairro_key=centro
api/geo_territory_cnaes.php?readmodel=1&date_from=2026-01-01&date_to=2026-06-03&territory_type=fortaleza_bairro&cidade_key=fortaleza&bairro_key=centro
api/geo_territory_invoices.php?readmodel=1&date_from=2026-01-01&date_to=2026-06-03&territory_type=fortaleza_bairro&cidade_key=fortaleza&bairro_key=centro
mapa_vendas.php?single_payload=1
```

`api/geo_map_payload.php` deve permanecer magro. Na primeira carga ele carrega apenas resumo, territorios, ranking, escalas e qualidade. Compradores, CNAEs detalhados, NFs e diagnosticos completos continuam em lazy-load ou CLI.

## Ativacao em Producao GEO.R2.2

Ordem recomendada:

```text
Estado 0: BIOMIE_GEO_READMODEL_V2=0 e BIOMIE_GEO_SINGLE_PAYLOAD=0
Estado 1: smoke autenticado com /mapa_vendas.php?single_payload=1
Estado 2: BIOMIE_GEO_READMODEL_V2=1 e BIOMIE_GEO_SINGLE_PAYLOAD=0
Estado 3: BIOMIE_GEO_READMODEL_V2=1 e BIOMIE_GEO_SINGLE_PAYLOAD=1
```

Rollback operacional:

```text
BIOMIE_GEO_READMODEL_V2=0
BIOMIE_GEO_SINGLE_PAYLOAD=0
```

Sem rollback de banco.

## Observabilidade Geo

Os endpoints migrados registram uma linha JSON por chamada em:

```text
storage/logs/performance/geo_readmodel_YYYY-MM-DD.log
```

Campos principais:

```json
{
  "request_id": "geo_...",
  "endpoint": "geo_map_payload",
  "source_contract": "geo.map_payload.v1",
  "mode": "readmodel",
  "duration_ms": 486,
  "payload_kb": 78,
  "sql_queries": 2,
  "sql_time_ms": 117.36,
  "date_from": "2026-01-01",
  "date_to": "2026-06-04",
  "ok": true
}
```

O release guard inclui `D45 Geo ReadModel Performance Budget`:

- `PASS`: `geo_map_payload` ate 800ms, ate 150KB e ate 5 queries SQL.
- `WARN`: ate 1500ms, ate 500KB e ate 10 queries SQL.
- `FAIL`: acima da zona WARN, JSON invalido ou medicao ausente.

## CNAE.R1 - CnaeReadModel & B2B Intelligence

A leitura oficial de CNAE passa por `CnaeReadModel` e `CnaeReadRepository`.
CPF sem CNAE nao e erro: entra em `not_applicable_cpf`. A pendencia comercial real e CNPJ com venda e sem CNAE valido: `missing_cnpj`.

Estados oficiais:

- `ok`: CNPJ com CNAE normalizado valido.
- `missing_cnpj`: CNPJ sem CNAE valido.
- `not_applicable_cpf`: CPF, CNAE nao aplicavel.
- `invalid_document`: documento com tamanho invalido.
- `unknown`: documento ausente ou desconhecido.

Endpoints CNAE:

| Endpoint | Contrato | Observacao |
| --- | --- | --- |
| `api/cnae_coverage.php` | `cnae.coverage.v1` | cobertura geral e B2B |
| `api/cnae_ranking.php` | `cnae.ranking.v1` | ranking padrao B2B/CNPJ |
| `api/cnae_opportunities.php` | `cnae.opportunities.v1` | CNPJs sem CNAE por impacto |
| `api/company_cnae_ranking.php` | `cnae.customer_status.v1` | adaptador legado fino |
| `api/sales_by_cnae.php` | `cnae.ranking.v1` | adaptador legado fino |

CLI:

```bash
php bin/biomie cnae:coverage --json
php bin/biomie cnae:ranking --document-type=cnpj --json
php bin/biomie cnae:opportunities --json
php bin/biomie cnae:audit --json
```

O release guard inclui `D46 CNAE Semantic Governance`, bloqueando regressao quando:

- CPF volta a contar como erro de CNAE.
- CNPJ sem CNAE nao tem bucket proprio.
- API CNAE nova consulta `mart_vendas_por_cnae_cnpj` ou `biomie_cnae_dim` diretamente.
- Payload/endpoint CNAE deixa de declarar `source_contract`.
- Contratos CNAE somem do registry.

## CLIENTE.R1 - CustomerReadModel & Customer Truth

A leitura oficial de cliente passa por `CustomerReadModel` e `CustomerReadRepository`.
O objetivo e formar um golden record operacional do cliente sem reescrever o legado: documento, geo, CNAE, dono comercial e historico no periodo passam a ter uma fonte declarada.

Contratos oficiais:

| Contrato | Fonte oficial | Grao | Uso |
| --- | --- | --- | --- |
| `customer.profile.v1` | `biomie_clientes_dim` + `biomie_clientes_geo_dim` + Gold | `codigo_cliente_omie` | perfil 360 do cliente |
| `customer.identity.v1` | `biomie_clientes_dim` | cliente + documento | identidade e tipo CPF/CNPJ |
| `customer.commercial_history.v1` | `mart_cliente_periodo` | competencia + cliente | historico comercial |
| `customer.geo_status.v1` | `biomie_clientes_geo_dim` | cliente + cidade/bairro | qualidade geografica |
| `customer.cnae_status.v1` | `mart_vendas_por_cnae_cnpj`/CNAE resolver | cliente + status CNAE | governanca B2B |
| `customer.quality.v1` | `CustomerQualityResolver` | cliente + quality_status | auditoria cadastral |
| `customer.search.v1` | `biomie_clientes_dim` | cliente | busca governada |
| `customer.opportunities.v1` | `mart_cliente_periodo` + dimensoes | cliente + lacuna | oportunidade de saneamento |

Estados oficiais de qualidade:

- `complete`
- `missing_document`
- `invalid_document`
- `missing_city`
- `missing_neighborhood`
- `missing_geo`
- `missing_cnae_cnpj`
- `not_applicable_cpf`
- `missing_owner`
- `no_commercial_history`
- `orphan_commercial_history`

CPF sem CNAE preserva o estado CNAE `not_applicable_cpf`. CNPJ com faturamento e sem CNAE preserva `missing_cnpj`.

Endpoints Cliente:

| Endpoint | Contrato | Observacao |
| --- | --- | --- |
| `api/customer_profile.php` | `customer.profile.v1` | perfil oficial mascarado por padrao |
| `api/customer_search.php` | `customer.search.v1` | busca com filtros controlados |
| `api/customer_quality.php` | `customer.quality.v1` | cobertura e impacto de qualidade |
| `api/customer_opportunities.php` | `customer.opportunities.v1` | clientes com impacto e cadastro incompleto |
| `api/customer_commercial_history.php` | `customer.commercial_history.v1` | resumo e meses do cliente |

CLI:

```bash
php bin/biomie customer:profile --customer=123456 --json
php bin/biomie customer:search --q="hospital" --json
php bin/biomie customer:quality --json
php bin/biomie customer:opportunities --json
php bin/biomie customer:audit --json
php bin/biomie customer:shadow --json
```

### CLIENTE.R1.1 - Customer Adoption & Legacy Adapter Migration

Adapters legados migrados para consumir a verdade oficial do cliente:

| API legada | Regra do dominio | Fonte de cliente |
| --- | --- | --- |
| `api/client_portfolio_360.php` | Comercial/portfolio calcula carteira; Cliente decide identidade, documento, geo, CNAE e qualidade | `CustomerReadModel` + `customer.profile.v1` |
| `api/top_buyers_by_geo.php` | Geo decide territorio; Cliente decide comprador | `CustomerLegacyAdapter` + batch CustomerReadModel |
| `api/geo_territory_buyers.php` | Geo decide territorio/ranking; Cliente decide identidade/CNAE/qualidade do comprador | `CustomerLegacyAdapter` + batch CustomerReadModel |

Regras de payload migrado:

- Documento sai mascarado por padrao, inclusive para payload legado.
- `documento_norm` fica vazio em respostas publicas migradas.
- Cada comprador/cliente enriquecido declara `customer_source_contract = customer.profile.v1`.
- Campos adicionados: `customer_profile`, `customer_quality_status`, `customer_quality_missing`, `geo_quality`, `cnae_status`.
- `geo_territory_buyers.php` nao consulta `biomie_clientes_dim` diretamente; o enriquecimento fica no CustomerReadModel.

CLI de adocao:

```bash
php bin/biomie customer:adoption --json
php bin/biomie customer:adapter-audit --json
php tools/dev/customer_adoption_audit.php --json
```

O release guard inclui `D47 Customer Source Governance`, bloqueando regressao quando:

- `CustomerReadModel`, `CustomerReadRepository`, `CustomerQuery` ou contratos somem.
- API nova de cliente consulta `biomie_clientes_dim`/`biomie_clientes_geo_dim` diretamente.
- Payload de perfil deixa de declarar `source_contract`.
- `geo.quality` deixa de existir no profile.
- CPF volta a ser tratado como erro de CNAE.
- `customer:audit` ou `customer:shadow` falham.
- adapters R1.1 deixam de usar `CustomerReadModel`.
- payload migrado volta a ter padrao de documento bruto.
- adapters migrados voltam a consultar dimensao de cliente diretamente.

## Auditoria

```bash
php tools/dev/source_contract_audit.php
php tools/dev/source_contract_audit.php --json
php tools/dev/source_contract_audit.php --strict --json
php tools/dev/geo_readmodel_probe.php --json
php tools/dev/geo_readmodel_shadow_compare.php --json
php tools/dev/geo_map_performance_probe.php --json
php bin/biomie map:probe --json
php bin/biomie map:shadow --json
php bin/biomie map:contract --json
php bin/biomie map:performance --json
php bin/biomie customer:audit --json
php bin/biomie customer:shadow --json
php bin/biomie customer:adoption --json
php bin/biomie customer:adapter-audit --json
```

Sem `--strict`, a ferramenta apenas mede o legado. Com `--strict`, findings viram falha de contrato para uso futuro no release guard.

O release guard inclui `D44 ReadModel Source Governance` como informacional. Ele deve virar bloqueante somente quando os endpoints de maior fan-out ja estiverem atras dos ReadModels.

Escalonamento recomendado do D44:

- `D44.1` informacional: mostra leituras diretas legadas.
- `D44.2` warning: bloqueia novas leituras diretas em APIs novas.
- `D44.3` blocking: bloqueia leitura direta em dominios ja migrados.

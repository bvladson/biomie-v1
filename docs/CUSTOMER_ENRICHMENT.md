# Customer Omie Enrichment — BIomie

## Visao geral

O enriquecimento de clientes consulta a API Omie para preencher campos de endereco
(cidade, bairro, UF, CEP, logradouro) e CNAE nos registros de `biomie_clientes_dim`,
mantendo `biomie_clientes_geo_dim` sincronizada para alimentar os dashboards geograficos.

## Arquitetura modular (Fase 5F)

```
app/CustomerOmieEnrichmentService.php   — fachada; delega para os modulos abaixo
app/Modules/Customers/
  Domain/
    CustomerIdentity.php                — value object: codigo, razao_social, cpf_cnpj
    CustomerAddress.php                 — value object: cidade, bairro, uf, cep, endereco
    CustomerContact.php                 — value object: email, telefone
    CustomerProfile.php                 — value object: cnae_codigo, cnae_source
  Application/
    CustomerEnrichmentCoordinator.php   — orquestra run() local_first e omie_page
    CustomerEnrichmentRunner.php        — orquestra runAll() cursor-based
    CustomerEnrichmentReport.php        — factory do array de relatorio zerado
    CustomerEnrichmentResult.php        — struct de resultado por cliente
  Infrastructure/
    CustomerNormalizer.php              — transforma payload Omie em campos canonicos
    CustomerQualityValidator.php        — classifica erros not-found e rate-limit
    CustomerDimensionRepository.php     — SQL: fetch, persist, bronze, bairro, fila
    CustomerGeoSyncRepository.php       — SQL: INSERT IGNORE + UPDATE geo_dim
    OmieCustomerFetcher.php             — ConsultarCliente e ListarClientes
    CustomerEnrichmentLogger.php        — wrapper de Logger com contexto de enriquecimento
```

## Modos de operacao

### local_first (padrao)
1. Busca clientes em `biomie_clientes_dim` com dados faltando.
2. Para cada cliente, chama `ConsultarCliente` na API Omie.
3. Normaliza, persiste dimensao, sincroniza geo_dim.

### omie_page
Usado para backfill amplo. Chama `ListarClientes` paginado sem consulta previa local.

### runAll (cursor-based)
Enriquece todos os elegíveis em lotes sem OFFSET sobre lista mutável.
O cursor avanca pelo `codigo_cliente_omie` para nao saltar registros recém-enriquecidos.

## Tabelas envolvidas

| Tabela                          | Operacao           | Responsavel                     |
|----------------------------------|--------------------|---------------------------------|
| `biomie_clientes_dim`            | UPDATE             | CustomerDimensionRepository     |
| `biomie_clientes_geo_dim`        | INSERT IGNORE + UPDATE | CustomerGeoSyncRepository  |
| `biomie_omie_bronze`             | INSERT/UPSERT      | CustomerDimensionRepository     |
| `biomie_clientes_para_enriquecer`| INSERT/UPSERT      | CustomerDimensionRepository     |
| `biomie_bairros_fortaleza_dim`   | SELECT             | CustomerDimensionRepository     |

## Invariantes de seguranca

- `cnae_codigo` usa `COALESCE(NULLIF(...), :value)` — nao sobrescreve valor existente.
- `biomie_clientes_geo_dim` recebe `INSERT IGNORE` antes de qualquer UPDATE (skeleton row).
- Campos de endereco so sao gravados quando o valor Omie vier preenchido.
- `vendedor_responsavel` e `documento_normalizado` nao sao tocados por este servico.
- Nenhum `app_secret` e logado.
- Sleep entre lotes suprimido automaticamente fora de CLI.

## Execucao via Admin Console

```
# Um lote (modo padrao):
clientes:enrich-omie  limit=25 mode=local_first

# Todos os elegiveis (cursor):
clientes:enrich-omie-all  target=geo source=sales_buyers max_batches=20

# Dry run para inspecao:
clientes:enrich-omie  dry_run=1 limit=10
```

## Campos do relatorio

O array retornado por `run()` e `runAll()` inclui:

- `clientes_consultados_omie` — quantos foram consultados na API
- `clientes_atualizados` — com pelo menos um campo alterado
- `clientes_ignorados` — sem dados novos
- `clientes_nao_encontrados` — ausentes no Omie
- `bairros_matched` — bairro Fortaleza resolvido para oficial
- `bairros_unmatched` — bairro nao encontrado, entrou na fila de revisao
- `queued` — clientes na fila `biomie_clientes_para_enriquecer`
- `bronze_saved` — payloads persistidos em bronze
- `api_rate_limited` — limite Omie atingido (aguardar 60 s)

## Fila de revisao

Clientes com `cidade_ausente`, `bairro_ausente` ou `bairro_nao_encontrado` entram em
`biomie_clientes_para_enriquecer` com status `pendente`.
A fila pode ser revisada manualmente ou por automacao de curadoria de geo.

## Dependencias

- `OmieClient` — executa chamadas HTTP Omie com politica de retry
- `GeoNameNormalizer` — normaliza cidade e bairro para comparacao
- `Logger` — logs de warning/error por cliente

## Monitoramento via System Health (Fase 9)

Os checks `customer_quality` e `geo_quality` do System Health monitoram a qualidade dos dados produzidos pelo enrichment:

**`customer_quality`** — `biomie_clientes_dim`:

| Condição | Status |
|---|---|
| % sem CPF/CNPJ ou sem nome < 10% | ok |
| Pior % entre 10–30% | warning |
| Pior % ≥ 30% | critical |

**`geo_quality`** — `biomie_clientes_geo_dim` + `biomie_bairros_fortaleza_dim`:

| Condição | Status |
|---|---|
| Enrichment ≥ 80% e bairros ≥ 100 | ok |
| Enrichment 50–80% ou bairros < 100 | warning |
| Enrichment < 50% | critical |

```bash
# Verificar qualidade dos dados de clientes
php tools/dev/system_health_check.php --check customer_quality,geo_quality
```

Ver também: `docs/SYSTEM_HEALTH.md`

# KPI Contracts - BIomie

## Metadados oficiais de confianca dos dados

Os endpoints golden legados do BIomie oficializam estes campos:

- `source_of_truth`: origem operacional considerada fonte de verdade para o payload.
- `last_success_at`: data/hora da ultima carga, coleta ou materializacao bem-sucedida conhecida.
- `last_reconcile_at`: data/hora da ultima reconciliacao conhecida.

Esses campos podem aparecer no payload raiz, em `meta` e em `data_contract`.

## Decisao de contrato

A Fase 2 oficializa esses campos porque eles aumentam a confianca operacional dos dashboards internos MAG. O consumidor consegue distinguir dado vivo, dado stale, dado reconciliado e origem do numero exibido.

Os snapshots golden foram atualizados conscientemente para refletir esse contrato. Nao atualizar snapshots golden apenas para "passar teste"; qualquer nova mudanca de shape deve vir acompanhada de decisao de contrato e documentacao.

## Shadow validation

A validacao shadow compara payloads legacy e canonical de forma diferida, sem alterar a resposta oficial dos endpoints. Divergencias registradas pelo shadow servem como evidencia operacional para a migracao gradual ao canonical, mas nao mudam o contrato publico por si so.

As tolerancias atuais ficam centralizadas em `ShadowTolerancePolicy`:

- valores monetarios: `0.01`;
- diferencas numericas gerais: `0.01`;
- contagens: `0`.

Qualquer mudanca nessas tolerancias deve ser tratada como decisao de contrato de KPI.

## MetricRegistry (Fase 6)

A partir da Fase 6, todo KPI deve estar registrado em `MetricRegistry` antes de ser exposto em um endpoint ou dashboard.

### Regra obrigatória

Nenhum KPI novo pode ser adicionado a um endpoint sem antes criar seu contrato em `MetricRegistry::build()`. A sequência é:

1. Registrar em `MetricRegistry` com status `draft`.
2. Verificar `source_table` e `official_date` existem no banco.
3. Promover para `active` após validação.
4. Rodar `php tools/dev/semantic_audit.php` — deve sair 0 sem erros.
5. Confirmar que `php tests/run_golden_endpoints.php` permanece 6/6.

### Source of truth e timestamps de contrato

| Campo no payload | Mapeamento MetricRegistry |
|---|---|
| `source_of_truth` | `KpiDefinition::$sourceOfTruth` (ver `DataSourceTruth`) |
| `last_success_at` | `FreshnessService::forDomain()['last_success_at']` |
| `last_reconcile_at` | `FreshnessService::forDomain()['last_reconcile_at']` |

Os valores de `source_of_truth` nos payloads golden existentes refletem os tiers catalogados em `DataSourceTruth`. Não alterar sem decisão de contrato documentada.

### Continuidade dos campos existentes

Os campos `source_of_truth`, `last_success_at` e `last_reconcile_at` nos payloads dos endpoints legados continuam sendo gerados pelos endpoints individuais — o MetricRegistry não os injeta automaticamente. O registry é fonte de verdade para definição e auditoria; a entrega ao frontend permanece responsabilidade dos endpoints.

### Referências

- [SEMANTIC_LAYER.md](SEMANTIC_LAYER.md) — como adicionar KPIs, tiers de source_of_truth
- [METRIC_REGISTRY.md](METRIC_REGISTRY.md) — tabela completa dos 29 KPIs catalogados

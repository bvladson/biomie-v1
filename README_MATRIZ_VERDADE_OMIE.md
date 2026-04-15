# Matriz de Verdade Omie -> BIomie

Status atual:
- Fase 0 concluida — contrato operacional de metricas travado
- Fase 1 concluida
- Fase 2 iniciada com camada bronze-imutavel generica
- Fase 3 iniciada com event store canonico minimo orientado a eventos

---

## Fase 0 — Contrato Operacional de Metricas

### Objetivo

Travar o catalogo oficial de metricas antes de qualquer refatoracao estrutural.
Cada KPI deve declarar: dominio, data oficial, chave natural, fonte primaria, regra de conflito e regra de exclusao.
Nenhum endpoint pode servir dado sem declarar de onde ele veio.

### Dominios

Tres dominios separados. Cada dominio fala apenas de sua propria evidencia.

- **comercial** — evidencia do contrato de venda (pedido, etapa, faturamento comercial)
- **fiscal** — evidencia documental oficial (NF-e autorizada, cancelamento, devolucao)
- **financeiro** — evidencia de liquidacao (titulo, baixa, recebimento)

### Regra Estrutural dos Dominios

- O financeiro pode referenciar pedido ou NF como origem, mas nao corrige o comercial nem o fiscal.
- O fiscal nao promove automaticamente receita comercial.
- O comercial pode reconhecer `faturamento_pendente_validacao_fiscal`, mas esse estado nao entra em receita_fiscal.
- Consolidado executivo nasce da ponte entre os tres dominios, nunca de um dominio isolado promovendo outro.

### Catalogo de KPIs — Dominio Comercial

| KPI | data_oficial | chave_natural | fonte_primaria | regra_conflito | regra_exclusao |
| --- | --- | --- | --- | --- | --- |
| `pedido_aberto` | `data_hora_ultima_etapa_valida` | `codigo_pedido` | Pedido de venda com ultima etapa valida | Indicio simultaneo de faturamento concluido e etapa aberta → classificar como `divergente` | Pedidos com cancelamento definitivo ou devolucao final |
| `pedido_faturado` | `data_faturamento` | `codigo_pedido` | Vinculo de faturamento do pedido no Omie | Vinculo sem NF valida → `pendente_validacao_fiscal`; nao promove receita_fiscal | Pedidos sem evidencia de faturamento reconhecida |
| `carteira_backlog` | `data_hora_ultima_etapa_valida` | `codigo_pedido` | Pedido com etapa vigente | Pedido com estado fiscal concluido e etapa ainda aberta → `divergente`, nao entra em carteira normal | Pedidos faturados definitivamente, cancelados ou devolvidos |
| `receita_comercial` | `data_emissao` | `codigo_pedido` | Contrato de negocio do dominio comercial | Diferenca entre faturamento comercial e evidencia fiscal → exibir como diferenca entre dominios, sem mascaramento | Pedidos cancelados, devolvidos ou sem faturamento reconhecido |
| `ranking_vendedor` | `data_emissao` | `codigo_vendedor_omie` | Pedidos de venda por vendedor no periodo | Vendedor inativo com historico valido: incluir no historico, marcar como inativo | Registros sem `codigo_vendedor_omie` valido |
| `meta_plano_voo` | `data_emissao` | `codigo_pedido` | Contrato comercial do plano de voo | Meta definida fora do Omie: declarar fonte separada | Periodo fora da janela de competencia do plano |

### Catalogo de KPIs — Dominio Fiscal

| KPI | data_oficial | chave_natural | fonte_primaria | regra_conflito | regra_exclusao |
| --- | --- | --- | --- | --- | --- |
| `nf_autorizada` | `data_competencia` | `chave_nfe` | Consulta fiscal Omie (NF-e autorizada valida) | Autorizacao seguida de cancelamento → estado liquido reflete cancelamento | NFs denegadas, canceladas ou com status fiscal invalido |
| `nf_cancelada` | `data_cancelamento` | `chave_nfe` | Consulta fiscal Omie (evento de cancelamento) | Cancelamento prevalece sobre autorizacao para KPI liquido | NFs sem evidencia de cancelamento formal |
| `receita_fiscal` | `data_competencia` | `chave_nfe` | NF-e autorizada valida, liquida de cancelamentos, denegacoes e devolucoes | Cancelamento ou denegacao reduz valor liquido; devolucao parcial ajusta proporcional | NFs canceladas, denegadas ou com devolucao integral |

### Catalogo de KPIs — Dominio Financeiro

| KPI | data_oficial | chave_natural | fonte_primaria | regra_conflito | regra_exclusao |
| --- | --- | --- | --- | --- | --- |
| `financeiro_realizado` | `data_liquidacao` | `codigo_titulo + documento_origem` | Titulo liquidado no ERP | Liquidacao sem vinculo de origem reconhecido → registrar com `documento_origem` nulo, nao omitir | Titulos em aberto, inadimplentes ou sem baixa confirmada |
| `financeiro_a_receber` | `data_vencimento` | `codigo_titulo + documento_origem` | Titulo em aberto no ERP | Titulo vencido sem baixa → manter como `a_receber` com flag `em_atraso` | Titulos ja liquidados ou cancelados |
| `inadimplencia` | `data_vencimento` | `codigo_titulo + documento_origem` | Titulo vencido sem baixa | Contestacao de valor: manter como inadimplente ate resolucao formal | Titulos com prorrogacao formal de vencimento confirmada |

### Chaves Naturais (resumo executivo)

| Dominio | Chave de negocio | Chave tecnica / fallback |
| --- | --- | --- |
| Pedido | `codigo_pedido` | `pedido_id_omie` |
| Nota fiscal | `chave_nfe` | `nota_key` |
| Titulo financeiro | `codigo_titulo + documento_origem` | id do titulo no ERP |
| Cliente | `codigo_cliente_omie` | documento do cliente |
| Vendedor | `codigo_vendedor_omie` | nome apenas como apoio visual |

### Contrato de Fonte de Verdade por Endpoint

Cada endpoint do BIomie deve declarar `source_of_truth` na resposta. Valores validos:

| source_of_truth | Significado |
| --- | --- |
| `canonical` | Dado lido da camada canonica do BIomie (fonte primaria) |
| `legacy` | Dado lido diretamente do Omie por consulta legada (fallback temporario) |
| `snapshot` | Dado lido de snapshot/cache consolidado (derivado da camada canonica) |
| `unknown` | Fonte nao declarada — indica gap de instrumentacao |

Nenhum endpoint pode retornar `unknown` em producao estavel.

### Contrato de Frescor Minimo

Cada endpoint deve responder com os seguintes campos obrigatorios:

- `source_of_truth` — origem efetiva do dado
- `coverage_start` — inicio da janela de dados coberta
- `coverage_end` — fim da janela de dados coberta
- `last_success_at` — ultima sincronizacao bem-sucedida do dominio
- `last_reconcile_at` — ultimo reconcile concluido do dominio

Esses campos devem aparecer em `data_contract` (envelope completo) e espelhados na raiz do payload.

### Estados Operacionais Aceitos para KPI

| Estado | Significado | Comportamento |
| --- | --- | --- |
| `ok` | Evidencia suficiente e consistente | Elegivel para KPI do dominio |
| `pendente_validacao` | Sinal util, falta confirmacao exigida pelo contrato | Aparece operacionalmente, nao entra em KPI liquido |
| `divergente` | Conflito entre evidencias ou regra violada | Exige conciliacao; permanece visivel como excecao |

### Criterio de Saida da Fase 0

- Cada endpoint declara `source_of_truth` na resposta.
- Cada KPI tem data oficial, chave natural, fonte primaria, regra de conflito e regra de exclusao documentadas neste contrato.
- `healthz.php` e `api/status.php` expõem `coverage_start`, `coverage_end`, `last_success_at`, `last_reconcile_at` e `source_of_truth` por dominio.
- Divergencia deixa de ser surpresa e vira dado monitorado.

Status: **concluida**.

---

## Objetivo

Definir, para cada entidade e KPI critico do BIomie, qual evidencia do Omie possui precedencia, qual data oficial deve ser usada, qual chave natural identifica o registro e como conflitos entre dominios devem ser tratados.

Este documento existe para transformar suposicoes operacionais em contrato explicito. A partir daqui, qualquer evolucao do projeto deve ser validada contra estas regras.

## Principios

- Evidencia observada vence inferencia derivada.
- Nenhum dominio promove sozinho o estado de outro dominio sem regra explicita.
- Toda consolidacao precisa ser rastreavel ate uma chave natural e uma evidencia de origem.
- Quando houver conflito entre fontes, o sistema deve classificar o caso como `pendente_validacao` ou `divergente`, e nao mascarar a inconsistencia.
- Toda metrica deve declarar sua data de referencia oficial.

## Datas Oficiais

| Indicador ou leitura | Data oficial | Observacao |
| --- | --- | --- |
| Competencia comercial | `data_emissao` | Base oficial para leituras comerciais por periodo. |
| Overview comercial | `data_emissao` | Coerente com a leitura canonica atual. |
| Vendedores | `data_emissao` | Mantem comparabilidade entre ranking e competencia. |
| Pedidos por periodo | `data_emissao` | Base principal de leitura comercial. |
| Plano de voo e metas | `data_emissao` | Mantem o contrato atual da rota comercial. |
| Pedido faturado | `data_faturamento` | Evento comercial de faturamento do pedido. |
| Pedido aberto | `data_hora_ultima_etapa_valida` | Deve refletir a ultima etapa valida conhecida. |
| Carteira / backlog | `data_hora_ultima_etapa_valida` | Sempre ancorado na ultima etapa valida. |
| Receita fiscal | `data_competencia` | Data fiscal oficial do KPI liquido. |
| NF cancelada | `data_cancelamento` | Cancelamento prevalece para KPI liquido. |
| Financeiro realizado | `data_liquidacao` | Nao promove sozinho estado comercial ou fiscal. |

## Chaves Naturais

| Dominio | Chave de negocio | Chave tecnica ou fallback | Observacao |
| --- | --- | --- | --- |
| Pedido | `codigo_pedido` | `pedido_id_omie` | `codigo_pedido` e a referencia principal de negocio. |
| Nota fiscal | `chave_nfe` | `nota_key` | `nota_key` continua como fallback de ingestao. |
| Financeiro | `codigo_titulo + documento_origem` | id do titulo no provedor, quando existir | Deve manter vinculo com o documento de origem. |
| Cliente | `codigo_cliente_omie` | documento do cliente, quando necessario para conciliacao | Nao substituir a chave primaria funcional. |
| Vendedor | `codigo_vendedor_omie` | nome somente como apoio visual | Nome nao deve ser chave dominante. |

## Politica de Conflito

- O painel nao deve travar por divergencia isolada.
- KPI liquido so deve considerar evidencia valida do proprio dominio.
- Conflitos continuam visiveis para operacao e conciliacao.
- O dominio comercial pode reconhecer `faturamento_pendente_validacao_fiscal`, mas isso nao entra em `receita_fiscal`.
- O dominio financeiro nao promove sozinho estado comercial nem fiscal.
- Divergencias devem alimentar o mecanismo existente de reconciliacao e monitoramento.

## Estados Aceitos

| Estado | Significado | Comportamento esperado |
| --- | --- | --- |
| `ok` | Evidencia suficiente e consistente | Registro elegivel para KPI do dominio. |
| `pendente_validacao` | Existe sinal util, mas falta confirmacao exigida pelo contrato | Registro pode aparecer operacionalmente, mas nao entra em KPI liquido indevido. |
| `divergente` | Ha conflito entre evidencias ou regra violada | Registro exige conciliacao e deve permanecer visivel como excecao. |

## Matriz Inicial

### 1. pedido_aberto

Definicao: pedido cuja ultima etapa valida conhecida indica processo comercial em andamento e que nao possua encerramento definitivo por faturamento concluido, cancelamento ou devolucao final.

Fonte primaria: pedido de venda com ultima etapa valida.

Fonte secundaria: historico de etapas, quando disponivel.

Regra de conflito: se houver indicio simultaneo de faturamento concluido e etapa ainda aberta, classificar como `divergente`.

### 2. pedido_faturado

Definicao: pedido com evidencia de faturamento reconhecida no dominio comercial.

Fonte primaria: vinculo de faturamento do pedido no Omie.

Confirmacao secundaria: NF vinculada valida.

Regra de conflito: vinculo de faturamento sem NF valida nao promove receita fiscal; classificar como `pendente_validacao`.

### 3. nf_autorizada

Definicao: nota fiscal eletronica autorizada e valida para fins fiscais.

Fonte primaria: consulta fiscal do Omie.

Fonte secundaria: documentos fiscais vinculados.

Regra de conflito: se houver autorizacao e posterior cancelamento, o estado liquido passa a refletir o cancelamento.

### 4. nf_cancelada

Definicao: nota fiscal com evidencia valida de cancelamento.

Fonte primaria: consulta fiscal do Omie.

Fonte secundaria: documentos fiscais vinculados.

Regra de conflito: cancelamento prevalece sobre autorizacao quando o KPI exigir valor liquido.

### 5. carteira_backlog

Definicao: pedidos ainda nao faturados definitivamente e nao cancelados, considerando sempre a ultima etapa valida conhecida.

Fonte primaria: pedido com etapa vigente.

Fonte secundaria: historico de eventos do pedido.

Regra de conflito: pedido com estado fiscal concluido e etapa comercial ainda aberta entra em `divergente`, nao em carteira normal.

### 6. receita_comercial

Definicao: valor reconhecido pelo contrato comercial do BIomie como faturamento comercial do pedido.

Fonte primaria: contrato de negocio do dominio comercial.

Fonte secundaria: evidencia fiscal apenas para conferencia e divergencia.

Regra de conflito: diferenca entre faturamento comercial e evidencia fiscal deve aparecer como diferenca entre dominios, sem mascaramento.

### 7. receita_fiscal

Definicao: soma das notas fiscais autorizadas validas, liquida de cancelamentos, denegacoes e devolucoes conforme regra fiscal definida.

Fonte primaria: NF autorizada valida.

Fonte secundaria: documentos fiscais e vinculo com pedido.

Regra de conflito: cancelamentos, denegacoes e devolucoes ajustam o valor liquido do indicador.

### 8. financeiro_realizado

Definicao: valor efetivamente liquidado no financeiro e vinculado ao documento de origem reconhecido pelo ERP.

Fonte primaria: titulo liquidado.

Fonte secundaria: vinculo com pedido, NF ou documento de origem.

Regra de conflito: nao altera sozinho status comercial nem fiscal.

## Lacunas Conhecidas de Implementacao

- O BIomie ja guarda `hash_payload` na camada canonica, mas ainda nao possui camada bruta-imutavel explicita por dominio.
- A linha do tempo de etapas ainda nao esta persistida como trilha de eventos reutilizavel.
- Parte da aplicacao ainda opera em modo hibrido entre leitura canonica e snapshot legado.
- A data fiscal oficial atual usa `data_competencia`, derivada hoje por emissao ou cancelamento, sem uma camada propria de eventos fiscais intermediarios.
- `nota_key` e `chave_nfe` ainda convivem como referencias complementares de ingestao e negocio.

## Criterio de Saida da Fase 1

A Fase 1 e considerada concluida quando toda leitura de KPI critico do BIomie puder declarar explicitamente:

- qual data usa
- qual chave usa
- qual evidencia vence
- como conflito e tratado

Status desta fase: concluida.

## Fase 2 - Camada Bruta-Imutavel por Dominio

### Objetivo

Criar a base de auditabilidade do BIomie para sustentar a matriz de verdade da Fase 1, preservando evidencia bruta, permitindo replay, auditoria, reparo retroativo e separacao clara entre ingestao e interpretacao.

### Dominios Prioritarios

- pedidos
- notas fiscais
- financeiro
- cadastro de vendedor
- cadastro de cliente
- eventos e etapas, quando disponiveis

### Implementacao Inicial Ja Entregue

- Tabela bronze generica `biomie_omie_bronze` criada para persistencia bruta append-only.
- Captura de payload bruto integrada aos repositórios `OmieRepository` e `FinanceRepository`.
- Registro de `endpoint`, `omie_method`, `request_params_json`, `payload_json`, `payload_hash`, `natural_key`, `provider_key`, `request_id`, `source` e `ingested_at`.
- Gravacao idempotente por `idempotency_key`, evitando duplicidade destrutiva em replays iguais.
- Cobertura inicial ligada nos fluxos de sync canonico, sync financeiro, reconciliacao, refresh em background e APIs que ainda leem Omie diretamente.

### Estrutura Alvo em Camadas

- Bronze: payload bruto Omie, auditavel e imutavel.
- Silver: pedidos, notas, clientes, vendedores, etapas e vinculos normalizados.
- Gold: KPI, snapshots por competencia, ranking, backlog e reconciliacao gerencial.

### Requisitos de Produto e Arquitetura

- Preservar o payload bruto por dominio sem sobrescrever a evidencia original.
- Manter chave natural, chave tecnica do provedor e hash do payload.
- Registrar carimbo de ingestao, origem da coleta e contexto minimo do sync.
- Permitir reprocessamento sem depender de uma nova consulta externa para reconstruir o estado interpretado.
- Separar claramente tabela ou armazenamento de ingestao bruta da camada canonica interpretada.
- Preparar o sistema para reparo retroativo de mudancas tardias, cancelamentos, devolucoes e reconciliacoes.

### Fora do Escopo da Fase 2

- Redesenho visual de telas.
- Recalibracao de KPI final.
- Mudanca de regra de negocio ja cravada na Fase 1.
- Reescrita completa da camada canonica atual.
- Persistencia completa de linha do tempo de etapas e eventos, que continua como proximo incremento da Fase 2.

### Criterio de Saida da Fase 2

A Fase 2 sera considerada concluida quando cada dominio priorizado puder:

- armazenar evidencia bruta imutavel
- identificar cada registro por chave natural e hash
- permitir replay controlado
- sustentar auditoria de transformacao ate a camada canonica
- viabilizar reparo retroativo sem inferencia opaca

### Relacao entre Fase 1 e Fase 2

A Fase 1 definiu o contrato semantico.

A Fase 2 deve garantir que esse contrato seja auditavel, reprocessavel e resiliente a mudancas tardias do Omie.

## Fase 3 - Modelo Orientado a Eventos do Omie

### Objetivo

Trocar o eixo central da integracao de um retrato incompleto para uma linha do tempo confiavel de eventos do pedido.

### Implementacao Inicial Ja Entregue

- Event store canonico minimo `biomie_eventos_canonicos`.
- Sincronizacao de eventos a partir do endpoint oficial `PedidoVendaEtapa` (`ListarEtapasPedido`).
- Persistencia append-only com `event_type`, `event_at_omie`, `event_order`, `event_user`, `stage_code`, `document_key` e `source_payload_json`.
- Idempotencia por `idempotency_hash`, permitindo replay sem duplicacao logica.

### Eventos Canonicos Iniciais

- `pedido_incluido`
- `pedido_etapa_alterada`
- `pedido_faturado`
- `nfe_autorizada`
- `nfe_denegada`
- `pedido_cancelado`
- `pedido_devolvido`

### Observacao Importante

O endpoint `PedidoVendaEtapa` fornece um eixo temporal forte para comercial, faturamento, autorizacao e situacoes operacionais do pedido.

A geracao de `nfe_cancelada` ainda deve ser consolidada em etapa posterior a partir da evidencia fiscal mais apropriada, sem assumir que o cancelamento do pedido represente cancelamento fiscal da NF-e.

## Fase 4 - Tempo Real + Reparo

### Objetivo

Fazer o BIomie operar com tres ritmos combinados:

- webhook para baixa latencia
- polling delta para reparar lacunas curtas
- reconciliacao programada para garantir consistencia historica

### Implementacao Inicial Ja Entregue

- Inbox duravel append-only `biomie_webhook_inbox`.
- Cursores por dominio e grupo de endpoint em `biomie_sync_cursors`.
- Run log operacional em `biomie_sync_runs`.
- Endpoint publico de ACK rapido em `api/omie_webhook.php`.
- Worker assincro de webhook em `cron/process_webhook_inbox.php`.
- Pipeline operacional de `delta`, `reconcile` e `backfill` em `cron/sync_realtime_pipeline.php`.
- Status operacional admin-only em `api/realtime_sync_status.php`.

### Contrato Atual da Fase 4

- O webhook so persiste bronze + inbox e responde `202`.
- O worker de inbox nao fecha KPI; ele apenas abre janela de reparo e agenda refresh leve.
- O delta por enquanto opera em dois grupos reais do projeto:
- `commercial`, cobrindo pedidos, etapas, fiscal operacional e cadastros que hoje piggybackam na camada canonica.
- `finance`, cobrindo refresh operacional do snapshot financeiro.
- O reconcile usa janela movel configuravel.
- O backfill usa reprocessamento controlado por periodo.

### Estados Operacionais Ja Padronizados

- Inbox: `persistido`, `enfileirado`, `processado`, `falhou_temporariamente`, `falhou_definitivamente`, `ignorado_por_deduplicacao`.
- Jobs e cursores: `running`, `completed`, `completed_with_warnings`, `failed_retryable`, `failed_terminal`.

### O Que Ainda Fica para Evolucao

- Expandir executores dedicados por dominio fino, em vez de agrupar varios fluxos em `commercial`.
- Evoluir observabilidade para painel visual interno.
- Ligar webhooks Omie homologados por endpoint real do portal e calibrar cadencias finais por volume observado.

## Fase 5 - Separacao Clara entre Comercial, Fiscal e Financeiro

### Objetivo

Parar de comparar grandezas diferentes dentro do BIomie e deixar cada dominio falar apenas sobre sua propria evidencia.

### Implementacao Inicial Ja Entregue

- `pedido_comercial` consolidado em `biomie_pedidos_canonicos`.
- `evento_pedido` consolidado em `biomie_eventos_canonicos`.
- `nota_fiscal` consolidada em `biomie_notas_fiscais_canonicas`.
- `documento_xml` formalizado em `biomie_documentos_xml_canonicos`.
- `titulo_financeiro` formalizado em `biomie_titulos_financeiros_canonicos`.
- `snapshot_indicadores` mapeado para `biomie_competencia_snapshots`.
- Endpoint admin-only para mapa de entidades em `api/domain_entity_map.php`.
- Sync canonico financeiro em `OmieFinanceCanonicalSyncService`, sem promover automaticamente comercial ou fiscal.

### Regra Estrutural Ja Assumida

O financeiro pode referenciar pedido ou NF quando houver trilha de origem, mas nao corrige o dominio comercial ou fiscal sozinho.

### Mapa Atual de Entidades

- `pedido_comercial`: comercial, com pedido, etapa vigente, previsao, vendedor, cliente e carteira.
- `evento_pedido`: comercial, com historico temporal de mudancas do pedido.
- `nota_fiscal`: fiscal, com emissao, autorizacao, cancelamento e chave da NF-e.
- `documento_xml`: fiscal, separado da nota para sustentar trilha documental mesmo quando o XML ainda nao foi coletado.
- `titulo_financeiro`: financeiro, com contas a receber, baixas, recebido, atraso e competencia financeira.
- `snapshot_indicadores`: gold, consolidacao derivada para consumo de painel.

## Fase 6 - Reconciliacao Exata com Tipologia

### Objetivo

Transformar divergencia em processo operacional claro, com tipologia, severidade, fila e rastreabilidade.

### Implementacao Inicial Ja Entregue

- Catalogo formal de divergencias em `ExactReconciliationService`.
- Tipologia inicial cobrindo:
- `pedido_sem_vendedor`
- `nf_sem_pedido_vinculado`
- `pedido_faturado_sem_nf_valida`
- `nf_autorizada_sem_vendedor_resolvido`
- `pedido_cancelado_com_nf_ativa`
- `pedido_em_carteira_ja_faturado`
- `diferenca_valor_pedido_nf`
- `diferenca_data_comercial_fiscal`
- `diferenca_cliente_pedido_nf`
- `duplicidade_chave_natural`
- `mudanca_retroativa_detectada`
- Fila administrativa persistida em `biomie_divergencias`, agora com `rule_code`, `severity`, `entity_type`, `source_domain`, `source_origin`, `first_detected_at`, `last_detected_at`, `occurrence_count`, `suggested_action` e `operational_status`.
- Estados operacionais formais: `aberta`, `reincidente`, `resolvida`, `ignorada_regra`.
- Endpoint admin-only para leitura e operacao da fila em `api/reconciliation_queue.php`.
- Runner CLI para rotina em `cron/reconcile_exact_queue.php`.

### Regra Estrutural Ja Assumida

Toda divergencia deve declarar:

- severidade
- entidade afetada
- fonte de origem
- primeira deteccao
- ultima ocorrencia
- regra que acusou
- acao sugerida
- status operacional

### O Que Ainda Fica para Evolucao

- Painel visual dedicado para a fila, acima do endpoint JSON.
- SLA por severidade e workflow de ownership funcional.
- Automacoes de reprocessamento dirigidas por tipo de divergencia.

## Fase 7 - Politica Tecnica de Consulta Omie

### Objetivo

Reduzir latencia e risco de bloqueio sem perder exatidao, formalizando uma politica por metodo Omie com page size, concorrencia, janela, retry e fallback.

### Implementacao Inicial Ja Entregue

- Registro central de politica por metodo em `OmieMethodPolicyRegistry`.
- Page size por metodo aplicado em `OmieRepository` e `FinanceRepository`.
- Retry do `OmieClient` agora guiado por politica de metodo.
- Cache curto para consultas sensiveis a redundancia, hoje aplicado em `ConsultarCliente`.
- Endpoint admin-only de consulta da politica em `api/omie_method_policy.php`.

### Politica Ja Formalizada

Cada metodo relevante do projeto agora declara explicitamente:

- `page_size_target`
- `max_concurrency`
- `window`
- `retry`
- `fallback`
- `redundant_cache_ttl_seconds`

### Direcao Estrutural

O BIomie passa a operar com a regra:

- listar por periodo antes de consultar item a item
- usar pagina grande onde o metodo suporta o volume
- reservar consulta pontual para reparo
- evitar reconsulta redundante ao mesmo ID em janela curta
- deixar fallback explicito, em vez de implícito no fluxo

### O Que Ainda Fica para Evolucao

- Enforcement distribuido de concorrencia por metodo entre processos, e nao apenas politica declarada.
- Telemetria por metodo Omie com taxa real de retry, latency e pagina efetiva.
- Ajuste fino de page size financeiro apos homologacao de volume real no ambiente de producao.

## Fase 8 - Contrato de Frescor e Completude

### Objetivo

Fazer cada endpoint critico declarar, junto com o dado, o quanto ele esta fresco, qual janela cobre e se ja passou por reconcile suficiente para uso gerencial.

### Implementacao Inicial Ja Entregue

- Servico central `DatasetFreshnessService` para enriquecer payloads sem quebrar o contrato antigo.
- Campos padronizados em `data_contract` e espelhados em `meta` e na raiz do payload:
- `source`
- `freshness_at`
- `coverage_start`
- `coverage_end`
- `reconciled_until`
- `staleness_reason`
- Enriquecimento aplicado aos endpoints criticos:
- `api/overview.php`
- `api/orders.php`
- `api/vendors.php`
- `api/finance_overview.php`
- `api/finance_titles.php`
- `api/reconciliation.php`
- Modos operacionais agora formalizados no contrato:
- `live`
- `delta`
- `reconciled`
- `stale`

### O Que o Contrato Agora Explicita

- origem efetiva da resposta
- marcador de frescor
- janela coberta
- estado de reconcile
- percentual conciliado
- divergencias abertas
- presenca de retry ou atraso operacional

## Fase 9 - Suite de Homologacao de Consistencia

### Objetivo

Parar de descobrir erro de dados pela interface, formalizando casos reais com entrada esperada, estado canonico esperado, KPI esperado e divergencia esperada.

### Implementacao Inicial Ja Entregue

- Runner CLI em `tests/run_consistency_homologation.php`.
- Fixtures versionadas em `tests/fixtures/consistency_homologation_cases.php`.
- Avaliador puro em `ConsistencyHomologationService`.
- Casos minimos cobertos:
- pedido autorizado sem NF
- pedido faturado com NF autorizada
- NF cancelada apos emissao
- pedido devolvido
- pedido sem vendedor
- vendedor inativo com historico valido
- cliente sem cadastro resumido
- mudanca retroativa em janela consolidada
- pedido previsto em um mes e faturado em outro
- competencia fiscal diferente da comercial

### Regra de Uso

- sempre rodar a suite antes de mexer em dashboard ou KPI sensivel
- ampliar fixtures quando nova regra fiscal/comercial entrar
- tratar fixture como contrato funcional, nao apenas como exemplo

## Fase 6 - Observabilidade Operacional Forte

### Objetivo

Responder rapidamente por que um numero saiu assim, conectando request, webhook, sync, reconcile, shadow validation e payload final por `correlation_id`.

### Entregas Implementadas

- trilha persistente de eventos operacionais em `biomie_operational_events`
- metricas append-only em `biomie_operational_metrics`
- spans/traces operacionais em `biomie_operational_spans`
- `correlation_id`, `trace_id` e `span_id` expostos no contexto de request/resposta
- instrumentacao inicial em:
- `OmieClient`
- `OmieRealtimeSyncService`
- `ExactReconciliationService`
- `ShadowValidationService`
- contratos de freshness anexando contexto de trace
- dashboard admin-only em `observabilidade.php`, consumindo `api/operational_observability.php`

### Painel Operacional

O endpoint operacional passa a resumir:
- jobs por dominio
- ultimo sucesso/falha
- p95 por janela de sync
- datasets stale
- shadow diffs por endpoint
- divergencias por regra/severidade
- tempo medio e p95 por metodo Omie

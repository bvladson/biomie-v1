# Entrega de Otimizacao de Desempenho

Data: 2026-06-18

## Resultado

As sete frentes do diagnostico foram implementadas sem alterar contratos comerciais,
regras monetarias ou permissoes.

| Frente | Antes | Depois |
|---|---:|---:|
| Geo summary | 1.077 ms / 8 SQL | 109 ms / 1 SQL |
| Bundle inicial financeiro | 1.319,9 KB / 38 arquivos | 483,1 KB / 22 arquivos |
| Bundle inicial geografia | aproximadamente 935 KB | 430,5 KB / 20 arquivos |
| Lazy-load de drawers | 11/12 checks | 12/12 checks |
| Governanca financeira | aproximadamente 3,6 s | 2,13 s frio / 1,25 s quente |
| Notas por territorio | 547 ms / 5 SQL | 188 ms / 2 SQL |
| Resolver financeiro | tabelas inteiras e schema incompatível | chaves do recorte, schema adaptativo |
| CA bundle CLI | alerta sem bundle | bundle confiavel resolvido |
| OPcache web | nao confirmado | ativo, timestamps validados |

## Implementacoes

### Telemetria autenticada

- Adicionado beacon autenticado com CSRF em `api/performance_beacon.php`.
- O navegador envia amostras reais de APIs para `endpoint_browser.jsonl`.
- Amostras do servidor continuam prioritarias; o browser funciona como fallback.
- O guard informa explicitamente quando ainda nao existe amostra real na janela.

### Assets e lazy-load

- `mapa_vendas.html` deixou de declarar assets ja gerenciados pelo registro canonico.
- Drawers do mapa voltaram a ser carregados sob demanda.
- Graficos, recebiveis, cobranca e governanca financeira foram separados em features.
- PWA, refresh de cache e linguagem comercial financeira carregam em idle.
- O bundle legado `main.css` deixou o caminho inicial do Financeiro e foi
  substituido por utilitarios dedicados.
- Mapa, Chart.js e modulos geograficos carregam por proximidade da viewport.
- O mapa de producao usa artefato minificado de 125,9 KB, mantendo a fonte
  legivel para manutencao e auditorias.
- Adicionado budget bloqueante de 850 KB brutos para o financeiro.

### Governanca financeira

- O resolver de identidade passou a buscar somente NFs, pedidos, clientes e bridges
  relacionados aos titulos do recorte.
- Compatibilidade corrigida para nomes reais de colunas do schema atual.
- Capacidade do schema e RBAC usam cache compartilhado.
- Base de titulos, identidades e acoes usa cache curto de 120 segundos.
- Registro de nova acao continua invalidando o cache financeiro.
- Criado `biomie_finance_collection_actions`, com indices por titulo, cliente,
  vendedor, operador, tipo e proxima acao.
- A escrita agora usa o read model estruturado e preserva `biomie_admin_logs`
  como trilha de auditoria.
- Migration aplicada com backfill automatico do historico existente.

### Geografia

- Resumo, segmentos, top compradores, top CNAEs e freshness passaram a ser lidos
  em uma unica consulta CTE/UNION.
- O contrato legado de `geo_summary.php` foi preservado.
- Resumo e pagina de notas territoriais passaram a usar uma unica consulta de
  dados, mais uma consulta consolidada de capacidades do schema.

### Infraestrutura

- Criado resolvedor central de CA bundle para banco, Omie e auditoria.
- Suporte a env, PHP ini, bundle do projeto, Git e caminhos Linux.
- `runtimez.php` exige sessao com `logs.view` ou token administrativo em header.
- O health check publico nao expoe mais versao PHP, SAPI ou estatisticas OPcache.
- Auditoria de infraestrutura aceita `--base-url` e token, e bloqueia OPcache web inativo.

### Retencao

- Criado job de retencao com backup JSONL gzip, hash SHA-256 e manifest.
- Na rodada foram arquivados e removidos 22.099 spans com mais de 30 dias;
  nenhuma metrica ou evento dentro da politica foi removido.
- O cron pode ser executado por `cronjobs/retention_cleanup.sh`.

### Analise estatica

- O total legado do PHPStan caiu de 741 para 733 ocorrencias.
- Os arquivos desta rodada possuem gate proprio sem baseline e zero erros:
  `composer phpstan-performance`.

### Planos SQL

- Titulos financeiros usam `idx_titulo_vencimento_status` com acesso `range`.
- Logs de cobranca usam `idx_biomie_admin_logs_event`.
- O mart geografico possui apenas 1.182 linhas no plano atual; o full scan agregado
  e mais barato que forcar um indice. Nenhum indice especulativo foi criado.

## Validacoes

- PHP lint: 617 arquivos, zero falhas.
- Testes: 53/53.
- JavaScript: 8 arquivos modificados sem erro de sintaxe.
- Query budgets: todos verdes.
- Lazy-load: 12/12.
- Geo performance: todos os budgets verdes.
- TLS banco: TLS_AES_256_GCM_SHA384.
- Verificacao TLS desativada: zero ocorrencias.
- CA CLI: resolvido.
- OPcache web: ativo e `validate_timestamps=1`.
- EXPLAIN: executado para financeiro, logs e mart geo.

## Pendencias externas ao escopo

- O PHPStan completo possui 733 erros preexistentes no projeto.
- O release guard passa 54/55 dominios; D13 falha por links tecnicos existentes na
  sidebar (`integracoes.php` e `observabilidade.php`).
- O SLO de producao precisa de navegacao autenticada real para formar a primeira
  janela de amostras. O beacon desta entrega passa a coletar essas amostras.

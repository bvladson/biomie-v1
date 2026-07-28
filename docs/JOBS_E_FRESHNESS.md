# Jobs e Freshness - BIomie MAG

## Politica de execucao

Requests web nao devem executar sync Omie pesado, materializacao ou backoff longo. O fluxo padrao e:

1. API valida login, permissao e CSRF.
2. API cria um job em `storage/cache/jobs`.
3. API responde `202 Accepted` com `job_id` e `status_url`.
4. Worker CLI processa o job com retry controlado.
5. `api/refresh_status.php?job_id=...` mostra o estado.

## Contextos Omie

- `web` e `webhook`: uma tentativa, timeout curto, `max_sleep_ms=0`.
- `cli` e `job`: retry maior, backoff com jitter, limite de sleep e logs detalhados.

Quando o Omie sinaliza retry em contexto web, o BIomie registra `omie.retry_suppressed_web` e deixa a operacao seguir pelo caminho de fila.

## Filas

- `high`: webhooks e tarefas curtas urgentes.
- `default`: sync comercial, financeiro e canonical.
- `low`: materializacoes, enriquecimentos e tarefas historicas.

Status de job:

- `queued`
- `running`
- `completed`
- `failed_retryable`
- `failed_terminal`
- `cancelled`

## Worker

Comandos recomendados:

```bash
php cron/worker.php --queue=high,default --limit=50 --sleep=1 --max-runtime=120
php cron/worker.php --queue=low --limit=20 --sleep=2 --max-runtime=120
```

O worker tenta usar `CronLock` via banco. Se o banco ainda estiver indisponivel, usa lock de arquivo em `storage/cache` para preservar a operacao local.

## Jobs iniciais

- `finance.snapshot.refresh`
- `sales.snapshot.refresh`
- `webhook.inbox.process`
- `canonical.sync`
- `clients.materialize`
- `geo.materialize`
- `geo_cnae.materialize`
- `commercial.materialize`

## Banco

A migration `Version20260506223000_BiomieJobs` prepara `biomie_jobs` para fila persistente em banco. Enquanto a conexao MariaDB/MySQL nao estiver estabilizada, o driver ativo permanece em arquivo.

## Validacao operacional

```bash
php tools/dev/lint_all.php
php bin/check_encoding.php
php cron/worker.php --queue=high,default --limit=1
```

Para uma requisicao web, a resposta esperada e `202` com:

```json
{
  "ok": true,
  "mode": "queued",
  "job_id": "...",
  "status_url": "refresh_status.php?job_id=..."
}
```

Falhas de job devem aparecer com `last_error`, `attempts` e status `failed_retryable` ou `failed_terminal`.

## Monitoramento via System Health (Fase 9)

O check `jobs` do System Health monitora o SLA do `gold_mart_refresh`:

| Último sucesso | Status |
|---|---|
| < 60 min | ok |
| 60–180 min | warning |
| > 180 min ou nenhum | critical |

O check `freshness` verifica `FreshnessService::forDomain()` para os domínios `gold`, `canonical`, `sales`, `fiscal`, `geo`.

```bash
# Verificar estado dos jobs via CLI
php tools/dev/system_health_check.php --check jobs,freshness

# Saída JSON para scripts
php tools/dev/system_health_check.php --check jobs,freshness --json
```

Ver também: `docs/SYSTEM_HEALTH.md`

# Configuração de Cron no Hostinger

> Versão: 2026-05-17

Guia específico para Hostinger Shared/Business — onde não há Supervisor nem `flock` confiável.

---

## Pré-requisitos

1. Descobrir o caminho real do PHP no Hostinger:
   ```bash
   # No terminal SSH do Hostinger:
   which php
   # Normalmente: /usr/bin/php ou /usr/local/bin/php8.x
   php -v   # confirmar versão >= 8.1
   ```

2. Descobrir o caminho raiz do projeto:
   ```bash
   pwd   # ex: /home/uXXXXXXX/domains/seu-dominio.com.br/public_html/biomie
   ```

3. Confirmar que `storage/logs/` existe e é gravável:
   ```bash
   ls -la /home/uXXXXXXX/domains/seu-dominio.com.br/public_html/biomie/storage/logs/
   ```

---

## Entradas de Cron (cPanel → Cron Jobs)

Substitua:
- `PHP_BIN` → `/usr/bin/php` ou o caminho real encontrado no passo 1
- `PROJECT_ROOT` → caminho completo do projeto (passo 2)

### 1. Worker — a cada 5 minutos

Processa jobs assíncronos enfileirados pela interface.

```cron
*/5 * * * * PHP_BIN PROJECT_ROOT/cron/worker.php --queue=high,default,low --limit=50 --max-runtime=240 --stop-when-empty --json >> PROJECT_ROOT/storage/logs/worker.log 2>&1
```

- `--max-runtime=240` garante que o processo encerra antes do próximo cron (5min = 300s)
- `--stop-when-empty` evita idle desnecessário em fila vazia
- `--json` produz log estruturado e machine-readable

### 2. Sync Omie comercial — a cada 15 minutos

Sincroniza pedidos, clientes, vendedores e NFs fiscais da API Omie.

```cron
*/15 * * * * PHP_BIN PROJECT_ROOT/cron/sync_realtime_pipeline.php --domains=commercial --mode=delta --json >> PROJECT_ROOT/storage/logs/sync-commercial.log 2>&1
```

### 3. Sync Omie financeiro — a cada 15 minutos (offset 7min)

Sincroniza títulos e movimentos financeiros. Offset de 7min evita conflito com sync comercial.

```cron
7,22,37,52 * * * * PHP_BIN PROJECT_ROOT/cron/sync_realtime_pipeline.php --domains=finance --mode=delta --json >> PROJECT_ROOT/storage/logs/sync-finance.log 2>&1
```

### 4. Materialização Gold — a cada 30 minutos

Recalcula os marts Gold (client_periodo, produto_periodo, geo etc.) com os dados locais mais recentes.

```cron
*/30 * * * * PHP_BIN PROJECT_ROOT/bin/materialize_marts.php --json >> PROJECT_ROOT/storage/logs/materialize.log 2>&1
```

### 5. Reconcile diário — 3h da manhã

Garante que os últimos 7 dias estejam consistentes com a API Omie.

```cron
0 3 * * * PHP_BIN PROJECT_ROOT/cron/sync_realtime_pipeline.php --domains=commercial,finance --mode=reconcile --reconcile=7 --json >> PROJECT_ROOT/storage/logs/reconcile.log 2>&1
```

---

## Exemplo completo (substituir caminhos)

```cron
*/5 * * * * /usr/bin/php /home/u123456/domains/meu-dominio.com.br/public_html/biomie/cron/worker.php --queue=high,default,low --limit=50 --max-runtime=240 --stop-when-empty --json >> /home/u123456/domains/meu-dominio.com.br/public_html/biomie/storage/logs/worker.log 2>&1
*/15 * * * * /usr/bin/php /home/u123456/domains/meu-dominio.com.br/public_html/biomie/cron/sync_realtime_pipeline.php --domains=commercial --mode=delta --json >> /home/u123456/domains/meu-dominio.com.br/public_html/biomie/storage/logs/sync-commercial.log 2>&1
7,22,37,52 * * * * /usr/bin/php /home/u123456/domains/meu-dominio.com.br/public_html/biomie/cron/sync_realtime_pipeline.php --domains=finance --mode=delta --json >> /home/u123456/domains/meu-dominio.com.br/public_html/biomie/storage/logs/sync-finance.log 2>&1
*/30 * * * * /usr/bin/php /home/u123456/domains/meu-dominio.com.br/public_html/biomie/bin/materialize_marts.php --json >> /home/u123456/domains/meu-dominio.com.br/public_html/biomie/storage/logs/materialize.log 2>&1
0 3 * * * /usr/bin/php /home/u123456/domains/meu-dominio.com.br/public_html/biomie/cron/sync_realtime_pipeline.php --domains=commercial,finance --mode=reconcile --reconcile=7 --json >> /home/u123456/domains/meu-dominio.com.br/public_html/biomie/storage/logs/reconcile.log 2>&1
```

---

## O que NÃO pode entrar nos logs

Os scripts já sanitizam via `SecretMask`, mas confirme que os arquivos de log **nunca contenham**:

- `APP_SECRET` ou `APP_KEY` completa
- Payload completo com dados sensíveis de clientes
- Stack traces com paths internos
- Credenciais do banco de dados

Se suspeitar de vazamento, truncar o log:

```bash
> PROJECT_ROOT/storage/logs/sync-commercial.log
```

---

## Verificação após configurar

```bash
# 1. Aguardar 5-10 minutos, então verificar:
tail -50 PROJECT_ROOT/storage/logs/worker.log
tail -50 PROJECT_ROOT/storage/logs/sync-commercial.log

# 2. Rodar diagnostic:
php PROJECT_ROOT/tools/dev/omie_sync_status.php

# 3. Verificar System Health (deve sair de CRITICAL → WARNING → OK):
php PROJECT_ROOT/tools/dev/system_health_check.php --json | grep omie_sync
```

---

## Regras operacionais

| Regra | Motivo |
|---|---|
| GET de página nunca chama sync Omie | Protege performance e rate limit |
| Cada domínio tem log separado | Facilita diagnóstico isolado |
| `--max-runtime` sempre menor que o intervalo do cron | Evita processos sobrepostos |
| Materialização Gold APÓS sync | Gold com dado stale é misleading |
| Reconcile diário cobre gaps de webhook | Garante consistência sem depender de push |

---

## Troubleshooting

| Sintoma | Diagnóstico | Ação |
|---|---|---|
| Health `omie_sync_freshness` = CRITICAL | Sync nunca rodou ou credenciais Omie inválidas | Checar log + rodar `php cron/sync_realtime_pipeline.php --domains=commercial --json` manualmente |
| Health `worker_queue` = CRITICAL + jobs pendentes | Worker cron não configurado ou parado | Adicionar entrada de cron do worker |
| Health `gold_materialization` = WARNING | Materialize nunca rodou | Rodar `php bin/materialize_marts.php --json` manualmente |
| Log vazio após 5min | Caminho PHP incorreto | Verificar `which php` e ajustar cron |
| `Permission denied` no log | Permissões de `storage/logs/` | `chmod 775 storage/logs/` |
| Rate limit Omie (429) | Cron muito frequente | Aumentar intervalo de sync para 20-30min |

---

## Referências

- [sync-worker-cron-contract.md](sync-worker-cron-contract.md) — regras arquiteturais
- [cron-production-examples.md](cron-production-examples.md) — exemplos com Supervisor/VPS
- `tools/dev/omie_sync_status.php` — diagnóstico operacional
- `api/omie_status.php` — diagnóstico de credenciais Omie

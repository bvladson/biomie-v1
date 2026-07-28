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

## Entradas de Cron (hPanel → Hosting → Gerenciar → Cron Jobs)

> **Hostinger hPanel**: `hPanel → Hosting → Gerenciar → Advanced → Cron Jobs`
> Adicionar cada linha abaixo como um novo cron job.

Caminhos reais do servidor:
- `PHP_BIN` = `/usr/bin/php`
- `PROJECT_ROOT` = `/home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie`

> **Multi-empresa**: sync e materialize usam scripts wrapper (`sync_all_companies.sh` /
> `materialize_all_companies.sh`) que iteram automaticamente sobre as 5 empresas ativas.
> O worker é genérico e não precisa de `--company`.

### 1. Worker — a cada 5 minutos

Processa jobs assíncronos enfileirados pela interface.

```
*/5 * * * * /usr/bin/php /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/cron/worker.php --queue=high,default,low --limit=50 --max-runtime=240 --stop-when-empty --json >> /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/storage/logs/worker.log 2>&1
```

- `--max-runtime=240` garante que o processo encerra antes do próximo cron (5min = 300s)
- `--stop-when-empty` evita idle desnecessário em fila vazia

### 2. Sync Omie comercial (todas as empresas) — a cada 15 minutos

Sincroniza pedidos, clientes, vendedores e NFs para MAG_FILIAL, EXPERT, UNI_DESIGN, ERGOOFFICE, MAG_MATRIZ.

```
*/15 * * * * /bin/sh /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/cron/sync_all_companies.sh --domains=commercial --mode=delta >> /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/storage/logs/sync-commercial.log 2>&1
```

### 3. Sync Omie financeiro (todas as empresas) — offset 7min

Sincroniza títulos e movimentos financeiros. Offset de 7min evita conflito com sync comercial.

```
7,22,37,52 * * * * /bin/sh /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/cron/sync_all_companies.sh --domains=finance --mode=delta >> /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/storage/logs/sync-finance.log 2>&1
```

### 4. Materialização Gold (todas as empresas) — a cada 30 minutos

Recalcula os marts Gold para as 5 empresas.

```
*/30 * * * * /bin/sh /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/cron/materialize_all_companies.sh >> /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/storage/logs/materialize.log 2>&1
```

### 5. Reconcile diário full — 3h da manhã

Garante que os últimos 7 dias estejam consistentes com a API Omie.

```
0 3 * * * /bin/sh /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/cron/sync_all_companies.sh --domains=commercial,finance --mode=reconcile --reconcile=7 >> /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/storage/logs/reconcile.log 2>&1
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

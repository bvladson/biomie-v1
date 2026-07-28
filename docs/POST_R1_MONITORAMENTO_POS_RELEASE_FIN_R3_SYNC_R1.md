# Monitoramento Pós-Release (FIN.R3 / SYNC.R1)

Este guia orienta o monitoramento e a mitigação rápida de incidentes operacionais e de freshness nas 24–48h subsequentes à publicação do release.

## Objetivo
Garantir a estabilidade técnica e a integridade de dados após as correções da fila (`RefreshQueue`), recuperação numérica comercial de 25/05/2026 e a entrada do painel de cobrança por vendedor.

## Comandos Disponíveis

Para rodar o diagnóstico geral em modo formatado (pretty):
```bash
php tools/dev/post_release_monitor.php --pretty
```

Para rodar no pipeline ou retornar um payload JSON legível por máquina (com exit code):
```bash
php tools/dev/post_release_monitor.php --json
```

Para restringir o intervalo de verificação:
```bash
php tools/dev/post_release_monitor.php --start=2026-05-01 --end=2026-05-25 --pretty
```

---

## Critérios de Status e Interpretação

O diagnóstico retornará um de três estados base:

* **OK**: Todos os domínios funcionam nos conformes (fila vazia ou fluindo, lags de sync dentro dos prazos, marts com dados, nenhuma falha de sintaxe ou coluna inválida detectada nas últimas 24h).
* **WARNING**: Um ou mais alertas operacionais não bloqueantes. Exemplos: jobs retidos em estado de execução (`running`) há mais de 30 minutos, jobs arquivados na DLQ ou registros de falhas isoladas na telemetria.
* **CRITICAL**: Falhas estruturais de execução ou integridade que exigem intervenção humana imediata. Exemplos: erro de coluna inexistente (1054 / 42S22) detectado no dashboard comercial, desfasagem de sync maior que 36h para comercial ou 48h para financeiro, tabelas Gold vazias ou banco de dados inacessível.

---

## Manual de Mitigação (Runbooks)

### 1. Se o Commercial Dashboard voltar a dar HTTP 500
* **Sintoma**: Status `CRITICAL` no domínio `Commercial Dashboard`. Erro MySQL de coluna inexistente ou falhas de PDO.
* **Ação**:
  1. Verificar no repositório se alguma alteração reintroduziu colunas depreciadas:
     - `nota_fiscal_id` (deve ser `nota_key`)
     - `descricao` (deve ser `descricao_produto`)
     - `cnpj_cliente` (deve ser `documento_cliente`)
  2. Inspecionar o log detalhado de exceções estruturadas do BIomie:
     ```bash
     tail -n 20 storage/logs/biomie_errors.jsonl
     ```
  3. Reverter qualquer commit suspeito ou reaplicar o hotfix nas queries afetadas em `api/commercial_dashboard.php`.

### 2. Se a Fila de Refresh travar
* **Sintoma**: Status `WARNING` ou `CRITICAL` no domínio `RefreshQueue & Workers`, com jobs presos em `running` há muito tempo ou retidos na DLQ.
* **Ação**:
  1. Listar detalhes de jobs travados/pendentes:
     ```bash
     php tools/dev/worker_queue_debug.php --json
     ```
  2. Liberar travas antigas/expiradas do worker (por padrão expira em 15 minutos):
     ```bash
     php -r "require 'app/Bootstrap.php'; \BIomie\Bootstrap::init(); (new \BIomie\Queue\FileJobQueue('storage/cache'))->releaseExpiredLocks(900);"
     ```
  3. Se houver jobs failed_terminal acumulados, diagnosticar o motivo com `dead_letter_report.php` e disparar o redrive se corrigido:
     ```bash
     php tools/dev/dead_letter_report.php --pretty
     php tools/dev/dead_letter_redrive.php --apply --reason="Destravando fila pós correção"
     ```

### 3. Se o Sync Omie atrasar (Lag alto)
* **Sintoma**: Alerta de lag acima de 6 horas em ambiente comercial ou 8 horas no financeiro.
* **Ação**:
  1. Verificar se a tarefa cron do Hostinger está habilitada e disparando o script `cron/sync_realtime_pipeline.php`.
  2. Executar uma sincronização forçada manual de forma síncrona:
     ```bash
     php cron/sync_realtime_pipeline.php --domains=commercial,finance --json
     ```
  3. Validar se as chaves da API do Omie continuam válidas acessando o status de conexões:
     ```bash
     php tools/dev/omie_sync_status.php --pretty
     ```

### 4. Se a Gold não materializar
* **Sintoma**: Tabelas `mart_` vazias ou sem atualização de data recente.
* **Ação**:
  1. Verificar o lock de execução anterior em `/storage/cache/locks/`. Se o script caiu sem liberar o lock, remova-o.
  2. Executar a rematerialização restrita forçada:
     ```bash
     php bin/materialize_marts.php --start=2026-05-25 --end=2026-05-26 --force
     ```
  3. Confirmar se a base canônica local possui dados antes de tentar rematerializar:
     ```bash
     php tools/dev/_tmp_dates_check.php
     ```

### 5. Se o painel FIN.R3.1 parar de responder
* **Sintoma**: Endpoint `api/finance_collection_by_vendor.php` retornando HTTP 500 ou JSON com erro.
* **Ação**:
  1. Rodar linter no arquivo correspondente para checar erros de digitação/sintaxe:
     ```bash
     php -l api/finance_collection_by_vendor.php
     ```
  2. Confirmar se a tabela canônica existe e tem integridade:
     ```bash
     php tools/dev/_schema_check.php
     ```
  3. Inspecionar o log do sistema em `storage/logs/biomie.log` procurando por erros na string `finance_collection_by_vendor`.

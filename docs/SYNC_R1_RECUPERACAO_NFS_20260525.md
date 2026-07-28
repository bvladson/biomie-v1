# Relatório de Recuperação do Pipeline e NFs de 25/05/2026 (SYNC.R1)

Este documento registra a execução controlada da recuperação do pipeline de vendas do dia 25/05/2026 e a correção de erros nos componentes de dashboard e fila.

---

## 1. Correções Realizadas

### A. Correção de Erros no Dashboard Comercial (`api/commercial_dashboard.php`)
Identificamos e corrigimos erros de sintaxe SQL originados de colunas inexistentes na query:
* Substituído `nota_fiscal_id` por `nota_key` na tabela de itens canônicos.
* Substituído `descricao` por `descricao_produto` na tabela de itens canônicos.
* Substituído `cnpj_cliente` por `documento_cliente` na tabela de notas fiscais canônicas.

### B. Correção na Fila de Atualização (`app/RefreshQueue.php`)
Corrigimos um bug de colisão no gerenciador da fila de refresh (`RefreshQueue`):
* O glob `queued_*.json` capturava e marcava como falhos os arquivos do worker geral (`queued_job_{queue}_*.json`).
* Ajustamos para glob explicitamente por `queued_sales_*.json` e `queued_finance_*.json` e corrigimos a mesma lógica na agregação de `summary()`.
* Desenvolvemos e executamos um script de re-enfileiramento na produção (`tools/dev/_tmp_recover_worker_jobs.php`) para restaurar e renomear com segurança os 5 jobs de webhook/materialização geral que tinham entrado em falha falsa por conta da colisão.

---

## 2. Execução da Recuperação (Backfill Restrito)

Para trazer as vendas do dia 25/05/2026 preservando as regras de produção:
1. **Upload das Correções:** Os arquivos `api/commercial_dashboard.php` e `app/RefreshQueue.php` foram implantados na produção via SSH/SFTP.
2. **Sincronização Restrita:** Executado o backfill do pipeline apenas para o dia 25/05:
   ```bash
   php cron/sync_realtime_pipeline.php --domains=commercial --backfill 2026-05-25 2026-05-25
   ```
   * *Resultado:* 14 Notas Fiscais (00001367–00001380), 8 Pedidos pendentes e 126 Eventos de pedidos importados com sucesso para a camada canônica.
3. **Execução do Refresh Queue:** Executado o pipeline de refresh de vendas para atualizar as projeções locais/cache:
   ```bash
   php cron/process_refresh_queue.php 5
   ```
4. **Materialização Gold:** Executada a consolidação dos marts no banco de produção limitada ao dia 25/05 a 26/05:
   ```bash
   php bin/materialize_marts.php --start=2026-05-25 --end=2026-05-26
   ```
5. **Limpeza de Caches:** Removidos 44 arquivos temporários de cache (`tools/dev/cache_clear.php --all`) e resetado o OPcache (`opcache_reset()`).

---

## 3. Validação Numérica e QA

### A. Resultados das NFs Recuperadas em `biomie_notas_fiscais_canonicas`
Verificamos a presença e os valores das Notas Fiscais 00001367 a 00001380:
* **Total de NFs:** 14 notas carregadas.
* **Valor Total de NFe (25/05):** R$ 49.552,07.
* **Frete Total (25/05):** R$ 60,00 (vinculado à NF 00001370).
* **Base Comercial Resultante (25/05):** R$ 49.492,07.
  * *Status:* **PASS** (Valor bate exatamente com o esperado de R$ 49.492,07 do Omie).

### B. Consistência nos Marts (`mart_vendedor_dia`)
* `dia = 2026-05-25`
* `valor_nfe` consolidado: **R$ 49.492,07**
* `nfes_emitidas` consolidado: **14**
  * *Status:* **PASS**

### C. Release Guard e Health Check (QA)
* **Schema Migrate Verify:** `OK` (Nenhum mismatch de esquema ou checksums de migração pendentes).
* **Release Guard (`biomie_release_guard.php`):** `PASS` (Todos os 9 domínios/regras de consistência comercial passaram com sucesso).
* **Health Check (`healthz.php`):** `PASS` (Retorno `{"ok":true}` completo na produção).

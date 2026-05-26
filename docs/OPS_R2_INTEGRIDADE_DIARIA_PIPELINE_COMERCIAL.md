# OPS.R2 — Integridade Diária do Pipeline Comercial e Fiscal

Este documento descreve as diretrizes, ferramentas e procedimentos operacionais criados para garantir a integridade diária do pipeline de dados comerciais e fiscais do BIomie.

## Motivação e Contexto

No dia **25/05/2026**, ocorreu um incidente operacional em que dados de faturamento do Omie não foram propagados corretamente para os painéis comerciais do BIomie. Os sintomas incluíam:
- Vendas exibidas nos painéis desatualizadas (parando em 23/05/2026).
- Fila do worker de atualização travada (`queued_job_*` travados).
- Chamadas à API do Omie gerando falhas operacionais silenciadas.

Para evitar que a tomada de decisões comerciais ocorra com dados defasados (desrespeitando os princípios de *Freshness* do SRE do Google e os pilares de qualidade de dados de completude, consistência e acurácia), foi estabelecida uma rotina automática e ferramentas dedicadas de auditoria.

## Ferramenta de Check de Integridade Diária

A ferramenta `tools/dev/daily_sales_integrity_check.php` compara os dados canônicos consolidados com os dados materializados em todas as tabelas Gold (marts).

### Comandos de Verificação

#### Execução para o dia atual:
```bash
php tools/dev/daily_sales_integrity_check.php --today --pretty
```

#### Execução para o dia anterior:
```bash
php tools/dev/daily_sales_integrity_check.php --yesterday --pretty
```

#### Execução para uma data específica:
```bash
php tools/dev/daily_sales_integrity_check.php --date=2026-05-25 --pretty
```

#### Execução com retorno de erro em caso de drift (ideal para pipelines/CI):
```bash
php tools/dev/daily_sales_integrity_check.php --date=2026-05-25 --fail-on-drift --tolerance=0.01
```

### Interpretação dos Resultados

O script analisa e compara a **Base Comercial Canônica** (com regras fiscais ativas, excluindo licitações e remessas, incluindo IPI e deduzindo frete/seguro/despesas) contra os seguintes marts:
1. `mart_vendedor_dia` (granularidade diária).
2. `mart_cliente_periodo` (granularidade mensal/competência).
3. `mart_produto_periodo` (granularidade mensal/competência — compara com valor de mercadoria).
4. `mart_compradores_por_geo` (granularidade mensal/competência).
5. `mart_vendas_por_cnae_cnpj` (granularidade mensal/competência).

O status pode ser:
- **`OK`**: Diferença absoluta entre canônico e marts está abaixo da tolerância especificada (padrão `R$ 0,01`).
- **`WARNING`**: Sem faturamento no dia (fim de semana ou feriado), sem dados canônicos e marts zerados. Nenhuma ação recomendada.
- **`CRITICAL`**: Descompasso financeiro detectado acima da tolerância, ou notas fiscais sem itens correspondentes importados.

---

## Procedimento de Recuperação Operacional

Caso a verificação aponte desvios (`CRITICAL`), a ferramenta `tools/dev/recover_sales_day.php` deve ser utilizada de forma guiada para restaurar a integridade.

### Fluxo Recomendado

1. **Simulação (Dry-Run)**:
   Verifique as ações recomendadas pelo assistente sem aplicar alterações:
   ```bash
   php tools/dev/recover_sales_day.php --date=2026-05-25 --dry-run --pretty
   ```

2. **Aplicação com justificativa auditável**:
   Para rodar de fato a correção, é obrigatório passar `--apply` e `--reason="..."` (gerando logs auditados):
   ```bash
   php tools/dev/recover_sales_day.php --date=2026-05-25 --apply --reason="Recuperando descompasso do dia 25 pós sync parcial" --pretty
   ```

### Ações Executadas Automaticamente pelo Recuperador:
- Se o canônico estiver zerado mas o Omie possuir dados: Executa a sincronização restrita da data.
- Se o canônico possuir dados mas os marts estiverem defasados: Executa a rematerialização restrita dos marts para o dia/mês afetado.
- Se os marts estiverem corretos mas a UI estiver defasada: Executa limpeza de cache de aplicação e opcache.

---

## Acordo de Nível de Serviço (SLA) Recomendado

1. **Janela de Freshness**: Os dados comerciais devem possuir um atraso máximo (*freshness SLA*) de **2 horas** em dias úteis durante o horário comercial (08:00 às 18:00).
2. **Auditoria Diária**: O check de integridade (`daily_sales_integrity_check.php`) deve ser executado automaticamente via CRON diariamente às **06:00** e às **19:00**.
3. **Tempo de Resolução**: Em caso de status `CRITICAL` fora de finais de semana, a recuperação automatizada via `recover_sales_day.php` deve ser disparada em até **30 minutos** pelo operador SRE de plantão.

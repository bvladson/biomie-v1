# AUTO.R1.1 - Today Sales Freshness

Data: 2026-05-26

## Resumo executivo

O dashboard do periodo 26/05/2026 a 31/05/2026 aparecia com KPIs zerados mesmo havendo sinais comerciais recentes.

A causa raiz confirmada em producao foi ordem/timing da automacao:

- o sync comercial trouxe NFs de 26/05 as 23:15;
- a ultima materializacao Gold tinha rodado antes, as 23:00;
- portanto o canonico fiscal ja tinha as vendas do dia, mas os marts ainda estavam atrasados.

O problema nao era regra comercial, Base Comercial NF, BiVisibilityScope ou conciliacao NF x titulo.

## Evidencia de producao

Diagnostico antes da correcao:

- `biomie_notas_fiscais_canonicas`: 12 NFs do dia, de `00001381` a `00001392`
- Base Comercial do dia: `R$ 9.983,40`
- itens fiscais: 19
- vendedores: 5
- `mart_vendedor_dia`: `R$ 0,00`
- marts mensais abaixo do canonico por `R$ 9.983,40`

Logs cron observados:

- `sync_commercial.log`: sync comercial bem-sucedido as 23:15 com 12 invoices inseridas e 10 pedidos pendentes inseridos
- `materialize.log`: materializacao anterior as 23:00, antes da chegada das NFs

Correcao operacional executada:

```bash
php bin/materialize_marts.php --start=2026-05-01 --end=2026-05-26
php tools/dev/daily_sales_integrity_check.php --date=2026-05-26 --pretty
```

Resultado depois:

- `mart_vendedor_dia`: `R$ 9.983,40`
- `mart_cliente_periodo`: `R$ 425.272,12`
- `mart_produto_periodo`: `R$ 422.731,71`
- `mart_compradores_por_geo`: `R$ 425.272,12`
- `mart_vendas_por_cnae_cnpj`: `R$ 425.272,12`
- `daily_sales_integrity_check`: OK

## Ajustes implementados

### Diagnostico read-only

Criado:

```bash
php tools/dev/today_sales_freshness_diagnosis.php --today --pretty
```

O script verifica, sem escrita:

- sync runs comerciais recentes;
- payloads bronze recentes;
- canonico fiscal;
- canonico de pedidos;
- itens de NF;
- marts Gold;
- sinais de cache;
- classificacao da causa provavel.

Tambem suporta probe Omie controlado com `--omie-probe`, sem persistir payloads.

### Consistencia de periodo do dashboard

Criado:

```bash
php tools/dev/dashboard_period_consistency_check.php --start=2026-05-26 --end=2026-05-31 --pretty
```

O check compara KPI, ranking, produtos, clientes e serie diaria contra a mesma janela. Ele tambem verifica se a tela parcial esta protegida contra mistura silenciosa com marts mensais ou snapshot antigo.

### Freshness comercial

A regra de fallback comercial foi endurecida:

- `commercial` pode usar NFs canonicas e pedidos canonicos;
- `commercial` nao usa mais clientes isoladamente como prova de venda recente;
- `finance` continua usando titulos financeiros;
- `customers` pode usar clientes;
- `geo` continua usando fontes geo.

Assim, `omie_sync_freshness` deixa de marcar vendas como OK apenas porque clientes foram atualizados.

### Cron comercial Hostinger

`cron/hostinger/sync_commercial.sh` passou a executar backfill restrito do dia por padrao:

```bash
php cron/sync_realtime_pipeline.php --domains=commercial --mode=backfill --start="$SYNC_DATE" --end="$SYNC_DATE" --json
```

Para delta antigo, usar:

```bash
BIOMIE_SYNC_MODE=delta /bin/sh cron/hostinger/sync_commercial.sh
```

### Daily integrity

Criados wrappers:

- `cron/hostinger/daily_integrity.sh`
- `tools/cron/biomie_daily_integrity.sh`

Eles registram log em `storage/logs/cron/daily_integrity.log` e usam lock para evitar overlap.

### Ordem sync -> materializacao

`cron/hostinger/materialize.sh` foi protegido contra corrida com os syncs:

- espera inicial padrao de 180 segundos (`BIOMIE_MATERIALIZE_START_DELAY_SECONDS`);
- espera liberacao dos locks `sync_commercial.lock` e `sync_finance.lock`;
- aborta com exit code 75 se algum sync continuar rodando alem de `BIOMIE_MATERIALIZE_WAIT_SYNC_SECONDS`.

Isso evita o caso observado no hPanel, em que `materialize.sh` e `sync_commercial.sh` podiam iniciar no mesmo minuto e a materializacao podia calcular Gold antes das NFs novas chegarem.

### Release Guard

Adicionado dominio:

- `D14 - Today Sales Freshness`

O D14 falha se:

- canonico tem vendas e marts nao batem;
- o dashboard mistura periodo parcial com fallback mensal indevido;
- o freshness comercial volta a usar clientes como prova de vendas;
- o diagnostico novo quebra tecnicamente.

## Decisoes

- Nao houve migration.
- Nao houve alteracao de schema.
- Nao houve escrita manual em raw/bronze.
- Nao houve alteracao de valores fiscais ou financeiros originais.
- A escrita executada em producao foi apenas rematerializacao Gold restrita a maio ate 26/05.

## Como validar amanha

1. Confirmar sync comercial:

```bash
tail -n 80 storage/logs/cron/sync_commercial.log
```

2. Confirmar materializacao posterior ao sync:

```bash
tail -n 80 storage/logs/cron/materialize.log
```

3. Rodar integridade do dia:

```bash
php tools/dev/daily_sales_integrity_check.php --date=$(date +%Y-%m-%d) --pretty
```

4. Rodar diagnostico de freshness:

```bash
php tools/dev/today_sales_freshness_diagnosis.php --today --pretty
```

5. Verificar dashboard no periodo de hoje ate fim do mes.

## Pendencias operacionais

- Garantir no hPanel que a materializacao rode depois do sync comercial, ou com frequencia suficiente para reduzir a janela de atraso.
- Acompanhar `D14` no release guard depois do deploy dos novos checks.

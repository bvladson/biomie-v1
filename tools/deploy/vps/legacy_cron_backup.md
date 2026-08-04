# Crons do host antigo — estado ANTES do corte (2026-08-03)

Capturado via MCP `hosting_listAccountCronJobsV1` (username `u106688109`) logo antes
de desligar. Serve para restaurar o host antigo caso o corte precise ser revertido.

Recriar com `hosting_createAccountCronJobV1`.

## Os 13 do BIomie — REMOVIDOS no corte

Todos apontam para `/home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/cron/hostinger/`.
Horários em **UTC** (o host antigo roda em UTC; na VPS foram convertidos para -03).

| uid | schedule (UTC) | script |
|---|---|---|
| `3A9sV2Baic` | `*/5 * * * *` | `worker.sh` |
| `zWuLhh6f4C` | `*/15 * * * *` | `sync_commercial.sh` |
| `iE9f9gx2Pu` | `*/15 * * * *` | `sync_finance.sh` |
| `JRf2myRfQX` | `*/15 * * * *` | `ops_sentinel.sh` |
| `vM3egv0krX` | `0,30 * * * *` | `materialize.sh` |
| `ZzKKxzFXwk` | `7,22,37,52 * * * *` | `quote_agenda_scan.sh` |
| `lqNssSrOYN` | `*/30 * * * *` | `quote_agenda_notify.sh stale` |
| `90QDmJ6NtT` | `0 11 * * 1-5` | `quote_agenda_notify.sh digest` |
| `34EwT2bgJg` | `30 1 * * *` | `enrich_clients_governance.sh` |
| `UFGxI5KHMg` | `0 2 * * *` | `compute_forecasts.sh` |
| `Rtbaf7C0iA` | `30 2 * * *` | `compute_opportunities.sh` |
| `bcCMKGfJMd` | `45 2 * * *` | `enrich_finance_titulos.sh` |
| `de20IQ9rXU` | `15 3 * * *` | `finance_future_receivables.sh` |

Comando completo (o mesmo para todos, trocando o script):

```
/bin/sh /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/cron/hostinger/<script>
```

## MANTIDO — não é do BIomie

| uid | schedule | comando |
|---|---|---|
| `ACGix1Gghk` | `* * * * *` | `/bin/sh /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/logistica/scripts/schedule-run.sh` |

O sistema `logistica` continua no host antigo — por isso o host antigo **não pode ser desligado**.

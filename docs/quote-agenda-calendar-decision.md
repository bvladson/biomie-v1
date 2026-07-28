# Decisão funcional: total do calendário da Agenda

O alerta estático em `QuoteAgendaReadService::calendar()` não representava um
branch impossível no domínio. O PHPStan inferia `known_value_count` como o
literal `0` porque a forma mutável do acumulador não estava declarada.

| Valores canônicos conhecidos no dia | Valores desconhecidos | Resultado |
| ---: | ---: | --- |
| 1 ou mais | qualquer quantidade | soma conhecida, `total_value` numérico |
| 0 | 1 ou mais | `total_value = null` |
| 0 | 0 | o dia não é criado |

O contrato foi explicitado com um array shape. O branch foi preservado porque
evita transformar “valor desconhecido” em zero. O teste
`tests/run_quote_agenda_freshness.php` cobre no mesmo dia um valor conhecido e
um desconhecido, comprovando o primeiro ramo e as contagens.


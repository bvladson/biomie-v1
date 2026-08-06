# Inteligência territorial Python

## Contrato

O mapa continua lendo vendas do `mart_compradores_por_geo`. O job
`geo-intelligence` agrega até doze competências por empresa e persiste em
`intelligence_geo_scores` score, potencial, forecast, faixa, confiança, motivo e
ação recomendada. O endpoint PHP é somente leitura, escopado pelo
`TenantContext`; o navegador não recalcula o resultado persistido.

## Confiança

- alta: ao menos seis meses e variação histórica inferior a 25%;
- média: ao menos três meses e variação inferior a 50%;
- baixa: demais casos.

Baixa confiança permanece gravada e visível no drawer, mas não colore o mapa
como recomendação. Antes da primeira execução do job, o modo Oportunidades usa
temporariamente a heurística legada do navegador.

## Operação

```bash
python -m biomie_intel geo-intelligence --company=all --dry-run
python -m biomie_intel geo-intelligence --company=all
```

O cron da VPS executa diariamente às 05:30. O health considera o job parado
após 30 horas sem sucesso e também informa quantidade, empresas e frescor dos
scores vigentes.

## Semântica

O baseline estima potencial por diferença de ticket contra a mediana da camada
e recuperação parcial da queda recente. Ele é deliberadamente explicável. Uma
versão estatística só deve substituí-lo depois que backtests demonstrarem lift
real no grupo priorizado.

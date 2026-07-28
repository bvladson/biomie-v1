# Orçamento de assets P3

Fonte viva: `docs/generated/asset-budget.json`.

O baseline registra bytes brutos e gzip de cada JS/CSS. O gate falha para asset
novo sem revisão ou crescimento gzip acima de 5%.

```bash
php tools/dev/asset_budget.php --enforce
```

O baseline mede tamanho de transferência. Tempo de parse, execução e cobertura
utilizada dependem de coleta no navegador autenticado e serão avaliados com RUM.


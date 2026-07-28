# BIomie — Roadmap Arquitetural 10/10

Este documento organiza a evolução do BIomie em fases pequenas, auditáveis e seguras. O objetivo é transformar o projeto em um cockpit comercial estável: rápido no pós-login, sem telas antigas presas em cache, com dados coerentes entre páginas, gráficos com estados vazios explicativos e diagnósticos operacionais.

## Princípios fixos

- Nenhuma página de leitura deve chamar Omie durante GET.
- Sync Omie, materialização Gold e renderização da tela são etapas separadas.
- Realizado fiscal usa NFs autorizadas por `data_competencia`.
- Carteira usa pedido por `data_emissao`.
- Meta usa competência mensal (`year_month`).
- Todo endpoint deve informar `source`, `period_basis`, `empty_states`, `diagnostics.request_id` e, quando aplicável, `cache`.
- Todo gráfico deve renderizar dados ou empty state; nunca card em branco.

## Fase 1 — Cache, Ctrl+F5 e versão de assets

### Por quê
O projeto ainda tinha Service Worker cacheando PHP/API. Isso explica páginas antigas voltando mesmo após Ctrl+F5.

### Entregas
- `sw.js` cacheia apenas assets versionados.
- PHP/API usam `no-store`.
- Assets usam `?v=filemtime` via `Asset::url()`.
- `cache-refresh.js` detecta troca de build e limpa caches do Service Worker.
- Checks de OPcache, assets e Service Worker no System Health.

### Arquivos
- `sw.js`
- `.htaccess`
- `app/Support/Asset.php`
- `app/Support/HttpCache.php`
- `assets/js/cache-refresh.js`
- `app/PageAssetRegistry.php`
- `app/PageRenderer.php`
- `app/SystemHealth/Checks/*Cache*`

### Aceite
Alterar CSS/JS e recarregar normalmente deve carregar a versão nova sem Ctrl+F5.

## Fase 2 — Pós-login rápido

### Por quê
O `index.php` não deve bloquear a primeira renderização montando dashboard completo.

### Entregas
- Boot pesado vira opt-in por `?boot=full`.
- `api/dashboard_boot.php` entrega KPIs rápidos e cacheáveis por curto período.
- A hidratação completa fica para o frontend via APIs.

### Arquivos
- `index.php`
- `api/dashboard_boot.php`
- `HomeBootstrapService.php` como legado controlado

### Aceite
Shell/skeleton aparece rápido; dados entram por blocos.

## Fase 3 — Contrato único de dados

### Por quê
Páginas diferentes precisam falar a mesma língua: fonte, base temporal, vazio e diagnóstico.

### Contrato recomendado
```json
{
  "ok": true,
  "source": "canonical|gold|fallback|empty",
  "period": {},
  "period_basis": {},
  "summary": {},
  "data": [],
  "empty_states": {},
  "diagnostics": { "request_id": "..." },
  "cache": {}
}
```

### Aceite
Nenhum card ou gráfico fica vazio sem explicar motivo e ação.

## Fase 4 — Mix Faturado 10/10

### Por quê
A tela exibia KPIs, mas gráficos e tabela ficavam vazios. Isso indica que o resumo de NF existia, mas o contrato de produtos/itens não chegava ao front.

### Entregas
- `ProductMixReadRepository` lê diretamente NFs + itens canônicos.
- `ProductMixReadService` retorna `products`, `top_products`, `product_mix`, aliases e empty states.
- `api/products.php` usa esta leitura antes do snapshot legado.
- `assets/js/app.js` normaliza aliases e mostra empty state.
- `tools/dev/products_mix_debug.php` diagnostica NF, itens, produtos e EXPLAIN.

### Fonte correta
`biomie_notas_fiscais_canonicas` + `biomie_notas_fiscais_itens_canonicos`, com `status_fiscal = autorizada` e `data_competencia`.

### Aceite
Se existem NFs no período, a tela precisa mostrar produtos, participação, tabela e detalhes; se não mostrar, precisa explicar exatamente por quê.

## Fase 5 — Mapa geográfico 10/10

### Por quê
Alguns bairros mostram faturamento/notas, mas não compradores. Isso é diferença entre resumo geográfico e detalhe de compradores.

### Próximas entregas
- Garantir `bairro_key`, `bairro_nome`, `bairro_normalizado`, `city_normalized` em todos os endpoints.
- Fallback de compradores deve filtrar cidade + bairro.
- Empty state nunca deve dizer “selecione um bairro” quando já existe bairro selecionado.
- Criar `GeoDetailConsistencyHealthCheck` mais profundo comparando resumo x detalhe.

### Aceite
Bairros com `clients_count > 0` mostram compradores ou motivo técnico correto.

## Fase 6 — Vendor 360 e Plano de Voo sem legado

### Entregas
- Remover drawer antigo de `views/vendedores.html` depois de validar o novo Vendor 360.
- Manter `orders_count` apenas como legado técnico.
- Garantir que plano, vendedores e drawer usem o mesmo `realized_total`.

### Aceite
Realizado do plano = Vendor 360 = NFs autorizadas por `data_competencia`.

## Fase 7 — Design System MAG

### Entregas
- Sidebar único baseado no visual do mapa.
- Cards menores e mais objetivos.
- Badges padronizados de `source` e `freshness`.
- Empty states reutilizáveis.

### Aceite
Trocar de página não muda drasticamente visual, proporção ou comportamento.

## Fase 8 — Observabilidade e QA

### Entregas
- Checks: Product Mix, Geo Detail, OPcache, Asset Version, Service Worker Cache, Dashboard Boot.
- Scripts CLI: `cache_clear.php`, `products_mix_debug.php`, `geo_sales_debug.php` expandido.
- Logs com `request_id`.

### Aceite
System Health mostra causa e ação recomendada para lentidão, dado vazio ou tela antiga.

## Fase 9 — Segurança e deploy limpo

### Entregas
- Deploy sem `.git`, logs, docs internas e tools dev.
- APIs sem stacktrace para o navegador.
- Secrets fora do repositório.

### Aceite
ZIP final de produção contém só o necessário para rodar.

## Fase 10 — Documentação arquitetural

### Entregas
- `docs/architecture-c4.md`
- `docs/data-contracts.md`
- `docs/cache-strategy.md`
- `docs/debug-playbook.md`

### Aceite
Um dev novo entende onde mexer, o que não quebrar e como validar.

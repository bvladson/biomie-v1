# Estudo: Imagens de Produto via Omie

**Status:** Feature flag only — não implementado em produção.

## Sumário

Este documento registra o estudo de viabilidade para exibir imagens de produto nas telas de mix faturado, itens de NF e drawers de produto do BIomie.

## Feature Flag

```
BIOMIE_PRODUCT_IMAGES_ENABLED=false
```

Definida em `config/env.example.php`. Por padrão `false` em todos os ambientes.

## O que o Omie oferece

O endpoint `ListarProdutos` da API Omie retorna, entre outros campos, um campo `imagem` (URL ou base64 dependendo da versão da API).

- Endpoint: `https://app.omie.com.br/api/v1/geral/produtos/`
- Ação: `ListarProdutos`
- Campo de imagem: `imagem` (pode ser URL externa ou Base64 JPEG/PNG)

## Riscos identificados

| Risco | Nível | Mitigação |
|---|---|---|
| Chamada Omie em request web | Crítico | **Proibido pelo absoluto da fase.** Sync deve ser feito em cron. |
| Volume de dados (Base64 por produto) | Alto | Armazenar URL, não Base64, em tabela canonical |
| Omie não garante disponibilidade de imagens | Médio | Feature degradável: exibir placeholder quando ausente |
| CDN / hosting de imagens | Médio | Usar URL Omie diretamente ou replicar para S3/similar |
| Custo de API por produto | Baixo-Médio | Sync incremental: só produtos sem imagem cacheada |

## Arquitetura proposta (quando implementar)

```
[Cron: sync_product_images]
  → ListarProdutos (Omie)
  → biomie_produtos_canonicos.imagem_url  ← salvar URL
  → não salvar Base64 diretamente

[API: api/products.php ou api/product_detail.php]
  → SELECT imagem_url FROM biomie_produtos_canonicos
  → retornar campo image_url no payload
  → null quando ausente → UI mostra placeholder

[UI: mix-faturado.php, drawer de produto]
  → <img src="{{ image_url }}" onerror="this.src='assets/img/product-placeholder.svg'">
  → Lazy loading com loading="lazy"
  → Dimensão máxima: 48×48 em listas, 120×120 em cards
```

## Tabela sugerida

Adicionar à migração de schema:

```sql
ALTER TABLE biomie_produtos_canonicos
  ADD COLUMN imagem_url VARCHAR(512) NULL DEFAULT NULL,
  ADD COLUMN imagem_synced_at DATETIME NULL DEFAULT NULL;
```

## Pré-requisitos antes de habilitar

- [ ] Coluna `imagem_url` na tabela `biomie_produtos_canonicos`
- [ ] Cron `sync_product_images.php` implementado e testado
- [ ] Placeholder SVG em `assets/img/product-placeholder.svg`
- [ ] Validação de URL (apenas HTTPS) antes de exibir
- [ ] QA: testar com produto sem imagem, URL quebrada, URL Base64 legada

## Decisão atual

**Não implementar** até que os pré-requisitos acima estejam completos e o time decida priorizar. A flag `BIOMIE_PRODUCT_IMAGES_ENABLED=false` documenta a intenção e permite habilitar sem deploy de código quando o cron estiver pronto.

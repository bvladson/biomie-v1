# UX.R11 - Vendor Chart e Geo Map

Data: 2026-05-27

## Objetivo

Corrigir o hover do ranking comercial em `vendedores.php` e redesenhar `mapa_vendas.php` para reduzir vazio visual e eliminar duplicidade entre ranking e detalhamento de bairro.

## Problemas identificados

### Ranking comercial em vendedores.php

O grafico horizontal "Ranking comercial - Orc-PD" usava Chart.js em barra horizontal com `indexAxis: 'y'`, mas a interacao estava em modo de indice sem eixo explicito. Em graficos horizontais empilhados isso pode fazer o tooltip procurar elementos pelo eixo errado quando o canvas e redimensionado ou quando existe muita area vertical.

Tambem nao havia altura calculada pelo numero de vendedores, o que aumentava risco de hitbox achatado ou desalinhado.

### Mapa de Vendas

A pagina tinha:

- mapa em uma coluna;
- painel lateral de detalhes sempre aberto;
- ranking de bairros em bloco separado abaixo;
- outro bloco "Detalhamento comercial por bairro" com informacao redundante;
- grandes vazios no layout em desktop.

## Correcoes aplicadas

### Chart.js

`assets/js/core/chart-factory.js` agora configura o ranking horizontal com:

- `indexAxis: 'y'`;
- `interaction.axis = 'y'`;
- `hover.axis = 'y'`;
- `tooltip.axis = 'y'`;
- `mode = 'nearest'`;
- altura dinamica baseada na quantidade de vendedores;
- `chart.resize()` apos render.

Os dados, datasets, ordem de vendedores e regras comerciais nao foram alterados.

## Novo layout do Mapa de Vendas

`views/mapa_vendas.html`, `assets/css/biomie-map.css` e `assets/js/biomie-map.js` foram reorganizados:

1. Header e filtros permanecem.
2. KPIs permanecem.
3. Area principal virou `geo-main-grid`:
   - esquerda: mapa;
   - direita: Ranking de bairros.
4. Ranking mostra todos os bairros em lista com scroll interno.
5. Ranking tem busca e ordenacao por:
   - faturamento;
   - clientes;
   - NFs;
   - ticket;
   - A-Z.
6. O card separado "Detalhamento comercial por bairro" foi removido.
7. Detalhes completos foram movidos para drawer lateral.

## Drawer de bairro

O drawer `geoNeighborhoodDrawer` abre ao clicar:

- em uma linha do ranking;
- em um poligono do mapa;
- no card de sem bairro.

Ele mostra:

- resumo do bairro;
- faturamento;
- clientes;
- notas;
- ticket medio;
- principais compradores;
- principais CNAEs;
- link Geo 360 quando aplicavel.

Controles:

- botao fechar;
- ESC fecha;
- backdrop fecha;
- foco inicial no botao de fechar;
- foco retorna para a linha clicada quando possivel.

## Leaflet

Foi mantido `invalidateSize`, agora tambem acionado em:

- abertura/fechamento do drawer;
- resize da janela;
- render inicial.

## UX.R11.1 - Compactacao do Ranking de bairros

Depois do primeiro redesenho, o ranking ainda podia participar da altura total do grid quando havia muitos bairros. Na pratica, os 71 bairros deixavam a pagina muito longa e o mapa crescia junto com a lista.

A correcao aplicada foi transformar o miolo mapa + ranking em um painel executivo com altura controlada:

- `geo-main-grid` agora usa `height: clamp(560px, 68vh, 680px)`;
- o mapa ocupa 100% da altura disponivel, sem crescer por causa da lista;
- o card do ranking usa `overflow: hidden`;
- a lista `geo-ranking-list` usa `overflow-y: auto`;
- busca, ordenacao e contador ficam fixos no topo do card;
- as linhas do ranking viraram itens compactos com posicao, bairro, cidade/NFs, faturamento e barra de participacao;
- todos os bairros continuam disponiveis por scroll interno;
- o drawer continua como overlay e nao empurra o layout;
- `map.invalidateSize({ pan: false })` continua sendo chamado em mudancas de layout.

Essa mudanca nao alterou contrato de API, dados comerciais, schema ou regras de materializacao.

## O que nao foi alterado

- Base Comercial NF;
- BiVisibilityScope;
- Licitacao;
- sync, cron, worker e materializacao;
- raw/bronze/canonical;
- schema/migrations;
- conciliacao NF x titulo;
- endpoints de dados.

## Diagnostico visual

Criado:

```bash
php tools/dev/visual_layout_guard.php --pretty
```

O guard verifica:

- hover do chart configurado no eixo y;
- altura dinamica do ranking comercial;
- `geo-main-grid`;
- altura controlada do grid mapa + ranking;
- scroll interno do ranking de bairros;
- card do ranking com overflow travado;
- drawer de bairro;
- remocao do detalhe duplicado;
- altura explicita do mapa;
- `invalidateSize`;
- busca no ranking;
- fechamento do drawer por ESC;
- ausencia de migration nova da fase.

## Checklist visual

### vendedores.php

- Ranking comercial carrega.
- Hover em cada vendedor mostra o vendedor correto.
- Tooltip nao pula para linha abaixo.
- Resize da janela nao quebra.
- Filtros continuam funcionando.

### mapa_vendas.php

- Mapa e ranking ficam lado a lado no desktop.
- Ranking tem scroll interno.
- Busca e ordenacao do ranking funcionam.
- Card duplicado de detalhamento nao aparece.
- Clique no ranking abre drawer.
- Clique no mapa abre drawer.
- Drawer fecha por botao, backdrop e ESC.
- Mobile usa drawer em formato de painel inferior.

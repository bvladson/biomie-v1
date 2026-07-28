# NF do Periodo - sistema visual

Este documento define a linguagem visual usada no card **Faturamento Orcamento-PD**
e nos drawers de Nota Fiscal e Pedido.

## Principios

- A linha inteira da NF abre o detalhe completo.
- Cliente, vendedor e pedido possuem drilldowns independentes.
- O valor principal da linha e sempre a **Base Comercial NF**.
- O total fiscal aparece como contexto secundario.
- Frete, desconto, seguro e outras despesas aparecem frontalmente como sinais.
- Vermelho deve ficar reservado para cancelamento, erro ou risco real.

## Cores semanticas

| Papel | Token / cor | Uso |
| --- | --- | --- |
| Comercial autorizado | `--nf-green` / `#15803d` | Base comercial, NF Orc-PD, estados positivos |
| Atencao financeira | `--nf-amber` / `#a15c07` | Frete, desconto, seguro e outras despesas |
| Navegacao e detalhe | `--nf-blue` / `#1d4ed8` | Botoes de detalhe, mapa, links e icones de drawer |
| Texto principal | `--nf-ink` / `#182230` | Titulos, nomes e valores de destaque |
| Texto auxiliar | `--nf-muted` / `#667085` | Datas, documentos e metadados |
| Cancelamento / erro | `--danger` / `#dc2626` | Somente cancelamentos, falhas e alertas criticos |

## Icones

O projeto usa **Material Symbols Rounded**, carregado globalmente na pagina.

| Icone | Significado |
| --- | --- |
| `receipt_long` | Nota fiscal / documento fiscal |
| `verified` | NF Orcamento-PD autorizada |
| `payments` | Base comercial ou valor financeiro |
| `account_tree` | Composicao da NF |
| `shopping_cart` | Pedido origem |
| `domain` | Cliente |
| `person` | Vendedor responsavel |
| `inventory_2` | Itens e mercadorias |
| `sell` | Desconto |
| `local_shipping` | Frete ou remessa |
| `shield` | Seguro |
| `receipt` | Outras despesas |
| `map` | Contexto territorial |
| `timeline` | Eventos do documento |
| `picture_as_pdf` | DANFE |
| `code` | XML fiscal |

## Componentes implementados

- `.nf-document-row`: linha operacional clicavel.
- `.nf-summary-card`: resumo frontal do resultado filtrado.
- `.nf-signal`: sinal financeiro da NF.
- `.nf-open-button`: acao primaria de detalhe.
- `.biomie-drawer--document`: drawer amplo para NF e pedido.
- `.document-hero`: resumo executivo no topo do drawer.
- `.document-composition`: composicao financeira.
- `.document-party-card`: cliente e vendedor relacionados.
- `.document-timeline`: linha do tempo do documento.

O CSS compartilhado fica em `assets/css/commercial-documents.css`, carregado pelo
registro base para manter os drawers consistentes em Pedidos, Dashboard, Mix e Mapa.

## Acessibilidade

- Linhas podem ser abertas com `Enter` ou `Espaco`.
- Botoes e links possuem rotulos e foco visivel.
- Cores nunca sao o unico indicador: cada estado possui texto e icone.

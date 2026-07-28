# Padrao Visual Comercial

Este documento define o padrao compartilhado dos cards e tabelas comerciais do BIomie.
O objetivo e manter a mesma leitura visual sem misturar a semantica de cada dominio.

## Estrutura compartilhada

- `commercial-panel`: card principal, cabecalho, borda, raio e sombra.
- `commercial-table`: tabela com cabecalho compacto, hover e foco.
- `commercial-entity`: identidade principal da linha com icone, titulo e metadados.
- `commercial-signal`: sinal curto de contexto ou estado.
- `commercial-metric`: valor com rotulo explicito.
- `commercial-action`: acao de detalhe.
- `commercial-row--interactive`: somente quando toda a linha abre um detalhe real.

Filtros, ordenacao, paginacao e drilldowns existentes devem continuar usando seus
proprios contratos. O padrao visual nao autoriza criar relacoes entre dominios.

## Categorias

| Categoria | Cor | Icone principal | Informacoes permitidas |
| --- | --- | --- | --- |
| Vendedor | Teal `#0f766e` | `person` | faturamento, meta, atingimento, risco, carteira, cancelamentos, conversao, ticket e conflitos de carteira |
| Produto | Violeta `#6d28d9` | `inventory_2` | SKU, quantidade, clientes, NFs, receita e participacao no mix |
| Nota fiscal | Verde `#15803d` | `receipt_long` | numero, emissao, cliente, vendedor, itens, base comercial e acao fiscal |
| Cliente | Azul `#1d4ed8` | `domain` | identidade, documento, valor faturado, quantidade de NFs, ticket medio e ultima compra |
| Territorio | Laranja `#c2410c` | `location_on` | bairro/territorio, receita e participacao geografica |

## Icones auxiliares

| Icone | Definicao |
| --- | --- |
| `receipt_long` | nota fiscal ou quantidade de documentos |
| `request_quote` | carteira comercial |
| `cancel` | cancelamento |
| `sell` | SKU/codigo de produto |
| `donut_large` | participacao no mix |
| `event` | data da ultima compra ou emissao |
| `verified` | documento fiscal valido no contexto exibido |
| `verified_user` | carteira sem conflito |
| `warning` | conflito ou atencao operacional |
| `map` / `pie_chart` | concentracao e participacao geografica |
| `open_in_new` | abertura de drawer ou detalhe 360 |

## Regras de semantica

1. A estrutura visual e compartilhada; os dados nao.
2. Vendedor nao recebe indicadores de mix de produto.
3. Produto nao recebe meta, risco ou carteira de vendedor.
4. Cliente nao recebe sinais fiscais que nao sejam derivados das suas compras.
5. Territorio nao recebe identidade de cliente, produto ou vendedor no ranking compacto.
6. Nota fiscal pode relacionar cliente e vendedor porque essas identidades pertencem ao documento.
7. Uma linha so recebe foco, `role="button"` e cursor interativo quando existe acao funcional.
8. Valores sempre exibem rotulo semantico; cor so reforca o significado.

## Escopo

Este padrao foi aplicado ao dominio comercial. O financeiro fica fora desta fase e
deve receber uma matriz semantica propria antes de reutilizar estes componentes.

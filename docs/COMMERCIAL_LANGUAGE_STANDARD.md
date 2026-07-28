# Padrao de Linguagem Comercial

O BIomie deve explicar o negocio antes de expor termos internos do sistema.

## Regra principal

- Titulo principal: pergunta ou resultado que a pessoa precisa entender.
- Subtitulo: explica origem, limite ou proxima acao em uma frase.
- Termos tecnicos ficam apenas em diagnosticos recolhiveis e telas administrativas.

## Substituicoes adotadas

| Termo interno | Linguagem comercial |
| --- | --- |
| Gap | Quanto falta para a meta |
| Pipeline | Etapas das vendas |
| Aging | Tempo de atraso |
| Governanca | Acompanhamento da equipe |
| Rastreabilidade | Origem dos valores |
| Cobertura NF x titulo | Notas com financeiro identificado |
| Score | Prioridade |
| Mix | Participacao nas vendas |
| SLA | Prazo de atendimento |
| Backlog | Pedidos aguardando faturamento |

## Cores

| Cor | Significado |
| --- | --- |
| Verde | Dinheiro confirmado e vendas faturadas |
| Azul | Meta, previsao e oportunidade |
| Ambar | Valores pendentes e carteira |
| Vermelho | Atraso, risco ou dado para conferir |
| Violeta | Produtos |
| Teal | Clientes, vendedores e equipe |
| Laranja | Territorios e localizacao |

Cor reforca o titulo, mas nunca substitui a explicacao textual.

## Ajuda comercial contextual

Todos os cards de negocio devem possuir uma explicacao curta e acessivel.

- O icone de informacao fica ao lado do titulo e nao substitui o clique principal do card.
- No computador, a ajuda abre ao passar o mouse ou focar pelo teclado.
- No celular, um toque fixa a ajuda; toque externo ou `Esc` fecha.
- O texto responde, quando aplicavel: `O que mostra`, `Como usar na decisao`, `Como explorar` e `O que considerar`.
- A acao `Ver leitura comercial` abre uma explicacao completa sem sobrecarregar o card.
- A leitura completa apresenta o valor atual, de onde vem o resultado, o que foi considerado, comparacoes, recorte atual, periodo, perguntas para agir e proximo passo sugerido.
- Periodo, origem, cobertura, conferencia e ultima atualizacao sao exibidos somente quando a pagina disponibiliza essas informacoes.
- A leitura comercial pode ser copiada para apoiar reunioes e conferencias.
- Indicadores usam explicacoes especificas sempre que o significado exigir.
- Graficos e tabelas usam explicacoes por assunto e tipo de visualizacao.
- Cards dinamicos recebem ajuda automaticamente.
- A cor e o icone da ajuda seguem a categoria comercial do card.

Implementacao:

- `assets/js/commercial-card-help.js`: catalogo, inferencia contextual, interacoes e acessibilidade.
- `assets/css/commercial-card-help.css`: gatilho, popover, cores, mobile e movimento reduzido.
- `tools/dev/commercial_card_help_audit.php`: cobertura e guardas da implementacao.

Para validar:

```bash
php tools/dev/commercial_card_help_audit.php
```

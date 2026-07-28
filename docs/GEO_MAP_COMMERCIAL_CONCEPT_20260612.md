# Conceito do Mapa Comercial

## Objetivo

O mapa deve responder rapidamente a quatro perguntas comerciais:

1. Onde estamos vendendo mais?
2. Onde existe espaço para crescer?
3. Quais territórios precisam de atenção cadastral?
4. Qual detalhe territorial deve ser aberto para agir?

## Navegação territorial

- **Entrada executiva:** Ceará por 14 Regiões de Planejamento do IPECE.
- **Aprofundamento:** Ceará por municípios, metrópoles e bairros.
- **Visão multiescala:** modo avançado para exploração conjunta de bairros e municípios, com aviso explícito de que escalas diferentes não devem ser comparadas diretamente.

## Linguagem visual

| Leitura | Cor | Uso |
|---|---|---|
| Resultado comercial | Azul sequencial | Faturamento, notas, clientes e ticket médio |
| Oportunidade | Âmbar até roxo | Muitos clientes ativos com menor faturamento relativo |
| Cobertura confirmada | Verde | Resultado localizado no mapa |
| Cadastro a revisar | Vermelho/âmbar | Registros sem localização confiável |
| Sem resultado | Cinza azulado | Território sem movimento no período |

A pintura territorial usa uma única escala sequencial para a métrica ativa. O tipo de território aparece em rótulos, badges e contornos, sem competir com o significado da cor.

## Interações

- Troca de métrica repinta mapa, ranking, legenda e tooltips.
- Ranking e mapa permanecem sincronizados.
- Clique no território abre drawer contextual.
- Seleção de área inclui territórios tocados e informa que o cálculo é aproximado.
- Comparação aceita até quatro territórios.
- Modos Vendas, Calor, Oportunidades e Cobertura preservam seu estado após atualização dos filtros.

## Drawer

- Bairros e municípios oferecem resumo, compradores, notas, CNAEs, evolução e ações.
- Regiões estratégicas oferecem resumo e ações; detalhes operacionais ficam disponíveis após aprofundar para municípios.
- Informações não suportadas não são simuladas nem apresentadas como completas.

## Regras de confiança

- O mart geográfico atual aceita somente venda comercial (`orc_pd`).
- Remessas e licitações ficam indisponíveis no mapa até possuírem separação geográfica confiável.
- Ranking de uma camada contém somente territórios presentes na geometria desenhada.
- A cobertura territorial permanece visível e informa vendas ainda sem localização.

# QA de Modo Escuro

## Objetivo

Homologar o modo escuro do BIomie como pronto para operacao real, com foco em:

- legibilidade
- consistencia entre telas
- ausencia de regressao visual
- coerencia entre navegador, PWA e tela offline
- fechamento dos achados por severidade

## Preflight automatizado

Antes de qualquer rodada manual, rode:

```bash
php tools/dev/dark_mode_qa_audit.php
```

O auditor verifica:

- presenca de `theme-init.js` nas paginas criticas
- `meta color-scheme`
- `theme-color` para light e dark
- `theme-tokens.css` versionado
- `shell.js` versionado nas telas shell
- `body[data-page]` nas views principais
- consistencia minima de `site.webmanifest` e `offline.html`
- ausencia do bloco legado de tokens em [shell-index.css](/c:/Users/Usuario/Downloads/biomie/assets/css/shell-index.css:1)

Esse comando nao substitui QA humano. Ele so elimina regressao estrutural antes da homologacao visual.

## Rodadas recomendadas

1. Rodada 1: [views/index.html](/c:/Users/Usuario/Downloads/biomie/views/index.html:1), [views/plano_voo.html](/c:/Users/Usuario/Downloads/biomie/views/plano_voo.html:1) e [views/financeiro.html](/c:/Users/Usuario/Downloads/biomie/views/financeiro.html:1)
2. Rodada 2: [views/vendedores.html](/c:/Users/Usuario/Downloads/biomie/views/vendedores.html:1), [views/pedidos.html](/c:/Users/Usuario/Downloads/biomie/views/pedidos.html:1) e [views/mercadorias.html](/c:/Users/Usuario/Downloads/biomie/views/mercadorias.html:1)
3. Rodada 3: [views/integracoes.html](/c:/Users/Usuario/Downloads/biomie/views/integracoes.html:1), [reconciliacao.php](/c:/Users/Usuario/Downloads/biomie/reconciliacao.php:1), [offline.html](/c:/Users/Usuario/Downloads/biomie/offline.html:1) e PWA instalada
4. Rodada 4: mobile, teclado e tema `system` com mudanca real do sistema operacional

## Fase 1 - Troca de tema e persistencia

Validar em cada tela critica:

- abrir em `light`
- trocar para `dark`
- trocar para `system`
- dar refresh
- navegar para outra tela
- fechar e reabrir o navegador
- mudar o tema do sistema operacional com a aplicacao aberta quando estiver em `system`

Aceite:

- sem flash claro inesperado
- sem perda da escolha salva
- `system` acompanha o sistema operacional
- header, sidebar e conteudo trocam juntos

## Fase 2 - Telas criticas

Ordem de revisao:

1. [views/index.html](/c:/Users/Usuario/Downloads/biomie/views/index.html:1)
2. [views/plano_voo.html](/c:/Users/Usuario/Downloads/biomie/views/plano_voo.html:1)
3. [views/financeiro.html](/c:/Users/Usuario/Downloads/biomie/views/financeiro.html:1)
4. [views/vendedores.html](/c:/Users/Usuario/Downloads/biomie/views/vendedores.html:1)
5. [views/pedidos.html](/c:/Users/Usuario/Downloads/biomie/views/pedidos.html:1)
6. [views/mercadorias.html](/c:/Users/Usuario/Downloads/biomie/views/mercadorias.html:1)
7. [views/integracoes.html](/c:/Users/Usuario/Downloads/biomie/views/integracoes.html:1)
8. [reconciliacao.php](/c:/Users/Usuario/Downloads/biomie/reconciliacao.php:1)

Em cada tela, revisar:

- fundo geral
- cards e superficies secundarias
- tabelas e scroll horizontal
- filtros, inputs, selects e datas
- botoes
- chips, badges e status
- banners operacionais
- overlays
- drawers
- modais
- tooltips

Aceite:

- nenhum bloco claro perdido
- nenhum texto lavado
- nenhuma borda invisivel
- nenhuma area com contraste ruim

## Fase 3 - Estados interativos

Validar:

- `hover`
- `focus` por mouse
- `focus` por teclado
- `active`
- `disabled`
- `loading`
- `erro`
- `vazio` e `sem dados`
- `sucesso`
- selecao em tabela
- selecao em filtros
- dropdown aberto
- modal aberto
- drawer aberto

Aceite:

- foco visivel por teclado
- hover perceptivel
- disabled claramente distinto
- overlays equilibrados

## Fase 4 - Graficos e areas analiticas

Revisar com mais cuidado:

- graficos do index
- forecast
- ranking de risco
- barras e comparativos
- tooltip
- legenda
- labels
- grids
- drawer do vendedor
- blocos operacionais do plano de voo
- filtros do financeiro ligados aos graficos

Arquivos de maior atencao:

- [assets/js/app.js](/c:/Users/Usuario/Downloads/biomie/assets/js/app.js:1)
- [assets/js/flight-plan-v2.js](/c:/Users/Usuario/Downloads/biomie/assets/js/flight-plan-v2.js:1)

Aceite:

- labels legiveis
- grid discreta, mas visivel
- tooltip coerente com o tema
- cores de risco distinguiveis
- nada some ou estoura no dark

## Fase 5 - Mobile

Validar em viewport menor:

- header
- seletor de tema
- sidebar recolhida
- cards empilhados
- tabelas com scroll
- drawers
- modais
- filtros
- grafico com toque e tooltip
- scrollbars

Aceite:

- sem quebra de layout
- sem botao inacessivel
- sem texto espremido
- sem seletor de tema empurrando o topo

## Fase 6 - PWA e navegador real

Validar:

- instalacao da PWA
- abertura inicial
- splash e background
- `theme-color` no navegador
- `theme-color` na PWA instalada
- [offline.html](/c:/Users/Usuario/Downloads/biomie/offline.html:1)
- troca `light` / `dark` / `system`
- reabertura da PWA apos fechar
- alteracao do tema do sistema com a PWA instalada

Aceite:

- PWA abre no tema correto
- barra do navegador acompanha o tema
- `offline.html` nao volta para light
- `system` permanece coerente fora do browser normal

## Severidade dos achados

Classifique cada ponto assim:

- `P0` - bloqueador: texto ilegivel, foco invisivel, grafico ilegivel, modal ou drawer quebrado, tema nao persiste, `system` nao acompanha, fundo claro escapando em tela critica
- `P1` - corrigir antes de encerrar: hover fraco, borda fraca, placeholder apagado, tooltip destoando, zebra de tabela sutil demais, chip ou status com contraste apenas razoavel
- `P2` - refinamento: sombra, microajuste de opacidade, polimento visual fino, ritmo de animacao

## Como registrar os achados

Para cada rodada:

1. Gere um relatorio base:

```bash
php tools/dev/dark_mode_qa_report.php
```

2. Preencha o arquivo criado em `docs/qa-reports/`
3. Registre cada achado com:

- severidade
- tela
- modo (`light`, `dark`, `system`)
- viewport (`desktop`, `mobile`, `PWA`)
- componente
- descricao objetiva
- evidencia
- status (`aberto`, `corrigido`, `aceito_como_refinamento`)

## Modelo de encerramento

A frente pode ser considerada homologada quando:

- `light`, `dark` e `system` funcionarem nas telas criticas
- desktop e mobile estiverem validados
- PWA instalada estiver validada
- foco por teclado estiver claro
- graficos estiverem legiveis
- drawers e modais estiverem consistentes
- `theme-color` e `offline.html` estiverem corretos
- nao houver `P0` aberto
- os `P1` restantes forem minimos ou ja corrigidos

## Fechamento esperado

Depois da homologacao, o trabalho restante deve virar apenas lista curta de correcao pontual:

- opacidade de borda
- texto secundario um pouco fraco
- tooltip fora do padrao
- badge com contraste baixo
- placeholder apagado
- foco discreto demais

Essa etapa nao abre nova frente tecnica. Ela so fecha o tema como homologado para uso real.

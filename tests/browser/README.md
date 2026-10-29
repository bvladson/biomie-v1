# Validação local da barra horizontal flutuante

Esta suíte usa o runner nativo do Node e Playwright somente como ferramenta de teste. Não adiciona pacotes ao frontend PHP, não executa PHP, não usa dados de clientes e não acessa APIs, banco ou produção. O servidor escuta exclusivamente `127.0.0.1` em uma porta livre e serve apenas `assets/` e `tests/browser/fixtures/`. A execução local registrada está em [RESULTS.md](RESULTS.md).

## Execução

Requisitos: Node 24 e Playwright **1.64.1** com Chromium correspondente instalado. O pacote pode ficar em um diretório temporário externo ao repositório.

```powershell
$browserTools = Join-Path $env:TEMP 'biomie-floating-scroll-tools'
npm install --prefix $browserTools --no-save --no-package-lock playwright@1.64.1
node (Join-Path $browserTools 'node_modules/playwright/cli.js') install chromium
$env:PLAYWRIGHT_MODULE = Join-Path $browserTools 'node_modules/playwright'
$env:FLOATING_SCROLL_ARTIFACTS = Join-Path (Get-Location) 'storage/diagnostics/floating-scroll'
node --test tests/browser/*.test.cjs
```

No Linux/CI, instalar o navegador com `install --with-deps chromium`. O diretório de evidências fica ignorado pelo Git. Se `PLAYWRIGHT_MODULE` não for informado, a resolução padrão do Node procura `playwright` já instalado; nenhuma instalação ocorre durante os testes.

`BROWSER=firefox` ou `BROWSER=webkit` permite executar `floating-scroll.test.cjs` no navegador correspondente quando ele estiver instalado. O teste de toque via CDP é específico do Chromium e fica marcado como ignorado nos demais. Os arquivos de zoom nativo e ponteiro são exclusivos do Chromium. Disponibilidade de um navegador não implica execução: registrar separadamente os resultados de cada engine.

## Cenários e critérios

`fixtures/floating-scroll.html` carrega os arquivos CSS reais do shell, design system, mobile, drawers, financeiro e Plano de Voo, além do módulo novo. O CSS da fixture acrescenta apenas geometria e dados sintéticos para tornar os cenários reproduzíveis. Isto verifica o componente com os padrões existentes; não substitui um teste autenticado de cada página real.

| Grupo | Validação automatizada |
| --- | --- |
| Resoluções | 1920×1080, 1366×768 e janela 900×600; barra dentro do retângulo visível, antes/depois de rolar a página |
| Zoom CSS | 100%, 125%, 150% em cada resolução; limites e sincronização nos dois sentidos |
| Dimensões | Tabela curta, tabela longa, sem overflow, filtro que reduz linhas/largura, redimensionamento da janela |
| Containers | Clipping `overflow:hidden`, painel com rolagem vertical, painéis aninhados, duas tabelas no mesmo owner |
| Padrões reais | `.mag-table-scroll`, `.fin-table-wrap`, `.dashboard-panel__body--table`, `.nf-table-scroll`, `.fp-table-wrapper`, tabela mobile com overflow próprio, drawers e tabelas sem classe |
| Conteúdo dinâmico | Painel inicialmente oculto, abertura posterior, três substituições AJAX sem reinicialização, limpeza de overlays antigos |
| Escopo | Opt-in explícito de relatório sem tabela; opt-out; carrosséis e abas sem barras adicionais |
| Acessibilidade | `role`, orientação, nome, controle e valor ARIA; setas, Home/End, PageDown, saída por Tab, foco ao desaparecer o overflow |
| Entrada | Scroll nativo e barra sincronizados; arraste real do thumb com `page.mouse`, wheel horizontal e gesto de toque sintetizado por CDP |
| Drawers | Portal dentro do drawer com `transform`, limite do corpo, rolagem interna, abertura/fechamento |
| Obstáculos | Navegação mobile inferior, rodapé fixo opt-in, menu aberto, modal nativo no top layer e foco no portal do modal |
| Ciclo de vida | `init`, `refresh` e `destroy` repetidos; sem overlays/wrappers duplicados; opt-out no wrapper preservado entre scans; nó/ID/listeners originais mantidos e parent restaurado no destroy |
| Observadores | Redimensionamento do container sem evento de janela; contagem de callbacks RAF antes do módulo e ausência de loop contínuo após estabilização |

O teste `floating-scroll-native-zoom.test.cjs` usa uma extensão temporária MV3 e `chrome.tabs.setZoom` para **zoom nativo da aba**. Ele verifica o nível efetivo e a geometria resultante. O zoom CSS da primeira suíte é um estresse adicional; não é equivalente a Ctrl+Mais, mudança de DPR, pinch zoom ou escala do sistema operacional.

O teste `floating-scroll-pointer.test.cjs` arrasta o thumb nativo até as duas extremidades e exige a última coluna visível, incluindo drawer. As execuções Chromium retiram o argumento padrão `--hide-scrollbars` do Playwright para exercitar e capturar a barra real. O teste de ponteiro também exige gutter visível; não substitui o arraste por atribuições de `scrollLeft`.

## Evidências e limites

- O runner informa nome e versão do navegador, testes, falhas e testes ignorados. Com `FLOATING_SCROLL_ARTIFACTS`, cada caso da suíte principal salva uma captura após as verificações, inclusive quando o caso falha; uma captura isolada não prova que o caso passou.
- O caso de barra nativa verifica sua pré-condição pelo CSS computado e pela geometria do owner. Com barra nativa disponível, exige a barra extra oculta. Com `scrollbar-width:none`, exige a barra extra visível, sincronizada e acessível. No Firefox headless Windows desta execução, até uma página sem estilos computou `none` e gutter zero; o runner registra user agent, estilo e medidas para identificar esse caminho. Chromium e WebKit também verificam o caminho positivo de ocultar a duplicação quando a nativa está acessível.
- A fixture contém valores fictícios e não modifica dados ou regras de negócio. Não há afirmação de validação de páginas autenticadas ou dados reais.
- Arraste do thumb, wheel horizontal e toque CDP verificam entrada sintetizada pelo navegador. Não comprovam ergonomia de um mouse/trackpad físico em todos os sistemas, gestos de um celular real, leitores de tela ou teclado virtual móvel.
- A altura reduzida da janela verifica recomposição do viewport. Não reproduz integralmente a alteração de `visualViewport` por teclado virtual ou barras do navegador móvel.
- Escala de tela do Windows/macOS, barras de rolagem configuradas pelo sistema, zoom por atalho do navegador e safe area física precisam de verificação manual no dispositivo. Não inferir esses resultados a partir de CSS zoom ou `deviceScaleFactor`.
- As suítes PHP preexistentes não são substituídas por estes testes. Nesta base, `composer test` referencia `tests/TestSupport.php` e `tests/TestRunner.php`, ausentes no Git; essa falha de infraestrutura deve ser reportada, sem alterar cálculos ou regras para mascará-la.

Checklist manual antes de publicar: usar uma sessão homologada em estoque/mercadorias, NF, financeiro e Produto/Cliente/Vendedor 360; conferir mouse/trackpad físico, Tab, browser zoom por atalho, mobile real e menus/drawers existentes. A rota independente de Posições de estoque V2 não existe nesta base PHP. Publicação e merge exigem revisão separada.

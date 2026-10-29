# Resultado local — 29/10/2026

Execução no Windows, Node **24.21.0**, Playwright **1.64.1**, com dependências de teste externas ao repositório. A suíte carrega CSS/JS reais sobre fixtures sintéticas locais; não acessa produção.

| Navegador | Comando | Resultado |
| --- | --- | --- |
| Chromium 156.0.8078.4 | `node --test --test-reporter=tap tests/browser/*.test.cjs` | 49 aprovados, 0 falhas, 0 ignorados |
| Firefox 157.0 | `BROWSER=firefox node --test --test-reporter=tap tests/browser/floating-scroll.test.cjs` | 34 aprovados, 0 falhas, 1 ignorado |
| WebKit 27.2 | `BROWSER=webkit node --test --test-reporter=tap tests/browser/floating-scroll.test.cjs` | 34 aprovados, 0 falhas, 1 ignorado |

A contagem Chromium inclui 35 casos da suíte principal, nove subcenários de zoom nativo, o teste que os contém e quatro casos de arraste do thumb. Nos demais navegadores, somente o gesto de toque via CDP é ignorado, pois utiliza uma API exclusiva do Chromium.

O zoom nativo foi confirmado por `chrome.tabs.getZoom`, variação do DPR e redução de `innerWidth`, mantendo o zoom CSS em `1` e `visualViewport.scale` em `1`. Exemplo em janela 1920×1080: `innerWidth` 1904 → 1523 → 1269 e DPR 1 → 1,25 → 1,5. Em janela 900×600 a 150%, o viewport passou a 589×337 e o caso também passou, incluindo drawer.

Os 35 casos principais cobrem a matriz de viewport/zoom CSS, sincronização proporcional e extremidades nos dois sentidos, tabelas curtas/longas/sem overflow, owners internos, clipping, tabelas aninhadas, tabelas mobile, NF/financeiro/Plano de Voo/dashboard, AJAX, drawer, modal, menus, navegação inferior, teclado, wheel e ciclo de vida. Também passaram a preservação do nó/ID/listeners da tabela ao criar/remover wrapper, opt-out persistente, resize do container sem evento de janela e ausência de loop RAF contínuo após estabilização.

Os quatro casos de ponteiro arrastaram o thumb nativo por `page.mouse` até as extremidades, exigiram a última coluna visível e voltaram ao início: tabela longa, drawer fixo, drawer contido por ancestral transformado e modal no top layer dentro de um ancestral com recorte. O Chromium foi executado sem o argumento padrão `--hide-scrollbars`, com gutter visível e capturas do thumb. A posição inferior do drawer também é verificada, impedindo regressão para o limite de um card ao fundo.

O Firefox headless deste ambiente computou `scrollbar-width:none` e gutter zero inclusive em HTML sem CSS. Nesses casos, o teste exigiu a barra flutuante visível, sincronizada e acessível por teclado. Chromium e WebKit verificaram a ocultação da barra adicional quando a nativa estava acessível. O TAP contém user agent e medidas da pré-condição; nenhum desses cenários foi simplesmente dispensado.

Evidências locais, ignoradas pelo Git:

- `storage/diagnostics/floating-scroll/chromium-results.tap`
- `storage/diagnostics/floating-scroll/firefox-results.tap`
- `storage/diagnostics/floating-scroll/webkit-results.tap`
- Capturas PNG no mesmo diretório, incluindo as de zoom nativo.

Verificações adicionais executadas nesta alteração:

| Verificação | Resultado |
| --- | --- |
| `php tests/run_floating_scroll_assets.php` | 45 verificações aprovadas |
| `php tools/dev/lint_all.php` | 577 arquivos PHP, sem erros de sintaxe |
| `php tools/dev/rbac_audit.php` | 52/52 endpoints protegidos, sem achados |
| `php tools/dev/semantic_audit.php --fail-on-warning` | 29 KPIs, verificações aprovadas |
| `node --check assets/js/biomie-floating-scroll.js` e `git diff --check` | Aprovados |
| `php tests/run_phase2_coverage.php` | Bloqueado antes de executar: `tests/TestSupport.php` ausente na base Git; `tests/TestRunner.php` também está ausente |

O lint e as verificações PHP locais usaram `-d date.timezone=America/Sao_Paulo`. Composer e PHPStan não estavam instalados neste clone; a análise PHPStan local não foi executada. O workflow novo verifica o componente independentemente da infraestrutura PHP preexistente, sem substituir o CI geral.

As capturas podem incluir tentativas anteriores; o resultado final é o dos três arquivos TAP acima. Não houve teste autenticado de todas as páginas reais, escala de tela do sistema operacional, mouse/trackpad físico, teclado virtual, safe area física ou leitor de tela. Os limites e a reprodução estão no [README](README.md). Esta evidência não implica publicação, merge ou aprovação de produção.

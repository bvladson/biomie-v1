# Rolagem horizontal flutuante global

## Problema e integração

Uma tabela extensa pode continuar muito abaixo da tela, deixando sua barra horizontal
nativa inacessível até o fim das linhas. O módulo acrescenta um controle sincronizado
na borda inferior da **parte visível da tabela**, dentro dos limites de seu container.

`PageAssetRegistry::BASE_CSS` e `BASE_JS` carregam os dois assets uma vez por página,
inclusive para page-keys futuras. O `PageRenderer` existente mantém deduplicação,
versionamento por arquivo e carregamento deferido. Não é necessário registrar cada
tabela, alterar suas consultas ou mudar os renderizadores de cada módulo.

## Funcionamento

- Descobre tabelas e seus containers horizontais reais, inclusive tabelas sem classe
  e tabelas que são seu próprio container. Containers compartilhados são deduplicados.
- Se uma tabela larga estiver cortada e não tiver um container horizontal adequado,
  cria um wrapper local reversível em volta do **mesmo nó da tabela**. Cabeçalhos,
  filtros e o restante do drawer não passam a rolar horizontalmente. Wrappers já
  existentes são preservados.
- Usa `scrollWidth`, `clientWidth` e `scrollLeft`; a sincronização é proporcional,
  permitindo alcançar as duas extremidades mesmo quando o controle está parcialmente
  limitado por um ancestral. O scroll original não é reposicionado ao inicializar.
- Cruza o viewport visual com os ancestrais que cortam conteúdo ou rolam. Drawers e
  diálogos recebem um portal próprio, mantendo o contexto de empilhamento e foco,
  inclusive quando usam `transform`. Um drawer fixo respeita seu containing block
  real; o recorte de um card ao fundo não encurta sua área visível. Diálogos no
  top layer também escapam do recorte de ancestrais externos.
- A barra adicional desaparece quando não há overflow ou quando o controle nativo
  está visível e acessível. Uma barra nativa escondida por CSS não é considerada acessível.
- Navegação inferior, menus e outros elementos sobrepostos são considerados antes
  de posicionar o controle. Se não houver uma faixa segura suficiente, ele é ocultado.
- `ResizeObserver`, `MutationObserver`, eventos de scroll e viewport atualizam os
  resultados por `requestAnimationFrame`, com listeners globais únicos e sem polling.
  Mutações dos próprios portais não disparam ciclos de descoberta.

O controle usa rolagem nativa, nome e valores ARIA, foco por Tab, setas, Home/End e
PageUp/PageDown. A altura aumenta em dispositivos de ponteiro impreciso. Impressão
esconde os controles adicionais; a rolagem original continua disponível.

## Configuração opcional

O comportamento padrão cobre tabelas de dados. Carrosséis, navegação, menus, abas e
tabelas de apresentação não recebem controles automaticamente.

```html
<!-- Desabilitar uma tabela ou uma região inteira -->
<div data-biomie-floating-scroll="off">...</div>

<!-- Habilitar explicitamente um relatório horizontal que não usa <table> -->
<div data-biomie-floating-scroll="on"
     data-biomie-floating-scroll-label="Colunas do relatório comercial">...</div>

<!-- Identificar um obstáculo adicional, como uma barra inferior customizada -->
<footer data-biomie-floating-scroll-obstacle>...</footer>
```

O opt-in exige um container com overflow horizontal real. Não altera APIs ou cria
uma segunda fonte de dados.

```js
window.BiomieFloatingScroll.init();    // idempotente; inicializa também automaticamente
window.BiomieFloatingScroll.refresh(); // opcional; AJAX/resize já são observados
window.BiomieFloatingScroll.destroy(); // remove controles e desfaz wrappers/IDs próprios
```

As tabelas e seus listeners são preservados. Novos componentes devem preferir os
wrappers de tabela já existentes; código que dependa especificamente de
`table.parentElement` deve considerar o wrapper reversível de tabelas antes cortadas.

## Abrangência auditada

Foram identificadas 49 tabelas estáticas em 11 views principais, além das tabelas
administrativas e geradas dinamicamente. Os padrões abrangem dashboard, NF/pedidos,
produtos, vendedores, financeiro, Plano de Voo, conciliação, observabilidade e
drawers de Produto/Cliente/Vendedor 360.

Este repositório PHP contém análises de estoque em `mercadorias` e Produto 360.
A página independente de **Posições de estoque da aplicação V2** não está neste
repositório e não é apresentada como página real validada por esta alteração.

## Verificação e revisão

- `php tests/run_floating_scroll_assets.php` verifica registro global, defer,
  versionamento e deduplicação no renderer, sem sessão ou banco.
- Os testes de navegador usam CSS reais e fixtures locais sintéticas. Exercitam
  geometria, sincronização, drawers, obstáculos, responsividade, AJAX e ciclo de vida.
  Suítes adicionais usam zoom nativo de aba e arraste do thumb com mouse no Chromium,
  separados de zoom CSS e atribuições programáticas de scroll.
- [Execução, cenários e limitações dos testes](../tests/browser/README.md).
- O workflow **Floating horizontal scroll** executa a suíte de assets e os testes
  de navegador em ferramentas externas à aplicação; capturas são artefatos do CI.

Testes com fixtures não equivalem a navegar em todas as páginas autenticadas com
dados reais. Mouse, touchpad, leitor de tela e dispositivos físicos também exigem
conferência manual em homologação. As suítes PHP antigas dependem de arquivos de
suporte ausentes na base; esse problema é registrado separadamente.

Nenhuma API, regra financeira, filtro de negócio, permissão, integração ou estrutura
de banco foi alterada. O PR é para revisão; não executa publicação nem merge.

Para reverter, retirar os dois registros globais e seus assets, ou reverter o
commit da funcionalidade. Em uma página já aberta, `destroy()` restaura a estrutura
anterior dos wrappers criados pelo módulo.

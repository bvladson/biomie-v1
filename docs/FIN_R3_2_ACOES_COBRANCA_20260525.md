# FIN.R3.2 — Ações de Cobrança na Carteira por Vendedor

Este documento detalha o escopo, estrutura de dados, fluxos de uso e validações operacionais da funcionalidade de registro de ações de cobrança sobre títulos em atraso.

## Escopo e Racional de Design

Para transformar a carteira de cobrança em uma tela de acompanhamento verdadeiramente operacional, foi implementado um mecanismo de registro de ações sem a aplicação de novas migrations ou alterações de schema.

Conforme a recomendação de estabilidade da fase, os eventos de cobrança são persistidos no log de auditoria do sistema (`biomie_admin_logs`) sob o evento especializado `finance.collection_action`, mantendo os dados financeiros de faturamento e parcelas completamente intocados e seguros.

## Estrutura de Dados e Campos

Cada ação de cobrança é registrada como uma entrada de log administrativo contendo a seguinte estrutura no campo `details_json`:

- `titulo_key` (VARCHAR): Chave única identificadora do título.
- `cliente_codigo_omie` (VARCHAR): Código do cliente associado ao título.
- `vendedor_codigo_omie` (VARCHAR): Código do vendedor associado ao título.
- `action_type` (ENUM/String): Tipo da ação operacional realizada:
  - `cobrado_hoje`: Contato direto de cobrança realizado no dia.
  - `whatsapp_enviado`: Notificação via aplicativo de mensagens.
  - `ligacao_realizada`: Contato telefônico direto com o cliente.
  - `email_enviado`: Envio formal de e-mail de cobrança.
  - `aguardando_retorno`: Retorno pendente por parte do cliente.
  - `promessa_pagamento`: Cliente indicou data de quitação.
  - `validar_baixa_caixa`: Identificado pagamento que exige baixa manual no Omie.
  - `revisar_vinculo_financeiro`: Ação padrão para títulos agrupados como *Sem vendedor definido*.
- `note` (TEXT): Notas ou observações inseridas pelo operador (limite de 1.000 caracteres).
- `next_action_date` (DATE, YYYY-MM-DD ou NULL): Data planejada para novo contato.

### Metadados de Auditoria Gravados Automaticamente:
- `actor_user_id`: ID do usuário logado que realizou a ação (capturado via sessão ativa).
- `ip_address` / `user_agent` / `route`: Rastreamento de rede e dispositivo.
- `created_at`: Timestamp automático da operação.

---

## Fluxos Operacionais na UI

### 1. Registro de Ação por Drawer
Na linha de cada título acionável, o operador dispõe do botão **"Registrar cobrança"**.
Ao clicar:
- Um Drawer lateral (`#collectionActionDrawer`) se abre contendo o formulário de ação.
- Exibe o resumo do título (Cliente, Vendedor, Vencimento, Valor em aberto).
- Permite selecionar o tipo de ação, definir observações e agendar a data da próxima ação.
- Carrega de forma dinâmica o **Histórico de Ações** específicas daquele título em ordem cronológica reversa.

### 2. Ação Rápida: Marcação "Cobrado hoje"
Para agilizar a operação, títulos normais dispõem de um atalho rápido **"Marcar cobrado hoje"**. Ao clicar, o sistema cria automaticamente o evento `cobrado_hoje` com nota padrão e recarrega a tabela imediatamente.

### 3. Títulos sem Vendedor
Para títulos agrupados sob a carteira *Sem vendedor definido*:
- O vendedor não pode ser alterado ou atribuído de forma artificial na UI.
- O único botão operacional disponível é o **"Revisar vínculo"**, que abre o Drawer com a ação pré-selecionada para `revisar_vinculo_financeiro`.

---

## Painel de Controle e KPIs de Ação

A carteira por vendedor incorpora agora 4 novos KPIs no topo da tela para acompanhamento da performance operacional de cobrança:

1. **Cobrados hoje**: Quantidade de títulos únicos que receberam alguma ação de cobrança no dia atual.
2. **Promessas de pagamento**: Quantidade de títulos com status de contato ativo marcado como promessa.
3. **Revisões de vínculo**: Quantidade de pendências ativas aguardando conciliação de vendedor na origem.
4. **Sem ação de cobrança**: Títulos em aberto que ainda não receberam nenhum registro de ação operacional.

---

## Permissões e Segurança (RBAC)

- O endpoint `api/finance_collection_action.php` exige explicitamente a permissão `finance.view` (verificada no backend via `Auth::requireCapability('finance.view', true)`).
- Todo parâmetro recebido via POST é sanitizado e processado utilizando *Prepared Statements* de forma a evitar qualquer vulnerabilidade de injeção.
- Não existem consultas destrutivas ou de atualização direta nos dados financeiros originais.

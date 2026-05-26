# FIN.R3.3 — Governança das Ações de Cobrança

Este documento descreve a especificação, as regras de negócio, a arquitetura técnica e o fluxo operacional do painel de governança de cobrança implementado no BIomie.

---

## 1. Objetivo

Evoluir a carteira de cobrança do BIomie para uma camada de governança operacional de cobrança, permitindo acompanhar a produtividade dos operadores, controle de SLAs de retorno, promessas de pagamento vencidas, follow-ups agendados e uma fila de risco priorizada, sem alterar quaisquer dados financeiros ou comerciais originais.

---

## 2. O que é a Governança de Cobrança no BIomie

Diferente da visualização padrão da carteira de cobrança (que agrupa os títulos puramente por vendedor e aging), a governança gerencia a **operação humana de contato**:
* **Quem** está cobrando (Produtividade por Operador).
* **Qual** o canal mais efetivo (Action Mix / Contatos).
* **Quais** compromissos foram firmados (Agenda de Follow-ups e Promessas).
* **Onde** há maior perigo operacional (Fila de Risco prioritária).

---

## 3. KPIs Implementados

Os seguintes KPIs executivos são calculados em memória no backend com base no cruzamento de títulos e logs:
* **Cobrados hoje**: Quantidade de ações de cobrança registradas na data atual.
* **Retornos hoje**: Agenda de compromissos ou retornos de contato agendados para a data atual (`next_action_date` = hoje).
* **Retornos atrasados**: Ações com data de retorno expirada (`next_action_date` < hoje) com títulos ainda em aberto.
* **Promessas vencidas**: Acordos de promessas de pagamento com data inferior a hoje sem liquidação detectada.
* **Vencidos sem ação**: Títulos que já venceram no calendário financeiro mas não possuem nenhuma ação de cobrança registrada no log.
* **Sem vendedor**: Títulos com pendência de origem/atribuição de vendedor no Omie.
* **% acionada**: Cobertura geral da carteira (títulos com alguma ação / total de títulos).

---

## 4. Regras de Priorização da Fila de Risco

A fila de risco operacional classifica os títulos abertos de forma determinística em três níveis de prioridade:

### Prioridade 1 — Crítico:
1. **Promessa de pagamento vencida/quebrada**: Ação do tipo `promessa_pagamento` com `next_action_date` < hoje.
2. **Retorno agendado vencido com título atrasado**: `next_action_date` < hoje e título com `data_vencimento` < hoje.
3. **Título vencido sem histórico de contato**: Título com `data_vencimento` < hoje e zero ações registradas.
4. **Título vencido de alto valor**: Atraso > 30 dias com `valor_aberto` >= R$ 5.000,00.
5. **Título de alto valor sem vendedor atribuído**: Título sem vendedor com `valor_aberto` >= R$ 2.000,00.

### Prioridade 2 — Atenção:
1. **Retorno agendado para hoje**: `next_action_date` = hoje.
2. **Título vencido sem novos contatos**: Título com `data_vencimento` < hoje e última ação realizada há mais de 15 dias.
3. **Promessa de pagamento vencendo hoje**: `promessa_pagamento` com `next_action_date` = hoje.
4. **Validar baixa no caixa**: Título quitado no Omie (`status_titulo` em liquidado, recebido ou pago) mas com saldo aberto local no BIomie.

### Prioridade 3 — Rotina:
1. **Título a vencer em breve**: Vencimento nos próximos 7 dias.
2. **Aguardando retorno dentro do prazo**: Ação do tipo `aguardando_retorno` com `next_action_date` >= hoje.
3. **Cobrança recente**: Ação de cobrança realizada nos últimos 5 dias (aguardar prazo de maturação antes de novo contato).

---

## 5. Fluxo Operacional Diário Recomendado

1. **Abrir o Painel**: Acessar a tela `financeiro.php`.
2. **Análise dos KPIs**: Verificar no topo se há "Promessas vencidas" ou "Retornos atrasados".
3. **Fila de Risco**: Tratar primeiro os itens de **Prioridade 1 — Crítico**.
4. **Executar Agenda**:
   - Resolver promessas quebradas.
   - Entrar em contato para retornos agendados para hoje.
5. **Revisar sem Vendedor**: Usar a ação "Revisar vínculo financeiro" para encaminhar títulos sem vendedor definido.
6. **Registrar Ações**: Usar o Drawer de Cobrança para registrar o andamento de cada título, gerando o log histórico append-only.

---

## 6. Arquitetura e Fonte de Dados

* **Fonte de Títulos**: Tabela `biomie_titulos_financeiros_canonicos`.
* **Fonte de Ações**: Tabela `biomie_admin_logs` usando o `AdminLogService` (evento `finance.collection_action`).
* **Segurança & RBAC**: Todos os endpoints de leitura exigem a capacidade `finance.view`. Ações de edição ou registro exigem `finance.manage` (se configurada).
* **Prepared Statements**: Todas as queries e buscas por parâmetros utilizam prepared statements do PDO para total segurança contra SQL Injection.
* **Leitura em Memória**: Para evitar gargalos e subqueries complexas em strings JSON, a API lê os dados brutos e realiza a consolidação e classificação lógica em memória no PHP de forma otimizada.

---

## 7. Limitações e Próximas Fases

* **details_json**: As ações são persistidas como JSON de log. Esta estrutura é excelente para flexibilidade, mas não deve ser usada para relatórios analíticos de longo prazo com milhões de linhas.
* **Fases Futuras**:
  - **FIN.R3.4**: Atribuição e conciliação manual controlada de vendedor por título.
  - **FIN.R3.5**: Definição de metas de cobrança e KPIs de performance de conversão de promessas.
  - **FIN.R3.6**: Automação de envios de lembretes e mensagens pré-vencimento via canais homologados.

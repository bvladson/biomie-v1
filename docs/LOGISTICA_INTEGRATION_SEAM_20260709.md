# Conexão com o Projeto Logístico — Seam de Integração (Design)

> Status: **DESIGN / FUTURO**. Este documento define **como** o BIomie vai se conectar
> a um projeto logístico futuro. Nenhum endpoint é ativado nesta rodada — é a planta
> baixa da costura de integração, para não improvisar quando o projeto logístico chegar.

## Contexto

O BIomie já é a fonte canônica, normalizada e **isolada por empresa** de notas fiscais,
pedidos, clientes, produtos e vendedores (origem Omie). Um projeto logístico futuro
(expedição, roteirização, rastreio de entrega) vai precisar consumir principalmente:

- **Pedidos/NFs a expedir**: itens, quantidades, cliente, endereço de entrega.
- **Cliente/endereço**: já enriquecido (geo) em `biomie_clientes_dim` + dimensão geográfica.
- **Status de faturamento**: o que virou NF autorizada (gatilho para expedir).

Ou seja: 90% do que a logística precisa **já existe** e está isolado por `company_id`.
Não se deve duplicar essa base — a logística deve **ler** do BIomie (ou receber eventos).

## Princípios (não-negociáveis)

1. **Isolamento por empresa**: todo contrato carrega/filtra `company_id`
   (`TenantContext::sqlFilter`). Nenhum vazamento cross-empresa — ver
   `project_tenant_leak_marts_20260627` e o `TenantIsolationGuard`.
2. **Somente leitura para a logística**: o BIomie é fonte de verdade comercial/fiscal;
   a logística não escreve nessas tabelas. Retornos de status de entrega voltam por um
   canal separado (ver "Volta do status", abaixo).
3. **Reuso da infra existente**: autenticação por token por empresa + fila/worker de
   webhooks **já existem** (ver `project_omie_webhooks_ready`: `api/omie_webhook.php`,
   rota `/webhooks/omie/{slug}/{token}`, tokens por empresa, worker processa inbox).
   A mesma mecânica serve para eventos de saída — **não reconstruir**.

## Opções e recomendação

| Opção | Como | Quando usar |
|---|---|---|
| **A. API de leitura (pull)** | Endpoints `api/logistics/*` que a logística consulta sob demanda | MVP: logística busca "pedidos a expedir do dia" |
| **B. Eventos de saída (push)** | Ao autorizar uma NF/pedido, emitir um webhook para a logística | Quando latência importa (expedir assim que fatura) |
| **C. Híbrido (recomendado)** | Push de "NF autorizada / pedido pronto" + pull para reconciliação/backfill | Produção |

**Recomendado: C (híbrido).** Push dá reação em tempo real; o pull cobre reprocessamento,
janelas perdidas e conferência. Ambos reusam a base canônica e o token por empresa.

## Contrato de leitura (rascunho — API `api/logistics/*`)

Padrão idêntico às APIs atuais (`api/bootstrap.php` + `Auth`/token + `TenantContext`):

- `GET api/logistics/dispatch_queue.php?start&end&status=to_ship`
  → pedidos/NFs prontos para expedir da empresa ativa. Campos: `codigo_pedido`,
  `nota_key`, `numero_nf`, `cliente` (id/nome/doc), **endereço de entrega**
  (de `biomie_clientes_*`/dimensão geo), `itens[]` (código, descrição, quantidade),
  `valor_total`, `data_faturamento`.
- `GET api/logistics/order_detail.php?codigo_pedido=...` → detalhe de um pedido para a expedição.
- Paginação e filtros pelo motor existente (`QueryStringMapper`, `Pagination`).

Reuso direto: `CanonicalRepository`, `CustomerReadRepository`,
`biomie_notas_fiscais_itens_canonicos`, dimensão geográfica de clientes.

## Contrato de saída (rascunho — eventos)

Reusar a mecânica de webhooks por empresa (mesma da Omie→BIomie, invertida):

- Evento `nf.authorized` / `order.ready_to_ship` com payload mínimo + `company_id` + link
  para o detalhe de leitura. Assinado com o token da empresa.
- Entregue à URL da logística cadastrada por empresa (mesma tabela/fluxo de integração).

## Volta do status (logística → BIomie)

Para status de entrega (saiu, em rota, entregue), a logística chama um **inbox**
dedicado (não escreve nas tabelas comerciais):

- `POST api/logistics/delivery_status.php` (token por empresa) grava em uma tabela nova
  `biomie_logistica_entregas` (com `company_id`), consumida por um card/rastreio no BIomie.
  Fora de escopo agora; só a nota de que o retorno é **desacoplado** das tabelas fiscais.

## Segurança & multi-empresa

- Token por empresa (reuso do esquema atual de `company_integration`).
- `TenantContext` obrigatório em todo endpoint; `TenantIsolationGuard` no OpsSentinel
  para alertar regressões de vazamento.
- Somente leitura nas entidades comerciais; escrita apenas na tabela própria da logística.

## Próximos passos (quando o projeto logístico existir)

1. Definir com a logística os campos exatos da fila de expedição (endereço/itens).
2. Implementar `api/logistics/dispatch_queue.php` (pull) reusando os repositórios canônicos.
3. Ligar o evento `nf.authorized` ao webhook de saída por empresa.
4. Criar `biomie_logistica_entregas` + `api/logistics/delivery_status.php` (inbox) e o card de rastreio.

**Nada disso é ativado nesta entrega.** Este documento é o contrato de referência.

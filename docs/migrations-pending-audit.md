# Auditoria das migrations pendentes

Captura inicial: `docs/generated/migration-status.json`.

Esta auditoria é somente leitura. Nenhuma versão foi marcada como aplicada e
nenhum DDL foi executado. A autoria individual não pode ser comprovada enquanto
o histórico Git estiver indisponível.

| Versão | Origem | Estruturas afetadas | Operação | Dependência atual | Lock/risco | Down | Classificação preliminar |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 20260718010000 | InvoiceProjectLineage | `biomie_notas_fiscais_canonicas` | coluna `projeto_id_omie`, índice e unique `(company_id, nota_id_omie)` | Mapper e classificação por projeto | Alto: três `ALTER`, criação de índices e validação de duplicidade | Destrutivo: remove coluna e índices | Necessária, condicionada a ensaio |
| 20260718020000 | InvoiceFiscalAuthorizationStatus | NF canônica, `mart_fiscal_nfe`, `mart_fiscal_items` | amplia ENUM `status_fiscal` | Classificação fiscal fail-closed | Médio/alto: rebuild possível em três tabelas | Com perda semântica: converte `nao_autorizada` em `cancelada` | Necessária; down não restaura o estado original |
| 20260718030000 | CompetenciaSnapshotVisibilityScope | snapshots e catálogo de competência | duas colunas por tabela e substituição de uniques | Isolamento por canal/visibilidade | Alto: quatro colunas e reconstrução de uniques | Condicional; recusa se houver múltiplos escopos | Necessária, maior risco do lote |
| 20260718040000 | ChannelProjectClassification | regras de classificação | amplia ENUM `rule_type` | Regras `projeto_id_list` | Médio: `ALTER` do ENUM | Seguro apenas sem regras desse tipo | Necessária após lineage de projeto |
| 20260719010000 | InvoiceOrderOperationLineage | `biomie_notas_fiscais_canonicas` | coluna `pedido_operacao_omie` e índice | Lineage `pedido.opPedido` | Alto: dois `ALTER` e índice | Destrutivo: remove coluna | Necessária após 20260718010000 |
| 20260719020000 | FiscalGoldCommercialBase | `mart_fiscal_nfe` | coluna decimal | Mart fiscal Gold | Médio: `ALTER` em mart materializado | Destrutivo: remove coluna | Necessária após validação do builder |

## Dependências e ordem proposta para ensaio

1. `20260718010000`
2. `20260719010000`
3. `20260718040000`
4. `20260718020000`
5. `20260718030000`
6. `20260719020000`

A ordem acima é para banco de teste e ainda precisa ser confrontada com o plano
do `MigrationRunner`. Em produção, cada versão exige backup, contagens, `SHOW
CREATE TABLE` antes/depois, medição de tempo e smoke específico.

## Evidências ainda obrigatórias

- autor/commit verdadeiro de cada arquivo;
- contagem e tamanho atual de cada tabela;
- presença atual de cada coluna e índice;
- duplicidades de `(company_id, nota_id_omie)`;
- existência de regras `projeto_id_list`;
- estimativa de algoritmo/lock do MariaDB para cada `ALTER`;
- ensaio de `up()` e `down()` em clone anonimizado.

Sem essas evidências, nenhuma das seis está autorizada para produção.


# Relatório final de isolamento por empresa

Data da correção e validação: 23/06/2026  
Escopo: páginas, APIs, repositórios, jobs, marts, dimensões, previsões,
oportunidades, fiscal, financeiro, estoque, geografia e consolidado.

## Resultado executivo

A regra de negócio foi implantada:

- cada empresa lê e grava somente registros com seu `company_id`;
- junções de chaves Omie, notas, títulos e clientes usam também `company_id`;
- o consolidado consulta somente as empresas autorizadas no contexto;
- o consolidado não é tratado como uma empresa artificial de ID zero;
- operações de escrita que exigem uma empresa específica falham no consolidado;
- materializações, filas e sincronizações carregam a empresa explicitamente;
- não existem mais registros com empresa inválida nas tabelas auditadas;
- a soma do consolidado confere exatamente com a soma das empresas individuais.

## Resultados atuais por empresa

Valores acumulados existentes no banco até 23/06/2026.

| Empresa | Pedidos | NFs | Receita NF autorizada | Títulos | Em aberto | Clientes | Receita mart de produtos |
|---|---:|---:|---:|---:|---:|---:|---:|
| MAG_FILIAL | 170 | 1.494 | R$ 3.662.395,11 | 5.083 | R$ 291.318,42 | 1.267 | R$ 3.469.007,09 |
| EXPERT | 8 | 74 | R$ 1.096.541,34 | 58 | R$ 690.971,70 | 28 | R$ 1.096.472,74 |
| UNI_DESIGN | 3 | 18 | R$ 36.960,00 | 44 | R$ 14.441,00 | 19 | R$ 37.079,00 |
| MAG_MATRIZ | 0 | 1 | R$ 199,00 | 2 | R$ 199,00 | 1 | R$ 0,00 |
| ERGOOFFICE | 0 | 233 | R$ 5.590.995,80 | 217 | R$ 1.949.549,19 | 77 | R$ 5.082.923,42 |
| Consolidado | 181 | 1.820 | R$ 10.387.091,25 | 5.404 | R$ 2.946.479,31 | 1.392 | R$ 9.685.482,25 |

As diferenças entre receita bruta de NF, produto e geografia são diferenças de
base semântica. O mart de produtos foi reconciliado com os itens canônicos de
cada empresa e a diferença encontrada foi R$ 0,00 para todas as empresas.

## Reconciliação do consolidado

| Métrica | Soma das empresas | Consulta consolidada | Diferença |
|---|---:|---:|---:|
| Receita de NFs autorizadas | R$ 10.387.091,25 | R$ 10.387.091,25 | R$ 0,00 |
| Contas em aberto | R$ 2.946.479,31 | R$ 2.946.479,31 | R$ 0,00 |
| Receita do mart de produtos | R$ 9.685.482,25 | R$ 9.685.482,25 | R$ 0,00 |
| Receita do mart geográfico | R$ 9.905.987,25 | R$ 9.905.987,25 | R$ 0,00 |

## Dados reconstruídos

Foram executadas materializações separadas para MAG_FILIAL, MAG_MATRIZ, EXPERT,
UNI_DESIGN e ERGOOFFICE.

Foram reconstruídos:

- dimensões de clientes e geografia;
- marts comercial, fiscal, financeiro, vendedor, cliente, produto, CNAE e geo;
- mart fiscal de itens;
- forecasts;
- oportunidades.

A divergência anterior do mart de produtos da ERGOOFFICE foi eliminada:

- fonte canônica dos itens: R$ 5.082.923,42;
- `mart_produto_periodo`: R$ 5.082.923,42;
- diferença: R$ 0,00.

## Correções estruturais

Principais correções aplicadas:

- filtros tenant em Financeiro, Dashboard, Produtos, Estoque, Clientes, Geo e Fiscal;
- filtros tenant em forecast e oportunidades;
- junções compostas por `company_id` e chave de negócio;
- remoção de `TRUNCATE` e exclusões globais em materializadores;
- exclusões e reconstruções limitadas à empresa ativa;
- suporte correto a `IN (...)` no consolidado;
- payloads de filas com `company_id` obrigatório;
- ações de cobrança com `company_id` e índices compostos;
- metas e snapshots históricos isolados por empresa;
- bloqueio de materializações e escritas no modo consolidado;
- constraints que impedem `company_id = 0` nos read models corrigidos.

A migration aplicada foi:

`Version20260623000000_StrictTenantDataIsolation.php`

## Limpeza operacional

Em 23/06/2026 foram encontrados 13 runs presos, iniciados em 22/06/2026.

Resultado:

- 13 runs encerrados como falha terminal;
- 7 cursores resetados para `idle`;
- zero sincronização antiga ainda em `running`.

## Testes executados

- `tests/run_multitenant_foundation.php`: 28/28;
- `tools/dev/multitenant_channel_guard.php`: aprovado;
- `tools/dev/company_selector_guard.php`: 12/12;
- `tools/dev/tab_context_isolation_guard.php`: 7/7;
- `bin/d50_consolidado_guard.php`: 148 arquivos, zero falhas e zero alertas;
- `bin/d48_tenant_guard.php`: 35/35;
- `bin/d53_company_storage_guard.php`: aprovado;
- validação de sintaxe PHP: 620 arquivos;
- migrations verificadas: 63, zero divergência de checksum;
- auditoria de isolamento: aprovada;
- registros com empresa inválida: zero;
- itens de NF sem NF da mesma empresa: zero;
- geografia sem cliente da mesma empresa: zero.

O comando de auditoria permanente é:

```bash
php bin/audit_tenant_isolation.php
```

## Pontos de qualidade de dados

Estes pontos não representam mistura entre empresas:

- empresas 2, 3, 4 e 5 possuem dimensão geográfica própria, mas vários registros
  ainda são esqueletos sem endereço, bairro ou CNAE recebido do Omie;
- MAG_FILIAL possui 109 clientes sem documento, 107 sem vendedor responsável,
  67 sem bairro e 898 sem CNAE;
- no momento da materialização, a origem da UNI_DESIGN estava com cerca de
  29 horas desde a última atualização, classificada como `warning`;
- forecasts e oportunidades refletem somente os dados atualmente disponíveis
  em cada empresa.

Esses itens exigem enriquecimento ou nova sincronização da empresa correspondente,
mas não provocam exposição de dados de outra empresa.

## Critério de aceite alcançado

1. Empresa A retorna somente dados de A.
2. Empresa B retorna somente dados de B.
3. Consolidado retorna exatamente a soma das empresas autorizadas.
4. Não há registros `company_id = 0` nas tabelas auditadas.
5. Drilldowns, buscas, exportações e detalhes usam o mesmo tenant do card.
6. Escritas e materializações exigem uma empresa individual.
7. Marts de produto conferem com sua fonte canônica por empresa.

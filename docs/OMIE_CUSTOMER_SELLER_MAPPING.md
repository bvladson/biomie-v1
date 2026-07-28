# BIomie - Mapeamento de cliente e vendedor Omie

## Objetivo

Documentar a origem local usada pelo BIomie para exibir cliente, documento e vendedor em leituras fiscais/comerciais, especialmente na tabela "Maiores notas fiscais".

## Cliente via NF-e

Campos Omie de referencia:

- `nfCadastro.nfDestInt.nCodCli`
- `nfCadastro.nfDestInt.cCodCliInt`
- `nfCadastro.nfDestInt.cRazao`
- `nfCadastro.nfDestInt.cnpj_cpf`
- `nfCadastro.ide.nIdCliente`
- `nfCadastro.ide.cnpj_cpf`

Campos locais encontrados:

- `biomie_notas_fiscais_canonicas.codigo_cliente_omie`
- `biomie_notas_fiscais_canonicas.nome_cliente`
- `biomie_notas_fiscais_canonicas.documento_cliente`

## Cliente via pedido

Campos Omie de referencia:

- `cabecalho.codigo_cliente`
- `cabecalho.codigo_cliente_integracao`

Campos locais encontrados:

- `biomie_pedidos_canonicos.codigo_cliente_omie`
- `biomie_pedidos_canonicos.nome_cliente`
- `biomie_pedidos_canonicos.documento_cliente`

## Cliente via dimensao local

Campos Omie de referencia:

- `codigo_cliente_omie`
- `codigo_cliente_integracao`
- `razao_social`
- `nome_fantasia`
- `cnpj_cpf`
- `contato`

Campos locais encontrados:

- `biomie_clientes_dim.codigo_cliente_omie`
- `biomie_clientes_dim.nome_cliente`
- `biomie_clientes_dim.nome_fantasia`
- `biomie_clientes_dim.cpf_cnpj`
- `biomie_clientes_dim.documento_normalizado`, quando a migration de governanca foi aplicada

## Vendedor via pedido

Campos Omie de referencia:

- `informacoes_adicionais.codVend`

Campos locais encontrados:

- `biomie_pedidos_canonicos.codigo_vendedor_omie`
- `biomie_pedidos_canonicos.nome_vendedor`
- `biomie_notas_fiscais_canonicas.codigo_vendedor_omie`
- `biomie_notas_fiscais_canonicas.nome_vendedor`

## Vendedor via dimensao local

Campos Omie de referencia:

- `codigo`
- `codInt`
- `nome`
- `inativo`
- `email`

Campos locais encontrados:

- `biomie_vendedores_omie.codigo_omie`
- `biomie_vendedores_omie.nome`
- `biomie_vendedores_omie.email`
- `biomie_vendedores_omie.ativo`
- `biomie_vendedores_omie.sincronizado_em`

## Regra de exibicao de cliente

Ordem de preferencia:

1. `biomie_clientes_dim.nome_cliente`
2. Razao do destinatario da NF (`biomie_notas_fiscais_canonicas.nome_cliente`)
3. `biomie_clientes_dim.nome_fantasia`
4. Nome do cliente no pedido, quando persistido localmente
5. Contato, quando existir em dimensao futura
6. Documento mascarado
7. `Cliente nao informado`

O documento nunca deve ser exibido aberto. O BIomie usa `BIomie\Support\DocumentMask`.

## Regra de exibicao de vendedor

Ordem de preferencia:

1. Nome encontrado na dimensao `biomie_vendedores_omie` via codigo do vendedor
2. Nome de vendedor persistido no pedido/NF canonica
3. `Sem vendedor`

O BIomie nao infere vendedor por cliente, usuario emissor, e-mail solto ou regra aproximada.

## Reparo de dados

A tela nao chama a API Omie durante a renderizacao. Quando houver cliente ou vendedor ausente, o reparo deve ocorrer em job/rotina administrativa:

- sincronizar clientes pela API de clientes/fornecedores
- sincronizar vendedores pela API de vendedores
- reprocessar canonical/gold quando necessario

Segredos Omie nao podem aparecer em tela, log de usuario ou diagnostico publico.

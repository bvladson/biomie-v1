# Diagnóstico de frescor e completude por empresa

Data da implementação e medição: 23/06/2026  
Banco de referência: `2026-06-23 15:06:53`

## Objetivo

Medir separadamente, para cada empresa:

- frescor da integração Omie, comercial, financeiro, clientes, marts Gold e estoque;
- completude de clientes, NFs, títulos financeiros e produtos;
- ações operacionais recomendadas sem misturar empresas.

O auditor implementado é somente leitura e sempre consulta um `company_id`
explícito.

## Resultado executivo

| Empresa | Status | Frescor | Completude | Cobertura geral |
|---|---|---|---|---:|
| MAG_FILIAL | warning | warning | warning | 96,96% |
| EXPERT | critical | critical | critical | 80,67% |
| UNI_DESIGN | critical | critical | critical | 76,13% |
| MAG_MATRIZ | critical | warning | critical | 40,00% |
| ERGOOFFICE | critical | critical | critical | 82,23% |

`critical` neste relatório representa atraso ou campo operacional incompleto.
Não representa mistura entre empresas. A auditoria de isolamento continua
aprovada.

## Frescor

### Situação comum

- clientes: `ok` nas cinco empresas;
- materializações Gold: `ok` nas cinco empresas;
- comercial: `warning` nas cinco empresas;
- estoque oficial: `no_data` nas cinco empresas.

### Financeiro

| Empresa | Status |
|---|---|
| MAG_FILIAL | warning |
| EXPERT | critical |
| UNI_DESIGN | critical |
| MAG_MATRIZ | warning |
| ERGOOFFICE | critical |

Prioridade operacional:

1. atualizar financeiro de EXPERT, UNI_DESIGN e ERGOOFFICE;
2. atualizar comercial das cinco empresas;
3. confirmar a rotina de estoque oficial antes de torná-la requisito de
   prontidão.

## Completude de clientes

| Empresa | Clientes | Sem documento | Sem vendedor | Sem cidade | Sem bairro | PJ sem CNAE |
|---|---:|---:|---:|---:|---:|---:|
| MAG_FILIAL | 1.267 | 109 | 107 | 67 | 69 | 108 |
| EXPERT | 28 | 0 | 2 | 28 | 28 | 21 |
| UNI_DESIGN | 19 | 0 | 0 | 19 | 19 | 9 |
| MAG_MATRIZ | 1 | 0 | 1 | 1 | 1 | 1 |
| ERGOOFFICE | 77 | 0 | 3 | 77 | 77 | 77 |

As dimensões existem para todas as empresas. Nas empresas 2–5, porém, vários
registros são esqueletos sem endereço e CNAE recebido da origem.

## Completude financeira

| Empresa | Títulos abertos | Sem vendedor | Sem forma de pagamento |
|---|---:|---:|---:|
| MAG_FILIAL | 91 | 34 | 29 |
| EXPERT | 40 | 40 | 40 |
| UNI_DESIGN | 4 | 0 | 4 |
| MAG_MATRIZ | 1 | 1 | 1 |
| ERGOOFFICE | 91 | 38 | 91 |

A rastreabilidade para NF/pedido está alta. Os principais gaps são vendedor e
forma de pagamento.

## Pontos aprovados

- NFs comerciais com cliente: cobertura aprovada;
- NFs comerciais com itens: cobertura aprovada;
- SKU e descrição dos itens faturados: cobertura aprovada;
- dimensões geográficas existem para os clientes das cinco empresas;
- marts Gold foram materializados recentemente;
- nenhuma linha com empresa inválida foi encontrada;
- nenhuma sincronização antiga permanece presa em `running`.

## Implementação entregue

Arquivos:

- `app/CompanyReadinessAuditor.php`;
- `bin/audit_company_readiness.php`;
- `tests/run_company_readiness.php`.

Execução resumida:

```bash
php bin/audit_company_readiness.php --pretty
```

Somente uma empresa:

```bash
php bin/audit_company_readiness.php --company=EXPERT
```

Gerar artefato JSON:

```bash
php bin/audit_company_readiness.php \
  --output=storage/reports/company_readiness.json
```

Falhar em automação quando houver estado crítico:

```bash
php bin/audit_company_readiness.php --fail-on=critical
```

## Próxima implementação recomendada

### P0 — frescor

Executar e validar sync isolado por empresa:

```bash
php cron/sync_realtime_pipeline.php --company=EXPERT --domains=finance
php cron/sync_realtime_pipeline.php --company=UNI_DESIGN --domains=finance
php cron/sync_realtime_pipeline.php --company=ERGOOFFICE --domains=finance
```

Depois atualizar comercial das cinco empresas e executar novamente o auditor.

### P1 — completude

1. enriquecer CNAE por empresa;
2. preencher cidade e bairro a partir da origem Omie;
3. enriquecer vendedor dos títulos usando NF/pedido da mesma empresa;
4. corrigir captura de forma de pagamento no sync financeiro;
5. decidir e implementar a sincronização oficial de estoque por empresa.

# BIomie v1 — plataforma de BI comercial sobre o ERP Omie

Sistema de inteligência comercial multiempresa construído sobre o ERP **Omie**, usado em
produção pelo Grupo MAG (5 empresas) entre 2026 e outubro de 2026, quando foi substituído
pela [v2](../biomie-v2). Este repositório preserva a primeira geração: um monolito PHP que
saiu de uma planilha e virou uma plataforma de dados com governança própria.

> **Status:** desativado em 23/10/2026, após a migração para a v2. O código está aqui como
> registro técnico, com o histórico completo de 113 commits e 6 branches de trabalho.

## O problema

A operação comercial de cinco empresas vivia dentro do Omie, mas o Omie não responde às
perguntas que a diretoria faz: *qual vendedor está fora do ritmo da meta hoje?*, *que cliente
sumiu?*, *qual CNAE compra mais e onde ele está?*. Exportar para planilha funcionava uma vez;
não funcionava toda segunda-feira, para cinco empresas, com números que precisam bater.

## O que o sistema faz

| Módulo | Entrega |
| --- | --- |
| **Cockpit** | Faturamento, meta, atingimento, forecast e carteira do período, por empresa |
| **Plano de Voo** | Ritmo necessário por dia útil, linha de meta distribuída, ação por vendedor |
| **Vendas** | Evolução, notas, pedidos, itens, drivers do resultado, exportação |
| **Clientes** | Carteira 360°, oportunidades, qualidade do cadastro, conflitos de titularidade |
| **Geografia** | Vendas por bairro/cidade/UF com enriquecimento oficial de CEP e malha IBGE |
| **CNAE** | Ranking, cobertura e oportunidades por atividade econômica |
| **Financeiro** | Títulos, cobrança por vendedor, impostos, conciliação |
| **Fiscal** | Notas, NCM, apuração, alertas e projeção da reforma tributária |

## Arquitetura

PHP 8 sem framework, por decisão: o sistema precisava rodar em hospedagem compartilhada no
início e migrar para VPS depois, sem reescrita.

```
api/        166 endpoints HTTP (um arquivo por recurso)
app/        503 classes — migração gradual de monolito para módulos por domínio
  Modules/    Sales, Finance, Fiscal, Geo (Application / Domain / Infrastructure)
  DataPlatform/  Bronze → Silver → Gold (payload cru → canônico → marts)
  Support/    multi-tenant, cifra de segredos, máscara de log, guardas
bin/        62 comandos de linha (sincronização, enriquecimento, auditoria)
database/   125 migrations versionadas
tests/      86 contratos executáveis
docs/       138 documentos de decisão
```

**Decisões que sustentaram o resto:**

- **Multiempresa por isolamento real**, não por coluna `company_id` solta: contexto de tenant
  resolvido na borda (`TenantContextResolver`), storage separado por empresa, e guardas
  (`d48_tenant_guard`, `d49_multitenant_hardening_guard`) que falham o release se vazar.
- **Camada canônica** (`README_CANONICAL.md`): o Omie é a fonte, mas não é a verdade. Dados
  crus entram em bronze, são normalizados em canônico e só então viram marts. Regras de
  negócio (como *vendedor "Licitação" = canal licitação*) vivem em um ponto só.
- **Barreira estrutural contra escrita em produção** (`ProductionDatabaseGuard`): a máquina de
  desenvolvimento lê produção por túnel SSH com usuário somente-leitura, e o guard aborta
  qualquer migration apontada para o banco de produção. Nasceu de um incidente real.
- **Segredos cifrados em repouso** (`SecretCipher`) e mascarados em log (`SecretMask`), com
  contrato de teste que falha se uma credencial aparecer em texto claro.
- **Portão de release** (`composer release:guard`): 55+ domínios verificados antes de publicar.

## Operação

Deploy por script idempotente na VPS Ubuntu (nginx + PHP-FPM + MariaDB), com rollback,
logrotate, cron inventariado e backup cifrado fora do servidor. O detalhe da infraestrutura
está em [infra-vps-ubuntu](../infra-vps-ubuntu).

## Sobre esta cópia pública

Para publicação, o endereço real da VPS foi trocado pelo IP de documentação
`203.0.113.10` (RFC 5737) e o identificador da instância por `srv-vps`. Nenhuma credencial
foi versionada em nenhum momento — `.env` e `config/*.local.php` sempre estiveram no
`.gitignore`, o que o histórico confirma.

---

## Autoria

Projeto meu — concepção, arquitetura, implementação, implantação e operação em produção.
Durante o desenvolvimento usei assistentes de IA (Codex e Claude) como ferramenta de apoio;
a condução e as decisões técnicas são minhas.

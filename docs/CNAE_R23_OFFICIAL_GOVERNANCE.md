# CNAE.R2.3 - Governanca oficial CNAE

## Fonte oficial

- Fonte: CONCLA/IBGE.
- Arquivo usado: `CNAE_Subclasses_2_3_Estrutura_Detalhada.xlsx`.
- URL oficial: `https://concla.ibge.gov.br/images/concla/documentacao/CNAE_Subclasses_2_3_Estrutura_Detalhada.xlsx`.
- Copia local auditavel: `storage/sources/cnae/CNAE_Subclasses_2_3_Estrutura_Detalhada.xlsx`.
- Hash SHA-256 atual: `96853a62e65a200ddb54b28bc7e8ad22fc7a0306cd3f21c21e63f1b934dc06ac`.

## Estrutura

A migration `20260602080000` expande `biomie_cnae_dim` com hierarquia oficial:

- secao, divisao, grupo, classe, subclasse;
- descricoes oficiais em cada nivel;
- versao, fonte e hash;
- tabelas `biomie_cnae_sync_runs` e `biomie_cliente_cnae_audit`;
- colunas de origem/confianca CNAE em `biomie_clientes_dim`.

Nenhuma coluna existente e removida no rollback automatico para evitar perda de historico.

## Comandos

Dry-run padrao:

```bash
php bin/cnae_sync.php --dry-run --format=json
```

Download da fonte oficial e dry-run:

```bash
php bin/cnae_sync.php --download --dry-run --format=json
```

Aplicar importacao oficial:

```bash
php bin/cnae_sync.php --apply --format=json
```

Probe de cobertura:

```bash
php tools/dev/cnae_coverage_probe.php --date-from=2026-01-01 --date-to=2026-06-02 --format=json
```

## Status apos importacao local

- Dimensao CNAE: 1.331 subclasses oficiais.
- Codigos invalidos na dimensao: 0.
- Descricoes vazias na dimensao: 0.
- Clientes com CNAE: 334/1.078.
- Clientes com CNAE que batem na dimensao: 334/334.
- Status restante: `client_cnae_enrichment_required`.

## Proxima frente

A fase oficial resolve a taxonomia e as descricoes. Ela nao inventa CNAE para cliente.

O proximo passo e enriquecer os 744 clientes sem CNAE usando a precedencia definida:

1. Omie cadastro cliente;
2. fonte fiscal/canonica quando existir;
3. enriquecimento oficial por CNPJ se for aprovado em fase posterior;
4. desconhecido com motivo.

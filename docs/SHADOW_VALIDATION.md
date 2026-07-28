# Shadow Validation - BIomie

## Objetivo

Shadow Validation compara payloads legacy e canonical sem alterar a resposta oficial enviada ao usuario. A validacao roda de forma diferida, registra divergencias e ajuda a decidir quando o canonical pode assumir mais responsabilidade nos endpoints.

## Contratos preservados

- A resposta oficial do endpoint continua sendo gerada pelo fluxo existente.
- `source_of_truth`, `last_success_at` e `last_reconcile_at` continuam como metadados oficiais de confianca.
- Golden contracts nao devem ser atualizados apenas por causa de divergencia shadow.
- Comparacao, tolerancia e persistencia nao podem alterar payload canonical, permissoes ou visual.

## Componentes

- `ShadowValidationService`: fachada publica temporaria. Mantem `boot()` e `defer()`.
- `ShadowValidationCoordinator`: aplica habilitacao, amostragem e agenda a tarefa diferida.
- `ShadowValidationRunner`: executa legacy/candidate, mede duracao e consolida o registro.
- `ShadowComparisonEngine`: compara overview, orders, vendors, finance e reconciliation.
- `ShadowTolerancePolicy`: centraliza tolerancias atuais sem mudar valores.
- `ShadowValidationRepository`: persiste em `biomie_shadow_validations` ou no fallback de log.
- `ShadowValidationFormatter`: mantem hash, JSON e truncamento no formato usado antes.
- `ShadowValidationIssue`, `ShadowValidationResult`, `ShadowValidationReport`: objetos de resultado para a futura camada semantica.

## Tolerancias atuais

- Valores monetarios: `0.01`.
- Diferencas numericas gerais: `0.01`.
- Contagens: `0`.

Esses valores refletem a regra existente e nao devem ser alterados sem decisao de contrato.

## Operacao

O shadow pode ser ligado por endpoint em `shadow_validation.endpoints`. Quando banco nao esta disponivel, a validacao grava fallback em `storage/logs/shadow_validation.log`.

Comandos uteis:

```bash
php tools/dev/lint_all.php
php bin/check_encoding.php
php tests/run_golden_endpoints.php
php tests/run_phase2_coverage.php
php tools/dev/large_file_audit.php --json
```


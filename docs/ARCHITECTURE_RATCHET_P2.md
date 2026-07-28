# Ratchet de arquitetura P2

Fonte viva: `docs/generated/architecture-debt.json`.

O catálogo separa leituras em infraestrutura permitida e dívida arquitetural,
priorizando endpoints. Ele é um detector lexical: cada ocorrência deve ser
confirmada antes de virar refatoração.

O ratchet de tamanho aplica a regra:

- arquivo legado acima de 1.000 linhas pode diminuir, mas não crescer;
- arquivo novo acima de 1.000 linhas falha;
- limites mais estritos para arquivos novos serão adicionados quando o Git
  voltar a fornecer uma distinção confiável entre arquivos novos e existentes.

Executar:

```bash
php tools/dev/architecture_debt_inventory.php --enforce
```


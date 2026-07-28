# Decisão de tipo para `company_id`

Fonte viva: `docs/generated/company-id-inventory.json`.

Tipo canônico futuro: `INT UNSIGNED`.

A migração será feita por família, nunca em um único release:

1. acesso e contexto;
2. Agenda;
3. canônico comercial;
4. marts;
5. observabilidade;
6. jobs e webhooks.

Antes de cada família: validar FKs, índices, estimativa de rebuild/lock, código
consumidor, backup e ensaio. Com cinco empresas, esta é uma dívida planejada,
não uma emergência operacional.


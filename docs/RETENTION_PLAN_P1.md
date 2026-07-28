# Plano de retenção P1

Fonte viva: `docs/generated/retention-plan.json`.

Nenhuma exclusão foi autorizada ou executada. O dry-run registra total,
elegíveis, intervalo temporal, tamanho proporcional estimado, lotes de 1.000 e
valor de auditoria.

Ordem candidata:

1. exportar e criar manifesto SHA-256;
2. validar restauração da exportação;
3. limpar spans operacionais em lotes pequenos;
4. agregar métricas antes de excluir;
5. preservar bronze até existir política fiscal/jurídica aprovada;
6. interromper por latência, lock, falha do healthz ou atraso de fila.

Automação semanal somente depois de duas execuções manuais comprovadas.


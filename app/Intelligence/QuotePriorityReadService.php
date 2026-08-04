<?php

declare(strict_types=1);

namespace BIomie\Intelligence;

use BIomie\Logger;
use PDO;
use Throwable;

/**
 * Lê a prioridade calculada dos orçamentos para exibir na Agenda.
 *
 * BARREIRA DO MODO SILENCIOSO: esta classe NUNCA lê `run_mode = 'shadow'`. Um
 * modelo em observação existe justamente para ser avaliado sem influenciar o
 * vendedor — se vazasse para a tela, a comparação contra o baseline ficaria
 * contaminada pelo comportamento que ele mesmo induziu. O filtro está no SQL,
 * não numa checagem que alguém possa esquecer de chamar.
 *
 * DEGRADAÇÃO GRACIOSA: se a tabela ainda não existe (migration não aplicada) ou
 * a consulta falha, a Agenda continua funcionando sem a coluna de prioridade.
 * Prioridade é enriquecimento — não pode derrubar a tela que o gestor usa.
 */
final class QuotePriorityReadService
{
    /** Modos que PODEM aparecer na tela. 'shadow' fica de fora de propósito. */
    private const MODOS_VISIVEIS = ['baseline', 'active'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly ?Logger $logger = null,
    ) {
    }

    /**
     * Prioridades vigentes de uma empresa, indexadas por omie_order_id.
     *
     * @return array<string,array{score:float,band:string,reasons:list<string>,scored_at:string,scorer:string}>
     */
    public function forCompany(int $companyId): array
    {
        $placeholders = implode(',', array_fill(0, count(self::MODOS_VISIVEIS), '?'));

        try {
            $stmt = $this->pdo->prepare(
                "SELECT omie_order_id, score, priority_band, reasons_json, scored_at, scorer
                   FROM intelligence_quote_scores
                  WHERE company_id = ?
                    AND is_current = 1
                    AND run_mode IN ($placeholders)
                  ORDER BY CASE run_mode WHEN 'active' THEN 0 ELSE 1 END"
            );
            $stmt->execute(array_merge([$companyId], self::MODOS_VISIVEIS));
        } catch (Throwable $e) {
            $this->logger?->warning('QuotePriorityReadService: leitura falhou; agenda segue sem prioridade.', [
                'message' => $e->getMessage(),
            ]);
            return [];
        }

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = (string) $row['omie_order_id'];
            // O ORDER BY coloca 'active' primeiro: quando o modelo estiver
            // promovido, ele vence o baseline sem precisar de outra consulta.
            if (isset($out[$key])) {
                continue;
            }

            $reasons = json_decode((string) ($row['reasons_json'] ?? '[]'), true);
            $out[$key] = [
                'score' => (float) $row['score'],
                'band' => (string) $row['priority_band'],
                'reasons' => is_array($reasons) ? array_values(array_filter($reasons, 'is_string')) : [],
                'scored_at' => (string) $row['scored_at'],
                'scorer' => (string) $row['scorer'],
            ];
        }

        return $out;
    }
}

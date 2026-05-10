<?php

declare(strict_types=1);

namespace BIomie\Modules\Finance\Infrastructure;

use Closure;
use PDO;

final class FinanceReadRepository
{
    public function __construct(
        private readonly Closure $pdoResolver,
    ) {
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listFinancialTitlesByPeriod(string $start, string $end): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT * FROM biomie_titulos_financeiros_canonicos
             WHERE data_competencia BETWEEN :start AND :end
             ORDER BY data_competencia DESC, data_liquidacao DESC, data_vencimento DESC, titulo_key DESC'
        );
        $stmt->execute(['start' => $start, 'end' => $end]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function pdo(): PDO
    {
        $pdo = ($this->pdoResolver)();
        if (!$pdo instanceof PDO) {
            throw new \RuntimeException('PDO resolver returned an invalid connection.');
        }

        return $pdo;
    }
}

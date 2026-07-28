<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Silver\Readers;

use Closure;
use PDO;

final class CanonicalEventReadRepository
{
    public function __construct(
        private readonly Closure $pdoResolver,
    ) {
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listByPeriod(string $start, string $end, ?string $entityName = null): array
    {
        $sql = <<<'SQL'
            SELECT *
            FROM biomie_eventos_canonicos
            WHERE DATE(event_at_omie) BETWEEN :start AND :end
        SQL;

        $params = ['start' => $start, 'end' => $end];
        if ($entityName !== null && $entityName !== '') {
            $sql .= ' AND entity_name = :entity_name';
            $params['entity_name'] = $entityName;
        }
        $sql .= ' ORDER BY event_at_omie ASC, event_order ASC, id ASC';

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);

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

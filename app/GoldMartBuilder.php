<?php

declare(strict_types=1);

namespace BIomie;

use BIomie\DataPlatform\Gold\GoldRefreshCoordinator;
use PDO;

/**
 * Fachada — delega para GoldRefreshCoordinator (Fase 5).
 *
 * Mantém a API pública original para compatibilidade com todos os callers.
 */
final class GoldMartBuilder
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Logger $logger,
        private readonly ?PipelineStatusRepository $pipelineStatus = null,
    ) {
    }

    private function coordinator(): GoldRefreshCoordinator
    {
        return new GoldRefreshCoordinator($this->pdo, $this->logger, $this->pipelineStatus);
    }

    /**
     * @return array<string,int|float>
     */
    public function refreshPeriod(string $start, string $end): array
    {
        return $this->coordinator()->refreshPeriod($start, $end);
    }

    /**
     * @return array<string,int|float>
     */
    public function refreshCommercialPeriod(string $start, string $end): array
    {
        return $this->coordinator()->refreshCommercialPeriod($start, $end);
    }

    /**
     * @return array<string,int|float>
     */
    public function refreshSince(string $since): array
    {
        return $this->refreshPeriod($since, date('Y-m-d'));
    }

    /**
     * @return array<string,int|float>
     */
    public function refreshToday(): array
    {
        $today = date('Y-m-d');
        return $this->refreshPeriod($today, $today);
    }
}

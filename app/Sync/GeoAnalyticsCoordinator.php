<?php

declare(strict_types=1);

namespace BIomie\Sync;

use BIomie\CanonicalRepository;
use BIomie\Logger;

/**
 * Coordenador que reconstrói a base analítica geográfica offline.
 */
final class GeoAnalyticsCoordinator
{
    public function __construct(
        private readonly CanonicalRepository $canonical,
        private readonly Logger $logger
    ) {
    }

    /**
     * Executa o enriquecimento e reconstrói as tabelas fatos do período selecionado.
     */
    public function buildAnalytics(string $start, string $end): void
    {
        $this->logger->info('GeoAnalyticsCoordinator: iniciando re-construcao analitica.', ['start' => $start, 'end' => $end]);
        
        $t0 = microtime(true);
        $this->canonical->reconstructGeoFactTables($start, $end);
        $duration = round(microtime(true) - $t0, 2);

        $this->logger->info('GeoAnalyticsCoordinator: build concluido.', ['duration_seconds' => $duration]);
    }
}

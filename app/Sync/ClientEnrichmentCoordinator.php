<?php

declare(strict_types=1);

namespace BIomie\Sync;

use BIomie\CanonicalRepository;
use BIomie\Canonical\ClientGeoMapper;
use BIomie\Logger;
use BIomie\OmieRepository;

/**
 * Coordenador do fluxo de enriquecimento passivo de clientes.
 * Processa a fila biomie_clientes_para_enriquecer.
 */
final class ClientEnrichmentCoordinator
{
    private ClientGeoMapper $mapper;

    public function __construct(
        private readonly OmieRepository $omie,
        private readonly CanonicalRepository $canonical,
        private readonly Logger $logger
    ) {
        $this->mapper = new ClientGeoMapper();
    }

    /**
     * @return array{processed:int,failed:int}
     */
    public function processQueue(int $limit = 200): array
    {
        $this->logger->info('ClientEnrichmentCoordinator: iniciando processamento da fila.', ['limit' => $limit]);

        $pendingCodes = $this->canonical->getPendingEnrichmentClients($limit);
        if ($pendingCodes === []) {
            return ['processed' => 0, 'failed' => 0];
        }

        // Marcar como 'processando'
        $this->canonical->markEnrichmentStatus($pendingCodes, 'processando');

        // Consultar API - array chaveado por código do cliente Omie (string)
        $clientesOmie = $this->omie->listClientsSummaryByCodes($pendingCodes);

        $processed = 0;
        $failed = 0;
        $falhas = [];
        $concluidos = [];

        foreach ($pendingCodes as $code) {
            $rawClient = $clientesOmie[(string) $code] ?? null;

            if ($rawClient === null) {
                // Cliente não retornado pela Omie ou excluído
                $falhas[] = $code;
                $failed++;
                continue;
            }

            try {
                $mapped = $this->mapper->mapClient($rawClient);
                $this->canonical->upsertClientDimensions($mapped['dim'], $mapped['geo']);
                $concluidos[] = $code;
                $processed++;
            } catch (\Throwable $e) {
                $this->logger->error('Falha ao processar dimensoes do cliente.', ['code' => $code, 'error' => $e->getMessage()]);
                $falhas[] = $code;
                $failed++;
            }
        }

        if ($concluidos !== []) {
            $this->canonical->markEnrichmentStatus($concluidos, 'concluido');
        }

        if ($falhas !== []) {
            $this->canonical->markEnrichmentStatus($falhas, 'falha', 'Cliente nao localizado na API listClientsSummaryByCodes ou falha de mapeamento.');
        }

        $this->logger->info('ClientEnrichmentCoordinator: finalizado.', ['processed' => $processed, 'failed' => $failed]);

        return ['processed' => $processed, 'failed' => $failed];
    }
}

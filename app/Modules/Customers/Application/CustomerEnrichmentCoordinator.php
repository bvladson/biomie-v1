<?php

declare(strict_types=1);

namespace BIomie\Modules\Customers\Application;

use BIomie\GeoNameNormalizer;
use BIomie\Logger;
use BIomie\Modules\Customers\Infrastructure\CustomerDimensionRepository;
use BIomie\Modules\Customers\Infrastructure\CustomerGeoSyncRepository;
use BIomie\Modules\Customers\Infrastructure\CustomerNormalizer;
use BIomie\Modules\Customers\Infrastructure\CustomerQualityValidator;
use BIomie\Modules\Customers\Infrastructure\OmieCustomerFetcher;
use Throwable;

final class CustomerEnrichmentCoordinator
{
    public function __construct(
        private readonly CustomerDimensionRepository $dimRepo,
        private readonly CustomerGeoSyncRepository   $geoRepo,
        private readonly CustomerNormalizer          $normalizer,
        private readonly CustomerQualityValidator    $validator,
        private readonly OmieCustomerFetcher         $fetcher,
        private readonly Logger                      $logger,
    ) {}

    /**
     * @param array{dry_run?:bool,limit?:int,offset?:int,pagina?:int,mode?:string,only_missing?:bool} $options
     * @return array<string,mixed>
     */
    public function run(array $options, ?int $actorUserId): array
    {
        $dryRun      = (bool) ($options['dry_run'] ?? false);
        $limit       = max(1, min(100, (int) ($options['limit'] ?? 25)));
        $mode        = (string) ($options['mode'] ?? 'local_first');
        $onlyMissing = !array_key_exists('only_missing', $options) || (bool) $options['only_missing'];

        if ($mode === 'omie_page') {
            $pagina = max(1, (int) ($options['pagina'] ?? 1));
            return $this->runOmiePage($dryRun, $limit, $pagina, $actorUserId);
        }

        $offset = max(0, (int) ($options['offset'] ?? 0));
        return $this->runLocalFirst($dryRun, $limit, $offset, $onlyMissing, $actorUserId);
    }

    // ── Local-first mode ──────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function runLocalFirst(bool $dryRun, int $limit, int $offset, bool $onlyMissing, ?int $actorUserId): array
    {
        $startedAt = microtime(true);
        $report    = CustomerEnrichmentReport::empty();
        $report['dry_run'] = $dryRun;
        $report['limit']   = $limit;
        $report['offset']  = $offset;
        $report['mode']    = 'local_first';

        try {
            $localClients = $this->dimRepo->fetchLocalClientsForEnrichment($limit, $offset, $onlyMissing);
            $report['clientes_locais_lidos'] = count($localClients);

            if ($localClients === []) {
                $report['ok'] = true;
                $report['status'] = 'no_clients';
                $report['duration_ms'] = (int) round((microtime(true) - $startedAt) * 1000);
                return $report;
            }

            $totalEligible = $this->dimRepo->countLocalClientsForEnrichment($onlyMissing);
            $c = $this->emptyCounters();
            $preview = [];
            $rateLimited = false;

            foreach ($localClients as $localClient) {
                $codigoOmie = (int) ($localClient['codigo_cliente_omie'] ?? 0);
                if ($codigoOmie === 0) { $c['ignorados']++; continue; }

                try {
                    $rawCliente = $this->fetcher->consult($codigoOmie);
                } catch (Throwable $e) {
                    $norm = $this->validator->normalizeMessage($e->getMessage());
                    if ($this->validator->isNotFoundError($norm))  { $c['notFound']++; continue; }
                    if ($this->validator->isRateLimitError($norm)) { $c['rateLimited']++; $rateLimited = true; break; }
                    $c['apiErrors']++;
                    $this->logger->warning('CustomerEnrichmentCoordinator: ConsultarCliente falhou.', [
                        'codigo_cliente_omie' => $codigoOmie, 'message' => $e->getMessage(),
                    ]);
                    continue;
                }

                if ($rawCliente === null) { $c['notFound']++; continue; }

                $c['consultados']++;
                $this->processRawClient(
                    $codigoOmie, $rawCliente, $dryRun,
                    'omie_consultar_cliente', $this->fetcher->getCallConsultar(),
                    $c, $preview
                );
            }

            $nextOffset = $offset + count($localClients);
            $hasMore    = $totalEligible > $nextOffset;

            $report['clientes_consultados_omie'] = $c['consultados'];
            $report['clientes_atualizados']      = $c['atualizados'];
            $report['clientes_ignorados']        = $c['ignorados'];
            $report['clientes_nao_encontrados']  = $c['notFound'];
            $report['api_errors']                = $c['apiErrors'];
            $report['api_rate_limited']          = $c['rateLimited'];
            $report['bronze_saved']              = $c['bronzeSaved'];
            $report['clientes_com_cidade']       = $c['comCidade'];
            $report['clientes_com_bairro']       = $c['comBairro'];
            $report['clientes_com_cnae']         = $c['comCnae'];
            $report['clientes_sem_bairro']       = $c['semBairro'];
            $report['clientes_sem_cnae']         = $c['semCnae'];
            $report['bairros_matched']           = $c['bairrosMatched'];
            $report['bairros_unmatched']         = $c['bairrosUnmatched'];
            $report['queued']                    = $c['queued'];
            $report['not_found']                 = $c['notFound'];
            $report['cidade_atualizados']        = $c['cidadeAtualizados'];
            $report['bairro_atualizados']        = $c['bairroAtualizados'];
            $report['cnae_atualizados']          = $c['cnaeAtualizados'];
            $report['next_offset']               = $hasMore ? $nextOffset : null;
            $report['has_more']                  = $hasMore;
            $report['total_eligible']            = $totalEligible;
            $report['ok']                        = true;

            if ($rateLimited) {
                $report['status'] = 'rate_limited';
            } elseif ($c['apiErrors'] > 0) {
                $report['status'] = $dryRun ? 'dry_run' : 'partial';
            } else {
                $report['status'] = $dryRun ? 'dry_run' : ($hasMore ? 'partial' : 'ok');
            }

            if ($dryRun) { $report['preview'] = $preview; }

        } catch (Throwable $e) {
            $norm = $this->validator->normalizeMessage($e->getMessage());
            $isRL = $this->validator->isRateLimitError($norm);
            $this->logger->error('CustomerEnrichmentCoordinator: falha no modo local_first.', [
                'message' => $e->getMessage(), 'actor_user_id' => $actorUserId, 'rate_limited' => $isRL,
            ]);
            $report['status']           = $isRL ? 'rate_limited' : 'error';
            $report['api_rate_limited'] = $isRL ? 1 : 0;
            $report['error_message']    = $e->getMessage();
        }

        $report['duration_ms'] = (int) round((microtime(true) - $startedAt) * 1000);
        return $report;
    }

    // ── Omie-page mode (ListarClientes) ───────────────────────────────────────

    /** @return array<string,mixed> */
    private function runOmiePage(bool $dryRun, int $limit, int $pagina, ?int $actorUserId): array
    {
        $startedAt = microtime(true);
        $report    = CustomerEnrichmentReport::empty();
        $report['dry_run'] = $dryRun;
        $report['limit']   = $limit;
        $report['pagina']  = $pagina;
        $report['mode']    = 'omie_page';

        try {
            $response   = $this->fetcher->listPage($pagina, $limit);
            $clientes   = $response['clientes_cadastro'] ?? [];
            $totalPages = (int) ($response['total_de_paginas'] ?? 1);
            $totalReg   = (int) ($response['total_de_registros'] ?? count($clientes));

            $report['total_paginas_omie']   = $totalPages;
            $report['total_registros_omie'] = $totalReg;
            $report['pagina_atual']         = $pagina;

            $c = $this->emptyCounters();
            $preview = [];

            foreach ($clientes as $rawCliente) {
                $c['consultados']++;
                $codigoOmie = (int) ($rawCliente['codigo_cliente_omie'] ?? 0);
                if ($codigoOmie === 0) { $c['ignorados']++; continue; }

                $this->processRawClient(
                    $codigoOmie, $rawCliente, $dryRun,
                    'omie_listagem', $this->fetcher->getCallListar(),
                    $c, $preview
                );
            }

            $report['clientes_consultados_omie'] = $c['consultados'];
            $report['clientes_atualizados']      = $c['atualizados'];
            $report['clientes_ignorados']        = $c['ignorados'];
            $report['api_errors']                = $c['apiErrors'];
            $report['bronze_saved']              = $c['bronzeSaved'];
            $report['clientes_com_cidade']       = $c['comCidade'];
            $report['clientes_com_bairro']       = $c['comBairro'];
            $report['clientes_com_cnae']         = $c['comCnae'];
            $report['clientes_sem_bairro']       = $c['semBairro'];
            $report['clientes_sem_cnae']         = $c['semCnae'];
            $report['bairros_matched']           = $c['bairrosMatched'];
            $report['bairros_unmatched']         = $c['bairrosUnmatched'];
            $report['queued']                    = $c['queued'];
            $report['not_found']                 = $c['notFound'];
            $report['cidade_atualizados']        = $c['cidadeAtualizados'];
            $report['bairro_atualizados']        = $c['bairroAtualizados'];
            $report['cnae_atualizados']          = $c['cnaeAtualizados'];
            $report['ok']                        = true;
            $report['status']                    = $dryRun ? 'dry_run' : ($c['apiErrors'] > 0 ? 'partial' : 'ok');

            if ($dryRun) { $report['preview'] = $preview; }

        } catch (Throwable $e) {
            $norm = $this->validator->normalizeMessage($e->getMessage());
            $isRL = $this->validator->isRateLimitError($norm);
            $this->logger->error('CustomerEnrichmentCoordinator: falha na chamada Omie (page mode).', [
                'message' => $e->getMessage(), 'actor_user_id' => $actorUserId, 'rate_limited' => $isRL,
            ]);
            $report['status']           = $isRL ? 'rate_limited' : 'error';
            $report['api_rate_limited'] = $isRL ? 1 : 0;
            $report['error_message']    = $e->getMessage();
        }

        $report['duration_ms'] = (int) round((microtime(true) - $startedAt) * 1000);
        return $report;
    }

    // ── Per-client processing (shared by both modes) ──────────────────────────

    /**
     * Processa um cliente já obtido (raw payload Omie).
     * Incrementa contadores via referência; não propaga exceções.
     *
     * @param array<string,mixed>       $rawCliente
     * @param array<string,int>         $c       contadores (referência)
     * @param list<array<string,mixed>> $preview amostras dry_run (referência)
     */
    private function processRawClient(
        int    $codigoOmie,
        array  $rawCliente,
        bool   $dryRun,
        string $enrichSource,
        string $bronzeCallMethod,
        array  &$c,
        array  &$preview,
    ): void {
        $fields = $this->normalizer->normalize($rawCliente);

        if ($fields['cidade'] !== '')      { $c['comCidade']++; }
        if ($fields['bairro'] !== '')      { $c['comBairro']++; } else { $c['semBairro']++; }
        if ($fields['cnae_codigo'] !== '') { $c['comCnae']++;   } else { $c['semCnae']++;   }

        if ($dryRun) {
            $bairroNorm = $fields['bairro'] !== ''
                ? GeoNameNormalizer::normalizeNeighborhood($fields['bairro'])
                : '';
            $wouldMatch = ($bairroNorm !== '' && $fields['cidade_norm'] === 'fortaleza' && $fields['uf'] === 'CE')
                ? $this->dimRepo->findBairroOficial($bairroNorm)
                : null;
            if (count($preview) < 10) {
                $preview[] = [
                    'codigo_cliente_omie' => $codigoOmie,
                    'razao_social'        => mb_substr((string) ($rawCliente['razao_social'] ?? ''), 0, 40),
                    'cidade'              => $fields['cidade'],
                    'uf'                  => $fields['uf'],
                    'bairro'              => $fields['bairro'],
                    'bairro_norm'         => $bairroNorm,
                    'bairro_match'        => $wouldMatch !== null ? $wouldMatch['nome'] : null,
                    'cnae'                => $fields['cnae_codigo'],
                ];
            }
            return;
        }

        if ($this->dimRepo->saveBronze($codigoOmie, $rawCliente, $bronzeCallMethod)) {
            $c['bronzeSaved']++;
        }

        try {
            $result = $this->dimRepo->persist($codigoOmie, $rawCliente, $fields, $enrichSource);

            if ($result['changed']) { $c['atualizados']++; } else { $c['ignorados']++; }
            if ($result['field_updates']['cidade']) { $c['cidadeAtualizados']++; }
            if ($result['field_updates']['bairro']) { $c['bairroAtualizados']++; }
            if ($result['field_updates']['cnae'])   { $c['cnaeAtualizados']++; }

            $bRes = $result['bairro_result'];
            if ($bRes === 'matched')       { $c['bairrosMatched']++; }
            elseif ($bRes === 'unmatched') { $c['bairrosUnmatched']++; $c['queued']++; }
            elseif (in_array($bRes, ['bairro_ausente', 'cidade_ausente'], true)) { $c['queued']++; }

            $this->geoRepo->sync($codigoOmie, $fields, $result['bairro_norm'], $result['oficial_bairro']);
        } catch (Throwable $e) {
            $c['apiErrors']++;
            $this->logger->warning('CustomerEnrichmentCoordinator: falha ao enriquecer cliente.', [
                'codigo_cliente_omie' => $codigoOmie, 'message' => $e->getMessage(),
            ]);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @return array<string,int> */
    private function emptyCounters(): array
    {
        return [
            'consultados'      => 0, 'atualizados'      => 0, 'ignorados'        => 0,
            'apiErrors'        => 0, 'rateLimited'      => 0, 'bronzeSaved'      => 0,
            'notFound'         => 0, 'comCidade'        => 0, 'comBairro'        => 0,
            'comCnae'          => 0, 'semBairro'        => 0, 'semCnae'          => 0,
            'bairrosMatched'   => 0, 'bairrosUnmatched' => 0, 'queued'           => 0,
            'cidadeAtualizados'=> 0, 'bairroAtualizados'=> 0, 'cnaeAtualizados'  => 0,
        ];
    }
}

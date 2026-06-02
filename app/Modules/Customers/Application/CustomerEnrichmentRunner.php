<?php

declare(strict_types=1);

namespace BIomie\Modules\Customers\Application;

use BIomie\Logger;
use BIomie\Modules\Customers\Infrastructure\CustomerDimensionRepository;
use BIomie\Modules\Customers\Infrastructure\CustomerGeoSyncRepository;
use BIomie\Modules\Customers\Infrastructure\CustomerNormalizer;
use BIomie\Modules\Customers\Infrastructure\CustomerQualityValidator;
use BIomie\Modules\Customers\Infrastructure\OmieCustomerFetcher;
use Throwable;

final class CustomerEnrichmentRunner
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
     * Enriquece TODOS os clientes elegíveis em lotes usando cursor por codigo_cliente_omie.
     * Nunca usa OFFSET sobre lista mutável.
     *
     * @param array{
     *   dry_run?:bool, limit?:int, max_batches?:int, only_missing?:bool,
     *   sleep_ms?:int, max_runtime_seconds?:int, target?:string, source?:string
     * } $options
     * @return array<string,mixed>
     */
    public function runAll(array $options, ?int $actorUserId): array
    {
        $dryRun         = (bool) ($options['dry_run']              ?? false);
        $limit          = max(1, min(50, (int) ($options['limit']           ?? 25)));
        $maxBatches     = max(1, min(200, (int) ($options['max_batches']    ?? 20)));
        $onlyMissing    = !array_key_exists('only_missing', $options) || (bool) $options['only_missing'];
        $sleepMs        = max(0, (int) ($options['sleep_ms']           ?? 0));
        $maxRuntimeSecs = max(0, (int) ($options['max_runtime_seconds'] ?? 0));
        $target         = in_array($options['target'] ?? '', ['geo', 'cnae', 'all'], true) ? (string) $options['target'] : 'geo';
        $source         = in_array($options['source'] ?? '', ['sales_buyers', 'clientes_dim', 'all'], true) ? (string) $options['source'] : 'sales_buyers';

        $startedAt = microtime(true);

        $agg = [
            'ok'                    => false,
            'status'                => 'pending',
            'mode'                  => 'local_first_all',
            'target'                => $target,
            'source'                => $source,
            'dry_run'               => $dryRun,
            'limit'                 => $limit,
            'max_batches'           => $maxBatches,
            'batches_executed'      => 0,
            'total_eligible_before' => 0,
            'total_remaining_after' => 0,
            'has_more'              => false,
            'stopped_reason'        => 'exhausted',
            'processed'             => 0,
            'updated'               => 0,
            'ignored'               => 0,
            'not_found'             => 0,
            'api_errors'            => 0,
            'rate_limited'          => 0,
            'with_city_from_omie'   => 0,
            'with_bairro_from_omie' => 0,
            'with_cnae_from_omie'   => 0,
            'cidade_updated'        => 0,
            'bairro_updated'        => 0,
            'cnae_updated'          => 0,
            'bairro_matched'        => 0,
            'bairro_unmatched'      => 0,
            'queued_enrichment'     => 0,
            'bronze_saved'          => 0,
            'duration_ms'           => 0,
        ];

        $agg['total_eligible_before'] = $this->dimRepo->countLocalClientsForEnrichment($onlyMissing, $target, $source);
        $lastCode   = 0;
        $batchesRun = 0;

        while ($batchesRun < $maxBatches) {
            if ($maxRuntimeSecs > 0 && (microtime(true) - $startedAt) >= $maxRuntimeSecs) {
                $agg['stopped_reason'] = 'max_runtime';
                break;
            }

            $localClients = $this->dimRepo->fetchLocalClientsForEnrichmentCursor($limit, $lastCode, $onlyMissing, $target, $source);
            if ($localClients === []) {
                $agg['stopped_reason'] = 'exhausted';
                break;
            }

            $batchesRun++;
            $gotRateLimit = false;

            foreach ($localClients as $localClient) {
                $codigoOmie = (int) ($localClient['codigo_cliente_omie'] ?? 0);
                if ($codigoOmie === 0) { $agg['ignored']++; continue; }

                if (!$dryRun) {
                    $this->dimRepo->ensureMinimalClient($codigoOmie, $localClient);
                }

                try {
                    $rawCliente = $this->fetcher->consult($codigoOmie);
                } catch (Throwable $e) {
                    $norm = $this->validator->normalizeMessage($e->getMessage());
                    if ($this->validator->isNotFoundError($norm))  {
                        $agg['not_found']++;
                        if (!$dryRun) {
                            $this->dimRepo->markEnrichmentQueue($codigoOmie, 'erro', 'omie_nao_encontrado', $norm);
                        }
                        continue;
                    }
                    if ($this->validator->isRateLimitError($norm)) { $agg['rate_limited']++; $gotRateLimit = true; break; }
                    $agg['api_errors']++;
                    if (!$dryRun) {
                        $this->dimRepo->markEnrichmentQueue($codigoOmie, 'erro', 'omie_api_error', $norm);
                    }
                    $this->logger->warning('CustomerEnrichmentRunner::runAll ConsultarCliente falhou.', [
                        'codigo_cliente_omie' => $codigoOmie, 'message' => $e->getMessage(),
                    ]);
                    continue;
                }

                if ($rawCliente === null) {
                    $agg['not_found']++;
                    if (!$dryRun) {
                        $this->dimRepo->markEnrichmentQueue($codigoOmie, 'erro', 'omie_sem_retorno', 'ConsultarCliente retornou vazio');
                    }
                    continue;
                }

                $agg['processed']++;
                $fields = $this->normalizer->normalize($rawCliente);

                if ($fields['cidade'] !== '')      { $agg['with_city_from_omie']++; }
                if ($fields['bairro'] !== '')      { $agg['with_bairro_from_omie']++; }
                if ($fields['cnae_codigo'] !== '') { $agg['with_cnae_from_omie']++; }

                if (!$dryRun) {
                    if ($this->dimRepo->saveBronze($codigoOmie, $rawCliente, $this->fetcher->getCallConsultar())) {
                        $agg['bronze_saved']++;
                    }

                    try {
                        $result = $this->dimRepo->persist($codigoOmie, $rawCliente, $fields, 'omie_consultar_cliente_all');
                        if ($result['changed']) { $agg['updated']++; } else { $agg['ignored']++; }
                        if ($result['field_updates']['cidade']) { $agg['cidade_updated']++; }
                        if ($result['field_updates']['bairro']) { $agg['bairro_updated']++; }
                        if ($result['field_updates']['cnae'])   { $agg['cnae_updated']++; }

                        $bRes = $result['bairro_result'];
                        if ($bRes === 'matched')       { $agg['bairro_matched']++; }
                        elseif ($bRes === 'unmatched') { $agg['bairro_unmatched']++; $agg['queued_enrichment']++; }
                        elseif (in_array($bRes, ['bairro_ausente', 'cidade_ausente'], true)) {
                            $agg['queued_enrichment']++;
                        }

                        $this->geoRepo->sync($codigoOmie, $fields, $result['bairro_norm'], $result['oficial_bairro']);
                        [$queueStatus, $queueMotivo] = $this->queueStatusForFields($fields, $target);
                        $this->dimRepo->markEnrichmentQueue($codigoOmie, $queueStatus, $queueMotivo);
                    } catch (Throwable $e) {
                        $agg['api_errors']++;
                        $this->dimRepo->markEnrichmentQueue($codigoOmie, 'erro', 'persist_error', $e->getMessage());
                        $this->logger->warning('CustomerEnrichmentRunner::runAll enrich falhou.', [
                            'codigo_cliente_omie' => $codigoOmie, 'message' => $e->getMessage(),
                        ]);
                    }
                }
            }

            $maxCodeInBatch = (int) max(array_column($localClients, 'codigo_cliente_omie'));
            $lastCode       = $maxCodeInBatch;

            if ($gotRateLimit) {
                $agg['stopped_reason'] = 'rate_limited';
                break;
            }
            if (count($localClients) < $limit) {
                $agg['stopped_reason'] = 'exhausted';
                break;
            }
            if ($sleepMs > 0 && !$dryRun && PHP_SAPI === 'cli') {
                usleep($sleepMs * 1000);
            } elseif ($sleepMs > 0 && !$dryRun) {
                $this->logger->warning('Sleep de enriquecimento Omie suprimido fora de CLI.', [
                    'sleep_ms' => $sleepMs, 'service' => self::class,
                ]);
            }
        }

        if ($batchesRun >= $maxBatches && $agg['stopped_reason'] === 'exhausted') {
            $agg['stopped_reason'] = 'max_batches';
        }

        $agg['batches_executed']      = $batchesRun;
        $agg['total_remaining_after'] = $this->dimRepo->countLocalClientsForEnrichment($onlyMissing, $target, $source);
        $agg['has_more']              = $agg['total_remaining_after'] > 0;
        $agg['ok']                    = true;
        $agg['status']                = $dryRun ? 'dry_run'
            : ($agg['rate_limited'] > 0 ? 'rate_limited'
            : ($agg['has_more']         ? 'partial'
            : 'ok'));
        $agg['duration_ms']           = (int) round((microtime(true) - $startedAt) * 1000);

        return $agg;
    }

    /** @param array<string,string> $fields */
    private function queueStatusForFields(array $fields, string $target): array
    {
        $missing = [];
        if ($target === 'geo' || $target === 'all') {
            if (($fields['cidade'] ?? '') === '') {
                $missing[] = 'cidade_ausente';
            }
            if (($fields['bairro'] ?? '') === '') {
                $missing[] = 'bairro_ausente';
            }
        }
        if ($target === 'cnae' || $target === 'all') {
            if (($fields['cnae_codigo'] ?? '') === '') {
                $missing[] = 'cnae_ausente';
            }
        }

        if ($missing === []) {
            return ['concluido', 'omie_completo'];
        }
        return ['pendente', implode(',', $missing)];
    }
}

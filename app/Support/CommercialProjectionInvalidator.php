<?php

declare(strict_types=1);

namespace BIomie\Support;

use BIomie\Cache;
use BIomie\Support\SecretMask;
use PDO;
use Throwable;

/**
 * Invalida, de forma centralizada, as projecoes comerciais derivadas.
 *
 * Uma alteracao Canonical/Gold pode afetar simultaneamente Varejo e Licitacao.
 * Por isso o catalogo e marcado por empresa/competencia (todos os escopos) e
 * os caches dos dois canais sao removidos antes de restaurar o contexto CLI.
 */
final class CommercialProjectionInvalidator
{
    /** @var list<string> */
    private const CACHE_PREFIXES = [
        'snapshot_fiscal',
        'api_overview_payload',
        'home_bootstrap',
        'dashboard_boot',
        'board_consolidated',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $cacheRoot,
    ) {
    }

    /** @return array<string,mixed> */
    public function invalidate(
        int $companyId,
        string $originalChannel,
        string $start,
        string $end,
    ): array {
        $catalog = $this->markSnapshotCatalogStale($companyId, $start, $end);
        $cache = $this->invalidateChannelCaches($companyId, $originalChannel);

        return [
            'ok' => (bool) ($catalog['ok'] ?? false) && (bool) ($cache['ok'] ?? false),
            'snapshot_catalog' => $catalog,
            'cache' => $cache,
        ];
    }

    /** @return array<string,mixed> */
    private function markSnapshotCatalogStale(int $companyId, string $start, string $end): array
    {
        $from = substr($start, 0, 7);
        $to = substr($end, 0, 7);

        try {
            $tableStmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
            );
            $tableStmt->execute(['table' => 'biomie_competencia_sync_catalog']);
            if ((int) $tableStmt->fetchColumn() === 0) {
                return [
                    'ok' => false,
                    'rows_marked_stale' => 0,
                    'competencia_from' => $from,
                    'competencia_to' => $to,
                    'error' => 'biomie_competencia_sync_catalog ausente',
                ];
            }

            $stmt = $this->pdo->prepare(
                "UPDATE biomie_competencia_sync_catalog
                    SET status = 'stale', updated_at = NOW()
                  WHERE company_id = :company_id
                    AND competencia >= :from_competencia
                    AND competencia <= :to_competencia"
            );
            $stmt->execute([
                'company_id' => $companyId,
                'from_competencia' => $from,
                'to_competencia' => $to,
            ]);

            return [
                'ok' => true,
                'rows_marked_stale' => $stmt->rowCount(),
                'competencia_from' => $from,
                'competencia_to' => $to,
                'scope' => 'all_channels_and_visibility_scopes',
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'rows_marked_stale' => 0,
                'competencia_from' => $from,
                'competencia_to' => $to,
                'error' => SecretMask::sanitizeErrorMessage($exception->getMessage(), 300),
            ];
        }
    }

    /** @return array<string,mixed> */
    private function invalidateChannelCaches(int $companyId, string $originalChannel): array
    {
        $channels = ['varejo', 'licitacao'];
        $invalidated = [];
        $failed = 0;

        try {
            $cache = new Cache($this->cacheRoot);
            foreach ($channels as $channel) {
                TenantContext::bootForCompany($this->pdo, $companyId, $channel);
                foreach (self::CACHE_PREFIXES as $prefix) {
                    $result = $cache->invalidatePrefix($prefix);
                    $invalidated[$channel][$prefix] = $result;
                    $failed += $result['failed'];
                }
            }

            // O painel consolidado pode ter sido criado a partir de qualquer
            // empresa visivel ao usuario. Como nao existe um indice reverso de
            // conjuntos consolidados, remova apenas seus pequenos payloads
            // comerciais em todos os diretorios de empresa.
            $consolidated = $this->invalidateConsolidatedCaches();
            $failed += $consolidated['failed'];

            return [
                'ok' => $failed === 0,
                'channels' => $invalidated,
                'consolidated' => $consolidated,
                'scope' => 'company_varejo_and_licitacao',
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'channels' => $invalidated,
                'scope' => 'company_varejo_and_licitacao',
                'error' => SecretMask::sanitizeErrorMessage($exception->getMessage(), 300),
            ];
        } finally {
            // O restante do comando continua sob o mesmo canal informado no
            // bootstrap, inclusive logs e encerramento da pipeline.
            TenantContext::bootForCompany($this->pdo, $companyId, $originalChannel);
        }
    }

    /** @return array{matched:int,deleted:int,failed:int,directories:int} */
    private function invalidateConsolidatedCaches(): array
    {
        $summary = ['matched' => 0, 'deleted' => 0, 'failed' => 0, 'directories' => 0];
        $consolidatedDirectory = dirname(rtrim($this->cacheRoot, '/\\'))
            . '/companies/consolidado/cache';
        $fallbackDirectories = glob(
            rtrim($this->cacheRoot, '/\\') . '/tenant_0_consolidado_*',
            GLOB_ONLYDIR,
        );
        $directories = array_values(array_unique(array_merge(
            is_dir($consolidatedDirectory) ? [$consolidatedDirectory] : [],
            is_array($fallbackDirectories) ? $fallbackDirectories : [],
        )));

        foreach ($directories as $directory) {
            $summary['directories']++;
            $cache = new Cache($directory, isolated: true);
            foreach (self::CACHE_PREFIXES as $prefix) {
                // R1 usa chave lógica primeiro e fingerprint group no sufixo.
                // O prefixo cru alcança todos os usuários/conjuntos no diretório
                // consolidado; a chamada legada remove resíduos pré-R1.
                $result = $cache->invalidatePrefix($prefix);
                $legacy = $cache->invalidatePrefix('canal_consolidado_' . $prefix);
                $summary['matched'] += $result['matched'];
                $summary['matched'] += $legacy['matched'];
                $summary['deleted'] += $result['deleted'] + $legacy['deleted'];
                $summary['failed'] += $result['failed'] + $legacy['failed'];
            }
        }

        return $summary;
    }
}

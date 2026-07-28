<?php

declare(strict_types=1);

namespace BIomie\Modules\Sales\Application\Overview;

use BIomie\Http;
use BIomie\Support\TenantContext;

final class OverviewRequest
{
    /**
     * Increment whenever the overview payload contract or tenant scoping changes.
     * Keeping the version in the key makes old payloads unreachable without a
     * filesystem-wide cache purge.
     */
    public const CACHE_CONTRACT_VERSION = 2;

    /**
     * @param array{start:string,end:string,label:string,competencia:?string} $range
     */
    public function __construct(
        public readonly array $range,
        public readonly bool $force,
    ) {
    }

    public static function fromGlobals(): self
    {
        $range = Http::resolveDateRangeOrCompetencia();
        Http::validateDateRange($range['start'], $range['end']);

        return new self($range, Http::boolQuery('force', false));
    }

    public function start(): string
    {
        return $this->range['start'];
    }

    public function end(): string
    {
        return $this->range['end'];
    }

    public function competencia(): ?string
    {
        return $this->range['competencia'] ?? null;
    }

    public function cacheKey(): string
    {
        return self::cacheKeyForRange($this->start(), $this->end(), $this->competencia());
    }

    public static function cacheKeyForRange(string $start, string $end, ?string $competencia = null): string
    {
        return sprintf(
            'api_overview_payload_v%d_%s_%s',
            self::CACHE_CONTRACT_VERSION,
            self::tenantCacheScope(),
            md5($start . '_' . $end . '_' . ($competencia ?? '')),
        );
    }

    /**
     * Cache visibility is wider than company_id: the same company can expose
     * different commercial datasets for varejo and licitacao. Consolidated
     * contexts also need the allowed-company set to avoid cross-context hits.
     */
    public static function tenantCacheScope(): string
    {
        $companyId = TenantContext::companyId();
        $channel = preg_replace('/[^a-z0-9_-]+/i', '-', TenantContext::canal()) ?: 'unknown';
        $allowedCompanyIds = TenantContext::allowedCompanyIds();
        sort($allowedCompanyIds, SORT_NUMERIC);
        $visibility = substr(hash('sha256', implode(',', $allowedCompanyIds)), 0, 12);

        return sprintf('c%d_%s_%s', $companyId, strtolower($channel), $visibility);
    }
}

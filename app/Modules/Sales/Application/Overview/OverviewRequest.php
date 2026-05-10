<?php

declare(strict_types=1);

namespace BIomie\Modules\Sales\Application\Overview;

use BIomie\Http;

final class OverviewRequest
{
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
        return 'api_overview_payload_' . md5($this->start() . '_' . $this->end() . '_' . ($this->competencia() ?? ''));
    }
}

<?php

declare(strict_types=1);

namespace BIomie\Support\Filters;

/**
 * Parsed, normalized filter state — read-only after construction.
 * Built by QueryStringMapper from raw HTTP parameters.
 *
 * @property array<string,array{op:string,val:mixed}> $filters Column-level filters.
 */
final class FilterState
{
    /**
     * @param array<string,array{op:string,val:mixed}> $filters Column-level filters.
     */
    public function __construct(
        public readonly string $q         = '',
        public readonly string $scope     = 'commercial',
        public readonly string $start     = '',
        public readonly string $end       = '',
        public readonly string $sortField = 'emissao',
        public readonly string $sortDir   = 'desc',
        public readonly int    $page      = 1,
        public readonly int    $perPage   = 50,
        public readonly array  $filters   = [],
    ) {}

    public function hasQ(): bool       { return $this->q !== ''; }
    public function hasDateRange(): bool { return $this->start !== '' || $this->end !== ''; }

    public function hasFilter(string $field): bool { return isset($this->filters[$field]); }
    public function filterOp(string $field): string { return (string) ($this->filters[$field]['op'] ?? 'contains'); }
    public function filterVal(string $field): mixed  { return $this->filters[$field]['val'] ?? null; }

    /** @return array<string,mixed> Snapshot for the `filters` key in API responses. */
    public function toApiArray(): array
    {
        $out = [];
        if ($this->q !== '')     $out['q']     = $this->q;
        if ($this->scope !== '') $out['scope']  = $this->scope;
        if ($this->start !== '') $out['start']  = $this->start;
        if ($this->end   !== '') $out['end']    = $this->end;
        if ($this->filters !== []) $out['filters'] = $this->filters;
        return $out;
    }
}

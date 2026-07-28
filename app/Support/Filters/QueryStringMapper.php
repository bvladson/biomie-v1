<?php

declare(strict_types=1);

namespace BIomie\Support\Filters;

/**
 * Maps raw HTTP query parameters to a FilterState.
 * Accepts legacy aliases: search/query/busca/termo → q.
 */
final class QueryStringMapper
{
    public function __construct(private readonly int $maxPerPage = 200) {}

    /**
     * @param array<string,mixed> $raw Typically $_GET or Http::allQuery()
     */
    public function map(array $raw): FilterState
    {
        $q = FilterNormalizer::text((string) (
            $raw['q'] ?? $raw['search'] ?? $raw['query'] ?? $raw['busca'] ?? $raw['termo'] ?? ''
        ));

        return new FilterState(
            q:         $q,
            scope:     FilterNormalizer::scope((string) ($raw['scope'] ?? 'commercial')),
            start:     FilterNormalizer::date((string) ($raw['start'] ?? '')),
            end:       FilterNormalizer::date((string) ($raw['end'] ?? '')),
            sortField: FilterNormalizer::text((string) ($raw['sort'] ?? 'emissao'), 40),
            sortDir:   FilterNormalizer::direction((string) ($raw['direction'] ?? 'desc')),
            page:      max(1, (int) ($raw['page'] ?? 1)),
            perPage:   min($this->maxPerPage, max(1, (int) ($raw['per_page'] ?? 50))),
            filters:   $this->parseFilters($raw['filters'] ?? []),
        );
    }

    /**
     * Parses filters[field]=value  OR  filters[field][op|val]=...
     *
     * @param mixed $raw
     * @return array<string,array{op:string,val:mixed}>
     */
    private function parseFilters(mixed $raw): array
    {
        if (!is_array($raw)) return [];
        $out = [];
        foreach ($raw as $field => $def) {
            $field = FilterNormalizer::text((string) $field, 50);
            if ($field === '') continue;
            if (is_array($def)) {
                $out[$field] = [
                    'op'  => FilterNormalizer::text((string) ($def['op'] ?? 'contains'), 20),
                    'val' => $def['val'] ?? null,
                ];
            } else {
                $out[$field] = ['op' => 'contains', 'val' => (string) $def];
            }
        }
        return $out;
    }
}

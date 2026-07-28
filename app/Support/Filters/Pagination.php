<?php

declare(strict_types=1);

namespace BIomie\Support\Filters;

final class Pagination
{
    public readonly int  $totalPages;
    public readonly int  $offset;
    public readonly bool $hasNext;
    public readonly bool $hasPrev;

    /**
     * @param int $total     Total rows matching the filter (used for page math).
     * @param int $page      Current 1-based page.
     * @param int $perPage   Rows per page.
     * @param int $filtered  Rows in current result set — defaults to $total.
     */
    public function __construct(
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $filtered = -1,
    ) {
        $safePerPage      = max(1, $perPage);
        $this->offset     = ($page - 1) * $safePerPage;
        $this->totalPages = max(1, (int) ceil($total / $safePerPage));
        $this->hasNext    = $page < $this->totalPages;
        $this->hasPrev    = $page > 1;
    }

    public function filteredCount(): int
    {
        return $this->filtered >= 0 ? $this->filtered : $this->total;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'page'        => $this->page,
            'per_page'    => $this->perPage,
            'total'       => $this->total,
            'filtered'    => $this->filteredCount(),
            'total_pages' => $this->totalPages,
            'has_next'    => $this->hasNext,
            'has_prev'    => $this->hasPrev,
        ];
    }
}

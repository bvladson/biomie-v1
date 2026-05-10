<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Semantic;

final readonly class MetricMeasure
{
    public const VALID_STATUSES     = ['active', 'draft', 'deprecated'];
    public const VALID_AGGREGATIONS = ['sum', 'count', 'count_distinct', 'avg', 'min', 'max', 'ratio'];

    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        public string $aggregation,
        public string $sourceColumn,
        public string $sourceTable,
        public string $filterRule = '',
        public string $status = 'active',
    ) {
        if (!in_array($this->status, self::VALID_STATUSES, true)) {
            throw new \InvalidArgumentException("Invalid measure status '{$this->status}' for '{$this->id}'.");
        }
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id:           (string) ($data['id']            ?? ''),
            name:         (string) ($data['name']          ?? ''),
            description:  (string) ($data['description']   ?? ''),
            aggregation:  (string) ($data['aggregation']   ?? 'sum'),
            sourceColumn: (string) ($data['source_column'] ?? ''),
            sourceTable:  (string) ($data['source_table']  ?? ''),
            filterRule:   (string) ($data['filter_rule']   ?? ''),
            status:       (string) ($data['status']        ?? 'active'),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'name'          => $this->name,
            'description'   => $this->description,
            'aggregation'   => $this->aggregation,
            'source_column' => $this->sourceColumn,
            'source_table'  => $this->sourceTable,
            'filter_rule'   => $this->filterRule,
            'status'        => $this->status,
        ];
    }
}

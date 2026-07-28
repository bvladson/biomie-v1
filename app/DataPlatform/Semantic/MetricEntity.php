<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Semantic;

final readonly class MetricEntity
{
    public const VALID_STATUSES = ['active', 'draft', 'deprecated'];

    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        public string $primaryKey,
        public string $sourceTable,
        public string $status = 'active',
    ) {
        if (!in_array($this->status, self::VALID_STATUSES, true)) {
            throw new \InvalidArgumentException("Invalid entity status '{$this->status}' for entity '{$this->id}'.");
        }
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id:          (string) ($data['id']           ?? ''),
            name:        (string) ($data['name']         ?? ''),
            description: (string) ($data['description']  ?? ''),
            primaryKey:  (string) ($data['primary_key']  ?? ''),
            sourceTable: (string) ($data['source_table'] ?? ''),
            status:      (string) ($data['status']       ?? 'active'),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'description'  => $this->description,
            'primary_key'  => $this->primaryKey,
            'source_table' => $this->sourceTable,
            'status'       => $this->status,
        ];
    }
}

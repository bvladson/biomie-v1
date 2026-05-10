<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Semantic;

final readonly class MetricDimension
{
    public const VALID_STATUSES = ['active', 'draft', 'deprecated'];
    public const VALID_TYPES    = ['string', 'date', 'period', 'integer', 'boolean', 'enum'];

    /** @param list<string> $allowedDomains */
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        public string $type,
        public string $sourceColumn,
        public array  $allowedDomains = [],
        public string $status = 'active',
    ) {
        if (!in_array($this->status, self::VALID_STATUSES, true)) {
            throw new \InvalidArgumentException("Invalid dimension status '{$this->status}' for '{$this->id}'.");
        }
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id:             (string) ($data['id']              ?? ''),
            name:           (string) ($data['name']            ?? ''),
            description:    (string) ($data['description']     ?? ''),
            type:           (string) ($data['type']            ?? 'string'),
            sourceColumn:   (string) ($data['source_column']   ?? ''),
            allowedDomains: (array)  ($data['allowed_domains'] ?? []),
            status:         (string) ($data['status']          ?? 'active'),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id'              => $this->id,
            'name'            => $this->name,
            'description'     => $this->description,
            'type'            => $this->type,
            'source_column'   => $this->sourceColumn,
            'allowed_domains' => $this->allowedDomains,
            'status'          => $this->status,
        ];
    }
}

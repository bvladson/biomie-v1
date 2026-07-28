<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Semantic;

final readonly class KpiDefinition
{
    public const VALID_STATUSES = ['active', 'draft', 'deprecated'];
    public const VALID_DOMAINS  = [
        'sales', 'fiscal', 'finance', 'customers',
        'geo', 'cnae', 'operations', 'omie', 'shadow', 'admin',
    ];
    public const VALID_GRAINS = ['day', 'week', 'month', 'quarter', 'year', 'period', 'realtime'];

    /**
     * @param list<string> $entities
     * @param list<string> $dimensions
     * @param list<string> $measures
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $domain,
        public string $description,
        public string $sourceOfTruth,
        public string $sourceTable,
        public string $valueColumn,
        public string $officialDate,
        public string $grain,
        public array  $entities,
        public array  $dimensions,
        public array  $measures,
        public string $includeRule,
        public string $excludeRule,
        public string $cancelRule,
        public string $permission,
        public string $freshnessDomain,
        public string $status,
        public string $owner,
        public string $notes,
    ) {
        if ($this->id === '') {
            throw new \InvalidArgumentException('KpiDefinition: id cannot be empty.');
        }
        if (!in_array($this->status, self::VALID_STATUSES, true)) {
            throw new \InvalidArgumentException("KpiDefinition '{$this->id}': invalid status '{$this->status}'.");
        }
        if (!in_array($this->domain, self::VALID_DOMAINS, true)) {
            throw new \InvalidArgumentException("KpiDefinition '{$this->id}': invalid domain '{$this->domain}'.");
        }
        if (!DataSourceTruth::isValid($this->sourceOfTruth)) {
            throw new \InvalidArgumentException("KpiDefinition '{$this->id}': invalid source_of_truth '{$this->sourceOfTruth}'.");
        }
        if ($this->permission === '') {
            throw new \InvalidArgumentException("KpiDefinition '{$this->id}': permission cannot be empty.");
        }
        if ($this->freshnessDomain === '') {
            throw new \InvalidArgumentException("KpiDefinition '{$this->id}': freshness_domain cannot be empty.");
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id:              (string) ($data['id']               ?? ''),
            name:            (string) ($data['name']             ?? ''),
            domain:          (string) ($data['domain']           ?? ''),
            description:     (string) ($data['description']      ?? ''),
            sourceOfTruth:   (string) ($data['source_of_truth']  ?? ''),
            sourceTable:     (string) ($data['source_table']     ?? ''),
            valueColumn:     (string) ($data['value_column']     ?? ''),
            officialDate:    (string) ($data['official_date']    ?? ''),
            grain:           (string) ($data['grain']            ?? 'month'),
            entities:        (array)  ($data['entities']         ?? []),
            dimensions:      (array)  ($data['dimensions']       ?? []),
            measures:        (array)  ($data['measures']         ?? []),
            includeRule:     (string) ($data['include_rule']     ?? ''),
            excludeRule:     (string) ($data['exclude_rule']     ?? ''),
            cancelRule:      (string) ($data['cancel_rule']      ?? ''),
            permission:      (string) ($data['permission']       ?? ''),
            freshnessDomain: (string) ($data['freshness_domain'] ?? ''),
            status:          (string) ($data['status']           ?? 'draft'),
            owner:           (string) ($data['owner']            ?? ''),
            notes:           (string) ($data['notes']            ?? ''),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id'               => $this->id,
            'name'             => $this->name,
            'domain'           => $this->domain,
            'description'      => $this->description,
            'source_of_truth'  => $this->sourceOfTruth,
            'source_table'     => $this->sourceTable,
            'value_column'     => $this->valueColumn,
            'official_date'    => $this->officialDate,
            'grain'            => $this->grain,
            'entities'         => $this->entities,
            'dimensions'       => $this->dimensions,
            'measures'         => $this->measures,
            'include_rule'     => $this->includeRule,
            'exclude_rule'     => $this->excludeRule,
            'cancel_rule'      => $this->cancelRule,
            'permission'       => $this->permission,
            'freshness_domain' => $this->freshnessDomain,
            'status'           => $this->status,
            'owner'            => $this->owner,
            'notes'            => $this->notes,
        ];
    }

    public function isActive(): bool      { return $this->status === 'active'; }
    public function isDraft(): bool       { return $this->status === 'draft'; }
    public function isDeprecated(): bool  { return $this->status === 'deprecated'; }
}

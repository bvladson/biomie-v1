<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Contracts;

use InvalidArgumentException;

final readonly class MetricContract
{
    /**
     * @param list<string> $dimensions
     */
    public function __construct(
        public string $id,
        public string $domain,
        public string $sourceContractId,
        public string $measure,
        public string $aggregation,
        public array $dimensions = [],
        public string $description = '',
    ) {
        if ($this->id === '') {
            throw new InvalidArgumentException('MetricContract: id cannot be empty.');
        }
        if ($this->domain === '') {
            throw new InvalidArgumentException("MetricContract '{$this->id}': domain cannot be empty.");
        }
        if ($this->sourceContractId === '') {
            throw new InvalidArgumentException("MetricContract '{$this->id}': source contract cannot be empty.");
        }
        if ($this->measure === '') {
            throw new InvalidArgumentException("MetricContract '{$this->id}': measure cannot be empty.");
        }
        if ($this->aggregation === '') {
            throw new InvalidArgumentException("MetricContract '{$this->id}': aggregation cannot be empty.");
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'domain' => $this->domain,
            'source_contract_id' => $this->sourceContractId,
            'measure' => $this->measure,
            'aggregation' => $this->aggregation,
            'dimensions' => $this->dimensions,
            'description' => $this->description,
        ];
    }
}

<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Contracts;

use BIomie\DataPlatform\Semantic\DataSourceTruth;
use InvalidArgumentException;

final readonly class SourceContract
{
    /**
     * @param list<string> $requiredFilters
     * @param list<string> $allowedFallbacks
     */
    public function __construct(
        public string $id,
        public string $domain,
        public string $sourceTier,
        public string $table,
        public string $periodColumn,
        public string $grain,
        public array $requiredFilters = [],
        public array $allowedFallbacks = [],
    ) {
        if ($this->id === '') {
            throw new InvalidArgumentException('SourceContract: id cannot be empty.');
        }
        if ($this->domain === '') {
            throw new InvalidArgumentException("SourceContract '{$this->id}': domain cannot be empty.");
        }
        if (!DataSourceTruth::isValid($this->sourceTier)) {
            throw new InvalidArgumentException("SourceContract '{$this->id}': invalid source tier '{$this->sourceTier}'.");
        }
        if ($this->table === '') {
            throw new InvalidArgumentException("SourceContract '{$this->id}': table cannot be empty.");
        }
        if ($this->periodColumn === '') {
            throw new InvalidArgumentException("SourceContract '{$this->id}': period column cannot be empty.");
        }
        if ($this->grain === '') {
            throw new InvalidArgumentException("SourceContract '{$this->id}': grain cannot be empty.");
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'domain' => $this->domain,
            'source_tier' => $this->sourceTier,
            'table' => $this->table,
            'period_column' => $this->periodColumn,
            'grain' => $this->grain,
            'required_filters' => $this->requiredFilters,
            'allowed_fallbacks' => $this->allowedFallbacks,
        ];
    }
}

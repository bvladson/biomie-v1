<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Contracts;

use BIomie\RequestContext;

final readonly class ReadModelResponse
{
    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $meta
     * @param list<array<string,mixed>|string> $insights
     * @param list<array<string,mixed>|string> $warnings
     * @param array<string,mixed> $debug
     */
    public function __construct(
        public bool $ok,
        public SourceContract $sourceContract,
        public array $data,
        public array $meta = [],
        public array $insights = [],
        public array $warnings = [],
        public array $debug = [],
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'meta' => array_replace_recursive([
                'source' => [
                    'tier' => $this->sourceContract->sourceTier,
                    'contract' => $this->sourceContract->id,
                    'table' => $this->sourceContract->table,
                    'period_column' => $this->sourceContract->periodColumn,
                    'grain' => $this->sourceContract->grain,
                ],
                'request' => [
                    'request_id' => RequestContext::id(),
                    'generated_at' => date(DATE_ATOM),
                ],
            ], $this->meta),
            'data' => $this->data,
            'insights' => $this->insights,
            'warnings' => $this->warnings,
            'debug' => $this->debug,
        ];
    }
}

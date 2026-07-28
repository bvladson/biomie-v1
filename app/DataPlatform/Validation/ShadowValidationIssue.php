<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Validation;

final class ShadowValidationIssue
{
    public function __construct(
        public readonly string $code,
        public readonly string $severity,
        public readonly string $path,
        public readonly mixed $expected,
        public readonly mixed $actual,
        public readonly mixed $delta = null,
        public readonly mixed $tolerance = null,
        public readonly string $message = '',
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'severity' => $this->severity,
            'path' => $this->path,
            'expected' => $this->expected,
            'actual' => $this->actual,
            'delta' => $this->delta,
            'tolerance' => $this->tolerance,
            'message' => $this->message,
        ];
    }
}

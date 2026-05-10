<?php

declare(strict_types=1);

namespace BIomie\AdminConsole;

final class AdminCommandResult
{
    /**
     * @param list<string>                        $outputLines
     * @param array<string,mixed>                 $metrics
     * @param list<array<string,mixed>>           $tables
     * @param list<string>                        $warnings
     * @param list<string>                        $errors
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $status,
        public readonly string $title,
        public readonly string $message,
        public readonly array $outputLines = [],
        public readonly array $metrics = [],
        public readonly array $tables = [],
        public readonly array $warnings = [],
        public readonly array $errors = [],
        public readonly int $durationMs = 0,
    ) {}

    /**
     * @param list<string>              $outputLines
     * @param array<string,mixed>       $metrics
     * @param list<array<string,mixed>> $tables
     * @param list<string>              $warnings
     */
    public static function ok(
        string $title,
        string $message,
        array $outputLines = [],
        array $metrics = [],
        array $tables = [],
        array $warnings = [],
    ): self {
        return new self(
            success: true,
            status: 'ok',
            title: $title,
            message: $message,
            outputLines: $outputLines,
            metrics: $metrics,
            tables: $tables,
            warnings: $warnings,
        );
    }

    /**
     * @param list<string>              $outputLines
     * @param list<array<string,mixed>> $tables
     * @param list<string>              $warnings
     */
    public static function warning(
        string $title,
        string $message,
        array $outputLines = [],
        array $tables = [],
        array $warnings = [],
    ): self {
        return new self(
            success: true,
            status: 'warning',
            title: $title,
            message: $message,
            outputLines: $outputLines,
            tables: $tables,
            warnings: $warnings,
        );
    }

    /**
     * @param list<string> $errors
     */
    public static function error(
        string $title,
        string $message,
        array $errors = [],
    ): self {
        return new self(
            success: false,
            status: 'error',
            title: $title,
            message: $message,
            errors: $errors,
        );
    }

    public function withDurationMs(int $durationMs): self
    {
        return new self(
            success: $this->success,
            status: $this->status,
            title: $this->title,
            message: $this->message,
            outputLines: $this->outputLines,
            metrics: $this->metrics,
            tables: $this->tables,
            warnings: $this->warnings,
            errors: $this->errors,
            durationMs: $durationMs,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'success'      => $this->success,
            'status'       => $this->status,
            'title'        => $this->title,
            'message'      => $this->message,
            'output_lines' => $this->outputLines,
            'metrics'      => $this->metrics,
            'tables'       => $this->tables,
            'warnings'     => $this->warnings,
            'errors'       => $this->errors,
            'duration_ms'  => $this->durationMs,
        ];
    }
}

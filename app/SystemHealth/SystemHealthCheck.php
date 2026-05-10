<?php

declare(strict_types=1);

namespace BIomie\SystemHealth;

/**
 * Immutable result of a single health check.
 *
 * @phpstan-type MetricMap array<string,int|float|string|null>
 */
final class SystemHealthCheck
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        /** ok | warning | critical | unknown */
        public readonly string $status,
        /** info | warning | critical */
        public readonly string $severity,
        public readonly string $summary,
        /** @var array<string,int|float|string|null> */
        public readonly array  $metrics,
        /** @var list<string> */
        public readonly array  $actions,
        public readonly string $checked_at,
        public readonly int    $duration_ms,
    ) {}

    public static function ok(
        string $id,
        string $label,
        string $summary,
        array  $metrics     = [],
        int    $duration_ms = 0,
    ): self {
        return new self($id, $label, 'ok', 'info', $summary, $metrics, [], date('c'), $duration_ms);
    }

    public static function warning(
        string $id,
        string $label,
        string $summary,
        array  $metrics     = [],
        array  $actions     = [],
        int    $duration_ms = 0,
    ): self {
        return new self($id, $label, 'warning', 'warning', $summary, $metrics, $actions, date('c'), $duration_ms);
    }

    public static function critical(
        string $id,
        string $label,
        string $summary,
        array  $metrics     = [],
        array  $actions     = [],
        int    $duration_ms = 0,
    ): self {
        return new self($id, $label, 'critical', 'critical', $summary, $metrics, $actions, date('c'), $duration_ms);
    }

    public static function unknown(
        string     $id,
        string     $label,
        \Throwable $e,
        int        $duration_ms = 0,
    ): self {
        return new self(
            $id, $label, 'unknown', 'critical',
            'Check falhou com exceção: ' . $e->getMessage(),
            [],
            ['Verificar logs do sistema para mais detalhes.'],
            date('c'),
            $duration_ms,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'label'       => $this->label,
            'status'      => $this->status,
            'severity'    => $this->severity,
            'summary'     => $this->summary,
            'metrics'     => $this->metrics,
            'actions'     => $this->actions,
            'checked_at'  => $this->checked_at,
            'duration_ms' => $this->duration_ms,
        ];
    }
}

<?php

declare(strict_types=1);

namespace BIomie\Support;

final class PerformanceTimer
{
    private float $startedAt;

    /** @var array<string,float> */
    private array $marks = [];

    /** @var array<string,float> */
    private array $measures = [];

    private function __construct(private readonly string $name)
    {
        $this->startedAt = microtime(true);
        $this->marks['start'] = $this->startedAt;
    }

    public static function start(string $name): self
    {
        return new self($name);
    }

    public function mark(string $name): void
    {
        $this->marks[$name] = microtime(true);
    }

    public function measure(string $name, string $startMark, ?string $endMark = null): ?float
    {
        if (!isset($this->marks[$startMark])) {
            return null;
        }

        $end = $endMark !== null && isset($this->marks[$endMark])
            ? $this->marks[$endMark]
            : microtime(true);

        $duration = round(($end - $this->marks[$startMark]) * 1000, 2);
        $this->measures[$name] = $duration;

        return $duration;
    }

    /**
     * @return array{name:string,total_ms:float,measures:array<string,float>,warnings:list<string>}
     */
    public function finish(): array
    {
        $total = round((microtime(true) - $this->startedAt) * 1000, 2);
        $warnings = [];

        foreach ($this->measures as $name => $durationMs) {
            if ($durationMs > 800) {
                $warnings[] = $name . ' acima de 800ms';
            }
        }

        if ($total > 2000) {
            $warnings[] = 'endpoint acima de 2000ms';
        }

        return [
            'name' => $this->name,
            'total_ms' => $total,
            'measures' => $this->measures,
            'warnings' => $warnings,
        ];
    }
}

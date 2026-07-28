<?php

declare(strict_types=1);

namespace BIomie\SystemHealth;

final class SystemHealthReport
{
    private const STATUS_RANK = ['ok' => 0, 'unknown' => 1, 'warning' => 2, 'critical' => 3];

    /** @param list<SystemHealthCheck> $checks */
    public function __construct(
        private readonly array  $checks,
        private readonly string $generated_at,
    ) {}

    public function overallStatus(): string
    {
        $worst = 'ok';
        foreach ($this->checks as $check) {
            $rank = self::STATUS_RANK[$check->status] ?? 1;
            if ($rank > (self::STATUS_RANK[$worst] ?? 0)) {
                $worst = $check->status;
            }
        }
        return $worst;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'status'       => $this->overallStatus(),
            'generated_at' => $this->generated_at,
            'checks'       => array_map(static fn(SystemHealthCheck $c) => $c->toArray(), $this->checks),
        ];
    }
}

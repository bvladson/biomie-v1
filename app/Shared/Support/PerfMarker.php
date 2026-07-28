<?php

declare(strict_types=1);

namespace BIomie\Shared\Support;

use BIomie\Support\PerformanceTimer;

final class PerfMarker
{
    public function mark(string $name): void
    {
        $timer = $GLOBALS['biomie_performance_timer'] ?? null;
        if ($timer instanceof PerformanceTimer) {
            $timer->mark($name);
        }
    }

    public function measure(string $name, string $startMark): void
    {
        $timer = $GLOBALS['biomie_performance_timer'] ?? null;
        if ($timer instanceof PerformanceTimer) {
            $timer->measure($name, $startMark);
        }
    }
}

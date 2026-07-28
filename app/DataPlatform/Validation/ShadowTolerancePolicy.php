<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Validation;

final class ShadowTolerancePolicy
{
    public function moneyTolerance(): float
    {
        return 0.01;
    }

    public function numericTolerance(): float
    {
        return 0.01;
    }

    public function countTolerance(): int
    {
        return 0;
    }
}

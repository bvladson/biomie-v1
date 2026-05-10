<?php

declare(strict_types=1);

namespace BIomie\SystemHealth;

interface CheckInterface
{
    public function id(): string;

    public function label(): string;

    /** Must never throw — catch all internally and return unknown/critical. */
    public function run(): SystemHealthCheck;
}

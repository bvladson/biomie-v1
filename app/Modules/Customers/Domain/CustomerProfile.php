<?php

declare(strict_types=1);

namespace BIomie\Modules\Customers\Domain;

final readonly class CustomerProfile
{
    public function __construct(
        public string $cnaeCodigo        = '',
        public string $cnaeSource        = '',
        public string $inscricaoEstadual = '',
    ) {}
}

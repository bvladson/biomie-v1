<?php

declare(strict_types=1);

namespace BIomie\Modules\Customers\Domain;

final readonly class CustomerContact
{
    public function __construct(
        public string $email    = '',
        public string $telefone = '',
    ) {}
}

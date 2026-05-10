<?php

declare(strict_types=1);

namespace BIomie\Modules\Customers\Domain;

final readonly class CustomerAddress
{
    public function __construct(
        public string $cidade      = '',
        public string $cidadeNorm  = '',
        public string $uf          = '',
        public string $bairro      = '',
        public string $cep         = '',
        public string $endereco    = '',
        public string $numero      = '',
        public string $complemento = '',
    ) {}
}

<?php

declare(strict_types=1);

namespace BIomie\Modules\Customers\Domain;

final readonly class CustomerIdentity
{
    public function __construct(
        public int    $codigoClienteOmie,
        public string $razaoSocial        = '',
        public string $nomeFantasia        = '',
        public string $cpfCnpj            = '',
        public string $tipoPessoa         = '',
    ) {}
}

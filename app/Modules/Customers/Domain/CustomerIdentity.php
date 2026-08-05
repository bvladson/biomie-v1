<?php

declare(strict_types=1);

namespace BIomie\Modules\Customers\Domain;

use BIomie\DataGovernance\ClientClassifier;

final readonly class CustomerIdentity
{
    public function __construct(
        public int    $codigoClienteOmie,
        public string $razaoSocial        = '',
        public string $nomeFantasia        = '',
        public string $cpfCnpj            = '',
        // NUNCA '' aqui. String vazia é o sentinela de ENUM inválido do MySQL
        // (índice 0) e foi ela que derrubou a materialização dos marts 40 vezes
        // em 2026-08-04. Esta classe ainda não tem consumidor; o dia em que
        // tiver, o default não pode ser a arma engatilhada.
        public string $tipoPessoa         = ClientClassifier::DESCONHECIDO,
    ) {}
}
